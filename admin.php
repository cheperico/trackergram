<?php
/**
 * trackerGram - Panel de Administración
 * 
 * Dos pestañas: Webhook (CRUD de conexiones) e Importar (backfill ZIP)
 * Cada conexión vincula un bot de Telegram con un tracker de TikiWiki.
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
$sessionTimeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $sessionTimeout)) {
    session_unset();
    session_destroy();
    header('Location: admin.php');
    exit;
}
$_SESSION['last_activity'] = time();

// Regenerar ID de sesión periódicamente
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 15 * 60) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

require_once 'bootstrap.php';
require_once 'ConfigManager.php';

// ── Helpers ──

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

function checkRateLimit() {
    $maxAttempts = 5;
    $lockoutTime = 15 * 60;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateFile = TEMP_DIR . '/tg_admin_rate_' . md5($ip);
    
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
    $rateFile = TEMP_DIR . '/tg_admin_rate_' . md5($ip);
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
    $rateFile = TEMP_DIR . '/tg_admin_rate_' . md5($ip);
    $data = ['attempts' => 0, 'first_attempt' => time()];
    @file_put_contents($rateFile, json_encode($data));
}

function generateWebhookUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($protocol === 'http' && !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $protocol = 'https';
    }
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $scriptPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $prefix = rtrim($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '', '/');
    $url = $protocol . '://' . $host . $prefix . $scriptPath;
    $url = rtrim($url, '/');
    if (!str_ends_with($url, '/api.php')) {
        $url .= '/api.php';
    }
    return $url;
}

function escapeHtml($str): string {
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

// ── Auth ──

function checkAuth() {
    $username = $_ENV['ADMIN_USERNAME'] ?? 'admin';
    $password = $_ENV['ADMIN_PASSWORD'] ?? null;
    
    if ($password === null || $password === '') {
        die('Error: ADMIN_PASSWORD no está configurado en .env.');
    }

    if (!isset($_SESSION['authenticated'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_username']) && isset($_POST['login_password'])) {
            checkRateLimit();
            
            if ($_POST['login_username'] === $username) {
                $loginOk = false;
                
                if (str_starts_with($password, '$2y$')) {
                    $loginOk = password_verify($_POST['login_password'], $password);
                } else {
                    $loginOk = $_POST['login_password'] === $password;
                    if ($loginOk) {
                        $envFile = __DIR__ . '/.env';
                        if (file_exists($envFile)) {
                            $env = file_get_contents($envFile);
                            $env = preg_replace(
                                '/^ADMIN_PASSWORD=.*$/m',
                                'ADMIN_PASSWORD=' . password_hash($_POST['login_password'], PASSWORD_BCRYPT),
                                $env
                            );
                            @file_put_contents($envFile, $env, LOCK_EX);
                        }
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

// Verificar autenticación
if (!checkAuth()) {
    $loginFailed = $_SERVER['REQUEST_METHOD'] === 'POST';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>trackerGram - Login</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
            .login-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); padding: 32px; max-width: 360px; width: 100%; }
            .login-card h1 { margin: 0 0 8px 0; font-size: 1.4em; color: #1c1e21; }
            .login-card p { margin: 0 0 20px 0; color: #65676b; font-size: 0.9em; }
            .login-card label { display: block; font-weight: 500; font-size: 0.9em; margin-bottom: 4px; color: #1c1e21; }
            .login-card input { width: 100%; padding: 10px 14px; border: 1px solid #dddfe2; border-radius: 8px; font-size: 0.95em; margin-bottom: 16px; box-sizing: border-box; }
            .login-card input:focus { outline: none; border-color: #4a76a8; box-shadow: 0 0 0 3px rgba(74,118,168,0.12); }
            .login-card button { width: 100%; padding: 10px; background: #4a76a8; color: white; border: none; border-radius: 8px; font-size: 1em; font-weight: 600; cursor: pointer; }
            .login-card button:hover { background: #345583; }
            .error { color: #e74c3c; font-size: 0.9em; margin-bottom: 12px; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <h1>trackerGram</h1>
            <p>Ingresá con tu usuario y contraseña</p>
            <?php if ($loginFailed): ?><div class="error">Usuario o contraseña incorrectos</div><?php endif; ?>
            <form method="post">
                <label>Usuario</label>
                <input type="text" name="login_username" required>
                <label>Contraseña</label>
                <input type="password" name="login_password" required>
                <button type="submit">Iniciar sesión</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Cargar conexiones existentes ──
$configManager = new ConfigManager();
$connections = $configManager->listConnections();

// ── Procesar acciones POST ──

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    validateCSRFToken($csrfToken);
    
    switch ($_POST['action']) {
        
        // ── Cambiar contraseña ──
        case 'change_password':
            $newPassword = trim($_POST['admin_password'] ?? '');
            if (strlen($newPassword) < 8) {
                $errorMessage = 'La contraseña debe tener al menos 8 caracteres';
            } else {
                $envFile = __DIR__ . '/.env';
                if (file_exists($envFile)) {
                    $env = file_get_contents($envFile);
                    $env = preg_replace(
                        '/^ADMIN_PASSWORD=.*$/m',
                        'ADMIN_PASSWORD=' . password_hash($newPassword, PASSWORD_BCRYPT),
                        $env
                    );
                    if (@file_put_contents($envFile, $env, LOCK_EX)) {
                        session_regenerate_id(true);
                        $successMessage = 'Contraseña cambiada exitosamente';
                    } else {
                        $errorMessage = 'Error al guardar la nueva contraseña';
                    }
                } else {
                    $errorMessage = 'Archivo .env no encontrado';
                }
            }
            break;
        
        // ── Guardar conexión (crear o editar) ──
        case 'save_connection':
            $slug = $_POST['slug'] ?? '';
            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'bot_token' => trim($_POST['bot_token'] ?? ''),
                'webhook_secret' => trim($_POST['webhook_secret'] ?? ''),
                'chat_id' => (int) ($_POST['chat_id'] ?? 0),
                'tiki_api_url' => trim($_POST['tiki_api_url'] ?? ''),
                'tiki_api_token' => trim($_POST['tiki_api_token'] ?? ''),
                'tracker_id' => (int) ($_POST['tracker_id'] ?? 0),
                'enabled' => isset($_POST['enabled']),
            ];
            
            // Si es edición, pasar el slug existente
            if (!empty($slug)) {
                $data['slug'] = $slug;
            }
            
            try {
                $newSlug = $configManager->saveConnection($data);
                $successMessage = 'Conexión "' . escapeHtml($data['name']) . '" guardada exitosamente (slug: ' . $newSlug . ')';
                $connections = $configManager->listConnections(); // refrescar
            } catch (Exception $e) {
                $errorMessage = 'Error al guardar conexión: ' . $e->getMessage();
            }
            break;
        
        // ── Eliminar conexión ──
        case 'delete_connection':
            $slug = $_POST['slug'] ?? '';
            if ($configManager->deleteConnection($slug)) {
                $successMessage = 'Conexión eliminada';
                $connections = $configManager->listConnections();
            } else {
                $errorMessage = 'Error al eliminar conexión';
            }
            break;
        
        // ── Activar/Desactivar conexión ──
        case 'toggle_connection':
            $slug = $_POST['slug'] ?? '';
            $conn = $configManager->getConnection($slug);
            if ($conn) {
                if ($conn['enabled']) {
                    $configManager->disableConnection($slug);
                    $successMessage = 'Conexión desactivada';
                } else {
                    $configManager->enableConnection($slug);
                    $successMessage = 'Conexión activada';
                }
                $connections = $configManager->listConnections();
            } else {
                $errorMessage = 'Conexión no encontrada';
            }
            break;
        
        // ── Configurar webhook en Telegram ──
        case 'configure_webhook':
            $slug = $_POST['slug'] ?? '';
            $conn = $configManager->getConnection($slug);
            if (!$conn) {
                $errorMessage = 'Conexión no encontrada';
                break;
            }
            
            $botToken = $conn['bot_token'];
            $webhookSecret = $conn['webhook_secret'];
            $webhookUrl = generateWebhookUrl();
            
            if (empty($botToken)) {
                $errorMessage = 'La conexión no tiene bot_token';
                break;
            }
            if (empty($webhookSecret)) {
                $errorMessage = 'La conexión no tiene webhook_secret';
                break;
            }
            
            $apiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook";
            $params = [
                'url' => $webhookUrl,
                'secret_token' => $webhookSecret,
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
                $successMessage = 'Webhook configurado para "' . escapeHtml($conn['name']) . '": ' . $webhookUrl;
            } else {
                $desc = $result['description'] ?? 'Error desconocido';
                $errorMessage = 'Error al configurar webhook: ' . $desc;
            }
            break;
        
        // ── Test de conexión (AJAX) ──
        case 'test_connection':
            $slug = $_POST['slug'] ?? '';
            $conn = $configManager->getConnection($slug);
            if (!$conn) {
                echo json_encode(['ok' => false, 'message' => 'Conexión no encontrada']);
                exit;
            }
            
            $results = [];
            
            // Test Telegram
            if (!empty($conn['bot_token'])) {
                $tgClient = new TelegramClient($conn['bot_token']);
                $tgResult = $tgClient->testConnection();
                $results['telegram'] = $tgResult;
            } else {
                $results['telegram'] = ['ok' => false, 'message' => 'Sin bot_token'];
            }
            
            // Test TikiWiki
            if (!empty($conn['tiki_api_url']) && !empty($conn['tiki_api_token'])) {
                $tikiClient = new TikiWikiClient($conn['tiki_api_url'], $conn['tiki_api_token']);
                $tikiResult = $tikiClient->testConnection();
                $results['tikiwiki'] = $tikiResult;
            } else {
                $results['tikiwiki'] = ['ok' => false, 'message' => 'Sin API URL o token'];
            }
            
            header('Content-Type: application/json');
            echo json_encode($results);
            exit;
    }
}

// ── Determinar tab activa ──
$activeTab = $_GET['tab'] ?? 'webhook';
if (!in_array($activeTab, ['webhook', 'import'])) {
    $activeTab = 'webhook';
}

// Para la edición, cargar conexión si se pasa slug
$editConnection = null;
if (isset($_GET['edit'])) {
    $editConnection = $configManager->getConnection($_GET['edit']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>trackerGram - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        
        /* Tabs */
        .tabs { display: flex; background: var(--card-bg); border-bottom: 1px solid var(--border); padding: 0 24px; gap: 0; }
        .tab { padding: 14px 24px; font-weight: 500; font-size: 0.95em; color: var(--text-secondary); cursor: pointer; border-bottom: 3px solid transparent; text-decoration: none; transition: color 0.2s, border-color 0.2s; }
        .tab:hover { color: var(--text); }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        
        .container { max-width: 960px; margin: 0 auto; padding: 24px 16px; }
        
        /* Alert banners */
        .alert { padding: 14px 18px; border-radius: var(--radius); margin-bottom: 20px; font-size: 0.95em; font-weight: 500; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        
        /* Section cards */
        .section { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 20px; overflow: hidden; border: 1px solid var(--border); }
        .section-header { padding: 16px 20px; font-weight: 600; font-size: 1.05em; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 8px; }
        .section-content { padding: 20px; }
        
        /* Connection cards */
        .conn-list { display: flex; flex-direction: column; gap: 12px; }
        .conn-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 20px; transition: box-shadow 0.2s; }
        .conn-card:hover { box-shadow: var(--shadow-lg); }
        .conn-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .conn-name { font-weight: 600; font-size: 1.05em; display: flex; align-items: center; gap: 8px; }
        .conn-status { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
        .conn-status.active { background: var(--success); }
        .conn-status.inactive { background: var(--text-secondary); }
        .conn-details { display: flex; flex-wrap: wrap; gap: 16px; font-size: 0.85em; color: var(--text-secondary); margin-bottom: 12px; }
        .conn-details span { display: flex; align-items: center; gap: 4px; }
        .conn-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        
        .empty-state { text-align: center; padding: 40px 20px; color: var(--text-secondary); }
        .empty-state p { font-size: 0.95em; margin-bottom: 16px; }
        
        /* Forms */
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 500; font-size: 0.9em; color: var(--text); margin-bottom: 4px; }
        .form-group .hint { font-size: 0.8em; color: var(--text-secondary); margin-top: 2px; }
        .input-wrapper { display: flex; gap: 6px; align-items: stretch; }
        .input-wrapper input { flex: 1; }
        input[type="text"], input[type="password"], input[type="number"] { padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95em; width: 100%; transition: border-color 0.2s; background: #fff; }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(74,118,168,0.12); }
        select { padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95em; background: #fff; width: 100%; }
        
        .btn { padding: 10px 22px; border: none; border-radius: 8px; font-size: 0.9em; font-weight: 600; cursor: pointer; transition: background 0.2s, transform 0.1s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .btn:active { transform: scale(0.97); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: transparent; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover { background: rgba(74,118,168,0.06); }
        .btn-danger { background: var(--error); color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #36a420; }
        .btn-sm { padding: 6px 12px; font-size: 0.85em; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #d68910; }
        
        .icon-btn { padding: 8px 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; cursor: pointer; font-size: 0.9em; transition: background 0.2s; }
        .icon-btn:hover { background: #e4e6e9; }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
        
        .checkbox-row { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; }
        .checkbox-row input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .checkbox-row label { cursor: pointer; }
        
        /* Modal overlay */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 200; justify-content: center; align-items: center; padding: 20px; }
        .modal-overlay.show { display: flex; }
        .modal { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow-lg); max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-header { padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 600; font-size: 1.1em; display: flex; justify-content: space-between; align-items: center; }
        .modal-close { background: none; border: none; font-size: 1.5em; cursor: pointer; color: var(--text-secondary); padding: 0 4px; }
        .modal-close:hover { color: var(--text); }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; }
        
        hr { border: none; border-top: 1px solid var(--border); margin: 20px 0; }
        
        .inline-form { display: inline; }
        
        /* Webhook URL display */
        .webhook-url { background: var(--bg); padding: 12px 16px; border-radius: 8px; font-family: 'SFMono-Regular', Consolas, monospace; font-size: 0.9em; word-break: break-all; margin-bottom: 12px; border: 1px solid var(--border); }
        .webhook-url .label { font-family: -apple-system, sans-serif; color: var(--text-secondary); font-size: 0.85em; margin-bottom: 4px; }
        
        /* Import */
        input[type="file"] { font-size: 0.9em; padding: 8px 0; }
        
        /* Test result */
        .test-result { margin-top: 8px; padding: 8px 12px; border-radius: 6px; font-size: 0.85em; }
        .test-result.ok { background: #e8f5e9; color: #2e7d32; }
        .test-result.fail { background: #ffebee; color: #c62828; }
        
        @media (max-width: 600px) {
            .navbar { padding: 0 12px; }
            .navbar-brand { font-size: 1em; }
            .container { padding: 16px 12px; }
            .conn-actions { flex-direction: column; }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="navbar-brand">trackerGram <span>Admin</span></div>
    <div class="navbar-actions">
        <a href="?action=logout">Cerrar sesion</a>
    </div>
</nav>

<!-- Tabs -->
<div class="tabs">
    <a href="?tab=webhook" class="tab <?php echo $activeTab === 'webhook' ? 'active' : ''; ?>">Webhook</a>
    <a href="?tab=import" class="tab <?php echo $activeTab === 'import' ? 'active' : ''; ?>">Importar</a>
</div>

<div class="container">

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?php echo escapeHtml($successMessage); ?></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert alert-error"><?php echo escapeHtml($errorMessage); ?></div>
<?php endif; ?>

<?php if ($activeTab === 'webhook'): ?>

<!-- ===== TAB: WEBHOOK ===== -->

<div class="section">
    <div class="section-header">Conexiones Activas</div>
    <div class="section-content">
        <?php if (empty($connections)): ?>
            <div class="empty-state">
                <p>No hay conexiones configuradas.</p>
                <button class="btn btn-primary" onclick="openModal('connection-modal')">+ Agregar conexion</button>
            </div>
        <?php else: ?>
            <div style="margin-bottom:16px;">
                <button class="btn btn-primary" onclick="openModal('connection-modal')">+ Agregar conexion</button>
            </div>
            <div class="conn-list">
                <?php foreach ($connections as $slug => $conn): ?>
                <div class="conn-card">
                    <div class="conn-header">
                        <div class="conn-name">
                            <span class="conn-status <?php echo $conn['enabled'] ? 'active' : 'inactive'; ?>"></span>
                            <?php echo escapeHtml($conn['name']); ?>
                            <?php if (!$conn['enabled']): ?>
                                <span style="font-size:0.8em;color:var(--text-secondary);font-weight:400;">(inactivo)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="conn-details">
                        <span>Bot: <?php echo escapeHtml(substr($conn['bot_token'], 0, 20) . '...'); ?></span>
                        <span>Chat ID: <?php echo (int) ($conn['chat_id'] ?? 0) ?: 'Pendiente'; ?></span>
                        <span>Tracker: #<?php echo (int) $conn['tracker_id']; ?></span>
                        <span><?php echo escapeHtml(parse_url($conn['tiki_api_url'] ?? '', PHP_URL_HOST) ?: $conn['tiki_api_url']); ?></span>
                    </div>
                    <div class="conn-actions">
                        <form method="get" class="inline-form">
                            <input type="hidden" name="tab" value="webhook">
                            <input type="hidden" name="edit" value="<?php echo escapeHtml($slug); ?>">
                            <button type="submit" class="btn btn-outline btn-sm" onclick="event.preventDefault(); openEditModal('<?php echo escapeHtml($slug); ?>')">Editar</button>
                        </form>
                        
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="toggle_connection">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($slug); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-sm <?php echo $conn['enabled'] ? 'btn-warning' : 'btn-success'; ?>">
                                <?php echo $conn['enabled'] ? 'Desactivar' : 'Activar'; ?>
                            </button>
                        </form>
                        
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="configure_webhook">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($slug); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-outline btn-sm">Configurar Webhook</button>
                        </form>
                        
                        <button class="btn btn-outline btn-sm" onclick="testConnection('<?php echo escapeHtml($slug); ?>', this)">Test</button>
                        <div class="test-result" style="display:none;"></div>
                        
                        <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar conexion \'<?php echo escapeHtml($conn['name']); ?>\'?')">
                            <input type="hidden" name="action" value="delete_connection">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($slug); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Crear/Editar conexion -->
<div class="modal-overlay" id="connection-modal">
    <div class="modal">
        <form method="post">
            <input type="hidden" name="action" value="save_connection">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="slug" id="form-slug" value="">
            
            <div class="modal-header">
                <span id="modal-title">Nueva conexion</span>
                <button type="button" class="modal-close" onclick="closeModal('connection-modal')">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label>Nombre de la conexion</label>
                    <input type="text" name="name" id="form-name" required placeholder="Ej: QPCH Produccion">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Bot Token</label>
                        <div class="input-wrapper">
                            <input type="password" name="bot_token" id="form-bot_token" required placeholder="Token de @BotFather">
                            <button type="button" class="icon-btn" onclick="togglePassword(this)">Mostrar</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Webhook Secret</label>
                        <div class="input-wrapper">
                            <input type="password" name="webhook_secret" id="form-webhook_secret" placeholder="Auto-generado si se deja vacio">
                            <button type="button" class="icon-btn" onclick="togglePassword(this)">Mostrar</button>
                        </div>
                        <div class="hint">Dejar vacio para generar uno automaticamente</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Chat ID</label>
                        <input type="number" name="chat_id" id="form-chat_id" value="0" placeholder="0 = pendiente">
                        <div class="hint">Agrega el bot al grupo y revisa los logs para obtenerlo</div>
                    </div>
                    <div class="form-group">
                        <label>Tracker ID</label>
                        <input type="number" name="tracker_id" id="form-tracker_id" required placeholder="Ej: 22">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Tiki API URL</label>
                    <input type="text" name="tiki_api_url" id="form-tiki_api_url" required placeholder="https://wiki.ejemplo.org/api/">
                </div>
                
                <div class="form-group">
                    <label>Tiki API Token</label>
                    <div class="input-wrapper">
                        <input type="password" name="tiki_api_token" id="form-tiki_api_token" required placeholder="Token de TikiWiki">
                        <button type="button" class="icon-btn" onclick="togglePassword(this)">Mostrar</button>
                    </div>
                </div>
                
                <div class="checkbox-row">
                    <input type="checkbox" name="enabled" id="form-enabled" checked>
                    <label for="form-enabled">Activa</label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('connection-modal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar conexion</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function openEditModal(slug) {
    // Cargar datos via fetch y poblar formulario
    var connections = <?php echo json_encode($connections); ?>;
    var conn = connections[slug];
    if (!conn) return;
    
    document.getElementById('modal-title').textContent = 'Editar: ' + conn.name;
    document.getElementById('form-slug').value = slug;
    document.getElementById('form-name').value = conn.name;
    document.getElementById('form-bot_token').value = conn.bot_token;
    document.getElementById('form-webhook_secret').value = conn.webhook_secret || '';
    document.getElementById('form-chat_id').value = conn.chat_id || 0;
    document.getElementById('form-tracker_id').value = conn.tracker_id;
    document.getElementById('form-tiki_api_url').value = conn.tiki_api_url;
    document.getElementById('form-tiki_api_token').value = conn.tiki_api_token;
    document.getElementById('form-enabled').checked = conn.enabled !== false;
    
    openModal('connection-modal');
}

function togglePassword(btn) {
    var input = btn.previousElementSibling;
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = 'Ocultar';
    } else {
        input.type = 'password';
        btn.textContent = 'Mostrar';
    }
}

function testConnection(slug, btn) {
    var resultDiv = btn.parentElement.querySelector('.test-result');
    resultDiv.style.display = 'block';
    resultDiv.className = 'test-result';
    resultDiv.textContent = 'Probando...';
    btn.disabled = true;
    
    var data = new URLSearchParams();
    data.append('action', 'test_connection');
    data.append('slug', slug);
    data.append('csrf_token', '<?php echo generateCSRFToken(); ?>');
    
    fetch('admin.php?tab=webhook', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        var lines = [];
        if (result.telegram) {
            lines.push('Telegram: ' + (result.telegram.ok ? 'OK' : 'ERROR') + ' — ' + result.telegram.message);
        }
        if (result.tikiwiki) {
            lines.push('TikiWiki: ' + (result.tikiwiki.ok ? 'OK' : 'ERROR') + ' — ' + result.tikiwiki.message);
        }
        var allOk = (result.telegram && result.telegram.ok) && (result.tikiwiki && result.tikiwiki.ok);
        resultDiv.className = 'test-result ' + (allOk ? 'ok' : 'fail');
        resultDiv.textContent = lines.join(' | ');
    })
    .catch(function(err) {
        resultDiv.className = 'test-result fail';
        resultDiv.textContent = 'Error de red: ' + err.message;
    })
    .finally(function() {
        btn.disabled = false;
    });
}

// Cerrar modal si se hace click fuera
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('show');
    }
});
</script>

<?php elseif ($activeTab === 'import'): ?>

<!-- ===== TAB: IMPORTAR ===== -->

<div class="section">
    <div class="section-header">Importar conversaciones (backfill)</div>
    <div class="section-content">
        <p style="margin-bottom:16px;">
            Importa un archivo ZIP exportado de Telegram para poblar un tracker con mensajes
            anteriores a la llegada del bot.
        </p>
        
        <form id="import-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <?php if (!empty($connections)): ?>
            <div class="form-group">
                <label>Usar conexion existente (opcional)</label>
                <select name="connection_slug" onchange="fillImportFromConnection(this)">
                    <option value="">— Ingresar manual —</option>
                    <?php foreach ($connections as $slug => $conn): ?>
                    <option value="<?php echo escapeHtml($slug); ?>"
                        data-tiki_url="<?php echo escapeHtml($conn['tiki_api_url']); ?>"
                        data-tiki_token="<?php echo escapeHtml($conn['tiki_api_token']); ?>"
                        data-tracker_id="<?php echo (int) $conn['tracker_id']; ?>">
                        <?php echo escapeHtml($conn['name']); ?>
                        (tracker #<?php echo (int) $conn['tracker_id']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">Si seleccionas una conexion, los campos Tiki se completan automaticamente</div>
            </div>
            <?php endif; ?>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Tiki API URL</label>
                    <input type="text" name="tiki_api_url" id="import-tiki_url" required placeholder="https://wiki.ejemplo.org/api/">
                </div>
                <div class="form-group">
                    <label>Tiki API Token</label>
                    <div class="input-wrapper">
                        <input type="password" name="tiki_api_token" id="import-tiki_token" required placeholder="Token de TikiWiki">
                        <button type="button" class="icon-btn" onclick="togglePassword(this)">Mostrar</button>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Tracker ID</label>
                    <input type="number" name="tracker_id" id="import-tracker_id" required placeholder="Ej: 22">
                </div>
                <div class="form-group">
                    <label>Archivo ZIP exportado de Telegram</label>
                    <input type="file" name="export_file" accept=".zip" required>
                </div>
            </div>
            
            <div style="margin-top:16px;">
                <button type="button" class="btn btn-primary" onclick="startImport()">Importar</button>
            </div>
        </form>
        
        <div id="import-result" style="margin-top:16px;"></div>
    </div>
</div>

<script>
function fillImportFromConnection(select) {
    var option = select.options[select.selectedIndex];
    if (option.value === '') return;
    
    document.getElementById('import-tiki_url').value = option.dataset.tiki_url;
    document.getElementById('import-tiki_token').value = option.dataset.tiki_token;
    document.getElementById('import-tracker_id').value = option.dataset.tracker_id;
}

function startImport() {
    var form = document.getElementById('import-form');
    var formData = new FormData(form);
    formData.append('mode', 'extract');
    var resultDiv = document.getElementById('import-result');
    var importBtn = form.querySelector('button');
    
    function setResult(text, isError) {
        resultDiv.innerHTML = '';
        if (isError) resultDiv.style.color = 'var(--error)';
        else resultDiv.style.color = '';
        resultDiv.textContent = text;
    }
    
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
    
    importBtn.disabled = true;
    importBtn.textContent = 'Importando...';
    
    setResult('Extrayendo archivo ZIP...');
    
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
            throw new Error('Respuesta invalida: ' + text.substring(0, 200));
        }
        if (data.error) throw new Error(data.error);
        if (data.status !== 'extracted') throw new Error('Respuesta inesperada');
        
        return processChunks(data.extract_id, data.total, data.chat_title, data.topics_found);
    })
    .then(function(result) {
        resultDiv.innerHTML = '';
        var div = document.createElement('div');
        div.style.cssText = 'background:#e8f5e9;padding:14px;border-radius:8px;color:#2e7d32;';
        
        var title = document.createElement('p');
        title.style.cssText = 'margin:0 0 8px 0;font-weight:bold;';
        title.textContent = 'Importacion completada';
        div.appendChild(title);
        
        var lines = [
            'Mensajes importados: ' + result.imported,
            'Errores: ' + result.skipped,
            'Archivos subidos: ' + result.media_processed,
            'Topics encontrados: ' + result.topics
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
        resultDiv.textContent = 'Error: ' + error.message;
    })
    .finally(function() {
        importBtn.disabled = false;
        importBtn.textContent = 'Importar';
    });
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
                    throw new Error('Respuesta invalida en lote: ' + text.substring(0, 200));
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
</script>

<?php endif; ?>

<!-- Footer: configuracion global -->
<div class="section" style="margin-top:32px;">
    <div class="section-header" style="cursor:pointer;" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'">
        Configuracion global
    </div>
    <div class="section-content" style="display:none;">
        <div class="form-group">
            <label>Contraseña de admin</label>
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div style="display:flex;gap:8px;align-items:flex-end;">
                    <div style="flex:1;">
                        <input type="password" name="admin_password" required minlength="8" placeholder="Minimo 8 caracteres">
                    </div>
                    <button type="submit" class="btn btn-outline">Cambiar</button>
                </div>
            </form>
        </div>
        
        <hr>
        
        <div class="form-group">
            <label>URL del Webhook</label>
            <div class="webhook-url">
                <div class="label">URL auto-detectada (usada al configurar webhook)</div>
                <?php echo escapeHtml(generateWebhookUrl()); ?>
            </div>
        </div>
        
        <div class="form-group">
            <label>Estado de debug</label>
            <p style="font-size:0.9em;color:var(--text-secondary);">
                DEBUG_MODE: <?php echo defined('DEBUG_MODE') && DEBUG_MODE ? 'Activado' : 'Desactivado'; ?>
                &middot; Async: <?php echo defined('ASYNC_PROCESSING') && ASYNC_PROCESSING ? 'Activado' : 'Desactivado'; ?>
            </p>
        </div>
    </div>
</div>

</div><!-- /container -->
</body>
</html>
