<?php
/**
 * Carga de idioma para trackerGram
 * 
 * Usa sesión para persistir la preferencia, con override via GET (?lang=es|en).
 * Los archivos de idioma están en lang/{lang}.php y devuelven un array key => value.
 * La función __($key) busca la clave y devuelve la traducción o el key como fallback.
 */

// Detectar y cargar idioma
$availableLangs = ['es', 'en'];
$langCode = 'es'; // default

// Iniciar sesión si no está activa (safe: no restart si ya iniciada)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prioridad: 1) GET param, 2) Session, 3) Default
if (isset($_GET['lang']) && in_array($_GET['lang'], $availableLangs, true)) {
    $langCode = $_GET['lang'];
    $_SESSION['trackergram_lang'] = $langCode;
} elseif (isset($_SESSION['trackergram_lang']) && in_array($_SESSION['trackergram_lang'], $availableLangs, true)) {
    $langCode = $_SESSION['trackergram_lang'];
}

$translations = [];
$langFile = __DIR__ . '/' . $langCode . '.php';
if (file_exists($langFile)) {
    $loaded = require $langFile;
    if (is_array($loaded)) {
        $translations = $loaded;
    }
}

/**
 * Obtener traducción para una clave.
 * Si la clave no existe, devuelve la propia clave (visible para debug).
 * Soporta sprintf-style placeholders: __('msg.saved', $name, $slug)
 */
function __(string $key, ...$args): string {
    global $translations;
    $text = $translations[$key] ?? $key;
    if ($args !== []) {
        $text = sprintf($text, ...$args);
    }
    return $text;
}

/**
 * Plural-aware translation.
 * Si $count === 1 se usa $singular, de lo contrario $plural.
 */
function _n(string $singular, string $plural, int $count, ...$args): string {
    global $translations;
    $key = $count === 1 ? $singular : $plural;
    $text = $translations[$key] ?? ($count === 1 ? $singular : $plural);
    if ($args !== []) {
        $text = sprintf($text, ...$args);
    }
    return $text;
}
