# AGENTS.md — Guía de Contexto para Agentes de IA

> **⚠️ LECTURA OBLIGATORIA** — Este es el archivo de contexto principal para opencode. No toques código, no hagas cambios, no respondas issues sin haber leído este documento completo.

---

## Índice

1. [Proyecto trackerGram](#1-proyecto-trackegram)
2. [Decisiones Arquitectónicas](#2-decisiones-arquitectónicas)
3. [Estructura de Archivos](#3-estructura-de-archivos)
4. [Flujo de Datos](#4-flujo-de-datos)
5. [Tracker Fields (Schema)](#5-tracker-fields-schema)
6. [APIs Externas](#6-apis-externas)
7. [Entorno y Configuración](#7-entorno-y-configuración)
8. [Reglas para Agentes de IA](#8-reglas-para-agentes-de-ia)
9. [Qué NO hacer](#9-qué-no-hacer)
10. [Roadmap](#10-roadmap)
11. [Historial de Cambios](#11-historial-de-cambios)
12. [Gestión de Documentación](#12-gestión-de-documentación)
13. [Flujo de Git y Versionado](#13-flujo-de-git-y-versionado)

---

## 1. Proyecto trackerGram

### Qué es

trackerGram es un puente entre **Telegram** y **TikiWiki**. Recibe mensajes de un grupo de Telegram (vía webhook o importación de exports) y los guarda automáticamente como items en un tracker de TikiWiki.

**Problema que resuelve**: Centralizar conversaciones de Telegram en TikiWiki para que sean buscables, indexables y permanezcan accesibles fuera de la plataforma de mensajería.

### Filosofía

- Sin base de datos local — TikiWiki es el almacenamiento
- PHP puro, sin framework — simplicidad sobre complejidad
- MVP pragmático — funcionalidad primero, perfección después
- Iteración rápida — funciona, luego se mejora

### Estado

| | |
|---|---|
| **Versión** | v0.5.5 |
| **Estado** | Beta funcional, desarrollo activo |
| **Metodología** | Director humano + agentes de IA |
| **Repositorio** | https://github.com/cheperico/trackergram |
| **Branch estable** | `qpch` — monoTiki (un solo TikiWiki, un solo grupo, un solo bot, legacy) |
| **Branch desarrollo** | `main` — **arquitectura multi-conexión** (múltiples bots, wikis y trackers) |

### Qué funciona

- ✅ Webhook en tiempo real: mensajes de Telegram → TikiWiki
- ✅ Importación de exports ZIP de Telegram (incluyendo batch con progreso)
- ✅ Soporte multimedia: fotos, videos, audio, documentos, stickers, notas de voz
- ✅ Topics (forums) de Telegram con resolución de nombres
- ✅ Reacciones a mensajes
- ✅ Service messages (creación de topics, miembros, pins, etc.)
- ✅ Creación automática de trackers en TikiWiki
- ✅ Panel de administración web (clásico + moderno con progress bar)
- ✅ Deduplicación de mensajes
- ✅ Seguridad: CSRF, rate limiting, hash de contraseñas, path traversal protection, XSS fix (innerHTML→textContent), DoS protection, 20MB real download limit, token leak fix
- ✅ Reacciones formateadas como texto legible (👍 3 · ❤️ 1)
- ✅ Álbumes/grupos de medios (mediaGroup) en webhook
- ✅ Auto-reparación de galería (repairFgGallery)
- ✅ Webhook Secret obligatorio (rechaza 500 si vacío)
- ✅ Cache de topics por chatId:threadId
- ✅ Cache de gallery ID por tracker ($mediaGalleryIdCache[$trackerId])
- ✅ Import chunked (extract + process con NDJSON, barra de progreso)
- ✅ displayName unificado entre webhook e import
- ✅ change_password funcional (fuera de checkAuth, sin duplicados)
- ✅ Links clickeables en text_entities (importación de exports)
- ✅ log_message() respeta DEBUG_MODE: debug.log solo si DEBUG_MODE=true o $force=true (error crítico). Sistema (error_log) siempre.
- ✅ Pretty Tracker template (opt/visualizacion-tiki.md)
- ✅ Vista wiki tipo feed con TRACKERLIST + template Smarty personalizado (burbujas de chat)
- ✅ MediaUrl poblado automáticamente en webhook e import
- ✅ Parseo robusto de galleryId desde options del campo FG (múltiples formatos API + endpoint `/fields` correcto)
- ✅ `fromExport()` usa display name completo como firstName (consistente con webhook)
- ✅ `userId` extraído con regex (soporta prefijos user/chat/channel)
- ✅ Soporte para múltiples archivos por item (comma-separated en campo FG)
- ✅ Creación automática de file gallery al crear tracker + FG con count=0
- ✅ `uploadedFileIds` como array en NormalizedMessage
- ✅ Límites dinámicos mostrados en admin (`upload_max_filesize`, `MAX_ZIP_UNCOMPRESSED_SIZE`)
- ✅ Timeout específico para upload (60s) vs API general (30s) via constantes de config
- ✅ **Arquitectura multi-conexión**: múltiples bots, wikis y trackers desde una instalación
- ✅ `ConfigManager.php` — CRUD de conexiones persistidas en `setup.json`, slug auto-generado, migración desde `.env`
- ✅ Admin panel rediseñado con dos pestañas (Webhook + Importar) y tarjetas de conexión
- ✅ Conexión multi-bot identificada por `(chat_id, X-Telegram-Bot-Api-Secret-Token)`
- ✅ Webhook handler per-conexión: cada conexión usa su propio `TikiWikiClient` + `TelegramClient`
- ✅ Import per-conexión: acepta credenciales Tiki por formulario, las persiste en metadata.json
- ✅ Worker async multi-conexión: lee `connection_slug` del buffer, crea handler por conexión
- ✅ `.htaccess` bloquea `*.json`, fuerza HTTPS, agrega CSP header
- ✅ `config/` directorio fuera del webroot con deny-all
- ✅ **Async processing per-conexión**: cada conexión tiene su propio toggle async en el admin
- ✅ **`.env` simplificado**: solo contiene config global (admin, debug, async); credenciales bot/wiki solo en `setup.json`
- ✅ **Sin fallback legacy**: api.php rechaza con 403 si no hay conexión en `setup.json`
- ✅ **Accesibilidad ARIA completa en admin.php**: roles, landmarks, tooltips, skip link, focus trap, aria-live, teclado, contraste, prefers-reduced-motion
- ✅ **Webhook_secret compartido**: conexiones con mismo `bot_token` reusan el mismo `webhook_secret` (un bot = un webhook = un secret)
- ✅ **Updates sin error 409**: `getUpdates()` parsea el body de Telegram y detecta webhook activo; el admin muestra mensaje informativo en vez de "error"
- ✅ **Health check por conexión**: cada tarjeta en admin crea su propio `TelegramClient` (fix de leak de `$tgClient` entre conexiones)
- ✅ **checkPermissions sin side effects**: test de `admin_file_galleries` usa `DELETE /api/galleries/99999999/delete` (galería inexistente), no crea galerías reales

---

## 2. Decisiones Arquitectónicas

### Sin base de datos local

trackerGram no tiene base de datos propia. TikiWiki es el almacenamiento. La contrapartida es que toda la lógica de deduplicación, búsqueda y persistencia depende de la API de TikiWiki.

### PHP puro, sin framework

No hay Laravel, Symfony ni Composer. Archivos PHP que se incluyen directamente. La deuda técnica (sin autoloading PSR-4, sin tests unitarios fáciles) está identificada en el roadmap.

### Inyección de dependencias (por conexión ✅)

`TikiWikiClient`, `TelegramClient`, `WebhookHandler` y `MessageMapper` son **instanciables** con dependencias inyectadas por constructor. Ya no hay un wiring central en `bootstrap.php`. Cada entry point crea sus propios clientes por conexión desde `ConfigManager`:

```php
$tikiClient = new TikiWikiClient(
    apiUrl: $connection['tiki_api_url'],
    token: $connection['tiki_api_token'],
    timeout: TIMEOUT_TIKIWIKI_API,
    uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD
);
$tgClient = new TelegramClient(botToken: $connection['bot_token']);
$handler = new WebhookHandler(
    tikiWikiClient: $tikiClient,
    telegramClient: $tgClient,
    messageMapper: $messageMapper,
    trackerId: (int) $connection['tracker_id']
);
$handler->processUpdate($update);
```

Esto permite testear cada clase de forma aislada pasando mocks/stubs.

### TikiWiki como almacenamiento

La API de TikiWiki tiene particularidades:
- Campos con "permanent names" (permNames)
- File galleries para archivos adjuntos
- No es una API REST convencional
- Los items se crean con `fields[permName]=valor` (form-urlencoded)

### Desarrollado con asistencia de IA

El director (cheperico) no es programador profesional. Usa agentes de IA para implementar. Implicancias:
- El código funciona pero puede no seguir patrones convencionales
- Las sesiones largas de IA pierden contexto — por esto AGENTS.md es crítico
- La validación final siempre requiere pruebas humanas

---

## 3. Estructura de Archivos

### trackerGram (raíz)

La mayoría de los archivos están en la raíz. Subdirectorios:

| Directorio | Contenido |
|---|---|
| `opt/` | Investigaciones, templates Smarty, archivos opcionales |
| `tmp/` | Archivos temporales (rate limiting, buffers async, etc.) |
| `reports/` | Reportes históricos de auditoría (referencia) |
| `design/` | **📐 Documentos de diseño** — captura de decisiones, exploraciones y discusiones antes de implementar. **Leer antes de arrancar una feature nueva** para entender el contexto completo, alternativas consideradas y por qué se tomaron ciertas decisiones. Contiene: `001-configuracion-inversa-via-telegram.md`, `002-MiniApp.md`, `003-arquitectura-multi.md`. |
| `.opencode/` | Configuración de opencode (agentes, skills) |

#### Entry Points HTTP

| Archivo | Qué hace | Cuándo se ejecuta |
|---|---|---|
| `bootstrap.php` | Carga config + clases PHP (sin DI wiring central) | Siempre, primer include |
| `api.php` | Recibe webhooks de Telegram | Cuando Telegram envía un mensaje |
| `admin.php` | Panel de administración web | Cuando un humano abre la URL |
| `import.php` | Procesa exports ZIP de Telegram | Cuando un humano sube un ZIP |

#### Clientes y Lógica

| Archivo | Responsabilidad |
|---|---|
| `config.php` | Carga `.env`, define constantes globales, `log_message()` |
| `NormalizedMessage.php` | Modelo intermedio único entre parsers y TikiWiki |
| `TikiWikiClient.php` | API de TikiWiki (crear items, subir archivos, crear trackers, deduplicación) |
| `TelegramClient.php` | API de Telegram (descargar archivos, info de chats) |
| `MessageMapper.php` | Transforma mensajes → NormalizedMessage → campos TikiWiki |
| `WebhookHandler.php` | Orquesta: valida, resuelve topics, descarga media, envía a TikiWiki |
| `ConfigManager.php` | CRUD de conexiones multi-bot/wiki/tracker en `setup.json` |

#### Soporte

| Archivo | Qué es |
|---|---|
| `.env` | Variables de entorno (credenciales) — NO versionar |
| `.env.example` | Plantilla de variables de entorno |
| `.htaccess` | Apache: seguridad, rewrite, límites PHP |
| `topic_names.json` | Cache local de nombres de topics (auto-generado) |
| `setup.json` | Conexiones multi-bot/wiki/tracker (auto-generado por ConfigManager) — bloqueado via `.htaccess` |
| `debug.log` | Logs de debug (rotación automática a 10MB, escribe solo si DEBUG_MODE=true o error crítico con $force=true) |

#### Documentación

| Archivo | Propósito |
|---|---|
| `README.md` | Usuario final: qué es, cómo se usa |
| `INSTALL.md` | Instalación: requisitos, pasos, configuración |
| `TECHNICAL.md` | Desarrolladores: cómo está construido, tutorial |
| `AGENTS.md` | **Este archivo** — contexto para agentes de IA |
| `roadmap.md` | Pendientes, prioridades, bugs conocidos |
| `CAMBIOS.md` | Historial de cambios por versión |
| `opt/visualizacion-tiki.md` | Feed tipo chat en TikiWiki (investigación + template Smarty) — específico de wiki.chela.org.ar |
| `opt/telegram_bots.md` | Tokens de bots de Telegram — **NO versionar** (archivo local) |
| `design/*` | **📐 Documentos de diseño** — captura de decisiones, alternativas discutidas y arquitectura exploratoria antes de implementar. Leer siempre antes de arrancar una feature nueva (`001-configuracion-inversa-via-telegram.md`, `002-MiniApp.md`, `003-arquitectura-multi.md`). Este archivo se actualiza. |
| `..\TikiWiki\` | **Código fuente de TikiWiki 27.5** — fuera del workspace, en `C:\Users\Federico\Documents\OpenCode\TikiWiki\tiki-27.5\`. Solo referencia, no se versiona. El agente `@tiki-expert` lo consulta para entender comportamientos internos de la API. |

### Orden recomendado de lectura del código

1. `config.php` — constantes globales, timeouts, log_message()
2. `bootstrap.php` — carga de clases PHP (sin DI wiring)
3. `NormalizedMessage.php` — el modelo intermedio único
4. `api.php` — entry point del webhook, delega en WebhookHandler
5. `WebhookHandler.php` — el corazón: processUpdate → processMessage → sendToTikiWiki
6. `MessageMapper.php` — fromWebhook / fromExport → NormalizedMessage → toWikiFields
7. `TikiWikiClient.php` — cómo se envían a TikiWiki
8. `TelegramClient.php` — cómo se descargan archivos de Telegram
9. `import.php` — flujo alternativo de importación (usa fromExport)
10. `admin.php` — interfaz web de configuración

---

## 4. Flujo de Datos

### Webhook (tiempo real) — multi-conexión

```
Telegram → api.php
    → Extraer chat_id del update + X-Telegram-Bot-Api-Secret-Token header
    → Buscar conexión por (chat_id, webhook_secret) en ConfigManager
    → Si hay conexión:
        → Crear TikiWikiClient + TelegramClient + WebhookHandler per-conexión
        → $handler->processUpdate()
    → Si NO hay conexión (legacy):
        → Usar $webhookHandler global (de bootstrap, config .env)
    → Async opcional: buffer a tmp/buffer/ con connection_slug
```

**Procesamiento del handler (por conexión):**
```
$handler->processUpdate()
    → $handler->processMessage()
        → $messageMapper->fromWebhook()        # detecta tipo, extrae datos → NormalizedMessage
        → $telegramClient->getFileUrl()        # si tiene media
        → $tikiWikiClient->uploadFile()        # sube a file gallery
        → $tikiWikiClient->messageExists()     # deduplicación
        → $messageMapper->toWikiFields()       # NormalizedMessage → fields[permName]
        → $tikiWikiClient->createTrackerItem() # crea item en TikiWiki
```

### Importación (ZIP export)

```
admin.php → import.php
    → extraer ZIP, validar path traversal
    → parsear result.json
    → $messageMapper->fromExport()            # export → NormalizedMessage
    → $messageMapper->toWikiFields()          # NormalizedMessage → fields[permName]
    → $tikiWikiClient->createTrackerItem()
```

---

## 5. Tracker Fields (Schema)

El tracker por defecto tiene estos campos (permNames):

| PermName | Tipo | Descripción |
|---|---|---|
| `telegrammessageTelegramMessageId` | t (text) | ID del mensaje en Telegram |
| `telegrammessageChatId` | t (text) | ID del chat |
| `telegrammessageChatTitle` | t (text) | Título del chat |
| `telegrammessageTopicId` | t (text) | ID del topic/forum |
| `telegrammessageTopicTitle` | t (text) | Nombre del topic |
| `telegrammessageUserId` | t (text) | ID del usuario de Telegram |
| `telegrammessageUsername` | t (text) | Username |
| `telegrammessageFirstName` | t (text) | Nombre |
| `telegrammessageLastName` | t (text) | Apellido |
| `telegrammessageDisplayName` | t (text) | Nombre para mostrar (unificado: webhook concatena firstName+lastName, import copia from original) |
| `telegrammessageMessageType` | t (text) | Tipo: text, photo, video, etc. |
| `telegrammessageText` | a (textarea) | Contenido del mensaje |
| `telegrammessageLocation` | G (geolocation) | Coordenadas GPS (lon,lat,zoom) |
| `telegrammessageMediaType` | t (text) | MIME type del media |
| `telegrammessageMediaSize` | n (number) | Tamaño del archivo en bytes |
| `telegrammessageMediaCaption` | t (text) | Caption del media |
| `telegrammessageMessageDate` | f (datetime) | Fecha/hora del mensaje (timestamp UNIX) |
| `telegrammessageMedia` | FG (file gallery) | Referencia al archivo subido |
| `telegrammessageMediaUrl` | t (text) | URL pública del archivo en TikiWiki |
| `telegrammessageFileUrl` | t (text) | URL del archivo original en Telegram |
| `telegrammessageMediaWidth` | n (number) | Ancho del media en píxeles |
| `telegrammessageMediaHeight` | n (number) | Alto del media en píxeles |
| `telegrammessageMediaDuration` | DUR (duration) | Duración en segundos (audio/video/voice), se muestra como hh:mm:ss |
| `telegrammessageEditedDate` | t (text) | Unix timestamp de última edición (se muestra condicionalmente via Pretty Tracker) |
| `telegrammessageReplyToId` | t (text) | ID del mensaje al que responde (con link a vista filtrada via Pretty Tracker) |
| `telegrammessageReactions` | t (text) | Reacciones formateadas como texto legible (👍 3 · ❤️ 1) |

### Auto-detección de field prefix

Desde v0.5.4, el sistema **detecta automáticamente el field prefix real** del tracker cuando el almacenado es `telegrammessage` (el default). Esto permite:

- **Adaptarse a cualquier prefix**: si el tracker se creó con un prefix custom (ej: `soporte`, `qpch2`, `equipo`), el sistema lo detecta desde los nombres de campo reales en TikiWiki.
- **Corregir prefix mal guardados**: si `setup.json` tiene un prefix incorrecto por bugs anteriores, la auto-detección lo corrige al primer webhook y lo persiste.
- **Sin configuración extra**: no requiere que el usuario configure el prefix manualmente.

**Cómo funciona** (`TikiWikiClient::resolveFieldPriority()`):
1. Si el prefix almacenado NO es `telegrammessage`, se confía en él (el usuario lo configuró explícitamente).
2. Si es `telegrammessage` (default), fetchea `GET /api/trackers/{id}/fields`.
3. Busca campos con permNames que terminen en sufijos conocidos (`TelegramMessageId`, `ChatId`, `Text`, `MessageDate`, `Media`).
4. Extrae el prefijo común del primer match.
5. Si el detectado difiere del almacenado, lo persiste a `setup.json` vía `ConfigManager::updateConnectionFields()`.

**Cobertura**: webhook (api.php), async worker (worker.php), importación (import.php).

### Nota sobre field prefix

Los permNames de los campos ya **no están hardcodeados** como `telegrammessage*`. Ahora se genera dinámicamente desde el `field_prefix` de la conexión (ej: `qpch`, `soporte`, `telegrammessage`).

- El prefix se configura al crear el tracker desde el panel admin (pestaña "Crear Tracker").
- Máximo 16 caracteres, solo `[a-z][a-z0-9]*`, debe comenzar con letra.
- Conexiones existentes sin prefix usan `telegrammessage` por defecto (backward compatible).
- Todos los flujos (webhook, import, async) usan el field prefix de la conexión.
- **Auto-detección**: si el prefix almacenado es `telegrammessage` (el default), el sistema lo verifica contra los campos reales del tracker vía API y lo corrige automáticamente si detecta un prefix diferente. Ver sección [Auto-detección de field prefix](#auto-detección-de-field-prefix) más arriba.

### Tipos de campo en TikiWiki

| Código | Tipo | Uso |
|---|---|---|---|
| `t` | Text | Texto corto |
| `a` | Textarea | Texto largo |
| `n` | Number | Número |
| `f` | Date and Time | Fecha/hora (timestamp UNIX) |
| `D` | Date | Fecha (sin hora) |
| `G` | Geolocation | Coordenadas |
| `FG` | File Gallery | Archivo adjunto |
| `DUR` | Duration | Duración en segundos (se muestra como hh:mm:ss) |

---

## 6. APIs Externas

| API | Documentación |
|---|---|
| Telegram Bot API | https://core.telegram.org/bots/api |
| TikiWiki API | https://doc.tiki.org/API |

### Endpoints clave de TikiWiki API

```
GET  {api_url}trackers/{id}                    # Obtener tracker + campos
POST {api_url}trackers                         # Crear tracker
POST {api_url}trackers/{id}/items              # Crear item (form-urlencoded)
GET  {api_url}trackers/{id}/items?...          # Listar items (con filtros)
POST {api_url}galleries/upload                 # Subir archivo a file gallery
```

### Crear item en TikiWiki

```
POST {api_url}trackers/{id}/items
Authorization: Bearer {token}
Content-Type: application/x-www-form-urlencoded

fields[telegrammessageTelegramMessageId]=123
&fields[telegrammessageChatTitle]=Mi+Chat
&fields[telegrammessageText]=Hola+mundo
&fields[telegrammessageMessageDate]=1700000000
```

---

## 7. Entorno y Configuración

### Variables de entorno (.env) — configuración global

```ini
ADMIN_USERNAME=admin
ADMIN_PASSWORD=$2y$10$...hash...
DEBUG_MODE=false
ASYNC_PROCESSING=false

# NOTA: Las credenciales de bots, wikis y trackers se configuran
# desde el panel de admin y se persisten en setup.json (multi-conexión).
# .env ya no contiene TELEGRAM_BOT_TOKEN, TIKIWIKI_API_URL, etc.
```

### Constantes definidas en config.php

| Constante | Valor |
|---|---|---|
| `ADMIN_USERNAME` | del .env |
| `ADMIN_PASSWORD` | del .env (hash bcrypt) |
| `DEBUG_MODE` | del .env (default false) |
| `ASYNC_PROCESSING` | del .env (default false, sobrescribible por conexión) |
| `ALLOWED_CHAT_IDS` | del .env (filtro global opcional) |
| `TIMEOUT_TIKIWIKI_API` | 30 segundos |
| `TIMEOUT_TIKIWIKI_UPLOAD` | 60 segundos |
| `TIMEOUT_TELEGRAM_API` | 5 segundos |
| `TIMEOUT_TELEGRAM_DOWNLOAD` | 10 segundos |
| `MEDIA_DOWNLOAD_MAX_SIZE` | 20 MB |
| `MAX_ZIP_UNCOMPRESSED_SIZE` | 500 MB |
| `RETRY_MAX_ATTEMPTS` | 2 |
| `RETRY_DELAY_MICROSECONDS` | 100000 (0.1s) |
| `CACHE_ENABLED` | true |

### Servidores conocidos

| Servicio | URL |
|---|---|
| trackerGram webhook (prod) | https://trackergram2.cheps.chela.org.ar/api.php |
| TikiWiki API | https://wiki.chela.org.ar/api/ |
| TikiWiki (web) | https://wiki.chela.org.ar |

---

## 8. Reglas para Agentes de IA

### Contexto de desarrollo

- El director humano valida todo — no asumas que tu código es correcto sin que lo revise
- Las sesiones largas pierden contexto — referí siempre a los archivos específicos que modificás
- Este documento existe para compensar la pérdida de contexto entre sesiones

### Patrones comunes

- Siempre usar `bootstrap.php` como punto de entrada (en trackerGram)
- Siempre usar los clientes existentes, no hacer curl directo
- Siempre usar `MessageMapper` para transformación de datos
- Los logs van con `error_log()` y `log_message()`

### Convenciones de código

- PHP 8.0+ (usa `match`, `str_starts_with()`, named arguments)
- Inyección de dependencias por constructor (clases instanciables)
- Sin namespace, sin autoloading — `require_once` directo
- Sin comentarios innecesarios — código autoexplicativo

---

## 9. Qué NO hacer

> Estas son cosas que **ya pasaron y costaron arreglar**. No las repitas.

### Arquitectura

- **No agregar lógica de negocio en `api.php`** — Es solo entry point. Toda la lógica va en `WebhookHandler`.
- **No usar `curl` directamente** — Usar `TelegramClient` y `TikiWikiClient`.
- **No usar JSON en POST a API de TikiWiki** — La API NO mergea JSON body a `$_POST`. Todos los POST que crean o modifican recursos (trackers, fields, galleries) deben usar `application/x-www-form-urlencoded`.
- **No usar `GET /api/trackers/{id}` para obtener field definitions** — Ese endpoint devuelve **items**, no campos. Usar `GET /api/trackers/{id}/fields`. Confirmado en código fuente de TikiWiki (`ApiBridge.php` route `action=list_items`).
- **No usar `GET /api/trackers/{id}` para obtener field definitions** — Ese endpoint devuelve **items**, no campos. Usar `GET /api/trackers/{id}/fields`. Confirmado en código fuente de TikiWiki (`ApiBridge.php` route `action=list_items`).
- **No crear variables globales** — Usar `static` dentro de funciones o pasar por parámetros.
- **No asumir que HTTP 200 significa éxito** en actualizaciones de FG field options — `POST /api/trackers/{id}/fields/{id}` requiere `name` + `type` + `option[...]` en el body. Si falta `name`, TikiWiki salta el bloque de guardado pero igual responde HTTP 200 (y la respuesta siempre muestra options viejas, incluso si se guardó). Verificar con `GET /api/trackers/{id}/fields` después.
- **No requerir archivos individuales** — Siempre usar `require_once 'bootstrap.php'` (en trackerGram).
- **No duplicar lógica de parsing entre webhook e import** — Ambos convergen en `MessageMapper::toWikiFields()` vía `NormalizedMessage`. Webhook usa `fromWebhook()`, import usa `fromExport()`.
- **No depender del modo legacy** — El modo legacy (constantes en `.env`) fue eliminado. Todas las conexiones se configuran desde el panel admin y se persisten en `setup.json`. `api.php` rechaza con 403 si no hay conexión en `setup.json`.

### Telegram

- **No intentar usar `getForumTopic`** — No existe en la Telegram Bot API. Resolución de topics por cache + fallback.
- **No asumir que `message_id` es único sin `chat_id`** — Deduplicación es por par `(chat_id, message_id)`.
- **No usar `sleep()` en reintentos** — Usar `usleep()`.
- **No descargar archivos sin verificar tamaño** — Límite de 20MB (`MEDIA_DOWNLOAD_MAX_SIZE`).

### Seguridad

- **No exponer credenciales en URLs o logs** — Los tokens en URLs de descarga se filtraban.
- **No comparar contraseñas en texto plano** — Se usa `password_verify()` con bcrypt.
- **No saltar validación CSRF en admin POST** — Todas las acciones mutantes la requieren.
- **No extraer ZIPs sin validar path traversal** — Verificar que ningún entry contenga `..`.
- **No usar `innerHTML` en el JS del admin** — Riesgo XSS. Usar `textContent`.

### General

- **No modificar `.env` manualmente en producción** — Usar el panel de admin.
- **No cargar archivos completos en memoria** — Usar chunks para descargas.
- **No actualizar código sin actualizar esta documentación** — AGENTS.md debe reflejar el estado real.

---

## 10. Roadmap

Ver [roadmap.md](roadmap.md) para el **roadmap unificado** (consolidado de reports + roadmap anterior). Prioridades reales ordenadas por fase.

---

## 11. Historial de Cambios

Ver [CAMBIOS.md](CAMBIOS.md) para el detalle completo por versión.

### Resumen

| Versión | Cambio principal |
|---|---|---|
| v0.5.3 | **Fix updateFgFieldOptions + gallery ID opcional + fan-out**: FG field options ahora requieren `name`+`type` en POST. Gallery ID opcional en crear tracker (auto-crea si no se provee). Fan-out: mismo mensaje a múltiples trackers. Auto-población bot_name/chat_title. Eliminado auto-fill de tokens via AJAX. |
| v0.5.2 | **Accesibilidad ARIA completa en admin.php**: roles, landmarks, tooltips, skip link, focus trap, aria-live, contraste, prefers-reduced-motion |
| v0.4.0 | **Async processing per-conexión + .env simplificado + adiós legacy**: toggle async por conexión en admin, api.php rechaza 403 sin conexión (sin fallback legacy), config.php sin constantes de credenciales, bootstrap.php sin DI wiring (cada entry point crea sus clientes), import.php usa MessageMapper local y requiere credenciales Tiki, TikiWikiClient.getBaseUrl() |
| v0.3.0 | **Arquitectura multi-conexión**: ConfigManager, setup.json, admin con pestañas, webhook multi-bot, import per-sesión, worker async multi-conexión, .htaccess mejorado, config/ fuera del webroot |
| v0.2.3 | log_message() ahora respeta DEBUG_MODE (debug.log solo cuando DEBUG_MODE=true o $force=true en errores críticos), documentación completa auditada y sincronizada |
| v0.2.1 | Vista wiki tipo feed con TRACKERLIST + template Smarty, multimedia HTML5 nativo, mediaUrl auto-populado |
| v0.1.9 | Fix galería (parseo multi-formato de options FG), fix usuario import (firstName completo, userId por regex), uploadedFileIds como array, createTracker() con galería + count=0 |
| v0.1.8 | Reactions formateadas, links clickeables en imports, messageDate tipo Date, log_message() siempre escribe, Pretty Tracker template |
| v0.1.7 | `api.php` → entry point puro, `WebhookHandler` creado, `bootstrap.php`, seguridad ZIP |
| v0.1.6 | Hash contraseñas, path traversal, chunked downloads, rate limiting, refactor `toWikiFields()` |
| v0.1.5 | Fix TypeError PHP 8.5, eliminado `getForumTopic`, reorganizado admin panel |
| v0.1.4 | Separación de clientes, importación ZIP, soporte multimedia |
| v0.1.2 | Creación automática de tracker, deduplicación, seguridad CSRF |
| v0.1.1 | Deduplicación por message_id, más tipos de mensaje, geolocation |
| v0.1.0 | Subida de multimedia a file gallery |
| v0.0.1 | Primera versión funcional |

---

## 12. Gestión de Documentación

La documentación del proyecto se distribuye en varios archivos. Cada uno con propósito, audiencia y reglas específicas. El **agente orquestador** es responsable de mantener toda esta documentación sincronizada con el código.

### Reglas por archivo

| Archivo | Para quién | Qué contiene | Regla clave |
|---------|-----------|-------------|-------------|
| `README.md` | Usuario final (técnico + no técnico) | Qué hace, cómo se usa, instalación rápida | Simplificar, mover tecnicismos a otros docs |
| `TECHNICAL.md` | Desarrollador / aprendiz | Decisiones de arquitectura, flujo educativo, lecciones | Explicar el "por qué" + "cómo" |
| `INSTALL.md` | Usuario que instala | Pasos de instalación exhaustivos | Solo instalación, detalle completo |
| `roadmap.md` | Equipo de desarrollo | Items pendientes por fase | Marcar completados, agregar nuevos, consolidar |
| `AGENTS.md` | Agentes de IA | Contexto completo del proyecto | Fuente de verdad para agentes |
| `CAMBIOS.md` | Todos | Historial de cambios por versión | Changelog cronológico |
| `design/*` | Equipo de desarrollo | Diseño exploratorio pre-implementación | Mantener como referencia, pasar a roadmap cuando madure |
| `reports/*` | Histórico | Auditorías externas | NO borrar, roadmap consolida items accionables |
| `opt/*` | Uso local | Credenciales, templates de instancia | NO versionar en GitHub |

### Reglas detalladas

**README.md**
- **Debe** tener una sección de "Instalación Rápida" al inicio y enlaces al resto de la documentación
- **Debe** incluir tabla de mensajes soportados para que el usuario sepa qué esperar
- **Puede** incluir el schema del tracker (campos) solo si es necesario para configuración manual; si pesa mucho, mover a TECHNICAL.md
- **NO**: Decisiones de arquitectura, detalles internos de código, referencias a constantes internas

**TECHNICAL.md**
- **Debe** ser educativo: explicar el "por qué" además del "cómo"
- **Debe** actualizarse cuando cambia la arquitectura (DI, multi-conexión, async, etc.)
- **Debe** incluir referencias a APIs externas (Telegram, TikiWiki)
- **Debe** incluir lecciones aprendidas y problemas resueltos
- **NO**: Guías de instalación, tablas de compatibilidad de mensajes (eso va en README)

**INSTALL.md**
- **Debe** actualizarse cuando cambian los requisitos de instalación
- **Debe** ser exhaustivo: cubrir todos los pasos desde cero (crear bot, configurar TikiWiki, deploy, configurar webhook)
- El README tiene la "instalación rápida", este es el detalle completo

**roadmap.md**
- **Debe** actualizarse cuando un item se completa (mover a "funciona sólido")
- **Debe** agregar items nuevos cuando surgen
- Items de `design/` pasan al roadmap cuando el diseño está listo y solo necesita retoques de implementación
- **NO**: Items ya implementados (solo si están en "funciona sólido")
- **Formato**: Tabla por fase con #, Item, Esfuerzo, Notas/Por qué ahora

**design/***
- **Propósito**: Capturar decisiones, alternativas y discusiones antes de implementar
- **Cuándo pasan a roadmap**: Cuando el diseño avanza lo suficiente y solo necesita retoques de implementación
- **NO**: Borrar — mantener como referencia histórica de decisiones

**reports/***
- **Propósito**: Referencia histórica de investigaciones/auditorías externas
- **Cuándo borrar**: NUNCA — mantener como referencia. El roadmap.md ya consolidó los items accionables.
- Excepción: Si un reporte fue dividido y absorbido completamente por otro archivo (ej: template-wiki-feed → opt/visualizacion-tiki.md), el original puede eliminarse.

**opt/***
- **Propósito**: Credenciales locales, templates específicos de instancia, cosas útiles pero no parte del código
- **NO versionar en GitHub** — son locales

### Flujo de actualización

Cuando el orquestador recibe una tarea que toca documentación:
1. **Leer el código** — entender qué cambió realmente
2. **Leer cada doc** — identificar qué está obsoleto
3. **Evaluar reports/design/** — ¿hay items para mover/archivar?
4. **Actualizar docs** según las reglas de cada uno
5. **Verificar consistencia** — que los docs no se contradigan entre sí
6. **Reportar** al usuario qué cambió

## 13. Flujo de Git y Versionado

### Ramas y Tags

| Nombre | Tipo | Propósito |
|--------|------|-----------|
| `main` | branch | **Desarrollo de nueva arquitectura** — router multi-grupo, Mini App, mensajes prefijados, async worker, etc. Todo lo nuevo va acá. |
| `qpch` | branch | **Versión monoTiki estable** — un solo TikiWiki, un solo grupo Telegram, un solo bot. Para el grupo "Qué pasa cheLA?". Solo bugfixes y parches menores. |
| `qpch` | tag | Referencia histórica del punto exacto donde se creó el branch `qpch`. No se mueve. |
| `qpch-vX.Y.Z` | tag | Hotfixes sobre qpch branch (ej: `qpch-v0.3.1`) |

### Cómo trabajar

**Para desarrollo de features nuevas (arquitectura multi-grupo, etc.):**
```bash
git checkout main
# ... desarrollar, commit, push ...
```

**Para arreglar un bug en producción (qpch branch):**
```bash
git checkout qpch
git checkout -b fix/qpch-algo     # branch temporal para el fix
# ... arreglar, commit ...
git tag -a qpch-v0.3.1 -m "descripción del hotfix"
git push origin qpch-v0.3.1
git checkout qpch
git merge fix/qpch-algo           # mergear el fix al branch qpch
git push origin qpch
git branch -d fix/qpch-algo       # limpiar branch temporal
# Opcional: mergear el fix también a main
```

**Reglas:**
- `qpch` (branch) es la versión estable monoTiki — solo bugfixes, sin features nuevas
- Los hotfixes se taggean como `qpch-vX.Y.Z` incremental
- `main` es donde se desarrolla todo lo nuevo
- No hay branches `develop`, `staging`, etc. — simpleza sobre complejidad

---

## Mantenimiento de Este Archivo

Actualizar cuando:
1. Se agrega una funcionalidad principal
2. Se modifica la estructura de archivos
3. Se cambia la visión o filosofía del proyecto
4. Se alcanza una nueva versión
5. Un nuevo desarrollador o agente se une al proyecto

**No actualizar** por fixes de bugs menores — esos van a CAMBIOS.md.
