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

    /**
     * Probar conexión con la API de Telegram (getMe)
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $url = $this->baseUrl . '/bot' . $this->botToken . '/getMe';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
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

        if ($httpCode !== 200) {
            return ['ok' => false, 'message' => "HTTP {$httpCode} — la API de Telegram no respondió correctamente"];
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['ok']) || !$data['ok']) {
            $desc = $data['description'] ?? 'respuesta inválida';
            return ['ok' => false, 'message' => "Telegram rechazó la conexión: {$desc}"];
        }

        $botName = $data['result']['username'] ?? '';
        return ['ok' => true, 'message' => "Conectado como @{$botName}", 'bot_name' => $botName];
    }

    /**
     * Hacer que el bot abandone un chat (grupo, supergrupo, canal)
     * @param int $chatId ID del chat a abandonar
     * @return bool True si el bot salió exitosamente
     */
    public function leaveChat(int $chatId): bool
    {
        $url = $this->baseUrl . '/bot' . $this->botToken . '/leaveChat';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['chat_id' => $chatId]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            log_message("trackerGram: leaveChat({$chatId}) falló — HTTP {$httpCode}", true);
            return false;
        }

        $data = json_decode($response, true);
        $ok = isset($data['ok']) && $data['ok'] === true;

        if ($ok) {
            log_message("trackerGram: Bot salió del chat {$chatId} exitosamente");
        } else {
            $desc = $data['description'] ?? 'sin descripción';
            log_message("trackerGram: leaveChat({$chatId}) rechazado por Telegram: {$desc}", true);
        }

        return $ok;
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
