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

// --- Wiring con Inyección de Dependencias ---
$tikiWikiClient = new TikiWikiClient(
    apiUrl: TIKIWIKI_API_URL,
    token: TIKIWIKI_TOKEN,
    timeout: TIMEOUT_TIKIWIKI_API,
    uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
);

$telegramClient = new TelegramClient(
    botToken: TELEGRAM_BOT_TOKEN
);

$messageMapper = new MessageMapper();

$webhookHandler = new WebhookHandler(
    tikiWikiClient: $tikiWikiClient,
    telegramClient: $telegramClient,
    messageMapper: $messageMapper,
    trackerId: (int) TIKIWIKI_TRACKER_ID
);
