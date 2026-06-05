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

function handleError($errno, $errstr, $errfile, $errline) {
    log_message("import.php: ERROR $errno - $errstr in $errfile:$errline");
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
    log_message("import.php: EXCEPTION " . $exc->getMessage() . " in " . $exc->getFile() . ":" . $exc->getLine());
    http_response_code(500);
    echo json_encode(['error' => "Exception: " . $exc->getMessage()]);
    exit;
}
set_exception_handler('handleException');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
} elseif (session_status() === PHP_SESSION_ACTIVE) {
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    log_message("import.php: NO AUTENTICADO");
    http_response_code(403);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

require_once 'bootstrap.php';

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

$csrfToken = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

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

$topics = [];
foreach ($messages as $msg) {
    if (($msg['type'] ?? '') === 'service') {
        if (($msg['action'] ?? '') === 'topic_created') {
            $topics[$msg['id']] = $msg['title'] ?? 'Topic ' . $msg['id'];
        } elseif (($msg['action'] ?? '') === 'topic_edit' && isset($msg['new_title'])) {
            $replyTo = $msg['reply_to_message_id'] ?? null;
            if ($replyTo && isset($topics[$replyTo])) {
                $topics[$replyTo] = $msg['new_title'];
            }
        }
    }
}

$galleryId = $tikiWikiClient->getMediaGalleryId((int) $trackerId) ?? 29;

$fileIndex = [];
$dirIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
foreach ($dirIterator as $f) {
    if ($f->isFile()) {
        $fileIndex[$f->getFilename()] = $f->getPathname();
    }
}

$imported = 0;
$skipped = 0;
$mediaProcessed = 0;
$totalMessages = count($messages);
$processedCount = 0;

foreach ($messages as $i => $msg) {
    $msgType = $msg['type'] ?? 'message';
    if ($msgType !== 'message' && $msgType !== 'service') {
        continue;
    }

    $processedCount++;
    if ($processedCount % 10 === 0) {
        log_message("trackerGram: Importando mensaje $processedCount de $totalMessages...");
    }

    $topicId = '';
    $topicTitle = '';
    $replyTo = $msg['reply_to_message_id'] ?? '';
    if ($replyTo && isset($topics[$replyTo])) {
        $topicId = $replyTo;
        $topicTitle = $topics[$replyTo];
    }

    $filePath = '';
    $fileName = '';
    $fileId = '';

    if ($msgType === 'message') {
        if (!empty($msg['photo'])) {
            $fileName = basename($msg['photo']);
            $filePath = $fileIndex[$fileName] ?? findFileInTempFallback($tempDir, $fileName);
        } elseif (!empty($msg['file'])) {
            $fileName = $msg['file_name'] ?? basename($msg['file']);
            $filePath = $fileIndex[$fileName] ?? findFileInTempFallback($tempDir, $fileName);
        }

        if ($filePath && file_exists($filePath)) {
            $fileId = $tikiWikiClient->uploadFile($filePath, $fileName, $galleryId);
            if ($fileId) {
                $mediaProcessed++;
            }
        }
    }

    $context = [
        'chat_id' => $chatId,
        'chat_title' => $chatTitle,
        'topic_id' => $topicId,
        'topic_title' => $topicTitle,
        'file_id' => $fileId ?: null,
    ];

    $normalized = $messageMapper->fromExport($msg, $context);
    $fields = $messageMapper->toWikiFields($normalized);

    if ($tikiWikiClient->createTrackerItem((int) $trackerId, $fields)) {
        $imported++;
    } else {
        $skipped++;
    }
}

rrmdir($tempDir);

echo json_encode([
    'success' => true,
    'imported' => $imported,
    'skipped' => $skipped,
    'media_processed' => $mediaProcessed,
    'topics_found' => count($topics)
]);

function findFileInTempFallback(string $tempDir, string $fileName): string {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
    foreach ($files as $f) {
        if ($f->getFilename() === $fileName || strpos($f->getFilename(), $fileName) !== false) {
            return $f->getPathname();
        }
    }
    return '';
}
