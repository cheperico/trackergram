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
    // Usa flock(LOCK_EX) para evitar race condition entre requests concurrentes
    // Key por secret_token (cada bot tiene su propio budget) con fallback a IP
    $rateKey = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateFile = TEMP_DIR . '/tg_rate_' . md5($rateKey);
    $window = 60;
    $maxRequests = 30;
    $now = time();
    $requests = [];

    $fp = fopen($rateFile, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $content = stream_get_contents($fp);
        if ($content !== false && $content !== '') {
            $requests = json_decode($content, true) ?? [];
        }
        $requests = array_values(array_filter($requests, fn($t) => $t > $now - $window));
        $requests[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($requests));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    // GC probabilístico: 1% de las veces limpia archivos rate limit stale ( > 1 hora sin actividad)
    if (mt_rand(1, 100) === 1) {
        $staleThreshold = $now - 3600;
        foreach (glob(TEMP_DIR . '/tg_rate_*') as $staleFile) {
            if (is_file($staleFile) && filemtime($staleFile) < $staleThreshold) {
                if (!unlink($staleFile)) {
                    $error = error_get_last();
                    $msg = $error ? $error['message'] : 'unknown error';
                    log_message("trackerGram: GC no pudo eliminar rate file '{$staleFile}': {$msg}");
                }
            }
        }
    }

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
    // IMPORTANTE: incluir TODOS los tipos de update que contienen chat.id
    // Si falta alguno, ese tipo de update se descarta silenciosamente.
    $chatId = 0;
    if (isset($update['message']['chat']['id'])) {
        $chatId = $update['message']['chat']['id'];
    } elseif (isset($update['edited_message']['chat']['id'])) {
        $chatId = $update['edited_message']['chat']['id'];
    } elseif (isset($update['channel_post']['chat']['id'])) {
        $chatId = $update['channel_post']['chat']['id'];
    } elseif (isset($update['edited_channel_post']['chat']['id'])) {
        $chatId = $update['edited_channel_post']['chat']['id'];
    } elseif (isset($update['callback_query']['message']['chat']['id'])) {
        $chatId = $update['callback_query']['message']['chat']['id'];
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

    // 6b. Detectar migración grupo→supergrupo ANTES de detección pasiva
    // Cuando un grupo básico migra a supergrupo, Telegram envía un mensaje con migrate_to_chat_id
    // en el último mensaje del grupo viejo. Actualizamos la conexión automáticamente.
    if (isset($update['message']['migrate_to_chat_id'])) {
        $migrateToChatId = (int) $update['message']['migrate_to_chat_id'];
        if (!empty($allFound)) {
            // Actualizar chat_id de todas las conexiones que matchean
            foreach ($allFound as $found) {
                $configManager->updateConnectionFields($found['_slug'], [
                    'chat_id' => $migrateToChatId,
                ]);
                log_message("trackerGram: 🚚 Migración grupo→supergrupo: chat_id {$chatId} → {$migrateToChatId} para conexión '{$found['_slug']}'");
            }
            // Usar el nuevo chat_id para el resto del procesamiento
            $chatId = $migrateToChatId;
            // Re-buscar conexiones con el nuevo chat_id (ya actualizadas en setup.json)
            $allFound = $chatId ? $configManager->findAllByChatId((int) $chatId, $secretToken) : [];
        } else {
            // Mensaje de migración sin conexión previa — loguear y proseguir con detección pasiva
            log_message("trackerGram: Migración detectada ({$chatId} → {$migrateToChatId}) pero sin conexión previa — se usará detección pasiva");
        }
    }
    // También manejar migrate_from_chat_id: mensaje en el nuevo supergrupo que referencia el grupo viejo
    elseif (isset($update['message']['migrate_from_chat_id']) && empty($allFound)) {
        $migrateFromChatId = (int) $update['message']['migrate_from_chat_id'];
        // Buscar conexión por el chat_id viejo
        $oldConnections = $configManager->findAllByChatId($migrateFromChatId, $secretToken);
        if (!empty($oldConnections)) {
            foreach ($oldConnections as $oldConn) {
                $configManager->updateConnectionFields($oldConn['_slug'], [
                    'chat_id' => $chatId,
                ]);
                log_message("trackerGram: 🚚 Post-migración: chat_id actualizado de {$migrateFromChatId} a {$chatId} para conexión '{$oldConn['_slug']}'");
            }
            // Re-buscar con el nuevo chat_id
            $allFound = $chatId ? $configManager->findAllByChatId((int) $chatId, $secretToken) : [];
        }
    }

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
                // Determinar título del chat
                $chatTitle = '';
                if (isset($update['message']['chat']['title'])) {
                    $chatTitle = $update['message']['chat']['title'];
                } elseif (isset($update['message']['chat']['username'])) {
                    $chatTitle = '@' . $update['message']['chat']['username'];
                } elseif (isset($update['edited_message']['chat']['title'])) {
                    $chatTitle = $update['edited_message']['chat']['title'];
                } elseif (isset($update['edited_message']['chat']['username'])) {
                    $chatTitle = '@' . $update['edited_message']['chat']['username'];
                } elseif (isset($update['edited_channel_post']['chat']['title'])) {
                    $chatTitle = $update['edited_channel_post']['chat']['title'];
                } elseif (isset($update['edited_channel_post']['chat']['username'])) {
                    $chatTitle = '@' . $update['edited_channel_post']['chat']['username'];
                } elseif (isset($update['my_chat_member']['chat']['title'])) {
                    $chatTitle = $update['my_chat_member']['chat']['title'];
                }
                if ($chatTitle === '') {
                    $chatTitle = 'Chat ' . $chatId;
                }

                // Detectar migración post-facto: la conexión ya tiene chat_id asignado (básico)
                // pero el incoming chat es nuevo y parece supergrupo (-100...)
                $connChatId = (int) ($detectedConn['chat_id'] ?? 0);
                if ($connChatId !== 0 && $connChatId !== (int) $chatId && str_starts_with((string) $chatId, '-100')) {
                    // Probablemente migración básico→supergrupo — auto-asignar sin detección manual
                    $configManager->updateConnectionFields($detectedConn['_slug'], [
                        'chat_id' => (int) $chatId,
                    ]);
                    log_message("trackerGram: 🚚 Post-migración auto-asignada: chat_id {$connChatId} → {$chatId} para conexión '{$detectedConn['_slug']}' (chat: {$chatTitle})");
                    // Refrescar allFound y continuar al fan-out normalmente
                    $allFound = $chatId ? $configManager->findAllByChatId((int) $chatId, $secretToken) : [];
                } else {
                    // Chat genuinamente nuevo — registrar detección para el admin
                    $slug = $detectedConn['_slug'];
                    addDetection($slug, (int) $chatId, $chatTitle);
                    log_message("trackerGram: Chat detectado '{$chatTitle}' ({$chatId}) para conexión '{$slug}'");

                    // Responder 200 para no saturar logs de errores en Telegram
                    http_response_code(200);
                    die(json_encode(['status' => 'detected', 'slug' => $slug]));
                }
            } else {
                // Test webhook sin chat — el webhook funciona correctamente
                $slug = $detectedConn['_slug'];
                log_message("trackerGram: Webhook OK (test) para conexión '{$slug}'");

                // Responder 200
                http_response_code(200);
                die(json_encode(['status' => 'detected', 'slug' => $slug]));
            }
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
                    if (!mkdir($bufferDir, 0700, true) && !is_dir($bufferDir)) {
                        log_message("trackerGram: No se pudo crear buffer dir '{$bufferDir}' — procesando sincrónicamente", true);
                        processUpdate($update, $connection, $connectionSlug, $messageMapper, $configManager);
                        break; // sale del case async
                    }
                }

                $bufferData = [
                    'connection_slug' => $connectionSlug,
                    'update' => $update,
                ];
                $bufferFile = $bufferDir . '/event_' . time() . '_' . bin2hex(random_bytes(4)) . '.json';
                $tmpFile = $bufferFile . '.tmp'; // escritura temporal + rename atómico
                $written = file_put_contents($tmpFile, json_encode($bufferData), LOCK_EX);
                if ($written === false) {
                    $error = error_get_last();
                    $msg = $error ? $error['message'] : 'unknown error';
                    if (file_exists($tmpFile)) {
                        unlink($tmpFile);
                    }
                    log_message("trackerGram: No se pudo escribir buffer async ({$msg}) — procesando sincrónicamente", true);
                    processUpdate($update, $connection, $connectionSlug, $messageMapper, $configManager);
                } elseif (!rename($tmpFile, $bufferFile)) {
                    $error = error_get_last();
                    $msg = $error ? $error['message'] : 'unknown error';
                    if (file_exists($tmpFile)) {
                        unlink($tmpFile);
                    }
                    log_message("trackerGram: No se pudo renombrar buffer async ({$msg}) — procesando sincrónicamente", true);
                    processUpdate($update, $connection, $connectionSlug, $messageMapper, $configManager);
                }
            } else {
                // Modo sync: procesar inmediatamente
                processUpdate($update, $connection, $connectionSlug, $messageMapper, $configManager);
            }
            $fanOutResults[$connectionSlug] = 'ok';
        } catch (Throwable $e) {
            log_message("trackerGram: Error en fan-out para conexión '{$connectionSlug}': " . $e->getMessage(), true);
            $fanOutResults[$connectionSlug] = 'error: ' . $e->getMessage();
        }
    }

    // Determinar código de respuesta según resultados del fan-out
    // Si al menos una conexión procesó OK → 200 (las que fallaron tienen error en el detalle)
    // Si TODAS fallaron → 502 para que Telegram reintente el webhook
    $anyOk = false;
    foreach ($fanOutResults as $result) {
        if ($result === 'ok') {
            $anyOk = true;
            break;
        }
    }

    if ($anyOk || empty($fanOutResults)) {
        http_response_code(200);
    } else {
        http_response_code(502);
        log_message("trackerGram: Todas las conexiones fallaron en fan-out — respondiendo 502 para reintento de Telegram", true);
    }

    echo json_encode(['status' => $anyOk ? 'ok' : 'error', 'connections' => $fanOutResults]);
}

/**
 * Procesar un update de Telegram usando la conexión encontrada
 */
function processUpdate(array $update, array $connection, string $connectionSlug, MessageMapper $messageMapper, ConfigManager $configManager): void
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
        $configManager->updateConnectionFields($connectionSlug, $updateFields);
    } elseif ($trackerId > 0 && $prefixChecked && ($connection['field_prefix'] ?? 'telegrammessage') === 'telegrammessage') {
        // Prefix ya verificado y es el default — marcar verified para evitar
        // que getMediaGalleryId() haga otra llamada API.
        $tikiClient->setPrefixVerified(true);
    }

    $tgClient = new TelegramClient(
        botToken: $connection['bot_token']
    );

    // Derivar URL del admin panel desde el request actual
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $adminUrl = $protocol . '://' . $host . $scriptDir . '/admin.php';

    $collectManager = new CollectSessionManager();
    $handler = new WebhookHandler(
        tikiWikiClient: $tikiClient,
        telegramClient: $tgClient,
        messageMapper: $messageMapper,
        trackerId: $trackerId,
        adminUrl: $adminUrl,
        connectionName: $connectionSlug,
        collectSessionManager: $collectManager
    );
    $handler->processUpdate($update);
}
