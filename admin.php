<?php
/**
 * Interfaz de administración de trackerGram
 * Permite configurar credenciales y actualizar el webhook de Telegram
 */

session_start();
require_once 'config.php';

// Función para cargar variables de entorno
function loadEnv() {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        return [];
    }
    
    $env = [];
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $env[trim($name)] = trim($value);
    }
    return $env;
}

// Función para guardar variables de entorno
function saveEnv($env) {
    $envFile = __DIR__ . '/.env';
    $content = "# trackerGram - Variables de Entorno\n";
    $content .= "# Generado automáticamente por admin.php\n\n";
    
    foreach ($env as $key => $value) {
        $content .= "$key=$value\n";
    }
    
    return file_put_contents($envFile, $content) !== false;
}

// Verificar autenticación
function checkAuth() {
    $env = loadEnv();
    $username = $env['ADMIN_USERNAME'] ?? 'admin';
    $password = $env['ADMIN_PASSWORD'] ?? '';
    
    if (!isset($_SESSION['authenticated'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_username']) && isset($_POST['login_password'])) {
            if ($_POST['login_username'] === $username && $_POST['login_password'] === $password) {
                $_SESSION['authenticated'] = true;
                return true;
            } else {
                return false;
            }
        }
        return false;
    }
    return true;
}

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Procesar formulario de configuración
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    $env = loadEnv();
    
    $env['TELEGRAM_BOT_TOKEN'] = $_POST['telegram_bot_token'] ?? '';
    $env['TELEGRAM_WEBHOOK_SECRET'] = $_POST['telegram_webhook_secret'] ?? '';
    $env['TIKIWIKI_API_URL'] = $_POST['tikiwiki_api_url'] ?? '';
    $env['TIKIWIKI_TOKEN'] = $_POST['tikiwiki_token'] ?? '';
    $env['TIKIWIKI_TRACKER_ID'] = $_POST['tikiwiki_tracker_id'] ?? '1';
    
    if (saveEnv($env)) {
        $success = "Configuración guardada exitosamente";
    } else {
        $error = "Error al guardar configuración";
    }
}

// Procesar actualización de webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_webhook') {
    $env = loadEnv();
    $botToken = $env['TELEGRAM_BOT_TOKEN'] ?? '';
    $secretToken = $env['TELEGRAM_WEBHOOK_SECRET'] ?? '';
    
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $webhookUrl = $protocol . '://' . $host . $scriptPath . '/api.php';
    
    $apiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook";
    $params = [
        'url' => $webhookUrl,
        'secret_token' => $secretToken
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result && $result['ok']) {
        $success = "Webhook actualizado exitosamente: {$webhookUrl}";
    } else {
        $error = "Error al actualizar webhook: " . ($result['description'] ?? 'Error desconocido');
    }
}

// Cargar configuración actual
$env = loadEnv();
$config = [
    'telegram_bot_token' => $env['TELEGRAM_BOT_TOKEN'] ?? '',
    'telegram_webhook_secret' => $env['TELEGRAM_WEBHOOK_SECRET'] ?? '',
    'tikiwiki_api_url' => $env['TIKIWIKI_API_URL'] ?? '',
    'tikiwiki_token' => $env['TIKIWIKI_TOKEN'] ?? '',
    'tikiwiki_tracker_id' => $env['TIKIWIKI_TRACKER_ID'] ?? '1'
];

// Mostrar login si no está autenticado
if (!checkAuth()) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>trackerGram - Login</title>
    </head>
    <body>
        <h1>trackerGram - Login</h1>
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <p style="color: red;">Usuario o contraseña incorrectos</p>
        <?php endif; ?>
        <form method="post">
            <label>Usuario: <input type="text" name="login_username" required></label><br><br>
            <label>Contraseña: <input type="password" name="login_password" required></label><br><br>
            <button type="submit">Iniciar sesión</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>trackerGram - Administración</title>
</head>
<body>
    <h1>trackerGram - Administración</h1>
    <p><a href="?action=logout">Cerrar sesión</a></p>
    
    <?php if (isset($success)): ?>
        <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    
    <h2>Configuración Actual</h2>
    <form method="post">
        <input type="hidden" name="action" value="save_config">
        
        <label>Telegram Bot Token:</label><br>
        <input type="text" name="telegram_bot_token" value="<?php echo htmlspecialchars($config['telegram_bot_token']); ?>" size="50"><br><br>
        
        <label>Telegram Webhook Secret:</label><br>
        <input type="text" name="telegram_webhook_secret" value="<?php echo htmlspecialchars($config['telegram_webhook_secret']); ?>" size="50"><br><br>
        
        <label>TikiWiki API URL:</label><br>
        <input type="text" name="tikiwiki_api_url" value="<?php echo htmlspecialchars($config['tikiwiki_api_url']); ?>" size="50"><br><br>
        
        <label>TikiWiki Token:</label><br>
        <input type="text" name="tikiwiki_token" value="<?php echo htmlspecialchars($config['tikiwiki_token']); ?>" size="50"><br><br>
        
        <label>TikiWiki Tracker ID:</label><br>
        <input type="text" name="tikiwiki_tracker_id" value="<?php echo htmlspecialchars($config['tikiwiki_tracker_id']); ?>" size="10"><br><br>
        
        <button type="submit">Guardar Configuración</button>
    </form>
    
    <h2>Webhook de Telegram</h2>
    <p>URL del webhook actual: 
        <?php
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        echo htmlspecialchars($protocol . '://' . $host . $scriptPath . '/api.php');
        ?>
    </p>
    <form method="post">
        <input type="hidden" name="action" value="update_webhook">
        <button type="submit">Actualizar Webhook</button>
    </form>
</body>
</html>
