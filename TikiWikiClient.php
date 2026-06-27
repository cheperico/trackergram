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
    /** Cache de fields del tracker (para evitar doble fetch al detectar prefix y gallery ID) */
    private array $trackerFieldsCache = [];
    /** Cache de prefix resuelto por trackerId */
    private array $resolvedPrefixCache = [];
    /** Trackers where repairFgGallery was already attempted (prevents loops) */
    private array $repairedTrackers = [];

    /**
     * Setear field prefix para todos los permNames (ej: 'qpch', 'soporte')
     */
    public function setFieldPrefix(string $prefix): void
    {
        $this->fieldPrefix = $prefix;
    }

    /**
     * Obtener field prefix resuelto (auto-detectado si es necesario).
     * Si el prefix actual es el default 'telegrammessage', intenta detectarlo
     * desde los fields reales del tracker vía API.
     * Una vez detectado, se guarda en el cache interno.
     */
    public function resolveFieldPrefix(int $trackerId): string
    {
        // Cache por trackerId para esta request
        if (isset($this->resolvedPrefixCache[$trackerId])) {
            return $this->resolvedPrefixCache[$trackerId];
        }

        // Si ya tenemos un prefix custom (no el default), confiarlo
        if ($this->fieldPrefix !== 'telegrammessage') {
            $this->resolvedPrefixCache[$trackerId] = $this->fieldPrefix;
            return $this->fieldPrefix;
        }

        // Cargar fields del tracker (también detecta el prefix)
        $fields = $this->loadTrackerFields($trackerId);

        // Intentar detectar prefix desde los field names
        $detected = $this->detectPrefixFromFieldNames($fields);
        if ($detected !== null) {
            log_message("TikiWikiClient: Field prefix auto-detectado como '{$detected}' para tracker {$trackerId}");
            $this->fieldPrefix = $detected;
            $this->resolvedPrefixCache[$trackerId] = $detected;
            return $detected;
        }

        // Fallback al prefix default
        $this->resolvedPrefixCache[$trackerId] = $this->fieldPrefix;
        return $this->fieldPrefix;
    }

    /**
     * Cargar fields del tracker desde la API y cachearlos internamente.
     * Útil para compartir el fetch entre getMediaGalleryId() y resolveFieldPrefix().
     * @return array Lista de fields
     */
    private function loadTrackerFields(int $trackerId): array
    {
        if (isset($this->trackerFieldsCache[$trackerId])) {
            return $this->trackerFieldsCache[$trackerId];
        }

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

        $fields = [];
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['fields'])) {
                $fields = $data['fields'];
            } else {
                log_message("TikiWikiClient: GET trackers/{$trackerId}/fields sin clave 'fields'. Keys: " . implode(', ', array_keys($data)), true);
            }
        } else {
            log_message("TikiWikiClient: Error HTTP {$httpCode} al obtener fields de tracker {$trackerId}", true);
        }

        $this->trackerFieldsCache[$trackerId] = $fields;
        return $fields;
    }

    /**
     * Detectar field prefix desde los nombres de campo del tracker.
     * Busca campos que terminen en sufijos conocidos (TelegramMessageId, ChatId, Text, etc.)
     * y extrae el prefijo común.
     */
    private function detectPrefixFromFieldNames(array $fields): ?string
    {
        $knownSuffixes = ['TelegramMessageId', 'ChatId', 'Text', 'MessageDate', 'Media', 'Hashtags'];

        foreach ($fields as $field) {
            $permName = $field['permName'] ?? '';
            foreach ($knownSuffixes as $suffix) {
                if (str_ends_with($permName, $suffix)) {
                    $prefix = substr($permName, 0, -strlen($suffix));
                    if ($prefix !== '') {
                        return $prefix;
                    }
                }
            }
        }

        return null;
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

        // Resolver prefix desde los fields del tracker (usa cache si ya se cargaron)
        $prefix = $this->resolveFieldPrefix($trackerId);
        $fields = $this->loadTrackerFields($trackerId);

        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'FG' && ($field['permName'] ?? '') === $prefix . 'Media') {
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

    /**
     * Subir archivo a galería usando base64 (alternativa a multipart).
     * Útil cuando curl_file_create() no está disponible o falla.
     * @see https://doc.tiki.org/API#File_Gallery
     */
    public function uploadFileBase64(string $filePath, string $fileName, ?int $galleryId = null, string $source = 'webhook', string $caption = ''): ?string
    {
        $galleryId ??= $this->getMediaGalleryId();

        if (!file_exists($filePath)) {
            log_message("TikiWikiClient: File not found for base64 upload: $filePath", true);
            return null;
        }

        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            log_message("TikiWikiClient: Error reading file for base64 upload: $filePath", true);
            return null;
        }

        $base64 = base64_encode($fileContent);
        $size = strlen($fileContent);
        $mimeType = $this->getMimeType($filePath);
        $description = 'Subido desde trackerGram ' . $source;
        $description .= ($caption !== '') ? ' | ' . $caption : ' - ' . date('Y-m-d H:i:s');

        $url = $this->apiUrl . "galleries/upload";

        $postFields = http_build_query([
            'galleryId' => $galleryId,
            'name' => $fileName,
            'title' => $fileName,
            'type' => $mimeType,
            'data' => $base64,
            'size' => $size,
            'description' => $description,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "Content-Type: application/x-www-form-urlencoded",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->uploadTimeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log_message("TikiWikiClient: cURL error en uploadFileBase64: $curlError", true);
            return null;
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            log_message("TikiWikiClient: uploadFileBase64 HTTP $httpCode - Response: " . substr($response, 0, 200), true);
            return null;
        }

        $data = json_decode($response, true);
        $fileId = $data['fileId'] ?? $data['file_id'] ?? $data['id'] ?? null;
        if ($fileId) {
            log_message("TikiWikiClient: Archivo subido por base64: fileId={$fileId}, galleryId={$galleryId}, size={$size}");
            return (string) $fileId;
        }

        log_message("TikiWikiClient: uploadFileBase64 respuesta sin fileId - Response: " . substr($response, 0, 200), true);
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
        $prefix = $this->resolveFieldPrefix($trackerId);
        $url = $this->apiUrl . "trackers/$trackerId/items?filter[fields][{$prefix}TelegramMessageId]=$messageId";

        if ($chatId !== null) {
            $url .= "&filter[fields][{$prefix}ChatId]=$chatId";
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
     * Buscar un item en el tracker por (chat_id, message_id) de Telegram.
     * Similar a messageExists() pero devuelve el itemId real si existe.
     * @param int $trackerId ID del tracker
     * @param int $messageId Telegram message_id
     * @param int $chatId Telegram chat_id
     * @return int|null El itemId del tracker, o null si no existe
     */
    public function findItemByMessageId(int $trackerId, int $messageId, int $chatId): ?int
    {
        $prefix = $this->resolveFieldPrefix($trackerId);
        $url = $this->apiUrl . "trackers/$trackerId/items"
            . "?filter[fields][{$prefix}TelegramMessageId]=" . urlencode((string) $messageId)
            . "&filter[fields][{$prefix}ChatId]=" . urlencode((string) $chatId)
            . "&maxRecords=1";

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
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        // La API puede devolver los items en 'data' o 'result'
        $items = $data['data'] ?? $data['result'] ?? [];
        if (empty($items) || !is_array($items)) {
            return null;
        }

        $first = reset($items);
        return isset($first['itemId']) ? (int) $first['itemId'] : null;
    }

    /**
     * Obtener un item completo del tracker por su itemId interno.
     * Devuelve el primer item del array 'data' con todos sus field values.
     * @param int $trackerId ID del tracker
     * @param int $itemId ID interno del item en TikiWiki
     * @return array|null Item con fields, o null si no existe
     */
    public function getTrackerItem(int $trackerId, int $itemId): ?array
    {
        $url = $this->apiUrl . "trackers/$trackerId/items?itemId=" . urlencode((string) $itemId) . "&maxRecords=1";

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
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        $items = $data['data'] ?? $data['result'] ?? [];
        if (empty($items) || !is_array($items)) {
            return null;
        }

        return reset($items);
    }

    /**
     * Crear una file gallery en TikiWiki
     */
    public function createGallery(string $name, string $description = ''): ?int
    {
        $url = $this->apiUrl . "galleries";

        // Intentar con parentId=0 primero, fallback a parentId=1 (root gallery)
        foreach ([0, 1] as $parentId) {
            // Usamos form-urlencoded porque TikiWiki NO mergea correctamente JSON body
            $postFields = http_build_query([
                'name' => $name,
                'description' => $description ?: 'Galería creada por trackerGram',
                'parentId' => $parentId,
            ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
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

            if ($httpCode === 200 || $httpCode === 201) {
                $data = json_decode($response, true);
                // La respuesta envuelve galleryId dentro de 'info'
                $galleryId = $data['info']['galleryId'] ?? $data['galleryId'] ?? $data['id'] ?? null;
                if ($galleryId) {
                    log_message("TikiWikiClient: Galería '{$name}' creada con ID {$galleryId} (parentId={$parentId})");
                    return (int) $galleryId;
                }
                log_message("TikiWikiClient: createGallery respuesta inesperada — " . substr($response, 0, 300));
            }

            // Si no es 403, no tiene sentido probar con otro parentId
            if ($httpCode !== 403) {
                break;
            }
        }

        log_message("TikiWikiClient: Error al crear galería '{$name}'. " .
            "Verificá que el usuario del token API tenga permiso 'admin_file_galleries' " .
            "y creá una galería manualmente desde el panel de TikiWiki.", true);
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
        $fieldName = null;
        if (isset($data['fields'])) {
            foreach ($data['fields'] as $field) {
                if (($field['permName'] ?? '') === $fgPermName) {
                    $fieldId = $field['fieldId'] ?? $field['id'] ?? null;
                    $fieldName = $field['name'] ?? $fgPermName;
                    break;
                }
            }
        }

        if ($fieldId === null) {
            log_message("TikiWikiClient: No se encontró el campo {$fgPermName} en tracker {$trackerId}", true);
            return false;
        }

        // Actualizar las options del field
        // IMPORTANTE: action_edit_field requiere 'name' en el POST, 
        // sin name el bloque de guardado se salta silenciosamente.
        // La respuesta HTTP 200 muestra options VIEJAS (bug de TikiWiki) - 
        // verificar con GET /fields después.
        $updateUrl = $this->apiUrl . "trackers/{$trackerId}/fields/{$fieldId}";
        $postData = http_build_query([
            'name' => $fieldName,
            'type' => 'FG',
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

        if ($httpCode !== 200) {
            log_message("TikiWikiClient: Error HTTP {$httpCode} al actualizar FG field en tracker {$trackerId}", true);
            return false;
        }

        log_message("TikiWikiClient: FG field options enviadas en tracker {$trackerId}: galleryId={$galleryId}, count=0");

        // ── VERIFICACIÓN: leer el campo de vuelta para confirmar que se guardó ──
        // TikiWiki action_edit_field responde HTTP 200 aunque falle (falta de name, etc.)
        // y la respuesta siempre muestra options VIEJAS. La única forma de saber si
        // realmente se guardó es hacer GET /fields y verificar.
        unset($this->trackerFieldsCache[$trackerId]); // forzar recarga fresca
        $fields = $this->loadTrackerFields($trackerId);

        $savedGalleryId = null;
        foreach ($fields as $field) {
            if (($field['permName'] ?? '') === $fgPermName) {
                $savedGalleryId = $this->extractGalleryIdFromOptions($field['options'] ?? null);
                break;
            }
        }

        if ($savedGalleryId === $galleryId) {
            log_message("TikiWikiClient: FG field VERIFICADO — galleryId={$galleryId} confirmado en tracker {$trackerId}");
            // Actualizar cache de gallery ID para evitar re-fetch
            $this->mediaGalleryIdCache[$trackerId] = $galleryId;
            return true;
        }

        // La verificación falló — el POST no guardó realmente
        $savedStr = $savedGalleryId ?? 'null';
        log_message("TikiWikiClient: FG field NO VERIFICADO — se esperaba galleryId={$galleryId} pero se encontró {$savedStr} en tracker {$trackerId}. " .
            "Bug conocido de TikiWiki: action_edit_field responde HTTP 200 aunque falle.", true);
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
    /**
     * Obtener la versión de TikiWiki vía API.
     * Llama a GET /api/version con el token de autenticación.
     * @return string|null Versión (ej: "27.5") o null si falla
     */
    public function getVersion(): ?string
    {
        $url = $this->apiUrl . 'version';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            log_message("getVersion: GET /api/version HTTP {$httpCode}" . ($curlError ? " — cURL error: {$curlError}" : ""));
            return null;
        }

        $data = json_decode($response, true);
        if ($data === null) {
            log_message("getVersion: respuesta no es JSON válido: " . substr($response, 0, 200));
            return null;
        }
        return $data['version'] ?? null;
    }

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

    /**
     * Verificar permisos del token de API de TikiWiki
     * Prueba los 6 permisos que trackerGram necesita para operar.
     * @param int|null $trackerId ID del tracker para probar admin_trackers y create_tracker_items
     * @return array{ok: bool, api_access: bool, view_trackers: bool, admin_trackers: ?bool, create_tracker_items: ?bool, view_file_gallery: bool, upload_files: bool, admin_file_galleries: bool, file_gallery: bool, message: string}
     */
    public function checkPermissions(?int $trackerId = null): array
    {
        $result = [
            'ok' => false,
            'api_access' => false,
            'view_trackers' => false,
            'admin_trackers' => null,   // null = no testeado (sin tracker ID)
            'create_tracker_items' => null, // null = no testeado (sin tracker ID)
            'view_file_gallery' => false,
            'upload_files' => false,
            'admin_file_galleries' => false,
            'file_gallery' => false, // backward compat
            'message' => '',
        ];

        // 1. Acceso básico a la API (GET /api/trackers)
        $basic = $this->testConnection();
        if (!$basic['ok']) {
            $result['message'] = $basic['message'];
            return $result;
        }
        $result['api_access'] = true;
        $result['view_trackers'] = true;
        $parts = ['API: OK'];

        // 2. admin_trackers global (GET /api/trackers/{trackerId}) — CRÍTICO
        //    La ruta correcta es /api/trackers/{id} (sin /items). Ver ApiBridge.php línea 137.
        if ($trackerId > 0) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->apiUrl . "trackers/$trackerId?maxRecords=1");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $this->token,
                "Accept: application/json",
                "User-Agent: Mozilla/5.0"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_exec($ch);
            $admHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result['admin_trackers'] = ($admHttp === 200);
            $parts[] = 'admin_trackers: ' . ($result['admin_trackers'] ? 'OK' : "FALTA (HTTP {$admHttp})");
            if (!$result['admin_trackers']) {
                log_message("TikiWikiClient: checkPermissions — GET /api/trackers/{$trackerId} HTTP {$admHttp} (needs admin_trackers global)", true);
            }
        } else {
            $parts[] = 'admin_trackers: no testeado (sin tracker ID)';
        }

        // 3. create_tracker_items (POST /api/trackers/{id}/items) — CRÍTICO
        if ($trackerId > 0) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->apiUrl . "trackers/$trackerId/items");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $this->token,
                "Accept: application/json",
                "User-Agent: Mozilla/5.0"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_exec($ch);
            $createHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result['create_tracker_items'] = ($createHttp !== 403);
            $parts[] = 'create_tracker_items: ' . ($result['create_tracker_items'] ? 'OK' : "FALTA (HTTP {$createHttp})");
            if (!$result['create_tracker_items']) {
                log_message("TikiWikiClient: checkPermissions — POST /trackers/{$trackerId}/items HTTP {$createHttp} (needs create_tracker_items)", true);
            }
        } else {
            $parts[] = 'create_tracker_items: no testeado (sin tracker ID)';
        }

        // 4. view_file_gallery (GET /api/galleries)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . 'galleries');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "Accept: application/json",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_exec($ch);
        $galHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result['view_file_gallery'] = ($galHttp === 200);
        $result['file_gallery'] = $result['view_file_gallery']; // backward compat
        $parts[] = 'view_file_gallery: ' . ($result['view_file_gallery'] ? 'OK' : "FALTA (HTTP {$galHttp})");
        if (!$result['view_file_gallery']) {
            log_message("TikiWikiClient: checkPermissions — GET /api/galleries HTTP {$galHttp} (needs view_file_gallery)", true);
        }

        // 5. upload_files (POST /api/galleries/upload con galleryId inválido)
        //    Verificamos que NO dé 403 (si da 403, falta upload_files)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . 'galleries/upload');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'galleryId=1');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: application/json",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_exec($ch);
        $upHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result['upload_files'] = ($upHttp !== 403);
        $parts[] = 'upload_files: ' . ($result['upload_files'] ? 'OK' : "FALTA (HTTP {$upHttp})");
        if (!$result['upload_files']) {
            log_message("TikiWikiClient: checkPermissions — POST /galleries/upload HTTP {$upHttp} (403=no upload_files)", true);
        }

        // 6. admin_file_galleries (DELETE /api/galleries/99999999/delete)
        //    Envía DELETE a una galería inexistente. Si tenemos admin_file_galleries,
        //    la API responde HTTP 200 (la galería no existe pero el permiso es válido).
        //    Si NO tenemos el permiso, responde 403. Sin efectos secundarios.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . 'galleries/99999999/delete');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "Accept: application/json",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_exec($ch);
        $crGalHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result['admin_file_galleries'] = ($crGalHttp !== 403);
        $parts[] = 'admin_file_galleries: ' . ($result['admin_file_galleries'] ? 'OK' : 'FALTA (HTTP 403)');
        if ($crGalHttp === 403) {
            log_message("TikiWikiClient: checkPermissions — DELETE /galleries/99999999/delete HTTP 403 (no admin_file_galleries)", true);
        }

        // Armar resultado
        $result['message'] = implode(' | ', $parts);

        // Mensajes de ayuda si falta algo
        $hints = [];
        if ($trackerId > 0 && !$result['admin_trackers']) {
            $hints[] = 'tiki_p_admin_trackers debe ser GLOBAL (Admin → Grupos → trackerGram → Permisos)';
        }
        if ($trackerId > 0 && !$result['create_tracker_items']) {
            $hints[] = 'tiki_p_create_tracker_items necesario para crear mensajes';
        }
        if (!$result['view_file_gallery']) {
            $hints[] = 'tiki_p_view_file_gallery necesario';
        }
        if ($result['view_file_gallery'] && !$result['upload_files']) {
            $hints[] = 'tiki_p_upload_files necesario para subir multimedia';
        }
        if ($result['view_file_gallery'] && !$result['admin_file_galleries']) {
            $hints[] = 'tiki_p_admin_file_galleries permite auto-reparación de galerías';
        }
        if ($hints) {
            $result['message'] .= ' | ⚠️ ' . implode(' | ', $hints);
        }

        // OK global: críticos presentes
        if ($trackerId > 0) {
            $result['ok'] = $result['admin_trackers'] && $result['create_tracker_items'] && $result['upload_files'];
        } else {
            $result['ok'] = $result['view_file_gallery']; // parcial sin tracker ID
        }

        return $result;
    }

    public function createTracker(string $trackerName, string $description = '', string $prefix = 'telegrammessage', ?int $galleryId = null): ?int
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

        $desc = $description ?: 'Tracker automático creado por trackerGram';

        // 1. Obtener galería de medios: usar la proporcionada o intentar crear una
        if ($galleryId === null) {
            $galleryId = $this->createGallery($trackerName . ' Media');
            if ($galleryId === null) {
                log_message("TikiWikiClient: No se pudo crear galería — el tracker se creará sin campo FG vinculado. " .
                    "El usuario puede asignar una galería manualmente desde el panel de TikiWiki.");
            }
        } else {
            log_message("TikiWikiClient: Usando galería existente ID {$galleryId} para el tracker");
        }

        // 2. Crear tracker SHELL (name + description + fieldPrefix — la API NO soporta fields inline)
        $trackerId = $this->createTrackerShell($trackerName, $desc, $prefix);
        if ($trackerId === null) {
            return null;
        }

        // 3. Definición de todos los campos a crear (source of truth compartido con sync)
        $fieldDefs = $this->getTrackerFieldDefinitions($prefix);

        // 4. Crear cada field individualmente vía POST /api/trackers/{trackerId}/fields
        //    Si algún field falla, abortamos — un tracker incompleto causaría errores difíciles
        $fgPermName = $prefix . 'Media';
        foreach ($fieldDefs as $fd) {
            if (! $this->createTrackerField($trackerId, $fd['name'], $fd['permName'], $fd['type'], $fd['description'] ?? '')) {
                log_message("TikiWikiClient: createTracker — error fatal creando field '{$fd['name']}', abortando", true);
                return null;
            }
        }

        // 5. Si hay galería, actualizar options del campo FG y verificar
        if ($galleryId !== null) {
            if (! $this->updateFgFieldOptions($trackerId, $galleryId, 'discard', $fgPermName)) {
                log_message("TikiWikiClient: createTracker — galería {$galleryId} NO se pudo asignar al tracker {$trackerId}. " .
                    "El tracker se creó pero la galería de medios deberá asignarse manualmente desde TikiWiki.", true);
            }
        }

        return $trackerId;
    }

    /**
     * Crea el tracker SHELL (solo nombre + descripción, sin fields)
     */
    private function createTrackerShell(string $name, string $description, string $fieldPrefix = ''): ?int
    {
        $url = $this->apiUrl . "trackers";

        // confirm=1 requerido por action_replace.
        // Usamos form-urlencoded porque TikiWiki NO mergea correctamente JSON body a $_POST
        $postData = [
            'name' => $name,
            'description' => $description,
            'confirm' => 1,
        ];
        // Si hay fieldPrefix, enviarlo para que TikiWiki lo guarde en tiki_tracker_options
        // (nativo de TikiWiki — usado al auto-generar permNames de nuevos campos)
        if ($fieldPrefix !== '') {
            $postData['fieldPrefix'] = $fieldPrefix;
        }
        $postFields = http_build_query($postData);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
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

        log_message("TikiWikiClient: createTrackerShell HTTP {$httpCode} — response: " . substr($response, 0, 300));

        if ($httpCode === 200 || $httpCode === 201) {
            $data = json_decode($response, true);
            // action_replace devuelve trackerId en el root del response
            $trackerId = $data['trackerId'] ?? $data['tracker_id'] ?? $data['id'] ?? null;
            if ($trackerId === null) {
                log_message("TikiWikiClient: createTrackerShell — respuesta sin trackerId. Keys: " .
                    implode(', ', array_keys($data ?? [])), true);
            }
            return $trackerId;
        }

        return null;
    }

    /**
     * Crea un field individual en un tracker vía POST /api/trackers/{trackerId}/fields
     */
    private function createTrackerField(int $trackerId, string $name, string $permName, string $type, string $description = ''): bool
    {
        $url = $this->apiUrl . "trackers/{$trackerId}/fields";
        $postData = [
            'name' => $name,
            'permName' => $permName,
            'type' => $type,
        ];
        if ($description !== '') {
            $postData['description'] = $description;
        }
        $postFields = http_build_query($postData);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
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

        if ($httpCode !== 200 && $httpCode !== 201) {
            log_message("TikiWikiClient: createField '{$name}' HTTP {$httpCode} — response: " . substr($response, 0, 300));
            return false;
        }

        return true;
    }

    /**
     * Obtener información de un tracker (nombre, descripción, etc.) vía API.
     * @return array|null Datos del tracker o null si no se encontró
     */
    public function getTrackerInfo(int $trackerId): ?array
    {
        $url = $this->apiUrl . "trackers/{$trackerId}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
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
            return null;
        }

        return json_decode($response, true) ?: null;
    }

    /**
     * Actualizar el fieldPrefix de un tracker existente vía API (POST /api/trackers con trackerId).
     * TikiWiki guarda fieldPrefix como opción del tracker en tiki_tracker_options.
     */
    public function setTrackerFieldPrefix(int $trackerId, string $prefix): bool
    {
        // Necesitamos el nombre actual del tracker (requerido por action_replace)
        $info = $this->getTrackerInfo($trackerId);
        if ($info === null || empty($info['name'])) {
            log_message("TikiWikiClient: setTrackerFieldPrefix — no se pudo obtener info del tracker {$trackerId}", true);
            return false;
        }

        $url = $this->apiUrl . "trackers";
        $postFields = http_build_query([
            'trackerId' => $trackerId,
            'name' => $info['name'],
            'fieldPrefix' => $prefix,
            'confirm' => 1,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
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

        if ($httpCode !== 200 && $httpCode !== 201) {
            log_message("TikiWikiClient: setTrackerFieldPrefix HTTP {$httpCode} — response: " . substr($response, 0, 300));
            return false;
        }

        log_message("TikiWikiClient: fieldPrefix '{$prefix}' seteado en tracker {$trackerId}");
        return true;
    }

    /**
     * Devuelve la definición de todos los campos del tracker.
     * Es el source of truth único — usado por createTracker() y synchronizeTrackerFields().
     * @return array Lista de arrays con keys: name, permName, type, description
     */
    public function getTrackerFieldDefinitions(string $prefix): array
    {
        return [
            ['name' => $prefix . 'TelegramMessageId', 'type' => 't', 'permName' => $prefix . 'TelegramMessageId', 'description' => 'ID único del mensaje en Telegram'],
            ['name' => $prefix . 'ChatId', 'type' => 't', 'permName' => $prefix . 'ChatId', 'description' => 'ID del chat/grupo en Telegram'],
            ['name' => $prefix . 'ChatTitle', 'type' => 't', 'permName' => $prefix . 'ChatTitle', 'description' => 'Título del chat o grupo'],
            ['name' => $prefix . 'TopicId', 'type' => 't', 'permName' => $prefix . 'TopicId', 'description' => 'ID del tema o foro (0 si es General)'],
            ['name' => $prefix . 'TopicTitle', 'type' => 't', 'permName' => $prefix . 'TopicTitle', 'description' => 'Nombre del tema o foro'],
            ['name' => $prefix . 'UserId', 'type' => 't', 'permName' => $prefix . 'UserId', 'description' => 'ID numérico del usuario que envió el mensaje'],
            ['name' => $prefix . 'Username', 'type' => 't', 'permName' => $prefix . 'Username', 'description' => '@username del usuario en Telegram'],
            ['name' => $prefix . 'FirstName', 'type' => 't', 'permName' => $prefix . 'FirstName', 'description' => 'Nombre del usuario (en import: display name completo)'],
            ['name' => $prefix . 'LastName', 'type' => 't', 'permName' => $prefix . 'LastName', 'description' => 'Apellido del usuario (solo disponible en webhook)'],
            ['name' => $prefix . 'DisplayName', 'type' => 't', 'permName' => $prefix . 'DisplayName', 'description' => 'Nombre completo para mostrar (unificado webhook e import)'],
            ['name' => $prefix . 'MessageType', 'type' => 't', 'permName' => $prefix . 'MessageType', 'description' => 'Tipo de mensaje: text, photo, video, audio, document, sticker, voice, system, etc.'],
            ['name' => $prefix . 'Text', 'type' => 'a', 'permName' => $prefix . 'Text', 'description' => 'Contenido textual del mensaje (incluye captions de media)'],
            ['name' => $prefix . 'Location', 'type' => 'G', 'permName' => $prefix . 'Location', 'description' => 'Coordenadas GPS del mensaje (formato: lon, lat, zoom)'],
            ['name' => $prefix . 'MediaType', 'type' => 't', 'permName' => $prefix . 'MediaType', 'description' => 'Tipo MIME del archivo adjunto (ej: image/jpeg, video/mp4)'],
            ['name' => $prefix . 'MediaSize', 'type' => 'n', 'permName' => $prefix . 'MediaSize', 'description' => 'Tamaño del archivo adjunto en bytes'],
            ['name' => $prefix . 'MediaCaption', 'type' => 't', 'permName' => $prefix . 'MediaCaption', 'description' => 'Texto de descripción asociado al archivo multimedia'],
            ['name' => $prefix . 'MessageDate', 'type' => 'f', 'permName' => $prefix . 'MessageDate', 'description' => 'Fecha/hora del mensaje (timestamp UNIX)'],
            ['name' => $prefix . 'Media', 'type' => 'FG', 'permName' => $prefix . 'Media', 'description' => 'Archivo multimedia adjunto (referencia a File Gallery de TikiWiki)'],
            ['name' => $prefix . 'MediaUrl', 'type' => 't', 'permName' => $prefix . 'MediaUrl', 'description' => 'URL pública del archivo multimedia en TikiWiki'],
            ['name' => $prefix . 'FileUrl', 'type' => 't', 'permName' => $prefix . 'FileUrl', 'description' => 'URL original del archivo en los servidores de Telegram'],
            ['name' => $prefix . 'MediaWidth', 'type' => 'n', 'permName' => $prefix . 'MediaWidth', 'description' => 'Ancho de la imagen/video en píxeles'],
            ['name' => $prefix . 'MediaHeight', 'type' => 'n', 'permName' => $prefix . 'MediaHeight', 'description' => 'Alto de la imagen/video en píxeles'],
            ['name' => $prefix . 'MediaDuration', 'type' => 'DUR', 'permName' => $prefix . 'MediaDuration', 'description' => 'Duración del audio/video/voice en segundos (se muestra como hh:mm:ss)'],
            ['name' => $prefix . 'EditedDate', 'type' => 't', 'permName' => $prefix . 'EditedDate', 'description' => 'Fecha de última edición (timestamp UNIX, vacío si no fue editado)'],
            ['name' => $prefix . 'ReplyToId', 'type' => 't', 'permName' => $prefix . 'ReplyToId', 'description' => 'ID del mensaje al que responde (para conversaciones en hilo)'],
            ['name' => $prefix . 'Reactions', 'type' => 'a', 'permName' => $prefix . 'Reactions', 'description' => 'Reacciones al mensaje formateadas como texto (ej: 👍 3 · ❤️ 1)'],
            ['name' => $prefix . 'Hashtags', 'type' => 'F', 'permName' => $prefix . 'Hashtags', 'description' => 'Hashtags de Telegram como etiquetas (espacio-separados, sin #)'],
        ];
    }

    /**
     * Obtener los permNames actuales de los campos del tracker.
     */
    public function getExistingFieldPermNames(int $trackerId): array
    {
        $fields = $this->loadTrackerFields($trackerId);
        $names = [];
        foreach ($fields as $f) {
            if (!empty($f['permName'])) {
                $names[] = $f['permName'];
            }
        }
        return $names;
    }

    /**
     * Sincronizar los campos del tracker con los esperados por trackerGram.
     * Crea los campos faltantes (no modifica ni elimina existentes).
     * Además, asegura que el fieldPrefix esté configurado en el tracker.
     *
     * @param int    $trackerId ID del tracker en TikiWiki
     * @param string $prefix    Field prefix a usar (ej: telegrammessage, qpch, soporte)
     * @return array Con keys: 'created' (lista de campos creados), 'existing' (lista de campos existentes), 'prefix_used' (prefix efectivo usado), 'prefix_set' (bool: se configuró el fieldPrefix en el tracker?)
     */
    public function synchronizeTrackerFields(int $trackerId, string $prefix): array
    {
        $existingPermNames = $this->getExistingFieldPermNames($trackerId);
        $expectedDefs = $this->getTrackerFieldDefinitions($prefix);
        $created = [];

        foreach ($expectedDefs as $fd) {
            if (!in_array($fd['permName'], $existingPermNames, true)) {
                if ($this->createTrackerField($trackerId, $fd['name'], $fd['permName'], $fd['type'], $fd['description'] ?? '')) {
                    $created[] = $fd['permName'];
                    log_message("TikiWikiClient: sync — campo creado '{$fd['permName']}' en tracker {$trackerId}");
                } else {
                    log_message("TikiWikiClient: sync — ERROR creando campo '{$fd['permName']}' en tracker {$trackerId}", true);
                }
            }
        }

        // También asegurar que el tracker tenga configurado su fieldPrefix nativo
        $prefixSet = $this->setTrackerFieldPrefix($trackerId, $prefix);

        return [
            'created' => $created,
            'existing' => $existingPermNames,
            'prefix_used' => $prefix,
            'prefix_set' => $prefixSet,
        ];
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
