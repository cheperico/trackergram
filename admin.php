<?php
/**
 * trackerGram - Panel de Administración
 * 
 * Tres pestañas: Webhook (CRUD de conexiones), Importar (backfill ZIP) y Crear Tracker
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
require_once 'detect_helper.php';

// GC probabilístico para archivos tg_admin_rate_* viejos
gcAdminRateFiles();

// ── Helpers ──

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        // Si es una acción AJAX, responder con JSON + 403
        $ajaxActions = ['get_connection', 'test_connection', 'check_privacy'];
        if (isset($_POST['action']) && in_array($_POST['action'], $ajaxActions)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Token CSRF inválido o sesión expirada. Por favor recargue la página.']);
            exit;
        }
        die('Error: Token CSRF inválido. Por favor recargue la página e intente nuevamente.');
    }
}

/**
 * Leer/escribir datos de rate limit con flock(LOCK_EX) para evitar race conditions.
 * Reutiliza el archivo sin cerrar entre read/write dentro del mismo llamado.
 */
function readWriteRateData(string $ip, callable $mutate): void
{
    $rateFile = TEMP_DIR . '/tg_admin_rate_' . md5($ip);
    $fp = fopen($rateFile, 'c+');
    if (!$fp) {
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }

    // Leer data actual
    $data = ['attempts' => 0, 'first_attempt' => time()];
    $content = stream_get_contents($fp);
    if ($content !== false && $content !== '') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    // Aplicar mutación
    $mutate($data);

    // Escribir de vuelta (truncar primero)
    rewind($fp);
    $written = fwrite($fp, json_encode($data));
    if ($written !== false) {
        ftruncate($fp, ftell($fp));
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

function checkRateLimit() {
    $maxAttempts = 5;
    $lockoutTime = 15 * 60;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $rateFile = TEMP_DIR . '/tg_admin_rate_' . md5($ip);
    $fp = fopen($rateFile, 'c+');
    if (!$fp) {
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }

    $data = ['attempts' => 0, 'first_attempt' => time()];
    $content = stream_get_contents($fp);
    if ($content !== false && $content !== '') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    // Resetear si pasó la ventana
    if (time() - $data['first_attempt'] > $lockoutTime) {
        $data['attempts'] = 0;
        $data['first_attempt'] = time();
        rewind($fp);
        $written = fwrite($fp, json_encode($data));
        if ($written !== false) {
            ftruncate($fp, ftell($fp));
        }
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($data['attempts'] >= $maxAttempts) {
        $remainingTime = $lockoutTime - (time() - $data['first_attempt']);
        die("Demasiados intentos de login. Por favor espere " . ceil($remainingTime / 60) . " minutos antes de intentar nuevamente.");
    }
}

function incrementFailedLogin() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    readWriteRateData($ip, function(array &$data) {
        $data['attempts'] = ($data['attempts'] ?? 0) + 1;
    });
}

function resetFailedLogin() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    readWriteRateData($ip, function(array &$data) {
        $data['attempts'] = 0;
        $data['first_attempt'] = time();
    });
}

/**
 * GC probabilístico para archivos tg_admin_rate_* (1% de las requests).
 * Barre archivos con más de 1 hora de inactividad y los elimina.
 * Análogo al GC de tg_rate_* en api.php.
 */
function gcAdminRateFiles(): void {
    if (mt_rand(1, 100) !== 1) {
        return;
    }
    $pattern = (defined('TEMP_DIR') ? TEMP_DIR : __DIR__ . '/tmp') . '/tg_admin_rate_*';
    foreach (glob($pattern) as $file) {
        if (filemtime($file) < time() - 3600) {
            @unlink($file);
        }
    }
}

function generateWebhookUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($protocol === 'http' && !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $protocol = 'https';
    }
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    // Sanitizar host: solo caracteres válidos (alphanumérico, guión, punto, :port)
    // Si contiene algo extraño (inyección), forzar a SERVER_NAME como fallback seguro
    if (preg_match('/[^a-zA-Z0-9.\-:\[\]]/', $host)) {
        log_message("admin/generateWebhookUrl: Host header sospechoso '{$host}' — usando SERVER_NAME como fallback");
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    }

    // Si el host difiere de SERVER_NAME, loguear advertencia (posible proxy o inyección)
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    if ($serverName !== '' && $host !== $serverName) {
        log_message("admin/generateWebhookUrl: Host '{$host}' difiere de SERVER_NAME '{$serverName}' — verificar si hay proxy inverso");
    }

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
    <html lang="es">
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
            .login-card input:focus { outline: 2px solid #4a76a8; outline-offset: 1px; border-color: #4a76a8; }
            .login-card button { width: 100%; padding: 10px; background: #4a76a8; color: white; border: none; border-radius: 8px; font-size: 1em; font-weight: 600; cursor: pointer; }
            .login-card button:hover { background: #345583; }
            .login-card button:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
            .error { color: #c62828; font-size: 0.9em; margin-bottom: 12px; }
        </style>
    </head>
    <body>
        <div class="login-card" role="region" aria-labelledby="login-heading">
            <h1 id="login-heading">trackerGram</h1>
            <p>Ingresá con tu usuario y contraseña</p>
            <?php if ($loginFailed): ?><div class="error" role="alert">Usuario o contraseña incorrectos</div><?php endif; ?>
            <form method="post">
                <label for="login-username">Usuario</label>
                <input type="text" name="login_username" id="login-username" required aria-required="true">
                <label for="login-password">Contraseña</label>
                <input type="password" name="login_password" id="login-password" required aria-required="true">
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

// ── Procesar acciones POST (ANTES de llamadas lentas a APIs externas) ──
// Los handlers AJAX (get_connection, test_connection, check_privacy) hacen exit
// inmediato, evitando que los loops pesados de abajo se ejecuten.
$webhookStatuses = [];
include 'admin_handlers.php';

// ── Poblar bot_name y chat_title para conexiones existentes que aún no los tengan
foreach ($connections as $slug => $conn) {
    $needsUpdate = false;
    $updateFields = [];

    // Si tiene bot_token pero no bot_name, fetchear via getMe
    if (!empty($conn['bot_token']) && empty($conn['bot_name'])) {
        try {
            $tgClient = new TelegramClient($conn['bot_token']);
            $tgResult = $tgClient->testConnection();
            if ($tgResult['ok'] && !empty($tgResult['bot_name'])) {
                $updateFields['bot_name'] = $tgResult['bot_name'];
                $needsUpdate = true;
            }
        } catch (Exception $e) {
            log_message("admin: Error fetching bot_name for {$slug}: " . $e->getMessage());
        }
    }

    // Si tiene chat_id pero no chat_title, fetchear via getChat
    $chatId = (int) ($conn['chat_id'] ?? 0);
    if (!empty($conn['bot_token']) && $chatId > 0 && empty($conn['chat_title'])) {
        try {
            $tgClient = new TelegramClient($conn['bot_token']);
            $chatInfo = $tgClient->getChat($chatId);
            if ($chatInfo !== null && !empty($chatInfo['title'] ?? $chatInfo['username'] ?? '')) {
                $chatTitle = $chatInfo['title'] ?? $chatInfo['username'] ?? '';
                $updateFields['chat_title'] = $chatTitle;
                $needsUpdate = true;
            }
        } catch (Exception $e) {
            log_message("admin: Error fetching chat_title for {$slug}: " . $e->getMessage());
        }
    }

    // Auto-detectar field_prefix UNA SOLA VEZ por conexión (cacheado con flag field_prefix_checked)
    $trackerId = (int) ($conn['tracker_id'] ?? 0);
    $storedPrefix = $conn['field_prefix'] ?? 'telegrammessage';
    $prefixChecked = !empty($conn['field_prefix_checked']);
    if ($storedPrefix === 'telegrammessage' && !$prefixChecked && $trackerId > 0 && !empty($conn['tiki_api_url']) && !empty($conn['tiki_api_token'])) {
        try {
            $tikiClient = new TikiWikiClient(
                apiUrl: $conn['tiki_api_url'],
                token: $conn['tiki_api_token'],
                timeout: TIMEOUT_TIKIWIKI_API,
                uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
            );
            $resolvedPrefix = $tikiClient->resolveFieldPrefix($trackerId);
            if ($resolvedPrefix !== 'telegrammessage') {
                $updateFields['field_prefix'] = $resolvedPrefix;
                log_message("admin: Field prefix auto-detectado como '{$resolvedPrefix}' para conexión '{$slug}' (tracker {$trackerId})");
            } else {
                log_message("admin: Field prefix verificado como 'telegrammessage' para conexión '{$slug}' (tracker {$trackerId})");
            }
            // Marcar como verificado para NO repetir la llamada API en cada page load
            $updateFields['field_prefix_checked'] = true;
            $needsUpdate = true;
        } catch (Exception $e) {
            log_message("admin: Error detectando field_prefix para {$slug}: " . $e->getMessage());
            // Marcar como verificado igual para no reintentar si TikiWiki está caído
            $updateFields['field_prefix_checked'] = true;
            $needsUpdate = true;
        }
    }

    if ($needsUpdate) {
        $configManager->updateConnectionFields($slug, $updateFields);
        // Refrescar en $connections para que los cards muestren los nuevos valores
        foreach ($updateFields as $key => $value) {
            $connections[$slug][$key] = $value;
        }
    }
}

// ── Health check: estado del webhook para cada conexión ──
foreach ($connections as $slug => $conn) {
    $status = ['ok' => false, 'label' => 'Sin bot token', 'pending' => 0];
    if (!empty($conn['bot_token'])) {
        try {
            $tgClient = new TelegramClient($conn['bot_token']);
            $wh = $tgClient->getWebhookInfo();
            if (!empty($wh['url'])) {
                $status['ok'] = true;
                $expectedUrl = generateWebhookUrl();
                $urlMatch = $wh['url'] === $expectedUrl;
                $status['label'] = $urlMatch ? '✅' : ('⚠️ ' . parse_url($wh['url'], PHP_URL_HOST));
                $status['pending'] = (int) ($wh['pending_update_count'] ?? 0);
                if ($wh['pending_update_count'] > 10) {
                    $status['label'] = '⚠️ ' . $wh['pending_update_count'] . ' pend.';
                    $status['ok'] = false;
                }
                if (!empty($wh['last_error_message'])) {
                    $lastErrorDate = (int) ($wh['last_error_date'] ?? 0);
                    $lastSuccessDate = (int) ($wh['last_successful_synchronization'] ?? 0);
                    $noPending = ($wh['pending_update_count'] ?? 1) === 0;
                    if ($noPending || ($lastSuccessDate > 0 && $lastSuccessDate > $lastErrorDate)) {
                        // Error antiguo — webhook funciona correctamente desde entonces
                        $status['label'] = $urlMatch ? '✅ (error histórico)' : ('⚠️ ' . parse_url($wh['url'], PHP_URL_HOST));
                    } else {
                        $status['label'] = '❌ Error: ' . substr($wh['last_error_message'], 0, 40);
                        $status['ok'] = false;
                    }
                }
            } else {
                $status['label'] = '❌ No configurado';
            }
        } catch (Exception $e) {
            $status['label'] = '❓ Error';
        }
    }
    $webhookStatuses[$slug] = $status;
}

// ── Construir versión sanitizada para el frontend (después de todo el procesamiento) ──
$connectionsSafe = [];
foreach ($connections as $slug => $conn) {
    $safe = $conn;
    foreach (['bot_token', 'tiki_api_token', 'webhook_secret'] as $field) {
        if (!empty($safe[$field]) && strlen($safe[$field]) > 8) {
            $val = $safe[$field];
            $safe[$field] = substr($val, 0, 4) . '...' . substr($val, -4);
        }
    }
    $connectionsSafe[$slug] = $safe;
}

// ── Determinar tab activa ──
$activeTab = $_GET['tab'] ?? 'webhook';
if (!in_array($activeTab, ['webhook', 'import', 'create'])) {
    $activeTab = 'webhook';
}

// View mode: classic (default) or grouped (by bot)
$view = $_GET['view'] ?? 'grouped';
if (!in_array($view, ['classic', 'grouped'])) {
    $view = 'grouped';
}

// Para la edición, cargar conexión si se pasa slug
$editConnection = null;
if (isset($_GET['edit'])) {
    $editConnection = $configManager->getConnection($_GET['edit']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>trackerGram - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo generateCSRFToken(); ?>">
    <link rel="stylesheet" href="admin.css">
</head>
<body>
<!-- Skip link for keyboard users -->
<a href="#main-content" class="skip-link">Saltar al contenido principal</a>

<!-- Navbar -->
<nav class="navbar" aria-label="Navegación principal">
    <a href="admin.php" class="navbar-brand" aria-label="Volver al inicio (pestaña Webhook)">
        trackerGram <span>Admin <span style="font-weight:400;font-size:0.65em;opacity:0.6;margin-left:4px;"><?php echo TRACKERGRAM_VERSION; ?></span></span>
    </a>
    <div class="navbar-actions">
        <a href="?action=logout" aria-label="Cerrar sesión de administrador">Cerrar sesion</a>
    </div>
</nav>

<!-- Tabs -->
<nav class="tabs" aria-label="Secciones de administración">
    <a href="?tab=webhook" class="tab <?php echo $activeTab === 'webhook' ? 'active' : ''; ?>" aria-current="<?php echo $activeTab === 'webhook' ? 'page' : 'false'; ?>">Webhook</a>
    <a href="?tab=import" class="tab <?php echo $activeTab === 'import' ? 'active' : ''; ?>" aria-current="<?php echo $activeTab === 'import' ? 'page' : 'false'; ?>">Importar</a>
    <a href="?tab=create" class="tab <?php echo $activeTab === 'create' ? 'active' : ''; ?>" aria-current="<?php echo $activeTab === 'create' ? 'page' : 'false'; ?>">Crear Tracker</a>
</nav>

<div class="container" id="main-content" role="main">

<?php if ($successMessage): ?>
    <div class="alert alert-success" role="alert" aria-live="polite"><?php echo escapeHtml($successMessage); ?></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert alert-error" role="alert" aria-live="assertive"><?php echo escapeHtml($errorMessage); ?></div>
<?php endif; ?>

<?php if ($activeTab === 'webhook'): ?>

<!-- ===== TAB: WEBHOOK ===== -->

<!-- View toggle: classic / grouped -->
<div class="view-toggle">
    <a href="?tab=webhook&view=classic" class="btn btn-sm <?php echo $view === 'classic' ? 'btn-primary' : 'btn-outline'; ?>">Vista clasica</a>
    <a href="?tab=webhook&view=grouped" class="btn btn-sm <?php echo $view === 'grouped' ? 'btn-primary' : 'btn-outline'; ?>">Vista agrupada</a>
</div>

<?php if ($view === 'classic'): ?>
<!-- ── VISTA CLASICA ── -->
<div class="section">
    <div class="section-header">Conexiones Activas</div>
    <div class="section-content">
        <?php if (empty($connections)): ?>
            <div class="empty-state">
                <p>No hay conexiones configuradas.</p>
                <button class="btn btn-primary" onclick="resetConnectionForm(); openModal('connection-modal')" title="Agregar una nueva conexión entre un bot de Telegram y un tracker de TikiWiki">+ Agregar conexion</button>
            </div>
        <?php else: ?>
            <div style="margin-bottom:16px;">
                <button class="btn btn-primary" onclick="resetConnectionForm(); openModal('connection-modal')" title="Agregar una nueva conexión entre un bot de Telegram y un tracker de TikiWiki">+ Agregar conexion</button>
            </div>
            <div class="conn-list">
                <?php foreach ($connectionsSafe as $slug => $conn): ?>
                <div class="conn-card">
                    <div class="conn-header">
                        <div class="conn-name">
                            <span class="conn-status <?php echo $conn['enabled'] ? 'active' : 'inactive'; ?>" aria-label="<?php echo $conn['enabled'] ? 'Conexión activa' : 'Conexión inactiva'; ?>"></span>
                            <?php echo escapeHtml($conn['name']); ?>
                            <?php if (!$conn['enabled']): ?>
                                <span style="font-size:0.8em;color:var(--text-secondary);font-weight:400;">(inactivo)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="conn-details">
                        <span>Bot: <?php 
                            $botDisplay = !empty($conn['bot_name']) ? '@' . escapeHtml($conn['bot_name']) : ($conn['bot_token'] ?? 'Sin token');
                            echo $botDisplay;
                        ?></span>
                        <span>Chat: <?php
                            $chatIdVal = (int) ($conn['chat_id'] ?? 0);
                            if (!empty($conn['chat_title'])) {
                                echo escapeHtml($conn['chat_title']);
                                if ($chatIdVal != 0) {
                                    echo ' <span style="font-size:0.85em;opacity:0.7;">(ID: ' . $chatIdVal . ')</span>';
                                }
                            } elseif ($chatIdVal != 0) {
                                echo 'ID: ' . $chatIdVal;
                            } else {
                                echo 'Pendiente';
                            }
                        ?></span>
                        <span>Tracker: #<?php echo (int) $conn['tracker_id']; ?></span>
                        <span><?php echo escapeHtml(parse_url($conn['tiki_api_url'] ?? '', PHP_URL_HOST) ?: $conn['tiki_api_url']); ?></span>
                        <span title="Estado del webhook">Webhook: <?php 
                            $ws = $webhookStatuses[$slug] ?? ['label' => '❓'];
                            echo $ws['label'];
                        ?></span>
                    </div>
                    <div class="conn-actions">
                        <form method="get" class="inline-form">
                            <input type="hidden" name="tab" value="webhook">
                            <input type="hidden" name="edit" value="<?php echo escapeHtml($slug); ?>">
                            <button type="submit" class="btn btn-outline btn-sm" onclick="event.preventDefault(); openEditModal('<?php echo escapeHtml($slug); ?>')" title="Editar los datos de esta conexión">Editar</button>
                        </form>
                        
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="duplicate_connection">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($slug); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-outline btn-sm" title="Duplicar esta conexión para crear una similar">Duplicar</button>
                        </form>
                        
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="toggle_connection">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($slug); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-sm <?php echo $conn['enabled'] ? 'btn-warning' : 'btn-success'; ?>" title="<?php echo $conn['enabled'] ? 'Desactivar temporalmente esta conexión' : 'Activar esta conexión'; ?>">
                                <?php echo $conn['enabled'] ? 'Desactivar' : 'Activar'; ?>
                            </button>
                        </form>
                        
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="configure_webhook">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($slug); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-outline btn-sm" title="Configurar el webhook en Telegram para recibir mensajes">Configurar Webhook</button>
                        </form>
                        
                        <button class="btn btn-outline btn-sm" onclick="testConnection('<?php echo escapeHtml($slug); ?>', this)" title="Probar conexión con Telegram y TikiWiki">Test</button>
                        
                        <button class="btn btn-outline btn-sm" onclick="checkPrivacy('<?php echo escapeHtml($slug); ?>', this)" title="Ver últimos mensajes recibidos por el bot (para verificar privacy mode)">📡 Updates</button>
                        
                        <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar conexion \'<?php echo escapeHtml($conn['name']); ?>\'?')">
                            <input type="hidden" name="action" value="delete_connection">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($slug); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-danger btn-sm" title="Eliminar esta conexión permanentemente">Eliminar</button>
                        </form>
                        
                        <div class="test-result" style="display:none;flex-basis:100%;" aria-live="polite" aria-atomic="true"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ── VISTA AGRUPADA ── -->

<?php
// Group connections by bot_token (usar $connectionsSafe para datos sanitizados en vista)
$grouped = [];
foreach ($connectionsSafe as $slug => $conn) {
    $botToken = $conn['bot_token'] ?? '';
    if ($botToken === '') {
        $botToken = '__no_token__';
    }
    if (!isset($grouped[$botToken])) {
        $grouped[$botToken] = [
            'bot_name' => $conn['bot_name'] ?? '',
            'bot_token' => $conn['bot_token'] ?? '',
            'slug' => $slug,
            'connections' => [],
        ];
    }
    if (empty($grouped[$botToken]['bot_name']) && !empty($conn['bot_name'])) {
        $grouped[$botToken]['bot_name'] = $conn['bot_name'];
    }
    $grouped[$botToken]['connections'][] = ['slug' => $slug] + $conn;
}
?>

<?php if (empty($connections)): ?>
    <div class="section">
        <div class="section-content">
            <div class="empty-state">
                <p>No hay conexiones configuradas.</p>
                <button class="btn btn-primary" onclick="resetConnectionForm(); openModal('connection-modal')" title="Agregar una nueva conexión">+ Agregar conexion</button>
            </div>
        </div>
    </div>
<?php else: ?>
    <div style="margin-bottom:16px;">
        <button class="btn btn-primary" onclick="resetConnectionForm(); openModal('connection-modal')" title="Agregar una nueva conexión">+ Agregar conexion</button>
    </div>
    <?php foreach ($grouped as $botToken => $bot): ?>
        <?php 
        $firstSlug = $bot['connections'][0]['slug'] ?? '';
        $whStatus = $webhookStatuses[$firstSlug] ?? ['label' => '❓', 'pending' => 0];
        $connCount = count($bot['connections']);
        ?>
        <div class="bot-card">
            <div class="bot-header">
                <div class="bot-name-col">
                    <div class="bot-icon" aria-hidden="true">🤖</div>
                    <div>
                        <div class="bot-title">
                            <?php if (!empty($bot['bot_name'])): ?>
                                @<?php echo escapeHtml($bot['bot_name']); ?>
                            <?php else: ?>
                                Bot sin nombre
                            <?php endif; ?>
                            <span style="font-weight:400;font-size:0.8em;color:var(--text-secondary);margin-left:6px;">
                                (<?php echo $connCount; ?> conexion<?php echo $connCount !== 1 ? 'es' : ''; ?>)
                            </span>
                        </div>
                        <div class="bot-token-masked">
                            Token: <?php echo escapeHtml($bot['bot_token']); ?>
                        </div>
                    </div>
                </div>
                <div class="bot-webhook-col">
                    <span class="webhook-indicator <?php echo $whStatus['ok'] ? 'ok' : 'fail';?>">
                        Webhook: <?php echo $whStatus['label']; ?>
                    </span>
                    <?php if (($whStatus['pending'] ?? 0) > 0): ?>
                        <span class="pending-badge"><?php echo (int) $whStatus['pending']; ?> pend.</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="bot-actions">
                <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="configure_webhook">
                    <input type="hidden" name="slug" value="<?php echo escapeHtml($firstSlug); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="submit" class="btn btn-outline btn-sm" title="Configurar el webhook en Telegram para este bot">Configurar Webhook</button>
                </form>
                <button class="btn btn-outline btn-sm" onclick="testBotConnection('<?php echo escapeHtml($firstSlug); ?>', this)" title="Probar conexión con Telegram y estado del webhook">Test Bot</button>
                <button class="btn btn-outline btn-sm" onclick="checkPrivacy('<?php echo escapeHtml($firstSlug); ?>', this)" title="Ver mensajes recibidos para verificar privacy mode">📡 Updates</button>
                <div class="test-result" style="display:none;flex-basis:100%;" aria-live="polite" aria-atomic="true"></div>
            </div>
            <div class="bot-connections">
                <?php foreach ($bot['connections'] as $conn): ?>
                <div class="sub-conn-card">
                    <div class="sub-conn-header">
                        <span class="conn-status <?php echo $conn['enabled'] ? 'active' : 'inactive'; ?>" aria-label="<?php echo $conn['enabled'] ? 'Activa' : 'Inactiva'; ?>"></span>
                        <span class="sub-conn-name">
                            <?php echo escapeHtml($conn['name']); ?>
                            <?php if (!$conn['enabled']): ?>
                                <span class="inactive-label">(inactivo)</span>
                            <?php endif; ?>
                        </span>
                        <span class="tracker-badge">Tracker #<?php echo (int) $conn['tracker_id']; ?></span>
                    </div>
                    <div class="sub-conn-details">
                        <span>Chat: <?php
                            $chatIdVal = (int) ($conn['chat_id'] ?? 0);
                            if (!empty($conn['chat_title'])) {
                                echo escapeHtml($conn['chat_title']);
                                if ($chatIdVal != 0) {
                                    echo ' <span style="font-size:0.85em;opacity:0.7;">(ID: ' . $chatIdVal . ')</span>';
                                }
                            } elseif ($chatIdVal != 0) {
                                echo 'ID: ' . $chatIdVal;
                            } else {
                                echo 'Pendiente';
                            }
                        ?></span>
                        <span><?php echo escapeHtml(parse_url($conn['tiki_api_url'] ?? '', PHP_URL_HOST) ?: $conn['tiki_api_url']); ?></span>
                        <span>Prefix: <?php echo escapeHtml($conn['field_prefix'] ?? 'telegrammessage'); ?></span>
                    </div>
                    <div class="sub-conn-actions">
                        <button class="btn btn-outline btn-sm" onclick="event.preventDefault(); openEditModal('<?php echo escapeHtml($conn['slug']); ?>')" title="Editar">Editar</button>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="duplicate_connection">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($conn['slug']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-outline btn-sm" title="Duplicar esta conexión">Duplicar</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="toggle_connection">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($conn['slug']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-sm <?php echo $conn['enabled'] ? 'btn-warning' : 'btn-success'; ?>" title="<?php echo $conn['enabled'] ? 'Desactivar' : 'Activar'; ?>">
                                <?php echo $conn['enabled'] ? 'Desactivar' : 'Activar'; ?>
                            </button>
                        </form>
                        <button class="btn btn-outline btn-sm" onclick="testTikiConnection('<?php echo escapeHtml($conn['slug']); ?>', this)" title="Probar conexión con TikiWiki">Test Tiki</button>
                        <form method="post" class="inline-form" style="display:inline;">
                            <input type="hidden" name="action" value="sync_tracker">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($conn['slug']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-outline btn-sm" title="Sincronizar campos del tracker con los esperados por trackerGram">🛠️ Sync</button>
                        </form>
                        <form method="post" class="inline-form" onsubmit="return confirm('<?php echo addslashes('¿Eliminar conexion \'' . $conn['name'] . '\'?'); ?>')">
                            <input type="hidden" name="action" value="delete_connection">
                            <input type="hidden" name="slug" value="<?php echo escapeHtml($conn['slug']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <button type="submit" class="btn btn-danger btn-sm" title="Eliminar esta conexión permanentemente">Eliminar</button>
                        </form>
                        <div class="test-result sync-result" style="display:none;flex-basis:100%;" aria-live="polite" aria-atomic="true"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php endif; ?><!-- /view -->

<?php
// ── Chats detectados ──
$detections = getDetections();
$detectionsBySlug = [];
foreach ($detections as $det) {
    $detectionsBySlug[$det['slug']][] = $det;
}
?>
<?php if (!empty($detectionsBySlug)): ?>
<div class="section">
    <div class="section-header">📡 Chats detectados</div>
    <div class="section-content">
        <p style="font-size:0.85em;color:var(--text-secondary);margin-bottom:16px;">
            El bot recibió mensajes de chats que aún no tienen un chat_id asignado.
            Verificá que sea el grupo correcto y asignalo a la conexión correspondiente.
        </p>
        <?php foreach ($detectionsBySlug as $slug => $chats): 
            $conn = $configManager->getConnection($slug);    
        ?>
        <div style="margin-bottom:16px;padding:12px;border:1px solid var(--border);border-radius:8px;">
            <div style="font-weight:600;font-size:0.9em;margin-bottom:8px;">
                Conexión: <?php echo escapeHtml($conn['name'] ?? $slug); ?>
                <span style="font-weight:400;color:var(--text-secondary);">(slug: <?php echo escapeHtml($slug); ?>)</span>
            </div>
            <?php foreach ($chats as $det): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;margin-bottom:6px;border:1px solid var(--border);border-radius:6px;">
                <div>
                    <strong><?php echo escapeHtml($det['chat_title']); ?></strong><br>
                    <span style="font-size:0.85em;color:var(--text-secondary);">
                        ID: <?php echo (int) $det['chat_id']; ?> — 
                        Detectado: <?php echo date('Y-m-d H:i', strtotime($det['detected_at'])); ?>
                        (<?php echo (int) ($det['detected_count'] ?? 1); ?> veces)
                    </span>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="assign_chat">
                        <input type="hidden" name="slug" value="<?php echo escapeHtml($slug); ?>">
                        <input type="hidden" name="chat_id" value="<?php echo (int) $det['chat_id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <button type="submit" class="btn btn-success btn-sm" title="Asignar este chat a la conexión">Asignar</button>
                    </form>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="ignore_chat">
                        <input type="hidden" name="slug" value="<?php echo escapeHtml($slug); ?>">
                        <input type="hidden" name="chat_id" value="<?php echo (int) $det['chat_id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <button type="submit" class="btn btn-outline btn-sm" title="Ignorar este chat y no mostrar más esta detección">Ignorar</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Crear/Editar conexion -->
<div class="modal-overlay" id="connection-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title" aria-hidden="true">
    <div class="modal">
        <form method="post">
            <input type="hidden" name="action" value="save_connection">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="slug" id="form-slug" value="">
            
            <div class="modal-header">
                <span id="modal-title">Nueva conexion</span>
                <button type="button" class="modal-close" onclick="closeModal('connection-modal')" aria-label="Cerrar">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="form-name">Nombre de la conexion</label>
                    <input type="text" name="name" id="form-name" required aria-required="true" placeholder="Ej: QPCH Produccion" title="Nombre descriptivo para identificar esta conexión">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="form-bot_token">Bot Token</label>
                        <div class="input-wrapper">
                            <input type="password" name="bot_token" id="form-bot_token" required aria-required="true" autocomplete="new-password" placeholder="Token de @BotFather" title="Token del bot de Telegram obtenido de @BotFather">
                            <button type="button" class="icon-btn" onclick="togglePassword(this)" title="Mostrar u ocultar el token" aria-label="Mostrar contraseña">Mostrar</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="form-webhook_secret">Webhook Secret</label>
                        <div class="input-wrapper">
                            <input type="password" name="webhook_secret" id="form-webhook_secret" autocomplete="new-password" placeholder="Auto-generado si se deja vacio" aria-describedby="hint-webhook_secret" title="Secreto del webhook para verificar que los mensajes vienen de Telegram">
                            <button type="button" class="icon-btn" onclick="togglePassword(this)" title="Mostrar u ocultar el secreto" aria-label="Mostrar contraseña">Mostrar</button>
                        </div>
                        <div class="hint" id="hint-webhook_secret">Dejar vacio para generar uno automaticamente</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="form-chat_id">Chat ID</label>
                        <input type="number" name="chat_id" id="form-chat_id" value="0" placeholder="0 = pendiente" aria-describedby="hint-chat_id" title="ID numérico del grupo o chat de Telegram">
                        <div class="hint" id="hint-chat_id">Agrega el bot al grupo y revisa los logs para obtenerlo</div>
                    </div>
                    <div class="form-group">
                        <label for="form-tracker_id">Tracker ID</label>
                        <input type="number" name="tracker_id" id="form-tracker_id" required aria-required="true" placeholder="Ej: 22" title="ID del tracker en TikiWiki donde se guardarán los mensajes">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="form-tiki_api_url">Tiki API URL</label>
                    <input type="text" name="tiki_api_url" id="form-tiki_api_url" required aria-required="true" placeholder="https://wiki.ejemplo.org/api/" title="URL base de la API REST de TikiWiki">
                </div>
                
                <div class="form-group">
                    <label for="form-tiki_api_token">Tiki API Token</label>
                    <div class="input-wrapper">
                        <input type="password" name="tiki_api_token" id="form-tiki_api_token" required aria-required="true" autocomplete="new-password" placeholder="Token de TikiWiki" title="Token de autenticación de la API de TikiWiki">
                        <button type="button" class="icon-btn" onclick="togglePassword(this)" title="Mostrar u ocultar el token" aria-label="Mostrar contraseña">Mostrar</button>
                    </div>
                </div>
                
                <div class="checkbox-row">
                    <input type="checkbox" name="enabled" id="form-enabled" checked>
                    <label for="form-enabled">Activa</label>
                </div>
                
                <div class="checkbox-row">
                    <input type="checkbox" name="async_processing" id="form-async_processing" aria-describedby="hint-async">
                    <label for="form-async_processing">Procesamiento asincrono (buffer + worker)</label>
                    <div class="hint" id="hint-async" style="margin-left:24px;">api.php responde 200 al instante, worker.php procesa en background (requiere cron)</div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('connection-modal')" title="Cancelar y cerrar">Cancelar</button>
                <button type="submit" class="btn btn-primary" title="Guardar los datos de la conexión">Guardar conexion</button>
            </div>
        </form>
    </div>
</div>

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
                    <label for="import-connection-slug">Usar conexion existente (opcional)</label>
                        <select name="connection_slug" id="import-connection-slug" onchange="fillConnectionSlug(this, 'import-')" title="Seleccionar una conexión existente">
                            <option value="">— Ingresar manual —</option>
                        <?php foreach ($connectionsSafe as $slug => $conn): ?>
                        <option value="<?php echo escapeHtml($slug); ?>">
                            <?php echo escapeHtml($conn['name']); ?>
                            (tracker #<?php echo (int) $conn['tracker_id']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
            </div>
            <?php endif; ?>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="import-tiki_url">Tiki API URL</label>
                    <input type="text" name="tiki_api_url" id="import-tiki_url" required aria-required="true" placeholder="https://wiki.ejemplo.org/api/" title="URL base de la API REST de TikiWiki">
                </div>
                <div class="form-group">
                    <label for="import-tiki_token">Tiki API Token</label>
                    <div class="input-wrapper">
                        <input type="password" name="tiki_api_token" id="import-tiki_token" required aria-required="true" autocomplete="new-password" placeholder="Token de TikiWiki" title="Token de autenticación de la API de TikiWiki">
                        <button type="button" class="icon-btn" onclick="togglePassword(this)" title="Mostrar u ocultar el token" aria-label="Mostrar contraseña">Mostrar</button>
                    </div>
                </div>
            </div>
            <input type="hidden" name="field_prefix" id="import-field_prefix" value="telegrammessage">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="import-tracker_id">Tracker ID</label>
                    <input type="number" name="tracker_id" id="import-tracker_id" required aria-required="true" placeholder="Ej: 22" title="ID del tracker en TikiWiki donde se importarán los mensajes">
                </div>
                <div class="form-group">
                    <label for="import-export_file">Archivo ZIP exportado de Telegram</label>
                    <input type="file" name="export_file" id="import-export_file" accept=".zip" required title="Archivo ZIP con el export de conversaciones de Telegram">
                </div>
            </div>
            
            <div style="margin-top:16px;">
                <button type="button" class="btn btn-primary" onclick="startImport()" title="Iniciar importación del archivo ZIP seleccionado">Importar</button>
            </div>
        </form>
        
        <div id="import-result" style="margin-top:16px;" aria-live="polite" aria-atomic="true"></div>
    </div>
</div>

<script src="admin_import.js"></script>

<?php elseif ($activeTab === 'create'): ?>

<!-- ===== TAB: CREAR TRACKER ===== -->

<div class="section">
    <div class="section-header">Crear tracker en TikiWiki</div>
    <div class="section-content">
        <p style="margin-bottom:16px;">
            Crea un tracker completo en TikiWiki con todos los campos necesarios
            para recibir mensajes de Telegram. Se crea automáticamente la galería
            de medios y se configura el campo FG.
        </p>
        
        <form method="post">
            <input type="hidden" name="action" value="create_tracker">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <div class="form-group">
                <label for="create-tracker-name">Nombre del tracker *</label>
                <input type="text" name="tracker_name" id="create-tracker-name" required aria-required="true" placeholder="Ej: Conversaciones del grupo de Telegram" value="<?php echo escapeHtml($_POST['tracker_name'] ?? ''); ?>" aria-describedby="hint-tracker-name" title="Nombre con el que se creará el tracker en TikiWiki">
                <div class="hint" id="hint-tracker-name">Este nombre se usará como nombre del tracker en TikiWiki</div>
            </div>
            
            <div class="form-group">
                <label for="create-tracker-desc">Descripción (opcional)</label>
                <input type="text" name="tracker_description" id="create-tracker-desc" placeholder="Breve descripción del tracker" value="<?php echo escapeHtml($_POST['tracker_description'] ?? ''); ?>" title="Descripción opcional del tracker">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="create-field-prefix">Field prefix</label>
                    <input type="text" name="field_prefix" id="create-field-prefix" value="<?php echo escapeHtml($_POST['field_prefix'] ?? 'telegrammessage'); ?>" placeholder="telegrammessage" pattern="[a-z][a-z0-9]*" maxlength="16" aria-describedby="hint-prefix" title="Prefijo para los nombres de campo del tracker">
                    <div class="hint" id="hint-prefix">
                        Prefijo para los nombres de campo (permNames). 
                        Solo minúsculas + números, máximo 16 caracteres.
                        Ej: <code>qpch</code>, <code>soporte</code>, <code>chelapedia</code>
                    </div>
                </div>
                <div class="form-group">
                    <label for="create-connection-slug">Asignar a conexión (opcional)</label>
                    <select name="connection_slug" id="create-connection-slug" onchange="fillConnectionSlug(this, 'create-')" title="Seleccioná una conexión para ver sus datos abajo">
                        <option value="">— Solo crear tracker —</option>
                        <?php foreach ($connectionsSafe as $slug => $conn): ?>
                        <option value="<?php echo escapeHtml($slug); ?>"
                            <?php echo ($_POST['connection_slug'] ?? '') === $slug ? 'selected' : ''; ?>>
                            <?php echo escapeHtml($conn['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Seleccioná una conexión para recordar sus datos al asignar</div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="create-tiki-url">Tiki API URL <span id="create-url-required" style="color:var(--error);">*</span></label>
                    <input type="text" name="tiki_api_url" id="create-tiki-url" required aria-required="true" placeholder="https://wiki.ejemplo.org/api/" value="<?php echo escapeHtml($_POST['tiki_api_url'] ?? ''); ?>" title="URL base de la API REST de TikiWiki">
                </div>
                <div class="form-group">
                    <label for="create-tiki-token">Tiki API Token <span id="create-token-required" style="color:var(--error);">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="tiki_api_token" id="create-tiki-token" required aria-required="true" autocomplete="new-password" placeholder="Token de TikiWiki" value="<?php echo escapeHtml($_POST['tiki_api_token'] ?? ''); ?>" title="Token de autenticación de la API de TikiWiki">
                        <button type="button" class="icon-btn" onclick="togglePassword(this)" title="Mostrar u ocultar el token" aria-label="Mostrar contraseña">Mostrar</button>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <div style="border:1px solid var(--border);border-radius:8px;padding:12px 16px;font-size:0.85em;color:var(--text);" aria-live="polite" aria-atomic="true">
                    <strong>📋 Vista previa de campos que se crearán:</strong>
                    <div style="margin-top:6px;font-family:monospace;font-size:0.9em;" id="field-preview">
                        <span id="preview-prefix"><?php echo escapeHtml($_POST['field_prefix'] ?? 'telegrammessage'); ?></span>TelegramMessageId,
                        <span id="preview-prefix2"><?php echo escapeHtml($_POST['field_prefix'] ?? 'telegrammessage'); ?></span>ChatId,
                        <span id="preview-prefix3"><?php echo escapeHtml($_POST['field_prefix'] ?? 'telegrammessage'); ?></span>Text, ...
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="create-gallery-id">Gallery ID (opcional)</label>
                <input type="number" name="gallery_id" id="create-gallery-id" placeholder="Dejar vacío para auto-crear" value="<?php echo escapeHtml($_POST['gallery_id'] ?? ''); ?>" title="Si ya tenés una galería, ingresá su ID para usarla">
                <div class="hint" id="hint-gallery-id">
                    Si ya tenés una galería de archivos en TikiWiki, ingresá su ID para usarla.
                    Si se deja vacío, trackerGram intentará crear una automáticamente.
                </div>
            </div>
            
            <div style="margin-top:16px;">
                <button type="submit" class="btn btn-primary" title="Crear el tracker en TikiWiki con los campos especificados">Crear Tracker</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- Footer: configuracion global -->
<div class="section" style="margin-top:32px;">
    <div class="section-header" style="cursor:pointer;" onclick="toggleGlobalConfig(this)" role="button" tabindex="0" aria-expanded="false" aria-controls="config-content" onkeydown="if(event.key==='Enter'||event.key===' ') { event.preventDefault(); toggleGlobalConfig(this); }">
        Configuracion global
    </div>
    <div class="section-content" id="config-content" style="display:none;">
        <div class="form-group">
            <label for="admin-password-input">Contraseña de admin</label>
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div style="display:flex;gap:8px;align-items:flex-end;">
                    <div style="flex:1;">
                        <input type="password" name="admin_password" id="admin-password-input" required minlength="8" placeholder="Minimo 8 caracteres" title="Nueva contraseña de administrador (mínimo 8 caracteres)">
                    </div>
                    <button type="submit" class="btn btn-outline" title="Cambiar la contraseña de administrador">Cambiar</button>
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

<script src="admin.js"></script>
</body>
</html>
