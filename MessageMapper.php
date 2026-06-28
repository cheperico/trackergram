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
    private string $fieldPrefix = 'telegrammessage';

    /**
     * Setear field prefix para los permNames de campos
     */
    public function setFieldPrefix(string $prefix): void
    {
        $this->fieldPrefix = $prefix;
    }

    public function getFieldPrefix(): string
    {
        return $this->fieldPrefix;
    }

    /**
     * Procesar mensaje de webhook de Telegram
     * Sin efectos secundarios (no upload, no cache)
     */
    /**
     * Extraer hashtags de entities de Telegram
     * Busca en 'entities' y 'caption_entities', devuelve tags espacio-separados sin #
     */
    private function extractHashtags(array $message): string
    {
        $parts = [];
        // entities -> text, caption_entities -> caption
        foreach (['entities' => 'text', 'caption_entities' => 'caption'] as $entitiesKey => $textKey) {
            if (isset($message[$entitiesKey]) && isset($message[$textKey]) && is_string($message[$textKey])) {
                foreach ($message[$entitiesKey] as $entity) {
                    if (($entity['type'] ?? '') === 'hashtag') {
                        $tag = substr($message[$textKey], $entity['offset'], $entity['length']);
                        $parts[] = ltrim($tag, '#');
                    }
                }
            }
        }
        return implode(' ', $parts);
    }

    public function fromWebhook(array $message): NormalizedMessage
    {
        $msg = new NormalizedMessage();
        $msg->hashtags = $this->extractHashtags($message);
        $msg->messageId = (string) ($message['message_id'] ?? '');
        $msg->text = $message['text'] ?? '';
        $msg->editedDate = (string) ($message['edit_date'] ?? '');
        // Ignorar reply_to si apunta a un mensaje de sistema de topics (forum_topic_*)
        $replyToMsg = $message['reply_to_message'] ?? null;
        if ($replyToMsg) {
            $topicKeys = ['forum_topic_created', 'forum_topic_edited', 'forum_topic_closed', 'forum_topic_reopened'];
            $isTopicMsg = false;
            foreach ($topicKeys as $k) {
                if (isset($replyToMsg[$k])) { $isTopicMsg = true; break; }
            }
            $msg->replyToId = $isTopicMsg ? '' : (string) ($replyToMsg['message_id'] ?? '');
            // Extraer texto del mensaje original (gratis en webhook) para replyToText
            if (!$isTopicMsg) {
                $msg->replyToText = $replyToMsg['text'] ?? $replyToMsg['caption'] ?? '';
            }
        } else {
            $msg->replyToId = '';
        }

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
            $msg->width = (string) ($photo['width'] ?? '');
            $msg->height = (string) ($photo['height'] ?? '');
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
            $msg->width = (string) ($video['width'] ?? '');
            $msg->height = (string) ($video['height'] ?? '');
            $msg->duration = (string) ($video['duration'] ?? '');
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
            $msg->duration = (string) ($audio['duration'] ?? '');
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
            $msg->width = (string) ($sticker['width'] ?? '');
            $msg->height = (string) ($sticker['height'] ?? '');
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
            $msg->duration = (string) ($voice['duration'] ?? '');
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
            $msg->width = (string) ($vn['width'] ?? '');
            $msg->height = (string) ($vn['height'] ?? '');
            $msg->duration = (string) ($vn['duration'] ?? '');
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
            // Las encuestas en webhook siempre llegan con 0 votos (apenas se crean).
            // trackerGram es append-only, no puede actualizar el item con resultados posteriores.
            // Las encuestas con datos reales solo se capturan correctamente via import (export ZIP).
            $q = $message['poll']['question'] ?? 'Sin pregunta';
            $isQuiz = ($message['poll']['type'] ?? 'regular') === 'quiz';
            $icon = $isQuiz ? '🧠 Quiz' : '📊 Encuesta';
            $msg->messageType = 'other';
            $msg->text = $icon . ': "' . $q . '" — no capturada en tiempo real (usar import)';
            return $msg;
        }

        if (isset($message['animation'])) {
            $anim = $message['animation'];
            $msg->messageType = 'animation';
            $msg->mediaType = $anim['mime_type'] ?? 'animation/gif';
            $msg->fileId = $anim['file_id'] ?? '';
            $msg->mediaSize = (string) ($anim['file_size'] ?? '');
            $msg->width = (string) ($anim['width'] ?? '');
            $msg->height = (string) ($anim['height'] ?? '');
            $msg->duration = (string) ($anim['duration'] ?? '');
            $msg->text = '🎬 Animation: ' . $msg->mediaType;
            return $msg;
        }

        if (isset($message['new_chat_members'])) {
            $fromId = $message['from']['id'] ?? null;
            $fromName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
            $members = $message['new_chat_members'];
            $memberNames = array_map(fn($u) => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')), $members);

            // Si from == único new_member → joined (se unió solo vía link)
            $isSelfJoin = count($members) === 1 && $fromId !== null && $fromId === ($members[0]['id'] ?? null);

            if ($isSelfJoin) {
                $msg->messageType = 'system';
                $msg->text = '👤 ' . $memberNames[0] . ' se unió al grupo';
            } else {
                $msg->messageType = 'system';
                $msg->text = '👤 ' . $fromName . ' agregó a: ' . implode(', ', $memberNames);
            }
            return $msg;
        }

        if (isset($message['left_chat_member'])) {
            $fromId = $message['from']['id'] ?? null;
            $leftUser = $message['left_chat_member'];
            $leftId = $leftUser['id'] ?? null;
            $leftName = trim(($leftUser['first_name'] ?? '') . ' ' . ($leftUser['last_name'] ?? ''));

            // Si from != left_chat_member → lo eliminó un admin
            if ($fromId !== null && $leftId !== null && $fromId !== $leftId) {
                $fromName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
                $msg->messageType = 'system';
                $msg->text = '🚫 ' . $fromName . ' eliminó a: ' . $leftName;
            } else {
                $msg->messageType = 'system';
                $msg->text = '🚪 ' . $leftName . ' salió del grupo';
            }
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
     * Formatear texto del export de Telegram convirtiendo entidades a texto plano
     * con links en sintaxis wiki [url texto]
     */
    private function formatExportText(array|string $text): string
    {
        if (is_string($text)) {
            return $text;
        }

        $result = '';
        foreach ($text as $part) {
            $partText = $part['text'] ?? '';
            $partType = $part['type'] ?? 'plain';

            if ($partType === 'text_link' && !empty($part['href'])) {
                // Link clickeable en sintaxis wiki
                $result .= '[' . $part['href'] . ' ' . $partText . ']';
            } elseif ($partType === 'url' && !empty($part['href'])) {
                // URL directa
                $result .= '[' . $part['href'] . ']';
            } elseif ($partType === 'mention' && !empty($part['href'])) {
                // Mención a usuario (href tipo tg://user?id=xxx)
                $result .= $partText;
            } else {
                // plain, bold, italic, etc → solo texto (formato wiki es estudio pendiente)
                $result .= $partText;
            }
        }
        return $result;
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
        $msg->uploadedFileIds = $context['file_ids'] ?? [];
        $msg->mediaUrl = $context['media_url'] ?? '';

        // En el export de Telegram, 'from' es un string con el display name completo
        // (no hay first_name/last_name separados ni username).
        // firstName guarda el string original (dato crudo).
        // displayName tiene el mismo valor (para mostrar unificado).
        $from = $message['from'] ?? $message['actor'] ?? '';
        $msg->firstName = $from;
        $msg->displayName = $from;
        // lastName se deja vacío — el export no tiene este campo separado.
        // username se deja vacío — el export no tiene @handle.

        // from_id puede ser "user12345", "chat987654" o "channel456789"
        $fromId = $message['from_id'] ?? $message['actor_id'] ?? '';
        if (preg_match('/^(?:user|chat|channel)(\d+)$/', $fromId, $matches)) {
            $msg->userId = $matches[1];
        } else {
            $msg->userId = preg_replace('/[^0-9]/', '', $fromId) ?: $fromId;
        }

        $rawDate = $message['date'] ?? '';
        $msg->date = is_numeric($rawDate) ? (string) (int) $rawDate : (string) strtotime((string) $rawDate);

        $msgType = $message['type'] ?? 'message';

        // Extraer hashtags del texto del export (formato array o string, y captions)
        $exportHashtags = [];

        // Función helper inline para extraer hashtags de un text array del export
        $extractExportTags = function($text) use (&$exportHashtags) {
            if (is_array($text)) {
                foreach ($text as $part) {
                    if (($part['type'] ?? '') === 'hashtag') {
                        $tag = ltrim($part['text'] ?? '', '#');
                        if ($tag !== '') {
                            $exportHashtags[] = $tag;
                        }
                    }
                }
            }
        };

        $rawText = $message['text'] ?? null;
        if ($rawText !== null) {
            $extractExportTags($rawText);
        }
        // También extraer de captions (photo_caption, file_caption, caption)
        foreach (['photo_caption', 'file_caption', 'caption'] as $captionKey) {
            if (isset($message[$captionKey])) {
                $extractExportTags($message[$captionKey]);
            }
        }
        if (!empty($exportHashtags)) {
            $msg->hashtags = implode(' ', array_unique($exportHashtags));
        }

        if ($msgType === 'message') {
            // Texto: si es array (con entidades), formatear links a sintaxis wiki
            $rawText = $message['text'] ?? '';
            $msg->text = is_array($rawText) ? $this->formatExportText($rawText) : (string) $rawText;

            // ── Polls / Quizzes ──
            // El export de Telegram tiene datos reales (voters, answers), a diferencia
            // del webhook que llega con 0 votos. Se genera texto enriquecido.
            if (!empty($message['poll'])) {
                $poll = $message['poll'];
                $pollQuestion = $poll['question'] ?? $msg->text;
                $isQuiz = !empty($poll['quiz']) || ($poll['type'] ?? '') === 'quiz';
                $isClosed = ($poll['closed'] ?? '') === 'true';
                $totalVoters = (int) ($poll['total_voters'] ?? 0);

                // El export usa 'answers', pero se cae a 'options' por compatibilidad
                $pollAnswers = $poll['answers'] ?? $poll['options'] ?? [];

                $msg->messageType = $isQuiz ? 'quiz' : 'poll';

                $icon = $isQuiz ? '🧠' : '📊';
                $lines = ["{$icon} {$pollQuestion}"];

                foreach ($pollAnswers as $answer) {
                    $answerText = $answer['text'] ?? '?';
                    $voters = (int) ($answer['voters'] ?? 0);
                    $isChosen = ($answer['chosen'] ?? '') === 'true';
                    $isCorrect = ($answer['correct'] ?? '') === 'true';

                    $prefix = '•';
                    if ($isCorrect) {
                        $prefix = '✅';
                    } elseif ($isChosen) {
                        $prefix = '☑️';
                    }

                    $votoLabel = $voters === 1 ? 'voto' : 'votos';
                    $lines[] = "{$prefix} {$answerText}: {$voters} {$votoLabel}";
                }

                if ($totalVoters > 0) {
                    $totalLabel = $totalVoters === 1 ? 'voto' : 'votos';
                    $lines[] = "Total: {$totalVoters} {$totalLabel}";
                }

                if ($isClosed) {
                    $lines[] = '🔒 Cerrada';
                }

                $msg->text = implode("\n", $lines);
                // No seguir a photo/file checks — ya se determinó que es poll
            } elseif (!empty($message['photo']) && !$this->isMediaExcluded($message['photo'])) {
                $msg->messageType = 'photo';
                $msg->mediaCaption = $message['photo_caption'] ?? '';
            } elseif (!empty($message['file']) && !$this->isMediaExcluded($message['file'])) {
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

            $msg->width = (string) ($message['width'] ?? '');
            $msg->height = (string) ($message['height'] ?? '');
            $msg->duration = (string) ($message['duration_seconds'] ?? '');
            $msg->mediaType = $message['mime_type'] ?? $message['media_type'] ?? '';
            $msg->mediaSize = (string) ($message['file_size'] ?? '');
            if (empty($msg->mediaCaption) && isset($message['file'])) {
                $msg->mediaCaption = $message['file_caption'] ?? ($message['caption'] ?? '');
            }

            // Reacciones: formatear como texto legible en vez de JSON crudo
            if (!empty($message['reactions'])) {
                $parts = [];
                foreach ($message['reactions'] as $r) {
                    $emoji = $r['emoji'] ?? ($r['type'] === 'custom_emoji' ? '⭐' : '❓');
                    $count = $r['count'] ?? 1;
                    $parts[] = $emoji . ' ' . $count;
                }
                $msg->reactions = implode(' · ', $parts);
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
                'change_photo' => '🖼️ Foto del grupo actualizada',
                'delete_photo' => '🗑️ Foto del grupo eliminada',
                default => '🔔 ' . $action . (!empty($message['title']) ? ': ' . $message['title'] : '')
            };
        }

        $msg->editedDate = (string) ($message['edited_unixtime'] ?? '');
        $msg->replyToId = (string) ($message['reply_to_message_id'] ?? '');

        return $msg;
    }

    /**
     * Transformar NormalizedMessage a campos para TikiWiki API
     * Devuelve array fields[permName] listo para http_build_query
     */
    public function toWikiFields(NormalizedMessage $msg): array
    {
        $p = $this->fieldPrefix;

        // NOTA: NO usar htmlspecialchars() aquí. Los datos se envían via form-urlencoded
        // (http_build_query los URL-encodea automáticamente). TikiWiki almacena el valor
        // decodificado y la capa de vista (Smarty) se encarga del escape HTML al mostrar.
        // Si aplicamos htmlspecialchars(), las comillas se guardan literalmente como &quot;.
        $fields = [
            "fields[{$p}TelegramMessageId]" => $msg->messageId,
            "fields[{$p}ChatId]" => $msg->chatId,
            "fields[{$p}ChatTitle]" => $msg->chatTitle,
            "fields[{$p}TopicId]" => $msg->topicId,
            "fields[{$p}TopicTitle]" => $msg->topicTitle,
            "fields[{$p}UserId]" => $msg->userId,
            "fields[{$p}Username]" => $msg->username,
            "fields[{$p}FirstName]" => $msg->firstName,
            "fields[{$p}LastName]" => $msg->lastName,
            "fields[{$p}DisplayName]" => $msg->displayName,
            "fields[{$p}MessageType]" => $msg->messageType,
            "fields[{$p}Text]" => $msg->text,
            "fields[{$p}Location]" => $msg->location,
            "fields[{$p}MediaType]" => $msg->mediaType,
            "fields[{$p}MediaSize]" => $msg->mediaSize,
            "fields[{$p}MediaWidth]" => $msg->width,
            "fields[{$p}MediaHeight]" => $msg->height,
            "fields[{$p}MediaDuration]" => $msg->duration,
            "fields[{$p}MediaCaption]" => $msg->mediaCaption,
            "fields[{$p}MessageDate]" => $msg->date,
            "fields[{$p}EditedDate]" => $msg->editedDate,
            "fields[{$p}ReplyToId]" => $msg->replyToId,
            "fields[{$p}Reactions]" => $msg->reactions,
            "fields[{$p}Hashtags]" => $msg->hashtags,
        ];

        if (!empty($msg->uploadedFileIds)) {
            $fields["fields[{$p}Media]"] = implode(',', $msg->uploadedFileIds);
        }

        if ($msg->mediaUrl !== '') {
            $fields["fields[{$p}MediaUrl]"] = $msg->mediaUrl;
        }

        if ($msg->fileUrl !== '') {
            $fields["fields[{$p}FileUrl]"] = $msg->fileUrl;
        }

        return $fields;
    }

    /**
     * Generar SOLO los campos que se pueden actualizar en un mensaje editado.
     * NUNCA incluye Media, MediaUrl, MessageType, Location ni ningún campo
     * que pueda causar pérdida de datos si el export vino sin medios.
     *
     * @return array Array con formato fields[permName]=valor (solo Text, EditedDate, Reactions)
     */
    public function toWikiFieldsEdit(NormalizedMessage $msg): array
    {
        $p = $this->fieldPrefix;
        $fields = [
            "fields[{$p}Text]" => $msg->text,
            "fields[{$p}EditedDate]" => $msg->editedDate,
        ];
        if ($msg->reactions !== '') {
            $fields["fields[{$p}Reactions]"] = $msg->reactions;
        }
        return $fields;
    }

    /**
     * Detectar si un campo de media del export de Telegram contiene el placeholder
     * de media excluida: "(File not included. Change data exporting settings to download.)"
     */
    private function isMediaExcluded(?string $fieldValue): bool
    {
        return $fieldValue !== null && str_starts_with($fieldValue, '(File not included');
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
