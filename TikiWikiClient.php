<?php
/**
 * TikiWikiClient - Cliente para comunicarse con la API de TikiWiki
 * Encapsula toda la lógica de comunicación con TikiWiki: trackers, galleries, items
 */
class TikiWikiClient
{
    private string $apiUrl;
    private string $token;
    private int $timeout;
    private int $uploadTimeout;
    private array $mediaGalleryIdCache = [];
    /** Trackers where repairFgGallery was already attempted (prevents loops) */
    private array $repairedTrackers = [];

    public function __construct(
        string $apiUrl,
        string $token,
        int $timeout = 30,
        int $uploadTimeout = 30
    ) {
        $this->apiUrl = rtrim($apiUrl, '/') . '/';
        $this->token = $token;
        $this->timeout = $timeout;
        $this->uploadTimeout = $uploadTimeout;
    }

    public function getMediaGalleryId(?int $trackerId = null): ?int
    {
        $trackerId ??= (int) getenv('TIKIWIKI_TRACKER_ID') ?: 12;

        if (isset($this->mediaGalleryIdCache[$trackerId])) {
            return $this->mediaGalleryIdCache[$trackerId];
        }

        // Usar el endpoint de fields, NO trackers/{id} (que lista items en TikiWiki 27+)
        $url = $this->apiUrl . "trackers/$trackerId/fields";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['fields'])) {
                foreach ($data['fields'] as $field) {
                    if (($field['type'] ?? '') === 'FG' && ($field['permName'] ?? '') === 'telegrammessageMedia') {
                        $options = $field['options'] ?? null;
                        $galleryId = $this->extractGalleryIdFromOptions($options);
                        if ($galleryId !== null) {
                            $this->mediaGalleryIdCache[$trackerId] = $galleryId;
                            log_message("TikiWikiClient: Gallery ID {$galleryId} resuelto para tracker {$trackerId}");
                            return $galleryId;
                        }
                        // No se pudo extraer galleryId — loguear el options real para debug
                        $optionsPreview = is_string($options) ? $options : (is_array($options) ? json_encode($options, JSON_UNESCAPED_UNICODE) : var_export($options, true));
                        log_message("TikiWikiClient: No se pudo extraer galleryId de options del campo FG en tracker {$trackerId}. Raw options: " . substr($optionsPreview, 0, 500));
                        
                        // Auto-reparación: crear galería + actualizar FG field (solo una vez por tracker)
                        if (!in_array($trackerId, $this->repairedTrackers, true)) {
                            $this->repairedTrackers[] = $trackerId;
                            log_message("TikiWikiClient: Intentando auto-reparar galería para tracker {$trackerId}");
                            $newGalleryId = $this->repairFgGallery($trackerId);
                            if ($newGalleryId !== null) {
                                $this->mediaGalleryIdCache[$trackerId] = $newGalleryId;
                                log_message("TikiWikiClient: Gallery ID {$newGalleryId} creado y asignado tras auto-reparación");
                                return $newGalleryId;
                            }
                        } else {
                            log_message("TikiWikiClient: Auto-reparación ya intentada para tracker {$trackerId} — no reintentar");
                        }
                    }
                }
            } else {
                log_message("TikiWikiClient: GET trackers/{$trackerId}/fields sin clave 'fields'. Keys: " . implode(', ', array_keys($data)));
            }
        } else {
            log_message("TikiWikiClient: Error HTTP {$httpCode} al obtener fields de tracker {$trackerId}");
        }

        return null;
    }

    /**
     * Extrae el galleryId de las options de un campo FG en distintos formatos
     *
     * La API de TikiWiki puede devolver options en estos formatos:
     *   1. [{"name": "galleryId", "value": "36"}]   — array de objetos (esperado por código legacy)
     *   2. {"galleryId": 36}                         — array asociativo (TikiWiki 27+)
     *   3. "{\"galleryId\":36}"                      — JSON string (API raw)
     *   4. [36]                                       — array plano (fallback)
     */
    private function extractGalleryIdFromOptions($options): ?int
    {
        if ($options === null || $options === '' || $options === []) {
            return null;
        }

        // Si es string, intentar decodificar JSON
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            $options = $decoded;
        }

        if (!is_array($options)) {
            return null;
        }

        // Formato 1: [{"name": "galleryId", "value": "36"}]
        // Buscar primer elemento con 'value'
        foreach ($options as $opt) {
            if (is_array($opt) && isset($opt['value'])) {
                return (int) $opt['value'];
            }
        }

        // Formato 2: {"galleryId": 36} (array asociativo)
        foreach (['galleryId', 'gallery_id', 'gal_id', 'id'] as $key) {
            if (isset($options[$key]) && is_numeric($options[$key])) {
                return (int) $options[$key];
            }
        }

        // Formato 4: Array plano [36]
        if (isset($options[0]) && is_numeric($options[0])) {
            return (int) $options[0];
        }

        // Buscar cualquier valor numérico como último recurso
        foreach ($options as $val) {
            if (is_numeric($val)) {
                return (int) $val;
            }
        }

        return null;
    }

    // extractTrackerData eliminado — ahora usamos GET /api/trackers/{id}/fields directamente

    public function uploadFile(string $filePath, string $fileName, ?int $galleryId = null): ?string
    {
        $galleryId ??= $this->getMediaGalleryId();

        $url = $this->apiUrl . "galleries/upload";

        if (!file_exists($filePath)) {
            log_message("TikiWikiClient: File not found for upload: $filePath");
            return null;
        }

        $mimeType = $this->getMimeType($filePath);

        $postFields = [
            'galleryId' => $galleryId,
            'data' => curl_file_create($filePath, $mimeType, $fileName),
            'name' => $fileName,
            'title' => $fileName,
            'description' => 'Subido desde trackerGram webhook - ' . date('Y-m-d H:i:s')
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->uploadTimeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log_message("TikiWikiClient: cURL error en uploadFile: $curlError");
            return null;
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            log_message("TikiWikiClient: uploadFile HTTP $httpCode - Response: " . substr($response, 0, 200));
            return null;
        }

        $data = json_decode($response, true);
        $fileId = $data['fileId'] ?? $data['file_id'] ?? $data['id'] ?? null;
        if ($fileId) {
            return (string) $fileId;
        }

        log_message("TikiWikiClient: uploadFile respuesta sin fileId - Response: " . substr($response, 0, 200));
        return null;
    }

    public function createTrackerItem(int $trackerId, array $postFields): bool
    {
        $url = $this->apiUrl . "trackers/$trackerId/items";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // No FOLLOWLOCATION: evita leak del token si TikiWiki redirige a otro host
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            log_message("TikiWikiClient: cURL error al crear item: $error");
            return false;
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            log_message("TikiWikiClient: HTTP $httpCode al crear item - Response: $response");
            return false;
        }

        $responseData = json_decode($response, true);
        if (!$responseData || !isset($responseData['itemId'])) {
            $clean = str_replace(["\r", "\n"], ' ', strip_tags(substr($response, 0, 300)));
            log_message("TikiWikiClient: Respuesta inválida (Status $httpCode): $clean");
            return false;
        }

        log_message("TikiWikiClient: Item creado - itemId={$responseData['itemId']}");
        return true;
    }

    public function messageExists(int $trackerId, int $messageId, ?int $chatId = null): int
    {
        $url = $this->apiUrl . "trackers/$trackerId/items?filter[fields][telegrammessageTelegramMessageId]=$messageId";

        if ($chatId !== null) {
            $url .= "&filter[fields][telegrammessageChatId]=$chatId";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return count($data['data'] ?? []);
        }

        return 0;
    }

    /**
     * Crear una file gallery en TikiWiki
     */
    public function createGallery(string $name, string $description = ''): ?int
    {
        $url = $this->apiUrl . "galleries";

        $payload = json_encode([
            'name' => $name,
            'description' => $description ?: 'Galería creada por trackerGram',
            'parentId' => 0,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "Content-Type: application/json",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 201) {
            $data = json_decode($response, true);
            $galleryId = $data['galleryId'] ?? $data['id'] ?? null;
            if ($galleryId) {
                log_message("TikiWikiClient: Galería '{$name}' creada con ID {$galleryId}");
                return (int) $galleryId;
            }
        }

        log_message("TikiWikiClient: Error al crear galería '{$name}' (HTTP {$httpCode})");
        return null;
    }

    /**
     * Actualizar las options de un campo FG (File Gallery) en un tracker existente.
     * Usado para asignar galería y configurar count=0 (ilimitado).
     */
    public function updateFgFieldOptions(int $trackerId, int $galleryId, string $excessBehavior = 'discard'): bool
    {
        // Obtener fieldId del campo FG desde GET /api/trackers/{id}/fields
        $url = $this->apiUrl . "trackers/{$trackerId}/fields";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            log_message("TikiWikiClient: Error HTTP {$httpCode} al obtener fields de tracker {$trackerId}");
            return false;
        }

        $data = json_decode($response, true);

        $fieldId = null;
        if (isset($data['fields'])) {
            foreach ($data['fields'] as $field) {
                if (($field['permName'] ?? '') === 'telegrammessageMedia') {
                    $fieldId = $field['fieldId'] ?? $field['id'] ?? null;
                    break;
                }
            }
        }

        if ($fieldId === null) {
            log_message("TikiWikiClient: No se encontró el campo telegrammessageMedia en tracker {$trackerId}");
            return false;
        }

        // Actualizar las options del field
        $updateUrl = $this->apiUrl . "trackers/{$trackerId}/fields/{$fieldId}";
        $postData = http_build_query([
            'option[galleryId]' => $galleryId,
            'option[count]' => 0,
            'option[excessBehavior]' => $excessBehavior,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $updateUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "Content-Type: application/x-www-form-urlencoded",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            log_message("TikiWikiClient: FG field options actualizadas en tracker {$trackerId}: galleryId={$galleryId}, count=0");
            return true;
        }

        log_message("TikiWikiClient: Error HTTP {$httpCode} al actualizar FG field en tracker {$trackerId}");
        return false;
    }

    /**
     * Reparar campo FG de un tracker: crea galería y actualiza options
     * Se llama automáticamente cuando getMediaGalleryId() no encuentra galleryId
     */
    private function repairFgGallery(int $trackerId): ?int
    {
        $trackerName = 'Tracker ' . $trackerId;

        // Crear galería
        $galleryId = $this->createGallery($trackerName . ' Media');
        if ($galleryId === null) {
            log_message("TikiWikiClient: repairFgGallery — no se pudo crear galería para tracker {$trackerId}");
            return null;
        }

        // Actualizar el campo FG
        if ($this->updateFgFieldOptions($trackerId, $galleryId)) {
            log_message("TikiWikiClient: repairFgGallery — galería {$galleryId} asignada a tracker {$trackerId}");
            return $galleryId;
        }

        log_message("TikiWikiClient: repairFgGallery — galería creada ({$galleryId}) pero no se pudo actualizar FG field");
        return $galleryId; // retornar la galería igual, por si el update falla pero la galería existe
    }

    public function createTracker(string $trackerName): ?int
    {
        $url = $this->apiUrl . "trackers";

        // 1. Crear galería de medios asociada
        $galleryId = $this->createGallery($trackerName . ' Media');

        // 2. Armar definición del campo FG con options si tenemos galería
        $fgField = [
            'name' => 'telegrammessageMedia',
            'type' => 'FG',
            'permName' => 'telegrammessageMedia',
        ];
        if ($galleryId !== null) {
            $fgField['options'] = [
                'galleryId' => $galleryId,
                'count' => 0,
                'excessBehavior' => 'discard',
            ];
        }

        $fields = [
            'name' => $trackerName,
            'description' => 'Tracker automático creado por trackerGram',
            'fields' => [
                ['name' => 'telegrammessageTelegramMessageId', 'type' => 't', 'permName' => 'telegrammessageTelegramMessageId'],
                ['name' => 'telegrammessageChatId', 'type' => 't', 'permName' => 'telegrammessageChatId'],
                ['name' => 'telegrammessageChatTitle', 'type' => 't', 'permName' => 'telegrammessageChatTitle'],
                ['name' => 'telegrammessageTopicId', 'type' => 't', 'permName' => 'telegrammessageTopicId'],
                ['name' => 'telegrammessageTopicTitle', 'type' => 't', 'permName' => 'telegrammessageTopicTitle'],
                ['name' => 'telegrammessageUserId', 'type' => 't', 'permName' => 'telegrammessageUserId'],
                ['name' => 'telegrammessageUsername', 'type' => 't', 'permName' => 'telegrammessageUsername'],
                ['name' => 'telegrammessageFirstName', 'type' => 't', 'permName' => 'telegrammessageFirstName'],
                ['name' => 'telegrammessageLastName', 'type' => 't', 'permName' => 'telegrammessageLastName'],
                ['name' => 'telegrammessageDisplayName', 'type' => 't', 'permName' => 'telegrammessageDisplayName'],
                ['name' => 'telegrammessageMessageType', 'type' => 't', 'permName' => 'telegrammessageMessageType'],
                ['name' => 'telegrammessageText', 'type' => 'a', 'permName' => 'telegrammessageText'],
                ['name' => 'telegrammessageLocation', 'type' => 'G', 'permName' => 'telegrammessageLocation'],
                ['name' => 'telegrammessageMediaType', 'type' => 't', 'permName' => 'telegrammessageMediaType'],
                ['name' => 'telegrammessageMediaSize', 'type' => 'n', 'permName' => 'telegrammessageMediaSize'],
                ['name' => 'telegrammessageMediaCaption', 'type' => 't', 'permName' => 'telegrammessageMediaCaption'],
                ['name' => 'telegrammessageMessageDate', 'type' => 'f', 'permName' => 'telegrammessageMessageDate'],
                $fgField,
                ['name' => 'telegrammessageMediaUrl', 'type' => 't', 'permName' => 'telegrammessageMediaUrl'],
                ['name' => 'telegrammessageFileUrl', 'type' => 't', 'permName' => 'telegrammessageFileUrl'],
                ['name' => 'telegrammessageMediaWidth', 'type' => 'n', 'permName' => 'telegrammessageMediaWidth'],
                ['name' => 'telegrammessageMediaHeight', 'type' => 'n', 'permName' => 'telegrammessageMediaHeight'],
                ['name' => 'telegrammessageMediaDuration', 'type' => 'DUR', 'permName' => 'telegrammessageMediaDuration'],
                ['name' => 'telegrammessageEditedDate', 'type' => 't', 'permName' => 'telegrammessageEditedDate'],
                ['name' => 'telegrammessageReplyToId', 'type' => 't', 'permName' => 'telegrammessageReplyToId'],
                ['name' => 'telegrammessageReactions', 'type' => 'a', 'permName' => 'telegrammessageReactions']
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "Content-Type: application/json",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $trackerId = $data['id'] ?? null;
            if ($trackerId) {
                // 3. Si la galería se creó pero el FG field inline no tomó las options,
                //    hacemos un update explícito
                if ($galleryId !== null) {
                    $this->updateFgFieldOptions((int) $trackerId, $galleryId);
                }
                return (int) $trackerId;
            }
        }

        return null;
    }

    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'zip' => 'application/zip'
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
