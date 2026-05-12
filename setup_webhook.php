<?php
/**
 * Script para configurar automáticamente el webhook de Telegram.
 * Detecta la URL del servidor automáticamente y configura el webhook.
 * 
 * SEGURIDAD: Ejecutar solo desde línea de comandos o localhost.
 * Eliminar después de usar en producción.
 */

// Verificar que se ejecuta desde CLI o localhost
if (php_sapi_name() !== 'cli' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    http_response_code(403);
    die("Acceso denegado. Ejecutar desde línea de comandos.\n");
}

// Cargar configuración
require_once 'config.php';

// Detectar URL del servidor
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_path = dirname($_SERVER['SCRIPT_NAME']);
$webhook_url = $protocol . '://' . $host . $script_path . '/api.php';

// Obtener tokens
$bot_token = TELEGRAM_BOT_TOKEN;
$secret_token = getenv('TELEGRAM_WEBHOOK_SECRET');

if (!$bot_token) {
    die("Error: TELEGRAM_BOT_TOKEN no está configurado en .env\n");
}

if (!$secret_token) {
    echo "ADVERTENCIA: TELEGRAM_WEBHOOK_SECRET no está configurado en .env\n";
    echo "El webhook se configurará sin secret token (menos seguro)\n";
    echo "Se recomienda configurar TELEGRAM_WEBHOOK_SECRET en .env para mayor seguridad\n\n";
}

// Construir URL de la API de Telegram
$api_url = "https://api.telegram.org/bot{$bot_token}/setWebhook";

// Parámetros del webhook
$params = [
    'url' => $webhook_url
];

// Agregar secret token solo si está configurado
if ($secret_token) {
    $params['secret_token'] = $secret_token;
}

// Configurar cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url . '?' . http_build_query($params));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

// Ejecutar petición
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Mostrar resultado
echo "=== Configuración de Webhook de Telegram ===\n\n";
echo "URL detectada: $webhook_url\n";
echo "Secret Token: " . substr($secret_token, 0, 8) . "...\n\n";

if ($error) {
    echo "Error de cURL: $error\n";
    exit(1);
}

echo "Código HTTP: $http_code\n";
echo "Respuesta: $response\n\n";

// Decodificar respuesta
$result = json_decode($response, true);

if ($result && $result['ok']) {
    echo "✅ Webhook configurado exitosamente\n";
    echo "✅ URL del webhook: {$result['result']['url']}\n";
} else {
    echo "❌ Error al configurar webhook\n";
    if ($result) {
        echo "Descripción: {$result['description']}\n";
    }
    exit(1);
}

// Opcional: Verificar configuración actual
echo "\n=== Verificación del Webhook ===\n";
$verify_url = "https://api.telegram.org/bot{$bot_token}/getWebhookInfo";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $verify_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$verify_response = curl_exec($ch);
curl_close($ch);

$verify_result = json_decode($verify_response, true);

if ($verify_result && $verify_result['ok']) {
    echo "✅ URL del webhook: {$verify_result['result']['url']}\n";
    echo "✅ Secret Token configurado: " . (isset($verify_result['result']['secret_token']) ? 'Sí' : 'No') . "\n";
    echo "✅ Pendiente de actualización: " . ($verify_result['result']['pending_update_count'] ?? 0) . " mensajes\n";
}
