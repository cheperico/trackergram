<?php
/**
 * trackerGram - Webhook endpoint para Telegram → TikiWiki
 * 
 * Punto de entrada HTTP para webhooks de Telegram.
 * La lógica de negocio está en WebhookHandler.
 */

require_once 'bootstrap.php';

// Manejar webhook de Telegram
// solo se ejecuta si api.php es el entry point directo
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty(TELEGRAM_WEBHOOK_SECRET)) {
        log_message("trackerGram: TELEGRAM_WEBHOOK_SECRET no configurado — rechazando webhook");
        http_response_code(500);
        die(json_encode(['error' => 'Webhook secret no configurado']));
    }

    $input = file_get_contents('php://input');
    $update = json_decode($input, true);

    if (!$update) {
        log_message("trackerGram: JSON inválido en webhook");
        http_response_code(400);
        die(json_encode(['error' => 'Invalid JSON']));
    }

    $secretToken = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals(TELEGRAM_WEBHOOK_SECRET, $secretToken)) {
        http_response_code(403);
        die(json_encode(['error' => 'Acceso denegado']));
    }

    // Rate limiting simple por IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateFile = sys_get_temp_dir() . '/tg_rate_' . md5($ip);
    $window = 60;
    $maxRequests = 30;
    $now = time();
    $requests = [];
    if (file_exists($rateFile)) {
        $content = @file_get_contents($rateFile);
        if ($content) {
            $requests = json_decode($content, true) ?? [];
            $requests = array_values(array_filter($requests, fn($t) => $t > $now - $window));
        }
    }
    $requests[] = $now;
    @file_put_contents($rateFile, json_encode($requests));

    if (count($requests) > $maxRequests) {
        http_response_code(429);
        die(json_encode(['error' => 'Too Many Requests']));
    }

    WebhookHandler::processUpdate($update);
    echo json_encode(['status' => 'ok']);
}
