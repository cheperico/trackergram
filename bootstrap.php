<?php
/**
 * Bootstrap - Carga centralizada de dependencias
 * Todos los entry points deben requerir este archivo.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/TikiWikiClient.php';
require_once __DIR__ . '/TelegramClient.php';
require_once __DIR__ . '/MessageMapper.php';
require_once __DIR__ . '/WebhookHandler.php';
