<?php
/**
 * Bootstrap - Carga centralizada de dependencias + wiring con DI
 * Todos los entry points deben requerir este archivo.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/NormalizedMessage.php';
require_once __DIR__ . '/TikiWikiClient.php';
require_once __DIR__ . '/TelegramClient.php';
require_once __DIR__ . '/MessageMapper.php';
require_once __DIR__ . '/WebhookHandler.php';

// NOTA: No hay DI wiring central. Cada entry point
// (api.php, import.php, admin.php, worker.php) crea
// sus propios clientes por conexión desde ConfigManager.
