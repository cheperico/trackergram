<?php
/**
 * worker.php — Procesador async de eventos de Telegram (multi-conexión)
 * 
 * Lee los eventos buffereados por api.php (cuando ASYNC_PROCESSING=true)
 * y los procesa uno por uno. Cada evento incluye connection_slug para
 * usar las credenciales correctas de TikiWiki y Telegram.
 * 
 * Uso:
 *   php worker.php                     # procesar todos los eventos pendientes
 *   php worker.php --max=10            # procesar solo 10 eventos
 *   php worker.php --forever           # modo loop infinito (cada 10s)
 *   php worker.php --once              # procesar un solo evento y salir
 * 
 * Se recomienda cron cada 1-2 minutos:
 *   * * * * * php /ruta/a/worker.php --max=50 >> /dev/null 2>&1
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ConfigManager.php';

// ── Parámetros ──
$maxEvents = PHP_INT_MAX;
$forever = false;
for ($i = 1; $i < $argc; $i++) {
    if (str_starts_with($argv[$i], '--max=')) {
        $maxEvents = (int) substr($argv[$i], 6);
    } elseif ($argv[$i] === '--forever') {
        $forever = true;
    } elseif ($argv[$i] === '--once') {
        $maxEvents = 1;
    }
}

$bufferDir = TEMP_DIR . '/buffer';
if (!is_dir($bufferDir)) {
    echo "[" . date('Y-m-d H:i:s') . "] Buffer dir no existe: {$bufferDir}\n";
    exit(0);
}

$configManager = new ConfigManager();
$messageMapper = new MessageMapper();
$exitCode = 0;

do {
    // Limpiar archivos .done viejos siempre (incluso si no hay eventos)
    cleanupDoneFiles($bufferDir, 3600);

    $processed = processBatch($bufferDir, $maxEvents, $configManager, $messageMapper);
    if ($processed === 0) {
        if (!$forever) {
            break;
        }
        sleep(10);
    }
} while ($forever);

exit($exitCode);

// ──────────────────────────────────────────────

/**
 * Procesar un lote de eventos buffereados
 */
function processBatch(string $bufferDir, int $maxEvents, ConfigManager $configManager, MessageMapper $messageMapper): int
{
    $files = glob($bufferDir . '/event_*.json');
    if (empty($files)) {
        echo "[" . date('Y-m-d H:i:s') . "] No hay eventos pendientes\n";
        return 0;
    }

    // FIFO por nombre (incluye timestamp)
    sort($files);

    $processed = 0;
    $success = 0;
    $failed = 0;

    foreach ($files as $file) {
        if ($processed >= $maxEvents) {
            break;
        }

        $baseName = basename($file);

        // Lock para evitar duplicados entre workers
        $lockFile = $file . '.lock';
        $lockFp = @fopen($lockFile, 'x');
        if ($lockFp === false) {
            continue;
        }
        flock($lockFp, LOCK_EX);

        // Leer evento
        $json = @file_get_contents($file);
        if ($json === false) {
            echo "[" . date('Y-m-d H:i:s') . "] ERROR: No se pudo leer {$baseName}\n";
            @fclose($lockFp);
            @unlink($lockFile);
            $processed++;
            $failed++;
            continue;
        }

        $bufferData = json_decode($json, true);
        if (!$bufferData || !isset($bufferData['connection_slug']) || !isset($bufferData['update'])) {
            echo "[" . date('Y-m-d H:i:s') . "] ERROR: Formato inválido en {$baseName}\n";
            @fclose($lockFp);
            @unlink($lockFile);
            @rename($file, $file . '.failed');
            $processed++;
            $failed++;
            continue;
        }

        $connectionSlug = $bufferData['connection_slug'];
        $update = $bufferData['update'];

        // Buscar conexión por slug
        $connection = $configManager->getConnection($connectionSlug);

        if (!$connection || empty($connection['enabled'])) {
            echo "[" . date('Y-m-d H:i:s') . "] SKIP {$baseName}: conexión '{$connectionSlug}' no encontrada o deshabilitada\n";
            @fclose($lockFp);
            @unlink($lockFile);
            @rename($file, $file . '.failed');
            $processed++;
            $failed++;
            continue;
        }

        // Procesar con handler per-conexión
        try {
            $startTime = microtime(true);

            $tikiClient = new TikiWikiClient(
                apiUrl: $connection['tiki_api_url'],
                token: $connection['tiki_api_token'],
                timeout: TIMEOUT_TIKIWIKI_API,
                uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
            );
            $tikiClient->setFieldPrefix($connection['field_prefix'] ?? 'telegrammessage');
            $messageMapper->setFieldPrefix($connection['field_prefix'] ?? 'telegrammessage');

            // Auto-detectar field prefix desde el tracker (corrige prefix mal guardado)
            $trackerId = (int) $connection['tracker_id'];
            if ($trackerId > 0) {
                $resolvedPrefix = $tikiClient->resolveFieldPrefix($trackerId);
                if ($resolvedPrefix !== $messageMapper->getFieldPrefix()) {
                    echo "[" . date('Y-m-d H:i:s') . "] Field prefix corregido de '{$messageMapper->getFieldPrefix()}' a '{$resolvedPrefix}' para conexión '{$connectionSlug}'\n";
                    $messageMapper->setFieldPrefix($resolvedPrefix);
                    $tikiClient->setFieldPrefix($resolvedPrefix);
                    $configManager->updateConnectionFields($connectionSlug, ['field_prefix' => $resolvedPrefix]);
                }
            }

            $tgClient = new TelegramClient(
                botToken: $connection['bot_token']
            );
            $handler = new WebhookHandler(
                tikiWikiClient: $tikiClient,
                telegramClient: $tgClient,
                messageMapper: $messageMapper,
                trackerId: (int) $connection['tracker_id']
            );

            $handler->processUpdate($update);

            $elapsed = round((microtime(true) - $startTime) * 1000);

            @fclose($lockFp);
            @unlink($lockFile);
            @rename($file, $file . '.done');

            echo "[" . date('Y-m-d H:i:s') . "] OK {$baseName} ({$connectionSlug}, {$elapsed}ms)\n";
            $success++;
        } catch (Throwable $e) {
            @fclose($lockFp);
            @unlink($lockFile);
            @rename($file, $file . '.failed_' . time());

            echo "[" . date('Y-m-d H:i:s') . "] ERROR {$baseName} ({$connectionSlug}): {$e->getMessage()}\n";
            $failed++;
        }

        $processed++;
    }

    $total = $success + $failed;
    echo "[" . date('Y-m-d H:i:s') . "] Resumen: {$total} procesados, {$success} ok, {$failed} errores\n";
    return $processed;
}

/**
 * Limpiar archivos .done viejos para que no se acumulen
 */
function cleanupDoneFiles(string $bufferDir, int $maxAgeSeconds): void
{
    $files = glob($bufferDir . '/event_*.json.done');
    if (empty($files)) {
        return;
    }
    $now = time();
    foreach ($files as $file) {
        if ($now - filemtime($file) > $maxAgeSeconds) {
            @unlink($file);
        }
    }
}
