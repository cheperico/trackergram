# trackerGram - Documentación Técnica

## Arquitectura General

### Flujo de Datos

```
Alguien escribe en el grupo de Telegram
       ↓
Telegram busca el webhook configurado para ese bot
       ↓
Telegram hace POST a la URL del webhook con los datos del mensaje (JSON)
       ↓
api.php recibe el POST, procesa el mensaje
       ↓
api.php envía los datos a la API de TikiWiki
       ↓
El mensaje aparece como un item en el tracker de TikiWiki
```

### ¿Qué es un webhook?

Un webhook es una forma de que Telegram **te avise** cuando pasa algo, sin necesidad de que vos estés preguntando constantemente.

**Sin webhook**: Tu servidor tiene que preguntar "¿hay mensajes nuevos?" cada 2 segundos (polling). Ineficiente y lento.

**Con webhook**: Telegram te llama a vos cuando hay un mensaje nuevo. Vos solo tenés que darle una URL pública donde recibir el aviso. Telegram hace un POST a esa URL con un JSON que contiene todos los datos del mensaje.

### Requisitos de la URL del webhook

La URL del webhook debe cumplir con estas condiciones para que Telegram pueda usarla:

- **Pública**: Telegram debe poder llegar a ella desde internet. No sirven IPs privadas (192.168.x.x, 127.0.0.1) ni localhost.
- **HTTPS**: Telegram solo acepta URLs con HTTPS (certificado SSL válido).
- **Apuntar a `api.php`**: La URL debe terminar en `/api.php`, que es el endpoint que procesa los mensajes.

Ejemplo válido: `https://trackergram.chelachela.duckdns.org/api.php`
Ejemplo inválido: `http://localhost/trackergram/api.php` (no es pública ni HTTPS)

### Cómo se configura el webhook en trackerGram

Hay tres formas:

1. **Desde el admin panel** (sección 4): apretar "Actualizar Webhook". Usa la URL de `CUSTOM_WEBHOOK_URL` del `.env` si está configurada, o auto-detecta la URL del servidor.
2. **Desde la línea de comandos**: ejecutar `setup_webhook.php` en el servidor (solo CLI o localhost por seguridad).
3. **Manual**: usando curl para llamar a la API de Telegram directamente:
   ```bash
   curl -s "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://midominio.com/api.php&secret_token=MI_SECRET"
   ```

Para verificar el estado actual del webhook:
```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
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
| `telegrammessageTopicTitle` | Cache local + fallback (ver sección "Resolución de Nombres de Topics") | String |
| `telegrammessageUserId` | `message.from.id` | Integer |
| `telegrammessageUsername` | `message.from.username` | String |
| `telegrammessageFirstName` | `message.from.first_name` | String |
| `telegrammessageLastName` | `message.from.last_name` | String |
| `telegrammessageMessageType` | `extractMessageData().type` | String |
| `telegrammessageText` | `extractMessageData().text` | String |
| `telegrammessageMedia` | `downloadAndUploadMedia().fileId` | File (vinculado a galería) |
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

## Resolución de Nombres de Topics (Forums)

### El problema

En grupos de Telegram con temas (forums), cada mensaje pertenece a un topic identificado por `message_thread_id`. El webhook recibe este ID pero **no** el nombre del topic. Telegram Bot API no tiene un método `getForumTopic` para obtener el nombre a partir del ID.

### La solución

Se usan tres mecanismos en orden de prioridad:

1. **`reply_to_message.forum_topic_created.name`** — Cuando un mensaje responde al mensaje de creación de un topic (el service message `forum_topic_created`), Telegram incluye el nombre del topic en `reply_to_message`. Esto permite capturar el nombre exacto sin llamar a ninguna API.

2. **Cache local (`topic_names.json`)** — Cuando se detecta un `forum_topic_created`, el nombre se guarda en un archivo JSON con el `message_thread_id` como clave. Los mensajes posteriores en el mismo topic leen el nombre desde esta cache sin necesidad de repetir el paso 1.

3. **Fallback** — Si no se pudo obtener el nombre por ninguno de los mecanismos anteriores:
   - Si hay un `message_thread_id` numérico (grupo con temas): se usa `'Topic-XX'` como identificador
   - Si no hay `message_thread_id` (grupo sin temas): se usa `'General'`, que es el nombre por defecto

### Nota

`getForumTopic` no existe como método de la Telegram Bot API. Cualquier intento de usarlo fallará silenciosamente. La resolución de nombres depende exclusivamente de los mensajes de creación de topics y la cache local.

---

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

---

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
