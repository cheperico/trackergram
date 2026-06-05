<?php
/**
 * TelegramClient - Cliente para comunicarse con la API de Telegram
 * Encapsula la lógica de descarga de archivos, obtención de información de chats, etc.
 */
class TelegramClient
{
    private string $baseUrl = 'https://api.telegram.org';
    private string $botToken;
    private int $timeout;
    private int $downloadTimeout;

    public function __construct(
        string $botToken,
        int $timeout = 5,
        int $downloadTimeout = 10
    ) {
        $this->botToken = $botToken;
        $this->timeout = $timeout;
        $this->downloadTimeout = $downloadTimeout;
    }

    public function getFileUrl(string $fileId): ?string
    {
        $apiUrl = $this->baseUrl . '/bot' . $this->botToken . '/getFile';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl . '?file_id=' . urlencode($fileId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['result']['file_path'])) {
            return $this->baseUrl . '/file/bot' . $this->botToken . '/' . $data['result']['file_path'];
        }

        return null;
    }

    public function getChat(int $chatId): ?array
    {
        $url = $this->baseUrl . '/bot' . $this->botToken . '/getChat?chat_id=' . $chatId;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['result'])) {
            return $data['result'];
        }

        return null;
    }

    public function downloadFile(string $fileUrl, string $destinationPath): bool
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->downloadTimeout);

        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $fileContent !== false) {
            return file_put_contents($destinationPath, $fileContent) !== false;
        }

        return false;
    }

    public function getFileContent(string $fileId): ?string
    {
        $fileUrl = $this->getFileUrl($fileId);
        if (!$fileUrl) {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->downloadTimeout);

        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return $fileContent;
        }

        return null;
    }

    public function getTopicNameFromMessage(array $message, int $chatId): string
    {
        if (($message['type'] ?? '') === 'service' && ($message['action'] ?? '') === 'topic_created') {
            return $message['title'] ?? 'Topic ' . ($message['id'] ?? '');
        }

        if (isset($message['reply_to_message']['forum_topic_created'])) {
            return $message['reply_to_message']['forum_topic_created']['title'] ?? 'Nuevo Topic';
        }

        return '';
    }
}
