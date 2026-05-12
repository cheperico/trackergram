<?php
/**
 * trackerGram - Importación de exports de Telegram
 * Procesa archivos ZIP exportados desde Telegram y crea items en TikiWiki
 */

// Iniciar sesión para CSRF y autenticación
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
} elseif (session_status() === PHP_SESSION_ACTIVE) {
    // Sesión ya iniciada, no hacer nada
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

// Cargar config después de verificar auth
require_once 'config.php';

// Validar tracker ID
$trackerId = $_POST['tracker_id'] ?? '';
if (!$trackerId || !ctype_digit($trackerId)) {
    echo json_encode(['error' => 'Tracker ID inválido']);
    exit;
}

if (!isset($_FILES['export_file'])) {
    echo json_encode(['error' => 'No se recibió archivo']);
    exit;
}

$file = $_FILES['export_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Error al subir archivo: ' . $file['error']]);
    exit;
}

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($extension !== 'zip') {
    echo json_encode(['error' => 'El archivo debe ser un ZIP']);
    exit;
}

// Validar CSRF token
$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($csrfToken)) {
    echo json_encode(['error' => 'Token CSRF requerido']);
    exit;
}
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

// Extraer el ZIP en carpeta temporal
$tempDir = sys_get_temp_dir() . '/trackergram_import_' . time();
if (!mkdir($tempDir, 0777, true)) {
    echo json_encode(['error' => 'No se pudo crear directorio temporal']);
    exit;
}

$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    rmdir($tempDir);
    echo json_encode(['error' => 'No se pudo abrir el archivo ZIP']);
    exit;
}

$zip->extractTo($tempDir);
$zip->close();

// Buscar result.json
$jsonFile = null;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
foreach ($files as $f) {
    if ($f->getFilename() === 'result.json') {
        $jsonFile = $f->getPathname();
        break;
    }
}

if (!$jsonFile) {
    array_map('unlink', glob("$tempDir/*"));
    rmdir($tempDir);
    echo json_encode(['error' => 'No se encontró result.json en el export']);
    exit;
}

// Leer y parsear el JSON
$jsonContent = file_get_contents($jsonFile);
$data = json_decode($jsonContent, true);
if (!$data || !isset($data['messages'])) {
    array_map('unlink', glob("$tempDir/*"));
    rmdir($tempDir);
    echo json_encode(['error' => 'Formato de export inválido']);
    exit;
}

$messages = $data['messages'];
$chatTitle = $data['name'] ?? 'Unknown Chat';
$chatId = $data['id'] ?? 0;

// Mapear topics creados (action: topic_created)
$topics = [];
foreach ($messages as $msg) {
    if (($msg['type'] ?? '') === 'service' && ($msg['action'] ?? '') === 'topic_created') {
        $topics[$msg['id']] = $msg['title'] ?? 'Topic ' . $msg['id'];
    }
}

// Obtener galleryId del tracker
$galleryId = getGalleryIdForTracker($trackerId);
if (!$galleryId) {
    $galleryId = 29; // Default fallback
}

// Procesar mensajes
$imported = 0;
$skipped = 0;
$mediaProcessed = 0;

foreach ($messages as $msg) {
    // Solo procesar mensajes regulares
    if (($msg['type'] ?? '') !== 'message') {
        continue;
    }

    // Determinar topic
    $topicId = '';
    $topicTitle = '';
    $replyTo = $msg['reply_to_message_id'] ?? '';
    if ($replyTo && isset($topics[$replyTo])) {
        $topicId = $replyTo;
        $topicTitle = $topics[$replyTo];
    }

    // Determinar message_type y archivo
    $messageType = 'text';
    $filePath = '';
    $fileName = '';
    $mediaCaption = '';

    if (!empty($msg['photo'])) {
        $messageType = 'photo';
        $fileName = basename($msg['photo']);
        $mediaCaption = $msg['photo_caption'] ?? '';
        // Buscar el archivo en la carpeta temporal
        $filePath = findFileInTemp($tempDir, $fileName);
    } elseif (!empty($msg['file'])) {
        $fileName = $msg['file_name'] ?? basename($msg['file']);
        if (strpos($msg['file'] ?? '', 'sticker') !== false) {
            $messageType = 'sticker';
        } elseif (strpos($msg['file'] ?? '', 'video') !== false) {
            $messageType = 'video';
        } elseif (strpos($msg['file'] ?? '', 'audio') !== false) {
            $messageType = 'audio';
        } else {
            $messageType = 'document';
        }
        $filePath = findFileInTemp($tempDir, basename($msg['file']));
    }

    // Subir archivo si existe
    $fileId = '';
    if ($filePath && file_exists($filePath)) {
        $fileId = uploadFileToTikiWiki($filePath, $fileName, $galleryId);
        if ($fileId) {
            $mediaProcessed++;
        }
    }

    // Extraer nombre
    $from = $msg['from'] ?? '';
    $fromParts = explode(' ', $from, 2);
    $firstName = $fromParts[0] ?? '';
    $lastName = $fromParts[1] ?? '';

    $userId = $msg['from_id'] ?? '';
    $userId = str_replace('user', '', $userId);

    $date = $msg['date'] ?? '';

    // Construir data
    $itemData = [
        'message_id' => $msg['id'],
        'chat_id' => $chatId,
        'chat_title' => $chatTitle,
        'topic_id' => $topicId,
        'topic_title' => $topicTitle,
        'user_id' => $userId,
        'username' => '',
        'first_name' => $firstName,
        'last_name' => $lastName,
        'message_type' => $messageType,
        'text' => $msg['text'] ?? '',
        'media_caption' => $mediaCaption,
        'date' => $date,
        'file_id' => $fileId
    ];

    $result = importItemToTikiWiki($trackerId, $itemData);
    if ($result) {
        $imported++;
    } else {
        $skipped++;
    }
}

// Limpiar directorio temporal
array_map('unlink', glob("$tempDir/*/*"));
array_map('unlink', glob("$tempDir/*"));
rmdir($tempDir);

echo json_encode([
    'success' => true,
    'imported' => $imported,
    'skipped' => $skipped,
    'media_processed' => $mediaProcessed,
    'topics_found' => count($topics)
]);

/**
 * Buscar archivo en carpeta temporal por nombre
 */
function findFileInTemp(string $tempDir, string $fileName): string {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
    foreach ($files as $f) {
        if ($f->getFilename() === $fileName || strpos($f->getFilename(), $fileName) !== false) {
            return $f->getPathname();
        }
    }
    return '';
}

/**
 * Obtener galleryId del tracker
 */
function getGalleryIdForTracker(int $trackerId): ?int {
    $url = TIKIWIKI_API_URL . "trackers/$trackerId/fields";
    
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
    
    if ($httpCode !== 200) return null;
    
    $data = json_decode($response, true);
    $fields = $data['fields'] ?? [];
    
    // Buscar campo de tipo FG (files)
    foreach ($fields as $field) {
        if (($field['type'] ?? '') === 'FG') {
            $options = json_decode($field['options'] ?? '{}', true);
            return $options['galleryId'] ?? null;
        }
    }
    return null;
}

/**
 * Subir archivo a TikiWiki
 */
function uploadFileToTikiWiki(string $filePath, string $fileName, int $galleryId): string {
    $uploadUrl = TIKIWIKI_API_URL . 'galleries/upload';
    
    $mimeType = mime_content_type($filePath);
    $cfile = curl_file_create($filePath, $mimeType, $fileName);
    
    $postData = [
        'galleryId' => $galleryId,
        'data' => $cfile,
        'name' => $fileName,
        'title' => $fileName,
        'description' => 'Archivo importado desde Telegram - ' . date('Y-m-d H:i:s')
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uploadUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . TIKIWIKI_TOKEN,
        "User-Agent: Mozilla/5.0"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        return '';
    }
    
    $responseData = json_decode($response, true);
    return $responseData['fileId'] ?? $responseData['file_id'] ?? '';
}

/**
 * Enviar item a TikiWiki
 */
function importItemToTikiWiki(int $trackerId, array $data): bool {
    $url = TIKIWIKI_API_URL . "trackers/{$trackerId}/items";
    
    $postFields = [
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
        'fields[telegrammessageMediaCaption]' => htmlspecialchars($data['media_caption'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fields[telegrammessageMessageDate]' => $data['date']
    ];
    
    // Agregar archivo si se subió
    if (!empty($data['file_id'])) {
        $postFields['fields[telegrammessageMedia]'] = $data['file_id'];
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
    curl_close($ch);
    
    return $httpCode === 200;
}