<?php
/**
 * trackerGram - Configuración de la aplicación
 */

// Cargar variables de entorno desde .env
function loadEnv($file = __DIR__ . '/.env') {
    $envFile = $file;
    if (!file_exists($envFile)) {
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

// Token del bot de Telegram (obtenido de @BotFather)
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/');

// Secret token para webhook de Telegram (opcional pero recomendado)
define('TELEGRAM_WEBHOOK_SECRET', getenv('TELEGRAM_WEBHOOK_SECRET') ?: '');

// Configuración de TikiWiki
define('TIKIWIKI_API_URL', getenv('TIKIWIKI_API_URL') ?: '');
define('TIKIWIKI_TOKEN', getenv('TIKIWIKI_TOKEN') ?: '');
define('TIKIWIKI_TRACKER_ID', getenv('TIKIWIKI_TRACKER_ID') ?: 1);

// Configuración de la aplicación
define('APP_NAME', 'trackerGram');
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

// Deshabilitar visualización de errores en producción
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 0);

/**
 * Función de logging - única función de logging del proyecto
 * 
 * Siempre escribe a error_log() del sistema y al archivo debug.log.
 * DEBUG_MODE=true activa logs adicionales en los puntos que llaman a esta función.
 * El archivo debug.log se rota automáticamente al superar 10MB.
 */
function log_message(string $message): void
{
    $timestamp = date('[Y-m-d H:i:s] ');
    $logLine = $timestamp . $message . PHP_EOL;

    // Siempre al sistema (Apache error_log, stderr en CLI, syslog en Docker)
    error_log($logLine);

    $logFile = __DIR__ . '/debug.log';

    // ── Verificar encoding del archivo existente ──
    // Si el archivo tiene BOM UTF-16 (FF FE o FE FF) está corrupto.
    // Lo respaldamos y empezamos de nuevo.
    $fileOk = true;
    if (file_exists($logFile) && filesize($logFile) > 0) {
        $firstBytes = @file_get_contents($logFile, false, null, 0, 2);
        if ($firstBytes === "\xFF\xFE" || $firstBytes === "\xFE\xFF") {
            $backup = __DIR__ . '/debug.log.corrupted.' . date('Ymd_His');
            if (@rename($logFile, $backup)) {
                error_log("trackerGram: debug.log corrupto (BOM UTF-16) respaldado a " . basename($backup));
            } else {
                // No se pudo renombrar — truncar
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
        // Fallback 1: temp del sistema
        $tempLog = sys_get_temp_dir() . '/trackergram_debug.log';
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
?>
