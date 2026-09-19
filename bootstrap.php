<?php
/**
 * Bootstrap - Carga centralizada de dependencias + wiring con DI
 * Todos los entry points deben requerir este archivo.
 */
require_once __DIR__ . '/config.php';

// ── Carga de idioma ──
require_once __DIR__ . '/lang/load.php';

require_once __DIR__ . '/lib/Infra/exceptions.php';
require_once __DIR__ . '/lib/Core/NormalizedMessage.php';
require_once __DIR__ . '/lib/Client/TikiWikiClient.php';
require_once __DIR__ . '/lib/Client/TelegramClient.php';
require_once __DIR__ . '/lib/Core/MessageMapper.php';
require_once __DIR__ . '/lib/Infra/CollectSessionManager.php';
require_once __DIR__ . '/lib/Handler/WebhookHandler.php';

// NOTA: No hay DI wiring central. Cada entry point
// (api.php, import.php, admin.php, worker.php) crea
// sus propios clientes por conexión desde ConfigManager.
