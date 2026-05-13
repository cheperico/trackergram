<?php
/**
 * MessageMapper - Transforma mensajes de Telegram al formato de TikiWiki
 */

require_once 'config.php';

class MessageMapper
{
    /**
     * Transformar mensaje de Telegram a campos para TikiWiki tracker
     */
    public static function toTrackerFields(array $message, array $context = []): array
    {
        $fields = [];

        // IDs básicos
        $fields['telegrammessageTelegramMessageId'] = $message['message_id'] ?? $message['id'] ?? '';
        $fields['telegrammessageChatId'] = $context['chat_id'] ?? '';
        $fields['telegrammessageChatTitle'] = $context['chat_title'] ?? '';

        // Topic
        $fields['telegrammessageTopicId'] = $context['topic_id'] ?? '';
        $fields['telegrammessageTopicTitle'] = $context['topic_title'] ?? '';

        // Usuario
        $fromId = $message['from_id'] ?? '';
        $fields['telegrammessageUserId'] = is_numeric($fromId) ? $fromId : str_replace('user', '', $fromId);

        $from = $message['from'] ?? '';
        $fromParts = explode(' ', $from, 2);
        $fields['telegrammessageFirstName'] = $fromParts[0] ?? '';
        $fields['telegrammessageLastName'] = $fromParts[1] ?? '';
        $fields['telegrammessageUsername'] = $message['from_username'] ?? '';

        // Tipo de mensaje y contenido
        $typeInfo = self::detectMessageType($message);
        $fields['telegrammessageMessageType'] = $typeInfo['type'];
        $fields['telegrammessageText'] = self::extractText($message);
        $fields['telegrammessageMediaCaption'] = $typeInfo['caption'] ?? '';

        // Fecha (UNIX timestamp)
        $fields['telegrammessageMessageDate'] = self::extractDate($message);

        // Media
        $fields['telegrammessageMedia'] = $context['file_id'] ?? '';

        return $fields;
    }

    /**
     * Detectar tipo de mensaje y extraer info de media
     */
    public static function detectMessageType(array $message): array
    {
        $result = ['type' => 'text', 'caption' => '', 'file_id' => '', 'file_name' => ''];

        // Fotos
        if (!empty($message['photo'])) {
            $result['type'] = 'photo';
            $result['caption'] = $message['photo_caption'] ?? '';
            $photos = is_array($message['photo']) ? $message['photo'] : [$message['photo']];
            $result['file_id'] = end($photos);
            return $result;
        }

        // Videos
        if (!empty($message['video'])) {
            $result['type'] = 'video';
            $result['caption'] = $message['video_caption'] ?? '';
            $result['file_id'] = $message['video']['file_id'] ?? '';
            $result['file_name'] = $message['video']['file_name'] ?? '';
            return $result;
        }

        // Videos округленные (video_message)
        if (($message['media_type'] ?? '') === 'video_message' || strpos($message['file'] ?? '', 'video') !== false) {
            $result['type'] = 'video';
            $result['file_id'] = $message['file'] ?? '';
            $result['file_name'] = $message['file_name'] ?? '';
            return $result;
        }

        // Audio
        if (!empty($message['audio'])) {
            $result['type'] = 'audio';
            $result['caption'] = $message['audio_caption'] ?? '';
            $result['file_id'] = $message['audio']['file_id'] ?? '';
            return $result;
        }

        // Notas de voz
        if (!empty($message['voice'])) {
            $result['type'] = 'voice';
            $result['file_id'] = $message['voice']['file_id'] ?? '';
            return $result;
        }

        // Documentos
        if (!empty($message['document'])) {
            $doc = $message['document'];
            if (isset($doc['mime_type'])) {
                if (strpos($doc['mime_type'], 'image') !== false && empty($message['photo'])) {
                    $result['type'] = 'photo';
                } elseif (strpos($doc['mime_type'], 'video') !== false) {
                    $result['type'] = 'video';
                } elseif (strpos($doc['mime_type'], 'audio') !== false) {
                    $result['type'] = 'audio';
                } else {
                    $result['type'] = 'document';
                }
            } else {
                $result['type'] = 'document';
            }
            $result['caption'] = $message['caption'] ?? '';
            $result['file_id'] = $doc['file_id'] ?? '';
            $result['file_name'] = $doc['file_name'] ?? '';
            return $result;
        }

        // Sticker
        if (!empty($message['sticker'])) {
            $result['type'] = 'sticker';
            $result['file_id'] = $message['sticker']['file_id'] ?? '';
            return $result;
        }

        // Ubicación
        if (!empty($message['location'])) {
            $result['type'] = 'location';
            $lat = $message['location']['latitude'] ?? 0;
            $lon = $message['location']['longitude'] ?? 0;
            $result['file_id'] = "$lon,$lat,15";
            return $result;
        }

        // Contacto
        if (!empty($message['contact'])) {
            $result['type'] = 'contact';
            $phone = $message['contact']['phone_number'] ?? '';
            $first = $message['contact']['first_name'] ?? '';
            $last = $message['contact']['last_name'] ?? '';
            $result['file_id'] = json_encode([
                'phone' => $phone,
                'first_name' => $first,
                'last_name' => $last
            ]);
            return $result;
        }

        // Encuesta
        if (!empty($message['poll'])) {
            $result['type'] = 'poll';
            $question = $message['poll']['question'] ?? '';
            $options = $message['poll']['options'] ?? [];
            $result['file_id'] = json_encode([
                'question' => $question,
                'options' => array_column($options, 'text')
            ]);
            return $result;
        }

        // Animation (GIF)
        if (!empty($message['animation'])) {
            $result['type'] = 'animation';
            $result['file_id'] = $message['animation']['file_id'] ?? '';
            return $result;
        }

        // Servicio (topic creado, miembro unido, etc)
        if (($message['type'] ?? '') === 'service') {
            $result['type'] = 'system';
            return $result;
        }

        return $result;
    }

    /**
     * Extraer texto del mensaje (maneja varios formatos)
     */
    public static function extractText(array $message): string
    {
        // Texto directo
        if (isset($message['text']) && is_string($message['text'])) {
            return $message['text'];
        }

        // Texto en array (formatos complejos de Telegram)
        if (isset($message['text']) && is_array($message['text'])) {
            return json_encode($message['text']);
        }

        // Caption (para medios)
        if (!empty($message['caption']) && is_string($message['caption'])) {
            return $message['caption'];
        }

        return '';
    }

    /**
     * Extraer fecha como UNIX timestamp
     */
    public static function extractDate(array $message): int
    {
        $date = $message['date'] ?? '';

        if (is_numeric($date)) {
            return (int) $date;
        }

        if (is_string($date)) {
            return (int) strtotime($date);
        }

        return time();
    }

    /**
     * Extraer información de ubicación
     */
    public static function extractLocation(array $message): ?array
    {
        if (empty($message['location'])) {
            return null;
        }

        return [
            'lat' => $message['location']['latitude'] ?? 0,
            'lon' => $message['location']['longitude'] ?? 0,
            'zoom' => 15
        ];
    }
}