<?php
/**
 * worker.php — Procesador async de eventos de Telegram
 * 
 * Lee los eventos buffereados por api.php (cuando ASYNC_PROCESSING=true)
 * y los procesa uno por uno: descarga media, sube a TikiWiki, crea items.
 * 
 * Uso:
 *   php worker.php                     # procesar todos los eventos pendientes
 *   php worker.php --max=10            # procesar solo 10 eventos
 *   php worker.php --forever           # modo loop infinito (cada 10s)
 *   php worker.php --once              # procesar un solo evento y salir
 * 
 * Se recomienda cron cada 1-2 minutos:
 *   * * * * * php /ruta/a/worker.php --max=50 >> /dev/null 2>&1
 * O cada minuto directamente:
 *   * * * * * php /ruta/a/worker.php >> /dev/null 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

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

$exitCode = 0;

do {
    $processed = processBatch($bufferDir, $maxEvents, $webhookHandler);
    if ($processed === 0) {
        if (!$forever) {
            break;
        }
        // En modo forever, esperar antes de reintentar
        sleep(10);
    }
} while ($forever);

exit($exitCode);

// ──────────────────────────────────────────────

/**
 * Procesar un lote de eventos buffereados
 */
function processBatch(string $bufferDir, int $maxEvents, WebhookHandler $webhookHandler): int
{
    $files = glob($bufferDir . '/event_*.json');
    if (empty($files)) {
        echo "[" . date('Y-m-d H:i:s') . "] No hay eventos pendientes\n";
        return 0;
    }

    // Ordenar por nombre (que incluye timestamp) para procesar en orden FIFO
    sort($files);

    $processed = 0;
    $success = 0;
    $failed = 0;

    foreach ($files as $file) {
        if ($processed >= $maxEvents) {
            break;
        }

        $baseName = basename($file);
        
        // Saltar archivos que ya están siendo procesados por otro worker
        $lockFile = $file . '.lock';
        $lockFp = @fopen($lockFile, 'x');
        if ($lockFp === false) {
            continue; // otro worker ya agarró este
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

        $update = json_decode($json, true);
        if (!$update) {
            echo "[" . date('Y-m-d H:i:s') . "] ERROR: JSON inválido en {$baseName}\n";
            @fclose($lockFp);
            @unlink($lockFile);
            @rename($file, $file . '.failed');
            $processed++;
            $failed++;
            continue;
        }

        // Procesar
        try {
            $startTime = microtime(true);
            $webhookHandler->processUpdate($update);
            $elapsed = round((microtime(true) - $startTime) * 1000);
            
            // Éxito: renombrar a .done
            @fclose($lockFp);
            @unlink($lockFile);
            @rename($file, $file . '.done');
            
            echo "[" . date('Y-m-d H:i:s') . "] OK {$baseName} ({$elapsed}ms)\n";
            $success++;
        } catch (Throwable $e) {
            @fclose($lockFp);
            @unlink($lockFile);
            @rename($file, $file . '.failed_' . time());
            
            echo "[" . date('Y-m-d H:i:s') . "] ERROR {$baseName}: {$e->getMessage()}\n";
            $failed++;
        }

        $processed++;
    }

    // Limpiar archivos .done viejos (>1 hora)
    cleanupDoneFiles($bufferDir, 3600);

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
