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
define('TIMEOUT_TIKIWIKI_UPLOAD', 30);

// Configuración de reintentos
define('RETRY_MAX_ATTEMPTS', 2);
define('RETRY_DELAY_MICROSECONDS', 100000); // 0.1 segundos

// Límite de descarga de media (20 MB)
define('MEDIA_DOWNLOAD_MAX_SIZE', 20 * 1024 * 1024);

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

    // Siempre al sistema
    error_log($logLine);

    // Siempre al archivo (con rotación si supera 10MB)
    $logFile = __DIR__ . '/debug.log';
    if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
        $rotated = __DIR__ . '/debug.log.old';
        @rename($logFile, $rotated);
    }

    if (@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX) === false) {
        $tempLog = sys_get_temp_dir() . '/trackergram_debug.log';
        @file_put_contents($tempLog, $logLine, FILE_APPEND | LOCK_EX);
    }
}
?>
