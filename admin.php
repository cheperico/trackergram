<?php
/**
 * Interfaz de administración de trackerGram
 * Permite configurar credenciales y actualizar el webhook de Telegram
 */

// Mostrar errores solo si DEBUG_MODE está activo
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Configuración segura de sesión
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');

session_start();

// Timeout de sesión (30 minutos)
$sessionTimeout = 30 * 60; // 30 minutos en segundos
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $sessionTimeout)) {
    session_unset();
    session_destroy();
    header('Location: admin.php');
    exit;
}
$_SESSION['last_activity'] = time();

// Regenerar ID de sesión periódicamente para prevenir fixation
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 15 * 60) { // Cada 15 minutos
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

require_once 'bootstrap.php';

// Función para generar y validar CSRF tokens
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        die('Error: Token CSRF inválido. Por favor recargue la página e intente nuevamente.');
    }
}

// Rate limiting para login (por IP, no por sesión)
function checkRateLimit() {
    $maxAttempts = 5;
    $lockoutTime = 15 * 60;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateFile = sys_get_temp_dir() . '/tg_admin_rate_' . md5($ip);
    
    $data = ['attempts' => 0, 'first_attempt' => time()];
    if (file_exists($rateFile)) {
        $content = @file_get_contents($rateFile);
        if ($content) {
            $data = json_decode($content, true) ?? $data;
        }
    }
    
    if (time() - $data['first_attempt'] > $lockoutTime) {
        $data['attempts'] = 0;
        $data['first_attempt'] = time();
    }
    
    if ($data['attempts'] >= $maxAttempts) {
        $remainingTime = $lockoutTime - (time() - $data['first_attempt']);
        die("Demasiados intentos de login. Por favor espere " . ceil($remainingTime / 60) . " minutos antes de intentar nuevamente.");
    }
}

function incrementFailedLogin() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateFile = sys_get_temp_dir() . '/tg_admin_rate_' . md5($ip);
    $data = ['attempts' => 0, 'first_attempt' => time()];
    if (file_exists($rateFile)) {
        $content = @file_get_contents($rateFile);
        if ($content) {
            $data = json_decode($content, true) ?? $data;
        }
    }
    $data['attempts']++;
    @file_put_contents($rateFile, json_encode($data));
}

function resetFailedLogin() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateFile = sys_get_temp_dir() . '/tg_admin_rate_' . md5($ip);
    $data = ['attempts' => 0, 'first_attempt' => time()];
    @file_put_contents($rateFile, json_encode($data));
}

// Función para generar URL de webhook automática del servidor actual
function generateWebhookUrl() {
    $protocol = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        $protocol = 'https';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $scriptPath = rtrim($scriptPath, '/');

    $prefix = $_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '';
    $prefix = rtrim($prefix, '/');

    $url = $protocol . '://' . $host . $prefix . $scriptPath;
    $url = rtrim($url, '/');
    if (!str_ends_with($url, '/api.php')) {
        $url .= '/api.php';
    }
    return $url;
}

// Validación de inputs para prevenir inyección
function validateInput($input, $type = 'string') {
    if ($input === null) {
        return '';
    }
    
    $input = trim($input);
    
    switch ($type) {
        case 'url':
            // Validar URL (permitir http, https, sin espacios)
            if (!preg_match('/^https?:\/\/[^\s]+$/', $input)) {
                die('Error: URL inválida. Debe comenzar con http:// o https:// y no contener espacios.');
            }
            break;
            
        case 'token':
            // Validar token (alfanumérico, guiones bajos, guiones, dos puntos para Telegram)
            if (!preg_match('/^[a-zA-Z0-9_:-]+$/', $input)) {
                die('Error: Token inválido. Solo permite letras, números, guiones bajos, guiones y dos puntos.');
            }
            break;
            
        case 'number':
            // Validar número entero positivo
            if (!preg_match('/^[1-9]\d*$/', $input)) {
                die('Error: Número inválido. Debe ser un entero positivo mayor que cero.');
            }
            break;
            
        case 'string':
        default:
            // Validar string (sin caracteres de control, sin newlines)
            if (preg_match('/[\x00-\x1F\x7F]/', $input)) {
                die('Error: Texto inválido. No permite caracteres de control.');
            }
            // Limitar longitud
            if (strlen($input) > 1000) {
                die('Error: Texto demasiado largo. Máximo 1000 caracteres.');
            }
            break;
    }
    
    return $input;
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

// Función para cargar variables de entorno desde archivo (para saveEnv)
function loadEnvFromFile() {
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
        if (strpos($line, '=') === false) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $env[trim($name)] = trim($value);
    }
    return $env;
}

// Verificar autenticación
function checkAuth() {
    $username = $_ENV['ADMIN_USERNAME'] ?? 'admin';
    $password = $_ENV['ADMIN_PASSWORD'] ?? null;
    
    // Requerir que se configure una contraseña
    if ($password === null || $password === '') {
        die('Error: ADMIN_PASSWORD no está configurado en .env. Por favor configure una contraseña segura.');
    }

    if (!isset($_SESSION['authenticated'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_username']) && isset($_POST['login_password'])) {
            checkRateLimit();
            
            if ($_POST['login_username'] === $username) {
                $loginOk = false;
                
                // Si la contraseña almacenada es un hash bcrypt, usar password_verify
                if (str_starts_with($password, '$2y$')) {
                    $loginOk = password_verify($_POST['login_password'], $password);
                } else {
                    // Compatibilidad hacia atrás: comparación en texto plano
                    $loginOk = $_POST['login_password'] === $password;
                    // Si es correcta, re-guardar como hash
                    if ($loginOk) {
                        $env = loadEnvFromFile();
                        $env['ADMIN_PASSWORD'] = password_hash($_POST['login_password'], PASSWORD_BCRYPT);
                        saveEnv($env);
                    }
                }

                if ($loginOk) {
                    $_SESSION['authenticated'] = true;
                    resetFailedLogin();
                    return true;
                } else {
                    incrementFailedLogin();
                    return false;
                }
            }
            incrementFailedLogin();
            return false;
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

// Verificar autenticación antes de procesar cualquier acción
if (!checkAuth()) {
    // Mostrar login y terminar
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>trackerGram - Login</title>
    </head>
    <body>
        <h1>trackerGram - Login</h1>
        <?php if (isset($loginError)): ?>
            <p style="color: red;"><?php echo htmlspecialchars($loginError); ?></p>
        <?php endif; ?>
        <form method="post">
            <label>Usuario:</label><br>
            <input type="text" name="login_username" required><br><br>
            <label>Contraseña:</label><br>
            <input type="password" name="login_password" required><br><br>
            <button type="submit">Iniciar sesión</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Procesar cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $newPassword = trim($_POST['admin_password'] ?? '');
    if (strlen($newPassword) < 8) {
        $error = "La contraseña debe tener al menos 8 caracteres";
    } else {
        $env = loadEnvFromFile();
        $env['ADMIN_PASSWORD'] = password_hash($newPassword, PASSWORD_BCRYPT);
        if (saveEnv($env)) {
            session_regenerate_id(true);
            $success = "Contraseña cambiada exitosamente";
        } else {
            $error = "Error al guardar la nueva contraseña";
        }
    }
}

// Procesar formulario de configuración general
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_general') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $env = loadEnvFromFile();
    $env['TELEGRAM_BOT_TOKEN'] = validateInput($_POST['telegram_bot_token'] ?? '', 'token');
    $env['TELEGRAM_WEBHOOK_SECRET'] = validateInput($_POST['telegram_webhook_secret'] ?? '', 'token');
    $env['CUSTOM_WEBHOOK_URL'] = validateInput($_POST['custom_webhook_url'] ?? '', 'url');
    $env['TIKIWIKI_API_URL'] = validateInput($_POST['tikiwiki_api_url'] ?? '', 'url');
    $env['TIKIWIKI_TOKEN'] = validateInput($_POST['tikiwiki_token'] ?? '', 'token');
    
    if (saveEnv($env)) {
        $success = "Configuración general guardada exitosamente";
    } else {
        $error = "Error al guardar configuración";
    }
}

// Procesar formulario de tracker del webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tracker') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $env = loadEnvFromFile();
    $env['TIKIWIKI_TRACKER_ID'] = validateInput($_POST['tikiwiki_tracker_id'] ?? '1', 'number');
    
    if (saveEnv($env)) {
        $success = "Tracker actualizado exitosamente";
    } else {
        $error = "Error al guardar tracker";
    }
}

// Procesar actualización de webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_webhook') {
    // Validar CSRF token
    validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $env = loadEnvFromFile();
    $botToken = $env['TELEGRAM_BOT_TOKEN'] ?? '';
    $secretToken = $env['TELEGRAM_WEBHOOK_SECRET'] ?? '';

    // Auto-detectar URL y guardarla en .env
    $webhookUrl = generateWebhookUrl();
    $env['CUSTOM_WEBHOOK_URL'] = $webhookUrl;
    saveEnv($env);

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

// Procesar creación de tracker
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_tracker') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $trackerName = trim($_POST['tracker_name'] ?? 'Telegram Messages');
    
    $newTrackerId = $tikiWikiClient->createTracker($trackerName);
    
    if (is_array($newTrackerId) && isset($newTrackerId['error'])) {
        $error = $newTrackerId['error'];
    } elseif ($newTrackerId) {
        $success = "Tracker '$trackerName' creado exitosamente con ID: $newTrackerId. Podés usarlo como tracker en directo o para importar mensajes.";
    } else {
        $error = "Error al crear el tracker. Verifica las credenciales de TikiWiki.";
    }
}

// Cargar configuración actual
$env = loadEnvFromFile();
$config = [
    'telegram_bot_token' => $env['TELEGRAM_BOT_TOKEN'] ?? '',
    'telegram_webhook_secret' => $env['TELEGRAM_WEBHOOK_SECRET'] ?? '',
    'tikiwiki_api_url' => $env['TIKIWIKI_API_URL'] ?? '',
    'tikiwiki_token' => $env['TIKIWIKI_TOKEN'] ?? '',
    'tikiwiki_tracker_id' => $env['TIKIWIKI_TRACKER_ID'] ?? '1',
    'custom_webhook_url' => $env['CUSTOM_WEBHOOK_URL'] ?? ''
];

// UI Mode: classic o modern
$uiMode = $_GET['ui'] ?? $_COOKIE['tg_ui'] ?? 'classic';
if (!in_array($uiMode, ['classic', 'modern'])) $uiMode = 'classic';
if (isset($_GET['ui'])) {
    setcookie('tg_ui', $uiMode, time() + 365 * 86400, '/', '', !empty($_SERVER['HTTPS']), true);
}

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
    <title>trackerGram - Administración<?php echo $uiMode === 'modern' ? ' (Modern)' : ''; ?></title>
    <?php if ($uiMode === 'modern'): ?>
    <style>
        :root {
            --primary: #4a76a8;
            --primary-dark: #345583;
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --text: #1c1e21;
            --text-secondary: #65676b;
            --border: #dddfe2;
            --success: #42b72a;
            --error: #e74c3c;
            --radius: 12px;
            --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-lg: 0 4px 12px rgba(0,0,0,0.1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; padding: 0; }
        
        /* Navbar */
        .navbar { background: var(--primary); color: white; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; height: 56px; box-shadow: var(--shadow-lg); position: sticky; top: 0; z-index: 100; }
        .navbar-brand { font-size: 1.2em; font-weight: 700; letter-spacing: -0.3px; }
        .navbar-brand span { opacity: 0.85; font-weight: 400; }
        .navbar-actions { display: flex; align-items: center; gap: 12px; }
        .navbar a { color: white; text-decoration: none; font-size: 0.9em; padding: 6px 12px; border-radius: 6px; transition: background 0.2s; }
        .navbar a:hover { background: rgba(255,255,255,0.15); }
        
        /* UI Toggle */
        .ui-toggle { display: flex; background: rgba(255,255,255,0.15); border-radius: 8px; overflow: hidden; }
        .ui-toggle a { padding: 6px 14px; font-size: 0.85em; border-radius: 0; font-weight: 500; }
        .ui-toggle a.active { background: white; color: var(--primary); }
        
        /* Container */
        .container { max-width: 900px; margin: 0 auto; padding: 24px 16px; }
        
        /* Alert banners */
        .alert { padding: 14px 18px; border-radius: var(--radius); margin-bottom: 20px; font-size: 0.95em; font-weight: 500; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .alert-icon { font-size: 1.2em; }
        
        /* Cards grid */
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow); padding: 20px; transition: box-shadow 0.2s, transform 0.2s; cursor: pointer; border: 1px solid var(--border); }
        .card:hover { box-shadow: var(--shadow-lg); transform: translateY(-2px); }
        .card-icon { font-size: 1.8em; margin-bottom: 8px; }
        .card-title { font-weight: 600; font-size: 1em; color: var(--text); }
        .card-desc { font-size: 0.85em; color: var(--text-secondary); margin-top: 4px; }
        
        /* Section cards */
        .section { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 20px; overflow: hidden; border: 1px solid var(--border); }
        .section-header { padding: 16px 20px; font-weight: 600; font-size: 1.05em; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; }
        .section-header:hover { background: #f7f8fa; }
        .section-header .arrow { margin-left: auto; transition: transform 0.2s; font-size: 0.8em; }
        .section.collapsed .section-header .arrow { transform: rotate(-90deg); }
        .section-content { padding: 20px; }
        .section.collapsed .section-content { display: none; }
        
        /* Forms */
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 500; font-size: 0.9em; color: var(--text); margin-bottom: 4px; }
        .form-group .hint { font-size: 0.8em; color: var(--text-secondary); margin-top: 2px; }
        .input-wrapper { display: flex; gap: 6px; align-items: stretch; }
        .input-wrapper input { flex: 1; }
        input[type="text"], input[type="password"], input[type="number"] { padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95em; width: 100%; transition: border-color 0.2s, box-shadow 0.2s; background: #fff; }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(74,118,168,0.12); }
        .btn { padding: 10px 22px; border: none; border-radius: 8px; font-size: 0.95em; font-weight: 600; cursor: pointer; transition: background 0.2s, transform 0.1s; display: inline-flex; align-items: center; gap: 6px; }
        .btn:active { transform: scale(0.97); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: transparent; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover { background: rgba(74,118,168,0.06); }
        .btn-danger { background: var(--error); color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-sm { padding: 6px 12px; font-size: 0.85em; }
        .icon-btn { padding: 10px 14px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; cursor: pointer; font-size: 1em; transition: background 0.2s; }
        .icon-btn:hover { background: #e4e6e9; }
        
        /* Webhook URL display */
        .webhook-url { background: var(--bg); padding: 12px 16px; border-radius: 8px; font-family: 'SFMono-Regular', Consolas, monospace; font-size: 0.9em; word-break: break-all; margin-bottom: 12px; border: 1px solid var(--border); }
        .webhook-url .label { font-family: -apple-system, sans-serif; color: var(--text-secondary); font-size: 0.85em; margin-bottom: 4px; }
        
        /* File input styling */
        input[type="file"] { font-size: 0.9em; padding: 8px 0; }
        
        /* Status badges */
        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 0.8em; font-weight: 500; }
        .badge-active { background: #e8f5e9; color: #2e7d32; }
        .badge-inactive { background: #fff3e0; color: #e65100; }
        
        /* Responsive */
        @media (max-width: 600px) {
            .navbar { padding: 0 12px; }
            .navbar-brand { font-size: 1em; }
            .container { padding: 16px 12px; }
            .cards-grid { grid-template-columns: 1fr 1fr; }
            .section-content { padding: 16px; }
        }
        @media (max-width: 400px) {
            .cards-grid { grid-template-columns: 1fr; }
        }
    </style>
    <?php else: ?>
    <style>
        .import-success { color: green; }
        .import-error { color: red; }
        .collapsible h2 { cursor: pointer; user-select: none; }
        .collapsible h2::before { content: '\25BC'; font-size: 0.7em; margin-right: 0.5em; display: inline-block; transition: transform 0.2s; }
        .collapsible.collapsed h2::before { transform: rotate(-90deg); }
        .collapsible-content { overflow: hidden; transition: max-height 0.3s ease; }
        .collapsible.collapsed .collapsible-content { max-height: 0 !important; }
        .ui-toggle-classic { margin: 10px 0; padding: 6px 12px; background: #f0f0f0; border-radius: 6px; display: inline-block; }
        .ui-toggle-classic a { padding: 4px 10px; text-decoration: none; }
        .ui-toggle-classic a.active { font-weight: bold; }
    </style>
    <?php endif; ?>
</head>
<body>

<?php if ($uiMode === 'modern'): ?>

<!-- ===== MODERN UI ===== -->

<nav class="navbar">
    <div class="navbar-brand">🎛 trackerGram <span>Admin</span></div>
    <div class="navbar-actions">
        <div class="ui-toggle">
            <a href="?ui=classic" <?php echo $uiMode === 'classic' ? 'class="active"' : ''; ?>>Classic</a>
            <a href="?ui=modern" <?php echo $uiMode === 'modern' ? 'class="active"' : ''; ?>>Modern</a>
        </div>
        <a href="?action=logout">🚪 Cerrar sesión</a>
    </div>
</nav>

<div class="container">

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><span class="alert-icon">✅</span> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><span class="alert-icon">❌</span> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="cards-grid">
        <a href="#section-config" style="text-decoration:none;color:inherit;">
            <div class="card"><div class="card-icon">⚙️</div><div class="card-title">Configuración</div><div class="card-desc">Bot, TikiWiki, webhook</div></div>
        </a>
        <a href="#section-import" style="text-decoration:none;color:inherit;">
            <div class="card"><div class="card-icon">📥</div><div class="card-title">Importar</div><div class="card-desc">Subir export ZIP</div></div>
        </a>
        <a href="#section-webhook" style="text-decoration:none;color:inherit;">
            <div class="card"><div class="card-icon">🔗</div><div class="card-title">Webhook</div><div class="card-desc">Estado y actualización</div></div>
        </a>
        <a href="#section-tracker" style="text-decoration:none;color:inherit;">
            <div class="card"><div class="card-icon">➕</div><div class="card-title">Tracker</div><div class="card-desc">Crear tracker nuevo</div></div>
        </a>
    </div>

    <!-- 1. Configuración General -->
    <div class="section" id="section-config">
        <div class="section-header" onclick="this.parentElement.classList.toggle('collapsed')">
            ⚙️ Configuración General <span class="arrow">▼</span>
        </div>
        <div class="section-content">
            <form method="post">
                <input type="hidden" name="action" value="save_general">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <h4 style="margin-bottom:12px;color:var(--primary);">Telegram</h4>
                
                <div class="form-group">
                    <label>Bot Token</label>
                    <div class="input-wrapper">
                        <input type="password" name="telegram_bot_token" value="<?php echo htmlspecialchars($config['telegram_bot_token']); ?>" placeholder="Token de @BotFather">
                        <button type="button" class="icon-btn" onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'" title="Mostrar/Ocultar">👁</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Webhook Secret</label>
                    <div class="input-wrapper">
                        <input type="password" name="telegram_webhook_secret" value="<?php echo htmlspecialchars($config['telegram_webhook_secret']); ?>" placeholder="Secret token para webhook">
                        <button type="button" class="icon-btn" onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'" title="Mostrar/Ocultar">👁</button>
                    </div>
                    <div class="hint">Se envía como header X-Telegram-Bot-Api-Secret-Token</div>
                </div>
                
                <div class="form-group">
                    <label>Custom Webhook URL</label>
                    <input type="text" name="custom_webhook_url" value="<?php echo htmlspecialchars($config['custom_webhook_url'] ?? ''); ?>" placeholder="https://ejemplo.com/api.php">
                    <div class="hint">Solo si la URL auto-detectada no funciona</div>
                </div>
                
                <h4 style="margin:20px 0 12px;color:var(--primary);">TikiWiki</h4>
                
                <div class="form-group">
                    <label>API URL</label>
                    <input type="text" name="tikiwiki_api_url" value="<?php echo htmlspecialchars($config['tikiwiki_api_url']); ?>" placeholder="https://wiki.ejemplo.org/api/">
                </div>
                
                <div class="form-group">
                    <label>Token de API</label>
                    <div class="input-wrapper">
                        <input type="password" name="tikiwiki_token" value="<?php echo htmlspecialchars($config['tikiwiki_token']); ?>" placeholder="Token de TikiWiki">
                        <button type="button" class="icon-btn" onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'" title="Mostrar/Ocultar">👁</button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">💾 Guardar Configuración</button>
            </form>
            
            <hr style="margin:24px 0;border:none;border-top:1px solid var(--border);">
            
            <h4 style="margin-bottom:12px;">🔑 Contraseña de Admin</h4>
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label>Nueva contraseña</label>
                    <input type="password" name="admin_password" required minlength="8" placeholder="Mínimo 8 caracteres">
                </div>
                
                <button type="submit" class="btn btn-outline">🔑 Cambiar Contraseña</button>
            </form>
        </div>
    </div>

    <!-- 2. Importar -->
    <div class="section" id="section-import">
        <div class="section-header" onclick="this.parentElement.classList.toggle('collapsed')">
            📥 Importar Conversaciones <span class="arrow">▼</span>
        </div>
        <div class="section-content">
            <p style="margin-bottom:12px;">Importar conversaciones desde archivos ZIP exportados de Telegram. Los archivos multimedia se subirán a la file gallery del tracker.</p>
            <p style="font-size:0.85em;color:var(--text-secondary);margin-bottom:16px;">Límites: ZIP de hasta <?php echo ini_get('upload_max_filesize'); ?> (<?php echo formatBytes(MAX_ZIP_UNCOMPRESSED_SIZE); ?> descomprimido) • Archivos multimedia individuales de hasta <?php echo formatBytes(MEDIA_DOWNLOAD_MAX_SIZE); ?></p>
            
            <form id="import-form-modern" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label>Tracker destino</label>
                    <input type="text" name="tracker_id" value="<?php echo htmlspecialchars($config['tikiwiki_tracker_id']); ?>" style="width:100px;">
                    <div class="hint">ID del tracker donde se importarán los mensajes</div>
                </div>
                
                <div class="form-group">
                    <label>Archivo export (ZIP)</label>
                    <input type="file" name="export_file" accept=".zip" required>
                </div>
                
                <button type="button" class="btn btn-primary" onclick="importExportModern()">📥 Importar</button>
            </form>
            
            <div id="import-result-modern" style="margin-top:12px;"></div>
            
            <script>
            function importExportModern() {
                var form = document.getElementById('import-form-modern');
                var formData = new FormData(form);
                formData.append('mode', 'extract'); // modo chunked
                var resultDiv = document.getElementById('import-result-modern');
                var importBtn = form.querySelector('button');
                
                // Helper seguro: muestra texto plano
                function setResult(text, isError) {
                    resultDiv.innerHTML = '';
                    if (isError) resultDiv.style.color = 'var(--error)';
                    else resultDiv.style.color = '';
                    resultDiv.textContent = text;
                }
                
                // Helper: muestra progreso con barra
                function showProgress(current, total, label) {
                    var pct = total > 0 ? Math.round((current / total) * 100) : 0;
                    resultDiv.innerHTML = '';
                    
                    var container = document.createElement('div');
                    container.style.cssText = 'background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:16px;';
                    
                    var labelEl = document.createElement('div');
                    labelEl.style.cssText = 'margin-bottom:8px;color:var(--text);';
                    labelEl.textContent = label + ' (' + current + ' / ' + total + ')';
                    container.appendChild(labelEl);
                    
                    var barOuter = document.createElement('div');
                    barOuter.style.cssText = 'background:var(--border);border-radius:4px;height:20px;overflow:hidden;';
                    
                    var barInner = document.createElement('div');
                    barInner.style.cssText = 'background:#4caf50;height:100%;width:' + pct + '%;transition:width 0.3s;border-radius:4px;';
                    barInner.textContent = pct + '%';
                    barInner.style.cssText += ';color:#fff;text-align:center;font-size:12px;line-height:20px;';
                    barOuter.appendChild(barInner);
                    
                    container.appendChild(barOuter);
                    resultDiv.appendChild(container);
                }
                
                // Deshabilitar botón durante importación
                importBtn.disabled = true;
                importBtn.textContent = '⏳ Importando...';
                
                setResult('⏳ Extrayendo archivo ZIP...');
                
                // FASE 1: Extraer ZIP
                fetch('import.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) {
                    if (!r.ok) {
                        return r.text().then(function(text) {
                            try { var err = JSON.parse(text); throw new Error(err.error || 'HTTP ' + r.status); }
                            catch(e) { if (e.message !== text) throw e; throw new Error('HTTP ' + r.status + ': ' + text.substring(0,200)); }
                        });
                    }
                    return r.text();
                })
                .then(function(text) {
                    var data;
                    try { data = JSON.parse(text); } catch(e) {
                        throw new Error('Respuesta inválida: ' + text.substring(0, 200));
                    }
                    if (data.error) throw new Error(data.error);
                    if (data.status !== 'extracted') throw new Error('Respuesta inesperada');
                    
                    // FASE 2: Procesar por lotes
                    return processChunks(data.extract_id, data.total, data.chat_title, data.topics_found);
                })
                .then(function(result) {
                    // Mostrar resultado final
                    resultDiv.innerHTML = '';
                    
                    var div = document.createElement('div');
                    div.style.cssText = 'background:#e8f5e9;padding:14px;border-radius:8px;color:#2e7d32;';
                    
                    var title = document.createElement('p');
                    title.style.cssText = 'margin:0 0 8px 0;font-weight:bold;';
                    title.textContent = '✅ Importación completada';
                    div.appendChild(title);
                    
                    var lines = [
                        '📨 ' + result.imported + ' mensajes importados',
                        '⚠️ ' + result.skipped + ' errores',
                        '📎 ' + result.media_processed + ' archivos subidos',
                        '📂 ' + result.topics + ' topics encontrados'
                    ];
                    lines.forEach(function(line) {
                        var p = document.createElement('p');
                        p.style.cssText = 'margin:0;';
                        p.textContent = line;
                        div.appendChild(p);
                    });
                    
                    resultDiv.appendChild(div);
                })
                .catch(function(error) {
                    resultDiv.innerHTML = '';
                    resultDiv.style.color = 'var(--error)';
                    resultDiv.textContent = '❌ Error: ' + error.message;
                })
                .finally(function() {
                    importBtn.disabled = false;
                    importBtn.textContent = '📥 Importar';
                });
            }
            
            function processChunks(extractId, total, chatTitle, topicsFound) {
                var offset = 0;
                var batchSize = 50;
                var accumulated = { imported: 0, skipped: 0, media_processed: 0, topics: topicsFound || 0 };
                
                function nextChunk() {
                    return new Promise(function(resolve, reject) {
                        showProgress(offset, total, '📨 Importando mensajes');
                        
                        var data = new URLSearchParams();
                        data.append('mode', 'process');
                        data.append('extract_id', extractId);
                        data.append('offset', offset);
                        data.append('batch_size', batchSize);
                        data.append('csrf_token', document.querySelector('#import-form-modern [name=csrf_token]').value);
                        
                        fetch('import.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: data.toString()
                        })
                        .then(function(r) {
                            if (!r.ok) {
                                return r.text().then(function(text) {
                                    try { var err = JSON.parse(text); throw new Error(err.error || 'HTTP ' + r.status); }
                                    catch(e) { if (e.message !== text) throw e; throw new Error('HTTP ' + r.status + ': ' + text.substring(0,200)); }
                                });
                            }
                            return r.text();
                        })
                        .then(function(text) {
                            var result;
                            try { result = JSON.parse(text); } catch(e) {
                                throw new Error('Respuesta inválida en lote: ' + text.substring(0, 200));
                            }
                            if (result.error) throw new Error(result.error);
                            
                            // Acumular
                            accumulated.imported += result.imported || 0;
                            accumulated.skipped += result.skipped || 0;
                            accumulated.media_processed += result.media_processed || 0;
                            offset = result.offset || offset;
                            
                            if (result.more) {
                                // Siguiente lote (con pequeño delay para no saturar)
                                setTimeout(function() { nextChunk().then(resolve).catch(reject); }, 100);
                            } else {
                                // Terminado
                                resolve(accumulated);
                            }
                        })
                        .catch(reject);
                    });
                }
                
                return nextChunk();
            }
            </script>
        </div>
    </div>

    <!-- 3. Webhook / Tracker en directo -->
    <div class="section" id="section-webhook">
        <div class="section-header" onclick="this.parentElement.classList.toggle('collapsed')">
            🔗 Tracker en Directo <span class="arrow">▼</span>
        </div>
        <div class="section-content">
            <p style="margin-bottom:16px;">ID del tracker de TikiWiki donde se enviarán los mensajes en vivo desde Telegram.</p>
            
            <form method="post" style="margin-bottom:20px;">
                <input type="hidden" name="action" value="save_tracker">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label>TikiWiki Tracker ID</label>
                    <input type="text" name="tikiwiki_tracker_id" value="<?php echo htmlspecialchars($config['tikiwiki_tracker_id']); ?>" style="width:100px;">
                </div>
                
                <button type="submit" class="btn btn-primary">💾 Guardar Tracker</button>
            </form>
            
            <hr style="margin:20px 0;border:none;border-top:1px solid var(--border);">
            
            <h4 style="margin-bottom:8px;">🌐 Webhook</h4>
            <div class="webhook-url">
                <div class="label">URL actual del webhook</div>
                <?php
                $displayUrl = $env['CUSTOM_WEBHOOK_URL'] ?? '';
                if (empty($displayUrl)) {
                    $displayUrl = generateWebhookUrl();
                }
                echo htmlspecialchars($displayUrl);
                ?>
                <?php if (!empty($env['CUSTOM_WEBHOOK_URL'] ?? '')): ?>
                    <div style="font-family:-apple-system,sans-serif;font-size:0.85em;color:var(--text-secondary);margin-top:4px;">📌 Guardada en .env</div>
                <?php endif; ?>
            </div>
            
            <form method="post">
                <input type="hidden" name="action" value="update_webhook">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <button type="submit" class="btn btn-outline">🔄 Actualizar Webhook</button>
            </form>
        </div>
    </div>

    <!-- 4. Crear Tracker -->
    <div class="section" id="section-tracker">
        <div class="section-header" onclick="this.parentElement.classList.toggle('collapsed')">
            ➕ Crear Tracker en TikiWiki <span class="arrow">▼</span>
        </div>
        <div class="section-content">
            <p style="margin-bottom:16px;">Creá un tracker nuevo en TikiWiki con todos los campos necesarios para trackerGram.</p>
            
            <form method="post">
                <input type="hidden" name="action" value="create_tracker">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label>Nombre del tracker</label>
                    <input type="text" name="tracker_name" value="Telegram Messages" required>
                </div>
                
                <button type="submit" class="btn btn-primary">➕ Crear Tracker</button>
            </form>
            
            <div class="hint" style="margin-top:8px;">El tracker creado puede usarse como tracker en directo o para importar mensajes.</div>
        </div>
    </div>

</div>

<?php else: ?>

<!-- ===== CLASSIC UI ===== -->

<div style="max-width:900px;margin:0 auto;padding:20px;">
    <h1>trackerGram - Administración</h1>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div class="ui-toggle-classic">
            <a href="?ui=classic" class="<?php echo $uiMode === 'classic' ? 'active' : ''; ?>">Classic</a> |
            <a href="?ui=modern" class="<?php echo $uiMode === 'modern' ? 'active' : ''; ?>">Modern</a>
        </div>
        <a href="?action=logout">Cerrar sesión</a>
    </div>
    
    <?php if (isset($success)): ?>
        <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    
    <h2>Índice</h2>
    <ul>
        <li><a href="#config-general">1. Configuración general</a></li>
        <li><a href="#tracker-importar">2. Importar conversaciones</a></li>
        <li><a href="#tracker-webhook">3. Tracker en directo (recibe el webhook)</a></li>
        <li><a href="#crear-tracker">4. Crear Tracker en TikiWiki</a></li>
    </ul>
    
    <div class="collapsible">
    <h2 id="config-general">1. Configuración general</h2>
    <div class="collapsible-content">
    <p>Configuración básica del bot de Telegram y la instalación de TikiWiki.</p>
    <form method="post">
        <input type="hidden" name="action" value="save_general">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <h3>Telegram</h3>
        <label>Bot Token:</label><br>
        <input type="password" name="telegram_bot_token" value="<?php echo htmlspecialchars($config['telegram_bot_token']); ?>" size="60" placeholder="Click para ver">
        <button type="button" onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'">👁</button>
        <br><br>
        
        <label>Webhook Secret:</label><br>
        <input type="password" name="telegram_webhook_secret" value="<?php echo htmlspecialchars($config['telegram_webhook_secret']); ?>" size="50" placeholder="Click para ver">
        <button type="button" onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'">👁</button>
        <br><br>
        
        <label>Custom Webhook URL <small>(solo si la auto-detectada no funciona)</small>:</label><br>
        <input type="text" name="custom_webhook_url" value="<?php echo htmlspecialchars($config['custom_webhook_url'] ?? ''); ?>" size="50" placeholder="https://ejemplo.com/api.php"><br><br>
        
        <h3>TikiWiki</h3>
        <label>API URL:</label><br>
        <input type="text" name="tikiwiki_api_url" value="<?php echo htmlspecialchars($config['tikiwiki_api_url']); ?>" size="50"><br><br>
        
        <label>Token de API:</label><br>
        <input type="password" name="tikiwiki_token" value="<?php echo htmlspecialchars($config['tikiwiki_token']); ?>" size="50" placeholder="Click para ver">
        <button type="button" onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'">👁</button>
        <br><br>
        
        <button type="submit">Guardar Configuración General</button>
    </form>
    
    <h3>Cambiar contraseña de admin</h3>
    <form method="post">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <label>Nueva contraseña:</label><br>
        <input type="password" name="admin_password" required minlength="8" size="30">
        <br><br>
        
        <button type="submit">Cambiar Contraseña</button>
    </form>
    
    <h3>Webhook</h3>
    <p>URL autodetectada del webhook: 
        <?php
        $displayUrl = $env['CUSTOM_WEBHOOK_URL'] ?? '';
        if (empty($displayUrl)) {
            $displayUrl = generateWebhookUrl();
        }
        echo htmlspecialchars($displayUrl);
        ?>
        <?php if (!empty($env['CUSTOM_WEBHOOK_URL'] ?? '')): ?>
            <br><small>(guardada en .env)</small>
        <?php endif; ?>
    </p>
    <form method="post">
        <input type="hidden" name="action" value="update_webhook">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <button type="submit">Actualizar Webhook</button>
    </form>
    
    </div>
    </div>
    
    <div class="collapsible">
    <h2 id="tracker-importar">2. Importar conversaciones</h2>
    <div class="collapsible-content">
    <p>Importar conversaciones desde archivos ZIP exportados de Telegram. Los archivos (fotos, stickers, videos) se subirán a la file gallery del tracker.</p>
    <p><small>Límites: ZIP de hasta <?php echo ini_get('upload_max_filesize'); ?> (<?php echo formatBytes(MAX_ZIP_UNCOMPRESSED_SIZE); ?> descomprimido) • Archivos multimedia individuales de hasta <?php echo formatBytes(MEDIA_DOWNLOAD_MAX_SIZE); ?></small></p>
    
    <form id="import-form" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <label>Tracker destino:</label><br>
        <input type="text" name="tracker_id" value="<?php echo htmlspecialchars($config['tikiwiki_tracker_id']); ?>" size="10">
        <small>(ID del tracker donde se importarán los mensajes)</small><br><br>
        
        <label>Archivo export (ZIP):</label><br>
        <input type="file" name="export_file" accept=".zip" required><br><br>
        
        <button type="button" onclick="importExport()">Importar</button>
    </form>
    
    <div id="import-result"></div>
    
    <script>
    function importExport() {
        var form = document.getElementById('import-form');
        var formData = new FormData(form);
        formData.append('mode', 'extract');
        var resultDiv = document.getElementById('import-result');
        var importBtn = form.querySelector('button');
        
        function showProgress(current, total, label) {
            var pct = total > 0 ? Math.round((current / total) * 100) : 0;
            resultDiv.innerHTML = '<div style="background:#f5f5f5;padding:10px;border-radius:4px;">' +
                '<div style="margin-bottom:6px;">' + label + ' (' + current + ' / ' + total + ')</div>' +
                '<div style="background:#ddd;border-radius:4px;height:20px;overflow:hidden;">' +
                '<div style="background:#4caf50;height:100%;width:' + pct + '%;border-radius:4px;color:#fff;text-align:center;font-size:12px;line-height:20px;">' + pct + '%</div>' +
                '</div></div>';
        }
        
        function setResult(text, isError) {
            resultDiv.textContent = text;
            resultDiv.className = isError ? 'import-error' : '';
        }
        
        function processChunks(extractId, total, chatTitle, topicsFound) {
            var offset = 0;
            var batchSize = 50;
            var accumulated = { imported: 0, skipped: 0, media_processed: 0, topics: topicsFound || 0 };
            
            function nextChunk() {
                return new Promise(function(resolve, reject) {
                    showProgress(offset, total, 'Importando mensajes');
                    
                    var data = new URLSearchParams();
                    data.append('mode', 'process');
                    data.append('extract_id', extractId);
                    data.append('offset', offset);
                    data.append('batch_size', batchSize);
                    data.append('csrf_token', document.querySelector('#import-form [name=csrf_token]').value);
                    
                    fetch('import.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: data.toString()
                    })
                    .then(function(r) {
                        if (!r.ok) {
                            return r.text().then(function(text) {
                                try { var err = JSON.parse(text); throw new Error(err.error || 'HTTP ' + r.status); }
                                catch(e) { if (e.message !== text) throw e; throw new Error('HTTP ' + r.status + ': ' + text.substring(0,200)); }
                            });
                        }
                        return r.text();
                    })
                    .then(function(text) {
                        var result;
                        try { result = JSON.parse(text); } catch(e) {
                            throw new Error('Respuesta inv\u00e1lida en lote: ' + text.substring(0, 200));
                        }
                        if (result.error) throw new Error(result.error);
                        
                        accumulated.imported += result.imported || 0;
                        accumulated.skipped += result.skipped || 0;
                        accumulated.media_processed += result.media_processed || 0;
                        offset = result.offset || offset;
                        
                        if (result.more) {
                            setTimeout(function() { nextChunk().then(resolve).catch(reject); }, 100);
                        } else {
                            resolve(accumulated);
                        }
                    })
                    .catch(reject);
                });
            }
            
            return nextChunk();
        }
        
        importBtn.disabled = true;
        importBtn.textContent = 'Importando...';
        setResult('Extrayendo archivo ZIP...');
        
        // FASE 1: Extraer ZIP
        fetch('import.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) {
            if (!r.ok) {
                return r.text().then(function(text) {
                    try { var err = JSON.parse(text); throw new Error(err.error || 'HTTP ' + r.status); }
                    catch(e) { if (e.message !== text) throw e; throw new Error('HTTP ' + r.status + ': ' + text.substring(0,200)); }
                });
            }
            return r.text();
        })
        .then(function(text) {
            var data;
            try { data = JSON.parse(text); } catch(e) {
                throw new Error('Respuesta inv\u00e1lida: ' + text.substring(0, 200));
            }
            if (data.error) throw new Error(data.error);
            if (data.status !== 'extracted') throw new Error('Respuesta inesperada');
            
            // FASE 2: Procesar por lotes
            return processChunks(data.extract_id, data.total, data.chat_title, data.topics_found);
        })
        .then(function(result) {
            resultDiv.innerHTML = '';
            resultDiv.innerHTML = '<div style="background:#e8f5e9;padding:14px;border-radius:4px;color:#2e7d32;">' +
                '<p style="margin:0 0 8px 0;font-weight:bold;">Importaci\u00f3n completada</p>' +
                '<p style="margin:0;">Mensajes importados: ' + result.imported + '</p>' +
                '<p style="margin:0;">Errores: ' + result.skipped + '</p>' +
                '<p style="margin:0;">Archivos subidos: ' + result.media_processed + '</p>' +
                '<p style="margin:0;">Topics encontrados: ' + result.topics + '</p></div>';
            resultDiv.className = 'import-success';
        })
        .catch(function(error) {
            resultDiv.innerHTML = '';
            resultDiv.textContent = 'Error: ' + error.message;
            resultDiv.className = 'import-error';
        })
        .finally(function() {
            importBtn.disabled = false;
            importBtn.textContent = 'Importar';
        });
    }
    </script>
    
    </div>
    </div>
    
    <div class="collapsible">
    <h2 id="tracker-webhook">3. Tracker en directo (recibe el webhook)</h2>
    <div class="collapsible-content">
    <p>ID del tracker de TikiWiki donde se enviarán los mensajes en vivo desde Telegram.</p>
    <form method="post">
        <input type="hidden" name="action" value="save_tracker">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <label>TikiWiki Tracker ID:</label><br>
        <input type="text" name="tikiwiki_tracker_id" value="<?php echo htmlspecialchars($config['tikiwiki_tracker_id']); ?>" size="10">
        <br><br>
        
        <button type="submit">Guardar Tracker</button>
    </form>
    
    <!-- Sección de importación movida arriba -->
    
    </div>
    </div>
    
    <div class="collapsible">
    <h2 id="crear-tracker">4. Crear Tracker en TikiWiki</h2>
    <div class="collapsible-content">
    
    <form method="post">
        <input type="hidden" name="action" value="create_tracker">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <label>Nombre del tracker:</label><br>
        <input type="text" name="tracker_name" value="Telegram Messages" size="50" required><br><br>
        
        <button type="submit">Crear Tracker</button>
    </form>
    
    <p><small>El tracker creado puede usarse como tracker en directo o para importar mensajes.</small></p>
    </div>
    </div>

    <script>
    document.querySelectorAll('.collapsible h2').forEach(function(h2) {
        h2.addEventListener('click', function() {
            this.parentElement.classList.toggle('collapsed');
        });
    });
    </script>
</div>

<?php endif; ?>

</body>
</html>
