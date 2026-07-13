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

// Output buffering: captura cualquier output no deseado (whitespace, BOM)
// para que no se mezcle con la respuesta JSON
ob_start();

session_start();

/**
 * Shutdown function: captura errores fatales que el error handler no puede atrapar
 * (parse errors, undefined classes, out of memory, etc.)
 * y devuelve JSON en vez del HTML de PHP.
 */
function shutdownJsonHandler(): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $msg = $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
        log_message("import.php: FATAL ERROR — {$msg}", true);
        // Limpiar cualquier salida previa (HTML parcial)
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Error interno del servidor', 'detail' => $msg]);
        exit;
    }
}
register_shutdown_function('shutdownJsonHandler');

function handleError($errno, $errstr, $errfile, $errline) {
    log_message("import.php: ERROR $errno - $errstr in $errfile:$errline", true);

    // Los warnings/notices no deberían matar el proceso — solo loguear y continuar
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($errno, $fatalTypes, true)) {
        return false; // dejar que PHP maneje el error según su configuración
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Error interno del servidor', 'detail' => "[$errno] $errstr in $errfile:$errline"]);
    exit;
}
set_error_handler('handleError');

function handleException($exc) {
    $cls = $exc instanceof TrackerGramException
        ? (new \ReflectionClass($exc))->getShortName()
        : get_class($exc);
    $logMsg = "import.php: [{$cls}] " . $exc->getMessage() . " in " . $exc->getFile() . ":" . $exc->getLine();
    log_message($logMsg, true);
    http_response_code($exc instanceof ImportException ? 400 : 500);
    echo json_encode(['error' => $exc->getMessage()]);
    exit;
}
set_exception_handler('handleException');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

// Asegurar Content-Type JSON para toda la respuesta
header('Content-Type: application/json; charset=utf-8');

/**
 * Convertir raw chat_id del export de Telegram al formato final de trackerGram.
 * Supergrupos/channels: prependea -100.
 * Grupos básicos/privados: se usa el raw tal cual.
 */
function rawChatIdToFinal(int|string $rawId, string $chatType): string
{
    $id = (int) $rawId;
    // Supergrupos y channels (públicos o privados) siempre tienen -100 en Bot API
    if ($id > 0 && (str_ends_with($chatType, 'supergroup') || str_ends_with($chatType, 'channel'))) {
        return '-100' . $id;
    }
    return (string) $id;
}

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
// MODO CANCEL: Cancelar importación y limpiar
// ──────────────────────────────────────────────
if ($mode === 'cancel') {
    $extractId = $_POST['extract_id'] ?? '';
    if ($extractId && preg_match('/^trackergram_import_\d+$/', $extractId)) {
        $tempDir = TEMP_DIR . '/' . $extractId;
        if (is_dir($tempDir)) {
            rrmdir($tempDir);
        }
        unset($_SESSION['import_creds_' . $extractId]);
    }
    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode(['success' => true]);
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
    $messageMapper = new MessageMapper();

    // Credenciales Tiki desde el formulario (multi-conexión)
    $tikiApiUrl = $_POST['tiki_api_url'] ?? '';
    $tikiApiToken = $_POST['tiki_api_token'] ?? '';
    $trackerId = $_POST['tracker_id'] ?? '';
    $fieldPrefix = $_POST['field_prefix'] ?? 'telegrammessage';
    $messageMapper->setFieldPrefix($fieldPrefix);
    if (!$trackerId || !ctype_digit($trackerId)) {
        jsonError('Tracker ID inválido');
    }

    // Crear TikiWikiClient local (obligatorio — sin fallback legacy)
    $localTikiClient = null;
    if ($tikiApiUrl !== '' && $tikiApiToken !== '') {
        $localTikiClient = new TikiWikiClient(
            apiUrl: $tikiApiUrl,
            token: $tikiApiToken,
            timeout: TIMEOUT_TIKIWIKI_API,
            uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
        );
        $localTikiClient->setFieldPrefix($fieldPrefix);

        // Auto-detectar field prefix desde el tracker
        $resolvedPrefix = $localTikiClient->resolveFieldPrefix((int) $trackerId);
        if ($resolvedPrefix !== $fieldPrefix) {
            log_message("import.php: Field prefix corregido de '{$fieldPrefix}' a '{$resolvedPrefix}' para tracker {$trackerId}");
            $fieldPrefix = $resolvedPrefix;
            $messageMapper->setFieldPrefix($resolvedPrefix);
            $localTikiClient->setFieldPrefix($resolvedPrefix);
        }
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

    $tempDir = TEMP_DIR . '/trackergram_import_' . time();
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
        // Bloquear path traversal (..), rutas absolutas Unix (/), Windows (X:\) y ADS (:)
        $normalized = str_replace('\\', '/', $entryName);
        if (
            strpos($entryName, '..') !== false
            || str_starts_with($normalized, '/')
            || preg_match('/^[a-zA-Z]:[\/\\\\]/', $entryName)  // X:\ o X:/
            || strpos($entryName, ':') !== false  // Alternate Data Streams
        ) {
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

    // Validación post-extracción: verificar que todos los archivos están dentro de $tempDir
    $realTempDir = realpath($tempDir);
    $allFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
    $pathOk = true;
    foreach ($allFiles as $extracted) {
        if ($extracted->isDir()) continue;
        $realPath = realpath($extracted->getPathname());
        if ($realPath === false || strpos($realPath, $realTempDir) !== 0) {
            $pathOk = false;
            log_message("trackerGram import: Path traversal detectado post-extracción: {$extracted->getPathname()}", true);
            break;
        }
    }
    if (!$pathOk) {
        rrmdir($tempDir);
        jsonError('El archivo ZIP contiene rutas no válidas (path traversal detectado)');
    }

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

    // Validar tamaño antes de cargar a memoria (previene OOM con JSON malicioso)
    if (filesize($jsonFile) > MAX_JSON_IMPORT_SIZE) {
        rrmdir($tempDir);
        jsonError('result.json excede el tamaño máximo de ' . formatBytes(MAX_JSON_IMPORT_SIZE));
    }

    // Parsear JSON
    $jsonContent = file_get_contents($jsonFile);
    if ($jsonContent === false || $jsonContent === '') {
        rrmdir($tempDir);
        jsonError('No se pudo leer result.json');
    }

    $data = json_decode($jsonContent, true);
    if (!$data || !isset($data['messages'])) {
        rrmdir($tempDir);
        jsonError('Formato de export inválido: no se encontraron mensajes');
    }

    unset($jsonContent); // liberar RAM del string JSON

    $chatTitle = $data['name'] ?? 'Unknown Chat';
    $rawId = $data['id'] ?? 0;
    $chatType = $data['type'] ?? '';
    $chatId = rawChatIdToFinal($rawId, $chatType);
    log_message("trackerGram import extract: rawId={$rawId}, chatType='{$chatType}', chatId='{$chatId}'");

    // ── Detectar migración grupo→supergrupo en el export ──
    // Cuando un grupo básico migra, el chat_id cambia. El root id del export
    // puede ser el ID del grupo básico o del supergrupo según cuándo se exportó.
    // Detectamos service messages migrate_to_supergroup/migrate_from_group
    // para unificar todos los mensajes bajo el chat_id FINAL (supergrupo con -100).
    $migrated = false;
    $migrationPointId = 0;        // message_id del service message migrate_to_supergroup
    $supergroupIdOverride = 0;    // si el msg trae el ID real del supergrupo

    // Primera pasada: detectar migración
    foreach ($data['messages'] as $scanMsg) {
        if (($scanMsg['type'] ?? '') !== 'service') continue;
        $action = $scanMsg['action'] ?? '';
        if ($action === 'migrate_to_supergroup') {
            $migrated = true;
            $migrationPointId = (int) ($scanMsg['id'] ?? 0);
            // Algunos exports incluyen el nuevo supergroup ID en el text del mensaje
            if (!empty($scanMsg['text']) && is_numeric($scanMsg['text'])) {
                $supergroupIdOverride = (int) $scanMsg['text'];
            } elseif (!empty($scanMsg['title']) && is_numeric($scanMsg['title'])) {
                $supergroupIdOverride = (int) $scanMsg['title'];
            }
            break; // solo nos interesa la primera migración
        } elseif ($action === 'migrate_from_group') {
            $migrated = true;
            // migrate_from_group confirma que estamos viendo la conversación post-migración
        }
    }

    // Si hay migración pero el chat_id NO es de supergrupo, forzar unificación
    if ($migrated) {
        if ($supergroupIdOverride > 0) {
            // Usar el supergroup ID real extraído del mensaje
            $chatId = '-100' . $supergroupIdOverride;
            log_message("trackerGram import: 🚚 Migración detectada — chat_id sobreescrito a {$chatId} desde migrate_to_supergroup");
        } elseif (!str_starts_with((string) $chatId, '-100') && $rawId > 0) {
            // El root id es del grupo básico, pero hubo migración → usar -100 + root id
            $chatId = '-100' . $rawId;
            log_message("trackerGram import: 🚚 Migración detectada — chat_id unificado a {$chatId} (tipo original: {$chatType})");
        } else {
            log_message("trackerGram import: 🚚 Migración detectada — chat_id {$chatId} ya es supergrupo, ok");
        }
    }

    // Crear NDJSON (una línea por mensaje, para procesar sin recargar todo)
    $ndjsonFile = $tempDir . '/messages.ndjson';
    $ndjsonFp = fopen($ndjsonFile, 'w');
    if (!$ndjsonFp) {
        $error = error_get_last();
        $msg = $error ? $error['message'] : 'unknown error';
        log_message("import.php: No se pudo crear NDJSON '{$ndjsonFile}': {$msg}", true);
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
    $ndjsonFp = fopen($ndjsonFile, 'r');
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

    // Determinar qué cliente Tiki usar (local obligatorio)
    if (!$localTikiClient) {
        jsonError('Credenciales Tiki no proporcionadas');
    }
    $activeTikiClient = $localTikiClient;

    // Guardar metadata (sin credenciales Tiki — van a session)
    $metaData = [
        'chat_id' => $chatId,
        'chat_title' => $chatTitle,
        'topics' => $topics,
        'tracker_id' => (int) $trackerId,
        'total' => $totalMessages,
        'created' => time(),
        'field_prefix' => $fieldPrefix,
        'migrated' => $migrated,
        'migration_point_id' => $migrationPointId,
    ];
    if ($migrated && $rawId > 0) {
        $metaData['old_chat_id'] = '-' . $rawId;
    }
    $metaWritten = file_put_contents($tempDir . '/metadata.json', json_encode($metaData));
    if ($metaWritten === false) {
        $error = error_get_last();
        $msg = $error ? $error['message'] : 'unknown error';
        log_message("import.php: No se pudo escribir metadata.json: {$msg}", true);
        rrmdir($tempDir);
        jsonError('No se pudo escribir metadata.json en directorio temporal');
    }
    
    // Guardar credenciales Tiki en session (no en disco)
    $importSessionKey = 'import_creds_' . basename($tempDir);
    $_SESSION[$importSessionKey] = [
        'tiki_api_url' => $tikiApiUrl ?: '',
        'tiki_api_token' => $tikiApiToken ?: '',
        'field_prefix' => $fieldPrefix,
    ];
    session_write_close(); // creds guardadas, liberar lock para cancel

    // Indexar archivos multimedia
    $fileIndex = [];
    $dirIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
    foreach ($dirIterator as $f) {
        $fn = $f->getFilename();
        if ($f->isFile() && !in_array($fn, ['messages.ndjson', 'metadata.json', 'result.json', 'fileindex.json'])) {
            $fileIndex[$fn] = $f->getPathname();
        }
    }
    $idxWritten = file_put_contents($tempDir . '/fileindex.json', json_encode($fileIndex));
    if ($idxWritten === false) {
        $error = error_get_last();
        $msg = $error ? $error['message'] : 'unknown error';
        log_message("import.php: No se pudo escribir fileindex.json: {$msg}", true);
    }

    // Resolver gallery ID y persistirlo en metadata para que handleProcess lo re-use
    $galleryId = $activeTikiClient->getMediaGalleryId((int) $trackerId);
    if ($galleryId !== null) {
        $metadata = json_decode(file_get_contents($tempDir . '/metadata.json'), true);
        $metadata['gallery_id'] = $galleryId;
        $metaWritten2 = file_put_contents($tempDir . '/metadata.json', json_encode($metadata));
        if ($metaWritten2 === false) {
            $error = error_get_last();
            $msg = $error ? $error['message'] : 'unknown error';
            log_message("import.php: No se pudo actualizar metadata.json con gallery_id: {$msg}", true);
        }
        log_message("trackerGram import: Gallery ID {$galleryId} persistido para tracker {$trackerId}");
    } else {
        log_message("trackerGram import: WARNING — No se pudo resolver galleryId para tracker {$trackerId}", true);
    }

    log_message("trackerGram import: Extract OK — {$totalMessages} mensajes, " . count($fileIndex) . " archivos");

    if (ob_get_level() > 0) { ob_clean(); }
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
    session_write_close(); // libera lock para que cancel pueda ejecutarse
    $messageMapper = new MessageMapper();

    $extractId = $_POST['extract_id'] ?? '';
    $offset = (int) ($_POST['offset'] ?? 0);
    $batchSize = (int) ($_POST['batch_size'] ?? 100);
    if ($batchSize < 1) $batchSize = 100;
    if ($batchSize > 500) $batchSize = 500;

    if (!$extractId || !preg_match('/^trackergram_import_\d+$/', $extractId)) {
        jsonError('ID de extracción inválido');
    }

    $tempDir = TEMP_DIR . '/' . $extractId;
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
    $oldChatId = $metadata['old_chat_id'] ?? null;

    // Crear TikiWikiClient local desde credenciales en session (obligatorio)
    $importSessionKey = 'import_creds_' . $extractId;
    $creds = $_SESSION[$importSessionKey] ?? [];
    $tikiApiUrl = $creds['tiki_api_url'] ?? '';
    $tikiApiToken = $creds['tiki_api_token'] ?? '';
    $fieldPrefix = $metadata['field_prefix'] ?? $creds['field_prefix'] ?? 'telegrammessage';
    $messageMapper->setFieldPrefix($fieldPrefix);
    $localTikiClient = null;
    if ($tikiApiUrl !== '' && $tikiApiToken !== '') {
        $localTikiClient = new TikiWikiClient(
            apiUrl: $tikiApiUrl,
            token: $tikiApiToken,
            timeout: TIMEOUT_TIKIWIKI_API,
            uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
        );
        $localTikiClient->setFieldPrefix($fieldPrefix);

        // Auto-detectar field prefix desde el tracker
        $resolvedPrefix = $localTikiClient->resolveFieldPrefix((int) $trackerId);
        if ($resolvedPrefix !== $fieldPrefix) {
            log_message("import.php process: Field prefix corregido de '{$fieldPrefix}' a '{$resolvedPrefix}'");
            $fieldPrefix = $resolvedPrefix;
            $messageMapper->setFieldPrefix($resolvedPrefix);
            $localTikiClient->setFieldPrefix($resolvedPrefix);
            // Actualizar metadata en disco para chunks subsiguientes
            $metadata['field_prefix'] = $resolvedPrefix;
            $metaWritten3 = file_put_contents($metadataPath, json_encode($metadata));
            if ($metaWritten3 === false) {
                $error = error_get_last();
                $msg = $error ? $error['message'] : 'unknown error';
                log_message("import.php process: No se pudo actualizar metadata.json con field_prefix: {$msg}", true);
            }
            // Actualizar session
            if (isset($creds)) {
                $creds['field_prefix'] = $resolvedPrefix;
                $_SESSION[$importSessionKey] = $creds;
            }
        }
    }
    if (!$localTikiClient) {
        jsonError('Credenciales Tiki no disponibles — la sesión expiró o se perdió. Re-subir el export.');
    }
    $activeTikiClient = $localTikiClient;

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

    $fp = fopen($ndjsonFile, 'r');
    if (!$fp) {
        $error = error_get_last();
        $msg = $error ? $error['message'] : 'unknown error';
        log_message("import.php: No se pudo abrir NDJSON '{$ndjsonFile}': {$msg}", true);
        jsonError('No se pudo abrir archivo de mensajes');
    }

    // Avanzar hasta el offset
    $lineNum = 0;
    while ($lineNum < $offset && !feof($fp)) {
        fgets($fp);
        $lineNum++;
    }

    // Procesar batch: usar galleryId de metadata (persistido desde extract) o resolver
    $galleryId = $metadata['gallery_id'] ?? $activeTikiClient->getMediaGalleryId((int) $trackerId);
    if ($galleryId === null) {
        log_message("trackerGram import: NO HAY galleryId para tracker {$trackerId} — no se subirán archivos", true);
    }
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $failed = 0;
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
                $photoCaption = $msg['photo_caption'] ?? '';
                if ($photoCaption !== '') {
                    $lastPhotoCaption = $photoCaption;
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
                    $fileId = $activeTikiClient->uploadFile($filePath, $fileName, $galleryId, 'import', $caption);
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
            $baseUrl = rtrim(str_replace('/api/', '', $tikiApiUrl), '/');
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

        // Ignorar reply_to si el mensaje original es un topic message (forum_topic_*)
        if ($normalized->replyToId !== '') {
            $replyTopicId = (int) $normalized->replyToId;
            if (isset($topics[$replyTopicId])) {
                log_message("trackerGram import: reply_to message_id={$replyTopicId} es topic message, ignorado");
                $normalized->replyToId = '';
            }
        }

        // Resolver reply_to: buscar el tracker itemId del mensaje original
        // y concatenar el texto del mensaje al que responde (Opción B)
        $replyToId = $normalized->replyToId;
        if ($replyToId !== '') {
            $replyMessageId = (int) $replyToId;
            if ($replyMessageId > 0) {
                $foundItemId = $activeTikiClient->findItemByMessageId(
                    (int) $trackerId,
                    $replyMessageId,
                    (int) $chatId
                );
                if ($foundItemId !== null) {
                    // Resuelto: intentar obtener el texto del mensaje original
                    $replyRef = '#' . $foundItemId;
                    $originalItem = $activeTikiClient->getTrackerItem((int) $trackerId, $foundItemId);
                    if ($originalItem) {
                        $textKey = 'field_' . $fieldPrefix . 'Text';
                        $originalText = $originalItem[$textKey] ?? $originalItem['fields'][$fieldPrefix . 'Text'] ?? '';
                        if ($originalText !== '') {
                            $truncated = mb_strlen($originalText) > 120
                                ? mb_substr($originalText, 0, 120) . '…'
                                : $originalText;
                            $replyRef .= ' - "' . $truncated . '"';
                        }
                    }
                    $normalized->replyToId = $replyRef;
                    log_message("trackerGram import: reply_to message_id={$replyMessageId} resuelto a itemId={$foundItemId}");
                } else {
                    log_message("trackerGram import: reply_to message_id={$replyMessageId} NO RESUELTO (aún no en tracker)");
                }
            }
        }

        $fields = $messageMapper->toWikiFields($normalized);

        // ── Deduplicación (chunked batch): ¿ya existe este mensaje? ──
        // Soportamos IDs negativos (mensajes pre-migración grupo→supergrupo)
        $messageIdInt = (int) $normalized->messageId;
        $dedupChatId = (int) $chatId;
        log_message("trackerGram import dedup: buscando message_id={$messageIdInt}, chat_id={$dedupChatId} en tracker={$trackerId}");
        $existingItemId = ($messageIdInt !== 0)
            ? $activeTikiClient->findItemByMessageId((int) $trackerId, $messageIdInt, $dedupChatId)
            : null;
        if ($existingItemId !== null) {
            log_message("trackerGram import dedup: ENCONTRADO itemId={$existingItemId} para message_id={$messageIdInt}");
        }

        if ($existingItemId === null && $messageIdInt !== 0 && !empty($oldChatId)) {
            $oldChatIdInt = (int) $oldChatId;
            log_message("trackerGram import dedup: retry con oldChatId={$oldChatIdInt}");
            $existingItemId = $activeTikiClient->findItemByMessageId((int) $trackerId, $messageIdInt, $oldChatIdInt);
            if ($existingItemId !== null) {
                log_message("trackerGram import: message_id={$messageIdInt} encontrado bajo el chat_id antiguo {$oldChatId} (migrado) — itemId={$existingItemId}");
            }
        }

        if ($existingItemId !== null) {
            $shouldUpdate = false;
            $edited = $normalized->editedDate;

            if ($edited !== '') {
                $existingItem = $activeTikiClient->getTrackerItem((int) $trackerId, $existingItemId);
                if ($existingItem) {
                    $storedEdited = $existingItem['field_' . $fieldPrefix . 'EditedDate']
                        ?? $existingItem['fields'][$fieldPrefix . 'EditedDate']
                        ?? '';
                    if ($storedEdited !== $edited) {
                        $shouldUpdate = true;
                        log_message("trackerGram import: message_id={$messageIdInt} editado ({$storedEdited} → {$edited}), actualizando");
                    }
                }
            }

            // Enriquecer polls que vinieron incompletos del webhook
            // El webhook almacena polls con messageType='other' y texto "no capturada en tiempo real"
            $msgType = $normalized->messageType;
            if (!$shouldUpdate && ($msgType === 'poll' || $msgType === 'quiz')) {
                if (!isset($existingItem)) {
                    $existingItem = $activeTikiClient->getTrackerItem((int) $trackerId, $existingItemId);
                }
                if ($existingItem) {
                    $storedType = $existingItem['field_' . $fieldPrefix . 'MessageType']
                        ?? $existingItem['fields'][$fieldPrefix . 'MessageType']
                        ?? '';
                    $storedText = $existingItem['field_' . $fieldPrefix . 'Text']
                        ?? $existingItem['fields'][$fieldPrefix . 'Text']
                        ?? '';
                    if ($storedType === 'other' && str_contains($storedText, 'no capturada en tiempo real')) {
                        $shouldUpdate = true;
                        log_message("trackerGram import: poll message_id={$messageIdInt} enriquecido con datos del export");
                    }
                }
            }

            if ($shouldUpdate) {
                $updateFields = $messageMapper->toWikiFieldsEdit($normalized);
                if ($activeTikiClient->updateTrackerItem((int) $trackerId, $existingItemId, $updateFields)) {
                    $updated++;
                } else {
                    $failed++;
                }
            } else {
                // ── Fill empty fields: rellenar campos vacíos que el webhook no pudo capturar ──
                if (!isset($existingItem)) {
                    $existingItem = $activeTikiClient->getTrackerItem((int) $trackerId, $existingItemId);
                }
                if ($existingItem) {
                    $fillFields = $messageMapper->getFillEmptyFields($normalized, $existingItem);
                    if (!empty($fillFields)) {
                        if ($activeTikiClient->updateTrackerItem((int) $trackerId, $existingItemId, $fillFields)) {
                            $updated++;
                            log_message("trackerGram import: message_id={$messageIdInt} campos rellenados: " . implode(', ', array_keys($fillFields)));
                        } else {
                            $failed++;
                        }
                    } else {
                        $skipped++;
                    }
                } else {
                    $skipped++;
                }
            }
        } else {
            // No existe → crear normalmente
            if ($activeTikiClient->createTrackerItem((int) $trackerId, $fields)) {
                $imported++;
            } else {
                $failed++;
            }
        }
        // Contar este mensaje como procesado para respetar el batch size
        $processed++;
    }

    // Determinar si hay más mensajes después de este batch
    // Cada línea NDJSON = 1 mensaje, y conocemos el total desde metadata
    $nextOffset = $offset + $processed;
    $hasMore = $nextOffset < $total;

    // Limpiar tempDir solo si es el último batch
    if (!$hasMore) {
        rrmdir($tempDir);
        // Limpiar credenciales de session
        unset($_SESSION['import_creds_' . $extractId]);
    }

    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode([
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'failed' => $failed,
        'skipped' => $skipped,
        'media_processed' => $mediaProcessed,
        'topics_found' => count($topics),
        'offset' => $nextOffset,
        'more' => $hasMore,
    ]);
}
/**
 * Modo legacy: todo en una request (para UI clásica)
 */
function handleFull(): void
{
    session_write_close(); // libera lock para que cancel pueda ejecutarse
    $messageMapper = new MessageMapper();

    // Credenciales Tiki desde el formulario (multi-conexión)
    $tikiApiUrl = $_POST['tiki_api_url'] ?? '';
    $tikiApiToken = $_POST['tiki_api_token'] ?? '';
    $trackerId = $_POST['tracker_id'] ?? '';
    $fieldPrefix = $_POST['field_prefix'] ?? 'telegrammessage';
    $messageMapper->setFieldPrefix($fieldPrefix);
    if (!$trackerId || !ctype_digit($trackerId)) {
        jsonError('Tracker ID inválido');
    }

    // Crear TikiWikiClient local (obligatorio — sin fallback legacy)
    $localClient = null;
    if ($tikiApiUrl !== '' && $tikiApiToken !== '') {
        $localClient = new TikiWikiClient(
            apiUrl: $tikiApiUrl,
            token: $tikiApiToken,
            timeout: TIMEOUT_TIKIWIKI_API,
            uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
        );
        $localClient->setFieldPrefix($fieldPrefix);

        // Auto-detectar field prefix desde el tracker
        $resolvedPrefix = $localClient->resolveFieldPrefix((int) $trackerId);
        if ($resolvedPrefix !== $fieldPrefix) {
            log_message("import.php full: Field prefix corregido de '{$fieldPrefix}' a '{$resolvedPrefix}'");
            $fieldPrefix = $resolvedPrefix;
            $messageMapper->setFieldPrefix($resolvedPrefix);
            $localClient->setFieldPrefix($resolvedPrefix);
        }
    }
    if (!$localClient) {
        jsonError('Credenciales Tiki no proporcionadas');
    }
    $activeTikiClient = $localClient;

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

    $tempDir = TEMP_DIR . '/trackergram_import_' . time();
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
        // Bloquear path traversal, rutas absolutas, Windows ADS
        $normalized = str_replace('\\', '/', $entryName);
        if (
            strpos($entryName, '..') !== false
            || str_starts_with($normalized, '/')
            || preg_match('/^[a-zA-Z]:[\/\\\\]/', $entryName)
            || strpos($entryName, ':') !== false
        ) {
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

    // Validación post-extracción: verificar que todos los archivos están dentro de $tempDir
    $realTempDir = realpath($tempDir);
    $allFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
    $pathOk = true;
    foreach ($allFiles as $extracted) {
        if ($extracted->isDir()) continue;
        $realPath = realpath($extracted->getPathname());
        if ($realPath === false || strpos($realPath, $realTempDir) !== 0) {
            $pathOk = false;
            log_message("trackerGram import: Path traversal detectado post-extracción (full): {$extracted->getPathname()}", true);
            break;
        }
    }
    if (!$pathOk) {
        rrmdir($tempDir);
        jsonError('El archivo ZIP contiene rutas no válidas (path traversal detectado)');
    }

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

    // Validar tamaño antes de cargar a memoria
    if (filesize($jsonFile) > MAX_JSON_IMPORT_SIZE) {
        rrmdir($tempDir);
        jsonError('result.json excede el tamaño máximo de ' . formatBytes(MAX_JSON_IMPORT_SIZE));
    }

    $jsonContent = file_get_contents($jsonFile);
    $data = json_decode($jsonContent, true);
    if (!$data || !isset($data['messages'])) {
        rrmdir($tempDir);
        jsonError('Formato de export inválido');
    }

    $messages = $data['messages'];
    $chatTitle = $data['name'] ?? 'Unknown Chat';
    $rawId = $data['id'] ?? 0;
    $chatType = $data['type'] ?? '';
    $chatId = rawChatIdToFinal($rawId, $chatType);

    // ── Detectar migración grupo→supergrupo (full import) ──
    $migrated = false;
    $supergroupIdOverride = 0;
    foreach ($messages as $scanMsg) {
        if (($scanMsg['type'] ?? '') !== 'service') continue;
        $action = $scanMsg['action'] ?? '';
        if ($action === 'migrate_to_supergroup') {
            $migrated = true;
            if (!empty($scanMsg['text']) && is_numeric($scanMsg['text'])) {
                $supergroupIdOverride = (int) $scanMsg['text'];
            } elseif (!empty($scanMsg['title']) && is_numeric($scanMsg['title'])) {
                $supergroupIdOverride = (int) $scanMsg['title'];
            }
            break;
        } elseif ($action === 'migrate_from_group') {
            $migrated = true;
        }
    }
    if ($migrated) {
        if ($supergroupIdOverride > 0) {
            $chatId = '-100' . $supergroupIdOverride;
            log_message("trackerGram import (full): 🚚 Migración — chat_id sobreescrito a {$chatId}");
        } elseif (!str_starts_with((string) $chatId, '-100') && $rawId > 0) {
            $chatId = '-100' . $rawId;
            log_message("trackerGram import (full): 🚚 Migración — chat_id unificado a {$chatId}");
        } else {
            log_message("trackerGram import (full): 🚚 Migración — chat_id {$chatId} ya es supergrupo");
        }
    }
    $oldChatId = ($migrated && $rawId > 0) ? '-' . $rawId : null;

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

    $galleryId = $activeTikiClient->getMediaGalleryId((int) $trackerId);
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
    $updated = 0;
    $skipped = 0;
    $failed = 0;
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
                $photoCaption = $msg['photo_caption'] ?? '';
                if ($photoCaption !== '') {
                    $lastPhotoCaption = $photoCaption;
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
                    $fileId = $activeTikiClient->uploadFile($filePath, $fileName, $galleryId, 'import', $caption);
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
            $baseUrl = rtrim(str_replace('/api/', '', $tikiApiUrl), '/');
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

        // Ignorar reply_to si el mensaje original es un topic message (forum_topic_*)
        if ($normalized->replyToId !== '') {
            $replyTopicId = (int) $normalized->replyToId;
            if (isset($topics[$replyTopicId])) {
                log_message("trackerGram import: reply_to message_id={$replyTopicId} es topic message, ignorado");
                $normalized->replyToId = '';
            }
        }

        // Resolver reply_to: buscar el tracker itemId del mensaje original
        // y concatenar el texto del mensaje al que responde (Opción B)
        $replyToId = $normalized->replyToId;
        if ($replyToId !== '') {
            $replyMessageId = (int) $replyToId;
            if ($replyMessageId > 0) {
                $foundItemId = $activeTikiClient->findItemByMessageId(
                    (int) $trackerId,
                    $replyMessageId,
                    (int) $chatId
                );
                if ($foundItemId !== null) {
                    // Resuelto: intentar obtener el texto del mensaje original
                    $replyRef = '#' . $foundItemId;
                    $originalItem = $activeTikiClient->getTrackerItem((int) $trackerId, $foundItemId);
                    if ($originalItem) {
                        $textKey = 'field_' . $fieldPrefix . 'Text';
                        $originalText = $originalItem[$textKey] ?? $originalItem['fields'][$fieldPrefix . 'Text'] ?? '';
                        if ($originalText !== '') {
                            $truncated = mb_strlen($originalText) > 120
                                ? mb_substr($originalText, 0, 120) . '…'
                                : $originalText;
                            $replyRef .= ' - "' . $truncated . '"';
                        }
                    }
                    $normalized->replyToId = $replyRef;
                    log_message("trackerGram import: reply_to message_id={$replyMessageId} resuelto a itemId={$foundItemId}");
                } else {
                    log_message("trackerGram import: reply_to message_id={$replyMessageId} NO RESUELTO (aún no en tracker)");
                }
            }
        }

        $fields = $messageMapper->toWikiFields($normalized);

        // ── Deduplicación (full import): ¿ya existe este mensaje? ──
        // Soportamos IDs negativos (mensajes pre-migración grupo→supergrupo)
        $messageIdInt = (int) $normalized->messageId;
        $existingItemId = ($messageIdInt !== 0)
            ? $activeTikiClient->findItemByMessageId((int) $trackerId, $messageIdInt, (int) $chatId)
            : null;

        if ($existingItemId === null && $messageIdInt !== 0 && !empty($oldChatId)) {
            $existingItemId = $activeTikiClient->findItemByMessageId((int) $trackerId, $messageIdInt, (int) $oldChatId);
            if ($existingItemId !== null) {
                log_message("trackerGram import (full): message_id={$messageIdInt} encontrado bajo el chat_id antiguo {$oldChatId} (migrado) — itemId={$existingItemId}");
            }
        }

        if ($existingItemId !== null) {
            $shouldUpdate = false;
            $edited = $normalized->editedDate;

            if ($edited !== '') {
                $existingItem = $activeTikiClient->getTrackerItem((int) $trackerId, $existingItemId);
                if ($existingItem) {
                    $storedEdited = $existingItem['field_' . $fieldPrefix . 'EditedDate']
                        ?? $existingItem['fields'][$fieldPrefix . 'EditedDate']
                        ?? '';
                    if ($storedEdited !== $edited) {
                        $shouldUpdate = true;
                        log_message("trackerGram import: message_id={$messageIdInt} editado ({$storedEdited} → {$edited}), actualizando");
                    }
                }
            }

            // Enriquecer polls que vinieron incompletos del webhook
            $msgType = $normalized->messageType;
            if (!$shouldUpdate && ($msgType === 'poll' || $msgType === 'quiz')) {
                if (!isset($existingItem)) {
                    $existingItem = $activeTikiClient->getTrackerItem((int) $trackerId, $existingItemId);
                }
                if ($existingItem) {
                    $storedType = $existingItem['field_' . $fieldPrefix . 'MessageType']
                        ?? $existingItem['fields'][$fieldPrefix . 'MessageType']
                        ?? '';
                    $storedText = $existingItem['field_' . $fieldPrefix . 'Text']
                        ?? $existingItem['fields'][$fieldPrefix . 'Text']
                        ?? '';
                    if ($storedType === 'other' && str_contains($storedText, 'no capturada en tiempo real')) {
                        $shouldUpdate = true;
                        log_message("trackerGram import: poll message_id={$messageIdInt} enriquecido con datos del export");
                    }
                }
            }

            if ($shouldUpdate) {
                $updateFields = $messageMapper->toWikiFieldsEdit($normalized);
                if ($activeTikiClient->updateTrackerItem((int) $trackerId, $existingItemId, $updateFields)) {
                    $updated++;
                } else {
                    $failed++;
                }
            } else {
                // ── Fill empty fields: rellenar campos vacíos que el webhook no pudo capturar ──
                if (!isset($existingItem)) {
                    $existingItem = $activeTikiClient->getTrackerItem((int) $trackerId, $existingItemId);
                }
                if ($existingItem) {
                    $fillFields = $messageMapper->getFillEmptyFields($normalized, $existingItem);
                    if (!empty($fillFields)) {
                        if ($activeTikiClient->updateTrackerItem((int) $trackerId, $existingItemId, $fillFields)) {
                            $updated++;
                            log_message("trackerGram import: message_id={$messageIdInt} campos rellenados: " . implode(', ', array_keys($fillFields)));
                        } else {
                            $failed++;
                        }
                    } else {
                        $skipped++;
                    }
                } else {
                    $skipped++;
                }
            }
        } else {
            // No existe → crear normalmente
            if ($activeTikiClient->createTrackerItem((int) $trackerId, $fields)) {
                $imported++;
            } else {
                $failed++;
            }
        }
    }

    rrmdir($tempDir);

    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode([
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'failed' => $failed,
        'skipped' => $skipped,
        'media_processed' => $mediaProcessed,
        'topics_found' => count($topics),
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
                if (!unlink($path)) {
                    log_message("rrmdir: No se pudo eliminar '{$path}'");
                }
            }
        }
        rmdir($dir);
    } elseif (is_file($dir)) {
        if (!unlink($dir)) {
            log_message("rrmdir: No se pudo eliminar archivo '{$dir}'");
        }
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
