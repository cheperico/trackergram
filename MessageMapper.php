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

    /**
     * Procesar mensaje de webhook de Telegram: detecta tipo y extrae datos
     * Sin efectos secundarios (no upload, no cache). La llamadora hace eso.
     */
    public static function fromWebhook(array $message): array
    {
        $data = [
            'type' => 'text',
            'text' => $message['text'] ?? '',
            'file_id' => null,
            'file_name' => null,
            'mime_type' => null,
            'media_type' => null,
            'media_size' => null,
            'media_caption' => null,
            'system_message' => null,
            'location' => null,
            'topic_name' => null,
        ];

        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            $data['type'] = 'photo';
            $data['file_id'] = $photo['file_id'];
            $data['mime_type'] = 'image/jpeg';
            $data['file_name'] = 'telegram_photo_' . $photo['file_id'] . '.jpg';
            $data['media_type'] = 'image/jpeg';
            $data['media_size'] = $photo['file_size'] ?? null;
            $data['media_caption'] = $message['caption'] ?? null;
            $data['text'] = 'Foto: image/jpeg';
            if (isset($message['caption'])) {
                $data['text'] .= ' - ' . htmlspecialchars($message['caption']);
            }
            return $data;
        }

        if (isset($message['video'])) {
            $video = $message['video'];
            $data['type'] = 'video';
            $data['file_id'] = $video['file_id'];
            $mime = $video['mime_type'] ?? 'video/mp4';
            $data['mime_type'] = $mime;
            $data['media_type'] = $mime;
            $data['media_size'] = $video['file_size'] ?? null;
            $data['media_caption'] = $message['caption'] ?? null;
            $ext = pathinfo($video['file_id'], PATHINFO_EXTENSION);
            $data['file_name'] = 'telegram_video_' . $video['file_id'] . '.' . $ext;
            $data['text'] = 'Video: ' . $mime;
            if (isset($message['caption'])) {
                $data['text'] .= ' - ' . htmlspecialchars($message['caption']);
            }
            return $data;
        }

        if (isset($message['audio'])) {
            $audio = $message['audio'];
            $data['type'] = 'audio';
            $data['file_id'] = $audio['file_id'];
            $mime = $audio['mime_type'] ?? 'audio/mpeg';
            $data['mime_type'] = $mime;
            $data['media_type'] = $mime;
            $data['media_size'] = $audio['file_size'] ?? null;
            $data['file_name'] = 'telegram_audio_' . $audio['file_id'] . '.mp3';
            $data['text'] = 'Audio: ' . $mime;
            if (isset($audio['title'])) {
                $data['text'] .= ' - ' . htmlspecialchars($audio['title']);
            }
            return $data;
        }

        if (isset($message['document'])) {
            $doc = $message['document'];
            $data['type'] = 'document';
            $data['file_id'] = $doc['file_id'];
            $data['mime_type'] = $doc['mime_type'] ?? 'application/octet-stream';
            $data['media_type'] = $doc['mime_type'] ?? 'application/octet-stream';
            $data['media_size'] = $doc['file_size'] ?? null;
            $data['media_caption'] = $message['caption'] ?? null;
            $fn = $doc['file_name'] ?? 'Documento';
            $data['file_name'] = $fn;
            $data['text'] = 'Documento: ' . $data['media_type'] . ' - ' . htmlspecialchars($fn);
            return $data;
        }

        if (isset($message['sticker'])) {
            $sticker = $message['sticker'];
            $data['type'] = 'sticker';
            $data['file_id'] = $sticker['file_id'];
            $data['mime_type'] = 'image/webp';
            $data['media_type'] = 'image/webp';
            $data['file_name'] = 'telegram_sticker_' . $sticker['file_id'] . '.webp';
            $data['text'] = 'Sticker: image/webp';
            return $data;
        }

        if (isset($message['voice'])) {
            $voice = $message['voice'];
            $data['type'] = 'voice';
            $data['file_id'] = $voice['file_id'];
            $mime = $voice['mime_type'] ?? 'audio/ogg';
            $data['mime_type'] = $mime;
            $data['media_type'] = $mime;
            $data['media_size'] = $voice['file_size'] ?? null;
            $ext = '.ogg';
            if ($mime === 'audio/mpeg' || $mime === 'audio/mp3') $ext = '.mp3';
            elseif ($mime === 'audio/webm') $ext = '.webm';
            elseif ($mime === 'audio/wav') $ext = '.wav';
            $data['file_name'] = 'telegram_voice_' . $voice['file_id'] . $ext;
            $data['text'] = 'Nota de voz: ' . $mime;
            return $data;
        }

        if (isset($message['video_note'])) {
            $vn = $message['video_note'];
            $data['type'] = 'video_note';
            $data['file_id'] = $vn['file_id'];
            $mime = $vn['mime_type'] ?? 'video/mp4';
            $data['mime_type'] = $mime;
            $data['media_type'] = $mime;
            $data['media_size'] = $vn['file_size'] ?? null;
            $data['file_name'] = 'telegram_video_note_' . $vn['file_id'] . '.mp4';
            $data['text'] = 'Video circular: ' . $mime;
            return $data;
        }

        if (isset($message['forum_topic_created'])) {
            $topicName = $message['forum_topic_created']['name'] ?? 'Nuevo Topic';
            $data['type'] = 'system';
            $data['system_message'] = 'Topic creado: ' . $topicName;
            $data['text'] = 'Topic creado: ' . $topicName;
            $data['topic_name'] = $topicName;
            return $data;
        }

        if (isset($message['forum_topic_edited'])) {
            $newName = $message['forum_topic_edited']['name'] ?? 'Desconocido';
            $data['type'] = 'system';
            $data['system_message'] = 'Topic renombrado: ' . $newName;
            $data['text'] = 'Topic renombrado a: ' . $newName;
            $data['topic_name'] = $newName;
            return $data;
        }

        if (isset($message['forum_topic_closed'])) {
            $data['type'] = 'system';
            $data['system_message'] = 'Topic cerrado';
            $data['text'] = 'Topic cerrado';
            return $data;
        }

        if (isset($message['forum_topic_reopened'])) {
            $data['type'] = 'system';
            $data['system_message'] = 'Topic reabierto';
            $data['text'] = 'Topic reabierto';
            return $data;
        }

        if (isset($message['location'])) {
            $loc = $message['location'];
            $data['type'] = 'location';
            $data['media_type'] = 'location';
            $zoom = 15;
            $data['location'] = $loc['longitude'] . ',' . $loc['latitude'] . ',' . $zoom;
            $data['text'] = '📍 Ubicación';
            if (isset($loc['horizontal_accuracy'])) {
                $data['text'] .= ' (precisión: ' . $loc['horizontal_accuracy'] . 'm)';
            }
            return $data;
        }

        if (isset($message['contact'])) {
            $c = $message['contact'];
            $data['type'] = 'contact';
            $data['media_type'] = 'contact';
            $phone = $c['phone_number'] ?? '';
            $fn = $c['first_name'] ?? '';
            $ln = $c['last_name'] ?? '';
            $data['text'] = '👤 Contacto: ' . $fn . ' ' . $ln . ' (' . $phone . ')';
            return $data;
        }

        if (isset($message['poll'])) {
            $poll = $message['poll'];
            $data['type'] = 'poll';
            $data['media_type'] = 'poll';
            $q = $poll['question'] ?? 'Sin pregunta';
            $opts = count($poll['options'] ?? []);
            $data['text'] = '📊 Encuesta: ' . $q . ' (' . $opts . ' opciones)';
            return $data;
        }

        if (isset($message['animation'])) {
            $data['type'] = 'animation';
            $data['media_type'] = $message['animation']['mime_type'] ?? 'animation/gif';
            $data['text'] = '🎬 Animation: ' . $data['media_type'];
            return $data;
        }

        if (isset($message['new_chat_members'])) {
            $names = array_map(fn($u) => $u['first_name'] . ' ' . ($u['last_name'] ?? ''), $message['new_chat_members']);
            $data['type'] = 'system';
            $data['text'] = '👤 Se unieron: ' . implode(', ', $names);
            return $data;
        }

        if (isset($message['left_chat_member'])) {
            $u = $message['left_chat_member'];
            $data['type'] = 'system';
            $data['text'] = '🚪 ' . ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '') . ' salió del grupo';
            return $data;
        }

        if (isset($message['pinned_message'])) {
            $data['type'] = 'system';
            $data['text'] = '📌 Mensaje fijado';
            return $data;
        }

        if (isset($message['group_chat_created']) || isset($message['supergroup_chat_created']) || isset($message['channel_chat_created'])) {
            $data['type'] = 'system';
            $data['text'] = '🆕 Grupo creado';
            return $data;
        }

        if (isset($message['new_chat_title'])) {
            $data['type'] = 'system';
            $data['text'] = '✏️ Título cambiado a: ' . htmlspecialchars($message['new_chat_title']);
            return $data;
        }

        if (isset($message['new_chat_photo'])) {
            $data['type'] = 'system';
            $data['text'] = '🖼️ Foto del grupo actualizada';
            return $data;
        }

        if (isset($message['delete_chat_photo'])) {
            $data['type'] = 'system';
            $data['text'] = '🗑️ Foto del grupo eliminada';
            return $data;
        }

        // Unknown types
        if (!isset($message['text'])) {
            $messageKeys = array_keys($message);
            $knownTypes = ['message_id', 'from', 'chat', 'date', 'text', 'photo', 'video', 'audio', 'document', 'sticker', 'voice', 'video_note', 'location', 'contact', 'poll', 'animation', 'forum_topic_edited', 'forum_topic_created', 'forum_topic_closed', 'forum_topic_reopened', 'caption', 'edit_date', 'entities', 'forward_from', 'forward_from_chat', 'reply_to_message', 'via_bot', 'new_chat_members', 'left_chat_member', 'pinned_message', 'group_chat_created', 'supergroup_chat_created', 'new_chat_title', 'new_chat_photo', 'delete_chat_photo'];
            $unknownTypes = array_diff($messageKeys, $knownTypes);
            if (!empty($unknownTypes)) {
                $data['type'] = 'other';
                $data['text'] = '[Tipo no soportado: ' . implode(', ', $unknownTypes) . ']';
            } else {
                $data['type'] = 'other';
                $data['text'] = '[Mensaje sin texto]';
            }
        }

        return $data;
    }

    /**
     * Transformar datos estructurados de un mensaje a campos con encoding para TikiWiki API
     * Recibe el array $tikiData de processUpdate() y devuelve fields[permName] listo para POST
     */
    public static function toWikiFields(array $data): array
    {
        $fields = [
            'fields[telegrammessageTelegramMessageId]' => $data['message_id'],
            'fields[telegrammessageChatId]' => $data['chat_id'],
            'fields[telegrammessageChatTitle]' => htmlspecialchars($data['chat_title'] ?? '', ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageTopicId]' => $data['topic_id'],
            'fields[telegrammessageTopicTitle]' => htmlspecialchars($data['topic_title'] ?? '', ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageUserId]' => $data['user_id'],
            'fields[telegrammessageUsername]' => htmlspecialchars($data['username'] ?? '', ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageFirstName]' => htmlspecialchars($data['first_name'] ?? '', ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageLastName]' => htmlspecialchars($data['last_name'] ?? '', ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageMessageType]' => $data['message_type'],
            'fields[telegrammessageText]' => htmlspecialchars($data['text'] ?? '', ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageLocation]' => $data['location'] ?? '',
            'fields[telegrammessageMediaType]' => $data['media_type'],
            'fields[telegrammessageMediaSize]' => $data['media_size'],
            'fields[telegrammessageMediaCaption]' => htmlspecialchars($data['media_caption'] ?? '', ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageMessageDate]' => $data['date']
        ];

        if (!empty($data['uploaded_file_id'])) {
            $fields['fields[telegrammessageMedia]'] = $data['uploaded_file_id'];
        }

        return $fields;
    }
}