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
    /** Cache interno de respuestas getFile para evitar llamadas API duplicadas */
    private array $fileInfoCache = [];

    public function __construct(
        string $botToken,
        int $timeout = 5,
        int $downloadTimeout = 10
    ) {
        $this->botToken = $botToken;
        $this->timeout = $timeout;
        $this->downloadTimeout = $downloadTimeout;
    }

    /**
     * Obtener información completa de un archivo de Telegram (file_path, file_size, etc.)
     * Cachea el resultado para evitar llamadas API duplicadas entre getFileUrl() y getFileInfo().
     *
     * @return array{file_path: string, file_size?: int, file_unique_id?: string}|null
     */
    public function getFileInfo(string $fileId): ?array
    {
        if (isset($this->fileInfoCache[$fileId])) {
            return $this->fileInfoCache[$fileId];
        }

        $apiUrl = $this->baseUrl . '/bot' . $this->botToken . '/getFile';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl . '?file_id=' . urlencode($fileId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $result = $data['result'] ?? null;

        if ($result !== null && isset($result['file_path'])) {
            $this->fileInfoCache[$fileId] = $result;
            return $result;
        }

        $this->fileInfoCache[$fileId] = null;
        return null;
    }

    /**
     * Obtener URL pública de descarga para un file_id.
     * Reusa el cache de getFileInfo() para evitar llamadas API duplicadas.
     */
    public function getFileUrl(string $fileId): ?string
    {
        $info = $this->getFileInfo($fileId);
        if ($info === null || !isset($info['file_path'])) {
            return null;
        }
        return $this->baseUrl . '/file/bot' . $this->botToken . '/' . $info['file_path'];
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
     * Obtener info del webhook configurado para este bot
     * @return array{ok: bool, url: string, has_custom_certificate: bool, pending_update_count: int, last_error_date: ?int, last_error_message: string, max_connections: int, allowed_updates: array}
     */
    public function getWebhookInfo(): array
    {
        $url = $this->baseUrl . '/bot' . $this->botToken . '/getWebhookInfo';

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
            return ['ok' => false, 'url' => '', 'has_custom_certificate' => false, 'pending_update_count' => 0, 'last_error_date' => null, 'last_error_message' => "Error de red: {$curlError}", 'max_connections' => 40, 'allowed_updates' => []];
        }

        if ($httpCode !== 200) {
            return ['ok' => false, 'url' => '', 'has_custom_certificate' => false, 'pending_update_count' => 0, 'last_error_date' => null, 'last_error_message' => "HTTP {$httpCode}", 'max_connections' => 40, 'allowed_updates' => []];
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['ok']) || !$data['ok']) {
            return ['ok' => false, 'url' => '', 'has_custom_certificate' => false, 'pending_update_count' => 0, 'last_error_date' => null, 'last_error_message' => $data['description'] ?? 'respuesta inválida', 'max_connections' => 40, 'allowed_updates' => []];
        }

        return $data['result'];
    }

    /**
     * Configurar webhook en Telegram.
     *
     * @param string $url URL pública donde Telegram enviará los updates
     * @param string $secretToken Secreto opcional para verificar origen
     * @return array{ok: bool, description: string, result?: array}
     */
    public function setWebhook(string $url, string $secretToken = ''): array
    {
        $apiUrl = $this->baseUrl . '/bot' . $this->botToken . '/setWebhook';

        $params = ['url' => $url];
        if ($secretToken !== '') {
            $params['secret_token'] = $secretToken;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['ok' => false, 'description' => "Error de red: {$curlError}"];
        }

        if ($httpCode !== 200) {
            return ['ok' => false, 'description' => "HTTP {$httpCode}"];
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['ok'])) {
            return ['ok' => false, 'description' => 'Respuesta inválida de Telegram'];
        }

        return $data;
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

    /**
     * Obtener los updates recientes via getUpdates (para diagnóstico).
     * Útil para verificar si el bot recibe mensajes (privacy mode).
     * @param int $limit Máximo de updates a devolver (default 10)
     * @param int $offset Opcional: offset para paginación
     * @return array Lista de updates con información resumida
     */
    public function getUpdates(int $limit = 10, int $offset = 0): array
    {
        $url = $this->baseUrl . '/bot' . $this->botToken . '/getUpdates';

        $params = [
            'limit' => max(1, min(100, $limit)),
            'timeout' => 0, // Polling corto, no long-polling
            'allowed_updates' => json_encode(['message', 'edited_message', 'channel_post', 'my_chat_member']),
        ];
        if ($offset > 0) {
            $params['offset'] = $offset;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            // Intentar extraer mensaje descriptivo del body (Telegram incluye description en 409)
            $body = json_decode($response, true);
            $desc = $body['description'] ?? "HTTP {$httpCode}";
            return ['ok' => false, 'error' => $desc, 'updates' => []];
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['ok']) || !$data['ok']) {
            $desc = $data['description'] ?? 'respuesta inválida';
            return ['ok' => false, 'error' => $desc, 'updates' => []];
        }

        $updates = $data['result'] ?? [];

        // Resumir cada update para mostrar en pantalla
        $summaries = [];
        foreach ($updates as $u) {
            $summary = [
                'update_id' => $u['update_id'] ?? 0,
                'chat_id' => 0,
                'chat_title' => '',
                'type' => 'unknown',
                'text' => '',
                'from_command' => false,
                'mentioned_bot' => false,
                'is_private' => false,
                'timestamp' => 0,
            ];

            // message, edited_message, channel_post
            $msg = $u['message'] ?? $u['edited_message'] ?? $u['channel_post'] ?? null;
            if ($msg) {
                $chat = $msg['chat'] ?? [];
                $from = $msg['from'] ?? [];
                $summary['chat_id'] = $chat['id'] ?? 0;
                $summary['chat_title'] = $chat['title'] ?? $chat['username'] ?? ($from['first_name'] ?? '');
                $summary['type'] = $msg['chat']['type'] ?? 'unknown'; // private, group, supergroup
                $summary['is_private'] = ($summary['type'] === 'private');
                $summary['timestamp'] = $msg['date'] ?? 0;

                // Detectar si es un comando
                $text = $msg['text'] ?? $msg['caption'] ?? '';
                $summary['text'] = mb_substr($text, 0, 200);
                $summary['from_command'] = str_starts_with(ltrim($text), '/');

                // Detectar si menciona al bot
                $entities = $msg['entities'] ?? [];
                foreach ($entities as $e) {
                    if (($e['type'] ?? '') === 'bot_command') {
                        $summary['from_command'] = true;
                    }
                }
            }

            // my_chat_member
            if (isset($u['my_chat_member'])) {
                $mcm = $u['my_chat_member'];
                $chat = $mcm['chat'] ?? [];
                $summary['chat_id'] = $chat['id'] ?? 0;
                $summary['chat_title'] = $chat['title'] ?? $chat['username'] ?? '';
                $summary['type'] = 'my_chat_member';
                $summary['text'] = 'El bot fue agregado/salió del chat';
            }

            $summaries[] = $summary;
        }

        // Auto-detectar privacy mode: si hay mensajes que NO son comandos y NO son privados
        $hasNonCommandMessages = false;
        foreach ($summaries as $s) {
            if (!$s['from_command'] && !$s['is_private'] && $s['text'] !== '') {
                $hasNonCommandMessages = true;
                break;
            }
        }

        $privacyMode = null; // null = no se puede determinar
        if (empty($summaries)) {
            $privacyMode = null; // Sin mensajes, no se puede determinar
        } elseif ($hasNonCommandMessages) {
            $privacyMode = false; // Desactivado — ve mensajes normales
        } else {
            // Solo comandos → puede estar activado (o no hay mensajes de grupo)
            $privacyMode = true; // Probablemente activado
        }

        return [
            'ok' => true,
            'updates' => $summaries,
            'count' => count($summaries),
            'privacy_mode_on' => $privacyMode,
            'has_non_command_messages' => $hasNonCommandMessages,
        ];
    }

    /**
     * Enviar un mensaje de texto a un chat de Telegram
     * @return array|bool Devuelve true si ok, o el resultado decodificado si se necesita (message_id, etc.)
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): array|bool
    {
        $url = $this->baseUrl . '/bot' . $this->botToken . '/sendMessage';

        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            log_message("trackerGram: sendMessage falló (HTTP {$httpCode})", true);
            return false;
        }

        $data = json_decode($response, true);
        if (isset($data['ok']) && $data['ok'] === true) {
            return $data['result'] ?? true;
        }
        return false;
    }

    /**
     * Editar el texto de un mensaje existente enviado por el bot
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): bool
    {
        $url = $this->baseUrl . '/bot' . $this->botToken . '/editMessageText';

        $params = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            log_message("trackerGram: editMessageText falló (HTTP {$httpCode})", true);
            return false;
        }

        $data = json_decode($response, true);
        return isset($data['ok']) && $data['ok'] === true;
    }

    /**
     * Editar solo los botones inline de un mensaje existente
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, array $inlineKeyboard): bool
    {
        $url = $this->baseUrl . '/bot' . $this->botToken . '/editMessageReplyMarkup';

        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            log_message("trackerGram: editMessageReplyMarkup falló (HTTP {$httpCode})", true);
            return false;
        }

        $data = json_decode($response, true);
        return isset($data['ok']) && $data['ok'] === true;
    }

    /**
     * Responder a un callback_query (saca el relojito del botón)
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): bool
    {
        $url = $this->baseUrl . '/bot' . $this->botToken . '/answerCallbackQuery';

        $params = [
            'callback_query_id' => $callbackQueryId,
        ];
        if ($text !== '') {
            $params['text'] = $text;
        }
        if ($showAlert) {
            $params['show_alert'] = 'true';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            log_message("trackerGram: answerCallbackQuery falló (HTTP {$httpCode})", true);
            return false;
        }

        $data = json_decode($response, true);
        return isset($data['ok']) && $data['ok'] === true;
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
