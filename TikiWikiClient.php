<?php
/**
 * TikiWikiClient - Cliente para comunicarse con la API de TikiWiki
 * Encapsula toda la lógica de comunicación con TikiWiki: trackers, galleries, items
 */

require_once 'config.php';

class TikiWikiClient
{
    private static array $mediaGalleryIdCache = [];

    /**
     * Obtener el ID de la galería de medios del tracker configurado
     */
    public static function getMediaGalleryId(?int $trackerId = null): ?int
    {
        $trackerId = $trackerId ?? TIKIWIKI_TRACKER_ID;

        if (isset(self::$mediaGalleryIdCache[$trackerId])) {
            return self::$mediaGalleryIdCache[$trackerId];
        }

        $url = TIKIWIKI_API_URL . "trackers/$trackerId";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . TIKIWIKI_TOKEN,
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TIKIWIKI_API);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['configuration']['fieldDefinitions'])) {
                foreach ($data['configuration']['fieldDefinitions'] as $field) {
                    if (($field['name'] ?? '') === 'telegrammessageMedia' && ($field['type'] ?? '') === 'FG') {
                        $options = $field['options'] ?? [];
                        foreach ($options as $opt) {
                            if (isset($opt['value'])) {
                                self::$mediaGalleryIdCache[$trackerId] = (int) $opt['value'];
                                return self::$mediaGalleryIdCache[$trackerId];
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Establecer cache de gallery ID para un tracker (para testing)
     */
    public static function setMediaGalleryId(?int $galleryId, ?int $trackerId = null): void
    {
        $trackerId = $trackerId ?? TIKIWIKI_TRACKER_ID;
        self::$mediaGalleryIdCache[$trackerId] = $galleryId;
    }

    /**
     * Subir archivo a TikiWiki file gallery
     */
    public static function uploadFile(string $filePath, string $fileName, ?int $galleryId = null): ?string
    {
        $galleryId = $galleryId ?? self::getMediaGalleryId() ?? 29;

        $url = TIKIWIKI_API_URL . "galleries/upload";

        if (!file_exists($filePath)) {
            log_message("TikiWikiClient: File not found for upload: $filePath");
            return null;
        }

        $mimeType = self::getMimeType($filePath);

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
            "Authorization: Bearer " . TIKIWIKI_TOKEN,
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TIKIWIKI_API);

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

    /**
     * Crear item en TikiWiki tracker
     * @param int $trackerId ID del tracker
     * @param array $postFields Campos formateados como fields[permName] => valor (listo para http_build_query)
     */
    public static function createTrackerItem(int $trackerId, array $postFields): bool
    {
        $url = TIKIWIKI_API_URL . "trackers/$trackerId/items";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . TIKIWIKI_TOKEN,
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TIKIWIKI_API);

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

        // Validar respuesta JSON con itemId (detecta errores PHP que devuelven 200)
        $responseData = json_decode($response, true);
        if (!$responseData || !isset($responseData['itemId'])) {
            $clean = str_replace(["\r", "\n"], ' ', strip_tags(substr($response, 0, 300)));
            log_message("TikiWikiClient: Respuesta inválida (Status $httpCode): $clean");
            return false;
        }

        log_message("TikiWikiClient: Item creado - itemId={$responseData['itemId']}");
        return true;
    }

    /**
     * Verificar si un mensaje ya existe en el tracker (deduplicación)
     */
    public static function messageExists(int $trackerId, int $messageId, ?int $chatId = null): int
    {
        $url = TIKIWIKI_API_URL . "trackers/$trackerId/items?filter[fields][telegrammessageTelegramMessageId]=$messageId";
        
        // Si tenemos chat_id, filtrar también por chat para mayor precisión
        if ($chatId !== null) {
            $url .= "&filter[fields][telegrammessageChatId]=$chatId";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . TIKIWIKI_TOKEN,
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TIKIWIKI_API);

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
     * Crear tracker automáticamente con campos
     */
    public static function createTracker(string $trackerName): ?int
    {
        $url = TIKIWIKI_API_URL . "trackers";

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
                ['name' => 'telegrammessageMessageType', 'type' => 't', 'permName' => 'telegrammessageMessageType'],
                ['name' => 'telegrammessageText', 'type' => 'a', 'permName' => 'telegrammessageText'],
                ['name' => 'telegrammessageLocation', 'type' => 'G', 'permName' => 'telegrammessageLocation'],
                ['name' => 'telegrammessageMediaType', 'type' => 't', 'permName' => 'telegrammessageMediaType'],
                ['name' => 'telegrammessageMediaSize', 'type' => 't', 'permName' => 'telegrammessageMediaSize'],
                ['name' => 'telegrammessageMediaCaption', 'type' => 't', 'permName' => 'telegrammessageMediaCaption'],
                ['name' => 'telegrammessageMessageDate', 'type' => 't', 'permName' => 'telegrammessageMessageDate'],
                ['name' => 'telegrammessageMedia', 'type' => 'FG', 'permName' => 'telegrammessageMedia']
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . TIKIWIKI_TOKEN,
            "Content-Type: application/json",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TIKIWIKI_API);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['id'] ?? null;
        }

        return null;
    }

    /**
     * Obtener tipo MIME de un archivo
     */
    private static function getMimeType(string $filePath): string
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