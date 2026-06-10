<?php
/**
 * WebhookHandler - Procesa actualizaciones de Telegram y las envía a TikiWiki
 *
 * Separa la lógica de negocio del manejo HTTP (api.php).
 * Dependencias inyectadas: TikiWikiClient, TelegramClient, MessageMapper.
 */
class WebhookHandler
{
    private TikiWikiClient $tikiWikiClient;
    private TelegramClient $telegramClient;
    private MessageMapper $messageMapper;
    private int $trackerId;
    private int $retryMaxAttempts;
    private int $retryDelayMicroseconds;
    private int $maxDownloadSize;

    public function __construct(
        TikiWikiClient $tikiWikiClient,
        TelegramClient $telegramClient,
        MessageMapper $messageMapper,
        int $trackerId,
        int $retryMaxAttempts = 2,
        int $retryDelayMicroseconds = 100000,
        int $maxDownloadSize = 20971520
    ) {
        $this->tikiWikiClient = $tikiWikiClient;
        $this->telegramClient = $telegramClient;
        $this->messageMapper = $messageMapper;
        $this->trackerId = $trackerId;
        $this->retryMaxAttempts = $retryMaxAttempts;
        $this->retryDelayMicroseconds = $retryDelayMicroseconds;
        $this->maxDownloadSize = $maxDownloadSize;
    }

    /**
     * Obtener el nombre de un topic de Telegram desde cache local
     */
    public function getTopicName(int $chatId, int $messageThreadId): string
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
     * Con límite real: usa WRITEFUNCTION para contar bytes y abortar si excede
     */
    public function downloadAndUploadMedia(NormalizedMessage $msg): ?string
    {
        if ($msg->fileId === null) {
            return null;
        }

        $fileUrl = $this->telegramClient->getFileUrl($msg->fileId);
        if (!$fileUrl) {
            log_message("trackerGram: Cannot get download URL for file: {$msg->fileId}", true);
            return null;
        }

        // HEAD request previa para rechazar rápido archivos grandes conocidos
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);

        if ($contentLength > $this->maxDownloadSize) {
            log_message("trackerGram: File too large ($contentLength bytes) for file_id: {$msg->fileId}", true);
            return null;
        }

        $tempFile = tempnam(TEMP_DIR, 'tg_media_');
        $fp = fopen($tempFile, 'wb');
        if (!$fp) {
            log_message("trackerGram: Cannot create temp file for download", true);
            return null;
        }

        $downloadedBytes = 0;
        $maxSize = $this->maxDownloadSize;
        $aborted = false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        // WRITEFUNCTION: controla bytes escritos y aborta si excede límite
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($handle, $data) use ($fp, &$downloadedBytes, $maxSize, &$aborted) {
            $len = strlen($data);
            if ($downloadedBytes + $len > $maxSize) {
                $aborted = true;
                return 0; // aborta la transferencia
            }
            $written = fwrite($fp, $data);
            if ($written !== false) {
                $downloadedBytes += $written;
            }
            return $written === false ? 0 : $written;
        });
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        // Verificar si se abortó por tamaño o por error HTTP
        if ($aborted || $httpCode !== 200) {
            unlink($tempFile);
            if ($aborted) {
                log_message("trackerGram: File too large (>={$maxSize} bytes) for file_id: {$msg->fileId}", true);
            } else {
                log_message("trackerGram: Cannot download file from Telegram: {$msg->fileId} (HTTP $httpCode)", true);
            }
            return null;
        }

        // Verificación final: el archivo en disco no debe exceder el límite
        $actualSize = filesize($tempFile);
        if ($actualSize > $this->maxDownloadSize) {
            unlink($tempFile);
            log_message("trackerGram: Downloaded file too large ({$actualSize} bytes) for file_id: {$msg->fileId}", true);
            return null;
        }

        $galleryId = $this->tikiWikiClient->getMediaGalleryId($this->trackerId);
        if ($galleryId === null) {
            log_message("trackerGram: NO HAY galleryId para tracker {$this->trackerId} — no se puede subir media", true);
            unlink($tempFile);
            return null;
        }
        $fileName = $msg->fileName ?? 'file_' . $msg->fileId;
        $result = $this->tikiWikiClient->uploadFile($tempFile, $fileName, $galleryId, 'webhook', $msg->mediaCaption);
        unlink($tempFile);

        return $result;
    }

    /**
     * Enviar datos a TikiWiki con reintentos
     */
    public function sendToTikiWikiWithRetries(NormalizedMessage $msg): bool
    {
        for ($i = 0; $i < $this->retryMaxAttempts; $i++) {
            $fields = $this->messageMapper->toWikiFields($msg);
            if ($this->tikiWikiClient->createTrackerItem($this->trackerId, $fields)) {
                return true;
            }
            if ($i < $this->retryMaxAttempts - 1) {
                log_message("Reintento " . ($i + 1) . " para message_id={$msg->messageId}");
                usleep($this->retryDelayMicroseconds);
            }
        }
        return false;
    }

    /**
     * Procesar mensaje regular de Telegram
     */
    public function processMessage(array $message): void
    {
        $requiredFields = ['message_id', 'chat', 'from', 'date'];
        foreach ($requiredFields as $field) {
            if (!isset($message[$field])) {
                log_message("ERROR: Campo requerido '$field' no encontrado en el mensaje", true);
                return;
            }
        }

        $requiredSubFields = ['chat.id', 'from.id', 'from.first_name'];
        foreach ($requiredSubFields as $fieldPath) {
            $keys = explode('.', $fieldPath);
            $value = $message;
            foreach ($keys as $key) {
                if (!isset($value[$key])) {
                    log_message("ERROR: Subcampo requerido '$fieldPath' no encontrado en el mensaje", true);
                    return;
                }
                $value = $value[$key];
            }
        }

        $chatId = $message['chat']['id'];
        $chatTitle = $message['chat']['title'] ?? $message['chat']['username'] ?? 'Chat ' . $chatId;

        if (!empty(ALLOWED_CHAT_IDS) && !in_array($chatId, ALLOWED_CHAT_IDS)) {
            log_message("Chat $chatId no está en la lista de permitidos");
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
            $topicName = $this->getTopicName($chatId, $topicId);
        }

        if ($topicId > 0 && $topicName === 'General') {
            $topicName = 'Topic-' . $topicId;
        } elseif ($topicName === null) {
            $topicName = 'General';
        }

        // Parsear mensaje via MessageMapper
        $msg = $this->messageMapper->fromWebhook($message);

        // Propagar caption si es parte de un álbum (media_group_id)
        $this->propagateMediaGroupCaption($msg, $message);

        // Completar contexto
        $msg->messageId = (string) $message['message_id'];
        $msg->chatId = (string) $chatId;
        $msg->chatTitle = $chatTitle;
        $msg->topicId = (string) $topicId;
        $msg->topicTitle = $topicName;
        $msg->userId = (string) $message['from']['id'];
        $msg->username = $message['from']['username'] ?? '';
        $msg->firstName = $message['from']['first_name'] ?? '';
        $msg->lastName = $message['from']['last_name'] ?? '';
        $msg->displayName = trim($msg->firstName . ' ' . $msg->lastName);
        $msg->date = (string) $message['date'];


        // Cachear topic names for forum_topic_created/edited
        $chatIdInt = $chatId;
        if (isset($message['forum_topic_created']) && isset($message['message_thread_id'])) {
            $cacheFile = __DIR__ . '/topic_names.json';
            $topics = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
            $topics[$chatIdInt . ':' . $message['message_thread_id']] = $msg->topicName ?? $topicName;
            file_put_contents($cacheFile, json_encode($topics), LOCK_EX);
        }
        if (isset($message['forum_topic_edited']) && isset($message['message_thread_id'])) {
            $cacheFile = __DIR__ . '/topic_names.json';
            $topics = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
            $topics[$chatIdInt . ':' . $message['message_thread_id']] = $msg->topicName ?? $topicName;
            file_put_contents($cacheFile, json_encode($topics), LOCK_EX);
        }

        // Deduplicación
        if ($this->tikiWikiClient->messageExists($this->trackerId, $message['message_id'], $chatId) > 0) {
            log_message("trackerGram: SKIPPING duplicate message_id={$message['message_id']}");
            return;
        }

        // Descargar y subir media
        if ($msg->fileId !== null) {
            $logType = strtoupper($msg->messageType);
            log_message("trackerGram: {$logType} - file_id: {$msg->fileId}" . ($msg->mediaSize ? ", size: {$msg->mediaSize}" : ''));
            $uploadedFileId = $this->downloadAndUploadMedia($msg);
            log_message("trackerGram: {$logType} upload result: " . ($uploadedFileId ?? 'null'));
            $msg->uploadedFileIds = $uploadedFileId ? [$uploadedFileId] : [];
            if ($uploadedFileId) {
                $baseUrl = rtrim(str_replace('/api/', '', TIKIWIKI_API_URL), '/');
                $msg->mediaUrl = $baseUrl . '/tiki-download_file.php?fileId=' . $uploadedFileId;
            }
        }

        // Enviar a TikiWiki
        if (!$this->sendToTikiWikiWithRetries($msg)) {
            log_message("ERROR: No se pudo enviar mensaje a TikiWiki después de {$this->retryMaxAttempts} intentos: message_id={$msg->messageId}", true);
        } elseif ($this->tikiWikiClient->messageExists($this->trackerId, $message['message_id'], $chatId) > 1) {
            log_message("WARNING: duplicado detectado post-insert para message_id={$message['message_id']} — posible race condition", true);
        }

        log_message("Mensaje procesado: Topic $topicId, User {$message['from']['first_name']}");
    }

    /**
     * Procesar reacción a mensaje (message_reaction)
     */
    public function processMessageReaction(array $reaction): void
    {
        $chatId = $reaction['chat']['id'];
        $chatTitle = $reaction['chat']['title'] ?? $reaction['chat']['username'] ?? 'Chat ' . $chatId;

        if (!empty(ALLOWED_CHAT_IDS) && !in_array($chatId, ALLOWED_CHAT_IDS)) {
            log_message("Chat $chatId no está en la lista de permitidos");
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

        $msg = new NormalizedMessage();
        $msg->messageId = $reactionId;
        $msg->chatId = (string) $chatId;
        $msg->chatTitle = $chatTitle;
        $msg->topicTitle = 'General';
        $msg->userId = (string) $userId;
        $msg->username = $user['username'] ?? '';
        $msg->firstName = $firstName;
        $msg->lastName = $lastName;
        $msg->displayName = trim($firstName . ' ' . $lastName);
        $msg->messageType = 'system';
        $msg->text = $text;
        $msg->date = (string) ($reaction['date'] ?? time());

        if (!$this->sendToTikiWikiWithRetries($msg)) {
            log_message("ERROR: No se pudo enviar reacción a TikiWiki: message_id={$originalMessageId}", true);
        }
    }

    /**
     * Procesar conteo de reacciones (message_reaction_count)
     */
    public function processMessageReactionCount(array $reactionCount): void
    {
        $chatId = $reactionCount['chat']['id'];
        $chatTitle = $reactionCount['chat']['title'] ?? $reactionCount['chat']['username'] ?? 'Chat ' . $chatId;

        if (!empty(ALLOWED_CHAT_IDS) && !in_array($chatId, ALLOWED_CHAT_IDS)) {
            log_message("Chat $chatId no está en la lista de permitidos");
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

        $msg = new NormalizedMessage();
        $msg->messageId = $reactionCountId;
        $msg->chatId = (string) $chatId;
        $msg->chatTitle = $chatTitle;
        $msg->topicTitle = 'General';
        $msg->firstName = 'System';
        $msg->messageType = 'system';
        $msg->text = $text;
        $msg->date = (string) ($reactionCount['date'] ?? time());

        if (!$this->sendToTikiWikiWithRetries($msg)) {
            log_message("ERROR: No se pudo enviar conteo de reacciones a TikiWiki: message_id={$originalMessageId}", true);
        }
    }

    /**
     * Procesar actualización de Telegram (dispatcher)
     */
    public function processUpdate(array $update): void
    {
        // Detectar si el bot fue agregado a un chat no autorizado (my_chat_member)
        if (isset($update['my_chat_member'])) {
            $this->processMyChatMember($update['my_chat_member']);
            return;
        }

        if (isset($update['message'])) {
            $this->processMessage($update['message']);
        } elseif (isset($update['message_reaction'])) {
            $this->processMessageReaction($update['message_reaction']);
        } elseif (isset($update['message_reaction_count'])) {
            $this->processMessageReactionCount($update['message_reaction_count']);
        }
    }

    /**
     * Procesar update my_chat_member — detecta cuando el bot es agregado/removido de un chat.
     * Si el chat no está en ALLOWED_CHAT_IDS, el bot abandona el chat automáticamente.
     */
    public function processMyChatMember(array $myChatMember): void
    {
        $chat = $myChatMember['chat'] ?? [];
        $chatId = $chat['id'] ?? 0;
        $chatTitle = $chat['title'] ?? $chat['username'] ?? 'Chat ' . $chatId;
        $chatType = $chat['type'] ?? 'unknown';
        $from = $myChatMember['from'] ?? [];
        $fromName = $from['first_name'] ?? ($from['username'] ?? 'desconocido');
        $fromId = $from['id'] ?? 0;
        $newStatus = $myChatMember['new_chat_member']['status'] ?? 'unknown';
        $oldStatus = $myChatMember['old_chat_member']['status'] ?? 'unknown';

        log_message("trackerGram: my_chat_member — chat={$chatId} ({$chatTitle}), de {$oldStatus} → {$newStatus}, por {$fromName} (id={$fromId})");

        // Solo nos interesa cuando el bot es agregado a un chat (status → member/administrator)
        $isBeingAdded = in_array($newStatus, ['member', 'administrator'])
            && in_array($oldStatus, ['left', 'kicked', 'restricted']);

        if (!$isBeingAdded) {
            return;
        }

        // Verificar si el chat está autorizado
        $allowed = defined('ALLOWED_CHAT_IDS') ? ALLOWED_CHAT_IDS : [];
        if (!empty($allowed) && in_array($chatId, $allowed)) {
            log_message("trackerGram: Bot agregado a chat AUTORIZADO: {$chatTitle} ({$chatId})");
            return;
        }

        // Chat no autorizado — salir y loguear
        log_message("trackerGram: ⚠️ Bot agregado a chat NO AUTORIZADO: {$chatTitle} ({$chatId}, {$chatType}) por {$fromName} (id={$fromId})", true);
        $this->telegramClient->leaveChat($chatId);
    }

    // ──────────────────────────────────────────────
    // Propagación de caption en álbumes
    // ──────────────────────────────────────────────
    // Cada foto se procesa INDIVIDUALMENTE (sin buffer).
    // Guardamos el caption de la primera foto del álbum y lo propagamos
    // a las siguientes. Cache auto-limpiante: entradas >60s se eliminan.

    private function getMediaGroupCaptionFile(): string
    {
        return __DIR__ . '/media_group_captions.json';
    }

    private function loadMediaGroupCaptions(): array
    {
        $file = $this->getMediaGroupCaptionFile();
        if (!file_exists($file)) return [];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveMediaGroupCaptions(array $captions): void
    {
        // Limpiar entradas con más de 60 segundos
        $now = time();
        foreach ($captions as $key => $entry) {
            if (($now - $entry['time']) > 60) {
                unset($captions[$key]);
            }
        }
        file_put_contents($this->getMediaGroupCaptionFile(), json_encode($captions), LOCK_EX);
    }

    /**
     * Propagar caption dentro de un álbum.
     * Se llama desde processMessage() justo después de fromWebhook().
     */
    private function propagateMediaGroupCaption(NormalizedMessage $msg, array $message): void
    {
        if (!isset($message['media_group_id'])) return;

        $chatId = $message['chat']['id'];
        $groupId = $message['media_group_id'];
        $key = $chatId . ':' . $groupId;
        $currentCaption = $message['caption'] ?? '';

        if ($currentCaption !== '') {
            // Primer mensaje del álbum — guardar caption
            $captions = $this->loadMediaGroupCaptions();
            $captions[$key] = ['caption' => $currentCaption, 'time' => time()];
            $this->saveMediaGroupCaptions($captions);
        } else {
            // Mensaje subsiguiente — propagar caption guardado
            if ($msg->mediaCaption !== '') return; // ya tiene caption
            $captions = $this->loadMediaGroupCaptions();
            $saved = $captions[$key]['caption'] ?? '';
            if ($saved !== '') {
                $msg->mediaCaption = $saved;
                // Actualizar text si es tipo "Foto:" sin caption
                if ($msg->text !== '' && strpos($msg->text, ':') !== false && strpos($msg->text, $saved) === false) {
                    $msg->text .= ' - ' . htmlspecialchars($saved);
                }
            }
        }
    }
}
