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

    // 5. Buscar TODAS las conexiones por chat_id + webhook_secret (fan-out)
    $secretToken = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    $configManager = new ConfigManager();
    $allFound = $chatId ? $configManager->findAllByChatId((int) $chatId, $secretToken) : [];

    // 6. Sin conexión por chat_id: detectar pasivamente
    if (empty($allFound) && $chatId && $secretToken !== '') {
        // 6a. Buscar conexión pendiente (chat_id=0)
        $detectedConn = $configManager->findByWebhookSecretPending($secretToken);
        
        // 6b. Si no hay pendiente, buscar cualquier conexión con este webhook_secret
        //    (mismo bot agregado a un grupo NUEVO)
        if ($detectedConn === null) {
            $detectedConn = $configManager->findByWebhookSecret($secretToken);
        }
        
        if ($detectedConn !== null) {
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

            // Responder 200 para no saturar logs de errores en Telegram
            http_response_code(200);
            die(json_encode(['status' => 'detected', 'slug' => $slug]));
        }
        
        // 6c. Si no matchea ninguna conexión, el webhook_secret es desconocido
        log_message("trackerGram: webhook_secret desconocido para chat {$chatId} — ¿bot token filtrado?", true);
    }

    // 7. Sin conexión = rechazar
    if (empty($allFound)) {
        log_message("trackerGram: chat_id {$chatId} sin conexión habilitada — rechazando", true);
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden: no connection for this chat']));
    }

    // 8. Fan-out: procesar el update para TODAS las conexiones que matcheen
    //    (útil cuando se duplica una conexión con diferente tracker_id)
    foreach ($allFound as $found) {
        $connection = $found;
        $connectionSlug = $found['_slug'];
        unset($connection['_slug']);

        $messageMapper = new MessageMapper();
        $messageMapper->setFieldPrefix($connection['field_prefix'] ?? 'telegrammessage');
        $useAsync = $connection['async_processing'] ?? ASYNC_PROCESSING;

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
    }

    // Responder 200 OK una sola vez para todo el fan-out
    echo json_encode(['status' => 'ok']);
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

    // Auto-detectar field prefix desde el tracker (corrige prefix mal guardado)
    $trackerId = (int) $connection['tracker_id'];
    if ($trackerId > 0) {
        $resolvedPrefix = $tikiClient->resolveFieldPrefix($trackerId);
        if ($resolvedPrefix !== $messageMapper->getFieldPrefix()) {
            log_message("api.php: Field prefix corregido de '{$messageMapper->getFieldPrefix()}' a '{$resolvedPrefix}' para conexión '{$connectionSlug}'");
            $messageMapper->setFieldPrefix($resolvedPrefix);
            $tikiClient->setFieldPrefix($resolvedPrefix);
            // Persistir en setup.json para evitar re-detección en cada request
            $cm = new ConfigManager();
            $cm->updateConnectionFields($connectionSlug, ['field_prefix' => $resolvedPrefix]);
        }
    }

    $tgClient = new TelegramClient(
        botToken: $connection['bot_token']
    );
    $handler = new WebhookHandler(
        tikiWikiClient: $tikiClient,
        telegramClient: $tgClient,
        messageMapper: $messageMapper,
        trackerId: $trackerId
    );
    $handler->processUpdate($update);
}
