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
    private string $fieldPrefix = 'telegrammessage';
    private array $mediaGalleryIdCache = [];
    /** Trackers where repairFgGallery was already attempted (prevents loops) */
    private array $repairedTrackers = [];

    /**
     * Setear field prefix para todos los permNames (ej: 'qpch', 'soporte')
     */
    public function setFieldPrefix(string $prefix): void
    {
        $this->fieldPrefix = $prefix;
    }

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

    public function getBaseUrl(): string
    {
        return rtrim(str_replace('/api/', '', $this->apiUrl), '/');
    }

    public function getMediaGalleryId(?int $trackerId = null): ?int
    {
        $trackerId ??= 12; // fallback default tracker ID

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
                    if (($field['type'] ?? '') === 'FG' && ($field['permName'] ?? '') === $this->fieldPrefix . 'Media') {
                        $options = $field['options'] ?? null;
                        $galleryId = $this->extractGalleryIdFromOptions($options);
                        if ($galleryId !== null) {
                            $this->mediaGalleryIdCache[$trackerId] = $galleryId;
                            log_message("TikiWikiClient: Gallery ID {$galleryId} resuelto para tracker {$trackerId}");
                            return $galleryId;
                        }
                        // No se pudo extraer galleryId — loguear el options real para debug
                        $optionsPreview = is_string($options) ? $options : (is_array($options) ? json_encode($options, JSON_UNESCAPED_UNICODE) : var_export($options, true));
                        log_message("TikiWikiClient: No se pudo extraer galleryId de options del campo FG en tracker {$trackerId}. Raw options: " . substr($optionsPreview, 0, 500), true);
                        
                        // Auto-reparación: crear galería + actualizar FG field (solo una vez por tracker)
                        if (!in_array($trackerId, $this->repairedTrackers, true)) {
                            $this->repairedTrackers[] = $trackerId;
                            log_message("TikiWikiClient: Intentando auto-reparar galería para tracker {$trackerId}", true);
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
            log_message("TikiWikiClient: GET trackers/{$trackerId}/fields sin clave 'fields'. Keys: " . implode(', ', array_keys($data)), true);
        }
    } else {
        log_message("TikiWikiClient: Error HTTP {$httpCode} al obtener fields de tracker {$trackerId}", true);
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

    public function uploadFile(string $filePath, string $fileName, ?int $galleryId = null, string $source = 'webhook', string $caption = ''): ?string
    {
        $galleryId ??= $this->getMediaGalleryId();

        $url = $this->apiUrl . "galleries/upload";

        if (!file_exists($filePath)) {
            log_message("TikiWikiClient: File not found for upload: $filePath", true);
            return null;
        }

        $mimeType = $this->getMimeType($filePath);

        $description = 'Subido desde trackerGram ' . $source;
        if ($caption !== '') {
            $description .= ' | ' . $caption;
        } else {
            $description .= ' - ' . date('Y-m-d H:i:s');
        }

        $postFields = [
            'galleryId' => $galleryId,
            'data' => curl_file_create($filePath, $mimeType, $fileName),
            'name' => $fileName,
            'title' => $fileName,
            'description' => $description
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
            log_message("TikiWikiClient: cURL error en uploadFile: $curlError", true);
            return null;
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            log_message("TikiWikiClient: uploadFile HTTP $httpCode - Response: " . substr($response, 0, 200), true);
            return null;
        }

        $data = json_decode($response, true);
        $fileId = $data['fileId'] ?? $data['file_id'] ?? $data['id'] ?? null;
        if ($fileId) {
            return (string) $fileId;
        }

        log_message("TikiWikiClient: uploadFile respuesta sin fileId - Response: " . substr($response, 0, 200), true);
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
            log_message("TikiWikiClient: cURL error al crear item: $error", true);
            return false;
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            log_message("TikiWikiClient: HTTP $httpCode al crear item - Response: $response", true);
            return false;
        }

        $responseData = json_decode($response, true);
        if (!$responseData || !isset($responseData['itemId'])) {
            $clean = str_replace(["\r", "\n"], ' ', strip_tags(substr($response, 0, 300)));
            log_message("TikiWikiClient: Respuesta inválida (Status $httpCode): $clean", true);
            return false;
        }

        log_message("TikiWikiClient: Item creado - itemId={$responseData['itemId']}");
        return true;
    }

    public function messageExists(int $trackerId, int $messageId, ?int $chatId = null): int
    {
        $url = $this->apiUrl . "trackers/$trackerId/items?filter[fields][" . $this->fieldPrefix . "TelegramMessageId]=$messageId";

        if ($chatId !== null) {
            $url .= "&filter[fields][" . $this->fieldPrefix . "ChatId]=$chatId";
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

        log_message("TikiWikiClient: Error al crear galería '{$name}' (HTTP {$httpCode})", true);
        return null;
    }

    /**
     * Actualizar las options de un campo FG (File Gallery) en un tracker existente.
     * Usado para asignar galería y configurar count=0 (ilimitado).
     */
    public function updateFgFieldOptions(int $trackerId, int $galleryId, string $excessBehavior = 'discard', ?string $fgPermName = null): bool
    {
        $fgPermName ??= $this->fieldPrefix . 'Media';

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
            log_message("TikiWikiClient: Error HTTP {$httpCode} al obtener fields de tracker {$trackerId}", true);
            return false;
        }

        $data = json_decode($response, true);

        $fieldId = null;
        if (isset($data['fields'])) {
            foreach ($data['fields'] as $field) {
                if (($field['permName'] ?? '') === $fgPermName) {
                    $fieldId = $field['fieldId'] ?? $field['id'] ?? null;
                    break;
                }
            }
        }

        if ($fieldId === null) {
            log_message("TikiWikiClient: No se encontró el campo {$fgPermName} en tracker {$trackerId}", true);
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

        log_message("TikiWikiClient: Error HTTP {$httpCode} al actualizar FG field en tracker {$trackerId}", true);
        return false;
    }

    /**
     * Reparar campo FG de un tracker: crea galería y actualiza options
     * Se llama automáticamente cuando getMediaGalleryId() no encuentra galleryId
     */
    private function repairFgGallery(int $trackerId, ?string $fgPermName = null): ?int
    {
        $fgPermName ??= $this->fieldPrefix . 'Media';
        $trackerName = 'Tracker ' . $trackerId;

        // Crear galería
        $galleryId = $this->createGallery($trackerName . ' Media');
        if ($galleryId === null) {
            log_message("TikiWikiClient: repairFgGallery — no se pudo crear galería para tracker {$trackerId}", true);
            return null;
        }

        // Actualizar el campo FG
        if ($this->updateFgFieldOptions($trackerId, $galleryId, 'discard', $fgPermName)) {
            log_message("TikiWikiClient: repairFgGallery — galería {$galleryId} asignada a tracker {$trackerId}");
            return $galleryId;
        }

        log_message("TikiWikiClient: repairFgGallery — galería creada ({$galleryId}) pero no se pudo actualizar FG field", true);
        return $galleryId; // retornar la galería igual, por si el update falla pero la galería existe
    }

    /**
     * Probar conexión con la API de TikiWiki
     * Intenta acceder al endpoint de trackers y verifica que la API responda.
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $url = $this->apiUrl . 'trackers';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['ok' => false, 'message' => "Error de red: {$curlError}"];
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return ['ok' => false, 'message' => "HTTP {$httpCode} — Token de API inválido o sin permisos"];
        }

        if ($httpCode !== 200) {
            return ['ok' => false, 'message' => "HTTP {$httpCode} — la API de TikiWiki no respondió correctamente"];
        }

        $data = json_decode($response, true);
        if ($data === null) {
            return ['ok' => false, 'message' => 'Respuesta no es JSON válido — verificar que TIKIWIKI_API_URL apunte a /api/'];
        }

        $trackerCount = isset($data['data']) ? count($data['data']) : (isset($data['trackers']) ? count($data['trackers']) : 0);
        return ['ok' => true, 'message' => "API responde correctamente ({$trackerCount} trackers encontrados)"];
    }

    public function createTracker(string $trackerName, string $description = '', string $prefix = 'telegrammessage'): ?int
    {
        // Validar prefix
        $prefix = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $prefix));
        if ($prefix === '' || !ctype_alpha($prefix[0])) {
            log_message("TikiWikiClient: createTracker — prefix inválido '{$prefix}'", true);
            return null;
        }
        if (strlen($prefix) > 16) {
            log_message("TikiWikiClient: createTracker — prefix '{$prefix}' excede 16 caracteres", true);
            return null;
        }

        $url = $this->apiUrl . "trackers";
        $desc = $description ?: 'Tracker automático creado por trackerGram';

        // 1. Crear galería de medios asociada
        $galleryId = $this->createGallery($trackerName . ' Media');

        // 2. Armar definición del campo FG con options si tenemos galería
        $fgPermName = $prefix . 'Media';
        $fgField = [
            'name' => $fgPermName,
            'type' => 'FG',
            'permName' => $fgPermName,
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
            'description' => $desc,
            'fields' => [
                ['name' => $prefix . 'TelegramMessageId', 'type' => 't', 'permName' => $prefix . 'TelegramMessageId'],
                ['name' => $prefix . 'ChatId', 'type' => 't', 'permName' => $prefix . 'ChatId'],
                ['name' => $prefix . 'ChatTitle', 'type' => 't', 'permName' => $prefix . 'ChatTitle'],
                ['name' => $prefix . 'TopicId', 'type' => 't', 'permName' => $prefix . 'TopicId'],
                ['name' => $prefix . 'TopicTitle', 'type' => 't', 'permName' => $prefix . 'TopicTitle'],
                ['name' => $prefix . 'UserId', 'type' => 't', 'permName' => $prefix . 'UserId'],
                ['name' => $prefix . 'Username', 'type' => 't', 'permName' => $prefix . 'Username'],
                ['name' => $prefix . 'FirstName', 'type' => 't', 'permName' => $prefix . 'FirstName'],
                ['name' => $prefix . 'LastName', 'type' => 't', 'permName' => $prefix . 'LastName'],
                ['name' => $prefix . 'DisplayName', 'type' => 't', 'permName' => $prefix . 'DisplayName'],
                ['name' => $prefix . 'MessageType', 'type' => 't', 'permName' => $prefix . 'MessageType'],
                ['name' => $prefix . 'Text', 'type' => 'a', 'permName' => $prefix . 'Text'],
                ['name' => $prefix . 'Location', 'type' => 'G', 'permName' => $prefix . 'Location'],
                ['name' => $prefix . 'MediaType', 'type' => 't', 'permName' => $prefix . 'MediaType'],
                ['name' => $prefix . 'MediaSize', 'type' => 'n', 'permName' => $prefix . 'MediaSize'],
                ['name' => $prefix . 'MediaCaption', 'type' => 't', 'permName' => $prefix . 'MediaCaption'],
                ['name' => $prefix . 'MessageDate', 'type' => 'f', 'permName' => $prefix . 'MessageDate'],
                $fgField,
                ['name' => $prefix . 'MediaUrl', 'type' => 't', 'permName' => $prefix . 'MediaUrl'],
                ['name' => $prefix . 'FileUrl', 'type' => 't', 'permName' => $prefix . 'FileUrl'],
                ['name' => $prefix . 'MediaWidth', 'type' => 'n', 'permName' => $prefix . 'MediaWidth'],
                ['name' => $prefix . 'MediaHeight', 'type' => 'n', 'permName' => $prefix . 'MediaHeight'],
                ['name' => $prefix . 'MediaDuration', 'type' => 'DUR', 'permName' => $prefix . 'MediaDuration'],
                ['name' => $prefix . 'EditedDate', 'type' => 't', 'permName' => $prefix . 'EditedDate'],
                ['name' => $prefix . 'ReplyToId', 'type' => 't', 'permName' => $prefix . 'ReplyToId'],
                ['name' => $prefix . 'Reactions', 'type' => 'a', 'permName' => $prefix . 'Reactions']
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

        if ($httpCode === 200 || $httpCode === 201) {
            $data = json_decode($response, true);
            $trackerId = $data['id'] ?? null;
            if ($trackerId) {
                // 3. Si la galería se creó pero el FG field inline no tomó las options,
                //    hacemos un update explícito
                if ($galleryId !== null) {
                    $this->updateFgFieldOptions((int) $trackerId, $galleryId, 'discard', $fgPermName);
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
