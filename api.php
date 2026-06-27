<?php
/**
 * trackerGram - Webhook endpoint para Telegram → TikiWiki
 * 
 * Punto de entrada HTTP para webhooks de Telegram.
 * Busca la conexión configurada por chat_id + secret token.
 * Si no encuentra conexión, rechaza con 403.
 */

require_once 'bootstrap.php';
require_once 'ConfigManager.php';
require_once 'detect_helper.php';

// Manejar webhook de Telegram
// solo se ejecuta si api.php es el entry point directo
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validar tamaño del cuerpo ANTES de leerlo (previene DoS con cuerpos grandes)
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $maxBodySize = 1024 * 1024; // 1 MB máximo para updates de Telegram
    if ($contentLength > $maxBodySize) {
        http_response_code(413);
        die(json_encode(['error' => 'Payload Too Large']));
    }

    // 2. Rate limiting ANTES de parsear JSON (previene DoS con parsing pesado)
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateFile = TEMP_DIR . '/tg_rate_' . md5($ip);
    $window = 60;
    $maxRequests = 30;
    $now = time();
    $requests = [];
    if (file_exists($rateFile)) {
        $content = @file_get_contents($rateFile);
        if ($content) {
            $requests = json_decode($content, true) ?? [];
            $requests = array_values(array_filter($requests, fn($t) => $t > $now - $window));
        }
    }
    $requests[] = $now;
    @file_put_contents($rateFile, json_encode($requests));

    if (count($requests) > $maxRequests) {
        http_response_code(429);
        die(json_encode(['error' => 'Too Many Requests']));
    }

    // 3. Leer y parsear JSON (primero, para poder extraer chat_id)
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    if (!$update) {
        log_message("trackerGram: JSON inválido en webhook", true);
        http_response_code(400);
        die(json_encode(['error' => 'Invalid JSON']));
    }

    // 4. Extraer chat_id del update
    $chatId = 0;
    if (isset($update['message']['chat']['id'])) {
        $chatId = $update['message']['chat']['id'];
    } elseif (isset($update['message_reaction']['chat']['id'])) {
        $chatId = $update['message_reaction']['chat']['id'];
    } elseif (isset($update['message_reaction_count']['chat']['id'])) {
        $chatId = $update['message_reaction_count']['chat']['id'];
    } elseif (isset($update['my_chat_member']['chat']['id'])) {
        $chatId = $update['my_chat_member']['chat']['id'];
    }

    // 5. Extraer secret token del header
    $secretToken = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    $configManager = new ConfigManager();

    // 6. Buscar TODAS las conexiones por chat_id + webhook_secret (fan-out)
    $allFound = $chatId ? $configManager->findAllByChatId((int) $chatId, $secretToken) : [];

    // 7. Detectar pasivamente chats no configurados, incluso si chat_id=0 (test webhook)
    if (empty($allFound) && $secretToken !== '') {
        // 7a. Buscar conexión pendiente (chat_id=0)
        $detectedConn = $configManager->findByWebhookSecretPending($secretToken);
        
        // 7b. Si no hay pendiente, buscar cualquier conexión con este webhook_secret
        //     (mismo bot agregado a un grupo NUEVO)
        if ($detectedConn === null) {
            $detectedConn = $configManager->findByWebhookSecret($secretToken);
        }
        
        if ($detectedConn !== null) {
            if ($chatId) {
                // Hay chat real → registrar detección
                $chatTitle = '';
                if (isset($update['message']['chat']['title'])) {
                    $chatTitle = $update['message']['chat']['title'];
                } elseif (isset($update['message']['chat']['username'])) {
                    $chatTitle = '@' . $update['message']['chat']['username'];
                } elseif (isset($update['my_chat_member']['chat']['title'])) {
                    $chatTitle = $update['my_chat_member']['chat']['title'];
                }
                if ($chatTitle === '') {
                    $chatTitle = 'Chat ' . $chatId;
                }

                $slug = $detectedConn['_slug'];
                addDetection($slug, (int) $chatId, $chatTitle);
                log_message("trackerGram: Chat detectado '{$chatTitle}' ({$chatId}) para conexión '{$slug}'");
            } else {
                // Test webhook sin chat — el webhook funciona correctamente
                $slug = $detectedConn['_slug'];
                log_message("trackerGram: Webhook OK (test) para conexión '{$slug}'");
            }

            // Responder 200 para no saturar logs de errores en Telegram
            http_response_code(200);
            die(json_encode(['status' => 'detected', 'slug' => $slug]));
        }
        
        // 7c. Si no matchea ninguna conexión, el webhook_secret es desconocido
        log_message("trackerGram: webhook_secret desconocido para chat {$chatId} — ¿bot token filtrado?", true);
    }

    // 9. Sin conexión = rechazar
    if (empty($allFound)) {
        log_message("trackerGram: chat_id {$chatId} sin conexión habilitada — rechazando", true);
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden: no connection for this chat']));
    }

    // 10. Fan-out: procesar el update para TODAS las conexiones que matcheen
    //     (útil cuando se duplica una conexión con diferente tracker_id)
    $fanOutResults = [];
    foreach ($allFound as $found) {
        $connection = $found;
        $connectionSlug = $found['_slug'];
        unset($connection['_slug']);

        $messageMapper = new MessageMapper();
        $messageMapper->setFieldPrefix($connection['field_prefix'] ?? 'telegrammessage');
        $useAsync = $connection['async_processing'] ?? ASYNC_PROCESSING;

        try {
            if ($useAsync) {
                // Modo async: escribir a buffer y responder rápido
                $bufferDir = TEMP_DIR . '/buffer';
                if (!is_dir($bufferDir)) {
                    @mkdir($bufferDir, 0700, true);
                }

                $bufferData = [
                    'connection_slug' => $connectionSlug,
                    'update' => $update,
                ];
                $bufferFile = $bufferDir . '/event_' . time() . '_' . bin2hex(random_bytes(4)) . '.json';
                $written = @file_put_contents($bufferFile, json_encode($bufferData), LOCK_EX);
                if ($written === false) {
                    log_message("trackerGram: No se pudo escribir buffer async — procesando sincrónicamente", true);
                    processUpdate($update, $connection, $connectionSlug, $messageMapper);
                }
            } else {
                // Modo sync: procesar inmediatamente
                processUpdate($update, $connection, $connectionSlug, $messageMapper);
            }
            $fanOutResults[$connectionSlug] = 'ok';
        } catch (Throwable $e) {
            log_message("trackerGram: Error en fan-out para conexión '{$connectionSlug}': " . $e->getMessage(), true);
            $fanOutResults[$connectionSlug] = 'error: ' . $e->getMessage();
        }
    }

    // Responder 200 OK con resultados individuales
    echo json_encode(['status' => 'ok', 'connections' => $fanOutResults]);
}

/**
 * Procesar un update de Telegram usando la conexión encontrada
 */
function processUpdate(array $update, array $connection, string $connectionSlug, MessageMapper $messageMapper): void
{
    // Extraer solo los campos de la conexión (por si pasan con _slug)
    if (isset($connection['_slug'])) {
        unset($connection['_slug']);
    }
    $tikiClient = new TikiWikiClient(
        apiUrl: $connection['tiki_api_url'],
        token: $connection['tiki_api_token'],
        timeout: TIMEOUT_TIKIWIKI_API,
        uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
    );
    $tikiClient->setFieldPrefix($connection['field_prefix'] ?? 'telegrammessage');
    $messageMapper->setFieldPrefix($connection['field_prefix'] ?? 'telegrammessage');

    // Auto-detectar field prefix (UNA SOLA VEZ, cacheado con field_prefix_checked)
    $trackerId = (int) $connection['tracker_id'];
    $prefixChecked = !empty($connection['field_prefix_checked']);
    if ($trackerId > 0 && !$prefixChecked) {
        $resolvedPrefix = $tikiClient->resolveFieldPrefix($trackerId);
        $updateFields = ['field_prefix_checked' => true];
        if ($resolvedPrefix !== $messageMapper->getFieldPrefix()) {
            $msg = "api.php: Field prefix corregido de '{$messageMapper->getFieldPrefix()}' a '{$resolvedPrefix}' para conexión '{$connectionSlug}'";
            log_message($msg);
            $messageMapper->setFieldPrefix($resolvedPrefix);
            $tikiClient->setFieldPrefix($resolvedPrefix);
            $updateFields['field_prefix'] = $resolvedPrefix;
        }
        $cm = new ConfigManager();
        $cm->updateConnectionFields($connectionSlug, $updateFields);
    }

    $tgClient = new TelegramClient(
        botToken: $connection['bot_token']
    );

    // Derivar URL del admin panel desde el request actual
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $adminUrl = $protocol . '://' . $host . $scriptDir . '/admin.php';

    $handler = new WebhookHandler(
        tikiWikiClient: $tikiClient,
        telegramClient: $tgClient,
        messageMapper: $messageMapper,
        trackerId: $trackerId,
        adminUrl: $adminUrl,
        connectionName: $connectionSlug
    );
    $handler->processUpdate($update);
}
