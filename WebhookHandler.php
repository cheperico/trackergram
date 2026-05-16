<?php
/**
 * trackerGram - WebhookHandler
 * 
 * Procesa actualizaciones de Telegram y las envía a TikiWiki.
 * Separa la lógica de negocio del manejo HTTP (api.php).
 */

class WebhookHandler
{
    /**
     * Obtener el nombre de un topic de Telegram desde cache local
     */
    public static function getTopicName(int $chatId, int $messageThreadId): string
    {
        $cacheFile = __DIR__ . '/topic_names.json';
        if (file_exists($cacheFile)) {
            $topics = json_decode(file_get_contents($cacheFile), true);
            $key = $chatId . ':' . $messageThreadId;
            if (isset($topics[$key])) {
                return $topics[$key];
            }
        }

        return 'General';
    }

    /**
     * Descargar archivo de Telegram y subir a TikiWiki
     */
    public static function downloadAndUploadMedia(string $fileId, ?string $fileName = null, ?string $mimeType = null): ?string
    {
        $fileUrl = TelegramClient::getFileUrl($fileId);
        if (!$fileUrl) {
            error_log("trackerGram: Cannot get download URL for file: $fileId");
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TELEGRAM_API);
        curl_exec($ch);
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);

        if ($contentLength > MEDIA_DOWNLOAD_MAX_SIZE) {
            error_log("trackerGram: File too large ($contentLength bytes) for file_id: $fileId");
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'tg_media_');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FILE, fopen($tempFile, 'wb'));
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TELEGRAM_DOWNLOAD);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("trackerGram: Cannot download file from Telegram: $fileId (HTTP $httpCode)");
            unlink($tempFile);
            return null;
        }

        $galleryId = TikiWikiClient::getMediaGalleryId() ?? 29;
        $result = TikiWikiClient::uploadFile($tempFile, $fileName, $galleryId);
        unlink($tempFile);

        return $result;
    }

    /**
     * Extraer datos de mensaje según el tipo
     */
    public static function extractMessageData(array $message): array
    {
        $info = MessageMapper::fromWebhook($message);

        $uploadedFileId = null;
        if ($info['file_id']) {
            $logType = strtoupper($info['type']);
            error_log("trackerGram: {$logType} - file_id: {$info['file_id']}" . ($info['media_size'] ? ", size: {$info['media_size']}" : ''));
            $uploadedFileId = self::downloadAndUploadMedia($info['file_id'], $info['file_name'], $info['mime_type']);
            error_log("trackerGram: {$logType} upload result: " . ($uploadedFileId ?? 'null'));
        }

        $chatId = $message['chat']['id'] ?? 0;
        if (isset($message['forum_topic_created']) && isset($message['message_thread_id'])) {
            $cacheFile = __DIR__ . '/topic_names.json';
            $topics = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
            $topics[$chatId . ':' . $message['message_thread_id']] = $info['topic_name'];
            file_put_contents($cacheFile, json_encode($topics), LOCK_EX);
        }

        if (isset($message['forum_topic_edited']) && isset($message['message_thread_id'])) {
            $cacheFile = __DIR__ . '/topic_names.json';
            $topics = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
            $topics[$chatId . ':' . $message['message_thread_id']] = $info['topic_name'];
            file_put_contents($cacheFile, json_encode($topics), LOCK_EX);
        }

        return [
            'type' => $info['type'],
            'text' => $info['text'],
            'media_type' => $info['media_type'],
            'media_size' => $info['media_size'],
            'media_caption' => $info['media_caption'],
            'system_message' => $info['system_message'],
            'location' => $info['location'] ?? '',
            'uploaded_file_id' => $uploadedFileId,
        ];
    }

    /**
     * Enviar datos a TikiWiki con reintentos
     */
    public static function sendToTikiWikiWithRetries(array $tikiData): bool
    {
        $maxRetries = RETRY_MAX_ATTEMPTS;
        for ($i = 0; $i < $maxRetries; $i++) {
            $fields = MessageMapper::toWikiFields($tikiData);
            if (TikiWikiClient::createTrackerItem(TIKIWIKI_TRACKER_ID, $fields)) {
                return true;
            }
            if ($i < $maxRetries - 1) {
                error_log("Reintento " . ($i + 1) . " para message_id={$tikiData['message_id']}");
                usleep(RETRY_DELAY_MICROSECONDS);
            }
        }
        return false;
    }

    /**
     * Procesar mensaje regular de Telegram
     */
    public static function processMessage(array $message): void
    {
        $requiredFields = ['message_id', 'chat', 'from', 'date'];
        foreach ($requiredFields as $field) {
            if (!isset($message[$field])) {
                error_log("ERROR: Campo requerido '$field' no encontrado en el mensaje");
                return;
            }
        }

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

        if (!empty(ALLOWED_CHAT_IDS) && !in_array($chatId, ALLOWED_CHAT_IDS)) {
            error_log("Chat $chatId no está en la lista de permitidos");
            return;
        }

        $topicId = $message['message_thread_id'] ?? 0;

        $topicName = null;
        if (isset($message['reply_to_message']['forum_topic_created']['name'])) {
            $topicName = $message['reply_to_message']['forum_topic_created']['name'];
            $cacheFile = __DIR__ . '/topic_names.json';
            $topics = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
            $topics[$chatId . ':' . $topicId] = $topicName;
            file_put_contents($cacheFile, json_encode($topics), LOCK_EX);
        } elseif ($topicId > 0) {
            $topicName = self::getTopicName($chatId, $topicId);
        }

        if ($topicId > 0 && $topicName === 'General') {
            $topicName = 'Topic-' . $topicId;
        } elseif (!$topicName) {
            $topicName = 'General';
        }

        $messageData = self::extractMessageData($message);

        if (TikiWikiClient::messageExists(TIKIWIKI_TRACKER_ID, $message['message_id'], $chatId) > 0) {
            error_log("trackerGram: SKIPPING duplicate message_id={$message['message_id']}");
            return;
        }

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
            'media_type' => $messageData['media_type'],
            'media_size' => $messageData['media_size'],
            'media_caption' => $messageData['media_caption'],
            'location' => $messageData['location'] ?? '',
            'uploaded_file_id' => $messageData['uploaded_file_id'] ?? null,
            'date' => $message['date']
        ];

        if (!self::sendToTikiWikiWithRetries($tikiData)) {
            error_log("ERROR: No se pudo enviar mensaje a TikiWiki después de " . RETRY_MAX_ATTEMPTS . " intentos: message_id={$tikiData['message_id']}");
        } elseif (TikiWikiClient::messageExists(TIKIWIKI_TRACKER_ID, $message['message_id'], $chatId) > 1) {
            error_log("WARNING: duplicado detectado post-insert para message_id={$message['message_id']} — posible race condition");
        }

        error_log("Mensaje procesado: Topic $topicId, User {$message['from']['first_name']}");
    }

    /**
     * Procesar reacción a mensaje (message_reaction)
     */
    public static function processMessageReaction(array $reaction): void
    {
        $chatId = $reaction['chat']['id'];
        $chatTitle = $reaction['chat']['title'] ?? $reaction['chat']['username'] ?? 'Chat ' . $chatId;

        if (!empty(ALLOWED_CHAT_IDS) && !in_array($chatId, ALLOWED_CHAT_IDS)) {
            error_log("Chat $chatId no está en la lista de permitidos");
            return;
        }

        $originalMessageId = $reaction['message_id'];
        $user = $reaction['user'] ?? [];
        $userId = $user['id'] ?? 0;
        $firstName = $user['first_name'] ?? 'Unknown';
        $lastName = $user['last_name'] ?? '';

        $oldEmojis = array_map(fn($r) => $r['emoji'] ?? '❓', $reaction['old_reaction'] ?? []);
        $newEmojis = array_map(fn($r) => $r['emoji'] ?? '❓', $reaction['new_reaction'] ?? []);

        $oldStr = !empty($oldEmojis) ? implode('', $oldEmojis) . ' → ' : '';
        $newStr = implode('', $newEmojis);
        $text = '😀 ' . $firstName . ' ' . $oldStr . $newStr . ' en mensaje ' . $originalMessageId;

        $reactionId = 'reaction_' . $chatId . '_' . $originalMessageId . '_' . $userId . '_' . ($reaction['date'] ?? time());

        $tikiData = [
            'message_id' => $reactionId,
            'chat_id' => $chatId,
            'chat_title' => $chatTitle,
            'topic_id' => 0,
            'topic_title' => 'General',
            'user_id' => $userId,
            'username' => $user['username'] ?? '',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'message_type' => 'system',
            'text' => $text,
            'media_type' => '',
            'media_size' => '',
            'media_caption' => '',
            'location' => '',
            'uploaded_file_id' => null,
            'date' => $reaction['date'] ?? time()
        ];

        if (!self::sendToTikiWikiWithRetries($tikiData)) {
            error_log("ERROR: No se pudo enviar reacción a TikiWiki: message_id={$originalMessageId}");
        }
    }

    /**
     * Procesar conteo de reacciones (message_reaction_count)
     */
    public static function processMessageReactionCount(array $reactionCount): void
    {
        $chatId = $reactionCount['chat']['id'];
        $chatTitle = $reactionCount['chat']['title'] ?? $reactionCount['chat']['username'] ?? 'Chat ' . $chatId;

        if (!empty(ALLOWED_CHAT_IDS) && !in_array($chatId, ALLOWED_CHAT_IDS)) {
            error_log("Chat $chatId no está en la lista de permitidos");
            return;
        }

        $originalMessageId = $reactionCount['message_id'];

        $counts = [];
        foreach ($reactionCount['reactions'] ?? [] as $r) {
            $emoji = $r['emoji'] ?? ($r['type'] === 'custom_emoji' ? '⭐' : '❓');
            $counts[] = $emoji . ' ' . $r['count'];
        }
        $text = '💬 Mensaje ' . $originalMessageId . ' - reacciones: ' . implode(', ', $counts);

        $reactionCountId = 'reaction_count_' . $chatId . '_' . $originalMessageId . '_' . ($reactionCount['date'] ?? time());

        $tikiData = [
            'message_id' => $reactionCountId,
            'chat_id' => $chatId,
            'chat_title' => $chatTitle,
            'topic_id' => 0,
            'topic_title' => 'General',
            'user_id' => 0,
            'username' => '',
            'first_name' => 'System',
            'last_name' => '',
            'message_type' => 'system',
            'text' => $text,
            'media_type' => '',
            'media_size' => '',
            'media_caption' => '',
            'location' => '',
            'uploaded_file_id' => null,
            'date' => $reactionCount['date'] ?? time()
        ];

        if (!self::sendToTikiWikiWithRetries($tikiData)) {
            error_log("ERROR: No se pudo enviar conteo de reacciones a TikiWiki: message_id={$originalMessageId}");
        }
    }

    /**
     * Procesar actualización de Telegram (dispatcher)
     */
    public static function processUpdate(array $update): void
    {
        if (isset($update['message'])) {
            self::processMessage($update['message']);
        } elseif (isset($update['message_reaction'])) {
            self::processMessageReaction($update['message_reaction']);
        } elseif (isset($update['message_reaction_count'])) {
            self::processMessageReactionCount($update['message_reaction_count']);
        }
    }
}
