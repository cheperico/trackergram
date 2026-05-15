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
// Array de chat_ids permitidos (obtener el ID del grupo/grupo de Telegram)
// Ejemplo: define('ALLOWED_CHAT_IDS', [123456789, 987654321]);
// NOTA: Si está vacío, el sistema procesará todos los chats
define('ALLOWED_CHAT_IDS', []); // Vacío = procesar todos los chats

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
 * Usa error_log() que está disponible en todos los entornos
 */
function log_message(string $message): void
{
    // Siempre escribir al error log del sistema
    error_log('trackerGram: ' . $message);
    
    if (DEBUG_MODE) {
        $logFile = __DIR__ . '/debug.log';
        $timestamp = date('[Y-m-d H:i:s] ');
        $logLine = $timestamp . $message . PHP_EOL;
        
        // Intentar escribir directamente al archivo
        if (@file_put_contents($logFile, $logLine, FILE_APPEND) === false) {
            // Si falla, intentar en temp
            $tempLog = sys_get_temp_dir() . '/trackergram_debug.log';
            @file_put_contents($tempLog, $logLine, FILE_APPEND);
        }
    }
}
?>
