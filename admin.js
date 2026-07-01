/**
 * Obtener CSRF token desde meta tag en el head
 * (reemplaza a los <?php echo generateCSRFToken(); ?> inline)
 */
function csrftoken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * Abre un modal y maneja foco + aria-hidden
 */
function openModal(id) {
    var overlay = document.getElementById(id);
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    
    // Mover foco al primer input o botón dentro del modal
    var firstFocusable = overlay.querySelector('input, button, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (firstFocusable) {
        setTimeout(function() { firstFocusable.focus(); }, 50);
    }
}

/**
 * Cierra un modal y restaura aria-hidden
 */
function closeModal(id) {
    var overlay = document.getElementById(id);
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
}

/**
 * Cierra modal con tecla Escape y mantiene foco dentro (focus trap básico)
 */
document.addEventListener('keydown', function(e) {
    // Escape: cerrar modal abierto
    if (e.key === 'Escape') {
        var openModal = document.querySelector('.modal-overlay.show');
        if (openModal) {
            closeModal(openModal.id);
        }
    }
    
    // Tab: mantener foco dentro del modal (focus trap)
    if (e.key === 'Tab') {
        var openModal = document.querySelector('.modal-overlay.show');
        if (openModal) {
            var focusable = openModal.querySelectorAll('input, button, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (focusable.length === 0) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            
            if (e.shiftKey) {
                if (document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }
    }
});

function openEditModal(slug) {
    // Cargar datos via AJAX (no exponer tokens en HTML/JS)
    var data = new URLSearchParams();
    data.append('action', 'get_connection');
    data.append('slug', slug);
    data.append('csrf_token', csrftoken());
    
    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(conn) {
        if (conn.error) { alert(conn.error); return; }
        
        document.getElementById('modal-title').textContent = 'Editar: ' + conn.name;
        document.getElementById('form-slug').value = slug;
        document.getElementById('form-name').value = conn.name || '';
        document.getElementById('form-bot_token').value = conn.bot_token || '';
        document.getElementById('form-webhook_secret').value = conn.webhook_secret || '';
        document.getElementById('form-chat_id').value = conn.chat_id || 0;
        document.getElementById('form-tracker_id').value = conn.tracker_id || '';
        document.getElementById('form-tiki_api_url').value = conn.tiki_api_url || '';
        document.getElementById('form-tiki_api_token').value = conn.tiki_api_token || '';
        document.getElementById('form-enabled').checked = conn.enabled !== false;
        document.getElementById('form-async_processing').checked = conn.async_processing === true;
        
        openModal('connection-modal');
    })
    .catch(function() {
        alert('Error al cargar datos de la conexion');
    });
}

function safeRender(el, lines) {
    // Renderiza un array de strings como texto seguro (textContent) separado por <br>
    el.innerHTML = '';
    for (var i = 0; i < lines.length; i++) {
        if (i > 0) el.appendChild(document.createElement('br'));
        el.appendChild(document.createTextNode(lines[i]));
    }
}

function testConnection(slug, btn) {
    var resultDiv = btn.closest('.conn-actions').querySelector('.test-result');
    resultDiv.style.display = 'block';
    resultDiv.className = 'test-result';
    resultDiv.textContent = 'Probando...';
    btn.disabled = true;
    
    var data = new URLSearchParams();
    data.append('action', 'test_connection');
    data.append('slug', slug);
    data.append('csrf_token', csrftoken());
    
    fetch('admin.php?tab=webhook', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        var lines = [];
        var allOk = true;
        
        // Telegram test
        if (result.telegram) {
            var tgOk = result.telegram.ok;
            lines.push((tgOk ? '✅' : '❌') + ' Telegram: ' + result.telegram.message);
            if (!tgOk) allOk = false;
        }
        
        // Webhook status
        if (result.webhook) {
            var wh = result.webhook;
            var whLines = [];
            if (wh.url) {
                whLines.push('URL: ' + wh.url);
                whLines.push('Updates pendientes: ' + (wh.pending_update_count || 0));
                if (wh.last_error_message) {
                    var errorStale = (wh.pending_update_count === 0) || (wh.last_successful_synchronization && wh.last_error_date && wh.last_successful_synchronization > wh.last_error_date);
                    if (!errorStale) {
                        whLines.push('⚠️ Último error: ' + wh.last_error_message);
                    }
                }
                if (wh.pending_update_count > 10) {
                    whLines.push('⚠️ ' + wh.pending_update_count + ' updates encolados — el webhook puede estar caído');
                }
                lines.push('🌐 Webhook: ' + whLines.join(' | '));
            } else {
                lines.push('🌐 Webhook: ⚠️ No configurado');
            }
        }
        
        // TikiWiki test
        if (result.tikiwiki) {
            var twOk = result.tikiwiki.ok;
            lines.push((twOk ? '✅' : '❌') + ' TikiWiki: ' + result.tikiwiki.message);
            
            if (result.tikiwiki.api_access) {
                var p = result.tikiwiki;
                var permLines = [];
                
                if (p.admin_trackers === null) {
                    permLines.push('⏭️ admin_trackers (global): no testeado (sin tracker ID)');
                } else {
                    permLines.push((p.admin_trackers ? '✅' : '❌') + ' admin_trackers (global): ' + (p.admin_trackers ? 'OK' : 'FALTA — crítico'));
                    if (!p.admin_trackers) allOk = false;
                }
                
                if (p.create_tracker_items === null) {
                    permLines.push('⏭️ create_tracker_items: no testeado (sin tracker ID)');
                } else {
                    permLines.push((p.create_tracker_items ? '✅' : '❌') + ' create_tracker_items: ' + (p.create_tracker_items ? 'OK' : 'FALTA — crítico'));
                    if (!p.create_tracker_items) allOk = false;
                }
                
                if (p.modify_tracker_items === null) {
                    permLines.push('⏭️ modify_tracker_items: no testeado (sin tracker ID)');
                } else {
                    permLines.push((p.modify_tracker_items ? '✅' : '❌') + ' modify_tracker_items: ' + (p.modify_tracker_items ? 'OK' : 'FALTA — no se pueden editar mensajes'));
                    if (!p.modify_tracker_items) allOk = false;
                }
                
                permLines.push((p.view_file_gallery ? '✅' : '❌') + ' view_file_gallery: ' + (p.view_file_gallery ? 'OK' : 'FALTA'));
                permLines.push((p.upload_files ? '✅' : '❌') + ' upload_files: ' + (p.upload_files ? 'OK' : 'FALTA'));
                permLines.push((p.admin_file_galleries ? '✅' : '⚠️') + ' admin_file_galleries: ' + (p.admin_file_galleries ? 'OK' : 'FALTA — auto-repair no disponible'));
                
                if (!p.view_file_gallery) allOk = false;
                if (!p.upload_files) allOk = false;
                
                for (var pi = 0; pi < permLines.length; pi++) {
                    lines.push('    ' + permLines[pi]);
                }
            }
            
            if (!twOk) allOk = false;
        }
        
        resultDiv.className = 'test-result ' + (allOk ? 'ok' : 'fail');
        safeRender(resultDiv, lines);
        
        if (result.bot_name) {
            var card = btn.closest('.conn-card');
            if (card) {
                var details = card.querySelector('.conn-details');
                if (details) {
                    var spans = details.querySelectorAll('span');
                    if (spans.length > 0) {
                        spans[0].textContent = 'Bot: @' + result.bot_name;
                    }
                }
            }
        }
        if (result.chat_id || result.chat_title) {
            var card = btn.closest('.conn-card');
            if (card) {
                var details = card.querySelector('.conn-details');
                if (details) {
                    var spans = details.querySelectorAll('span');
                    if (spans.length > 1) {
                        var chatLabel = '';
                        if (result.chat_title) {
                            chatLabel = result.chat_title;
                            if (result.chat_id) {
                                chatLabel += ' (ID: ' + result.chat_id + ')';
                            }
                        } else if (result.chat_id) {
                            chatLabel = 'ID: ' + result.chat_id;
                        } else {
                            chatLabel = 'Pendiente';
                        }
                        spans[1].textContent = 'Chat: ' + chatLabel;
                    }
                }
            }
        }
    })
    .catch(function(err) {
        resultDiv.className = 'test-result fail';
        resultDiv.textContent = 'Error de red: ' + err.message;
    })
    .finally(function() {
        btn.disabled = false;
    });
}

/**
 * Testear solo la parte Telegram + Webhook (vista agrupada, nivel bot)
 */
function testBotConnection(slug, btn) {
    var resultDiv = btn.closest('.bot-actions').querySelector('.test-result');
    resultDiv.style.display = 'block';
    resultDiv.className = 'test-result';
    resultDiv.textContent = 'Probando...';
    btn.disabled = true;
    
    var data = new URLSearchParams();
    data.append('action', 'test_connection');
    data.append('slug', slug);
    data.append('csrf_token', csrftoken());
    
    fetch('admin.php?tab=webhook', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        var lines = [];
        var allOk = true;
        
        if (result.telegram) {
            var tgOk = result.telegram.ok;
            lines.push((tgOk ? '✅' : '❌') + ' Telegram: ' + result.telegram.message);
            if (!tgOk) allOk = false;
        }
        
        if (result.webhook) {
            var wh = result.webhook;
            if (wh.url) {
                var whMsg = 'URL: ' + wh.url;
                whMsg += ' | Pendientes: ' + (wh.pending_update_count || 0);
                if (wh.last_error_message) {
                    var errorStale = (wh.pending_update_count === 0) || (wh.last_successful_synchronization && wh.last_error_date && wh.last_successful_synchronization > wh.last_error_date);
                    if (errorStale) {
                        whMsg += ' | ⚠️ Error histórico (recuperado)';
                    } else {
                        whMsg += ' | ⚠️ ' + wh.last_error_message;
                        allOk = false;
                    }
                }
                if (wh.pending_update_count > 10) {
                    whMsg += ' | ⚠️ Updates encolados';
                    allOk = false;
                }
                lines.push('🌐 Webhook: ' + whMsg);
            } else {
                lines.push('🌐 Webhook: ⚠️ No configurado');
            }
        }
        
        resultDiv.className = 'test-result ' + (allOk ? 'ok' : 'fail');
        safeRender(resultDiv, lines);
    })
    .catch(function(err) {
        resultDiv.className = 'test-result fail';
        resultDiv.textContent = 'Error de red: ' + err.message;
    })
    .finally(function() {
        btn.disabled = false;
    });
}

/**
 * Testear solo la parte TikiWiki (vista agrupada, nivel conexion)
 */
function testTikiConnection(slug, btn) {
    var resultDiv = btn.closest('.sub-conn-actions').querySelector('.test-result');
    resultDiv.style.display = 'block';
    resultDiv.className = 'test-result';
    resultDiv.textContent = 'Probando TikiWiki...';
    btn.disabled = true;
    
    var data = new URLSearchParams();
    data.append('action', 'test_connection');
    data.append('slug', slug);
    data.append('csrf_token', csrftoken());
    
    fetch('admin.php?tab=webhook', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        var lines = [];
        var allOk = true;
        
        if (result.tikiwiki) {
            var twOk = result.tikiwiki.ok;
            lines.push((twOk ? '✅' : '❌') + ' TikiWiki: ' + result.tikiwiki.message);
            
            if (result.tikiwiki.api_access) {
                var p = result.tikiwiki;
                var permLines = [];
                
                if (p.admin_trackers === null) {
                    permLines.push('⏭️ admin_trackers: no testeado');
                } else {
                    permLines.push((p.admin_trackers ? '✅' : '❌') + ' admin_trackers: ' + (p.admin_trackers ? 'OK' : 'FALTA'));
                    if (!p.admin_trackers) allOk = false;
                }
                
                if (p.create_tracker_items === null) {
                    permLines.push('⏭️ create_tracker_items: no testeado');
                } else {
                    permLines.push((p.create_tracker_items ? '✅' : '❌') + ' create_tracker_items: ' + (p.create_tracker_items ? 'OK' : 'FALTA'));
                    if (!p.create_tracker_items) allOk = false;
                }
                
                if (p.modify_tracker_items === null) {
                    permLines.push('⏭️ modify_tracker_items: no testeado');
                } else {
                    permLines.push((p.modify_tracker_items ? '✅' : '❌') + ' modify_tracker_items: ' + (p.modify_tracker_items ? 'OK' : 'FALTA'));
                    if (!p.modify_tracker_items) allOk = false;
                }
                
                permLines.push((p.view_file_gallery ? '✅' : '❌') + ' view_file_gallery: ' + (p.view_file_gallery ? 'OK' : 'FALTA'));
                permLines.push((p.upload_files ? '✅' : '❌') + ' upload_files: ' + (p.upload_files ? 'OK' : 'FALTA'));
                permLines.push((p.admin_file_galleries ? '✅' : '⚠️') + ' admin_file_galleries: ' + (p.admin_file_galleries ? 'OK' : 'FALTA'));
                
                if (!p.view_file_gallery) allOk = false;
                if (!p.upload_files) allOk = false;
                
                for (var pi = 0; pi < permLines.length; pi++) {
                    lines.push('    ' + permLines[pi]);
                }
            }
            
            if (!twOk) allOk = false;
        } else {
            lines.push('❌ TikiWiki: Sin datos');
            allOk = false;
        }
        
        resultDiv.className = 'test-result ' + (allOk ? 'ok' : 'fail');
        safeRender(resultDiv, lines);
    })
    .catch(function(err) {
        resultDiv.className = 'test-result fail';
        resultDiv.textContent = 'Error de red: ' + err.message;
    })
    .finally(function() {
        btn.disabled = false;
    });
}

/**
 * Verificar privacy mode: muestra los últimos mensajes recibidos por el bot
 */
function checkPrivacy(slug, btn) {
    var actionsContainer = btn.closest('.conn-actions') || btn.closest('.bot-actions');
    var resultDiv = actionsContainer.querySelector('.test-result');
    resultDiv.style.display = 'block';
    resultDiv.className = 'test-result';
    resultDiv.textContent = 'Consultando updates recientes...';
    btn.disabled = true;
    
    var data = new URLSearchParams();
    data.append('action', 'check_privacy');
    data.append('slug', slug);
    data.append('csrf_token', csrftoken());
    
    fetch('admin.php?tab=webhook', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        var lines = [];
        
        if (result.webhook_active) {
            lines.push('🔌 Webhook activo — getUpdates no disponible');
            lines.push('   ' + result.error);
            if (result.pending > 0) {
                lines.push('   Updates pendientes: ' + result.pending);
            }
            resultDiv.className = 'test-result ok';
            safeRender(resultDiv, lines);
            btn.disabled = false;
            return;
        }
        
        if (!result.ok) {
            lines.push('❌ Error: ' + (result.error || 'sin respuesta'));
            resultDiv.className = 'test-result fail';
            safeRender(resultDiv, lines);
            return;
        }
        
        var updates = result.updates || [];
        lines.push('📡 Updates recientes: ' + result.count);
        
        if (result.privacy_mode_on === false) {
            lines.push('✅ Privacy mode: DESACTIVADO — el bot ve mensajes normales');
        } else if (result.privacy_mode_on === true) {
            lines.push('⚠️ Sin mensajes no-comando — posible privacy mode ACTIVADO');
            lines.push('   Probá enviar un mensaje normal al grupo y volvé a consultar');
        } else {
            lines.push('⏳ Sin updates aún — no se puede determinar');
            lines.push('   Agregá el bot a un grupo y enviale un mensaje');
        }
        
        if (updates.length > 0) {
            lines.push('');
            lines.push('┌─ Últimos mensajes recibidos ──────────────');
            updates.forEach(function(u) {
                var icon = '💬';
                var label = u.chat_title || '(sin nombre)';
                
                if (u.type === 'my_chat_member') {
                    icon = '🔌';
                    label = 'Evento de grupo';
                } else if (u.is_private) {
                    icon = '👤';
                } else if (u.from_command) {
                    icon = '/' ;
                }
                
                var timeStr = '';
                if (u.timestamp) {
                    var d = new Date(u.timestamp * 1000);
                    timeStr = d.toLocaleTimeString('es-AR', {hour:'2-digit', minute:'2-digit'});
                }
                
                var preview = u.text ? u.text.substring(0, 80) : '(sin texto)';
                if (u.text && u.text.length > 80) preview += '…';
                if (u.type === 'my_chat_member') preview = u.text;
                
                lines.push('  ' + icon + ' [' + timeStr + '] ' + label + ': ' + preview);
            });
            lines.push('└───────────────────────────────────────────');
        }
        
        resultDiv.className = 'test-result ' + (result.privacy_mode_on === false ? 'ok' : 'fail');
        safeRender(resultDiv, lines);
    })
    .catch(function(err) {
        resultDiv.className = 'test-result fail';
        resultDiv.textContent = 'Error de red: ' + err.message;
    })
    .finally(function() {
        btn.disabled = false;
    });
}

/**
 * Limpiar formulario del modal de conexión (para crear nueva, no editar)
 */
function resetConnectionForm() {
    document.getElementById('modal-title').textContent = 'Nueva conexion';
    document.getElementById('form-slug').value = '';
    document.getElementById('form-name').value = '';
    document.getElementById('form-bot_token').value = '';
    document.getElementById('form-webhook_secret').value = '';
    document.getElementById('form-chat_id').value = '0';
    document.getElementById('form-tracker_id').value = '';
    document.getElementById('form-tiki_api_url').value = '';
    document.getElementById('form-tiki_api_token').value = '';
    document.getElementById('form-enabled').checked = true;
    document.getElementById('form-async_processing').checked = false;
}

/**
 * Auto-llenar campos Tiki al seleccionar una conexión existente
 * @param {HTMLSelectElement} selectEl - El elemento <select> del connection_slug
 * @param {string} prefix - Prefijo de IDs: 'import-' o 'create-'
 */
function fillConnectionSlug(selectEl, prefix) {
    var slug = selectEl.value;
    if (!slug) {
        // Limpiar campos si se volvió a "Ingresar manual"
        var urlInput = document.getElementById(prefix + 'tiki_url');
        var tokenInput = document.getElementById(prefix + 'tiki_token');
        var trackerInput = document.getElementById(prefix + 'tracker_id');
        if (urlInput) urlInput.value = '';
        if (tokenInput) tokenInput.value = '';
        if (trackerInput) trackerInput.value = '';
        return;
    }
    
    var data = new URLSearchParams();
    data.append('action', 'get_connection');
    data.append('slug', slug);
    data.append('csrf_token', csrftoken());
    
    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(conn) {
        if (conn.error) { alert(conn.error); return; }
        
        var urlInput = document.getElementById(prefix + 'tiki_url');
        var tokenInput = document.getElementById(prefix + 'tiki_token');
        var trackerInput = document.getElementById(prefix + 'tracker_id');
        
        if (urlInput) urlInput.value = conn.tiki_api_url || '';
        if (tokenInput) tokenInput.value = conn.tiki_api_token || '';
        if (trackerInput) trackerInput.value = conn.tracker_id || '';
        
        // También el field_prefix si existe en el formulario
        var prefixInput = document.getElementById(prefix + 'field_prefix') || document.getElementById(prefix + 'field-prefix');
        if (prefixInput && conn.field_prefix) {
            prefixInput.value = conn.field_prefix;
        }
    })
    .catch(function() {
        alert('Error al cargar datos de la conexion');
    });
}

// Cerrar modal si se hace click fuera
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('show');
    }
});

/**
 * Actualizar previsualización de field prefix al escribir
 */
function updateFieldPreview(prefix) {
    var els = document.querySelectorAll('[id^="preview-prefix"]');
    els.forEach(function(el) { el.textContent = prefix; });
}

document.addEventListener('DOMContentLoaded', function() {
    var prefixInput = document.getElementById('create-field-prefix');
    if (prefixInput) {
        prefixInput.addEventListener('input', function() {
            var val = this.value || 'telegrammessage';
            updateFieldPreview(val);
        });
    }
});

/**
 * Mostrar/ocultar contraseña en campos password (usado en todos los tabs)
 */
function togglePassword(btn) {
    var input = btn.parentElement.querySelector('input');
    if (input && input.type === 'password') {
        input.type = 'text';
        btn.textContent = 'Ocultar';
        btn.setAttribute('aria-label', 'Ocultar contraseña');
    } else if (input) {
        input.type = 'password';
        btn.textContent = 'Mostrar';
        btn.setAttribute('aria-label', 'Mostrar contraseña');
    }
}

/**
 * Toggle panel de configuración global con soporte ARIA
 */
function toggleGlobalConfig(header) {
    var content = document.getElementById('config-content');
    var isHidden = content.style.display === 'none';
    content.style.display = isHidden ? 'block' : 'none';
    header.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
}
