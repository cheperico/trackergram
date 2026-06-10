<?php
/**
 * trackerGram - Importación de exports de Telegram
 * Procesa archivos ZIP exportados desde Telegram y crea items en TikiWiki
 * 
 * Soporta tres modos:
 *   mode=extract  → Extrae ZIP, parsea JSON, crea NDJSON. Retorna total + extract_id.
 *   mode=process  → Procesa un lote desde NDJSON. Retorna progreso.
 *   (sin mode)    → Legacy: todo en una request (clásico).
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('memory_limit', '1024M');
set_time_limit(0);

session_start();

function handleError($errno, $errstr, $errfile, $errline) {
    log_message("import.php: ERROR $errno - $errstr in $errfile:$errline", true);
    http_response_code(500);
    echo json_encode(['error' => "Error: $errstr"]);
    exit;
}
set_error_handler('handleError');

function handleException($exc) {
    log_message("import.php: EXCEPTION " . $exc->getMessage() . " in " . $exc->getFile() . ":" . $exc->getLine(), true);
    http_response_code(500);
    echo json_encode(['error' => "Exception: " . $exc->getMessage()]);
    exit;
}
set_exception_handler('handleException');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    log_message("import.php: NO AUTENTICADO", true);
    http_response_code(403);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

require_once 'bootstrap.php';

$mode = $_POST['mode'] ?? 'full';

// ──────────────────────────────────────────────
// MODO EXTRACT: Subir ZIP, extraer, crear NDJSON
// ──────────────────────────────────────────────
if ($mode === 'extract') {
    handleExtract();
    exit;
}

// ──────────────────────────────────────────────
// MODO PROCESS: Procesar lote desde NDJSON
// ──────────────────────────────────────────────
if ($mode === 'process') {
    handleProcess();
    exit;
}

// ──────────────────────────────────────────────
// MODO FULL (legacy): Una sola request
// ──────────────────────────────────────────────
handleFull();
exit;

// ══════════════════════════════════════════════
// FUNCIONES
// ══════════════════════════════════════════════

/**
 * Extraer ZIP, parsear JSON, crear NDJSON + metadatos
 */
function handleExtract(): void
{
    global $tikiWikiClient, $messageMapper;

    $trackerId = $_POST['tracker_id'] ?? '';
    if (!$trackerId || !ctype_digit($trackerId)) {
        jsonError('Tracker ID inválido');
    }

    if (!isset($_FILES['export_file'])) {
        jsonError('No se recibió archivo');
    }

    $file = $_FILES['export_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonError('Error al subir archivo: ' . $file['error']);
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'zip') {
        jsonError('El archivo debe ser un ZIP');
    }

    $tempDir = sys_get_temp_dir() . '/trackergram_import_' . time();
    if (!mkdir($tempDir, 0777, true)) {
        jsonError('No se pudo crear directorio temporal');
    }

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        rrmdir($tempDir);
        jsonError('No se pudo abrir el archivo ZIP');
    }

    // Validar seguridad del ZIP
    $safeExtract = true;
    $badEntry = '';
    $totalUncompressedSize = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if (strpos($entryName, '..') !== false || str_starts_with($entryName, '/')) {
            $safeExtract = false;
            $badEntry = $entryName;
            break;
        }
        $stat = $zip->statIndex($i);
        $totalUncompressedSize += $stat['size'] ?? 0;
    }

    if (!$safeExtract) {
        log_message("trackerGram import: ZIP entry inválido: '{$badEntry}'", true);
        $zip->close(); rrmdir($tempDir);
        jsonError('El archivo ZIP contiene rutas no válidas');
    }

    if ($zip->numFiles > 20000) {
        $zip->close(); rrmdir($tempDir);
        jsonError('El ZIP contiene demasiados archivos (máx. 20000)');
    }

    if ($totalUncompressedSize > MAX_ZIP_UNCOMPRESSED_SIZE) {
        $zip->close(); rrmdir($tempDir);
        jsonError('El tamaño total descomprimido excede el límite de ' . formatBytes(MAX_ZIP_UNCOMPRESSED_SIZE));
    }

    foreach (range(0, $zip->numFiles - 1) as $i) {
        $entryName = $zip->getNameIndex($i);
        $depth = substr_count(str_replace('\\', '/', $entryName), '/');
        if ($depth > 10) {
            $zip->close(); rrmdir($tempDir);
            jsonError('El ZIP contiene estructuras de carpetas demasiado profundas');
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
        jsonError('No se encontró result.json en el export');
    }

    // Parsear JSON
    $jsonContent = @file_get_contents($jsonFile);
    if ($jsonContent === false || $jsonContent === '') {
        rrmdir($tempDir);
        jsonError('No se pudo leer result.json');
    }

    $data = @json_decode($jsonContent, true);
    if (!$data || !isset($data['messages'])) {
        rrmdir($tempDir);
        jsonError('Formato de export inválido: no se encontraron mensajes');
    }

    unset($jsonContent); // liberar RAM del string JSON

    $chatTitle = $data['name'] ?? 'Unknown Chat';
    $chatId = $data['id'] ?? 0;

    // Crear NDJSON (una línea por mensaje, para procesar sin recargar todo)
    $ndjsonFile = $tempDir . '/messages.ndjson';
    $ndjsonFp = @fopen($ndjsonFile, 'w');
    if (!$ndjsonFp) {
        rrmdir($tempDir);
        jsonError('No se pudo crear archivo temporal de mensajes');
    }

    $totalMessages = 0;
    foreach ($data['messages'] as $msg) {
        fwrite($ndjsonFp, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $totalMessages++;
    }
    fclose($ndjsonFp);
    unset($data['messages']); // liberar RAM del array de mensajes

    // Resolver topics (recorrer mensajes de servicio)
    $topics = [];
    $ndjsonFp = @fopen($ndjsonFile, 'r');
    if ($ndjsonFp) {
        while (($line = fgets($ndjsonFp)) !== false) {
            $msg = json_decode($line, true);
            if (!$msg) continue;
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
        fclose($ndjsonFp);
    }
    unset($data); // liberar resto de RAM

    // Guardar metadata
    file_put_contents($tempDir . '/metadata.json', json_encode([
        'chat_id' => $chatId,
        'chat_title' => $chatTitle,
        'topics' => $topics,
        'tracker_id' => (int) $trackerId,
        'total' => $totalMessages,
        'created' => time(),
    ]));

    // Indexar archivos multimedia
    $fileIndex = [];
    $dirIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
    foreach ($dirIterator as $f) {
        $fn = $f->getFilename();
        if ($f->isFile() && !in_array($fn, ['messages.ndjson', 'metadata.json', 'result.json', 'fileindex.json'])) {
            $fileIndex[$fn] = $f->getPathname();
        }
    }
    file_put_contents($tempDir . '/fileindex.json', json_encode($fileIndex));

    // Resolver gallery ID y persistirlo en metadata para que handleProcess lo re-use
    $galleryId = $tikiWikiClient->getMediaGalleryId((int) $trackerId);
    if ($galleryId !== null) {
        $metadata = json_decode(file_get_contents($tempDir . '/metadata.json'), true);
        $metadata['gallery_id'] = $galleryId;
        file_put_contents($tempDir . '/metadata.json', json_encode($metadata));
        log_message("trackerGram import: Gallery ID {$galleryId} persistido para tracker {$trackerId}");
    } else {
        log_message("trackerGram import: WARNING — No se pudo resolver galleryId para tracker {$trackerId}", true);
    }

    log_message("trackerGram import: Extract OK — {$totalMessages} mensajes, " . count($fileIndex) . " archivos");

    echo json_encode([
        'status' => 'extracted',
        'total' => $totalMessages,
        'extract_id' => basename($tempDir),
        'chat_title' => $chatTitle,
        'topics_found' => count($topics),
    ]);
}

/**
 * Procesar un lote de mensajes desde NDJSON
 */
function handleProcess(): void
{
    global $tikiWikiClient, $messageMapper;

    $extractId = $_POST['extract_id'] ?? '';
    $offset = (int) ($_POST['offset'] ?? 0);
    $batchSize = (int) ($_POST['batch_size'] ?? 100);
    if ($batchSize < 1) $batchSize = 100;
    if ($batchSize > 500) $batchSize = 500;

    if (!$extractId || !preg_match('/^trackergram_import_\d+$/', $extractId)) {
        jsonError('ID de extracción inválido');
    }

    $tempDir = sys_get_temp_dir() . '/' . $extractId;
    if (!is_dir($tempDir)) {
        log_message("trackerGram import: Extract not found: {$extractId}", true);
        jsonError('La sesión de importación expiró. Por favor, suba el archivo nuevamente.');
    }

    // Cargar metadata
    $metadataPath = $tempDir . '/metadata.json';
    if (!file_exists($metadataPath)) {
        rrmdir($tempDir);
        jsonError('Metadatos no encontrados');
    }
    $metadata = json_decode(file_get_contents($metadataPath), true);
    $chatTitle = $metadata['chat_title'] ?? 'Unknown';
    $chatId = $metadata['chat_id'] ?? 0;
    $topics = $metadata['topics'] ?? [];
    $total = $metadata['total'] ?? 0;
    $trackerId = $metadata['tracker_id'] ?? 0;

    // Cargar file index
    $fileIndexPath = $tempDir . '/fileindex.json';
    $fileIndex = [];
    if (file_exists($fileIndexPath)) {
        $fileIndex = json_decode(file_get_contents($fileIndexPath), true) ?? [];
    }

    // Abrir NDJSON
    $ndjsonFile = $tempDir . '/messages.ndjson';
    if (!file_exists($ndjsonFile)) {
        rrmdir($tempDir);
        jsonError('Archivo de mensajes no encontrado');
    }

    $fp = @fopen($ndjsonFile, 'r');
    if (!$fp) {
        jsonError('No se pudo abrir archivo de mensajes');
    }

    // Avanzar hasta el offset
    $lineNum = 0;
    while ($lineNum < $offset && !feof($fp)) {
        fgets($fp);
        $lineNum++;
    }

    // Procesar batch: usar galleryId de metadata (persistido desde extract) o resolver
    $galleryId = $metadata['gallery_id'] ?? $tikiWikiClient->getMediaGalleryId((int) $trackerId);
    if ($galleryId === null) {
        log_message("trackerGram import: NO HAY galleryId para tracker {$trackerId} — no se subirán archivos", true);
    }
    $imported = 0;
    $skipped = 0;
    $mediaProcessed = 0;
    $processed = 0;

    while ($processed < $batchSize && ($json = fgets($fp)) !== false) {
        $json = trim($json);
        if ($json === '') continue;

        $msg = json_decode($json, true);
        if (!$msg) {
            $skipped++;
            $processed++;
            continue;
        }

        $msgType = $msg['type'] ?? 'message';
        if ($msgType !== 'message' && $msgType !== 'service') {
            $processed++;
            continue;
        }

        // Resolver topic
        $topicId = '';
        $topicTitle = '';
        $replyTo = $msg['reply_to_message_id'] ?? '';
        if ($replyTo && isset($topics[$replyTo])) {
            $topicId = (string) $replyTo;
            $topicTitle = $topics[$replyTo];
        }

        // --- Propagación de caption para álbumes/grupos de fotos ---
        // En export JSON, cuando se mandan varias fotos con un texto,
        // solo la PRIMERA foto tiene el caption. Propagar a las siguientes.
        static $lastPhotoCaption = '';
        static $lastPhotoSender = '';
        static $lastPhotoTime = 0;

        if (!empty($msg['photo'])) {
            $hasOwnCaption = !empty($msg['photo_caption']) || !empty($msg['text']);
            if (!$hasOwnCaption && $lastPhotoCaption !== '') {
                $sameSender = ($msg['from_id'] ?? '') === $lastPhotoSender;
                $timeDiff = abs(($msg['date_unixtime'] ?? 0) - $lastPhotoTime);
                if ($sameSender && $timeDiff <= 1) {
                    $msg['photo_caption'] = $lastPhotoCaption;
                }
            }
            if ($hasOwnCaption) {
                if ($msg['photo_caption']) {
                    $lastPhotoCaption = $msg['photo_caption'];
                } elseif (is_string($msg['text'])) {
                    $lastPhotoCaption = $msg['text'];
                } elseif (is_array($msg['text'])) {
                    $parts = [];
                    foreach ($msg['text'] as $entity) {
                        if (isset($entity['text'])) {
                            $parts[] = $entity['text'];
                        }
                    }
                    $lastPhotoCaption = implode('', $parts);
                } else {
                    $lastPhotoCaption = '';
                }
                $lastPhotoSender = $msg['from_id'] ?? '';
                $lastPhotoTime = $msg['date_unixtime'] ?? 0;
            }
        } else {
            $lastPhotoCaption = '';
            $lastPhotoSender = '';
            $lastPhotoTime = 0;
        }

        // Subir media si existe
        $uploadedFileIds = [];
        if ($msgType === 'message') {
            $fileName = '';
            $filePath = '';

            if (!empty($msg['photo'])) {
                $fileName = basename($msg['photo']);
                $filePath = $fileIndex[$fileName] ?? findFileInTempFallback($tempDir, $fileName);
            } elseif (!empty($msg['file'])) {
                $fileName = $msg['file_name'] ?? basename($msg['file']);
                $filePath = $fileIndex[$fileName] ?? findFileInTempFallback($tempDir, $fileName);
            }

            if ($filePath && file_exists($filePath)) {
                $fileSize = @filesize($filePath);
                if ($fileSize !== false && $fileSize <= MEDIA_DOWNLOAD_MAX_SIZE) {
                    $caption = !empty($msg['photo']) ? ($msg['photo_caption'] ?? '') : ($msg['file_caption'] ?? ($msg['caption'] ?? ''));
                    $fileId = $tikiWikiClient->uploadFile($filePath, $fileName, $galleryId, 'import', $caption);
                    if ($fileId) {
                        $uploadedFileIds[] = $fileId;
                        $mediaProcessed++;
                    }
                } elseif ($fileSize !== false) {
                    log_message("trackerGram import: SKIP file too large ({$fileSize} bytes): {$fileName}", true);
                }
            }
        }

        // Crear item en TikiWiki
        $mediaUrl = '';
        if (!empty($uploadedFileIds)) {
            $baseUrl = rtrim(str_replace('/api/', '', TIKIWIKI_API_URL), '/');
            $mediaUrl = $baseUrl . '/tiki-download_file.php?fileId=' . $uploadedFileIds[0];
        }
        $context = [
            'chat_id' => $chatId,
            'chat_title' => $chatTitle,
            'topic_id' => $topicId,
            'topic_title' => $topicTitle,
            'file_ids' => $uploadedFileIds,
            'media_url' => $mediaUrl,
        ];

        $normalized = $messageMapper->fromExport($msg, $context);
        $fields = $messageMapper->toWikiFields($normalized);

        if ($tikiWikiClient->createTrackerItem((int) $trackerId, $fields)) {
            $imported++;
        } else {
            $skipped++;
        }

        $processed++;
    }

    fclose($fp);

    $newOffset = $offset + $processed;
    $more = $newOffset < $total;

    // Si es el último lote, limpiar
    if (!$more) {
        log_message("trackerGram import: Completado — {$total} mensajes, {$imported} importados, {$mediaProcessed} archivos");
        rrmdir($tempDir);
    }

    echo json_encode([
        'status' => 'processing',
        'imported' => $imported,
        'skipped' => $skipped,
        'media_processed' => $mediaProcessed,
        'offset' => $newOffset,
        'total' => $total,
        'more' => $more,
        'progress_pct' => $total > 0 ? round(($newOffset / $total) * 100, 1) : 100,
    ]);
}

/**
 * Modo legacy: todo en una request (para UI clásica)
 */
function handleFull(): void
{
    global $tikiWikiClient, $messageMapper;

    $trackerId = $_POST['tracker_id'] ?? '';
    if (!$trackerId || !ctype_digit($trackerId)) {
        jsonError('Tracker ID inválido');
    }

    if (!isset($_FILES['export_file'])) {
        jsonError('No se recibió archivo');
    }

    $file = $_FILES['export_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonError('Error al subir archivo: ' . $file['error']);
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'zip') {
        jsonError('El archivo debe ser un ZIP');
    }

    $tempDir = sys_get_temp_dir() . '/trackergram_import_' . time();
    if (!mkdir($tempDir, 0777, true)) {
        jsonError('No se pudo crear directorio temporal');
    }

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        rmdir($tempDir);
        jsonError('No se pudo abrir el archivo ZIP');
    }

    $safeExtract = true;
    $badEntry = '';
    $totalUncompressedSize = 0;
    $maxDepth = 10;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if (strpos($entryName, '..') !== false || str_starts_with($entryName, '/')) {
            $safeExtract = false;
            $badEntry = $entryName;
            break;
        }
        $stat = $zip->statIndex($i);
        $totalUncompressedSize += $stat['size'] ?? 0;
    }

    if (!$safeExtract) {
        log_message("trackerGram import: ZIP entry inválido (full): '{$badEntry}'", true);
        $zip->close(); rrmdir($tempDir);
        jsonError('El archivo ZIP contiene rutas no válidas');
    }

    if ($zip->numFiles > 20000) {
        $zip->close(); rrmdir($tempDir);
        jsonError('El ZIP contiene demasiados archivos (máx. 20000)');
    }

    if ($totalUncompressedSize > MAX_ZIP_UNCOMPRESSED_SIZE) {
        $zip->close(); rrmdir($tempDir);
        jsonError('El tamaño total descomprimido excede el límite de ' . formatBytes(MAX_ZIP_UNCOMPRESSED_SIZE));
    }

    foreach (range(0, $zip->numFiles - 1) as $i) {
        $entryName = $zip->getNameIndex($i);
        $depth = substr_count(str_replace('\\', '/', $entryName), '/');
        if ($depth > $maxDepth) {
            $zip->close(); rrmdir($tempDir);
            jsonError('El ZIP contiene estructuras de carpetas demasiado profundas');
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
        jsonError('No se encontró result.json en el export');
    }

    $jsonContent = file_get_contents($jsonFile);
    $data = json_decode($jsonContent, true);
    if (!$data || !isset($data['messages'])) {
        rrmdir($tempDir);
        jsonError('Formato de export inválido');
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

    $galleryId = $tikiWikiClient->getMediaGalleryId((int) $trackerId);
    if ($galleryId === null) {
        log_message("trackerGram import (full): NO HAY galleryId para tracker {$trackerId} — no se subirán archivos", true);
    }

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
        if ($processedCount % 100 === 0) {
            log_message("trackerGram: Importando mensaje $processedCount de $totalMessages...");
        }

        $topicId = '';
        $topicTitle = '';
        $replyTo = $msg['reply_to_message_id'] ?? '';
        if ($replyTo && isset($topics[$replyTo])) {
            $topicId = $replyTo;
            $topicTitle = $topics[$replyTo];
        }

        // --- Propagación de caption para álbumes/grupos de fotos ---
        static $lastPhotoCaption = '';
        static $lastPhotoSender = '';
        static $lastPhotoTime = 0;

        if (!empty($msg['photo'])) {
            $hasOwnCaption = !empty($msg['photo_caption']) || !empty($msg['text']);
            if (!$hasOwnCaption && $lastPhotoCaption !== '') {
                $sameSender = ($msg['from_id'] ?? '') === $lastPhotoSender;
                $timeDiff = abs(($msg['date_unixtime'] ?? 0) - $lastPhotoTime);
                if ($sameSender && $timeDiff <= 1) {
                    $msg['photo_caption'] = $lastPhotoCaption;
                }
            }
            if ($hasOwnCaption) {
                if ($msg['photo_caption']) {
                    $lastPhotoCaption = $msg['photo_caption'];
                } elseif (is_string($msg['text'])) {
                    $lastPhotoCaption = $msg['text'];
                } elseif (is_array($msg['text'])) {
                    $parts = [];
                    foreach ($msg['text'] as $entity) {
                        if (isset($entity['text'])) {
                            $parts[] = $entity['text'];
                        }
                    }
                    $lastPhotoCaption = implode('', $parts);
                } else {
                    $lastPhotoCaption = '';
                }
                $lastPhotoSender = $msg['from_id'] ?? '';
                $lastPhotoTime = $msg['date_unixtime'] ?? 0;
            }
        } else {
            $lastPhotoCaption = '';
            $lastPhotoSender = '';
            $lastPhotoTime = 0;
        }

        $uploadedFileIds = [];

        if ($msgType === 'message') {
            $filePath = '';
            $fileName = '';
            if (!empty($msg['photo'])) {
                $fileName = basename($msg['photo']);
                $filePath = $fileIndex[$fileName] ?? findFileInTempFallback($tempDir, $fileName);
            } elseif (!empty($msg['file'])) {
                $fileName = $msg['file_name'] ?? basename($msg['file']);
                $filePath = $fileIndex[$fileName] ?? findFileInTempFallback($tempDir, $fileName);
            }

            if ($filePath && file_exists($filePath)) {
                $fileSize = @filesize($filePath);
                if ($fileSize !== false && $fileSize <= MEDIA_DOWNLOAD_MAX_SIZE) {
                    $caption = !empty($msg['photo']) ? ($msg['photo_caption'] ?? '') : ($msg['file_caption'] ?? ($msg['caption'] ?? ''));
                    $fileId = $tikiWikiClient->uploadFile($filePath, $fileName, $galleryId, 'import', $caption);
                    if ($fileId) {
                        $uploadedFileIds[] = $fileId;
                        $mediaProcessed++;
                    }
                } elseif ($fileSize !== false) {
                    log_message("trackerGram import: SKIP file too large ({$fileSize} bytes): {$fileName}", true);
                }
            }
        }

        $mediaUrl = '';
        if (!empty($uploadedFileIds)) {
            $baseUrl = rtrim(str_replace('/api/', '', TIKIWIKI_API_URL), '/');
            $mediaUrl = $baseUrl . '/tiki-download_file.php?fileId=' . $uploadedFileIds[0];
        }
        $context = [
            'chat_id' => $chatId,
            'chat_title' => $chatTitle,
            'topic_id' => $topicId,
            'topic_title' => $topicTitle,
            'file_ids' => $uploadedFileIds,
            'media_url' => $mediaUrl,
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
}

// ── Funciones auxiliares ──

function rrmdir($dir): void {
    if (is_dir($dir)) {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) {
                rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        rmdir($dir);
    } elseif (is_file($dir)) {
        @unlink($dir);
    }
}

function findFileInTempFallback(string $tempDir, string $fileName): string {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
    foreach ($files as $f) {
        if ($f->getFilename() === $fileName || strpos($f->getFilename(), $fileName) !== false) {
            return $f->getPathname();
        }
    }
    return '';
}

function jsonError(string $message): void {
    log_message("trackerGram import: ERROR — {$message}", true);
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}
