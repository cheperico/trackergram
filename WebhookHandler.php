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
    private string $adminUrl = '';
    private string $connectionName = '';
    private ?CollectSessionManager $collectSessionManager = null;

    public function __construct(
        TikiWikiClient $tikiWikiClient,
        TelegramClient $telegramClient,
        MessageMapper $messageMapper,
        int $trackerId,
        int $retryMaxAttempts = 2,
        int $retryDelayMicroseconds = 100000,
        int $maxDownloadSize = 20971520,
        string $adminUrl = '',
        string $connectionName = '',
        ?CollectSessionManager $collectSessionManager = null
    ) {
        $this->tikiWikiClient = $tikiWikiClient;
        $this->telegramClient = $telegramClient;
        $this->messageMapper = $messageMapper;
        $this->trackerId = $trackerId;
        $this->retryMaxAttempts = $retryMaxAttempts;
        $this->retryDelayMicroseconds = $retryDelayMicroseconds;
        $this->maxDownloadSize = $maxDownloadSize;
        $this->adminUrl = $adminUrl;
        $this->connectionName = $connectionName;
        $this->collectSessionManager = $collectSessionManager;
    }

    /**
     * Obtener el nombre de un topic de Telegram desde cache local.
     * Operación atómica con LOCK_EX para evitar TOCTOU con escrituras concurrentes.
     */
    public function getTopicName(int $chatId, int $messageThreadId): string
    {
        $found = null;
        $this->withTopicNamesLock(function(array $topics) use ($chatId, $messageThreadId, &$found): array {
            $found = $topics[$chatId . ':' . $messageThreadId] ?? null;
            return $topics; // solo lectura, no mutar
        });
        return $found ?? 'General';
    }

    /**
     * Operación atómica sobre topic_names.json: fopen('c+') + flock(LOCK_EX),
     * ejecuta callback, escribe resultado, libera.
     *
     * @param callable $mutate fn(array $topics): array
     * @return mixed Retorno opcional del callback (pasado por referencia)
     */
    private function withTopicNamesLock(callable $mutate): void
    {
        $file = (defined('TEMP_DIR') ? TEMP_DIR : __DIR__) . '/topic_names.json';
        $fp = fopen($file, 'c+');
        if (!$fp) {
            log_message("trackerGram: No se pudo abrir topic_names.json para lock atómico", true);
            // Fallback: ejecutar callback con array vacío
            $mutate([]);
            return;
        }
        flock($fp, LOCK_EX);

        $content = stream_get_contents($fp);
        $topics = [];
        if ($content !== false && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $topics = $decoded;
            }
        }

        $topics = $mutate($topics);

        // Poda: si supera 1000 entradas, conservar solo las últimas 500
        if (count($topics) > 1000) {
            $topics = array_slice($topics, -500, null, true);
            log_message("trackerGram: topic_names.json podado (>1000 entradas, recortado a 500)");
        }

        rewind($fp);
        $written = fwrite($fp, json_encode($topics));
        if ($written !== false) {
            ftruncate($fp, ftell($fp));
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Cache de resolución reply-to: mapea "{trackerId}:{chatId}:{messageId}" → itemId.
     * Evita llamada API a TikiWiki cuando el mensaje original se creó hace ms
     * y el índice de búsqueda aún no está actualizado (race condition F3-9).
     */
    private function replyCachePath(): string
    {
        return (defined('TEMP_DIR') ? TEMP_DIR : __DIR__) . '/reply_cache.json';
    }

    /**
     * Almacenar mapeo (chatId, messageId) → itemId en cache local con flock(LOCK_EX).
     */
    private function cacheReplyMapping(int $trackerId, int $chatId, int $messageId, int $itemId): void
    {
        $path = $this->replyCachePath();
        $fp = fopen($path, 'c+');
        if (!$fp) {
            log_message("trackerGram: No se pudo abrir reply_cache.json para escritura", true);
            return;
        }
        flock($fp, LOCK_EX);

        $content = stream_get_contents($fp);
        $cache = [];
        if ($content !== false && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $cache = $decoded;
            }
        }

        $key = "{$trackerId}:{$chatId}:{$messageId}";
        $cache[$key] = $itemId;

        // Evitar crecimiento infinito: podar a 1000 entradas si supera 5000
        if (count($cache) > 5000) {
            $cache = array_slice($cache, -1000, null, true);
        }

        rewind($fp);
        $written = fwrite($fp, json_encode($cache));
        if ($written !== false) {
            ftruncate($fp, ftell($fp));
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Buscar itemId en cache local (chatId, messageId) con flock(LOCK_SH).
     * @return int|null itemId si está cacheado, null si no.
     */
    private function lookupReplyCache(int $trackerId, int $chatId, int $messageId): ?int
    {
        $path = $this->replyCachePath();
        if (!file_exists($path)) {
            return null;
        }

        $fp = fopen($path, 'r');
        if (!$fp) {
            return null;
        }
        flock($fp, LOCK_SH);

        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($content === false || $content === '') {
            return null;
        }

        $cache = json_decode($content, true);
        if (!is_array($cache)) {
            return null;
        }

        $key = "{$trackerId}:{$chatId}:{$messageId}";
        return isset($cache[$key]) ? (int) $cache[$key] : null;
    }

    // ─────────────────────────────────────────────────────────
    // Album buffer (media_group_id → itemId)
    // ─────────────────────────────────────────────────────────

    private function albumBufferPath(): string
    {
        return (defined('TEMP_DIR') ? TEMP_DIR : __DIR__) . '/media_group_album.json';
    }

    /**
     * Operación atómica sobre media_group_album.json con flock(LOCK_EX).
     * @param callable $mutate fn(array $albums): array
     */
    private function withAlbumBufferLock(callable $mutate): void
    {
        $file = $this->albumBufferPath();
        $fp = fopen($file, 'c+');
        if (!$fp) {
            log_message("trackerGram: No se pudo abrir media_group_album.json para lock atómico", true);
            $mutate([]);
            return;
        }
        flock($fp, LOCK_EX);

        $content = stream_get_contents($fp);
        $albums = [];
        if ($content !== false && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $albums = $decoded;
            }
        }

        $albums = $mutate($albums);

        rewind($fp);
        $written = fwrite($fp, json_encode($albums));
        if ($written !== false) {
            ftruncate($fp, ftell($fp));
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Registrar o consultar un álbum atómicamente (race-condition-free).
     *
     * Bajo LOCK_EX:
     * - Si el álbum YA existe (item creado por otro proceso), retorna su entrada → modo append.
     * - Si el álbum NO existe, lo RESERVA con pending=true → modo creator.
     * - Si existe pero es pending (itemId=0, creator anterior crasheó), toma el rol de creator.
     *
     * @return array|null Entrada existente para append, o null si este proceso es el creator.
     */
    private function registerOrLookupAlbum(string $albumKey, array $fileIds): ?array
    {
        $result = null;
        $this->withAlbumBufferLock(function(array $albums) use ($albumKey, $fileIds, &$result): array {
            if (isset($albums[$albumKey])) {
                $entry = $albums[$albumKey];
                // Si es pending (itemId=0), el creator anterior crasheó — tomamos el rol
                if (($entry['pending'] ?? false) && ($entry['itemId'] ?? 0) === 0) {
                    $albums[$albumKey] = [
                        'itemId' => 0,
                        'fileIds' => $fileIds,
                        'createdAt' => time(),
                        'pending' => true,
                    ];
                    $result = null;
                    return $albums;
                }
                $result = $entry;
                return $albums;
            }

            $albums[$albumKey] = [
                'itemId' => 0,
                'fileIds' => $fileIds,
                'createdAt' => time(),
                'pending' => true,
            ];
            return $albums;
        });
        return $result;
    }

    /**
     * Completar el registro del álbum tras crear el item exitosamente.
     */
    private function completeAlbumRegistration(string $albumKey, int $itemId, array $fileIds): void
    {
        $this->withAlbumBufferLock(function(array $albums) use ($albumKey, $itemId, $fileIds): array {
            if (isset($albums[$albumKey])) {
                $albums[$albumKey] = [
                    'itemId' => $itemId,
                    'fileIds' => $fileIds,
                    'createdAt' => time(),
                ];
            }
            return $albums;
        });
    }

    /**
     * Remover registro de álbum si falló la creación del item.
     */
    private function removeAlbumRegistration(string $albumKey): void
    {
        $this->withAlbumBufferLock(function(array $albums) use ($albumKey): array {
            unset($albums[$albumKey]);
            return $albums;
        });
    }

    /**
     * GC probabilístico para entradas de álbum stale.
     * - Completados: >1 hora sin actividad.
     * - Pending: >5 minutos (creator crasheó sin completar).
     */
    private function gcAlbumBuffer(): void
    {
        if (mt_rand(1, 100) !== 1) {
            return;
        }
        $this->withAlbumBufferLock(function(array $albums): array {
            $now = time();
            $threshold = $now - 3600;
            $pendingThreshold = $now - 300;
            foreach ($albums as $key => $entry) {
                $age = $entry['createdAt'] ?? 0;
                if (($entry['pending'] ?? false) && $age > 0 && $age < $pendingThreshold) {
                    log_message("trackerGram: Album buffer GC — álbum '{$key}' pending stale (>5min), eliminado");
                    unset($albums[$key]);
                } elseif ($age > 0 && $age < $threshold) {
                    log_message("trackerGram: Album buffer GC — álbum '{$key}' stale (>1h), eliminado");
                    unset($albums[$key]);
                }
            }
            return $albums;
        });
    }

    /**
     * Buscar un álbum existente por su media_group_id.
     * @return array|null Array con itemId, fileIds, createdAt o null si no existe
     */
    private function lookupAlbum(string $albumKey): ?array
    {
        $path = $this->albumBufferPath();
        if (!file_exists($path)) {
            return null;
        }

        $fp = fopen($path, 'r');
        if (!$fp) {
            return null;
        }
        flock($fp, LOCK_SH);

        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($content === false || $content === '') {
            return null;
        }

        $albums = json_decode($content, true);
        if (!is_array($albums)) {
            return null;
        }

        return $albums[$albumKey] ?? null;
    }

    /**
     * Descargar archivo de Telegram y subir a TikiWiki (con reintentos)
     * Con límite real: usa WRITEFUNCTION para contar bytes y abortar si excede
     */
    public function downloadAndUploadMedia(NormalizedMessage $msg): ?string
    {
        if ($msg->fileId === null) {
            return null;
        }

        for ($attempt = 0; $attempt < $this->retryMaxAttempts; $attempt++) {
            $result = $this->attemptDownloadAndUpload($msg);
            if ($result !== null) {
                return $result;
            }
            if ($attempt < $this->retryMaxAttempts - 1) {
                $sleepMs = 500 + ($attempt * 500);
                log_message("trackerGram: Reintentando download/upload para file_id={$msg->fileId} (intento " . ($attempt + 2) . "/{$this->retryMaxAttempts}, espera {$sleepMs}ms)");
                usleep($sleepMs * 1000);
            }
        }
        log_message("trackerGram: Download/upload FALLÓ tras {$this->retryMaxAttempts} intentos para file_id={$msg->fileId}", true);
        return null;
    }

    /**
     * Intento único de descargar y subir (llamado desde downloadAndUploadMedia con reintentos)
     */
    private function attemptDownloadAndUpload(NormalizedMessage $msg): ?string
    {
        $fileUrl = $this->telegramClient->getFileUrl($msg->fileId);
        if (!$fileUrl) {
            log_message("trackerGram: Cannot get download URL for file: {$msg->fileId}");
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
                log_message("trackerGram: Cannot download file from Telegram: {$msg->fileId} (HTTP $httpCode)");
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
     * @return int|false itemId en éxito, false si fallan todos los reintentos
     */
    public function sendToTikiWikiWithRetries(NormalizedMessage $msg): int|false
    {
        for ($i = 0; $i < $this->retryMaxAttempts; $i++) {
            $fields = $this->messageMapper->toWikiFields($msg);
            $itemId = $this->tikiWikiClient->createTrackerItem($this->trackerId, $fields);
            if ($itemId !== false) {
                return $itemId;
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

        // Detectar comandos del bot (/inicio, /ayuda, /tracker, /estado)
        $entities = $message['entities'] ?? [];
        $commandText = '';
        foreach ($entities as $entity) {
            if (($entity['type'] ?? '') === 'bot_command') {
                $cmd = substr($message['text'], $entity['offset'], $entity['length']);
                // Strip bot username: /inicio@botname → /inicio
                if (($pos = strpos($cmd, '@')) !== false) {
                    $cmd = substr($cmd, 0, $pos);
                }
                $commandText = $cmd;
                break;
            }
        }
        if ($commandText !== '') {
            if ($commandText === '/gather') {
                // /gather necesita contexto completo del mensaje
                $this->handleGather($chatId, $message);
                return;
            }
            $this->handleCommand($commandText, $chatId);
            return;
        }

        // ── Sesión activa de /gather? ──
        $userId = $message['from']['id'] ?? 0;
        $sessionKey = $chatId . '_' . $userId;
        if ($this->collectSessionManager !== null) {
            $session = $this->collectSessionManager->get($sessionKey);
            if ($session !== null && ($session['awaiting'] ?? null) !== null) {
                $this->handleCollectResponse($session, $sessionKey, $message);
                return;
            }
        }

        $topicId = $message['message_thread_id'] ?? 0;

        $topicName = null;
        if (isset($message['reply_to_message']['forum_topic_created']['name'])) {
            $topicName = $message['reply_to_message']['forum_topic_created']['name'];
            $this->withTopicNamesLock(function(array $topics) use ($chatId, $topicId, $topicName): array {
                $topics[$chatId . ':' . $topicId] = $topicName;
                return $topics;
            });
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


        // ── Lock TOCTOU: prevenir duplicados por race condition ──
        // Adquirimos un lock exclusivo por (chatId:messageId) que se mantiene
        // hasta después de la creación del item. Dos webhooks concurrentes
        // para el mismo mensaje se serializan aquí.
        $lockDir = defined('TEMP_DIR') ? TEMP_DIR . '/dedup_locks' : sys_get_temp_dir() . '/trackergram_dedup';
        if (!is_dir($lockDir)) {
            if (!mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
                log_message("trackerGram: No se pudo crear directorio de locks '{$lockDir}'", true);
            }
        }
        $lockKey = md5($chatId . ':' . $message['message_id']);
        $lockFile = $lockDir . '/' . $lockKey . '.lock';
        $lockFp = fopen($lockFile, 'c+');
        if ($lockFp) {
            flock($lockFp, LOCK_EX);
        } else {
            log_message("trackerGram: No se pudo abrir lock file '{$lockFile}' — protección TOCTOU desactivada", true);
        }

        // Cachear topic names for forum_topic_created/edited (atómico)
        $chatIdInt = $chatId;
        if (isset($message['forum_topic_created']) && isset($message['message_thread_id'])) {
            $this->withTopicNamesLock(function(array $topics) use ($chatIdInt, $message, $topicName): array {
                $topics[$chatIdInt . ':' . $message['message_thread_id']] = $topicName;
                return $topics;
            });
        }
        if (isset($message['forum_topic_edited']) && isset($message['message_thread_id'])) {
            $this->withTopicNamesLock(function(array $topics) use ($chatIdInt, $message, $topicName): array {
                $topics[$chatIdInt . ':' . $message['message_thread_id']] = $topicName;
                return $topics;
            });
        }

        // Deduplicación
        $exists = $this->tikiWikiClient->messageExists($this->trackerId, $message['message_id'], $chatId);
        if ($exists === null) {
            log_message("WARNING: No se pudo verificar duplicado para message_id={$message['message_id']} — error de conexión TikiWiki. Se procede con creación.", true);
        } elseif ($exists > 0) {
            log_message("trackerGram: SKIPPING duplicate message_id={$message['message_id']}");
            // Liberar lock antes de salir
            if (isset($lockFp) && $lockFp) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                if (file_exists($lockFile)) {
                    unlink($lockFile);
                }
            }
            return;
        }

        // ── Mejorar nombre de archivo desde file_path si es genérico ──
        // Cuando Telegram no envía file_name en el mensaje, fromWebhook() usa
        // fallbacks como 'Documento' o 'telegram_photo_...'. getFileInfo()
        // devuelve file_path (ej: "documents/video_2025_04.mp4") del cual
        // podemos extraer un nombre más descriptivo vía basename().
        if ($msg->fileId !== null && $msg->fileName !== '') {
            $genericPatterns = ['/^Documento/', '/^telegram_/', '/^animation/', '/^file_/'];
            $isGeneric = false;
            foreach ($genericPatterns as $pattern) {
                if (preg_match($pattern, $msg->fileName)) {
                    $isGeneric = true;
                    break;
                }
            }
            if ($isGeneric) {
                $fileInfo = $this->telegramClient->getFileInfo($msg->fileId);
                if ($fileInfo !== null && isset($fileInfo['file_path'])) {
                    $basename = basename($fileInfo['file_path']);
                    // Solo usar si tiene extensión y no es un hash genérico
                    if (pathinfo($basename, PATHINFO_EXTENSION) !== '') {
                        log_message("trackerGram: fileName mejorado: '{$msg->fileName}' → '{$basename}'");
                        $msg->fileName = $basename;
                    }
                }
            }
        }

        // Descargar y subir media
        if ($msg->fileId !== null) {
            $logType = strtoupper($msg->messageType);
            log_message("trackerGram: {$logType} - file_id: {$msg->fileId}" . ($msg->mediaSize ? ", size: {$msg->mediaSize}" : ''));
            $uploadedFileId = $this->downloadAndUploadMedia($msg);
            log_message("trackerGram: {$logType} upload result: " . ($uploadedFileId ?? 'null'));
            $msg->uploadedFileIds = $uploadedFileId ? [$uploadedFileId] : [];
            if ($uploadedFileId) {
                $msg->mediaUrl = $this->tikiWikiClient->getBaseUrl() . '/tiki-download_file.php?fileId=' . $uploadedFileId;
            }
        }

        // ── Álbum: registro atómico (race-condition-free) ──
        // registerOrLookupAlbum() usa LOCK_EX sobre media_group_album.json:
        // atómicamente decide si esta foto es la primera del álbum (crea item)
        // o si ya existe (append al item existente).
        $albumKey = null;
        if ($msg->mediaGroupId !== '' && !empty($msg->uploadedFileIds)) {
            $albumKey = $chatId . ':' . $msg->mediaGroupId;
            $existingAlbum = $this->registerOrLookupAlbum($albumKey, $msg->uploadedFileIds);
            if ($existingAlbum !== null) {
                foreach ($msg->uploadedFileIds as $fileId) {
                    $this->tikiWikiClient->appendMediaToTrackerItem(
                        $this->trackerId,
                        $existingAlbum['itemId'],
                        $fileId
                    );
                }
                log_message("trackerGram: Álbum {$msg->mediaGroupId} — foto {$msg->messageId} agregada al item #{$existingAlbum['itemId']}");
                if (isset($lockFp) && $lockFp) {
                    flock($lockFp, LOCK_UN);
                    fclose($lockFp);
                    if (file_exists($lockFile)) {
                        unlink($lockFile);
                    }
                }
                return;
            }
        }

        // Resolver reply_to: buscar el tracker itemId del mensaje original
        // y concatenar el texto del mensaje al que responde (Opción B)
        if ($msg->replyToId !== '') {
            $replyMessageId = (int) $msg->replyToId;
            if ($replyMessageId > 0) {
                // Cache local primero (F3-9: evita miss de índice TikiWiki recién creados)
                $foundItemId = $this->lookupReplyCache($this->trackerId, $chatId, $replyMessageId);
                if ($foundItemId === null) {
                    $foundItemId = $this->tikiWikiClient->findItemByMessageId(
                        $this->trackerId,
                        $replyMessageId,
                        $chatId
                    );
                }
                if ($foundItemId !== null) {
                    // Resuelto: guardar referencia al itemId + texto del original
                    $replyRef = '#' . $foundItemId;
                    if ($msg->replyToText !== '') {
                        $truncated = mb_strlen($msg->replyToText) > 120
                            ? mb_substr($msg->replyToText, 0, 120) . '…'
                            : $msg->replyToText;
                        $replyRef .= ' - "' . $truncated . '"';
                    }
                    $msg->replyToId = $replyRef;
                    log_message("trackerGram: reply_to message_id={$replyMessageId} resuelto a itemId={$foundItemId}");
                } else {
                    // No resuelto: si tenemos texto del webhook, guardarlo igual
                    if ($msg->replyToText !== '') {
                        $truncated = mb_strlen($msg->replyToText) > 120
                            ? mb_substr($msg->replyToText, 0, 120) . '…'
                            : $msg->replyToText;
                        $msg->replyToId = '"' . $truncated . '"';
                    }
                    log_message("trackerGram: reply_to message_id={$replyMessageId} NO RESUELTO (aún no en tracker)");
                }
            }
        }

        // Enviar a TikiWiki
        $newItemId = $this->sendToTikiWikiWithRetries($msg);
        if ($newItemId === false) {
            log_message("ERROR: No se pudo enviar mensaje a TikiWiki después de {$this->retryMaxAttempts} intentos: message_id={$msg->messageId}", true);
            if ($albumKey !== null) {
                $this->removeAlbumRegistration($albumKey);
            }
        } else {
            // Cachear mapeo (chatId, messageId) → itemId para reply-to (F3-9)
            $this->cacheReplyMapping($this->trackerId, $chatId, $message['message_id'], $newItemId);

            // Completar registro del álbum (si aplica)
            if ($albumKey !== null) {
                $this->completeAlbumRegistration($albumKey, $newItemId, $msg->uploadedFileIds);
            }

            $postInsertCount = $this->tikiWikiClient->messageExists($this->trackerId, $message['message_id'], $chatId);
            if ($postInsertCount !== null && $postInsertCount > 1) {
                log_message("WARNING: duplicado detectado post-insert para message_id={$message['message_id']} — posible race condition", true);
            }
        }

        // Liberar lock TOCTOU
        if (isset($lockFp) && $lockFp) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }

        log_message("Mensaje procesado: Topic $topicId, User {$message['from']['first_name']}");
    }

    /**
     * Procesar edición de mensaje (edited_message / edited_channel_post)
     *
     * Actualiza solo Text + EditedDate + Reactions (campos seguros según toWikiFieldsEdit).
     * Usa el mismo TOCTOU lock que processMessage() para evitar race conditions
     * donde edited_message llega ANTES de que el message original termine de procesarse.
     *
     * Si el item no existe en el tracker, lo ignora (no crea duplicados). El message
     * original llegará por separado como update tipo "message" y se creará normalmente.
     */
    public function processEditedMessage(array $message): void
    {
        $requiredFields = ['message_id', 'chat', 'date', 'edit_date'];
        foreach ($requiredFields as $field) {
            if (!isset($message[$field])) {
                log_message("ERROR: edited_message - campo requerido '$field' no encontrado", true);
                return;
            }
        }

        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        $chatTitle = $message['chat']['title'] ?? $message['chat']['username'] ?? 'Chat ' . $chatId;

        if (!empty(ALLOWED_CHAT_IDS) && !in_array($chatId, ALLOWED_CHAT_IDS)) {
            log_message("Chat $chatId no está en la lista de permitidos");
            return;
        }

        // ── Lock TOCTOU: mismo lock que processMessage() para evitar race ──
        // Si el message original aún se está procesando, esperamos a que termine
        // para decidir si el item existe o no.
        $lockDir = defined('TEMP_DIR') ? TEMP_DIR . '/dedup_locks' : sys_get_temp_dir() . '/trackergram_dedup';
        if (!is_dir($lockDir)) {
            if (!mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
                log_message("trackerGram: No se pudo crear directorio de locks '{$lockDir}'", true);
            }
        }
        $lockKey = md5($chatId . ':' . $messageId);
        $lockFile = $lockDir . '/' . $lockKey . '.lock';
        $lockFp = fopen($lockFile, 'c+');
        if ($lockFp) {
            flock($lockFp, LOCK_EX);
        } else {
            log_message("trackerGram: No se pudo abrir lock file '{$lockFile}' — protección TOCTOU desactivada", true);
        }

        try {
            // Buscar el item existente (después del lock, processMessage() ya debió crear el item si existe)
            $existingItemId = $this->tikiWikiClient->findItemByMessageId(
                $this->trackerId,
                $messageId,
                $chatId
            );

            if ($existingItemId === null) {
                // No existe → ignorar. El message original llegará como update "message"
                // y se creará normalmente. Si es un edit de un mensaje anterior a trackerGram,
                // se pierde (no tenemos los datos originales para crearlo).
                log_message("trackerGram: edited_message #{$messageId} no existe en tracker — ignorado (el message original se procesará por separado)");
                return;
            }

            // Existe — parsear solo los campos editables via fromWebhook
            $msg = $this->messageMapper->fromWebhook($message);

            // Completar contexto (solo para log / album caption propagation)
            $msg->messageId = (string) $messageId;
            $msg->chatId = (string) $chatId;
            $msg->chatTitle = $chatTitle;
            $msg->userId = (string) ($message['from']['id'] ?? '');
            $msg->username = $message['from']['username'] ?? '';
            $msg->firstName = $message['from']['first_name'] ?? '';
            $msg->lastName = $message['from']['last_name'] ?? '';
            $msg->displayName = trim($msg->firstName . ' ' . $msg->lastName);
            // editedDate ya lo extrajo fromWebhook() de $message['edit_date']

            // Propagar caption si es parte de álbum (pueden editarse captions)
            $this->propagateMediaGroupCaption($msg, $message);

            // Generar solo campos editables y actualizar
            $editFields = $this->messageMapper->toWikiFieldsEdit($msg);
            if ($this->tikiWikiClient->updateTrackerItem($this->trackerId, $existingItemId, $editFields)) {
                log_message("trackerGram: Editado #{$messageId} → itemId={$existingItemId} en tracker {$this->trackerId}");
            } else {
                log_message("ERROR: No se pudo actualizar edit para #{$messageId} itemId={$existingItemId}", true);
            }
        } finally {
            // Liberar lock siempre
            if (isset($lockFp) && $lockFp) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                if (file_exists($lockFile)) {
                    unlink($lockFile);
                }
            }
        }
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

        // Dedup: evitar duplicados si Telegram reenvía el mismo evento
        $exists = $this->tikiWikiClient->messageExists($this->trackerId, $reactionId, $chatId);
        if ($exists !== null && $exists > 0) {
            log_message("trackerGram: SKIPPING duplicate reaction {$reactionId}");
            return;
        }

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

        // Dedup: evitar duplicados si Telegram reenvía el mismo evento
        $exists = $this->tikiWikiClient->messageExists($this->trackerId, $reactionCountId, $chatId);
        if ($exists !== null && $exists > 0) {
            log_message("trackerGram: SKIPPING duplicate reaction_count {$reactionCountId}");
            return;
        }

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
     * Manejar comandos del bot
     */
    private function handleCommand(string $command, int $chatId): void
    {
        switch ($command) {
            case '/inicio':
                $text = "👋 <b>trackerGram " . TRACKERGRAM_VERSION . "</b>\n\n"
                    . "Soy un puente entre Telegram y TikiWiki. "
                    . "Los mensajes de este grupo se guardan automáticamente en un tracker de TikiWiki "
                    . "para que queden indexados, buscables y accesibles fuera de Telegram.\n\n"
                    . "Usá /ayuda para más información.";
                $this->telegramClient->sendMessage($chatId, $text);
                log_message("trackerGram: /inicio respondido en chat {$chatId}");
                break;

            case '/ayuda':
                $text = "🤖 <b>trackerGram " . TRACKERGRAM_VERSION . " — Ayuda</b>\n\n"
                    . "<b>¿Qué hace?</b>\n"
                    . "trackerGram recibe los mensajes de este grupo y los guarda automáticamente "
                    . "en un tracker de TikiWiki. Todo el contenido queda indexado y buscable.\n\n"
                    . "<b>¿Cómo interactuar?</b>\n"
                    . "No necesitás hacer nada especial; escribí normalmente en el grupo. "
                    . "trackerGram registra: textos, fotos, videos, audios, stickers, ubicaciones, etc.\n\n"
                    . "<b>Comandos disponibles:</b>\n"
                    . "/inicio: mensaje de bienvenida;\n"
                    . "/ayuda: mostrar esta ayuda;\n"
                    . "/tracker: enlace al tracker en TikiWiki;\n"
                    . "/estado: estado de la conexión;\n"
                    . "/version: versión de trackerGram y enlace al repositorio.\n\n"
                    . "<b>Sintaxis TikiWiki:</b>\n"
                    . "El texto del mensaje soporta <a href=\"https://doc.tiki.org/Wiki-syntax\">sintaxis wiki</a>. "
                    . "Además podés incorporar etiquetas con #etiqueta para marcar contenido "
                    . "con <a href=\"https://doc.tiki.org/Tags\">freetags</a>.";
                $this->telegramClient->sendMessage($chatId, $text);
                log_message("trackerGram: /ayuda respondido en chat {$chatId}");
                break;

            case '/version':
                $text = "📦 <b>trackerGram " . TRACKERGRAM_VERSION . "</b>\n\n"
                    . "Código fuente: <a href=\"https://github.com/cheperico/trackergram\">github.com/cheperico/trackergram</a>\n\n"
                    . "Reportá bugs o sugerí mejoras en el repositorio.";
                $this->telegramClient->sendMessage($chatId, $text);
                log_message("trackerGram: /version respondido en chat {$chatId}");
                break;

            case '/tracker':
                $webUrl = $this->tikiWikiClient->getBaseUrl();
                $trackerLink = rtrim($webUrl, '/') . '/tiki-view_tracker.php?trackerId=' . $this->trackerId;
                $text = "🔗 <b>Tracker en TikiWiki</b>\n\n"
                    . "Los mensajes se guardan en el tracker <b>#{$this->trackerId}</b>.\n\n"
                    . "Podés ver todos los mensajes acá:\n"
                    . "<a href=\"{$trackerLink}\">{$trackerLink}</a>";
                $this->telegramClient->sendMessage($chatId, $text);
                log_message("trackerGram: /tracker respondido en chat {$chatId}");
                break;

            case '/estado':
                $this->handleEstado($chatId);
                break;

            default:
                $safe = htmlspecialchars($command, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $text = "❓ Comando desconocido: <code>{$safe}</code>\n\nUsá /ayuda para ver los comandos disponibles.";
                $this->telegramClient->sendMessage($chatId, $text);
                log_message("trackerGram: comando desconocido '{$command}' en chat {$chatId}");
                break;
        }
    }

    /**
     * Procesar el comando /estado: recopila health check de todas las conexiones
     */
    private function handleEstado(int $chatId): void
    {
        $lines = [];
        $lines[] = "📊 <b>trackerGram " . TRACKERGRAM_VERSION . " — Estado</b>\n";

        // Conexión
        $connName = $this->connectionName ?: 'Conexión #' . $this->trackerId;
        $lines[] = "<b>Conexión:</b> {$connName}";

        // Admin panel
        if ($this->adminUrl !== '') {
            $lines[] = "<b>Admin:</b> <a href=\"{$this->adminUrl}\">{$this->adminUrl}</a>";
        }

        // Bot de Telegram
        $tgTest = $this->telegramClient->testConnection();
        if ($tgTest['ok']) {
            $botName = $tgTest['bot_name'] ?? '';
            $lines[] = "🤖 <b>Bot:</b> @{$botName} ✅";
        } else {
            $lines[] = "🤖 <b>Bot:</b> ❌ " . $tgTest['message'];
        }

        // Webhook info — pending_count bajo (< 10) no se muestra porque siempre vemos "1 pendientes"
        // desde el propio comando /estado (BUG-002: el Bot API cuenta el update actual como pendiente
        // hasta recibir el 200 OK de ese mismo update). Solución posta: determinar el pending real
        // restando 1 (el propio comando), o consultar getWebhookInfo desde un health check externo.
        $webhookInfo = $this->telegramClient->getWebhookInfo();
        if ($webhookInfo['ok'] ?? $webhookInfo['url'] ?? false) {
            $pending = $webhookInfo['pending_update_count'] ?? 0;
            $lastError = $webhookInfo['last_error_message'] ?? '';
            $lastErrorDate = $webhookInfo['last_error_date'] ?? 0;
            $lastSuccess = $webhookInfo['last_successful_synchronization'] ?? 0;

            // Error histórico: el webhook ya se recuperó (success más reciente que error)
            $errorStale = $lastError !== '' && $lastSuccess > 0 && $lastSuccess > $lastErrorDate;

            if ($pending > 10 || ($lastError && !$errorStale)) {
                $statusIcon = ($lastError && !$errorStale) ? '⚠️' : '⚠️';
                $parts = ["{$statusIcon} <b>Webhook:</b> Activo ({$pending} pendientes)"];
                if ($lastError && !$errorStale) {
                    $parts[] = "error: {$lastError}";
                } elseif ($errorStale) {
                    $parts[] = "ⓘ error histórico: {$lastError} (ya recuperado)";
                }
                $lines[] = implode(' | ', $parts);
            } else {
                $lines[] = '🌐 <b>Webhook:</b> ✅ Activo';
            }
        } else {
            $lines[] = "🌐 <b>Webhook:</b> ❌ No configurado";
        }

        // TikiWiki — usa testConnection() (GET /api/trackers) que siempre funciona
        $tikiTest = $this->tikiWikiClient->testConnection();
        if ($tikiTest['ok']) {
            $versionLabel = '';
            // Intento opcional de versión (puede fallar con 406 en algunos hosts)
            $tikiVersion = $this->tikiWikiClient->getVersion();
            if ($tikiVersion !== null) {
                $versionLabel = " v{$tikiVersion}";
            }
            $lines[] = "🗄️ <b>TikiWiki:</b>{$versionLabel} ✅";
        } else {
            $lines[] = "🗄️ <b>TikiWiki:</b> ❌ " . $tikiTest['message']
                . " — revisar <a href=\"{$this->adminUrl}\">admin panel</a>";
            log_message("handleEstado: testConnection() falló para conexión '{$this->connectionName}' tracker #{$this->trackerId}: " . $tikiTest['message']);
        }

        // Tracker link
        $webUrl = $this->tikiWikiClient->getBaseUrl();
        $trackerLink = rtrim($webUrl, '/') . '/tiki-view_tracker.php?trackerId=' . $this->trackerId;
        $lines[] = "🎯 <b>Tracker:</b> <a href=\"{$trackerLink}\">#{$this->trackerId}</a>";

        $text = implode("\n", $lines);
        $this->telegramClient->sendMessage($chatId, $text);
        log_message("trackerGram: /estado respondido en chat {$chatId}");
    }

    /**
     * Procesar actualización de Telegram (dispatcher)
     */
    public function processUpdate(array $update): void
    {
        // GC probabilístico para álbumes stale
        $this->gcAlbumBuffer();

        // Detectar si el bot fue agregado a un chat no autorizado (my_chat_member)
        if (isset($update['my_chat_member'])) {
            $this->processMyChatMember($update['my_chat_member']);
            return;
        }

        if (isset($update['callback_query'])) {
            $this->processCallbackQuery($update['callback_query']);
        } elseif (isset($update['message'])) {
            $this->processMessage($update['message']);
        } elseif (isset($update['edited_message'])) {
            $this->processEditedMessage($update['edited_message']);
        } elseif (isset($update['edited_channel_post'])) {
            $this->processEditedMessage($update['edited_channel_post']);
        } elseif (isset($update['message_reaction'])) {
            $this->processMessageReaction($update['message_reaction']);
        } elseif (isset($update['message_reaction_count'])) {
            $this->processMessageReactionCount($update['message_reaction_count']);
        }
    }

    // ──────────────────────────────────────────────
    // /gather — formulario de recolección simple
    // ──────────────────────────────────────────────

    /**
     * Obtener la clave de sesión para un chat+usuario
     */
    private function getSessionKey(int $chatId, int $userId): string
    {
        return $chatId . '_' . $userId;
    }

    /**
     * Construir el texto del formulario según el estado de la sesión
     */
    private function buildFormText(array $session): string
    {
        $lines = [];
        $lines[] = '📋 <b>NUEVO REGISTRO</b>';
        $lines[] = '─────────────────';

        foreach ($session['fields'] as $key => $field) {
            $icon = match ($key) {
                'foto' => '📷',
                default => '✏️',
            };
            $value = $field['value'] ?? null;
            if ($value !== null && $value !== '') {
                $display = is_string($value)
                    ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : '✅ adjunta';
            } else {
                $display = '<i>(pendiente)</i>';
            }
            $lines[] = "{$icon} <b>{$field['label']}:</b> {$display}";
        }

        $lines[] = '─────────────────';
        $lines[] = 'Tocá un campo para cargarlo.';
        return implode("\n", $lines);
    }

    /**
     * Construir el teclado inline según el estado de la sesión
     */
    private function buildFormKeyboard(array $session): array
    {
        $keyboard = [];
        $allFilled = true;

        foreach ($session['fields'] as $key => $field) {
            $value = $field['value'] ?? null;
            $filled = ($value !== null && $value !== '');
            if (!$filled) {
                $allFilled = false;
            }

            $icon = match ($key) {
                'foto' => '📷',
                default => '✏️',
            };
            $status = $filled ? ' ✅' : '';
            $keyboard[] = [
                ['text' => "{$icon} {$field['label']}{$status}", 'callback_data' => "field_{$key}"],
            ];
        }

        $keyboard[] = [
            ['text' => $allFilled ? '✅ Guardar' : '❌ Salir', 'callback_data' => $allFilled ? 'guardar' : 'salir'],
        ];

        return $keyboard;
    }

    /**
     * Manejar /gather — inicia sesión y envía formulario
     */
    private function handleGather(int $chatId, array $message): void
    {
        if ($this->collectSessionManager === null) {
            $this->telegramClient->sendMessage($chatId, '❌ El formulario /gather no está disponible (falta CollectSessionManager).');
            return;
        }

        $from = $message['from'] ?? [];
        $userId = $from['id'] ?? 0;
        $key = $this->getSessionKey($chatId, $userId);

        // Verificar si ya hay una sesión activa
        $existing = $this->collectSessionManager->get($key);
        if ($existing !== null) {
            $this->telegramClient->sendMessage($chatId, '⚠️ Ya tenés un formulario abierto. Terminalo con Guardar o Salir.');
            return;
        }

        // Crear sesión con contexto para poder guardar en TikiWiki después
        $firstName = $from['first_name'] ?? '';
        $lastName = $from['last_name'] ?? '';
        $session = [
            'chatId' => $chatId,
            'userId' => $userId,
            'username' => $from['username'] ?? '',
            'firstName' => $firstName,
            'lastName' => $lastName,
            'displayName' => trim($firstName . ' ' . $lastName),
            'chatTitle' => $message['chat']['title'] ?? $message['chat']['username'] ?? 'Chat ' . $chatId,
            'formMessageId' => 0,
            'awaiting' => null,
            'fields' => [
                'nombre' => ['label' => 'Nombre', 'value' => null, 'type' => 'text'],
                'apellido' => ['label' => 'Apellido', 'value' => null, 'type' => 'text'],
                'foto' => ['label' => 'Foto', 'value' => null, 'type' => 'photo'],
            ],
        ];

        $text = $this->buildFormText($session);
        $keyboard = $this->buildFormKeyboard($session);

        $result = $this->telegramClient->sendMessage($chatId, $text, [
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);

        if ($result === false) {
            log_message("trackerGram: /gather — no se pudo enviar el formulario", true);
            return;
        }

        $messageId = is_array($result) ? ($result['message_id'] ?? 0) : 0;
        $session['formMessageId'] = $messageId;
        $this->collectSessionManager->set($key, $session);
        log_message("trackerGram: /gather iniciado por user={$userId} en chat={$chatId}");
    }

    /**
     * Manejar callback_query — toques en los botones del formulario
     */
    private function processCallbackQuery(array $callbackQuery): void
    {
        $callbackId = $callbackQuery['id'] ?? '';
        $data = $callbackQuery['data'] ?? '';
        $from = $callbackQuery['from'] ?? [];
        $userId = $from['id'] ?? 0;
        $message = $callbackQuery['message'] ?? [];
        $chatId = $message['chat']['id'] ?? 0;

        if ($this->collectSessionManager === null) {
            $this->telegramClient->answerCallbackQuery($callbackId);
            return;
        }

        $key = $this->getSessionKey($chatId, $userId);
        $session = $this->collectSessionManager->get($key);
        if ($session === null) {
            $this->telegramClient->answerCallbackQuery($callbackId, '❌ Sesión no encontrada. Usá /gather para empezar.', true);
            log_message("trackerGram: callback_query sin sesión: user={$userId} chat={$chatId}");
            return;
        }

        // Procesar según callback_data
        if ($data === 'salir') {
            $this->telegramClient->answerCallbackQuery($callbackId, '❌ Registro cancelado.');
            $this->telegramClient->editMessageText($chatId, $session['formMessageId'], '❌ Registro cancelado.', [
                'reply_markup' => json_encode(['inline_keyboard' => []]),
            ]);
            $this->collectSessionManager->delete($key);
            log_message("trackerGram: /gather cancelado por user={$userId} en chat={$chatId}");
            return;
        }

        if ($data === 'guardar') {
            $this->telegramClient->answerCallbackQuery($callbackId, '✅ Guardando...', false);

            // 1. Construir texto del formulario
            $textParts = [];
            $textParts[] = '📋 Registro de ' . ($session['displayName'] ?? 'Usuario ' . $session['userId']);
            $textParts[] = '─────────────────';
            foreach ($session['fields'] as $keyf => $field) {
                $plain = $field['value'] ?? '(vacío)';
                if ($keyf === 'foto' && $plain !== null && $plain !== '' && $plain !== '(vacío)') {
                    $textParts[] = $field['label'] . ': ✅';
                } else {
                    $textParts[] = $field['label'] . ': ' . $plain;
                }
            }
            $formText = implode("\n", $textParts);

            // 2. Crear NormalizedMessage como si viniera de un webhook
            $msg = new NormalizedMessage();
            $msg->messageId = 'gather_' . $key . '_' . time();
            $msg->chatId = (string) $session['chatId'];
            $msg->chatTitle = $session['chatTitle'] ?? 'Chat ' . $session['chatId'];
            $msg->topicId = '0';
            $msg->topicTitle = 'General';
            $msg->userId = (string) $session['userId'];
            $msg->username = $session['username'] ?? '';
            $msg->firstName = $session['firstName'] ?? '';
            $msg->lastName = $session['lastName'] ?? '';
            $msg->displayName = $session['displayName'] ?? '';
            $msg->messageType = 'text';
            $msg->text = $formText;
            $msg->date = (string) time();

            // 3. Si hay foto, descargar de Telegram y subir a TikiWiki
            $photoFileId = $session['fields']['foto']['value'] ?? null;
            if ($photoFileId !== null && $photoFileId !== '') {
                $msg->fileId = $photoFileId;
                $msg->fileName = 'gather_photo_' . $photoFileId . '.jpg';
                $msg->mimeType = 'image/jpeg';
                $msg->mediaType = 'image/jpeg';
                $msg->messageType = 'photo';

                $uploadedFileId = $this->downloadAndUploadMedia($msg);
                if ($uploadedFileId) {
                    $msg->uploadedFileIds = [$uploadedFileId];
                    $msg->mediaUrl = $this->tikiWikiClient->getBaseUrl() . '/tiki-download_file.php?fileId=' . $uploadedFileId;
                } else {
                    log_message("trackerGram: /gather — no se pudo subir foto para user={$userId}", true);
                    $msg->messageType = 'text';
                    $msg->text .= "\n\n[⚠️ Foto no pudo subirse]";
                }
            }

            // 4. Enviar a TikiWiki
            $success = $this->sendToTikiWikiWithRetries($msg);

            // 5. Mostrar resultado al usuario
            $resultLines = $success
                ? ['✅ <b>Guardado en el tracker</b>', '─────────────────']
                : ['❌ <b>Error al guardar</b>', '─────────────────'];

            foreach ($session['fields'] as $keyf => $field) {
                $plain = $field['value'] ?? '(vacío)';
                $safe = htmlspecialchars((string) $plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $resultLines[] = htmlspecialchars($field['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': ' . $safe;
            }
            $resultLines[] = '─────────────────';
            if ($success) {
                $resultLines[] = 'Usá /gather para crear otro.';
            } else {
                $resultLines[] = '❌ Ocurrió un error al guardar. Revisá los logs.';
            }

            $this->telegramClient->editMessageText($chatId, $session['formMessageId'], implode("\n", $resultLines), [
                'reply_markup' => json_encode(['inline_keyboard' => []]),
            ]);
            $this->collectSessionManager->delete($key);
            log_message("trackerGram: /gather " . ($success ? 'guardado' : 'ERROR') . " por user={$userId} en chat={$chatId} — " . json_encode($session['fields']));
            return;
        }

        // field_X — click en un campo
        if (str_starts_with($data, 'field_')) {
            $fieldKey = substr($data, 6);
            $field = $session['fields'][$fieldKey] ?? null;
            if ($field === null) {
                $this->telegramClient->answerCallbackQuery($callbackId, '❌ Campo desconocido.', true);
                return;
            }

            // Dismiss loading indicator
            $this->telegramClient->answerCallbackQuery($callbackId);

            $session['awaiting'] = $fieldKey;
            $this->collectSessionManager->set($key, $session);

            // Pedir el valor
            $prompt = match ($field['type']) {
                'photo' => "📷 Enviá la <b>{$field['label']}</b>:",
                default => "✏️ Ingresá el/la <b>{$field['label']}</b>:",
            };
            $this->telegramClient->sendMessage($chatId, $prompt, [
                'reply_to_message_id' => $session['formMessageId'],
            ]);
            return;
        }

        // callback_data desconocido
        $this->telegramClient->answerCallbackQuery($callbackId, '❌ Acción desconocida.', true);
        log_message("trackerGram: callback_query con data desconocida: '{$data}' user={$userId}");
    }

    /**
     * Manejar respuesta a un campo del formulario (texto o foto)
     */
    private function handleCollectResponse(array $session, string $sessionKey, array $message): void
    {
        $awaiting = $session['awaiting'];
        $field = $session['fields'][$awaiting] ?? null;
        if ($field === null) {
            return;
        }

        $chatId = $session['chatId'];

        // Obtener valor según tipo
        if ($field['type'] === 'photo') {
            $photos = $message['photo'] ?? [];
            if (empty($photos)) {
                // No es una foto, puede ser texto u otro
                if (isset($message['text'])) {
                    $this->telegramClient->sendMessage($chatId, '📷 Esperaba una foto. Enviá una foto o toca <b>Salir</b> en el formulario.');
                }
                return;
            }
            // Usar la foto de mayor resolución (última del array)
            $bestQuality = end($photos);
            $session['fields'][$awaiting]['value'] = $bestQuality['file_id'];
        } else {
            $text = $message['text'] ?? $message['caption'] ?? '';
            if ($text === '') {
                $this->telegramClient->sendMessage($chatId, "✏️ Esperaba texto para <b>{$field['label']}</b>. Escribí el valor o toca <b>Salir</b> en el formulario.");
                return;
            }
            $session['fields'][$awaiting]['value'] = $text;
        }

        // Campo cargado, limpiar awaiting y actualizar formulario
        $session['awaiting'] = null;
        $this->collectSessionManager->set($sessionKey, $session);

        $updatedText = $this->buildFormText($session);
        $updatedKeyboard = $this->buildFormKeyboard($session);
        $this->telegramClient->editMessageText($chatId, $session['formMessageId'], $updatedText, [
            'reply_markup' => json_encode(['inline_keyboard' => $updatedKeyboard]),
        ]);
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
        return defined('TEMP_DIR') ? TEMP_DIR . '/media_group_captions.json' : __DIR__ . '/media_group_captions.json';
    }

    /**
     * Operación atómica sobre media_group_captions.json: adquiere LOCK_EX, lee,
     * ejecuta el callback con los datos, escribe el resultado, libera.
     * Previene race conditions entre requests concurrentes del mismo álbum.
     *
     * @param callable $mutate fn(array $captions): array Recibe datos actuales, retorna modificados
     */
    private function withMediaGroupCaptionsLock(callable $mutate): void
    {
        $file = $this->getMediaGroupCaptionFile();
        $fp = fopen($file, 'c+');
        if (!$fp) {
            log_message("trackerGram: No se pudo abrir media_group_captions.json para lock atómico", true);
            return;
        }
        flock($fp, LOCK_EX);

        // Leer estado actual
        $content = stream_get_contents($fp);
        $captions = [];
        if ($content !== false && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $captions = $decoded;
            }
        }

        // Limpiar entradas expiradas (>60s) antes de mutar
        $now = time();
        foreach ($captions as $k => $entry) {
            if (($now - ($entry['time'] ?? 0)) > 60) {
                unset($captions[$k]);
            }
        }

        // Aplicar mutación
        $captions = $mutate($captions);

        // Escribir atómicamente
        rewind($fp);
        $written = fwrite($fp, json_encode($captions));
        if ($written !== false) {
            ftruncate($fp, ftell($fp));
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Propagar caption dentro de un álbum.
     * Operación atómica read-modify-write con LOCK_EX sostenido.
     */
    private function propagateMediaGroupCaption(NormalizedMessage $msg, array $message): void
    {
        if (!isset($message['media_group_id'])) return;

        $chatId = $message['chat']['id'];
        $groupId = $message['media_group_id'];
        $key = $chatId . ':' . $groupId;
        $currentCaption = $message['caption'] ?? '';

        if ($currentCaption !== '') {
            // Primer mensaje del álbum — guardar caption atómicamente
            $this->withMediaGroupCaptionsLock(function(array $captions) use ($key, $currentCaption): array {
                $captions[$key] = ['caption' => $currentCaption, 'time' => time()];
                return $captions;
            });
        } else {
            // Mensaje subsiguiente — propagar caption guardado
            if ($msg->mediaCaption !== '') return; // ya tiene caption
            $savedCaption = null;
            $this->withMediaGroupCaptionsLock(function(array $captions) use ($key, &$savedCaption): array {
                $savedCaption = $captions[$key]['caption'] ?? '';
                return $captions; // solo lectura, no mutamos
            });
            if ($savedCaption !== null && $savedCaption !== '') {
                $msg->mediaCaption = $savedCaption;
                if ($msg->text !== '' && strpos($msg->text, ':') !== false && strpos($msg->text, $savedCaption) === false) {
                    $msg->text .= ' - ' . $savedCaption;
                }
            }
        }
    }
}
