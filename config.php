<?php
/**
 * trackerGram - Configuración de la aplicación
 */

// Cargar variables de entorno desde .env
// Busca en orden de preferencia: config/.env → .env → ../.env
function loadEnv(): void {
    $possiblePaths = [
        __DIR__ . '/config/.env',
        __DIR__ . '/.env',
        __DIR__ . '/../.env',
    ];

    $envFile = null;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $envFile = $path;
            break;
        }
    }

    if (!$envFile) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Eliminar BOM UTF-8 si existe (EF BB BF al inicio del string)
        if (strlen($line) > 2 && ord($line[0]) === 0xEF && ord($line[1]) === 0xBB && ord($line[2]) === 0xBF) {
            $line = substr($line, 3);
        }

        // Ignorar comentarios
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parsear variable
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim($value));
            $_ENV[trim($name)] = trim($value);
        }
    }
}

loadEnv();

// ──────────────────────────────────────────────
// NOTA: Las credenciales de bots, wikis y trackers
// se configuran desde el panel de admin y se
// persisten en setup.json (multi-conexión).
// Las constantes legacy TELEGRAM_BOT_TOKEN,
// TIKIWIKI_API_URL, etc. fueron eliminadas.
// ──────────────────────────────────────────────

// Configuración de la aplicación
define('APP_NAME', 'trackerGram');
define('TRACKERGRAM_VERSION', 'v0.6.0');
define('TIMEZONE', 'America/Argentina/Buenos_Aires');

// Configuración de múltiples chats
// Lista de chat_ids permitidos separados por coma en .env (ALLOWED_CHAT_IDS=123,456,789)
// Vacío = procesar todos los chats
$allowedChatIds = getenv('ALLOWED_CHAT_IDS');
define('ALLOWED_CHAT_IDS', $allowedChatIds ? array_map('intval', explode(',', $allowedChatIds)) : []);

// Establecer timezone
date_default_timezone_set(TIMEZONE);

// Modo debug (desactivar en producción)
define('DEBUG_MODE', getenv('DEBUG_MODE') === 'true' ? true : false);

// Configuración de timeouts (en segundos)
define('TIMEOUT_TELEGRAM_API', 5);
define('TIMEOUT_TELEGRAM_DOWNLOAD', 10);
define('TIMEOUT_TIKIWIKI_API', 30);
define('TIMEOUT_TIKIWIKI_UPLOAD', 60);

// Configuración de reintentos
define('RETRY_MAX_ATTEMPTS', 2);
define('RETRY_DELAY_MICROSECONDS', 100000); // 0.1 segundos

// Límite de descarga de media (20 MB)
define('MEDIA_DOWNLOAD_MAX_SIZE', 20 * 1024 * 1024);

// Límite de tamaño total descomprimido del ZIP importado (500 MB)
define('MAX_ZIP_UNCOMPRESSED_SIZE', 500 * 1024 * 1024);

// Configuración de caché
define('CACHE_ENABLED', true);

// Procesamiento asíncrono: si es true, api.php encola eventos y worker.php los procesa
// Si es false (default), api.php procesa sincrónicamente (legacy)
define('ASYNC_PROCESSING', getenv('ASYNC_PROCESSING') === 'true' ? true : false);

// Directorio temporal propio (aislado de sys_get_temp_dir para shared hosting)
define('TEMP_DIR', __DIR__ . '/tmp');
if (!is_dir(TEMP_DIR)) {
    @mkdir(TEMP_DIR, 0700, true);
}

// Deshabilitar visualización de errores en producción
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 0);

/**
 * Función de logging - única función de logging del proyecto
 * 
 * Siempre escribe a error_log() del sistema (stdout/stderr en Docker, Apache error_log).
 * Escribe a debug.log solo si DEBUG_MODE=true o $force=true.
 * El archivo debug.log se rota automáticamente al superar 10MB.
 * 
 * @param string $message Mensaje a loguear
 * @param bool $force Si true, escribe a debug.log aunque DEBUG_MODE=false (para errores críticos)
 */
function log_message(string $message, bool $force = false): void
{
    $timestamp = date('[Y-m-d H:i:s] ');
    $logLine = $timestamp . $message . PHP_EOL;

    // Siempre al sistema (Apache error_log, stderr en CLI, syslog en Docker)
    error_log($logLine);

    // Solo a debug.log si DEBUG_MODE o force
    $debugMode = defined('DEBUG_MODE') && DEBUG_MODE;
    if (!$debugMode && !$force) {
        return;
    }

    $logFile = __DIR__ . '/debug.log';

    // ── Verificar encoding del archivo existente ──
    // Si el archivo tiene BOM UTF-16 (FF FE o FE FF) está corrupto.
    // Lo respaldamos y empezamos de nuevo.
    if (file_exists($logFile) && filesize($logFile) > 0) {
        $firstBytes = @file_get_contents($logFile, false, null, 0, 2);
        if ($firstBytes === "\xFF\xFE" || $firstBytes === "\xFE\xFF") {
            $backup = __DIR__ . '/debug.log.corrupted.' . date('Ymd_His');
            if (@rename($logFile, $backup)) {
                error_log("trackerGram: debug.log corrupto (BOM UTF-16) respaldado a " . basename($backup));
            } else {
                @file_put_contents($logFile, '');
            }
        }
    }

    // ── Rotación si supera 10MB ──
    if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
        $rotatedName = __DIR__ . '/debug.log.old';
        $rotated = @rename($logFile, $rotatedName);
        if (!$rotated) {
            @file_put_contents($logFile, '');
        }
    }

    // ── Escribir ──
    $written = @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        // Fallback 1: directorio temporal propio
        $tempLog = TEMP_DIR . '/debug_fallback.log';
        $written = @file_put_contents($tempLog, $logLine, FILE_APPEND | LOCK_EX);
    }
    if ($written === false) {
        // Fallback 2: último recurso, log al sistema con alerta
        error_log("trackerGram CRITICAL: No se pudo escribir log en {$logFile} ni en temp");
    }
}

/**
 * Convertir bytes a formato humano legible (ej: 20MB, 500MB)
 */
function formatBytes(int $bytes): string {
    if ($bytes >= 1024 * 1024 * 1024) {
        return round($bytes / (1024 * 1024 * 1024), 1) . 'GB';
    } elseif ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024)) . 'MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024) . 'KB';
    }
    return $bytes . 'B';
}

/**
 * Resolver un hostname a IP (IPv4 o IPv6).
 *
 * Primero intenta gethostbyname() (IPv4). Si no resuelve (el hostname puede
 * ser IPv6-only o no existir), intenta dns_get_record() con DNS_AAAA.
 *
 * @param string $host Hostname a resolver (ej: wiki.example.org)
 * @return string|null IP resuelta, o null si no se pudo resolver
 */
function resolveHostToIp(string $host): ?string
{
    // Caso 1: ya es una IP literal
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return $host;
    }

    // Caso 2: intentar resolución IPv4
    $ip = @gethostbyname($host);
    if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }

    // Caso 3: fallback a IPv6 (AAAA)
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_AAAA);
        if (!empty($records) && !empty($records[0]['ipv6'])) {
            return $records[0]['ipv6'];
        }
    }

    return null;
}
?>
