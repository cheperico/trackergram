<?php
/**
 * trackerGram - Importación de exports de Telegram
 * Procesa archivos ZIP exportados desde Telegram y crea items en TikiWiki
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('memory_limit', '512M');
set_time_limit(300);

session_start();

// Manejo de errores para evitar HTML en respuesta

function handleError($errno, $errstr, $errfile, $errline) {
    error_log("import.php: ERROR $errno - $errstr in $errfile:$errline");
    http_response_code(500);
    echo json_encode(['error' => "Error: $errstr"]);
    exit;
}
set_error_handler('handleError');

function rrmdir($dir) {
    if (is_dir($dir)) {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) {
                rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    } elseif (is_file($dir)) {
        unlink($dir);
    }
}

function handleException($exc) {
    error_log("import.php: EXCEPTION " . $exc->getMessage() . " in " . $exc->getFile() . ":" . $exc->getLine());
    http_response_code(500);
    echo json_encode(['error' => "Exception: " . $exc->getMessage()]);
    exit;
}
set_exception_handler('handleException');

// Iniciar sesión para CSRF y autenticación
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
} elseif (session_status() === PHP_SESSION_ACTIVE) {
    // Sesión ya iniciada, no hacer nada
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    error_log("import.php: NO AUTENTICADO");
    http_response_code(403);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

// Cargar config después de verificar auth
require_once 'bootstrap.php';

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
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
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

// Validar que ningún archivo del ZIP intente path traversal
$safeExtract = true;
$totalUncompressedSize = 0;
$maxDepth = 10;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entryName = $zip->getNameIndex($i);
    if (strpos($entryName, '..') !== false || str_starts_with($entryName, '/')) {
        $safeExtract = false;
        break;
    }
    $stat = $zip->statIndex($i);
    $totalUncompressedSize += $stat['size'] ?? 0;
}

if (!$safeExtract) {
    $zip->close();
    rrmdir($tempDir);
    echo json_encode(['error' => 'El archivo ZIP contiene rutas no válidas']);
    exit;
}

if ($zip->numFiles > 10000) {
    $zip->close();
    rrmdir($tempDir);
    echo json_encode(['error' => 'El ZIP contiene demasiados archivos (máx. 10000)']);
    exit;
}

if ($totalUncompressedSize > 200 * 1024 * 1024) {
    $zip->close();
    rrmdir($tempDir);
    echo json_encode(['error' => 'El tamaño total descomprimido excede el límite de 200 MB']);
    exit;
}

foreach (range(0, $zip->numFiles - 1) as $i) {
    $entryName = $zip->getNameIndex($i);
    $depth = substr_count(str_replace('\\', '/', $entryName), '/');
    if ($depth > $maxDepth) {
        $zip->close();
        rrmdir($tempDir);
        echo json_encode(['error' => 'El ZIP contiene estructuras de carpetas demasiado profundas']);
        exit;
    }
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
    rrmdir($tempDir);
    echo json_encode(['error' => 'No se encontró result.json en el export']);
    exit;
}

// Leer y parsear el JSON
$jsonContent = file_get_contents($jsonFile);
$data = json_decode($jsonContent, true);
if (!$data || !isset($data['messages'])) {
    rrmdir($tempDir);
    echo json_encode(['error' => 'Formato de export inválido']);
    exit;
}

$messages = $data['messages'];
$chatTitle = $data['name'] ?? 'Unknown Chat';
$chatId = $data['id'] ?? 0;

// Mapear topics creados y renombrados
$topics = [];
foreach ($messages as $msg) {
    if (($msg['type'] ?? '') === 'service') {
        if (($msg['action'] ?? '') === 'topic_created') {
            $topics[$msg['id']] = $msg['title'] ?? 'Topic ' . $msg['id'];
        } elseif (($msg['action'] ?? '') === 'topic_edit' && isset($msg['new_title'])) {
            // Buscar el topic original por reply_to_message_id si existe
            $replyTo = $msg['reply_to_message_id'] ?? null;
            if ($replyTo && isset($topics[$replyTo])) {
                $topics[$replyTo] = $msg['new_title'];
            }
        }
    }
}

// Obtener galleryId del tracker
$galleryId = TikiWikiClient::getMediaGalleryId((int) $trackerId) ?? 29;

// Indexar archivos una sola vez (evita escanear recursivamente por cada mensaje)
$fileIndex = [];
$dirIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
foreach ($dirIterator as $f) {
    if ($f->isFile()) {
        $fileIndex[$f->getFilename()] = $f->getPathname();
    }
}

// Procesar mensajes
$imported = 0;
$skipped = 0;
$mediaProcessed = 0;
$totalMessages = count($messages);
$processedCount = 0;

foreach ($messages as $i => $msg) {
    // Solo procesar mensajes regulares y service messages relevantes
    $msgType = $msg['type'] ?? 'message';
    if ($msgType !== 'message' && $msgType !== 'service') {
        continue;
    }

    $processedCount++;
    if ($processedCount % 10 === 0) {
        error_log("trackerGram: Importando mensaje $processedCount de $totalMessages...");
    }

    // Determinar topic
    $topicId = '';
    $topicTitle = '';
    $replyTo = $msg['reply_to_message_id'] ?? '';
    if ($replyTo && isset($topics[$replyTo])) {
        $topicId = $replyTo;
        $topicTitle = $topics[$replyTo];
    }

    // Variables para media
    $filePath = '';
    $fileName = '';
    $mediaCaption = '';
    $fileId = '';

    // Extraer nombre
    $from = $msg['from'] ?? $msg['actor'] ?? '';
    $fromParts = explode(' ', $from, 2);
    $firstName = $fromParts[0] ?? '';
    $lastName = $fromParts[1] ?? '';

    $userId = $msg['from_id'] ?? $msg['actor_id'] ?? '';
    $userId = str_replace('user', '', $userId);

    $date = is_numeric($msg['date'] ?? '') ? $msg['date'] : strtotime($msg['date'] ?? '');

    // Determinar texto y tipo según tipo de mensaje
    $text = '';
    $messageType = 'text';

    if ($msgType === 'message') {
        $messageType = 'text';
        $text = is_array($msg['text'] ?? '') ? json_encode($msg['text']) : ($msg['text'] ?? '');

        if (!empty($msg['photo'])) {
            $messageType = 'photo';
            $fileName = basename($msg['photo']);
            $mediaCaption = $msg['photo_caption'] ?? '';
            $filePath = $fileIndex[$fileName] ?? findFileInTempFallback($tempDir, $fileName);
        } elseif (!empty($msg['file'])) {
            $fileName = $msg['file_name'] ?? basename($msg['file']);
            $mediaType = $msg['media_type'] ?? '';
            if (strpos($msg['file'] ?? '', 'sticker') !== false || $mediaType === 'sticker') {
                $messageType = 'sticker';
            } elseif (strpos($msg['file'] ?? '', 'video') !== false || $mediaType === 'video_message') {
                $messageType = 'video';
            } elseif (strpos($msg['file'] ?? '', 'audio') !== false || $mediaType === 'audio') {
                $messageType = 'audio';
            } else {
                $messageType = 'document';
            }
            $filePath = $fileIndex[$fileName] ?? findFileInTempFallback($tempDir, $fileName);
        }

        if ($filePath && file_exists($filePath)) {
            $fileId = TikiWikiClient::uploadFile($filePath, $fileName, $galleryId);
            if ($fileId) {
                $mediaProcessed++;
            }
        }
    } else {
        // Service message
        $messageType = 'system';
        $action = $msg['action'] ?? '';
        $text = match ($action) {
            'topic_created' => '📌 Tema creado: ' . ($msg['title'] ?? ''),
            'topic_edit' => '✏️ Tema renombrado a: ' . ($msg['new_title'] ?? ''),
            'topic_closed' => '🔒 Tema cerrado',
            'topic_reopened' => '🔓 Tema reabierto',
            'pin_message', 'pinned_message' => '📌 Mensaje fijado por ' . $firstName,
            'create_group' => '🆕 Grupo creado',
            'invite_members', 'add_members' => '👤 ' . $firstName . ' agregó a: ' . implode(', ', $msg['members'] ?? []),
            'remove_members' => '🚫 ' . $firstName . ' eliminó a: ' . implode(', ', $msg['members'] ?? []),
            'joined' => '👤 ' . $firstName . ' se unió al grupo',
            'left' => '🚪 ' . $firstName . ' salió del grupo',
            'title_edit' => '✏️ Título cambiado a: ' . ($msg['title'] ?? ''),
            default => '🔔 ' . $action . ($msg['title'] ? ': ' . $msg['title'] : '')
        };
    }

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
        'text' => $text,
        'media_caption' => $mediaCaption ?? '',
        'date' => $date,
        'file_id' => $fileId ?? ''
    ];

    $result = importItemToTikiWiki($trackerId, $itemData);
    if ($result) {
        $imported++;
    } else {
        $skipped++;
    }
}

// Limpiar directorio temporal
rrmdir($tempDir);

echo json_encode([
    'success' => true,
    'imported' => $imported,
    'skipped' => $skipped,
    'media_processed' => $mediaProcessed,
    'topics_found' => count($topics)
]);

/**
 * Buscar archivo en carpeta temporal por nombre (fallback cuando el índice no encuentra coincidencia exacta)
 */
function findFileInTempFallback(string $tempDir, string $fileName): string {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
    foreach ($files as $f) {
        if ($f->getFilename() === $fileName || strpos($f->getFilename(), $fileName) !== false) {
            return $f->getPathname();
        }
    }
    return '';
}

/**
 * Enviar item a TikiWiki
 */
function importItemToTikiWiki(int $trackerId, array $data): bool {
    // Convertir formato de importación ($data) al mismo formato que processUpdate ($tikiData)
    $tikiData = [
        'message_id' => $data['message_id'],
        'chat_id' => $data['chat_id'],
        'chat_title' => $data['chat_title'] ?? '',
        'topic_id' => $data['topic_id'] ?? '',
        'topic_title' => $data['topic_title'] ?? '',
        'user_id' => $data['user_id'] ?? '',
        'username' => $data['username'] ?? '',
        'first_name' => $data['first_name'] ?? '',
        'last_name' => $data['last_name'] ?? '',
        'message_type' => $data['message_type'] ?? 'text',
        'text' => $data['text'] ?? '',
        'media_type' => '',
        'media_size' => '',
        'media_caption' => $data['media_caption'] ?? '',
        'location' => '',
        'uploaded_file_id' => $data['file_id'] ?? '',
        'date' => $data['date'] ?? time()
    ];

    $fields = MessageMapper::toWikiFields($tikiData);
    return TikiWikiClient::createTrackerItem($trackerId, $fields);
}