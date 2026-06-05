<?php
/**
 * NormalizedMessage — modelo intermedio unificado entre parsers y TikiWiki
 *
 * Representa un mensaje ya parseado, listo para convertirse a campos de tracker.
 * Es el formato de convergencia tanto para webhook como para importación.
 */
class NormalizedMessage
{
    // --- Campos que se persisten en el tracker ---
    public string $messageId = '';
    public string $chatId = '';
    public string $chatTitle = '';
    public string $topicId = '';
    public string $topicTitle = '';
    public string $userId = '';
    public string $username = '';
    public string $firstName = '';
    public string $lastName = '';
    public string $messageType = 'text';
    public string $text = '';
    public string $location = '';
    public string $mediaType = '';
    public string $mediaSize = '';
    public string $mediaCaption = '';
    public string $date = '';
    public ?string $uploadedFileId = null;

    // --- Transientes (usan durante el procesamiento, no persisten) ---
    public ?string $fileId = null;
    public ?string $fileName = null;
    public ?string $mimeType = null;
    public ?string $systemMessage = null;
    public ?string $topicName = null;
}
