<?php
/**
 * TelegramClient - Cliente para comunicarse con la API de Telegram
 * Encapsula la lógica de descarga de archivos, obtención de información de chats, etc.
 */

require_once 'config.php';

class TelegramClient
{
    private static string $baseUrl = 'https://api.telegram.org';

    /**
     * Obtener URL de descarga de un archivo de Telegram
     */
    public static function getFileUrl(string $fileId): ?string
    {
        $apiUrl = self::$baseUrl . '/bot' . TELEGRAM_BOT_TOKEN . '/getFile';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl . '?file_id=' . urlencode($fileId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TELEGRAM_API);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['result']['file_path'])) {
            return self::$baseUrl . '/file/bot' . TELEGRAM_BOT_TOKEN . '/' . $data['result']['file_path'];
        }

        return null;
    }

    /**
     * Obtener información de un chat (para nombre del topic)
     */
    public static function getChat(int $chatId): ?array
    {
        $url = self::$baseUrl . '/bot' . TELEGRAM_BOT_TOKEN . '/getChat?chat_id=' . $chatId;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TELEGRAM_API);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['result'])) {
            return $data['result'];
        }

        return null;
    }

    /**
     * Descargar archivo de Telegram y guardarlo localmente
     */
    public static function downloadFile(string $fileUrl, string $destinationPath): bool
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TELEGRAM_DOWNLOAD);

        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $fileContent !== false) {
            return file_put_contents($destinationPath, $fileContent) !== false;
        }

        return false;
    }

    /**
     * Descargar archivo por file_id y retornarlo como string (para upload a Tiki)
     */
    public static function getFileContent(string $fileId): ?string
    {
        $fileUrl = self::getFileUrl($fileId);
        if (!$fileUrl) {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_TELEGRAM_DOWNLOAD);

        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return $fileContent;
        }

        return null;
    }

    /**
     * Responder a Telegram (ack)
     */
    public static function respondOk(): void
    {
        http_response_code(200);
        echo json_encode(['ok' => true]);
    }

    /**
     * Responder con error a Telegram
     */
    public static function respondError(string $message, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $message]);
    }

    /**
     * Obtener nombre del topic/thread desde el mensaje de creación
     */
    public static function getTopicNameFromMessage(array $message, int $chatId): string
    {
        // Verificar si es un mensaje de tipo service que crea topic
        if (($message['type'] ?? '') === 'service' && ($message['action'] ?? '') === 'topic_created') {
            return $message['title'] ?? 'Topic ' . ($message['id'] ?? '');
        }

        // Verificar reply_to_message para forum_topic_created
        if (isset($message['reply_to_message']['forum_topic_created'])) {
            return $message['reply_to_message']['forum_topic_created']['title'] ?? 'Nuevo Topic';
        }

        return '';
    }
}