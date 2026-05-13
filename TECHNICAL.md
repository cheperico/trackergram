# trackerGram - Documentación Técnica

## Arquitectura General

### Flujo de Datos

```
Telegram Bot → Webhook (api.php) → Procesamiento → TikiWiki API → Tracker Item
```

### Componentes Principales

1. **api.php**: Endpoint principal del webhook
2. **config.php**: Configuración y carga de variables de entorno
3. **admin.php**: Interfaz de administración web
4. **import.php**: Script de importación de exports de Telegram
5. **setup_webhook.php**: Script para configuración automática

## Especificaciones Técnicas

### Requisitos del Sistema

- **PHP**: 7.4+ (recomendado 8.0+)
- **Web Server**: Apache con mod_rewrite (recomendado) o Nginx
- **Extensiones PHP**: 
  - `curl` (para peticiones HTTP)
  - `json` (para procesar webhooks de Telegram)
  - `mbstring` (para manejo de strings)
  - `session` (para administración)
  - `zip` (para importar exports de Telegram)

### Estructura de Archivos

```
trackergram/
├── api.php              # Webhook endpoint (punto de entrada principal)
├── admin.php            # Interfaz de administración
├── import.php           # Importación de exports de Telegram
├── config.php           # Configuración y variables de entorno
├── setup_webhook.php    # Script de configuración inicial
├── TikiWikiClient.php   # Cliente para API de TikiWiki
├── TelegramClient.php   # Cliente para API de Telegram
├── MessageMapper.php    # Transformación de mensajes
├── .env.example         # Plantilla de variables de entorno
├── .env                 # Variables de entorno (creado por usuario)
├── .htaccess            # Configuración Apache y seguridad
├── .gitignore           # Archivos ignorados por Git
├── README.md            # Documentación para usuarios
├── TECHNICAL.md         # Documentación técnica
├── INSTALL.md           # Guía de instalación
├── CAMBIOS.md           # Changelog
├── CONTEXTO.md          # Guía para nuevos integrantes
└── roadmap.md           # Roadmap del proyecto
```

### Arquitectura de Clientes

El proyecto usa una arquitectura basada en clientes para separar responsabilidades:

#### TikiWikiClient
- `getMediaGalleryId($trackerId)` - Obtiene ID de galería de archivos del tracker
- `uploadFile($filePath, $fileName, $galleryId)` - Sube archivo a TikiWiki
- `createTrackerItem($trackerId, $fields)` - Crea item en tracker
- `messageExists($trackerId, $messageId)` - Verifica si mensaje ya existe
- `createTracker($name)` - Crea tracker con campos automáticamente

#### TelegramClient
- `getFileUrl($fileId)` - Obtiene URL de descarga de archivo de Telegram
- `getChat($chatId)` - Obtiene información del chat
- `downloadFile($fileUrl, $path)` - Descarga archivo a disco
- `getFileContent($fileId)` - Descarga archivo y retorna contenido

#### MessageMapper
- `toTrackerFields($message, $context)` - Transforma mensaje a campos TikiWiki
- `detectMessageType($message)` - Detecta tipo de mensaje y extrae info de media
- `extractText($message)` - Extrae texto de various formatos
- `extractDate($message)` - Convierte fecha a UNIX timestamp

## API y Webhooks

### Telegram Webhook Endpoint

**URL**: `https://dominio.com/trackergram/api.php`
**Método**: `POST`
**Content-Type**: `application/json`

#### Headers de Seguridad

```
X-Telegram-Bot-Api-Secret-Token: <secret_token_configurado>
```

#### Estructura del Webhook

```json
{
  "update_id": 123456789,
  "message": {
    "message_id": 123,
    "from": {
      "id": 987654321,
      "is_bot": false,
      "first_name": "Usuario",
      "username": "usuario_ejemplo",
      "language_code": "es"
    },
    "chat": {
      "id": -100123456789,
      "title": "Grupo de Ejemplo",
      "type": "supergroup"
    },
    "date": 1640995200,
    "text": "Mensaje de ejemplo"
  }
}
```

### TikiWiki API Integration

**Endpoint**: `TIKIWIKI_API_URL/trackers/{TRACKER_ID}/items`
**Método**: `POST`
**Autenticación**: Bearer Token

#### Campos del Tracker (Permanent Names)

| Campo TikiWiki | Origen | Formato |
|----------------|--------|---------|
| `telegrammessageTelegramMessageId` | `message.message_id` | Integer |
| `telegrammessageChatId` | `message.chat.id` | Integer |
| `telegrammessageChatTitle` | `message.chat.title` o `username` | String |
| `telegrammessageTopicId` | `message.message_thread_id` | Integer/String |
| `telegrammessageTopicTitle` | Hardcoded "General" | String |
| `telegrammessageUserId` | `message.from.id` | Integer |
| `telegrammessageUsername` | `message.from.username` | String |
| `telegrammessageFirstName` | `message.from.first_name` | String |
| `telegrammessageLastName` | `message.from.last_name` | String |
| `telegrammessageMessageType` | `extractMessageData().type` | String |
| `telegrammessageText` | `extractMessageData().text` | String |
| `telegrammessageMedia` | `downloadAndUploadMedia().fileId` | File (vinculado a galería) |
| `telegrammessageMediaUrl` | `extractMessageData().media_url` | String |
| `telegrammessageFileUrl` | `extractMessageData().media_url` | String |
| `telegrammessageMediaType` | `extractMessageData().media_type` | String |
| `telegrammessageMediaSize` | `extractMessageData().media_size` | Integer |
| `telegrammessageMediaCaption` | `extractMessageData().media_caption` | String |
| `telegrammessageMessageDate` | `message.date` | Integer |

## Configuración

### Variables de Entorno (.env)

```bash
# Modo debug (opcional)
DEBUG_MODE=true
```

### Constantes del Sistema

Las siguientes constantes están definidas en `config.php`:

```php
// Timeouts (en segundos)
TIMEOUT_TELEGRAM_API = 5      // Para llamadas a API de Telegram
TIMEOUT_TELEGRAM_DOWNLOAD = 10 // Para descarga de archivos de Telegram
TIMEOUT_TIKIWIKI_API = 30     // Para llamadas a API de TikiWiki
TIMEOUT_TIKIWIKI_UPLOAD = 30  // Para subida de archivos a TikiWiki

// Reintentos
RETRY_MAX_ATTEMPTS = 2        // Número máximo de reintentos
RETRY_DELAY_MICROSECONDS = 100000 // Delay entre reintentos (0.1 segundos)

// Cache
CACHE_ENABLED = true          // Habilitar cache de galleryId
```

### Variables de Entorno (.env)

```bash
# Token del Bot de Telegram (obligatorio)
TELEGRAM_BOT_TOKEN=1234567890:ABCdefGHIjklMNOpqrsTUVwxyz

# Secret Token para Webhook (opcional pero recomendado)
TELEGRAM_WEBHOOK_SECRET=tu_token_secreto_aleatorio_32_chars

# Configuración TikiWiki (obligatorio)
TIKIWIKI_API_URL=https://wiki.ejemplo.com/api/
TIKIWIKI_TOKEN=tikiwiki_bearer_token_aqui
TIKIWIKI_TRACKER_ID=1

# Credenciales Administración (obligatorio)
ADMIN_USERNAME=admin
ADMIN_PASSWORD=contraseña_segura_aqui

# Configuración Aplicación
DEBUG_MODE=false
```

### Configuración Apache (.htaccess)

```apache
# Seguridad - Bloquear acceso a archivos sensibles
<Files ".env">
    Require all denied
</Files>

<Files "config.php">
    Require all denied
</Files>

# Headers de Seguridad
<IfModule mod_headers.c>
    Header set X-Frame-Options "DENY"
    Header set X-Content-Type-Options "nosniff"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

## Procesamiento de Mensajes

### Tipos de Mensajes Soportados

| Tipo | Campo Telegram | Procesamiento |
|------|----------------|---------------|
| Texto | `message.text` | Texto plano |
| Foto | `message.photo` | Sube a TikiWiki gallery, texto: "Foto: image/jpeg" |
| Video | `message.video` | Sube a TikiWiki gallery, texto: "Video: video/mp4" |
| Audio | `message.audio` | Sube a TikiWiki gallery, texto: "Audio: audio/mpeg" |
| Documento | `message.document` | Sube a TikiWiki gallery, texto: "Documento: tipo - nombre" |
| Sticker | `message.sticker` | Sube a TikiWiki gallery, texto: "Sticker: image/webp" |
| Voz | `message.voice` | Sube a TikiWiki gallery, texto: "Nota de voz: audio/ogg" |
| Video Nota | `message.video_note` | Sube a TikiWiki gallery, texto: "Video circular: video/mp4" |
| Ubicación | `message.location` | Link a Google Maps |
| Contacto | `message.contact` | Nombre y teléfono |
| Encuesta | `message.poll` | Pregunta y opciones |
| Animation | `message.animation` | Tipo de archivo |
| Sistema | `message.forum_topic_*` | Texto descriptivo |
| No soportado | (otros) | Muestra tipo de mensaje |

### Flujo de Procesamiento

1. **Recepción**: Webhook recibe JSON de Telegram
2. **Validación**: 
   - Secret token (si está configurado)
   - Campos requeridos del mensaje
   - Chat ID permitido (si está configurado)
3. **Extracción**: `extractMessageData()` procesa el contenido
4. **Transformación**: Mapeo a campos de TikiWiki
5. **Envío**: POST a TikiWiki API con reintentos
6. **Logging**: Registro de operaciones y errores

## Seguridad

### Implementaciones de Seguridad v0.0.1

#### Autenticación (admin.php)
- **Contraseña obligatoria**: No permite login sin ADMIN_PASSWORD
- **Rate limiting**: 5 intentos máximos, bloqueo 15 minutos
- **Timeout de sesión**: 30 minutos de inactividad
- **Regeneración de sesión**: Cada 15 minutos previene fixation

#### Protección CSRF
- **Tokens en formularios**: Todos los POST requieren CSRF token
- **Validación**: Verificación estricta del token antes de procesar

#### Validación de Inputs
- **URLs**: Validación de formato http/https
- **Tokens**: Solo caracteres alfanuméricos, guiones y guiones bajos
- **Números**: Enteros positivos validados
- **Strings**: Sin caracteres de control, máximo 1000 caracteres

#### Sesión Segura
- **Cookies**: HttpOnly, Secure, SameSite Strict
- **Modo estricto**: `session.use_strict_mode = 1`
- **Timeout automático**: Destrucción de sesión por inactividad

#### Configuración Segura
- **DEBUG_MODE configurable**: Via .env (antes hardcoded)
- **Paths absolutos**: `__DIR__` para evitar problemas de contexto
- **Detección HTTPS**: Compatible con diferentes configuraciones de servidor

## Manejo de Errores

### Estrategia de Reintentos

- **Máximo reintentos**: 2 (configurable)
- **Tiempo de espera**: 0.1 segundos entre reintentos
- **Backoff**: No exponencial (simple para evitar bloqueos)

### Logging

- **Función principal**: `log_message(string $message): void` - Usar en todo el código
- **Archivo**: `debug.log` (solo si DEBUG_MODE=true en .env)
- **Formato**: `[YYYY-MM-DD HH:MM:SS] Mensaje`
- **Contenido**: Errores HTTP, fallos de API, validaciones
- **También** va a `error_log` del sistema (disponible en todos los entornos)

### Códigos de Error

| Situación | Acción |
|-----------|--------|
| JSON inválido | HTTP 400 + error |
| Secret token inválido | HTTP 403 + error |
| Chat no permitido | Silencio (no procesar) |
| Error TikiWiki | Reintentar hasta máximo |
| Campos faltantes | Log error + omitir |

## Monitoreo y Diagnóstico

### Verificación de Webhook

```bash
# Verificar estado actual
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"

# Probar webhook manualmente
curl -X POST https://dominio.com/trackergram/api.php \
  -H "Content-Type: application/json" \
  -H "X-Telegram-Bot-Api-Secret-Token: <SECRET>" \
  -d '{"update_id":1,"message":{"message_id":1,"from":{"id":1,"first_name":"Test"},"chat":{"id":1},"date":1640995200,"text":"test"}}'
```

### Logs de Depuración

```bash
# Ver logs en tiempo real
tail -f /path/to/trackergram/debug.log

# Buscar errores específicos
grep "ERROR" /path/to/trackergram/debug.log
```

### Estado del Sistema

- **Webhook**: Verificar con `getWebhookInfo`
- **TikiWiki**: Probar conexión manual con curl
- **Archivos**: Verificar permisos de `.env` (600/640)
- **PHP**: Verificar extensiones requeridas

## Rendimiento y Optimización

### Timeouts Configurados

- **cURL TikiWiki**: 5 segundos
- **cURL Telegram**: 5 segundos  
- **file_get_contents**: 5 segundos (Telegram API)

### Limitaciones

- **Procesos PHP**: Evita bloqueos con usleep vs sleep
- **Memoria**: Procesamiento individual de mensajes
- **Conexiones**: Reutilizadas cuando es posible

### Escalabilidad

- **Horizontal**: Múltiples instancias behind load balancer
- **Vertical**: Aumentar límites de PHP-FPM/Apache
- **Distribuida**: Servidor webhook separado de TikiWiki

## Integración con TikiWiki

### Configuración del Tracker

1. **Crear Tracker** en TikiWiki
2. **Configurar Campos** con permanent names específicos
3. **Generar Token** de API en TikiWiki
4. **Configurar Permisos** para el token

### Mapeo de Campos

El sistema mapea automáticamente los campos de Telegram a los campos del tracker usando los permanent names definidos.

### Formatos de Datos

- **Fechas**: Timestamp Unix (integer)
- **Texto**: HTML sanitizado con `htmlspecialchars()`
- **URLs**: Completas y válidas
- **IDs**: Enteros positivos

## Troubleshooting Avanzado

### Problemas Comunes

#### Webhook no responde
1. Verificar URL pública y accesible
2. Revisar configuración del servidor web
3. Verificar archivo `.htaccess`
4. Probar secret token

#### Error de conexión TikiWiki
1. Verificar URL de API
2. Validar token de API
3. Revisar logs de TikiWiki
4. Probar conexión manual

#### Mensajes duplicados
1. Verificar `message_id` único
2. Revisar reintentos automáticos
3. Chequear configuración de webhook

### Herramientas de Diagnóstico

- **Postman/Insomnia**: Para probar webhooks
- **curl**: Para pruebas rápidas
- **Browser DevTools**: Para interfaz admin
- **Logs del servidor**: Para problemas de red

## Versiones y Cambios

### v0.1.2 (Beta) - Actual
- Importación de exports de Telegram (ZIP)
- Creación automática de trackers con campos via API
- Interfaz de administración reorganizada con índice
- Seguridad: deduplicación, CSRF, checkAuth(), hash_equals()
- Fix: ModSecurity, tipos de campo TikiWiki

### v0.1.1 (Alpha)
- Deduplicación de mensajes basada en message_id
- Agregado soporte para ubicaciones, contactos, encuestas, animations
- Captura de nombre del chat (chat_title)
- Título del topic (topic_title)
- Mejor manejo de mensajes no soportados (muestra el tipo)
- Refactorización: type hints en funciones, constantes de configuración, logging unificado

### v0.1.0 (Alpha)
- Subida de archivos multimedia a TikiWiki file gallery
- Los archivos se vinculan al campo `telegrammessageMedia` del tracker
- El galleryId se obtiene dinámicamente desde la configuración del tracker via API (no hardcodeado)
- Campo de texto ahora muestra solo el MIME type (no HTML)
- Mejoras de seguridad en admin.php

### v0.0.1 (Alpha)
- Primera versión funcional
- Webhook endpoint para Telegram
- Integración básica con TikiWiki trackers
- Interfaz de administración

## Licencia y Soporte

- **Licencia**: MIT
- **Soporte**: Documentación + logs
- **Contribuciones**: GitHub issues
- **Versiones**: Semantic versioning
