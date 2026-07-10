# Cambios - Changelog

## v0.6.3

### 🌐 Sistema de internacionalización (admin.php)
- **Nuevo**: `lang/` directorio con `es.php` (español) y `en.php` (inglés) ~80 claves c/u.
- **Nuevo**: `lang/load.php` — Loader con detección de idioma (GET > sesión > default 'es'), helpers `__()` y `_n()`.
- **Nuevo**: Selector ES|EN en navbar con persistencia en sesión. Links preservan tab/view activo.
- **Cambio**: bootstrap.php ahora incluye `lang/load.php`.
- **Cambio**: admin.php — ~60 strings visibles reemplazadas por `__()` (login, navbar, tabs, view toggle, classic + grouped view, modal, import, create tracker, global config). `<html lang>` dinámico.
- **Cambio**: admin_handlers.php — ~15 mensajes de success/error traducidos.
- **Nuevo**: admin.css — Estilos para `.lang-switch` en navbar.
- **Pendiente**: 3 strings en admin.js (modal title, mostrar/ocultar) y ~10 errores de borde en handlers aún sin traducir (trackeado en roadmap.md F4-5, F4-6).
- **Nota técnicas**: Las funciones `__()` y `_n()` están disponibles en todos los entry points via bootstrap. La sesión se arranca con guard `session_status()===NONE` para evitar double-start. api.php, import.php y worker.php son seguros.

---

### 🧹 Cache leaks resueltos (F3-10, F3-11, F3-13, #18, #19)

#### F3-10: `topic_names.json` crecía sin límite
- **WebhookHandler::withTopicNamesLock()**: Poda automática cuando supera 1000 entradas, recorta a las 500 más recientes.

#### F3-11: `tg_admin_rate_*` sin GC
- **admin.php**: Nueva función `gcAdminRateFiles()` con GC probabilístico 1% (>1h sin actividad), mismo patrón que `tg_rate_*` en api.php. Se ejecuta al inicio de cada request.

#### F3-13: `collect_sessions.json` acumulaba sesiones huérfanas
- **CollectSessionManager**: Nuevo método `gcSessions()` que purga sesiones con más de 1 hora sin actividad. Se ejecuta en cada `withLock()`. `last_activity` se actualiza automáticamente en cada `set()`.

#### #18: `chats_detectados.json` — ignored crecía sin límite
- **detect_helper.php**: `saveDetections()` ahora poda: max 100 entradas por slug en ignored, y elimina detecciones con más de 30 días de antigüedad.

#### #19: `debug_fallback.log` sin rotación
- **config.php**: Si `debug_fallback.log` supera 10MB, se trunca automáticamente.

**Nota**: F3-12 (import chunked temp leak) ya estaba resuelto desde antes — `handleProcess()` limpia con `rrmdir()` al final del último batch.

---

### 🔧 Backoff exponencial en GET requests de TikiWikiClient (#12)
- **TikiWikiClient**: `findItemByMessageId()`, `getTrackerItem()` y `loadTrackerFields()` ahora tienen retry loop con exponential backoff (`RETRY_DELAY_MICROSECONDS × 2^attempt`, solo en curl errors o HTTP 5xx). `loadTrackerFields()` no reintenta en 4xx o respuesta inválida.

### 📎 Nombre de archivo desde file_path (#11)
- **TelegramClient**: Nuevo método `getFileInfo($fileId)` que cachea la respuesta de `getFile()` en `$fileInfoCache[]`. `getFileUrl()` refactorizado para reusar el cache.
- **WebhookHandler::processMessage()**: Si `fileName` es genérico (`Documento`, `telegram_photo_*`, `animation`), llama a `getFileInfo()` y extrae `basename(file_path)` para un nombre más descriptivo.

### 🗑️ F3-8: @ operator eliminado en puntos críticos
- **WebhookHandler.php**: `@fopen` en dedup locks reemplazado con log + graceful fallback; `@unlink` reemplazado con `file_exists()` + `unlink()`.
- **api.php**: `@mkdir`, `@file_put_contents`, `@rename` en buffer async reemplazados con log de errores + fallback a sync processing.
- **CollectSessionManager.php**: `@fopen` reemplazado con log de `error_get_last()`.
- **import.php**: 3× `@fopen`, 4× `@file_put_contents`, `@session_start`, `@unlink` en `rrmdir()` reemplazados.
- **worker.php**: `@filemtime`, `@unlink` en GC reemplazados.
- `@` se mantiene solo en `log_message()` de `config.php` (intencional: no crashear por fallo de logging).

### 💾 F3-9: Reply-To cache local (chat_id, message_id) → itemId
- **TikiWikiClient::createTrackerItem()**: Cambia tipo de retorno de `bool` a `int|false` (devuelve el itemId).
- **WebhookHandler::sendToTikiWikiWithRetries()**: Cambia tipo de retorno de `bool` a `int|false`.
- **WebhookHandler**: Nuevos métodos `replyCachePath()`, `cacheReplyMapping()`, `lookupReplyCache()` con operaciones atómicas `flock(LOCK_EX/LOCK_SH)` en `reply_cache.json`.
- `processMessage()` cachea `(trackerId, chatId, messageId) → itemId` post-creación.
- Reply resolution consulta cache primero; API de TikiWiki solo si miss.

### 🚚 #6: Chat_id unificado para imports con migración grupo→supergrupo
- **import.php**: Detección de service messages `migrate_to_supergroup` / `migrate_from_group` durante la creación del NDJSON.
- **Estrategia unificada**: todos los mensajes de la conversación usan el chat_id FINAL (supergrupo con `-100`).
- Si el `migrate_to_supergroup` trae el ID real del supergrupo en `text`/`title`, lo usa como override.
- Si el root `id` es del grupo básico pero hubo migración, fuerza el prefijo `-100`.
- Helper `rawChatIdToFinal()` extraído para reuso.
- Cubre ambos paths: chunked (extract + process) y legacy (full).
- Flag `migrated` + `migration_point_id` persistido en metadata.json.

### 🐛 Fix: Syntax error en admin.php
- **admin.php**: Stray `}` duplicado antes de `generateWebhookUrl()` (introducido en F3-11) — eliminado.

### 🫂 #10: Álbumes atómicos en un solo item (race-free)

- **WebhookHandler**: Nueva función `withAlbumBufferLock()` que centraliza el lock exclusivo sobre `media_group_album.json`.
- **Nuevo método `registerOrLookupAlbum(mediaGroupId)`**: dentro de un solo LOCK_EX — si el álbum no existe, lo **reserva** con `pending=true` y `itemId=0` (modo creator); si ya existe, retorna la entrada (modo append). Elimina la race condition entre fotos concurrentes del mismo álbum.
- **Nuevo método `completeAlbumRegistration(mediaGroupId, itemId)`**: llena el `itemId` real y quita `pending` tras crear el item exitosamente.
- **Nuevo método `removeAlbumRegistration(mediaGroupId)`**: elimina la entrada pending del buffer si falló la creación.
- **Los 3 métodos** (`registerOrLookupAlbum`, `completeAlbumRegistration`, `removeAlbumRegistration`) ahora operan dentro de `withAlbumBufferLock()`, garantizando exclusión mutua sin locks externos.
- **`processMessage()`**: flujo bifurcado. Si `mediaGroupId` está presente:
  1. `registerOrLookupAlbum()` → si es nuevo (pending) → crea item + `completeAlbumRegistration()`.
  2. Si ya existe → `appendMediaToTrackerItem()` + retorna sin crear item nuevo.
- **`appendMediaToTrackerItem(trackerId, itemId, fileId)`**: método nuevo en TikiWikiClient que agrega un `fileId` al campo FG de un item existente. Es **idempotente**: verifica que el `fileId` no esté ya presente en el campo FG antes de agregarlo.
- **GC probabilístico** actualizado: entradas `pending` >5 minutos se consideran stale (creator crasheó). Completadas >1 hora se limpian.
- **Cobertura**: webhook sync + worker async (ambos llaman a `processMessage()` sin cambios extra).

### 💥 #8: Excepciones de dominio

- **exceptions.php**: Nuevo archivo con jerarquía de excepciones:
  - `TrackerGramException` (base, extends `\RuntimeException`)
  - `ConfigException` (configuración inválida, JSON corrupto)
  - `TelegramApiException` (fallos en API de Telegram)
  - `TikiWikiApiException` (fallos en API de TikiWiki)
  - `ImportException` (errores de importación)
  - `SecurityException` (violaciones de seguridad)
- **bootstrap.php**: Incluye `exceptions.php`.
- **ConfigManager::load()**: lanza `ConfigException` si `setup.json` tiene JSON corrupto (antes devolvía `[]` silenciosamente).
- **api.php**: catch distingue `TrackerGramException` — loggea la clase corta (ej: `ConfigException`) en vez de mensaje genérico.
- **import.php**: `handleException()` muestra el tipo exacto de excepción, responde 400 para `ImportException`, 500 para las demás.
- **Sin backward compat**: métodos que retornaban `false`/`null` en errores siguen haciéndolo. Excepciones solo donde reemplazan fallos silenciosos.

### 🐛 Code review fixes (3 hallazgos)

- **worker.php**: Race condition en `ftruncate(0)` — movido `ftruncate()` antes de `fclose()` y agregado `rewind()`.
- **api.php**: `missing_token` rate key — `$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? 'missing_token'` en vez de variable indefinida. GC de rate files con `DirectoryIterator` en vez de `scandir()` + `array_diff()`.
- **import.php**: Nueva constante `MAX_JSON_IMPORT_SIZE` (150MB) para validar tamaño de `result.json` al extraer ZIPs.

### ✅ F3-1..F3-7 verificados como ya implementados

Items del code review que ya estaban implementados en sesiones previas (verificados contra código, sin cambios):

| Item | Código verificado |
|------|-------------------|
| F3-1: Hashtags con regex | `preg_match_all('/#(\w+)/u')` en MessageMapper.php:43 |
| F3-2: SSRF fail-closed | `throw new \RuntimeException()` en TikiWikiClient.php:225 |
| F3-5: Rate key secret_token | `$rateKey = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']` en api.php:30 |
| F3-6: N+1 prefix con flag | `resolvedPrefixCache[]` + `prefixVerified` flag |
| F3-7: Archivos a TEMP_DIR | `topic_names.json` y `media_group_captions.json` ya usan `TEMP_DIR` |

### 🐛 Fix: Sticker sin mediaSize (#1 hallazgo)
- **MessageMapper::fromWebhook()**: Agregado `$msg->mediaSize = (string) ($sticker['file_size'] ?? '')` en el handler de sticker. El objeto sticker de Telegram tiene `file_size` pero no se capturaba, dejando el campo `MediaSize` vacío para stickers.

### 🐛 Fix: Caption perdido en exports sin media (#4 hallazgo)
- **MessageMapper::fromExport()**: Cuando el export ZIP de Telegram excluye archivos multimedia (`"(File not included..."`), el tipo de mensaje se degradaba a `'text'` y el caption se perdía. Ahora se detecta el tipo correcto (photo/file) y se captura el caption aunque el archivo no esté incluido.
- Ampliado el fallback de `mediaCaption` para photos: si `photo_caption` está vacío, busca `caption`.

### 🛠️ Refactor: messageExists/findItemByMessageId con string IDs
- **TikiWikiClient::messageExists()**: Type hint cambiado de `int $messageId` a `int|string $messageId`. La URL ahora usa `urlencode()` para soportar IDs sintéticos como `"reaction_123_456_789"`.
- **TikiWikiClient::findItemByMessageId()**: Mismo cambio — `int|string $messageId`. Ya usaba `urlencode()` internamente.
- Sin regresión: todos los callers existentes pasan int, que es compatible.

### 🐛 Fix: Dedup en handlers de reacción (#5 hallazgo)
- **WebhookHandler::processMessageReaction()**: Agregado `messageExists()` check antes de crear el item. Si Telegram reenvía el mismo evento de reacción, se skipea.
- **WebhookHandler::processMessageReactionCount()**: Mismo dedup para conteo de reacciones.

### 📝 Docs: messageType completo en schema
- **AGENTS.md** y **README.md**: Lista de valores de `MessageType` actualizada: `text, photo, video, audio, document, sticker, voice, video_note, animation, location, contact, poll, quiz, system, other`. Antes decía `"...etc."` omitiendo `video_note`, `animation`, `location`, `contact`, `poll`, `quiz` y `other`.

### 🐛 Data Flow — Code Review (3 hallazgos)

#### F1: Album dedup gap (✅ ya implementado previamente)
- **WebhookHandler::registerOrLookupAlbum()**: Guarda `messageIds[]` por entrada de álbum. Cuando Telegram reintenta una foto secundaria con el mismo `message_id`, el buffer detecta el duplicado vía `['duplicate' => true]` y el caller en `processMessage()` lo ignora.
- **WebhookHandler::processMessage()**: Handler de `['duplicate' => true]` libera el lock y retorna sin modificar el item.

#### F2: Import migration dup — fallback en full import
- **import.php::handleFull()**: Agregado fallback de `$oldChatId` en la búsqueda de deduplicación (previamente solo existía en `handleProcess()`). Cuando un mensaje de chat migrado no se encuentra por el `chat_id` unificado (`-100xxx`), reintenta la búsqueda con el `chat_id` antiguo (`-xxx`). Aplica a la ruta de importación completa (no-chunked).

#### F3: Edited message out-of-order — delegación segura
- **WebhookHandler::processEditedMessage()**: Cuando el item no existe (edited_message llegó antes que el message original), libera el TOCTOU lock y delega a `processMessage()` para crear el item con los datos del edit. El mensaje original que llegue después será capturado por deduplicación. No hay deadlock porque se libera el lock antes de la delegación.

#### F4: GC de lock files huérfanos de dedup
- **WebhookHandler::gcDedupLocks()**: Nueva función de GC probabilístico (~1% por `processUpdate()`) que limpia archivos `*.lock` en `TEMP_DIR/dedup_locks/` con más de 1 hora de antigüedad. Si PHP crashea entre la creación del lock file y su `unlink()`, el archivo queda huérfano. Este GC evita su acumulación.
- **WebhookHandler::processUpdate()**: Llama a `gcDedupLocks()` junto con `gcAlbumBuffer()`.

### 🔒 XSS prevention: strip_tags() en campos texto de usuario
- **MessageMapper::toWikiFields()**: Aplicado `strip_tags()` a todos los campos texto de usuario que van a TikiWiki: `ChatTitle`, `TopicTitle`, `Username`, `FirstName`, `LastName`, `DisplayName`, `Text`, `MediaCaption`, `ReplyToId`, `Reactions`, `Hashtags`.
- **MessageMapper::toWikiFieldsEdit()**: Aplicado `strip_tags()` a `Text` y `Reactions`.
- **AGENTS.md**: Nueva regla "Siempre aplicar `strip_tags()` a texto de usuario antes de enviarlo a TikiWiki".
- **design/999-a-tener-en-cuenta.md**: Actualizado §5 sobre inyección de código vía valores de campos.

### 🌐 Admin UI: Help icon + footer
- **admin.php**: Agregado icono de ayuda (❓) en navbar que enlaza a GitHub.
- **admin.php**: Agregado footer con versión de trackerGram y link a GitHub.
- **admin.css**: Estilos para `.nav-help`, `.admin-header`, `.admin-title`, `.admin-footer`.
- **lang/en.php**: Agregadas claves `nav.help`, `nav.help_aria`, `admin.title`.
- **lang/es.php**: Agregadas claves `nav.help`, `nav.help_aria`, `admin.title`.

---

## v0.5.14

## v0.5.14

### 🐛 Fixes de code review de julio 2026

#### 🔴 Fix #1: IPv6-only hosts en validación y DNS pinning
- **config.php**: Nueva función `resolveHostToIp()` que prueba `gethostbyname()` (IPv4) y fallback a `dns_get_record(DNS_AAAA)` para IPv6.
- **ConfigManager::validateConnectionData()**: Usa `resolveHostToIp()` en vez de `gethostbyname()` directo.
- **TikiWikiClient::initCurlResolve()**: Usa `resolveHostToIp()` en vez de `gethostbyname()` directo.

#### 🔴 Fix #2: Location messages perdidos en exports
- **MessageMapper::fromExport()**: Nueva detección de `message['location']` en exports — ahora las coordenadas GPS se mapean correctamente a `messageType='location'` y `location` field.

#### 🟡 Fix #3: Admin rate limit sin flock (race condition)
- **admin.php**: Las 3 funciones (`checkRateLimit()`, `incrementFailedLogin()`, `resetFailedLogin()`) refactorizadas a patrón `fopen('c+')` + `flock(LOCK_EX)` + `ftruncate()`. Nueva helper `readWriteRateData()` elimina código duplicado.

#### 🟡 Fix #4: Host header injection en generateWebhookUrl
- **admin.php**: `generateWebhookUrl()` ahora sanitiza el hostname (rechaza caracteres inválidos con regex). Si el host difiere de `SERVER_NAME`, loguea advertencia. Fallback seguro a `SERVER_NAME` en caso de host sospechoso.

#### 🟢 Fix #5: Dedup lock files sin GC
- **WebhookHandler::processMessage()`: Los lock files en `TEMP_DIR/dedup_locks/` ahora se eliminan con `@unlink()` después de `fclose()` en ambos puntos de salida (duplicado y post-procesamiento).

#### 🟢 Fix #6: configure_webhook usaba curl directo
- **TelegramClient**: Nuevo método `setWebhook(string $url, string $secretToken)` siguiendo el mismo patrón que `sendMessage()`.
- **admin.php**: El case `configure_webhook` ahora usa `TelegramClient::setWebhook()` en vez de `curl_init()` directo.

#### 🟢 Fix #7: get_connection devuelve tokens completos vía AJAX
- **admin.php**: Endpoint `get_connection` ahora agrega headers `Cache-Control: no-store` y `Pragma: no-cache` para evitar que tokens queden en cachés intermedias. Comentario de seguridad documentando el riesgo.

## v0.6.0

### 🏗️ Desarme de admin.php — Fase B: Handlers externos + fixes post-revision

#### Fase B: handlers POST extraídos a admin_handlers.php
- **admin.php**: Los 12 handlers POST (`change_password`, `save_connection`, `delete_connection`, `duplicate_connection`, `toggle_connection`, `sync_tracker`, `configure_webhook`, `test_connection`, `check_privacy`, `assign_chat`, `ignore_chat`, `create_tracker`) + 1 AJAX (`get_connection`) extraídos a nuevo archivo `admin_handlers.php` (508 líneas).
- admin.php reducido de 2529 → 1114 líneas (-56% del original).
- admin.php ahora es solo: entry point, 8 helpers (CSRF, rate limit, webhook URL, escape), auth, setup de conexiones, include de handlers, loops pesados, construcción de `$connectionsSafe`, determinación de tab, y HTML skeleton.

#### 🏗️ Orden de ejecución optimizado: handlers antes de loops pesados
- `include 'admin_handlers.php'` movido **ANTES** de los loops que llaman APIs externas de Telegram/TikiWiki (auto-fetch bot_name/chat_title/field_prefix + health check).
- Los 3 handlers AJAX (`get_connection`, `test_connection`, `check_privacy`) hacen `exit` inmediato sin tocar APIs externas. Corte de latencia drástico: de segundos a milisegundos.
- Los handlers POST mutantes (`save_connection`, `delete_connection`, etc.) ya no tienen que esperar a que se ejecuten los loops pesados.

#### 🔴 Fix #1: $connectionsSafe construido al final, sincronizado con $connections
- `$connectionsSafe` movido de antes del include (línea 295) a **después de los handlers + heavy loops + health check** (línea 425).
- Eliminadas 3 actualizaciones manuales de `$connectionsSafe` dentro de los loops (bot_name, chat_title, field_prefix) que se desincronizaban si otro handler modificaba `$connections`.
- `$connectionsSafe` ahora siempre refleja el estado final de `$connections`.

#### 🟡 Fix #2: $webhookStatuses sin inicialización redundante
- Eliminado `$webhookStatuses = [];` al inicio del health check que pisaba lo que `configure_webhook` handler hubiera seteado.
- Inicializado una sola vez antes del include. El health check loop sobreescribe slugs con datos frescos de Telegram.

#### 🟡 Fix #3: Vista HTML itera $connectionsSafe (tokens sanitizados)
- Las 3 vistas (clásica, agrupada por bot_token, selects de importación/creación) ahora iteran `$connectionsSafe` en vez de `$connections`.
- En la vista clásica, el fallback de bot_display ya no muestra `substr($token, 0, 20)` — ahora muestra el token sanitizado de `$connectionsSafe` o "Sin token".
- En la vista agrupada, `bot_token` ya no se sanitiza manualmente — se usa el valor ya sanitizado de `$connectionsSafe`.

#### 🟢 Fix #4: validateCSRFToken() con respuesta JSON+403 para AJAX
- `validateCSRFToken()` detecta acciones AJAX (`get_connection`, `test_connection`, `check_privacy`) y responde `HTTP 403` + `Content-Type: application/json` con mensaje estructurado, en vez de `die()` plano que causaba SyntaxError silencioso en el JS.

#### 🟢 Fix #5: Handlers sin escapeHtml() (double escape)
- Los handlers en `admin_handlers.php` ya no aplican `escapeHtml()` sobre `$successMessage`/`$errorMessage`. La vista (admin.php) es la única responsable de escapar al renderizar.

## v0.6.1

### ✨ Nuevo campo MediaGroupId para álbumes de fotos/videos

- **NormalizedMessage.php**: Nueva propiedad `$mediaGroupId` para transporte unificado del identificador de grupo de medios.
- **MessageMapper::fromWebhook()**: Extrae `media_group_id` del update de Telegram (se ejecuta antes de todos los early returns, cubriendo 100% de tipos de mensaje).
- **MessageMapper::fromExport()**: Extrae `grouped_id` del export ZIP de Telegram (Telegram Desktop usa `grouped_id` como equivalente a `media_group_id`).
- **MessageMapper::toWikiFields()**: Mapea a `fields[{prefix}MediaGroupId]`.
- **TikiWikiClient::getTrackerFieldDefinitions()**: Nuevo campo `{prefix}MediaGroupId` tipo `t` (text). Se crea automáticamente vía botón Sync o al crear tracker nuevo.
- **Docs actualizados**: README.md (tabla de campos → 28 campos), TECHNICAL.md (schema + INI), AGENTS.md (tabla de campos).

### 🔄 FileUrl eliminado, FileUniqueId agregado, Fill Empty Fields en import

- **NormalizedMessage.php**: Propiedad `$fileUrl` eliminada. Nueva propiedad `$fileUniqueId` (identificador universal del archivo en Telegram, no expira entre bots).
- **MessageMapper::fromWebhook()**: Extrae `file_unique_id` en los 8 tipos de media (photo, video, audio, document, sticker, voice, video_note, animation).
- **MessageMapper::toWikiFields()**: Eliminado mapeo a `{prefix}FileUrl`. Nuevo mapeo a `{prefix}FileUniqueId`.
- **WebhookHandler::attemptDownloadAndUpload()**: Eliminada asignación `$msg->fileUrl = $fileUrl` (la URL de descarga de Telegram ya no se persiste porque expira en 1h y expone el bot token).
- **TikiWikiClient::getTrackerFieldDefinitions()**: Campo `{prefix}FileUrl` eliminado. Nuevo campo `{prefix}FileUniqueId` tipo `t` (text).
- **MessageMapper**: Nuevo método `getFillEmptyFields(NormalizedMessage, array $existingItem)` que compara campos del import vs existentes y rellena solo si el valor actual está vacío (`''`/`null`/`'0'`). Nunca sobreescribe identity fields (MessageId, ChatId, TopicId, UserId, EditedDate).
- **import.php**: Fill empty fields implementado en `handleProcess()` y `handleFull()`. Cuando un item existe y no requiere update por edit/poll, se verifica si hay campos vacíos para rellenar. Incrementa `$updated` y loggea qué campos se llenaron.
- **Docs actualizados**: README.md, TECHNICAL.md, AGENTS.md, opt/visualizacion-tiki.md, opt/visualizacion-lcc2026.md.

## v0.5.13

### 🔒 Fix: DNS Rebinding en SSRF (hallazgo #2 code review)

- **TikiWikiClient**: Nueva propiedad `$curlResolveEntries` que resuelve el hostname de `$apiUrl` a IP durante la construcción (`initCurlResolve()`), y fuerza a cURL a conectarse siempre a esa IP validada mediante `CURLOPT_RESOLVE`. Esto previene ataques de DNS rebinding: si el DNS cambia entre la validación en ConfigManager y el request real de cURL, la conexión sigue yendo a la IP originalmente validada.
- **TikiWikiClient**: Nuevo método `createCurlHandle()` reemplaza a `curl_init()` en toda la clase. Configura automáticamente `CURLOPT_RESOLVE` y `CURLOPT_SSL_VERIFYPEER`/`CURLOPT_SSL_VERIFYHOST`, garantizando que **todos** los 23 calls curl tengan protección SSRF y SSL activa.
- **ConfigManager::validateConnectionData()**: Refuerzo en la validación anti-SSRF — ahora rechaza hostnames que **no se puedan resolver** (antes pasaban silenciosamente), y el mensaje de error para IPs privadas incluye la IP detectada.

### 🔒 Fix: Host header poisoning (hallazgo #6 code review)

- **TikiWikiClient (implícito)**: `CURLOPT_RESOLVE` previene Host header poisoning porque cURL siempre envía el Host header derivado del hostname original de la URL, no del IP al que se conecta. Al pinar la IP resuelta, no hay posibilidad de que un atacante controle el servidor de destino mediante DNS rebinding y reciba requests con Host header válido para explotar virtual hosting.
- Verificación: todas las llamadas curl en `TelegramClient` y `admin.php` se conectan a `api.telegram.org` (hardcoded, confiable), no requieren protección.

### Fix: Archivos parciales en cola async (hallazgo #4)

- **api.php (escritura de buffer)**: Cambiado de `file_put_contents($bufferFile, ...)` directo a escritura en `$bufferFile.tmp` + `rename()` atómico. Si el proceso crashea a la mitad de la escritura, queda un `.tmp` invisible para el worker en vez de un `.json` truncado.
- **worker.php (reader)**: Eliminado el lock separado (`event_*.json.lock` con `fopen('x')`) que dejaba locks huérfanos si el worker crasheaba — el evento quedaba bloqueado para siempre. Reemplazado por `flock(LOCK_EX | LOCK_NB)` directo sobre el archivo `.json`. Si el worker muere, el SO libera el lock automáticamente y otro worker puede retomar el evento.
- **worker.php (cleanup)**: `cleanupDoneFiles()` extendida para barrer también `.failed*` (errores), `.lock` (legacy) y `.tmp` (partial writes) con más de 1 hora de antigüedad.

### Docs sincronizados

- **AGENTS.md**: "Qué funciona" actualizado con SSRF reforzado, CURLOPT_RESOLVE y fix de cola async.
- **roadmap.md**: Items #11 (DNS rebinding), #6 (Host header), y #4 (partial async) movidos a "funciona sólido".
- **config.php**: TRACKERGRAM_VERSION → v0.5.13.

## v0.5.12

### Fix: edited_message ahora se rutea por webhook (code review #3)

- **api.php**: Extrae `chat_id` de `edited_message` y `edited_channel_post` (además de `message`, `message_reaction`, etc.).
- **WebhookHandler::processUpdate()**: Deriva `edited_message` y `edited_channel_post` a nuevo método `processEditedMessage()`.
- **WebhookHandler::processEditedMessage()**: Busca item existente por `(chat_id, message_id)` vía `findItemByMessageId()`. Si existe → aplica `toWikiFieldsEdit()` + `updateTrackerItem()` (solo Text+EditedDate+Reactions). Si no existe (edición anterior a trackerGram) → `processMessage()` como nuevo.
- Fix de **bug funcional**: los docs prometían la feature pero el ruteo nunca la invocaba. Los edits se perdían silenciosamente.

### Fix: Token de bot en POST body en vez de URL GET (code review #7)

- **admin.php (configure_webhook)**: Cambiado de `CURLOPT_URL` con query string a `CURLOPT_POST` + `CURLOPT_POSTFIELDS` con `http_build_query()`. El token ya no viaja en la URL, evitando que quede en logs de proxy, access_log, etc.

### Fix: innerHTML reemplazado por safeRender() en admin.php (code review #5)

- **admin.php**: Nueva función JS `safeRender(el, lines)` que renderiza texto seguro con `textContent` + nodos `<br>`, sin exponerse a XSS.
- Las 4 funciones que renderizaban datos de APIs externas (Telegram) y 3 adicionales ahora usan `safeRender()` en vez de `innerHTML = lines.join('<br>')`.

### Fix: htmlspecialchars eliminado de fromWebhook() (code review #8)

- **MessageMapper::fromWebhook()**: Eliminados 5 `htmlspecialchars()` en captions de photo/video, audio title, document filename, y new_chat_title.
- **WebhookHandler::propagateMediaGroupCaption()**: Eliminado `htmlspecialchars()` en caption propagada entre fotos del mismo álbum.
- Esto es distinto al fix de v0.5.8 (que fue en `toWikiFields()`). Este es en `fromWebhook()`, antes de que los datos entren al modelo. Sin esto, captions con comillas llegaban a TikiWiki como `&quot;` literal.

### Limpieza: tmp/test_create.php eliminado (code review #1)

- **tmp/test_create.php**: Borrado. Contenía token TikiWiki hardcodeado (`1970212e...5a57a1`).
- **.gitignore**: `tmp/` agregado para evitar que archivos temporales se versionen accidentalmente.

### Fix: Race condition en rate limiting con flock(LOCK_EX)

- **api.php (rate limiting)**: Reemplazado `file_get_contents()` + `file_put_contents()` por `fopen('c+')` + `flock(LOCK_EX)` + `ftruncate()`. Dos webhooks concurrentes ya no pueden eludir el límite leyendo el archivo antes de que el otro lo actualice.
- **api.php**: Agregado GC probabilístico (1% por request) que limpia archivos `tmp/tg_rate_*` con más de 1 hora sin actividad.

### Fix: Detección de migración grupo→supergrupo

- **api.php**: Nueva detección de `migrate_to_chat_id` en updates de Telegram. Cuando un grupo básico migra a supergrupo, actualiza automáticamente el `chat_id` de todas las conexiones que matchean.
- **api.php**: Soporte para `migrate_from_chat_id` (post-migración): mensajes en el nuevo supergrupo que referencian el grupo viejo.
- **api.php (detección pasiva)**: Auto-asignación inteligente: si la conexión ya tiene un `chat_id` asignado y el incoming chat es un supergrupo (-100...), lo trata como migración post-facto y actualiza automáticamente sin requerir intervención del admin.
- **ConfigManager**: `updateConnectionFields()` ya existente se reutiliza para persistir el cambio.

### Fix: ConfigManager::load() con flock(LOCK_SH) (Fase 2 #2)

- **ConfigManager::load()**: Reemplazado `file_get_contents()` sin lock por `fopen('r')` + `flock(LOCK_SH)` + `stream_get_contents()`. Previene leer JSON truncado si otro proceso escribe `setup.json` concurrentemente.

### Fix: messageExists() retorna null en error (hallazgo #11 code review)

- **TikiWikiClient::messageExists()**: Cambiado tipo de retorno de `int` a `?int`. Ahora retorna `null` si la API de TikiWiki no responde (timeout/5xx), en vez de `0` que se interpretaba como "mensaje no existe" y generaba duplicados transitorios.
- **WebhookHandler::processMessage()**: Actualizado para manejar `null` de messageExists(). Si hay error de conexión, logea warning pero procede con creación (fail open — mejor un duplicado raro que perder un mensaje).

### Fix: Fan-out responde 502 si todas las conexiones fallan (hallazgo #10)

- **api.php (fan-out)**: Ahora verifica si TODAS las conexiones fallaron en el fan-out. Si ninguna respondió `ok`, responde HTTP 502 para que Telegram reintente el webhook. Si al menos una funcionó, responde 200 con detalles de error por conexión.

### Fix: TOCTOU en dedup con lock por message_id (Fase 2 #3)

- **WebhookHandler::processMessage()**: Nuevo lock exclusivo por `(chatId:messageId)` en `TEMP_DIR/dedup_locks/`. Se adquiere ANTES del `messageExists()` inicial y se libera después de `sendToTikiWikiWithRetries()`. Elimina la ventana entre verificar y crear donde otro webhook concurrente podía insertar el mismo mensaje.
- Diferentes mensajes no compiten (distinto lock file). El lock es blocking — el segundo webhook para el mismo mensaje espera a que el primero termine.

### Docs sincronizados

- **design/004-trabajo-sobre-existentes.md**: Actualizado — edited_message ya no es ignorado.
- **roadmap.md**: Items de rate limiting (Fase 1 #2) y GC rate files (Fase 2 #6) movidos a "funciona sólido". Item de migración grupo→supergrupo (Fase 1 #1) también. Cloudflare removido (no es código del proyecto). Items de Fase 2 reordenados.
- **AGENTS.md**: "Qué funciona" actualizado con edited_message routing, safeRender, configure_webhook POST, rate limiting con flock, migración auto-detectada.
- **config.php**: TRACKERGRAM_VERSION → v0.5.12.

## v0.5.11

### Dedup con edit detection + polls enriquecidos desde export

- **TikiWikiClient**: Nuevo método `updateTrackerItem()` para reflejar edits de Telegram vía `POST /api/trackers/{id}/items/{itemId}`.
- **TikiWikiClient**: `checkPermissions()` ahora testea `modify_tracker_items` (POST a item inexistente).
- **MessageMapper**: Nuevo método `toWikiFieldsEdit()` que genera SOLO campos editables (Text + EditedDate + Reactions). Nunca incluye Media/MessageType/Location para evitar pérdida por exports parciales.
- **MessageMapper::fromExport()**: Polls y quizzes ahora parsean `answers[]` con `voters` reales del export ZIP. Genera texto enriquecido tipo `📊 Pregunta\n• Opción A: 5 votos\nTotal: 8 votos`. Soporta `answers` (schema oficial) y `options` (fallback legacy).
- **MessageMapper::isMediaExcluded()**: Detecta placeholder `"(File not included..."` de Telegram en exports sin media. Cuando se detecta, el mensaje se trata como texto en vez de media faltante.
- **import.php (ambos flujos)**: Dedup pre-create con `findItemByMessageId()`. Si el mensaje ya existe:
  - Si `editedDate` difiere del almacenado → actualiza con `toWikiFieldsEdit()`
  - Si es poll/quiz con stored `MessageType === 'other'` y texto contiene "no capturada en tiempo real" → enriquece con datos del export
  - Si no hay cambios → skipped
- **import.php**: Field access corrige ambos formatos de API de TikiWiki (`field_{prefix}Name` y `fields[{prefix}Name]`).
- **import.php**: Nuevos contadores `$updated` y `$failed` en respuestas JSON.
- **WebhookHandler**: Ayuda `/ayuda` actualizada con links a documentación de sintaxis wiki y freetags.
- **WebhookHandler**: XSS fix en comando desconocido default con `htmlspecialchars()`.
- **config.php**: `TRACKERGRAM_VERSION` → v0.5.11.

## v0.5.10

### Fix: error histórico de webhook mal clasificado como activo

- **admin.php (3 lugares)**: La detección de `errorStale` ahora considera `pending_update_count === 0` como evidencia de que el webhook se recuperó, aunque Telegram no haya actualizado `last_successful_synchronization`. Cuando no hay updates pendientes, el error se muestra como "histórico" en vez de activo.
- **Fix conexo**: Misma lógica aplicada al refrescar cards de conexión post-configuración de webhook.

### Docs: IPs de Telegram corregidas

- **INSTALL.md, README.md**: Actualizadas de 2 a 9 subredes IPv4 según fuente oficial (https://core.telegram.org/resources/cidr.txt).

### Docs: Cloudflare reverse proxy para shared hosting

- **roadmap.md**: Nuevo item #3 (Fase 2: Robustez).
- **opt/shared_hosting.md**: Guía completa de Cloudflare CNAME Setup y Full Zone para evitar firewall de hosting que bloquea Telegram.

### Templates tplwiki: reply link con preg_match

- **opt/visualizacion-tiki.md, opt/visualizacion-lcc2026.md**: Reemplazado `regex_replace` por `preg_match` (modifier nativo de TikiWiki) para extraer itemId y generar link clickeable al mensaje respondido.

## v0.5.9

### Feature: Hashtags como etiquetas (Freetags) en TikiWiki

- **NormalizedMessage**: Nuevo campo `$hashtags` para transportar tags extraídos del mensaje.
- **MessageMapper::extractHashtags()**: Nuevo método privado que extrae hashtags de `entities[].type=hashtag` (webhook: texto y captions) y del formato array del export (texto + `photo_caption`/`file_caption`/`caption`).
- **MessageMapper::toWikiFields()**: Mapea `$msg->hashtags` al campo `{prefix}Hashtags` tipo `F` (Freetags).
- **TikiWikiClient::getTrackerFieldDefinitions()**: Agregado `{prefix}Hashtags` tipo `F` como FIELD26. Nuevos trackers lo crean automáticamente.
- **Prefix detection**: `Hashtags` agregado a `knownSuffixes` para auto-detección de field prefix.
- **Sync**: `synchronizeTrackerFields()` crea el campo `Hashtags` en trackers existentes al hacer click en "Sync Fields" desde el admin.
- **Store**: Tags se guardan espacio-separados, sin `#`, según formato estándar de TikiWiki Freetags. Se conectan automáticamente al ecosistema de etiquetas (tag cloud, búsqueda, nube).

## v0.5.8

### Fix: BUG-001 — findByWebhookSecret() devolvía primera conexión en vez de la pendiente

- **ConfigManager::findByWebhookSecret()**: Cuando múltiples conexiones compartían el mismo `webhook_secret` (mismo bot en varios grupos), la función devolvía la primera conexión en orden de inserción. Si llegaba un mensaje de un grupo nuevo, la detección se asociaba al slug equivocado.
- **Fix**: Ahora recolecta TODOS los matches y los ordena: conexiones pendientes (`chat_id=0`) primero, luego por `created_at` descendente. Así la detección siempre se asigna a la conexión correcta.

### Fix: BUG-001 — assignDetection() sobrescribía chat_id existente

- **detect_helper.php::assignDetection()**: Si el admin clickeaba "Asignar" en una detección, la función pisaba el `chat_id` de la conexión aunque ya tuviera uno configurado. En combinación con el bug anterior, esto podía corromper la conexión de otro grupo.
- **Fix**: Ahora valida que la conexión tenga `chat_id=0` antes de asignar. Si ya tiene un chat asignado, devuelve error: "Creá una nueva conexión para este chat en vez de reasignar."

### Fix: field prefix incorrecto al sincronizar tracker

- **admin.php (sync_tracker)**: Cuando se ejecutaba la acción "Sincronizar Tracker", si el `field_prefix` almacenado en la conexión no estaba vacío (ej: `'telegrammessage'` default), se saltaba la auto-detección y usaba ese prefix directamente. Si el tracker real tenía un prefix diferente (ej: `lcc2026t`), generaba campos duplicados con el prefix incorrecto.
- **Fix**: Ahora siempre llama a `resolveFieldPrefix()` cuando el prefix almacenado es `'telegrammessage'` (default) o está vacío, igual que hace `import.php` y la carga de página del admin. Si detecta un prefix diferente, lo persiste en la conexión antes de sincronizar.

### Docs: Requisito de bot admin documentado

- Agregado requisito explícito en README.md > "Qué Necesitás Antes de Empezar": el bot debe ser administrador del grupo para recibir todos los mensajes (Privacy Mode de Telegram).
- Agregado en README.md > "Problemas Comunes": diagnóstico rápido cuando solo llegan mensajes de sistema pero no los de texto.
- Agregado paso explícito en INSTALL.md > Paso 5: "Hacé admin al bot" con instrucciones.
- Agregado en tabla de troubleshooting de INSTALL.md.

### Lección aprendida: Privacy Mode de Telegram

Los bots de Telegram tienen **Privacy Mode** habilitado por defecto. Esto significa que solo reciben:
- Mensajes de sistema (service messages) → **siempre**, incluso sin ser admin
- Comandos (`/comando`), menciones (`@bot`), replies al bot
- **NO reciben** mensajes de texto comunes de usuarios

Para que un bot reciba TODOS los mensajes de un grupo, debe ser **administrador** del grupo (o deshabilitar privacy mode en BotFather con `/setprivacy` y re-agregar el bot).

Documentación oficial: https://core.telegram.org/bots/features#privacy-mode

### Fix: htmlspecialchars() en toWikiFields corrompía caracteres especiales (&quot;)

- **MessageMapper::toWikiFields()**: Todos los campos de texto (Text, ChatTitle, TopicTitle, Username, FirstName, LastName, DisplayName, MediaCaption, Reactions) se pasaban por `htmlspecialchars($valor, ENT_QUOTES)` antes de enviarlos a la API de TikiWiki.
- **Problema**: Las comillas dobles `"` se convertían a `&quot;`, comillas simples `'` a `&#039;`, etc. Como el envío es via `http_build_query()` (form-urlencoded), TikiWiki recibe el valor URL-decodeado y guarda literalmente `&quot;` en lugar de `"`.
- **Fix**: Eliminados todos los `htmlspecialchars()` de `toWikiFields()`. El escape HTML es responsabilidad de la capa de vista (TikiWiki/Smarty al mostrar), no de la capa de datos.
- **Nota**: Los items ya existentes con `&quot;` quedan corruptos en la base de datos. Solo se puede corregir re-importando o con un UPDATE SQL directo.

### Docs: Reporte de arquitectura y escalabilidad

- `reports/architecture_scalability_2026-06.md`: nuevo reporte con 20 hallazgos clasificados P0-P3, 4 escenarios de escalabilidad, y tabla de recomendaciones por impacto/esfuerzo.
- `AGENTS.md`: agregado el "qué-no-hacer" sobre `htmlspecialchars()` en `toWikiFields()`.

## v0.5.7

### Feature: ReplyToId incluye texto del mensaje original (Opción B)

- **Webhook**: `MessageMapper::fromWebhook()` ahora extrae `reply_to_message.text ?? reply_to_message.caption` (viene gratis en el update de Telegram) y lo almacena como `replyToText` en `NormalizedMessage`.
- **WebhookHandler**: Al resolver un reply (buscar itemId en el tracker), concatena el texto: `#42 - "texto del mensaje"`. Si no está resuelto pero hay texto, guarda `"texto del mensaje"` (sin referencia).
- **Import**: Al resolver un reply, usa `TikiWikiClient::getTrackerItem()` para obtener el texto del mensaje original desde el tracker y lo concatena igual que en webhook.
- **TikiWikiClient**: Nuevo método `getTrackerItem(int $trackerId, int $itemId): ?array` para obtener un item completo del tracker con todos sus fields.
- **NormalizedMessage**: Nueva propiedad transiente `$replyToText` para transportar el texto del reply durante el procesamiento.
- **Template**: La plantilla Smarty muestra el campo completo (`↪️ Respuesta a: #42 - "texto..."`) en lugar de solo el ID.
- **Roadmap**: La Opción A (campo separado `ReplyToText`) queda documentada como prioridad media para quien prefiera datos más limpios.

## v0.5.6

### Fix: Chat_id sin prefijo `-100` en import de supergrupos

- **import.php**: El `id` raíz del export JSON de Telegram Desktop omite el prefijo `-100` para supergrupos (ej: `4299700952` en vez de `-1004299700952`). El webhook no tiene este problema porque recibe el chat_id directo de la Bot API.
- **Fix**: En `handleExtract()` y `handleFull()`, detectar `$data['type'] === 'private_supergroup'` o `'private_channel'` y anteponer `-100` al `id` raíz.
- **Nota**: Solución parcial. Si el export incluye una migración grupo→supergrupo, los mensajes pre-migración tienen IDs negativos con un chat_id diferente. Ver roadmap item #6.

### Fix: Auto-detección de field_prefix NO cacheada generaba llamadas API en cada page load

- **admin.php**: La auto-detección de field_prefix se ejecutaba en CADA carga de página para cada conexión con prefix default `'telegrammessage'`. Si el prefix detectado también era `'telegrammessage'` (el caso más común), no se persistía nada, y la siguiente carga de página volvía a ejecutar la detección. Para 2 conexiones apuntando a 2 TikiWikis diferentes, eso significaba **2 llamadas API por cada refresh del admin**, lo que ralentizaba la interfaz y ocupaba procesos de Apache innecesariamente.
- **Fix**: Agregado flag `field_prefix_checked` por conexión. La detección ahora se ejecuta UNA SOLA VEZ (cuando el flag no existe). Tras la detección (incluso si falla o si el prefix es `telegrammessage`), se persiste `field_prefix_checked: true` y no se vuelve a ejecutar. En pruebas de estrés, las 8 recargas del admin en 1 minuto pasaron de 16 llamadas API a 2 (o 0 si ya estaban cacheadas).
- **Mismo fix aplicado en `api.php` y `worker.php`**: ambos verifican `field_prefix_checked` antes de llamar a `resolveFieldPrefix()`, evitando llamadas API innecesarias en cada webhook entrante.

### Fix: Fan-out sin try-catch causaba 500 si una conexión fallaba

- **api.php**: El loop de fan-out (`foreach $allFound as $found`) llamaba a `processUpdate()` sin try-catch. Si la segunda conexión (apuntando a un TikiWiki diferente) fallaba por timeout, error de autenticación o cualquier excepción, **todo el request devolvía 500**, aunque la primera conexión hubiera procesado el mensaje exitosamente.
- **Fix**: Cada iteración del fan-out ahora envuelve `processUpdate()` en un `try { ... } catch (Throwable $e) { ... }`. La respuesta siempre es 200 con resultados individuales por conexión. Los errores se loggean internamente.

### Fix: Error histórico de webhook (stale) se mostraba como error actual

- **admin.php**: Los 4 lugares que mostraban `last_error_message` de `getWebhookInfo()` no verificaban si el error era antiguo. Telegram nunca borra `last_error_message` — solo lo actualiza cuando ocurre un NUEVO error. Si el webhook se recupera, el error viejo sigue apareciendo.
- **Fix**: Comparar `last_successful_synchronization` vs `last_error_date`. Si hubo una sincronización exitosa DESPUÉS del último error, se muestra como "error histórico (recuperado)" en vez de error activo. Aplica a: cards de conexión (PHP), post-configuración de webhook (PHP), test de conexión (JS), test del bot (JS).

### Fix: Foto perdida en álbum (media group) por falta de reintentos

- **WebhookHandler::downloadAndUploadMedia()**: No tenía reintentos. Cuando 3 fotos de un álbum llegan como webhooks casi simultáneos, cada una se procesa en un proceso de Apache independiente. Si una falla transitoriamente (timeout, error de red, contención con TikiWiki), el mensaje se guardaba SIN la foto pero CON el texto.
- **Fix**: `downloadAndUploadMedia()` ahora es un wrapper con reintentos (hasta 3 intentos con backoff progresivo 500ms + 1000ms). El intento único se movió a `attemptDownloadAndUpload()`. Si los 3 intentos fallan, recién ahí se guarda sin foto.
- **Test case real**: Álbum de 3 fotos con caption en la primera. La foto del medio (photo_195) se perdió. Con reintentos, el error hubiera sido transitorio y se hubiera recuperado.

### Fix: Race condition en cache de captions de álbumes

- **media_group_captions.json**: `loadMediaGroupCaptions()` leía el archivo con `file_get_contents()` SIN lock. `saveMediaGroupCaptions()` escribía con `LOCK_EX`. En concurrencia (3 webhooks simultáneos), un proceso podía leer datos vacíos/parciales mientras otro escribía.
- **Fix**: `loadMediaGroupCaptions()` ahora usa `fopen()` + `flock(LOCK_SH)` para lectura, bloqueando si otro proceso está escribiendo. Esto garantiza que la lectura siempre vea el último estado consistente.

### Fix: worker.php persistía field_prefix incluso cuando no cambiaba

- **worker.php**: El bloque de auto-detección de field_prefix usaba `updateConnectionFields()` SOLO si el prefix detectado era diferente al actual, pero no respetaba el nuevo flag `field_prefix_checked`.
- **Fix**: Alineado con el mismo patrón de api.php: verifica `field_prefix_checked` primero, y siempre persiste tanto el prefix como el flag (si corresponde).

---

## v0.5.5

### Fix: checkPermissions sin crear galerías en TikiWiki

- `checkPermissions()` paso 6 (test de `admin_file_galleries`): antes creaba una galería temporal (`POST /api/galleries` con `create=1`) y luego intentaba borrarla con `deleteGalleryQuiet()` que tenía la ruta incorrecta (falta `/delete` en la URL). Ahora usa `DELETE /api/galleries/99999999/delete` contra una galería inexistente — HTTP 200 = tiene permiso, HTTP 403 = no tiene permiso. Cero efectos secundarios.
- `deleteGalleryQuiet()` eliminado (ya no se necesita).

### Fix: Health check webhook mostraba token incorrecto

- `admin.php`: el health check (`$webhookStatuses`) reusaba el mismo `TelegramClient` para todas las conexiones por usar `??=` en vez de `=`. El primer loop (auto-poblar `bot_name`/`chat_title`) dejaba `$tgClient` con el token de la última conexión. El health check usaba ese mismo cliente para TODAS las conexiones, mostrando información del webhook equivocada en conexiones 2+.
- Mismo bug en el auto-poblado de `chat_title`: si una conexión tenía `bot_name` pero no `chat_title`, reusaba el cliente de una iteración anterior.
- Fix: `??=` reemplazado por `=` en ambos loops. Cada conexión ahora crea su propio `TelegramClient` con su token.

### Fix: Updates (getUpdates) daba error 409 siempre

- `TelegramClient::getUpdates()`: cuando hay webhook activo, Telegram devuelve HTTP 409 con descripción `"Conflict: can't use getUpdates method while webhook is active"`. El código ignoraba el body y devolvía "HTTP 409" genérico.
- Ahora parsea el `description` del body de Telegram para mensajes más claros.
- `check_privacy` en `admin.php`: detecta "webhook is active" y devuelve `webhook_active: true` con info útil via `getWebhookInfo()` (URL configurada, updates pendientes). El JS lo muestra como estado informativo en verde, no como error.

### Fix: Tilde verde no se actualizaba tras configurar webhook

- `$webhookStatuses` se computaba ANTES del bloque POST. Tras configurar webhook exitosamente, la página seguía mostrando el estado viejo (❌ No configurado).
- Ahora, tras un `configure_webhook` exitoso, refresca `$webhookStatuses[$slug]` via `getWebhookInfo()` para que el tilde verde se refleje inmediatamente.

### Docs: Reporte de arquitectura y escalabilidad + roadmap SQLite

- `reports/architecture_scalability_2026-06.md`: nuevo reporte con 20 hallazgos clasificados P0-P3, 4 escenarios de escalabilidad, y tabla de recomendaciones por impacto/esfuerzo.
- `roadmap.md`: item 14 refinado ("SQLite para cola async y rate limiting") como opcional/mínima prioridad/evaluación. Actualizada versión a v0.5.5. Aclarada exclusión de MySQL/PostgreSQL con servidor.

---

### Fix: webhook_secret compartido entre conexiones con mismo bot_token

- Un bot de Telegram tiene **un solo webhook** con **un solo secret_token**. Si dos conexiones usan el mismo `bot_token` pero distinto `webhook_secret`, al configurar el webhook para una se pisa la otra.
- `ConfigManager::saveConnection()`: al auto-generar secret, primero busca si otra conexión ya tiene el mismo `bot_token` y **reusa su secret**. En edición, si el campo viene vacío mantiene el actual en vez de generar uno nuevo.
- `ConfigManager::load()` (migración): mismo criterio al auto-generar secrets para conexiones existentes sin `webhook_secret`.

### Docs: Permisos de TikiWiki para trackerGram

- **README.md**: Nueva sección "Configurar Permisos en TikiWiki" con guía paso a paso para crear el grupo `trackerGram`, asignar los 6 permisos necesarios (con la advertencia de que `tiki_p_admin_trackers` debe ser global), crear el usuario y generar el token de API.
- **INSTALL.md**: Reemplazada la nota genérica de "permisos de administrador" por instrucciones específicas de grupo/usuario/permisos/token (mismos 6 permisos verificados contra el código fuente de TikiWiki 27.5).
- **Hallazgo crítico**: La deduplicación (`action_list_items`) requiere `tiki_p_admin_trackers` a nivel **global** (`Perms::get()` sin contexto de objeto en `Tracker/Controller.php:700`). Sin esto, `messageExists()` responde 403 y los mensajes se duplican.

### Infra: Reubicación del código fuente de TikiWiki

- Movido el source de TikiWiki 27.5 fuera del workspace a `C:\Users\Federico\Documents\OpenCode\TikiWiki\tiki-27.5\`
- Actualizados permisos de los agentes `tiki-expert` y `telegram-tiki-importer` con `read/glob/grep` sobre la nueva ruta

### Feat: Health check visible en cards de conexión

- `admin.php` ahora fetchea el estado del webhook (`getWebhookInfo()`) al cargar la página y lo muestra en cada tarjeta de conexión: ✅ configurado, ❌ no configurado, ⚠️ con errores.
- Sin necesidad de apretar "Test" — se ve de un vistazo al abrir el admin.

### Feat: Upload por base64

- `TikiWikiClient::uploadFileBase64()` — nuevo método que sube archivos codificando el contenido en base64 en vez de usar `curl_file_create()` (multipart). Alternativa cuando multipart no funciona.
- Sigue el formato que acepta la API de TikiWiki (`data` + `name` + `size` + `type`).

### Feat: Verificación post-creación de FG field

- `updateFgFieldOptions()` ahora verifica con `GET /fields` que el galleryId se haya guardado realmente (workaround del bug de TikiWiki que responde HTTP 200 aunque falle).
- `createTracker()` loggea warning si la galería no pudo asignarse.

### Feat: getWebhookInfo() en TelegramClient

- Nuevo método que consulta `getWebhookInfo` de la Telegram Bot API. Devuelve URL configurada, updates pendientes, último error, etc.
- Usado por el health check del admin y el botón "Test".

## v0.5.4

### Fix: Field prefix preservado al editar conexión desde modal Webhook
- `ConfigManager::saveConnection()` — al editar, si `field_prefix` no viene en el POST (como ocurre en el modal de edición de la pestaña Webhook), preserva el valor existente en vez de resetearlo a `telegrammessage`.
- Root cause: el modal de edición de conexiones no incluye el campo `field_prefix`, por lo que no se enviaba en el POST. `saveConnection()` asumía conexión nueva y ponía default.

### Feat: Auto-detección de field prefix desde el tracker
- `TikiWikiClient::resolveFieldPrefix(int $trackerId)` — detecta automáticamente el field prefix real del tracker consultando sus campos vía `GET /api/trackers/{id}/fields`. Busca permNames que terminen en sufijos conocidos (TelegramMessageId, ChatId, Text, MessageDate, Media) y extrae el prefijo común.
- **Scope**: No solo corrige el prefix mal guardado, sino que se adapta a CUALQUIER prefix que tenga el tracker (creado manualmente, con otro nombre, etc.).
- `getMediaGalleryId()` y `messageExists()` ahora usan `resolveFieldPrefix()` para obtener el prefix correcto sin asumir que el almacenado en `setup.json` es el real.
- `loadTrackerFields(int $trackerId)` — método compartido que fetchea fields del tracker una sola vez, evitando doble API call entre la detección de prefix y la búsqueda del gallery ID.
- **Persistencia**: Cuando el prefix detectado difiere del almacenado, se guarda automáticamente en `setup.json` vía `ConfigManager::updateConnectionFields()`.
- **Afecta**: api.php (webhook sync), worker.php (async), import.php (handleExtract, handleProcess, handleFull).
- `MessageMapper::getFieldPrefix()` — nuevo getter para sincronizar el prefix detectado.
- **Sin regresión**: Si el prefix almacenado NO es el default `telegrammessage`, se confía en él (no se fetchea el tracker). La auto-detección solo se activa para conexiones con prefix default o vacío.

## v0.5.3

### Fixes críticos (API TikiWiki Content-Type + FG field options)
- **Fix**: `createTrackerShell()`, `createTrackerField()`, `createGallery()` — la API REST de TikiWiki NO mergea JSON body a `$_POST`. Cambiado `Content-Type: application/json` → `application/x-www-form-urlencoded` en todos los POST que crean recursos. Los errores HTTP 409 silenciosos desaparecieron.
- **Fix**: `checkPermissions()` — usaba `DELETE /api/galleries/999999/delete` para probar upload permissions, pero ese endpoint devuelve HTTP 200 aunque el recurso no exista (no 404). Ahora usa `GET /api/galleries` (HTTP 200 = OK).
- **Fix**: `updateFgFieldOptions()` — no guardaba `galleryId` en el campo FG porque `action_edit_field` salta el bloque de guardado si no recibe `name` en el POST. Agregado `name` (fieldName) + `type` (`FG`) al POST body. La respuesta HTTP 200 es engañosa (TikiWiki bug: muestra options viejas), verificar con `GET /api/trackers/{id}/fields`.
  - Root cause trazada hasta el código fuente de TikiWiki: `Controller.php` valida `$input->name->text()` antes de procesar options.

### Feat: Gallery ID opcional en Crear Tracker
- `createTracker()` acepta `?int $galleryId`. Si se pasa, usa esa galería sin intentar crear una nueva.
- `admin.php` formulario "Crear Tracker" tiene campo "Gallery ID (opcional)".
- Si no se pasa gallery ID, el flujo auto-crea una galería y la asigna (comportamiento anterior).

### Feat: Fan-out en webhook
- `ConfigManager::findAllByChatId()` devuelve TODAS las conexiones que matchean `(chat_id, webhook_secret)`.
- `api.php` itera sobre todas las coincidencias y procesa el update para cada una.
- Duplicar una conexión con diferente tracker_id ahora permite enviar el mismo mensaje a múltiples trackers.

### Feat: Auto-población de bot_name y chat_title
- `admin.php` fetchea `bot_name` vía `getMe()` y `chat_title` vía `getChat()` de la Telegram Bot API al cargar el panel.
- Los nombres se persisten en `setup.json` y se muestran en las tarjetas de conexión.

### Security: Eliminar auto-fill de tokens en admin
- Eliminadas `fillImportFromConnection()` y `fillCreateFromConnection()` que exponían tokens TikiWiki via AJAX y data-attributes en HTML.
- Los selectores de conexión ahora son solo informativos (sin auto-fill de URLs/tokens).

### Chore
- `index.php` redirige a `admin.php` para simplificar acceso.
- Documentación sincronizada: AGENTS.md, CAMBIOS.md, roadmap.md.

## v0.5.2
- **Feat**: Accesibilidad completa del panel admin (`admin.php`).
  - **ARIA**: `role="alert"`, `role="dialog"`, `role="progressbar"`, `role="main"`, `role="button"`, `aria-modal`, `aria-live`, `aria-atomic`, `aria-describedby`, `aria-labelledby`, `aria-required`, `aria-expanded`, `aria-controls`, `aria-hidden`, `aria-current="page"`, `aria-label`.
  - **Tooltips**: Todos los botones y campos tienen `title` descriptivo (hover).
  - **Landmarks**: Skip link al contenido principal (`#main-content`), navbar con `aria-label`, tabs con `aria-label`, región login con `aria-labelledby`.
  - **Formularios**: Todos los inputs tienen `label` con `for`, campos requeridos con `aria-required="true"`, hints vinculados con `aria-describedby`.
  - **Modal**: `role="dialog"`, `aria-modal="true"`, focus trap con Tab+Shift+Tab, cierre con Escape, `aria-hidden` toggle.
  - **Teclado**: Sección colapsable "Configuración global" operativa con Enter/Espacio y `aria-expanded`.
  - **Barra de progreso**: `role="progressbar"`, `aria-valuenow`, `aria-valuemin`, `aria-valuemax`, `aria-valuetext`.
  - **Focus visible**: `:focus-visible` para keyboard-only, outline 2px en todos los elementos interactivos, sin outline en click.
  - **Contraste**: Alertas con borde 2px de mayor contraste, colores de texto mejorados.
  - **Reduced motion**: `@media (prefers-reduced-motion: reduce)` elimina transiciones/animaciones.
  - **Fix**: Eliminada duplicación de campos en formulario "Crear Tracker" (gallery_id ahora insertado correctamente entre preview y botón submit).
  - **Fix**: Event listener de `field_prefix` ahora usa `getElementById` en vez de selector de atributo frágil.
- **Chore**: `togglePassword()` ahora actualiza `aria-label` dinámicamente.

## v0.5.1
- **Fix**: `TikiWikiClient::createTracker()` — la API REST de TikiWiki NO soporta fields inline al crear un tracker.
  - Ahora se crea primero el tracker shell (POST `/api/trackers` solo con name + description).
  - Luego se crea cada field individualmente vía `POST /api/trackers/{id}/fields`.
  - Se agregaron métodos helper `createTrackerShell()` y `createTrackerField()`.
  - **Fail-fast**: si un field falla, se aborta toda la creación (tracker parcial es peor que nada).
- **Fix**: Código huérfano duplicado de `createTracker()` eliminado (causaba parse error).
- **Fix**: `createGallery()` ahora parsea correctamente la respuesta (`data.info.galleryId` estaba anidado).
  - Fallback `parentId=0` → `parentId=1` si el primero da 403 (root gallery).
  - Mensaje de error más claro: indica que el token necesita permiso `admin_file_galleries`.
- **Fix**: `createTrackerShell()` envía `confirm=1` explícitamente (requerido por `action_replace`).
  - Fallback `trackerId` antes que `id` en el response parsing (el controlador devuelve `trackerId`).
  - Loggeo extra si la respuesta no contiene `trackerId` (para diagnosticar problemas de ruteo).
- **Fix**: `createTrackerField()` ya incluía `Content-Type: application/json` correctamente (confirmado por revisión de código).

## v0.5.0
- **Feat**: Nueva pestaña "Crear Tracker" en el panel admin.
  - Crea tracker completo en TikiWiki con nombre, descripción y field prefix personalizable.
  - Galería de medios y campo FG creados automáticamente.
  - Vista previa en vivo de los campos generados.
  - Auto-asignación a conexión existente (actualiza tracker_id + field_prefix).
- **Feat**: Field prefix personalizable (≤16 caracteres, `[a-z][a-z0-9]*`).
  - `TikiWikiClient`, `MessageMapper`: soporte completo de field prefix dinámico.
  - `ConfigManager`: persiste `field_prefix` en cada conexión.
  - Todos los flujos (webhook, import, async worker) usan el field prefix de la conexión.
  - Backward compatible — conexiones existentes usan `telegrammessage` por defecto.
- **Fix**: `MessageMapper::toWikiFields()` ahora usa field prefix dinámico (no hardcodeado).
- **Fix**: `worker.php` setea field prefix por conexión en cada evento.
- **Docs**: AGENTS.md actualizado con nota sobre field prefix dinámico.

## v0.3.0
- **Arquitectura multi-conexión**: trackerGram ahora soporta múltiples bots, wikis y trackers desde una sola instalación.
  - Nueva unidad de configuración: **conexión** (bot_token + webhook_secret + chat_id + tiki_api_url + tiki_api_token + tracker_id + name + enabled).
  - `setup.json` reemplaza a `.env` para la configuración de conexiones. `.env` queda solo para config global (ADMIN_, DEBUG_, ASYNC_).
  - `ConfigManager.php`: CRUD de conexiones con slug auto-generado, migración automática desde `.env`, guardado atómico con LOCK_EX.
- **Webhook multi-bot**: `api.php` identifica la conexión por (chat_id + X-Telegram-Bot-Api-Secret-Token header). Crea TikiWikiClient + TelegramClient + WebhookHandler por conexión.
- **Admin panel rediseñado**: dos pestañas (Webhook + Importar). Lista de tarjetas de conexión con toggle enable/disable, botones de configuración de webhook, test, editar y eliminar. Modal de creación/edición.
- **Import multi-conexión**: `import.php` acepta `tiki_api_url` y `tiki_api_token` por formulario (por sesión de import). Los persiste en metadata.json para el procesamiento por lotes. Ya no depende de `$tikiWikiClient` global.
- **Worker async multi-conexión**: `worker.php` lee `connection_slug` del buffer, crea el handler per-conexión, y procesa contra la wiki/tracker correctos.
- **Seguridad**:
  - `.htaccess` bloquea `*.json`, fuerza HTTPS, agrega CSP header.
  - `config.php` busca `.env` en `config/.env` → `.env` → `../.env`.
  - `config/` directorio creado con `.htaccess` deny-all para futuro aislamiento de `setup.json`.
- **Chore**: Política de ramas documentada: `qpch` = monoTiki estable (solo bugfixes), `main` = desarrollo multi.

## v0.2.3
- **Fix**: `log_message()` ahora respeta `DEBUG_MODE` — solo escribe a `debug.log` cuando `DEBUG_MODE=true` o se pasa `$force=true`. Sistema (`error_log()`) siempre escribe.
- **Feat**: Nuevo parámetro `$force` en `log_message()` — errores críticos (auth, API, ZIP, filesize) pasan `$force=true` para siempre quedar registrados.
- **Docs**: Documentación completa auditada y sincronizada con código actual:
  - `roadmap.md`: versión actualizada a v0.2.2, items completados marcados, cobertura de service messages sincronizada
  - `AGENTS.md`: `log_message()` behavior corregido, servidor prod actualizado, tabla de constantes expandida, roadmap referenciado a archivo externo
  - `TECHNICAL.md`: referencias a métodos estáticos reemplazadas por instancias (DI ya implementada), deuda técnica actualizada
  - `INSTALL.md`: guía post-instalación para wiki feed, límites reales de PHP incluidos, tabla de constantes internas
  - `.env.example`: comentario mejorado para `ALLOWED_CHAT_IDS`
  - `CAMBIOS.md`: entrada v0.2.3 agregada (este cambio)
- **Chore**: Todos los archivos PHP revisados — llamadas críticas a `log_message()` ahora usan `$force=true`.

## v0.2.2
- **Fix**: Gallery resolution usa endpoint correcto `GET /api/trackers/{id}/fields` en vez de `GET /api/trackers/{id}`.
  - `getMediaGalleryId()` y `updateFgFieldOptions()` ahora consultan `/fields` que devuelve definiciones de campos (no items).
  - Eliminado `extractTrackerData()` y fallback hardcodeado `?? 29`.
  - `repairFgGallery()` simplificado sin HTTP call para tracker name.
- **Fix**: `bootstrap.php` ahora lee `TIMEOUT_TIKIWIKI_UPLOAD` (60s) y `TIMEOUT_TIKIWIKI_API` (30s) desde config.php.
- **Feat**: Admin panel muestra límites dinámicos desde PHP (`upload_max_filesize`, `MAX_ZIP_UNCOMPRESSED_SIZE`, `MEDIA_DOWNLOAD_MAX_SIZE`).
- **Feat**: Import batch size configurado a 50 items por request.
- **Feat**: Logging de ZIP entry inválido (`badEntry`) en import.php para debug de "rutas no válidas".
- **Feat**: `config.php` agrega `MAX_ZIP_UNCOMPRESSED_SIZE` (500MB) y helper `formatBytes()`.
- **Docs**: `opt/visualizacion-tiki.md` — unificación de investigación (Pretty Tracker) e implementación (template + CSS para wiki feed tipo chat).
- **Chore**: Eliminado `check_file.php` (diagnóstico, no parte del proyecto).

## v0.2.1
- **Feat**: Vista wiki tipo feed para tracker 22 — implementado con `{TRACKERLIST(tplwiki="plantillaTrackergram")}` más template Smarty personalizado con diseño tipo burbuja de chat.
- **Feat**: Multimedia con HTML5 directo — imágenes ocupan 100% del ancho, videos y audios con reproductores `<video controls>` y `<audio controls>` nativos del browser.
- **Fix**: `mediaUrl` (`telegrammessageMediaUrl`) ahora se popula automáticamente tanto en webhook como en import — antes nunca se guardaba.
  - WebhookHandler: construye URL `tiki-download_file.php?fileId=X` tras cada upload exitoso
  - Import (handleProcess y handleFull): mismo tratamiento con `media_url` en contexto
  - MessageMapper::fromExport(): toma `media_url` del contexto y lo pasa a `NormalizedMessage`
- **Docs**: `opt/visualizacion-tiki.md` — investigación Pretty Tracker + implementación TRACKERLIST + tplwiki.
- **Chore**: Documentación de la sesión de diseño de template wiki en `reports/template-wiki-feed.md`

## v0.2.0
- **Feat**: Nuevo campo `telegrammessageDisplayName` — nombre para mostrar unificado entre webhook e import.
  - Webhook: concatena `firstName + " " + lastName`
  - Import: copia el `from` original del export
  - `firstName`/`lastName` se conservan como datos crudos originales
- **Fix**: Import — inicialización de `$filePath` para evitar "Undefined variable" cuando un mensaje no tiene media.
- **Fix**: Seguridad P2 — XSS en admin moderno, DoS en webhook, límite 20MB real en descarga de media, token leak en redirects, validación de tamaño en import.
- **Feat**: Import chunked — `import.php` ahora soporta `mode=extract` y `mode=process`. Admin moderno con barra de progreso y procesamiento por lotes de 100 mensajes.
- **Fix**: `log_message()` mejorado con mejor manejo de errores de escritura y rotación.
- **Chore**: `.htaccess` actualizado con límites para imports grandes (200M, 1024M, tiempo ilimitado).
- **Chore**: Límites de import aumentados (20000 archivos, 500MB descomprimido).
- **Docs**: README.md y AGENTS.md actualizados con el nuevo campo displayName.

## v0.1.9
- **Fix**: Galería equivocada en import/webhook — `getMediaGalleryId()` ahora parsea todos los formatos de options que devuelve la API (array asociativo, array legacy, JSON string). Nueva `extractGalleryIdFromOptions()`.
- **Fix**: WebhookHandler ahora pasa `trackerId` a `getMediaGalleryId()` en vez de depender del default del `.env`.
- **Fix**: Import — `fromExport()` ya no parte el nombre con `explode()`. Usa el display name completo como `firstName` para consistencia con webhook.
- **Fix**: Import — `userId` ahora usa regex para extraer el ID numérico de prefijos `user/chat/channel` en vez del frágil `str_replace('user', ...)`.
- **Feat**: `uploadedFileIds` ahora es `array` en vez de `?string`. Mensajes con múltiples archivos se pasan al campo FG como comma-separated.
- **Feat**: `createTracker()` ahora crea una file gallery via `POST /api/galleries` y configura el campo FG con `count=0` (ilimitado) y `galleryId`.
- **Feat**: Nuevos métodos `createGallery()` y `updateFgFieldOptions()` en `TikiWikiClient.php`.
- **Docs**: AGENTS.md actualizado con nuevos features y schema de campos.

## v0.1.7
- **Arquitectura**: `api.php` simplificado a entry point HTTP puro (61 líneas). Toda la lógica extraída a `WebhookHandler.php`.
- **Architectura**: `bootstrap.php` creado con carga centralizada de dependencias. Todos los entry points lo usan.
- **Fix**: `debug.log` ahora funciona correctamente — reemplazados todos los `error_log()` por `log_message()` en el código.
- **Arquitectura**: `MessageMapper::fromWebhook()` unifica detección de tipo de mensaje en webhook.
- **Seguridad**: `TELEGRAM_WEBHOOK_SECRET` ahora obligatorio — bloquea webhook si no está configurado.
- **Seguridad**: ZIP import ahora valida cantidad de archivos (máx. 10000), tamaño descomprimido (200 MB) y profundidad de carpetas (10).
- **Fix**: `change_password` en admin.php movido fuera de `checkAuth()` — ahora funciona correctamente.
- **Fix**: XSS en admin.php — reemplazado `innerHTML` por `textContent` + clases CSS en resultados de import.
- **Fix**: Cache de topics ahora usa clave `chatId:threadId` (evita colisiones entre múltiples chats).
- **Fix**: Cache de gallery ID ahora discrimina por tracker (array `[$trackerId => $galleryId]`).
- **Fix**: Deduplicación post-insert detecta race conditions (cuenta items después de crear).
- **Fix**: IDs de reacciones ahora usan identificadores únicos hash-based (`reaction_{chat}_{msg}_{user}_{date}`).
- **Docs**: PHP 7.4+ actualizado a 8.0+ en toda la documentación.
- **Docs**: Eliminado `setup_webhook.php` (obsoleto, reemplazado por admin.php).
- **Docs**: Reportes externos movidos a `reports/`.
- **Docs**: AGENTS.md actualizado con nueva arquitectura (WebhookHandler, sin referencias a líneas obsoletas).

## v0.1.6
- **Seguridad**: Hash de contraseña admin con `password_hash()`/`password_verify()`. Migración automática desde texto plano.
- **Seguridad**: Path traversal en ZIP - validación de nombres de archivo al extraer exports.
- **Seguridad**: Descarga de multimedia en chunks con límite de 20MB (ya no carga todo en memoria).
- **Seguridad**: Rate limiting en webhook (máx 30 req/min por IP).
- **Seguridad**: URLs de Telegram con token ya no se guardan en TikiWiki.
- **Seguridad**: ALLOWED_CHAT_IDS configurable desde .env.
- **Arquitectura**: `sendToTikiWiki()` e `importItemToTikiWiki()` refactorizados para usar `MessageMapper::toWikiFields()` + `TikiWikiClient::createTrackerItem()`. Eliminado curl duplicado.
- **Fix**: `display_errors` condicional a DEBUG_MODE en admin.php e import.php.
- **Fix**: Deduplicación ahora considera (chat_id, message_id), no solo message_id.
- **Fix**: `ZipArchive::$numEntries` → `$numFiles` (PHP 8.2).

## v0.1.5
- **Fix**: TypeError fatal en PHP 8.5 al pasar string `'general'` donde se esperaba `int` (`getTopicName`)
- **Fix**: Eliminada llamada API a `getForumTopic` (no existe en Telegram Bot API)
- **Fix**: Admin.php ya no pisa `CUSTOM_WEBHOOK_URL` al hacer "Actualizar Webhook"
- **Fix**: Reorganizado admin panel en secciones lógicas: Configuración general (Telegram + TikiWiki), Importar, Tracker del webhook, Webhook, Crear Tracker. Cada formulario guarda solo sus campos.
- **Docs**: Agregada sección "Resolución de Nombres de Topics" en TECHNICAL.md
- **Docs**: Agregados links de referencia de APIs en AGENTS.md

## v0.1.4
- Refactorización: Separación de responsabilidades en clientes (TikiWikiClient, TelegramClient, MessageMapper)
- Fix: error de integración en la refactorización (braces extraviados, código residual)
- Importación de exports de Telegram: procesamiento de archivos ZIP exportados
- Soporte para varios tipos de mensaje: texto, foto, video, audio, documento, sticker
- Extracción de topics desde mensajes de tipo service (topic_created)
- Subida de archivos multimedia a TikiWiki durante importación
- Fix: conversion de fecha a UNIX timestamp para TikiWiki API
- Fix: manejo de texto como array (formatos complejos de Telegram)
- Fix: limpiar directorios recursivamente al finalizar importación
- Fix: usar file_name y media_type para detectar tipo de archivo correctamente

## v0.1.2
- Creación automática de tracker con campos via API de TikiWiki
- Interfaz reorganizada con índice y secciones (tracker en directo, importación)
- **Seguridad**: Deduplicación corregida - ahora filtra por message_id específico
- **Seguridad**: checkAuth() ejecutado antes de procesar cualquier acción
- **Seguridad**: setup_webhook.php protegido - solo CLI/localhost
- **Seguridad**: TELEGRAM_WEBHOOK_SECRET obligatorio con hash_equals()
- **Seguridad**: Logs de debug condicionados por DEBUG_MODE
- Fix: ModSecurity bloqueaba peticiones sin User-Agent
- Fix: Tipos de campo correctos para API de TikiWiki (t, a, n, f, D, FG)
- Fix: Validación de secret token solo en peticiones de webhook
- Fix: Evitar ejecución de webhook al incluir api.php como librería
- Detección automática de nombres de topics desde reply_to_message.forum_topic_created
- Fix detección HTTPS con proxies (X-Forwarded-Proto)
- Simplificación: webhook se actualiza automáticamente desde URL del servidor

## v0.1.1
- Deduplicación de mensajes basada en message_id
- Agregado soporte para ubicaciones, contactos, encuestas, animations
- Captura de nombre del chat (chat_title)
- Título del topic (topic_title)
- Mejor manejo de mensajes no soportados (muestra el tipo)
- Refactorización: type hints en funciones, constantes de configuración, logging unificado
- Fix: Token validation con `:` en tokens de Telegram
- Fix: Campo CUSTOM_WEBHOOK_URL para especificar URL del webhook manualmente
- Fix: Bug de doble `/api.php` en generateWebhookUrl()
- Fix: Campo de ubicación (geolocation) no se enviaba a TikiWiki

## v0.1.0
- Subida de archivos multimedia a TikiWiki file gallery
- Los archivos se vinculan al campo `telegrammessageMedia` del tracker
- El galleryId se obtiene dinámicamente desde la configuración del tracker via API

## v0.0.1
- Primera versión funcional
- Webhook endpoint para Telegram
- Integración básica con TikiWiki trackers
- Interfaz de administración
- Autenticación con rate limiting y sesión segura
- CSRF protection en formularios
- Validación de inputs