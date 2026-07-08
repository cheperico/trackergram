<?php
/**
 * trackerGram - Handlers POST del panel de administración
 * 
 * Incluido desde admin.php. Opera sobre variables del scope del llamante:
 *   $configManager, $connections, $webhookStatuses
 *   $successMessage, $errorMessage (modificables)
 * 
 * Los handlers AJAX (get_connection, test_connection, check_privacy)
 * terminan con exit; los demás retornan y el HTML sigue.
 */

// ── Procesar acciones POST ──

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    validateCSRFToken($csrfToken);
    
    // ── Obtener datos de conexión (AJAX, sin exponer tokens en HTML) ──
    // ⚠️ SEGURIDAD: Este endpoint devuelve tokens COMPLETOS (bot_token, tiki_api_token,
    // webhook_secret) vía AJAX. Es necesario para que el modal de edición pueda
    // poblar los campos. El acceso está protegido por: (a) sesión admin autenticada,
    // (b) token CSRF. Sin embargo, si un atacante obtiene acceso a la sesión admin
    // (XSS, cookie theft), podría extraer todos los tokens via este endpoint.
    // No cachear la respuesta para evitar que tokens queden en cachés intermedias.
    if ($_POST['action'] === 'get_connection') {
        $slug = $_POST['slug'] ?? '';
        $conn = $configManager->getConnection($slug);
        if ($conn) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            echo json_encode($conn);
        } else {
            http_response_code(404);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            echo json_encode(['error' => 'Conexión no encontrada']);
        }
        exit;
    }
    
    switch ($_POST['action']) {
        
        // ── Cambiar contraseña ──
        case 'change_password':
            $newPassword = trim($_POST['admin_password'] ?? '');
            if (strlen($newPassword) < 8) {
                $errorMessage = __('msg.password_short');
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
                        $successMessage = __('msg.password_changed');
                    } else {
                        $errorMessage = __('msg.password_error');
                    }
                } else {
                    $errorMessage = __('msg.env_not_found');
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
                'async_processing' => isset($_POST['async_processing']),
            ];
            
            // Si es edición, pasar el slug existente
            if (!empty($slug)) {
                $data['slug'] = $slug;
                // Preservar field_prefix existente (el form no lo incluye)
                $existing = $configManager->getConnection($slug);
                if ($existing && !empty($existing['field_prefix'])) {
                    $data['field_prefix'] = $existing['field_prefix'];
                }
            }
            
            try {
                $newSlug = $configManager->saveConnection($data);
                $successMessage = sprintf(__('msg.saved'), $data['name'], $newSlug);
                $connections = $configManager->listConnections(); // refrescar

                // Intentar fetchear bot_name y chat_title (no crítico si falla)
                if (!empty($data['bot_token'])) {
                    $tgClient = new TelegramClient($data['bot_token']);
                    $tgResult = $tgClient->testConnection();
                    if ($tgResult['ok'] && !empty($tgResult['bot_name'])) {
                        $configManager->updateConnectionFields($newSlug, ['bot_name' => $tgResult['bot_name']]);
                        $connections = $configManager->listConnections(); // refrescar de nuevo
                    }
                    $chatId = (int) ($data['chat_id'] ?? 0);
                    if ($chatId > 0) {
                        $chatInfo = $tgClient->getChat($chatId);
                        if ($chatInfo !== null && !empty($chatInfo['title'] ?? $chatInfo['username'] ?? '')) {
                            $chatTitle = $chatInfo['title'] ?? $chatInfo['username'] ?? '';
                            $configManager->updateConnectionFields($newSlug, ['chat_title' => $chatTitle]);
                            $connections = $configManager->listConnections();
                        }
                    }
                }
            } catch (Exception $e) {
                $errorMessage = __('msg.save_error') . ': ' . $e->getMessage();
            }
            break;
        
        // ── Eliminar conexión ──
        case 'delete_connection':
            $slug = $_POST['slug'] ?? '';
            if ($configManager->deleteConnection($slug)) {
                $successMessage = __('msg.deleted');
                $connections = $configManager->listConnections();
            } else {
                $errorMessage = __('msg.delete_error');
            }
            break;
        
        // ── Duplicar conexión ──
        case 'duplicate_connection':
            $slug = $_POST['slug'] ?? '';
            $newSlug = $configManager->duplicateConnection($slug);
            if ($newSlug) {
                $newName = $configManager->getConnection($newSlug)['name'] ?? $newSlug;
                $successMessage = sprintf(__('msg.duplicated'), $newName);
                $connections = $configManager->listConnections();
            } else {
                $errorMessage = __('msg.duplicate_error');
            }
            break;
        
        // ── Activar/Desactivar conexión ──
        case 'toggle_connection':
            $slug = $_POST['slug'] ?? '';
            $conn = $configManager->getConnection($slug);
            if ($conn) {
                if ($conn['enabled']) {
                    $configManager->disableConnection($slug);
                    $successMessage = __('msg.toggled_off');
                } else {
                    $configManager->enableConnection($slug);
                    $successMessage = __('msg.toggled_on');
                }
                $connections = $configManager->listConnections();
            } else {
                $errorMessage = __('msg.err_not_found');
            }
            break;

        // ── Sincronizar tracker: crear campos faltantes ──
        case 'sync_tracker':
            $slug = $_POST['slug'] ?? '';
            $conn = $configManager->getConnection($slug);
            if (!$conn) {
                $errorMessage = __('msg.err_not_found');
                break;
            }
            $trackerId = (int) ($conn['tracker_id'] ?? 0);
            if ($trackerId <= 0) {
                $errorMessage = __('msg.err_no_tracker');
                break;
            }
            $storedPrefix = $conn['field_prefix'] ?? 'telegrammessage';
            $tikiClient = new TikiWikiClient(
                apiUrl: $conn['tiki_api_url'],
                token: $conn['tiki_api_token'],
                timeout: TIMEOUT_TIKIWIKI_API
            );
            // Si el prefix es el default o está vacío, intentar auto-detectar desde el tracker
            if ($storedPrefix === 'telegrammessage' || $storedPrefix === '') {
                $resolvedPrefix = $tikiClient->resolveFieldPrefix($trackerId);
                if ($resolvedPrefix !== $storedPrefix) {
                    log_message("admin_handlers: sync_tracker — prefix corregido de '{$storedPrefix}' a '{$resolvedPrefix}' para slug={$slug}");
                    $storedPrefix = $resolvedPrefix;
                    $configManager->updateConnectionFields($slug, [
                        'field_prefix' => $resolvedPrefix,
                        'field_prefix_checked' => true,
                    ]);
                    $connections = $configManager->listConnections(); // refrescar estado local
                } elseif ($storedPrefix === '') {
                    $storedPrefix = $resolvedPrefix;
                }
            }
            try {
                $result = $tikiClient->synchronizeTrackerFields($trackerId, $storedPrefix);
                $created = $result['created'];
                $prefixMsg = '';
                if (!empty($result['prefix_set'])) {
                    $prefixMsg = ' | ✅ fieldPrefix configurado en tracker';
                } else {
                    $prefixMsg = ' | ⚠️ fieldPrefix no se pudo configurar en tracker (ver logs)';
                }
                if (empty($created)) {
                    $successMessage = 'Tracker sincronizado: todos los campos ya existen (' . count($result['existing']) . ' campos, prefix: ' . $storedPrefix . ')' . $prefixMsg;
                } else {
                    $successMessage = 'Tracker sincronizado: creados ' . count($created) . ' campos faltantes (' . implode(', ', $created) . '). Prefix: ' . $storedPrefix . $prefixMsg;
                }
            } catch (Exception $e) {
                $errorMessage = 'Error al sincronizar tracker: ' . $e->getMessage();
            }
            break;
        
        // ── Configurar webhook en Telegram ──
        case 'configure_webhook':
            $slug = $_POST['slug'] ?? '';
            $conn = $configManager->getConnection($slug);
            if (!$conn) {
                $errorMessage = __('msg.err_not_found');
                break;
            }
            
            $botToken = $conn['bot_token'];
            $webhookSecret = $conn['webhook_secret'];
            $webhookUrl = generateWebhookUrl();
            
            if (empty($botToken)) {
                $errorMessage = __('msg.err_no_bot_token');
                break;
            }
            if (empty($webhookSecret)) {
                $errorMessage = __('msg.err_no_webhook_secret');
                break;
            }
            
            $tgClient = new TelegramClient($botToken);
            $result = $tgClient->setWebhook($webhookUrl, $webhookSecret);
            
            if ($result['ok']) {
                $successMessage = sprintf(__('msg.webhook_ok'), $conn['name']) . ': ' . $webhookUrl;
                // Refrescar estado del webhook en los cards
                try {
                    $wh = $tgClient->getWebhookInfo();
                    $status = ['ok' => !empty($wh['url']), 'label' => '✅', 'pending' => (int) ($wh['pending_update_count'] ?? 0)];
                    if (!empty($wh['last_error_message'])) {
                        $lastErrorDate = (int) ($wh['last_error_date'] ?? 0);
                        $lastSuccessDate = (int) ($wh['last_successful_synchronization'] ?? 0);
                        $noPending = ($wh['pending_update_count'] ?? 1) === 0;
                        if ($noPending || ($lastSuccessDate > 0 && $lastSuccessDate > $lastErrorDate)) {
                            $status['label'] = '✅ (error histórico)';
                        } else {
                            $status['label'] = '❌ ' . substr($wh['last_error_message'], 0, 40);
                            $status['ok'] = false;
                        }
                    } elseif ($wh['pending_update_count'] > 10) {
                        $status['label'] = '⚠️ ' . $wh['pending_update_count'] . ' pend.';
                        $status['ok'] = false;
                    }
                    $webhookStatuses[$slug] = $status;
                } catch (Exception $e) {
                    // No actualizar status si falla
                }
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
                
                // Si Telegram responde OK, guardar bot_name
                if ($tgResult['ok'] && !empty($tgResult['bot_name'])) {
                    $configManager->updateConnectionFields($slug, ['bot_name' => $tgResult['bot_name']]);
                    $results['bot_name'] = $tgResult['bot_name'];
                }
                
                // Webhook info: estado del webhook configurado
                $webhookInfo = $tgClient->getWebhookInfo();
                $results['webhook'] = $webhookInfo;
                
                // Si hay chat_id, obtener chat_title via getChat
                $chatId = (int) ($conn['chat_id'] ?? 0);
                $results['chat_id'] = $chatId;
                if ($chatId > 0) {
                    $chatInfo = $tgClient->getChat($chatId);
                    if ($chatInfo !== null && !empty($chatInfo['title'] ?? $chatInfo['username'] ?? '')) {
                        $chatTitle = $chatInfo['title'] ?? $chatInfo['username'] ?? '';
                        $configManager->updateConnectionFields($slug, ['chat_title' => $chatTitle]);
                        $results['chat_title'] = $chatTitle;
                    }
                }
            } else {
                $results['telegram'] = ['ok' => false, 'message' => 'Sin bot_token'];
                $results['webhook'] = ['ok' => false, 'message' => 'Sin bot_token'];
            }
            
            // Test TikiWiki
            if (!empty($conn['tiki_api_url']) && !empty($conn['tiki_api_token'])) {
                $tikiClient = new TikiWikiClient($conn['tiki_api_url'], $conn['tiki_api_token']);
                $trackerId = (int) ($conn['tracker_id'] ?? 0);
                $tikiResult = $tikiClient->checkPermissions($trackerId);
                $results['tikiwiki'] = $tikiResult;
                
                // Auto-detectar field prefix si tracker_id es válido
                if ($trackerId > 0) {
                    $storedPrefix = $conn['field_prefix'] ?? 'telegrammessage';
                    try {
                        $resolvedPrefix = $tikiClient->resolveFieldPrefix($trackerId);
                        if ($resolvedPrefix !== $storedPrefix) {
                            $configManager->updateConnectionFields($slug, ['field_prefix' => $resolvedPrefix]);
                            $results['prefix_detected'] = $resolvedPrefix;
                            log_message("admin_handlers/test: Field prefix corregido de '{$storedPrefix}' a '{$resolvedPrefix}' para conexión '{$slug}'");
                        }
                    } catch (Exception $e) {
                        log_message("admin_handlers/test: Error detectando field_prefix para {$slug}: " . $e->getMessage());
                    }
                }
            } else {
                $results['tikiwiki'] = ['ok' => false, 'api_access' => false, 'file_gallery' => false, 'upload_files' => false, 'message' => 'Sin API URL o token'];
            }
            
            header('Content-Type: application/json');
            echo json_encode($results);
            exit;
        
        // ── Verificar privacy mode (getUpdates) ──
        case 'check_privacy':
            $slug = $_POST['slug'] ?? '';
            $conn = $configManager->getConnection($slug);
            if (!$conn || empty($conn['bot_token'])) {
                echo json_encode(['ok' => false, 'error' => 'Conexión inválida o sin bot_token']);
                exit;
            }
            
            try {
                $tgClient = new TelegramClient($conn['bot_token']);
                $result = $tgClient->getUpdates(10);
                // HTTP 409 = webhook activo, no se puede llamar getUpdates
                // No es un error real, es esperado. Devolver info útil.
                if (!$result['ok'] && str_contains($result['error'] ?? '', 'webhook is active')) {
                    // Obtener info del webhook para mostrar en su lugar
                    $whInfo = $tgClient->getWebhookInfo();
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok' => false,
                        'webhook_active' => true,
                        'error' => 'El webhook está activo — getUpdates no está disponible. Usá "Configurar Webhook" o "Test" para verificar el estado.',
                        'webhook_url' => $whInfo['url'] ?? '',
                        'pending' => $whInfo['pending_update_count'] ?? 0,
                    ]);
                    exit;
                }
                header('Content-Type: application/json');
                echo json_encode($result);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;
        
        // ── Asignar chat detectado a conexión ──
        case 'assign_chat':
            $slug = $_POST['slug'] ?? '';
            $chatId = (int) ($_POST['chat_id'] ?? 0);
            if (empty($slug) || !$chatId) {
                $errorMessage = 'Datos inválidos para asignar chat';
            } else {
                $result = assignDetection($slug, $chatId);
                if ($result['success']) {
                    $successMessage = $result['message'];
                    $connections = $configManager->listConnections();
                } else {
                    $errorMessage = $result['message'];
                }
            }
            break;
        
        // ── Ignorar chat detectado ──
        case 'ignore_chat':
            $slug = $_POST['slug'] ?? '';
            $chatId = (int) ($_POST['chat_id'] ?? 0);
            if (empty($slug) || !$chatId) {
                $errorMessage = 'Datos inválidos para ignorar chat';
            } elseif (ignoreChat($slug, $chatId)) {
                $successMessage = 'Chat #' . $chatId . ' ignorado';
            } else {
                $errorMessage = 'Error al ignorar chat';
            }
            break;
        
        // ── Crear tracker en TikiWiki ──
        case 'create_tracker':
            $trackerName = trim($_POST['tracker_name'] ?? '');
            $trackerDesc = trim($_POST['tracker_description'] ?? '');
            $rawPrefix = trim($_POST['field_prefix'] ?? 'telegrammessage');
            $connectionSlug = trim($_POST['connection_slug'] ?? '');
            $tikiApiUrl = trim($_POST['tiki_api_url'] ?? '');
            $tikiApiToken = trim($_POST['tiki_api_token'] ?? '');
            $galleryId = !empty($_POST['gallery_id']) ? (int) $_POST['gallery_id'] : null;
            
            // Validar nombre
            if ($trackerName === '') {
                $errorMessage = 'El nombre del tracker es obligatorio';
                break;
            }
            
            // Validar y normalizar prefix
            $cleanPrefix = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawPrefix));
            if ($cleanPrefix === '' || !ctype_alpha($cleanPrefix[0])) {
                $errorMessage = 'El field prefix debe comenzar con una letra y contener solo caracteres alfanuméricos';
                break;
            }
            if (strlen($cleanPrefix) > 16) {
                $errorMessage = 'El field prefix no puede tener más de 16 caracteres';
                break;
            }
            
            // Obtener credenciales Tiki
            if ($connectionSlug !== '') {
                $conn = $configManager->getConnection($connectionSlug);
                if (!$conn) {
                    $errorMessage = __('msg.err_not_found');
                    break;
                }
                $tikiApiUrl = $conn['tiki_api_url'];
                $tikiApiToken = $conn['tiki_api_token'];
            }
            
            if ($tikiApiUrl === '' || $tikiApiToken === '') {
                $errorMessage = 'Se requiere Tiki API URL y Token (o seleccionar una conexión)';
                break;
            }
            
            try {
                $tikiClient = new TikiWikiClient(
                    apiUrl: $tikiApiUrl,
                    token: $tikiApiToken,
                    timeout: TIMEOUT_TIKIWIKI_API,
                    uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
                );
                $newTrackerId = $tikiClient->createTracker($trackerName, $trackerDesc, $cleanPrefix, $galleryId);
                
                if ($newTrackerId === null) {
                    $errorMessage = 'Error al crear el tracker en TikiWiki. Verificar credenciales y revisar debug.log.';
                    break;
                }
                
                // Auto-asignar a conexión si se seleccionó una
                if ($connectionSlug !== '') {
                    $connData = [
                        'slug' => $connectionSlug,
                        'name' => $conn['name'],
                        'bot_token' => $conn['bot_token'],
                        'webhook_secret' => $conn['webhook_secret'],
                        'chat_id' => $conn['chat_id'] ?? 0,
                        'tiki_api_url' => $tikiApiUrl,
                        'tiki_api_token' => $tikiApiToken,
                        'tracker_id' => $newTrackerId,
                        'field_prefix' => $cleanPrefix,
                        'enabled' => $conn['enabled'] ?? true,
                        'async_processing' => $conn['async_processing'] ?? false,
                    ];
                    $configManager->saveConnection($connData);
                    $connections = $configManager->listConnections();
                }
                
                $galleryMsg = ($galleryId !== null) ? ", galería: #{$galleryId}" : "";
                $msg = sprintf(__('msg.tracker_created'), $trackerName, $newTrackerId, $cleanPrefix . $galleryMsg);
                if ($connectionSlug !== '') {
                    $msg .= sprintf(__('msg.tracker_assigned'), $conn['name']);
                }
                $successMessage = $msg;
                
                // Limpiar POST para que el form no retenga valores tras éxito
                $_POST['tracker_name'] = '';
                $_POST['tracker_description'] = '';
                $_POST['tiki_api_url'] = '';
                $_POST['tiki_api_token'] = '';
                $_POST['field_prefix'] = 'telegrammessage';
                $_POST['gallery_id'] = '';
            } catch (Exception $e) {
                $errorMessage = 'Error al crear tracker: ' . $e->getMessage();
            }
            break;
    }
}
