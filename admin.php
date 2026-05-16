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

require_once 'config.php';

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

// Rate limiting para login
function checkRateLimit() {
    $maxAttempts = 5;
    $lockoutTime = 15 * 60; // 15 minutos en segundos
    
    // Inicializar contadores si no existen
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }
    
    // Resetear si pasó el tiempo de bloqueo
    if (time() - $_SESSION['first_attempt_time'] > $lockoutTime) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }
    
    // Verificar si excedió el límite
    if ($_SESSION['login_attempts'] >= $maxAttempts) {
        $remainingTime = $lockoutTime - (time() - $_SESSION['first_attempt_time']);
        die("Demasiados intentos de login. Por favor espere " . ceil($remainingTime / 60) . " minutos antes de intentar nuevamente.");
    }
}

// Incrementar contador de intentos fallidos
function incrementFailedLogin() {
    $_SESSION['login_attempts']++;
}

// Resetear contador de intentos (login exitoso)
function resetFailedLogin() {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['first_attempt_time'] = time();
}

// Función para generar URL de webhook automática del servidor actual
function generateWebhookUrl() {
    // Detectar HTTPS - considerar varios headers de proxy
    $protocol = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        $protocol = 'https';
    }
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $scriptPath = rtrim($scriptPath, '/');
    return $protocol . '://' . $host . $scriptPath . '/api.php';
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
            $success = "Contraseña cambiada exitosamente";
        } else {
            $error = "Error al guardar la nueva contraseña";
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
    
    // Usar CUSTOM_WEBHOOK_URL si está configurada, sino auto-detectar
    $customUrl = $env['CUSTOM_WEBHOOK_URL'] ?? '';
    $webhookUrl = !empty($customUrl) ? $customUrl : generateWebhookUrl();
    
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
    
    // Incluir api.php para tener la función
    require_once __DIR__ . '/api.php';
    
    $newTrackerId = createTrackerWithFields($trackerName);
    
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
    
    <h2>Índice</h2>
    <ul>
        <li><a href="#config-general">1. Configuración general</a></li>
        <li><a href="#tracker-importar">2. Importar conversaciones</a></li>
        <li><a href="#tracker-webhook">3. Tracker en directo (recibe el webhook)</a></li>
        <li><a href="#crear-tracker">4. Crear Tracker en TikiWiki</a></li>
    </ul>
    
    <h2 id="config-general">1. Configuración general</h2>
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
    <p>URL del webhook: 
        <?php
        $detectedUrl = generateWebhookUrl();
        $customUrl = $env['CUSTOM_WEBHOOK_URL'] ?? '';
        $webhookUrl = !empty($customUrl) ? $customUrl : $detectedUrl;
        echo htmlspecialchars($webhookUrl);
        ?>
        <?php if (!empty($customUrl)): ?>
            <br><small>(CUSTOM_WEBHOOK_URL en .env sobreescribe la auto-detectada: <?php echo htmlspecialchars($detectedUrl); ?>)</small>
        <?php endif; ?>
    </p>
    <form method="post">
        <input type="hidden" name="action" value="update_webhook">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <button type="submit">Actualizar Webhook</button>
    </form>
    
    <h2 id="tracker-importar">2. Importar conversaciones</h2>
    <p>Importar conversaciones desde archivos ZIP exportados de Telegram. Los archivos (fotos, stickers, videos) se subirán a la file gallery del tracker.</p>
    <p><small>Límites: ZIP de hasta 50MB | Archivos multimedia individuales de hasta 20MB</small></p>
    
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
        var resultDiv = document.getElementById('import-result');
        
        resultDiv.innerHTML = 'Importando... Esto puede tomar varios minutos.';
        
        fetch('import.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        })
        .then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                resultDiv.innerHTML = '<p style="color: red;">Respuesta no válida: ' + text.substring(0, 200) + '</p>';
                return null;
            }
        })
        .then(data => {
            if (!data) return;
            if (data.error) {
                resultDiv.innerHTML = '<p style="color: red;">Error: ' + data.error + '</p>';
            } else {
                resultDiv.innerHTML = '<p style="color: green;">Importación completada:<br>' +
                    '- ' + data.imported + ' mensajes importados<br>' +
                    '- ' + data.skipped + ' errores<br>' +
                    '- ' + data.media_processed + ' archivos subidos<br>' +
                    '- ' + data.topics_found + ' topics encontrados</p>';
            }
        })
        .catch(error => {
            resultDiv.innerHTML = '<p style="color: red;">Error: ' + error.message + '</p>';
        });
    }
    </script>
    
    <h2 id="tracker-webhook">3. Tracker en directo (recibe el webhook)</h2>
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
    
    <h2 id="crear-tracker">4. Crear Tracker en TikiWiki</h2>
    
    <form method="post">
        <input type="hidden" name="action" value="create_tracker">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <label>Nombre del tracker:</label><br>
        <input type="text" name="tracker_name" value="Telegram Messages" size="50" required><br><br>
        
        <button type="submit">Crear Tracker</button>
    </form>
    
    <p><small>El tracker creado puede usarse como tracker en directo o para importar mensajes.</small></p>
</body>
</html>
