<?php
/**
 * TikiWikiClient - Cliente para comunicarse con la API de TikiWiki
 * Encapsula toda la lógica de comunicación con TikiWiki: trackers, galleries, items
 */

require_once 'config.php';

class TikiWikiClient
{
    private static ?int $mediaGalleryIdCache = null;

    /**
     * Obtener el ID de la galería de medios del tracker configurado
     */
    public static function getMediaGalleryId(?int $trackerId = null): ?int
    {
        if (self::$mediaGalleryIdCache !== null) {
            return self::$mediaGalleryIdCache;
        }

        $trackerId = $trackerId ?? TIKIWIKI_TRACKER_ID;
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
                                self::$mediaGalleryIdCache = (int) $opt['value'];
                                return self::$mediaGalleryIdCache;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Establecer cache de gallery ID (para testing)
     */
    public static function setMediaGalleryId(?int $galleryId): void
    {
        self::$mediaGalleryIdCache = $galleryId;
    }

    /**
     * Subir archivo a TikiWiki file gallery
     */
    public static function uploadFile(string $filePath, string $fileName, ?int $galleryId = null): ?string
    {
        $galleryId = $galleryId ?? self::getMediaGalleryId() ?? 29;

        $url = TIKIWIKI_API_URL . "filegals/$galleryId/files";

        if (!file_exists($filePath)) {
            return null;
        }

        $mimeType = self::getMimeType($filePath);

        $postFields = [
            'file' => curl_file_create($filePath, $mimeType, $fileName),
            'name' => $fileName,
            'galleryId' => $galleryId
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
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['id'] ?? null;
        }

        return null;
    }

    /**
     * Crear item en TikiWiki tracker
     */
    public static function createTrackerItem(int $trackerId, array $fields): bool
    {
        $url = TIKIWIKI_API_URL . "trackers/$trackerId/items";

        $postFields = [];
        foreach ($fields as $key => $value) {
            $postFields["fields[$key]"] = $value;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . TIKIWIKI_TOKEN,
            "User-Agent: Mozilla/5.0"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TIKIWIKI_API);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 && DEBUG_MODE) {
            error_log("TikiWikiClient: Error creando item - HTTP $httpCode, response: $response, error: $error");
        }

        return $httpCode === 200;
    }

    /**
     * Verificar si un mensaje ya existe en el tracker (deduplicación)
     */
    public static function messageExists(int $trackerId, int $messageId, ?int $chatId = null): bool
    {
        $url = TIKIWIKI_API_URL . "trackers/$trackerId/items?filter[fields][telegrammessageTelegramMessageId]=$messageId";

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
            return !empty($data['data'] ?? []);
        }

        return false;
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
                ['name' => 'telegrammessageMediaCaption', 'type' => 't', 'permName' => 'telegrammessageMediaCaption'],
                ['name' => 'telegrammessageMessageDate', 'type' => 't', 'permName' => 'telegrammessageMessageDate'],
                ['name' => 'telegrammessageMedia', 'type' => 't', 'permName' => 'telegrammessageMedia']
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