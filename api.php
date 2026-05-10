<?php
/**
 * trackerGram - Webhook endpoint para Telegram → TikiWiki
 */

require_once 'config.php';

// Cache para el galleryId (usando variable estática en función en vez de global)
$mediaGalleryIdCache = null;

/**
 * Establecer el galleryId en caché
 */
function setMediaGalleryId(?int $galleryId): void
{
    global $mediaGalleryIdCache;
    $mediaGalleryIdCache = $galleryId;
}

/**
 * Obtener el galleryId desde la caché
 */
function getCachedMediaGalleryId(): ?int
{
    global $mediaGalleryIdCache;
    return $mediaGalleryIdCache;
}

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
function getFileUrl(string $fileId): ?string
{
    $url = TELEGRAM_API_URL . 'getFile?file_id=' . $fileId;

    // Usar context para evitar que file_get_contents se quede colgado indefinidamente
    // (FIX: Previene timeouts cuando la API de Telegram tarda en responder)
    $ctx = stream_context_create(['http' => ['timeout' => TIMEOUT_TELEGRAM_API]]);
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
 * Obtener el nombre de un topic de Telegram
 * Primero busca en cache local, luego intenta API
 */
function getTopicName(int $chatId, int $messageThreadId): string
{
    // Primero buscar en cache local
    $cacheFile = __DIR__ . '/topic_names.json';
    if (file_exists($cacheFile)) {
        $topics = json_decode(file_get_contents($cacheFile), true);
        if (isset($topics[$messageThreadId])) {
            return $topics[$messageThreadId];
        }
    }
    
    // Intentar con la API
    $url = TELEGRAM_API_URL . 'getForumTopic';
    $postFields = http_build_query([
        'chat_id' => $chatId,
        'message_thread_id' => $messageThreadId
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TELEGRAM_API);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['result']['name'])) {
            return $data['result']['name'];
        }
    }
    
    return 'General';
}

/**
 * Verificar si un message_id ya existe en el tracker (para evitar duplicados)
 */
function messageExistsInTracker(int $messageId): bool
{
    $trackerId = TIKIWIKI_TRACKER_ID;
    // Buscar items con ese message_id
    $url = TIKIWIKI_API_URL . "trackers/{$trackerId}/items?fields=telegrammessageTelegramMessageId";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . TIKIWIKI_TOKEN,
        "User-Agent: trackerGram/1.0",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TELEGRAM_API);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("trackerGram: ERROR checking duplicate: HTTP $httpCode");
        return false; // En caso de error, permitir envío
    }
    
    $data = json_decode($response, true);
    $count = $data['count'] ?? 0;
    
    if ($count > 0) {
        error_log("trackerGram: DUPLICATE detected for message_id: $messageId");
        return true;
    }
    
    return false;
}

/**
 * Obtener el galleryId del campo de archivo en el tracker
 * Usa caché estático para evitar múltiples llamadas a la API
 */
function getMediaGalleryId(): ?int
{
    static $cachedGalleryId = null;
    
    if ($cachedGalleryId !== null) {
        return $cachedGalleryId;
    }
    
    $trackerId = TIKIWIKI_TRACKER_ID;
    $url = TIKIWIKI_API_URL . "trackers/{$trackerId}/fields";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . TIKIWIKI_TOKEN,
        "User-Agent: trackerGram/1.0",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TELEGRAM_API);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("trackerGram: ERROR getting tracker fields: HTTP $httpCode");
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['fields'])) {
        error_log("trackerGram: ERROR parsing tracker fields response");
        return null;
    }
    
    // Buscar el campo telegrammessageMedia
    foreach ($data['fields'] as $field) {
        if (($field['permName'] ?? '') === 'telegrammessageMedia') {
            $options = $field['options'] ?? [];
            
            // El galleryId puede estar en diferentes formatos
            $galleryId = $options['galleryId'] ?? $options['gallery_id'] ?? $options['id'] ?? null;
            
            if ($galleryId) {
                error_log("trackerGram: Found media field galleryId: $galleryId");
                $cachedGalleryId = (int)$galleryId;
                return $cachedGalleryId;
            }
        }
    }
    
    error_log("trackerGram: WARNING - telegrammessageMedia field not found or has no galleryId configured");
    $cachedGalleryId = 29; // Fallback
    return $cachedGalleryId;
}

/**
 * Subir archivo a TikiWiki file gallery
 */
function uploadToTikiWiki(string $filePath, string $fileName, ?string $mimeType = null): ?string
{
    global $mediaGalleryIdCache;
    
    // Usar cache si ya se obtuvo el galleryId
    if ($mediaGalleryIdCache === null) {
        $mediaGalleryIdCache = getMediaGalleryId();
    }
    
    // Si no se pudo obtener dinámicamente, usar fallback hardcodeado
    $galleryId = $mediaGalleryIdCache ?? 29;
    error_log("trackerGram: Using galleryId: $galleryId");
    $uploadUrl = TIKIWIKI_API_URL . 'galleries/upload';
    
    $cfile = curl_file_create($filePath, $mimeType ?: 'application/octet-stream', $fileName);
    
    $postData = [
        'galleryId' => $galleryId,
        'data' => $cfile,
        'name' => $fileName,
        'title' => $fileName,
        'description' => 'Archivo subido desde Telegram - ' . date('Y-m-d H:i:s')
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uploadUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . TIKIWIKI_TOKEN,
        "User-Agent: trackerGram/1.0",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TIKIWIKI_UPLOAD);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        error_log("trackerGram: ERROR uploading file: HTTP $httpCode, Response: $response");
        return false;
    }
    
    $responseData = json_decode($response, true);
    if (!$responseData) {
        error_log("trackerGram: ERROR: Response is not valid JSON: $response");
        return false;
    }
    
    $fileId = $responseData['fileId'] ?? $responseData['file_id'] ?? null;
    
    if ($fileId) {
        error_log("trackerGram: File uploaded to TikiWiki gallery, fileId: $fileId");
        return $fileId;
    }
    
    error_log("trackerGram: ERROR: No fileId in response: " . json_encode($responseData));
    return false;
}

/**
 * Descargar archivo de Telegram y subir a TikiWiki
 */
function downloadAndUploadMedia(string $fileId, ?string $fileName = null, ?string $mimeType = null): ?string
{
    $fileUrl = getFileUrl($fileId);
    if (!$fileUrl) {
        error_log("trackerGram: Cannot get URL for FileID: $fileId");
        return false;
    }
    
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $fileContent = @file_get_contents($fileUrl, false, $ctx);
    
    if ($fileContent === false) {
        error_log("trackerGram: Cannot download from: $fileUrl");
        return false;
    }
    
    $tempFile = tempnam(sys_get_temp_dir(), 'tg_media_');
    if (file_put_contents($tempFile, $fileContent) === false) {
        return false;
    }
    
    $result = uploadToTikiWiki($tempFile, $fileName, $mimeType);
    unlink($tempFile);
    
    return $result;
}

/**
 * Extraer datos de mensaje según el tipo (texto, multimedia, sistema)
 * @param array $message Datos del mensaje de Telegram
 * @return array Datos extraídos del mensaje
 */
function extractMessageData(array $message): array
{
    $data = [
        'type' => 'text',
        'text' => $message['text'] ?? '',
        'media_url' => null,
        'media_type' => null,
        'media_size' => null,
        'media_caption' => null,
        'system_message' => null,
        'uploaded_file_id' => null
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
        
        // Subir foto a TikiWiki
        $data['uploaded_file_id'] = downloadAndUploadMedia(
            $photo['file_id'],
            'telegram_photo_' . $photo['file_id'] . '.jpg',
            'image/jpeg'
        );
        
        $data['text'] = 'Foto: ' . $data['media_type'];
        if (isset($message['caption'])) {
            $data['text'] .= ' - ' . htmlspecialchars($message['caption']);
        }
    }

    // Videos
    if (isset($message['video'])) {
        $video = $message['video'];
        $data['type'] = 'video';
        $data['media_url'] = getFileUrl($video['file_id']) ?? $video['file_id'];
        $data['media_type'] = $video['mime_type'] ?? 'video/mp4';
        $data['media_size'] = $video['file_size'] ?? null;
        $data['media_caption'] = $message['caption'] ?? null;
        
        // Subir video a TikiWiki
        $fileName = 'telegram_video_' . $video['file_id'] . '.' . pathinfo($video['file_id'], PATHINFO_EXTENSION);
        $data['uploaded_file_id'] = downloadAndUploadMedia($video['file_id'], $fileName, $data['media_type']);
        
        $data['text'] = 'Video: ' . $data['media_type'];
        if (isset($message['caption'])) {
            $data['text'] .= ' - ' . htmlspecialchars($message['caption']);
        }
    }

    // Audio
    if (isset($message['audio'])) {
        $audio = $message['audio'];
        $data['type'] = 'audio';
        $data['media_url'] = getFileUrl($audio['file_id']) ?? $audio['file_id'];
        $data['media_type'] = $audio['mime_type'] ?? 'audio/mpeg';
        $data['media_size'] = $audio['file_size'] ?? null;
        
        // Subir audio a TikiWiki
        $fileName = 'telegram_audio_' . $audio['file_id'] . '.mp3';
        $data['uploaded_file_id'] = downloadAndUploadMedia($audio['file_id'], $fileName, $data['media_type']);
        
        $data['text'] = 'Audio: ' . $data['media_type'];
        if (isset($audio['title'])) {
            $data['text'] .= ' - ' . htmlspecialchars($audio['title']);
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
        
        // Subir documento a TikiWiki
        $data['uploaded_file_id'] = downloadAndUploadMedia($document['file_id'], $fileName, $data['media_type']);
        
        $data['text'] = 'Documento: ' . $data['media_type'] . ' - ' . htmlspecialchars($fileName);
    }

    // Stickers
    if (isset($message['sticker'])) {
        $sticker = $message['sticker'];
        $data['type'] = 'sticker';
        $data['media_url'] = getFileUrl($sticker['file_id']) ?? $sticker['file_id'];
        $data['media_type'] = 'image/webp';
        
        // Subir sticker a TikiWiki
        $fileName = 'telegram_sticker_' . $sticker['file_id'] . '.webp';
        $data['uploaded_file_id'] = downloadAndUploadMedia($sticker['file_id'], $fileName, 'image/webp');
        
        $data['text'] = 'Sticker: ' . $data['media_type'];
    }

    // Notas de voz
    if (isset($message['voice'])) {
        $voice = $message['voice'];
        $data['type'] = 'voice';
        $data['media_url'] = getFileUrl($voice['file_id']) ?? $voice['file_id'];
        $data['media_type'] = $voice['mime_type'] ?? 'audio/ogg';
        $data['media_size'] = $voice['file_size'] ?? null;
        
        error_log("trackerGram: VOICE - file_id: {$voice['file_id']}, mime_type: {$voice['mime_type']}, size: {$data['media_size']}");
        
        // Determinar extensión basada en mime_type o usar .ogg por defecto
        $ext = '.ogg';
        if ($data['media_type'] === 'audio/mpeg' || $data['media_type'] === 'audio/mp3') {
            $ext = '.mp3';
        } elseif ($data['media_type'] === 'audio/webm') {
            $ext = '.webm';
        } elseif ($data['media_type'] === 'audio/wav') {
            $ext = '.wav';
        }
        
        // Subir nota de voz a TikiWiki
        $fileName = 'telegram_voice_' . $voice['file_id'] . $ext;
        $data['uploaded_file_id'] = downloadAndUploadMedia($voice['file_id'], $fileName, $data['media_type']);
        
        error_log("trackerGram: VOICE upload result: " . ($data['uploaded_file_id'] ? "OK: {$data['uploaded_file_id']}" : "FAILED"));
        
        $data['text'] = 'Nota de voz: ' . $data['media_type'];
    }

    // Video messages (videos redonditos)
    if (isset($message['video_note'])) {
        $videoNote = $message['video_note'];
        $data['type'] = 'video_note';
        $data['media_url'] = getFileUrl($videoNote['file_id']) ?? $videoNote['file_id'];
        $data['media_type'] = $videoNote['mime_type'] ?? 'video/mp4';
        $data['media_size'] = $videoNote['file_size'] ?? null;
        
        // Subir video_note a TikiWiki
        $fileName = 'telegram_video_note_' . $videoNote['file_id'] . '.mp4';
        $data['uploaded_file_id'] = downloadAndUploadMedia($videoNote['file_id'], $fileName, $data['media_type']);
        
        $data['text'] = 'Video circular: ' . $data['media_type'];
    }

    // Mensajes de sistema (cambio de nombre de topic, etc.)
    if (isset($message['forum_topic_created'])) {
        $topicCreated = $message['forum_topic_created'];
        $data['type'] = 'system';
        $topicName = $topicCreated['name'] ?? 'Nuevo Topic';
        $data['system_message'] = 'Topic creado: ' . $topicName;
        $data['text'] = 'Topic creado: ' . $topicName;
        $data['topic_name'] = $topicName;
        // Guardar en un archivo temporal para uso posterior
        if (isset($message['message_thread_id'])) {
            file_put_contents(__DIR__ . '/topic_names.json', json_encode([
                $message['message_thread_id'] => $topicName
            ]), JSON_PRETTY_PRINT);
        }
    }
    
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

    // Ubicación
    if (isset($message['location'])) {
        $location = $message['location'];
        $data['type'] = 'location';
        $data['media_type'] = 'location';
        
        // Formato TikiWiki: longitude,latitude,zoom
        $zoom = 15; // Zoom por defecto
        $data['location'] = $location['longitude'] . ',' . $location['latitude'] . ',' . $zoom;
        
        $data['text'] = '📍 Ubicación';
        if (isset($location['horizontal_accuracy'])) {
            $data['text'] .= ' (precisión: ' . $location['horizontal_accuracy'] . 'm)';
        }
    }

    // Contacto
    if (isset($message['contact'])) {
        $contact = $message['contact'];
        $data['type'] = 'contact';
        $data['media_type'] = 'contact';
        $phone = $contact['phone_number'] ?? '';
        $firstName = $contact['first_name'] ?? '';
        $lastName = $contact['last_name'] ?? '';
        $data['text'] = '👤 Contacto: ' . $firstName . ' ' . $lastName . ' (' . $phone . ')';
    }

    // Encuesta
    if (isset($message['poll'])) {
        $poll = $message['poll'];
        $data['type'] = 'poll';
        $data['media_type'] = 'poll';
        $question = $poll['question'] ?? 'Sin pregunta';
        $options = count($poll['options'] ?? []);
        $data['text'] = '📊 Encuesta: ' . $question . ' (' . $options . ' opciones)';
    }

    // Sticker animated (sin archivo)
    if (isset($message['animation'])) {
        $data['type'] = 'animation';
        $data['media_type'] = $message['animation']['mime_type'] ?? 'animation/gif';
        $data['text'] = '🎬 Animation: ' . $data['media_type'];
    }

    // Otros tipos no manejados específicamente
    if ($data['type'] === 'text' && !isset($message['text'])) {
        // Obtener las claves del mensaje para mostrar qué tipo es
        $messageKeys = array_keys($message);
        $unknownTypes = array_diff($messageKeys, ['message_id', 'from', 'chat', 'date', 'text', 'photo', 'video', 'audio', 'document', 'sticker', 'voice', 'video_note', 'location', 'contact', 'poll', 'animation', 'forum_topic_edited', 'forum_topic_closed', 'forum_topic_reopened', 'caption', 'edit_date', 'entities', 'forward_from', 'forward_from_chat', 'reply_to_message', 'via_bot']);
        
        if (!empty($unknownTypes)) {
            $data['type'] = 'other';
            $data['text'] = '[Tipo no soportado: ' . implode(', ', $unknownTypes) . ']';
        } else {
            $data['type'] = 'other';
            $data['text'] = '[Mensaje sin texto]';
        }
    }

    return $data;
}

/**
 * Enviar mensaje a TikiWiki tracker
 * @param array $data Datos del mensaje a enviar
 * @return bool True si se envió correctamente
 */
function sendToTikiWiki(array $data): bool
{
    $trackerId = TIKIWIKI_TRACKER_ID;
    $url = TIKIWIKI_API_URL . "trackers/{$trackerId}/items";

    // Mapear campos del mensaje a los permanent names de TikiWiki con formato fields[fieldName]
    $postFields = [
        'fields[telegrammessageTelegramMessageId]' => $data['message_id'],
        'fields[telegrammessageChatId]' => $data['chat_id'],
        'fields[telegrammessageChatTitle]' => htmlspecialchars($data['chat_title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageTopicId]' => $data['topic_id'],
        'fields[telegrammessageTopicTitle]' => htmlspecialchars($data['topic_title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageUserId]' => $data['user_id'],
        'fields[telegrammessageUsername]' => htmlspecialchars($data['username'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageFirstName]' => htmlspecialchars($data['first_name'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageLastName]' => htmlspecialchars($data['last_name'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageMessageType]' => $data['message_type'],
        'fields[telegrammessageText]' => htmlspecialchars($data['text'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageLocation]' => $data['location'] ?? '',
        'fields[telegrammessageMediaUrl]' => $data['media_url'],
        'fields[telegrammessageFileUrl]' => $data['file_url'],
        'fields[telegrammessageMediaType]' => $data['media_type'],
        'fields[telegrammessageMediaSize]' => $data['media_size'],
        'fields[telegrammessageMediaCaption]' => htmlspecialchars($data['media_caption'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageMessageDate]' => $data['date']
    ];
    
    // Agregar archivo subido si existe
    if (!empty($data['uploaded_file_id'])) {
        $postFields['fields[telegrammessageMedia]'] = $data['uploaded_file_id'];
    }

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
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TIKIWIKI_API);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        error_log("Error cURL al enviar a TikiWiki: $error");
        return false;
    }

    if ($httpCode !== 200 && $httpCode !== 201) {
        error_log("Error HTTP al enviar a TikiWiki: $httpCode - Response: $response");
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
        error_log("Error de formato en respuesta de TikiWiki (Status $httpCode): $cleanResponse");
        return false;
    }

    error_log("Mensaje enviado a TikiWiki: message_id={$data['message_id']}");
    return true;
}

/**
 * Procesar actualización de Telegram
 * @param array $update Datos de la actualización recibida del webhook
 */
function processUpdate(array $update): void
{
error_log("processUpdate iniciado");

    if (!isset($update['message'])) {
        return;
    }
    
$message = $update['message'];
    
    // Validar campos requeridos del mensaje
    $requiredFields = ['message_id', 'chat', 'from', 'date'];
    foreach ($requiredFields as $field) {
        if (!isset($message[$field])) {
            error_log("ERROR: Campo requerido '$field' no encontrado en el mensaje");
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
                error_log("ERROR: Subcampo requerido '$fieldPath' no encontrado en el mensaje");
                return;
            }
            $value = $value[$key];
        }
    }
    
    $chatId = $message['chat']['id'];
    $chatTitle = $message['chat']['title'] ?? $message['chat']['username'] ?? 'Chat ' . $chatId;

    // Validar chat_id si ALLOWED_CHAT_IDS está configurado
    if (!empty(ALLOWED_CHAT_IDS) && !in_array($chatId, ALLOWED_CHAT_IDS)) {
        error_log("Chat $chatId no está en la lista de permitidos");
        return;
    }

    $topicId = $message['message_thread_id'] ?? 'general';
    
    // Intentar obtener el nombre del topic desde reply_to_message (si es el mensaje de creación)
    $topicName = null;
    if (isset($message['reply_to_message']['forum_topic_created']['name'])) {
        $topicName = $message['reply_to_message']['forum_topic_created']['name'];
        // Guardar en cache
        $cacheFile = __DIR__ . '/topic_names.json';
        $topics = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
        $topics[$topicId] = $topicName;
        file_put_contents($cacheFile, json_encode($topics));
    } else {
        // Buscar en cache
        $topicName = getTopicName($chatId, $topicId);
    }
    
    // Si no se pudo obtener, usar ID como fallback
    if ($topicName === 'General' && is_numeric($topicId)) {
        $topicName = 'Topic-' . $topicId;
    }

    // Determinar tipo de mensaje y extraer datos
    $messageData = extractMessageData($message);

    // Verificar si el mensaje ya existe (evitar duplicados)
    if (messageExistsInTracker($message['message_id'])) {
        error_log("trackerGram: SKIPPING duplicate message_id={$message['message_id']}");
        return;
    }

    // Enviar mensaje a TikiWiki
    $tikiData = [
        'message_id' => $message['message_id'],
        'chat_id' => $chatId,
        'chat_title' => $chatTitle,
        'topic_id' => $topicId,
        'topic_title' => $topicName,
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
        'location' => $messageData['location'] ?? '',
        'uploaded_file_id' => $messageData['uploaded_file_id'] ?? null,
        'date' => $message['date']
    ];

    // Intentar enviar a TikiWiki con reintentos
    $maxRetries = RETRY_MAX_ATTEMPTS;
    $success = false;

    for ($i = 0; $i < $maxRetries; $i++) {
        if (sendToTikiWiki($tikiData)) {
            $success = true;
            break;
        }

        if ($i < $maxRetries - 1) {
            error_log("Reintento " . ($i + 1) . " para message_id={$tikiData['message_id']}");
            usleep(RETRY_DELAY_MICROSECONDS);
        }
    }

    if (!$success) {
        error_log("ERROR: No se pudo enviar mensaje a TikiWiki después de $maxRetries intentos: message_id={$tikiData['message_id']}");
    }

    error_log("Mensaje procesado: Topic $topicId, User {$message['from']['first_name']}");
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
        error_log("Error al decodificar JSON del webhook");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
    }
} else {
    // GET request para verificar estado
    echo json_encode(['status' => 'trackerGram webhook endpoint']);
}
?>