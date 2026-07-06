<?php
/**
 * exceptions.php - Excepciones de dominio para trackerGram
 *
 * Jerarquía:
 *   TrackerGramException (extends \RuntimeException)
 *     ├── TelegramApiException    — errores de la API de Telegram
 *     ├── TikiWikiApiException    — errores de la API de TikiWiki
 *     ├── ImportException         — errores en importación de exports
 *     ├── ConfigException         — errores de configuración (setup.json, .env)
 *     └── SecurityException       — violaciones de seguridad (SSRF, CSRF, rate limit)
 *
 * No se requiere autoloading: cada archivo hace require_once.
 */

class TrackerGramException extends \RuntimeException {}

class TelegramApiException extends TrackerGramException {}

class TikiWikiApiException extends TrackerGramException {}

class ImportException extends TrackerGramException {}

class ConfigException extends TrackerGramException {}

class SecurityException extends TrackerGramException {}
