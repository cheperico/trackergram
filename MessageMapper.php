<?php
/**
 * MessageMapper - Transforma mensajes de Telegram al formato de TikiWiki
 *
 * Responsabilidades:
 * - fromWebhook():  mensaje de webhook de Telegram → NormalizedMessage
 * - fromExport():   mensaje de export ZIP de Telegram → NormalizedMessage
 * - toWikiFields(): NormalizedMessage → array fields[permName] listo para POST
 */
class MessageMapper
{
    /**
     * Procesar mensaje de webhook de Telegram
     * Sin efectos secundarios (no upload, no cache)
     */
    public function fromWebhook(array $message): NormalizedMessage
    {
        $msg = new NormalizedMessage();
        $msg->text = $message['text'] ?? '';

        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            $msg->messageType = 'photo';
            $msg->fileId = $photo['file_id'];
            $msg->mimeType = 'image/jpeg';
            $msg->fileName = 'telegram_photo_' . $photo['file_id'] . '.jpg';
            $msg->mediaType = 'image/jpeg';
            $msg->mediaSize = (string) ($photo['file_size'] ?? '');
            $msg->mediaCaption = $message['caption'] ?? '';
            $msg->text = 'Foto: image/jpeg';
            if (isset($message['caption'])) {
                $msg->text .= ' - ' . htmlspecialchars($message['caption']);
            }
            return $msg;
        }

        if (isset($message['video'])) {
            $video = $message['video'];
            $msg->messageType = 'video';
            $msg->fileId = $video['file_id'];
            $mime = $video['mime_type'] ?? 'video/mp4';
            $msg->mimeType = $mime;
            $msg->mediaType = $mime;
            $msg->mediaSize = (string) ($video['file_size'] ?? '');
            $msg->mediaCaption = $message['caption'] ?? '';
            $ext = pathinfo($video['file_name'] ?? $video['file_id'], PATHINFO_EXTENSION) ?: 'mp4';
            $msg->fileName = 'telegram_video_' . $video['file_id'] . '.' . $ext;
            $msg->text = 'Video: ' . $mime;
            if (isset($message['caption'])) {
                $msg->text .= ' - ' . htmlspecialchars($message['caption']);
            }
            return $msg;
        }

        if (isset($message['audio'])) {
            $audio = $message['audio'];
            $msg->messageType = 'audio';
            $msg->fileId = $audio['file_id'];
            $mime = $audio['mime_type'] ?? 'audio/mpeg';
            $msg->mimeType = $mime;
            $msg->mediaType = $mime;
            $msg->mediaSize = (string) ($audio['file_size'] ?? '');
            $msg->fileName = 'telegram_audio_' . $audio['file_id'] . '.mp3';
            $msg->text = 'Audio: ' . $mime;
            if (isset($audio['title'])) {
                $msg->text .= ' - ' . htmlspecialchars($audio['title']);
            }
            return $msg;
        }

        if (isset($message['document'])) {
            $doc = $message['document'];
            $msg->messageType = 'document';
            $msg->fileId = $doc['file_id'];
            $msg->mimeType = $doc['mime_type'] ?? 'application/octet-stream';
            $msg->mediaType = $doc['mime_type'] ?? 'application/octet-stream';
            $msg->mediaSize = (string) ($doc['file_size'] ?? '');
            $msg->mediaCaption = $message['caption'] ?? '';
            $fn = $doc['file_name'] ?? 'Documento';
            $msg->fileName = $fn;
            $msg->text = 'Documento: ' . $msg->mediaType . ' - ' . htmlspecialchars($fn);
            return $msg;
        }

        if (isset($message['sticker'])) {
            $sticker = $message['sticker'];
            $msg->messageType = 'sticker';
            $msg->fileId = $sticker['file_id'];
            $msg->mimeType = 'image/webp';
            $msg->mediaType = 'image/webp';
            $msg->fileName = 'telegram_sticker_' . $sticker['file_id'] . '.webp';
            $msg->text = 'Sticker: image/webp';
            return $msg;
        }

        if (isset($message['voice'])) {
            $voice = $message['voice'];
            $msg->messageType = 'voice';
            $msg->fileId = $voice['file_id'];
            $mime = $voice['mime_type'] ?? 'audio/ogg';
            $msg->mimeType = $mime;
            $msg->mediaType = $mime;
            $msg->mediaSize = (string) ($voice['file_size'] ?? '');
            $ext = '.ogg';
            if ($mime === 'audio/mpeg' || $mime === 'audio/mp3') $ext = '.mp3';
            elseif ($mime === 'audio/webm') $ext = '.webm';
            elseif ($mime === 'audio/wav') $ext = '.wav';
            $msg->fileName = 'telegram_voice_' . $voice['file_id'] . $ext;
            $msg->text = 'Nota de voz: ' . $mime;
            return $msg;
        }

        if (isset($message['video_note'])) {
            $vn = $message['video_note'];
            $msg->messageType = 'video_note';
            $msg->fileId = $vn['file_id'];
            $mime = $vn['mime_type'] ?? 'video/mp4';
            $msg->mimeType = $mime;
            $msg->mediaType = $mime;
            $msg->mediaSize = (string) ($vn['file_size'] ?? '');
            $msg->fileName = 'telegram_video_note_' . $vn['file_id'] . '.mp4';
            $msg->text = 'Video circular: ' . $mime;
            return $msg;
        }

        if (isset($message['forum_topic_created'])) {
            $topicName = $message['forum_topic_created']['name'] ?? 'Nuevo Topic';
            $msg->messageType = 'system';
            $msg->systemMessage = 'Topic creado: ' . $topicName;
            $msg->text = 'Topic creado: ' . $topicName;
            $msg->topicName = $topicName;
            return $msg;
        }

        if (isset($message['forum_topic_edited'])) {
            $newName = $message['forum_topic_edited']['name'] ?? 'Desconocido';
            $msg->messageType = 'system';
            $msg->systemMessage = 'Topic renombrado: ' . $newName;
            $msg->text = 'Topic renombrado a: ' . $newName;
            $msg->topicName = $newName;
            return $msg;
        }

        if (isset($message['forum_topic_closed'])) {
            $msg->messageType = 'system';
            $msg->systemMessage = 'Topic cerrado';
            $msg->text = 'Topic cerrado';
            return $msg;
        }

        if (isset($message['forum_topic_reopened'])) {
            $msg->messageType = 'system';
            $msg->systemMessage = 'Topic reabierto';
            $msg->text = 'Topic reabierto';
            return $msg;
        }

        if (isset($message['location'])) {
            $loc = $message['location'];
            $msg->messageType = 'location';
            $msg->mediaType = 'location';
            $zoom = 15;
            $msg->location = $loc['longitude'] . ',' . $loc['latitude'] . ',' . $zoom;
            $msg->text = '📍 Ubicación';
            if (isset($loc['horizontal_accuracy'])) {
                $msg->text .= ' (precisión: ' . $loc['horizontal_accuracy'] . 'm)';
            }
            return $msg;
        }

        if (isset($message['contact'])) {
            $c = $message['contact'];
            $msg->messageType = 'contact';
            $msg->mediaType = 'contact';
            $phone = $c['phone_number'] ?? '';
            $fn = $c['first_name'] ?? '';
            $ln = $c['last_name'] ?? '';
            $msg->text = '👤 Contacto: ' . $fn . ' ' . $ln . ' (' . $phone . ')';
            return $msg;
        }

        if (isset($message['poll'])) {
            $poll = $message['poll'];
            $msg->messageType = 'poll';
            $msg->mediaType = 'poll';
            $q = $poll['question'] ?? 'Sin pregunta';
            $opts = count($poll['options'] ?? []);
            $msg->text = '📊 Encuesta: ' . $q . ' (' . $opts . ' opciones)';
            return $msg;
        }

        if (isset($message['animation'])) {
            $msg->messageType = 'animation';
            $msg->mediaType = $message['animation']['mime_type'] ?? 'animation/gif';
            $msg->text = '🎬 Animation: ' . $msg->mediaType;
            return $msg;
        }

        if (isset($message['new_chat_members'])) {
            $names = array_map(fn($u) => $u['first_name'] . ' ' . ($u['last_name'] ?? ''), $message['new_chat_members']);
            $msg->messageType = 'system';
            $msg->text = '👤 Se unieron: ' . implode(', ', $names);
            return $msg;
        }

        if (isset($message['left_chat_member'])) {
            $u = $message['left_chat_member'];
            $msg->messageType = 'system';
            $msg->text = '🚪 ' . ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '') . ' salió del grupo';
            return $msg;
        }

        if (isset($message['pinned_message'])) {
            $msg->messageType = 'system';
            $msg->text = '📌 Mensaje fijado';
            return $msg;
        }

        if (isset($message['group_chat_created']) || isset($message['supergroup_chat_created']) || isset($message['channel_chat_created'])) {
            $msg->messageType = 'system';
            $msg->text = '🆕 Grupo creado';
            return $msg;
        }

        if (isset($message['new_chat_title'])) {
            $msg->messageType = 'system';
            $msg->text = '✏️ Título cambiado a: ' . htmlspecialchars($message['new_chat_title']);
            return $msg;
        }

        if (isset($message['new_chat_photo'])) {
            $msg->messageType = 'system';
            $msg->text = '🖼️ Foto del grupo actualizada';
            return $msg;
        }

        if (isset($message['delete_chat_photo'])) {
            $msg->messageType = 'system';
            $msg->text = '🗑️ Foto del grupo eliminada';
            return $msg;
        }

        if (!isset($message['text'])) {
            $messageKeys = array_keys($message);
            $knownTypes = ['message_id', 'from', 'chat', 'date', 'text', 'photo', 'video', 'audio', 'document', 'sticker', 'voice', 'video_note', 'location', 'contact', 'poll', 'animation', 'forum_topic_edited', 'forum_topic_created', 'forum_topic_closed', 'forum_topic_reopened', 'caption', 'edit_date', 'entities', 'forward_from', 'forward_from_chat', 'reply_to_message', 'via_bot', 'new_chat_members', 'left_chat_member', 'pinned_message', 'group_chat_created', 'supergroup_chat_created', 'new_chat_title', 'new_chat_photo', 'delete_chat_photo'];
            $unknownTypes = array_diff($messageKeys, $knownTypes);
            if (!empty($unknownTypes)) {
                $msg->messageType = 'other';
                $msg->text = '[Tipo no soportado: ' . implode(', ', $unknownTypes) . ']';
            } else {
                $msg->messageType = 'other';
                $msg->text = '[Mensaje sin texto]';
            }
        }

        return $msg;
    }

    /**
     * Procesar mensaje de export ZIP de Telegram
     */
    public function fromExport(array $message, array $context): NormalizedMessage
    {
        $msg = new NormalizedMessage();

        $msg->messageId = (string) ($message['id'] ?? '');
        $msg->chatId = (string) ($context['chat_id'] ?? '');
        $msg->chatTitle = $context['chat_title'] ?? '';
        $msg->topicId = (string) ($context['topic_id'] ?? '');
        $msg->topicTitle = $context['topic_title'] ?? '';
        $msg->uploadedFileId = $context['file_id'] ?? null;

        $from = $message['from'] ?? $message['actor'] ?? '';
        $fromParts = explode(' ', $from, 2);
        $msg->firstName = $fromParts[0] ?? '';
        $msg->lastName = $fromParts[1] ?? '';

        $msg->userId = str_replace('user', '', $message['from_id'] ?? $message['actor_id'] ?? '');

        $rawDate = $message['date'] ?? '';
        $msg->date = is_numeric($rawDate) ? (string) (int) $rawDate : (string) strtotime((string) $rawDate);

        $msgType = $message['type'] ?? 'message';

        if ($msgType === 'message') {
            $rawText = $message['text'] ?? '';
            $msg->text = is_array($rawText) ? json_encode($rawText, JSON_UNESCAPED_UNICODE) : (string) $rawText;

            if (!empty($message['photo'])) {
                $msg->messageType = 'photo';
                $msg->mediaCaption = $message['photo_caption'] ?? '';
            } elseif (!empty($message['file'])) {
                $fileType = $message['media_type'] ?? '';
                $fileName = $message['file_name'] ?? basename((string) $message['file']);
                if ($fileType === 'sticker' || strpos($message['file'] ?? '', 'sticker') !== false) {
                    $msg->messageType = 'sticker';
                } elseif ($fileType === 'voice_message') {
                    $msg->messageType = 'voice';
                } elseif ($fileType === 'animation') {
                    $msg->messageType = 'animation';
                } elseif ($fileType === 'video_message' || $fileType === 'video_file' || strpos($message['file'] ?? '', 'video') !== false) {
                    $msg->messageType = 'video';
                } elseif ($fileType === 'audio' || $fileType === 'audio_file' || strpos($message['file'] ?? '', 'audio') !== false) {
                    $msg->messageType = 'audio';
                } else {
                    $msg->messageType = 'document';
                }
            } else {
                $msg->messageType = 'text';
            }
        } else {
            $msg->messageType = 'system';
            $action = $message['action'] ?? '';
            $firstName = $msg->firstName;
            $msg->text = match ($action) {
                'topic_created' => '📌 Tema creado: ' . ($message['title'] ?? ''),
                'topic_edit' => '✏️ Tema renombrado a: ' . ($message['new_title'] ?? ''),
                'topic_closed' => '🔒 Tema cerrado',
                'topic_reopened' => '🔓 Tema reabierto',
                'pin_message', 'pinned_message' => '📌 Mensaje fijado por ' . $firstName,
                'create_group' => '🆕 Grupo creado',
                'invite_members', 'add_members' => '👤 ' . $firstName . ' agregó a: ' . implode(', ', $message['members'] ?? []),
                'remove_members' => '🚫 ' . $firstName . ' eliminó a: ' . implode(', ', $message['members'] ?? []),
                'joined' => '👤 ' . $firstName . ' se unió al grupo',
                'left' => '🚪 ' . $firstName . ' salió del grupo',
                'title_edit' => '✏️ Título cambiado a: ' . ($message['title'] ?? ''),
                default => '🔔 ' . $action . (!empty($message['title']) ? ': ' . $message['title'] : '')
            };
        }

        return $msg;
    }

    /**
     * Transformar NormalizedMessage a campos para TikiWiki API
     * Devuelve array fields[permName] listo para http_build_query
     */
    public function toWikiFields(NormalizedMessage $msg): array
    {
        $fields = [
            'fields[telegrammessageTelegramMessageId]' => $msg->messageId,
            'fields[telegrammessageChatId]' => $msg->chatId,
            'fields[telegrammessageChatTitle]' => htmlspecialchars($msg->chatTitle, ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageTopicId]' => $msg->topicId,
            'fields[telegrammessageTopicTitle]' => htmlspecialchars($msg->topicTitle, ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageUserId]' => $msg->userId,
            'fields[telegrammessageUsername]' => htmlspecialchars($msg->username, ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageFirstName]' => htmlspecialchars($msg->firstName, ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageLastName]' => htmlspecialchars($msg->lastName, ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageMessageType]' => $msg->messageType,
            'fields[telegrammessageText]' => htmlspecialchars($msg->text, ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageLocation]' => $msg->location,
            'fields[telegrammessageMediaType]' => $msg->mediaType,
            'fields[telegrammessageMediaSize]' => $msg->mediaSize,
            'fields[telegrammessageMediaCaption]' => htmlspecialchars($msg->mediaCaption, ENT_QUOTES, 'UTF-8'),
            'fields[telegrammessageMessageDate]' => $msg->date,
        ];

        if ($msg->uploadedFileId !== null && $msg->uploadedFileId !== '') {
            $fields['fields[telegrammessageMedia]'] = $msg->uploadedFileId;
        }

        return $fields;
    }

    // --- Static helpers para casos donde el msg no tiene extractDate ---

    public function extractText(array $message): string
    {
        if (isset($message['text']) && is_string($message['text'])) {
            return $message['text'];
        }
        if (isset($message['text']) && is_array($message['text'])) {
            return json_encode($message['text'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($message['caption']) && is_string($message['caption'])) {
            return $message['caption'];
        }
        return '';
    }

    public function extractDate(array $message): int
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

    public function detectMessageType(array $message): array
    {
        $result = ['type' => 'text', 'caption' => '', 'file_id' => '', 'file_name' => ''];

        if (!empty($message['photo'])) {
            $result['type'] = 'photo';
            $result['caption'] = $message['photo_caption'] ?? '';
            $photos = is_array($message['photo']) ? $message['photo'] : [$message['photo']];
            $result['file_id'] = end($photos);
            return $result;
        }

        if (!empty($message['video'])) {
            $result['type'] = 'video';
            $result['caption'] = $message['video_caption'] ?? '';
            $result['file_id'] = $message['video']['file_id'] ?? '';
            $result['file_name'] = $message['video']['file_name'] ?? '';
            return $result;
        }

        if (($message['media_type'] ?? '') === 'video_message' || strpos($message['file'] ?? '', 'video') !== false) {
            $result['type'] = 'video';
            $result['file_id'] = $message['file'] ?? '';
            $result['file_name'] = $message['file_name'] ?? '';
            return $result;
        }

        if (!empty($message['audio'])) {
            $result['type'] = 'audio';
            $result['caption'] = $message['audio_caption'] ?? '';
            $result['file_id'] = $message['audio']['file_id'] ?? '';
            return $result;
        }

        if (!empty($message['voice'])) {
            $result['type'] = 'voice';
            $result['file_id'] = $message['voice']['file_id'] ?? '';
            return $result;
        }

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

        if (!empty($message['sticker'])) {
            $result['type'] = 'sticker';
            $result['file_id'] = $message['sticker']['file_id'] ?? '';
            return $result;
        }

        if (!empty($message['location'])) {
            $result['type'] = 'location';
            $result['file_id'] = ($message['location']['longitude'] ?? 0) . ',' . ($message['location']['latitude'] ?? 0) . ',15';
            return $result;
        }

        if (!empty($message['contact'])) {
            $result['type'] = 'contact';
            $result['file_id'] = json_encode([
                'phone' => $message['contact']['phone_number'] ?? '',
                'first_name' => $message['contact']['first_name'] ?? '',
                'last_name' => $message['contact']['last_name'] ?? ''
            ], JSON_UNESCAPED_UNICODE);
            return $result;
        }

        if (!empty($message['poll'])) {
            $result['type'] = 'poll';
            $result['file_id'] = json_encode([
                'question' => $message['poll']['question'] ?? '',
                'options' => array_column($message['poll']['options'] ?? [], 'text')
            ], JSON_UNESCAPED_UNICODE);
            return $result;
        }

        if (!empty($message['animation'])) {
            $result['type'] = 'animation';
            $result['file_id'] = $message['animation']['file_id'] ?? '';
            return $result;
        }

        if (($message['type'] ?? '') === 'service') {
            $result['type'] = 'system';
            return $result;
        }

        return $result;
    }

    public function extractLocation(array $message): ?array
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
