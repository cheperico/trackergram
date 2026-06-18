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
        $knownSuffixes = ['TelegramMessageId', 'ChatId', 'Text', 'MessageDate', 'Media'];

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

        // Nota: HTTP 200 no garantiza que se guardó — la respuesta muestra options viejas.
        // Verificar con GET /api/trackers/{id}/fields después.
        if ($httpCode === 200) {
            log_message("TikiWikiClient: FG field options enviadas en tracker {$trackerId}: galleryId={$galleryId}, count=0");
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

    /**
     * Verificar permisos del token de API de TikiWiki
     * Prueba acceso a API y permisos específicos sin efectos secundarios.
     * @return array{ok: bool, api_access: bool, file_gallery: bool, upload_files: bool, message: string}
     */
    public function checkPermissions(): array
    {
        $basic = $this->testConnection();
        if (!$basic['ok']) {
            return [
                'ok' => false,
                'api_access' => false,
                'file_gallery' => false,
                'upload_files' => false,
                'message' => $basic['message'],
            ];
        }

        // Probar admin_file_galleries: GET /api/galleries devuelve lista
        //   200 → acceso a galerías OK (tiene al menos tiki_p_view_file_gallery)
        //   403 → no tiene permiso
        $hasFileGallery = false;
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
        $galleriesHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $hasFileGallery = ($galleriesHttp === 200);
        if (!$hasFileGallery) {
            log_message("TikiWikiClient: checkPermissions — GET /api/galleries HTTP {$galleriesHttp}", true);
        }

        // Probar upload_files: POST a gallerias/upload con datos mínimos
        //   Si no es 403, tiene permiso de upload (el error es por datos inválidos)
        $hasUpload = false;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . 'galleries/upload');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->token,
            "Content-Type: application/x-www-form-urlencoded",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'galleryId=1');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_exec($ch);
        $uploadHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $hasUpload = ($uploadHttp !== 403);
        if (!$hasUpload) {
            log_message("TikiWikiClient: checkPermissions — POST /galleries/upload HTTP {$uploadHttp} (403=no permiso)", true);
        }

        // Armar mensaje informativo
        $parts = [];
        if ($hasFileGallery) {
            $parts[] = 'admin_file_galleries: OK';
        } else {
            $parts[] = 'admin_file_galleries: FALTA';
        }
        if ($hasUpload) {
            $parts[] = 'upload_files: OK';
        } elseif ($hasFileGallery) {
            $parts[] = 'upload_files: FALTA — no se podrán subir archivos multimedia';
        }

        $message = 'API responde correctamente. ' . implode(' | ', $parts);

        if (!$hasFileGallery) {
            $message .= ' Agregá admin_file_galleries al token desde Admin → Security → API en TikiWiki.';
        } elseif (!$hasUpload) {
            $message .= ' Agregá upload_files al token desde Admin → Security → API en TikiWiki.';
        }

        return [
            'ok' => $hasFileGallery && $hasUpload,
            'api_access' => true,
            'file_gallery' => $hasFileGallery,
            'upload_files' => $hasUpload,
            'message' => $message,
        ];
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

        // 2. Crear tracker SHELL (solo name + description — la API NO soporta fields inline)
        $trackerId = $this->createTrackerShell($trackerName, $desc);
        if ($trackerId === null) {
            return null;
        }

        // 3. Definición de todos los campos a crear
        $fieldDefs = [
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
            ['name' => $prefix . 'Media', 'type' => 'FG', 'permName' => $prefix . 'Media'],
            ['name' => $prefix . 'MediaUrl', 'type' => 't', 'permName' => $prefix . 'MediaUrl'],
            ['name' => $prefix . 'FileUrl', 'type' => 't', 'permName' => $prefix . 'FileUrl'],
            ['name' => $prefix . 'MediaWidth', 'type' => 'n', 'permName' => $prefix . 'MediaWidth'],
            ['name' => $prefix . 'MediaHeight', 'type' => 'n', 'permName' => $prefix . 'MediaHeight'],
            ['name' => $prefix . 'MediaDuration', 'type' => 'DUR', 'permName' => $prefix . 'MediaDuration'],
            ['name' => $prefix . 'EditedDate', 'type' => 't', 'permName' => $prefix . 'EditedDate'],
            ['name' => $prefix . 'ReplyToId', 'type' => 't', 'permName' => $prefix . 'ReplyToId'],
            ['name' => $prefix . 'Reactions', 'type' => 'a', 'permName' => $prefix . 'Reactions'],
        ];

        // 4. Crear cada field individualmente vía POST /api/trackers/{trackerId}/fields
        //    Si algún field falla, abortamos — un tracker incompleto causaría errores difíciles
        $fgPermName = $prefix . 'Media';
        foreach ($fieldDefs as $fd) {
            if (! $this->createTrackerField($trackerId, $fd['name'], $fd['permName'], $fd['type'])) {
                log_message("TikiWikiClient: createTracker — error fatal creando field '{$fd['name']}', abortando", true);
                return null;
            }
        }

        // 5. Si hay galería, actualizar options del campo FG
        if ($galleryId !== null) {
            $this->updateFgFieldOptions($trackerId, $galleryId, 'discard', $fgPermName);
        }

        return $trackerId;
    }

    /**
     * Crea el tracker SHELL (solo nombre + descripción, sin fields)
     */
    private function createTrackerShell(string $name, string $description): ?int
    {
        $url = $this->apiUrl . "trackers";

        // confirm=1 requerido por action_replace.
        // Usamos form-urlencoded porque TikiWiki NO mergea correctamente JSON body a $_POST
        $postFields = http_build_query([
            'name' => $name,
            'description' => $description,
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
    private function createTrackerField(int $trackerId, string $name, string $permName, string $type): bool
    {
        $url = $this->apiUrl . "trackers/{$trackerId}/fields";
        $postFields = http_build_query([
            'name' => $name,
            'permName' => $permName,
            'type' => $type,
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
            log_message("TikiWikiClient: createField '{$name}' HTTP {$httpCode} — response: " . substr($response, 0, 300));
            return false;
        }

        return true;
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
