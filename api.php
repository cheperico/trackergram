<?php
/**
 * trackerGram - Webhook endpoint para Telegram → TikiWiki
 * 
 * Punto de entrada HTTP para webhooks de Telegram.
 * Busca la conexión configurada por chat_id y usa sus credenciales.
 * Si no encuentra conexión, fallback al modo legacy (constantes globales).
 */

require_once 'bootstrap.php';
require_once 'ConfigManager.php';

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

    // 5. Buscar conexión por chat_id
    $secretToken = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    $configManager = new ConfigManager();
    $connection = null;
    $connectionSlug = null;

    if ($chatId) {
        // Buscar entre todas las conexiones una que coincida con chat_id
        foreach ($configManager->listConnections() as $slug => $conn) {
            if ((int) ($conn['chat_id'] ?? 0) === $chatId && !empty($conn['enabled'])) {
                // Si hay secret_token en el header, verificar que coincida
                if ($secretToken !== '' && !empty($conn['webhook_secret'])) {
                    if (hash_equals($conn['webhook_secret'], $secretToken)) {
                        $connection = $conn;
                        $connectionSlug = $slug;
                        break;
                    }
                } else {
                    // Sin secret token, tomar la primera coincidencia por chat_id
                    $connection = $conn;
                    $connectionSlug = $slug;
                    break;
                }
            }
        }
    }

    // 6. Si no se encontró conexión, intentar modo legacy (constantes globales)
    $useConnection = $connection !== null;

    if (!$useConnection) {
        // Legacy: verificar secret token global
        if (defined('TELEGRAM_WEBHOOK_SECRET') && TELEGRAM_WEBHOOK_SECRET !== '') {
            if (!hash_equals(TELEGRAM_WEBHOOK_SECRET, $secretToken)) {
                log_message("trackerGram: Acceso denegado — secret token no coincide (ni conexión ni legacy)", true);
                http_response_code(403);
                die(json_encode(['error' => 'Acceso denegado']));
            }
        } else {
            // No hay secret global configurado
            if (empty($secretToken)) {
                log_message("trackerGram: WEBHOOK_SECRET no configurado y sin conexión — rechazando", true);
                http_response_code(500);
                die(json_encode(['error' => 'Webhook secret no configurado']));
            }
        }
    }

    // 7. Procesar el update
    if (ASYNC_PROCESSING) {
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
            processUpdate($update, $connection, $connectionSlug, $webhookHandler, $messageMapper);
        }
        echo json_encode(['status' => 'ok']);
    } else {
        // Modo sync: procesar inmediatamente
        processUpdate($update, $connection, $connectionSlug, $webhookHandler, $messageMapper);
        echo json_encode(['status' => 'ok']);
    }
}

/**
 * Procesar un update de Telegram usando la conexión encontrada o el handler legacy
 */
function processUpdate(array $update, ?array $connection, ?string $connectionSlug, WebhookHandler $legacyHandler, MessageMapper $messageMapper): void
{
    if ($connection !== null) {
        // Usar credenciales de la conexión
        $tikiClient = new TikiWikiClient(
            apiUrl: $connection['tiki_api_url'],
            token: $connection['tiki_api_token'],
            timeout: TIMEOUT_TIKIWIKI_API,
            uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
        );
        $tgClient = new TelegramClient(
            botToken: $connection['bot_token']
        );
        $handler = new WebhookHandler(
            tikiWikiClient: $tikiClient,
            telegramClient: $tgClient,
            messageMapper: $messageMapper,
            trackerId: (int) $connection['tracker_id']
        );
        $handler->processUpdate($update);
    } else {
        // Fallback legacy: usar el handler de bootstrap
        $legacyHandler->processUpdate($update);
    }
}
