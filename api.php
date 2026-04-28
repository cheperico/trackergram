<?php
/**
 * trackerGram - Webhook endpoint para Telegram → TikiWiki
 */

require_once 'config.php';

// Validar secret token del webhook si está configurado
if (TELEGRAM_WEBHOOK_SECRET) {
    $secretToken = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($secretToken !== TELEGRAM_WEBHOOK_SECRET) {
        http_response_code(403);
        die(json_encode(['error' => 'Acceso denegado']));
    }
}

/**
 * Obtener URL de archivo de Telegram
 */
function getFileUrl($fileId)
{
    $url = TELEGRAM_API_URL . 'getFile?file_id=' . $fileId;

    // Usar context para evitar que file_get_contents se quede colgado indefinidamente
    // (FIX: Previene timeouts cuando la API de Telegram tarda en responder)
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        return false; // Consistente: return false en caso de error
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['result']['file_path'])) {
        return false; // Consistente: return false en caso de error
    }

    return 'https://api.telegram.org/file/bot' . TELEGRAM_BOT_TOKEN . '/' . $data['result']['file_path'];
}

/**
 * Extraer datos de mensaje según el tipo (texto, multimedia, sistema)
 */
function extractMessageData($message)
{
    $data = [
        'type' => 'text',
        'text' => $message['text'] ?? '',
        'media_url' => null,
        'media_type' => null,
        'media_size' => null,
        'media_caption' => null,
        'system_message' => null
    ];

    // Mensajes de texto
    if (isset($message['text'])) {
        $data['text'] = $message['text'];
    }

    // Fotos
    if (isset($message['photo'])) {
        $photo = end($message['photo']); // Última foto (mayor resolución)
        $data['type'] = 'photo';
        $data['media_url'] = getFileUrl($photo['file_id']) ?? $photo['file_id'];
        $data['media_type'] = 'image/jpeg';
        $data['media_size'] = $photo['file_size'] ?? null;
        $data['text'] = '<img src="' . htmlspecialchars($data['media_url']) . '" alt="Foto" />';
        if (isset($message['caption'])) {
            $data['text'] .= '<br/>' . htmlspecialchars($message['caption']);
        }
    }

    // Videos
    if (isset($message['video'])) {
        $video = $message['video'];
        $data['type'] = 'video';
        $data['media_url'] = getFileUrl($video['file_id']) ?? $video['file_id'];
        $data['media_type'] = $video['mime_type'] ?? 'mp4';
        $data['media_size'] = $video['file_size'] ?? null;
        $data['media_caption'] = $message['caption'] ?? null;
        $data['text'] = '<video src="' . htmlspecialchars($data['media_url']) . '" controls>Video</video>';
        if (isset($message['caption'])) {
            $data['text'] .= '<br/>' . htmlspecialchars($message['caption']);
        }
    }

    // Audio
    if (isset($message['audio'])) {
        $audio = $message['audio'];
        $data['type'] = 'audio';
        $data['media_url'] = getFileUrl($audio['file_id']) ?? $audio['file_id'];
        $data['media_type'] = $audio['mime_type'] ?? 'mp3';
        $data['media_size'] = $audio['file_size'] ?? null;
        $data['text'] = '<audio src="' . htmlspecialchars($data['media_url']) . '" controls>Audio</audio>';
        if (isset($audio['title'])) {
            $data['text'] .= '<br/>' . htmlspecialchars($audio['title']);
        }
    }

    // Documentos
    if (isset($message['document'])) {
        $document = $message['document'];
        $data['type'] = 'document';
        $data['media_url'] = getFileUrl($document['file_id']) ?? $document['file_id'];
        $data['media_type'] = $document['mime_type'] ?? 'application/octet-stream';
        $data['media_size'] = $document['file_size'] ?? null;
        $data['media_caption'] = $message['caption'] ?? null;
        $fileName = $document['file_name'] ?? 'Documento';
        $data['text'] = '<a href="' . htmlspecialchars($data['media_url']) . '">' . htmlspecialchars($fileName) . '</a>';
    }

    // Stickers
    if (isset($message['sticker'])) {
        $sticker = $message['sticker'];
        $data['type'] = 'sticker';
        $data['media_url'] = getFileUrl($sticker['file_id']) ?? $sticker['file_id'];
        $data['media_type'] = 'webp';
        $data['text'] = '<img src="' . htmlspecialchars($data['media_url']) . '" alt="Sticker" />';
    }

    // Notas de voz
    if (isset($message['voice'])) {
        $voice = $message['voice'];
        $data['type'] = 'voice';
        $data['media_url'] = getFileUrl($voice['file_id']) ?? $voice['file_id'];
        $data['media_type'] = $voice['mime_type'] ?? 'ogg';
        $data['media_size'] = $voice['file_size'] ?? null;
        $data['text'] = '<audio src="' . htmlspecialchars($data['media_url']) . '" controls>Nota de voz</audio>';
    }

    // Video messages (videos redonditos)
    if (isset($message['video_note'])) {
        $videoNote = $message['video_note'];
        $data['type'] = 'video_note';
        $data['media_url'] = getFileUrl($videoNote['file_id']) ?? $videoNote['file_id'];
        $data['media_type'] = $videoNote['mime_type'] ?? 'mp4';
        $data['media_size'] = $videoNote['file_size'] ?? null;
        $data['text'] = '<video src="' . htmlspecialchars($data['media_url']) . '" controls>Video circular</video>';
    }

    // Mensajes de sistema (cambio de nombre de topic, etc.)
    if (isset($message['forum_topic_edited'])) {
        $topicEdit = $message['forum_topic_edited'];
        $data['type'] = 'system';
        $data['system_message'] = 'Topic renombrado: ' . ($topicEdit['name'] ?? 'Desconocido');
        $data['text'] = 'Topic renombrado';
    }

    if (isset($message['forum_topic_closed'])) {
        $data['type'] = 'system';
        $data['system_message'] = 'Topic cerrado';
        $data['text'] = 'Topic cerrado';
    }

    if (isset($message['forum_topic_reopened'])) {
        $data['type'] = 'system';
        $data['system_message'] = 'Topic reabierto';
        $data['text'] = 'Topic reabierto';
    }

    // Otros tipos no manejados específicamente
    if ($data['type'] === 'text' && !isset($message['text'])) {
        $data['type'] = 'other';
        $data['text'] = '[Mensaje no soportado]';
    }

    return $data;
}

/**
 * Enviar mensaje a TikiWiki tracker
 */
function sendToTikiWiki($data)
{
    $trackerId = TIKIWIKI_TRACKER_ID;
    $url = TIKIWIKI_API_URL . "trackers/{$trackerId}/items";

    // Mapear campos del mensaje a los permanent names de TikiWiki con formato fields[fieldName]
    $postFields = [
        'fields[telegrammessageTelegramMessageId]' => $data['message_id'],
        'fields[telegrammessageChatId]' => $data['chat_id'],
        'fields[telegrammessageTopicId]' => $data['topic_id'],
        'fields[telegrammessageUserId]' => $data['user_id'],
        'fields[telegrammessageUsername]' => htmlspecialchars($data['username'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageFirstName]' => htmlspecialchars($data['first_name'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageLastName]' => htmlspecialchars($data['last_name'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageMessageType]' => $data['message_type'],
        'fields[telegrammessageText]' => htmlspecialchars($data['text'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageMediaUrl]' => $data['media_url'],
        'fields[telegrammessageFileUrl]' => $data['file_url'],
        'fields[telegrammessageMediaType]' => $data['media_type'],
        'fields[telegrammessageMediaSize]' => $data['media_size'],
        'fields[telegrammessageMediaCaption]' => htmlspecialchars($data['media_caption'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageMessageDate]' => $data['date']
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/x-www-form-urlencoded",
        "Authorization: Bearer " . TIKIWIKI_TOKEN,
        "User-Agent: trackerGram/1.0",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Seguir redirecciones (error 302)
    // Reducido de 10 a 5 segundos para evitar que los procesos de PHP se acumulen
    // y saturen el servidor (FIX: Límite de procesos en hosting compartido)
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        log_message("Error cURL al enviar a TikiWiki: $error");
        return false;
    }

    if ($httpCode !== 200 && $httpCode !== 201) {
        log_message("Error HTTP al enviar a TikiWiki: $httpCode - Response: $response");
        return false;
    }

    // Validar que la respuesta sea JSON y contenga itemId (para detectar errores PHP que devuelven 200)
    $responseData = json_decode($response, true);
    if (!$responseData || !isset($responseData['itemId'])) {
        // Limpiar respuesta para logging: remover tags y newlines, truncar a 300 chars
        // Evitar truncar en medio de tags HTML
        $cleanResponse = strip_tags($response);
        $cleanResponse = str_replace(["\r", "\n"], ' ', $cleanResponse);
        $cleanResponse = substr($cleanResponse, 0, 300);
        if (strlen($cleanResponse) >= 300) {
            $cleanResponse .= '... [truncated]';
        }
        log_message("Error de formato en respuesta de TikiWiki (Status $httpCode): $cleanResponse");
        return false;
    }

    log_message("Mensaje enviado a TikiWiki: message_id={$data['message_id']}");
    return true;
}

/**
 * Procesar actualización de Telegram
 */
function processUpdate($update)
{
    log_message("processUpdate iniciado");

    if (!isset($update['message'])) {
        return;
    }

    $message = $update['message'];
    
    // Validar campos requeridos del mensaje
    $requiredFields = ['message_id', 'chat', 'from', 'date'];
    foreach ($requiredFields as $field) {
        if (!isset($message[$field])) {
            log_message("ERROR: Campo requerido '$field' no encontrado en el mensaje");
            return;
        }
    }
    
    // Validar subcampos requeridos
    $requiredSubFields = [
        'chat.id',
        'from.id',
        'from.first_name'
    ];
    
    foreach ($requiredSubFields as $fieldPath) {
        $keys = explode('.', $fieldPath);
        $value = $message;
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                log_message("ERROR: Subcampo requerido '$fieldPath' no encontrado en el mensaje");
                return;
            }
            $value = $value[$key];
        }
    }
    
    $chatId = $message['chat']['id'];

    // Validar chat_id si ALLOWED_CHAT_IDS está configurado
    if (!empty(ALLOWED_CHAT_IDS) && !in_array($chatId, ALLOWED_CHAT_IDS)) {
        log_message("Chat $chatId no está en la lista de permitidos");
        return;
    }

    $topicId = $message['message_thread_id'] ?? 'general';

    // Determinar tipo de mensaje y extraer datos
    $messageData = extractMessageData($message);

    // Enviar mensaje a TikiWiki
    $tikiData = [
        'message_id' => $message['message_id'],
        'chat_id' => $chatId,
        'topic_id' => $topicId,
        'user_id' => $message['from']['id'],
        'username' => $message['from']['username'] ?? null,
        'first_name' => $message['from']['first_name'] ?? '',
        'last_name' => $message['from']['last_name'] ?? '',
        'message_type' => $messageData['type'],
        'text' => $messageData['text'],
        'media_url' => $messageData['media_url'],
        'file_url' => $messageData['media_url'], // Mismo campo que media_url para compatibilidad
        'media_type' => $messageData['media_type'],
        'media_size' => $messageData['media_size'],
        'media_caption' => $messageData['media_caption'],
        'date' => $message['date']
    ];

    // Intentar enviar a TikiWiki con reintentos
    // Reducido de 3 a 2 para evitar bloqueo prolongado de procesos de PHP
    // (FIX: Límite de procesos en hosting compartido)
    $maxRetries = 2;
    $success = false;

    for ($i = 0; $i < $maxRetries; $i++) {
        if (sendToTikiWiki($tikiData)) {
            $success = true;
            break;
        }

        if ($i < $maxRetries - 1) {
            log_message("Reintento " . ($i + 1) . " para message_id={$tikiData['message_id']}");
            // Usar usleep en lugar de sleep para evitar bloquear el proceso por completo
            // 100000 microsegundos = 0.1 segundos
            usleep(100000); // Esperar 0.1 segundos antes de reintentar
        }
    }

    if (!$success) {
        log_message("ERROR: No se pudo enviar mensaje a TikiWiki después de $maxRetries intentos: message_id={$tikiData['message_id']}");
    }

    log_message("Mensaje procesado: Topic $topicId, User {$message['from']['first_name']}");
}

// Manejar webhook de Telegram
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);

    if ($update) {
        // Procesar la actualización
        processUpdate($update);
        echo json_encode(['status' => 'ok']);
    } else {
        log_message("Error al decodificar JSON del webhook");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
    }
} else {
    // GET request para verificar estado
    echo json_encode(['status' => 'trackerGram webhook endpoint']);
}
?>