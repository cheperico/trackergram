# Mejoras Técnicas Recomendadas

> **Estado**: Las siguientes mejoras ya fueron implementadas en el proyecto.

## Lo Que Ya Está Bien

- Separación básica de archivos (api.php, config.php, admin.php)
- Uso de constantes para configuración
- Carga de variables de entorno desde .env
- Seguridad básica implementada: CSRF, rate limiting, sanitización XSS
- Integración con API de Telegram y TikiWiki funcionando

## Mejoras Implementadas en v0.1.1

### 1. Type Hints Agregados

Todas las funciones principales ahora tienen type hints:

```php
function getFileUrl(string $fileId): ?string
function uploadToTikiWiki(string $filePath, string $fileName, ?string $mimeType = null): ?string
function downloadAndUploadMedia(string $fileId, ?string $fileName = null, ?string $mimeType = null): ?string
function extractMessageData(array $message): array
function sendToTikiWiki(array $data): bool
function processUpdate(array $update): void
function messageExistsInTracker(int $messageId): bool
function getMediaGalleryId(): ?int
```

### 2. Constantes de Configuración

Las configuraciones ahora están centralizadas en `config.php`:

```php
// Timeouts (en segundos)
define('TIMEOUT_TELEGRAM_API', 5);
define('TIMEOUT_TELEGRAM_DOWNLOAD', 10);
define('TIMEOUT_TIKIWIKI_API', 30);
define('TIMEOUT_TIKIWIKI_UPLOAD', 30);

// Reintentos
define('RETRY_MAX_ATTEMPTS', 2);
define('RETRY_DELAY_MICROSECONDS', 100000);
```

### 3. Función de Logging Unificada

Ahora se usa solo `log_message()` en todo el código:

```php
function log_message(string $message): void
```

### 4. Constantes Usadas en Todo el Código

Los valores hardcodeados fueron reemplazados por constantes:

- Timeouts de cURL → `TIMEOUT_TELEGRAM_API`, `TIMEOUT_TIKIWIKI_API`, etc.
- Reintentos → `RETRY_MAX_ATTEMPTS`
- Delay → `RETRY_DELAY_MICROSECONDS`

---

## Mejoras Futuras (Pendientes)

### Prioridad Alta
- Extraer clases para APIs externas (TelegramClient, TikiWikiClient)
- Implementar patrón de inyección de dependencias

### Prioridad Media
- Agregar tests unitarios
- Implementar PSR-4 autoloading
- Crear documentación de API interna

### Prioridad Baja
- Agregar tipos estrictos (strict_types)
- Agregar anotaciones de tipo para arrays (phpdoc)

### ✅ Completado
- Eliminar variables globales ($mediaGalleryIdCache) - Implementado con static dentro de función
- Type hints en funciones
- Constantes de configuración
- Logging unificado

---

## Cómo Contribuir

Si querés ayudar a mejorar el código, podés:

1. Revisar las funciones que aún usan arrays genéricos y agregar phpdoc
2. Proponer refactorización de funciones grandes en funciones más pequeñas
3. Agregar tests para funciones críticas
4. Revisar y mejorar la documentación técnica

### 1. Archivo Monolítico (api.php tiene ~560 líneas)

**Problema**: Todas las funcionalidades están en un solo archivo, lo que dificulta el mantenimiento y las pruebas.

**Solución**: Separar en módulos:
```
trackergram/
├── src/
│   ├── Telegram/
│   │   ├── Client.php        # Manejo de API de Telegram
│   │   ├── WebhookHandler.php
│   │   └── MessageParser.php
│   ├── TikiWiki/
│   │   ├── Client.php        # Manejo de API de TikiWiki
│   │   ├── TrackerItem.php
│   │   └── FileUploader.php
│   ├── Core/
│   │   ├── Config.php
│   │   ├── Logger.php
│   │   └── Router.php
│   └── Models/
│       └── Message.php
├── api.php                   # Entry point, solo routing
└── config.php
```

### 2. Sin Arquitectura Orientada a Objetos

**Problema**: Todo son funciones globales, sin encapsulamiento ni separación de responsabilidades.

**Solución**: Crear clases con responsabilidades claras:
- `TelegramClient`: Comunicación con API de Telegram
- `TikiWikiClient`: Comunicación con API de TikiWiki  
- `MessageProcessor`: Procesamiento de mensajes
- `FileUploader`: Subida de archivos
- `Config`: Manejo de configuración

### 3. Inconsistencias en el Código

**Problema**: Mezcla de estilos de logging y falta de tipado.

**Ejemplos**:
```php
// Mal: mezcle de error_log y log_message
error_log("trackerGram: ...");  // en algunos lugares
log_message("...");              // en otros

// Mal: sin type hints
function getFileUrl($fileId) { ... }

// Mal: sin return type
function downloadAndUploadMedia($fileId, $fileName = null, $mimeType = null) { ... }
```

**Solución**:
```php
// Bien: type hints y return type
function getFileUrl(string $fileId): ?string { ... }

function downloadAndUploadMedia(string $fileId, ?string $fileName = null, ?string $mimeType = null): ?string { ... }
```

### 4. Variables Globales

**Problema**: Estado compartido sin control.
```php
$mediaGalleryIdCache = null;  // Variable global - mala práctica
```

**Solución**: Usar inyección de dependencias o patrón Singleton:
```php
class TikiWikiClient {
    private static ?int $galleryIdCache = null;
    
    public static function getGalleryId(): ?int {
        if (self::$galleryIdCache === null) {
            self::$galleryIdCache = self::fetchGalleryIdFromTracker();
        }
        return self::$galleryIdCache;
    }
}
```

### 5. Números Mágicos

**Problema**: Valores hardcodeados sin explicación.
```php
curl_setopt($ch, CURLOPT_TIMEOUT, 30);    // ¿Por qué 30?
curl_setopt($ch, CURLOPT_TIMEOUT, 5);      // ¿Por qué 5?
```

**Solución**: Usar constantes con nombres descriptivos:
```php
class Config {
    public const CURL_TIMEOUT_LONG = 30;
    public const CURL_TIMEOUT_SHORT = 5;
    public const CURL_TIMEOUT_MEDIUM = 10;
    
    public const RETRY_MAX_ATTEMPTS = 2;
    public const RETRY_DELAY_MS = 100000;
}
```

### 6. Funciones con Responsabilidades Múltiples

**Problema**: `extractMessageData()` hace de todo: parsea mensajes, detecta tipos, sube archivos.

**Solución**: Separar en funciones más pequeñas:
```php
function detectMessageType(array $message): string
function extractText(array $message): ?string
function extractMedia(array $message): ?MediaData
function processPhoto(array $photo): MediaData
function processVideo(array $video): MediaData
// etc.
```

---

## Recomendaciones de Prioridad

### Prioridad Alta (Afecta funcionalidad)
1. Estandarizar logging (usar solo una función)
2. Agregar type hints a todas las funciones
3. Eliminar variables globales

### Prioridad Media (Mejora mantenimiento)
4. Extraer clases para APIs externas
5. Crear constantes para configuración
6. Separar funciones grandes en funciones más pequeñas

### Prioridad Baja (Mejora arquitectura)
7. Implementar PSR-4 autoloading
8. Agregar tests unitarios
9. Crear documentación de API interna

---

## Ejemplo de Refactorización (para TelegramClient)

```php
<?php
namespace trackergram\Telegram;

class Client
{
    private const BASE_URL = 'https://api.telegram.org/bot';
    private const TIMEOUT = 5;
    
    private string $botToken;
    
    public function __construct(string $botToken)
    {
        $this->botToken = $botToken;
    }
    
    public function getFileUrl(string $fileId): ?string
    {
        $url = self::BASE_URL . $this->botToken . '/getFile?file_id=' . $fileId;
        
        $context = stream_context_create([
            'http' => ['timeout' => self::TIMEOUT]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !($data['ok'] ?? false)) {
            return null;
        }
        
        $filePath = $data['result']['file_path'] ?? null;
        
        if (!$filePath) {
            return null;
        }
        
        return 'https://api.telegram.org/file/bot' . $this->botToken . '/' . $filePath;
    }
}
```

---

## Conclusión

El proyecto funciona y cumple su propósito, pero aplicar estas mejoras lo haría:
- Más fácil de mantener
- Más fácil de extender
- Más fácil de debuguear
- Más profesional

La refactorización puede hacerse de forma incremental, archivo por archivo o clase por clase.