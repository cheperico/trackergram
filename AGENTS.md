# AGENTS.md — Guía de Contexto para Agentes de IA

> **⚠️ LECTURA OBLIGATORIA** — Contexto principal para opencode. No toques código ni respondas issues sin haber leído este documento completo.

---

## Stack

| Componente | Detalle |
|---|---|
| **Lenguaje** | PHP 8.0+ (`match`, named arguments, `str_starts_with()`), sin framework, sin namespace, sin Composer — `require_once` directo |
| **Telegram** | Bot API vía webhook (`api.php`); `TelegramClient.php` para descargas |
| **Almacenamiento** | TikiWiki 27.5 vía API REST (`TikiWikiClient.php`). Datos transitorios en **archivos JSON** — no hay base de datos propia |
| **Multi-conexión** | Múltiples bots/wikis/trackers desde una instalación; conexiones en `setup.json` (via `ConfigManager`) |
| **Procesamiento** | Síncrono (webhook) o async (buffer + `worker.php`), toggle por conexión |

**Proyecto**: trackerGram — puente Telegram → TikiWiki. Centraliza conversaciones de Telegram como items de tracker (buscables, indexables, fuera de la plataforma). Repo: https://github.com/cheperico/trackergram · **Versión**: v0.7.1 · **Estado**: Beta funcional.

**Metodología**: director humano + agentes de IA. El director valida todo — no asumas que tu código es correcto sin que lo revise. Las sesiones largas pierden contexto; referí siempre a los archivos específicos que modificás.

---

## Estructura del proyecto

### Directorios

| Directorio | Contenido |
|---|---|
| `opt/` | Investigaciones, templates Smarty, archivos opcionales |
| `templates/visualization/` | Templates base de visualización (compilados por VisualizationDeployer) |
| `tmp/` | Archivos temporales (rate limiting, buffers async, caches) |
| `reports/` | Reportes históricos de auditoría (referencia) |
| `design/` | **📐 Diseños activos** — leer antes de arrancar una feature nueva |
| `design/archived/` | 🗄️ Diseños implementados/consolidados — **nunca borrar** |
| `docs/` | Guías de contribución (`CONTRIBUTING.md` — gestión de documentación) |
| `.opencode/` | Configuración de opencode (agentes, skills) |

### Entry Points HTTP

| Archivo | Qué hace |
|---|---|
| `bootstrap.php` | Carga config + clases PHP (sin DI wiring central) — primer include |
| `api.php` | Recibe webhooks de Telegram (solo entry point, sin lógica de negocio) |
| `admin.php` | Panel de administración web |
| `admin_handlers.php` | Handlers POST/AJAX (incluido desde admin.php) |
| `import.php` | Procesa exports ZIP de Telegram |

### Clientes y Lógica

| Archivo | Responsabilidad |
|---|---|
| `config.php` | Carga `.env`, define constantes globales, `log_message()`, `TRACKERGRAM_VERSION` |
| `NormalizedMessage.php` | Modelo intermedio único entre parsers y TikiWiki |
| `TikiWikiClient.php` | API de TikiWiki (crear items, subir archivos, crear trackers, dedup) |
| `TelegramClient.php` | API de Telegram (descargar archivos, info de chats) |
| `MessageMapper.php` | Transforma mensajes → NormalizedMessage → campos TikiWiki |
| `WebhookHandler.php` | Orquesta: valida, resuelve topics, descarga media, envía a TikiWiki |
| `ConfigManager.php` | CRUD de conexiones multi-bot/wiki/tracker en `setup.json` |
| `VisualizationDeployer.php` | Deploy automático de visualización (compila template Smarty, sube páginas wiki) |
| `exceptions.php` | Excepciones de dominio (`TrackerGramException` y subclases) |

Frontend admin: `admin.css`, `admin.js`, `admin_import.js`. Soporte: `.env` (NO versionar), `.htaccess`, `setup.json` (auto-generado, bloqueado por `.htaccess`), `debug.log`.

### Orden recomendado de lectura del código

1. `config.php` → 2. `bootstrap.php` → 3. `NormalizedMessage.php` → 4. `api.php` → 5. `WebhookHandler.php` → 6. `MessageMapper.php` → 7. `TikiWikiClient.php` → 8. `TelegramClient.php` → 9. `import.php` → 10. `admin.php`

---

## Decisiones clave (cómo trabajamos)

### Sin base de datos — archivos JSON, no SQLite

TikiWiki es el almacenamiento; toda la lógica de dedup/búsqueda/persistencia depende de su API. Para datos transitorios (cachés, cola async, rate limiting) se usan **archivos JSON** con `flock(LOCK_EX)`: human-editable, versionable en git, sin depender de `ext-sqlite3`, suficiente para el volumen actual. SQLite está en el roadmap como evaluación futura (cola async/rate limiting), no para `setup.json` ni cachés chicas.

### Inyección de dependencias por conexión

`TikiWikiClient`, `TelegramClient`, `WebhookHandler` y `MessageMapper` son instanciables con dependencias por constructor. No hay wiring central en `bootstrap.php`; cada entry point crea sus clientes por conexión desde `ConfigManager`:

```php
$tikiClient = new TikiWikiClient(apiUrl: $conn['tiki_api_url'], token: $conn['tiki_api_token'], timeout: TIMEOUT_TIKIWIKI_API, uploadTimeout: TIMEOUT_TIKIWIKI_UPLOAD);
$tgClient = new TelegramClient(botToken: $conn['bot_token']);
$handler = new WebhookHandler(tikiWikiClient: $tikiClient, telegramClient: $tgClient, messageMapper: $messageMapper, trackerId: (int) $conn['tracker_id']);
$handler->processUpdate($update);
```

### Field prefix auto-detectado (regla operativa)

Los permNames no están hardcodeados como `telegrammessage*`; se generan desde el `field_prefix` de la conexión (ej: `qpch`, `soporte`). El sistema **detecta automáticamente** el prefix real del tracker cuando el almacenado es `telegrammessage` (default): fetchea `GET /api/trackers/{id}/fields`, busca sufijos conocidos (`TelegramMessageId`, `ChatId`, `Text`, `MessageDate`, `Media`, `Hashtags`), extrae el prefijo común y lo persiste a `setup.json`.

**⚠️ Regla de rendimiento**: la auto-detección se ejecuta **UNA SOLA VEZ por conexión** (flag `field_prefix_checked` en `setup.json`). **NO llamar a la API de TikiWiki en cada request** para re-detectar el prefix — respetá el flag. Aplica a admin.php, api.php, worker.php e import.php.

### Álbumes: agrupación atómica

Telegram envía las fotos de un álbum como mensajes individuales. Para que compartan un solo item se usa un buffer JSON (`tmp/media_group_album.json`) con registro atómico vía `flock(LOCK_EX)`: `registerOrLookupAlbum()` reserva/append, `completeAlbumRegistration()` llena el itemId real, `removeAlbumRegistration()` limpia si falla, GC probabilístico limpia entradas stale. `appendMediaToTrackerItem()` es idempotente (no duplica fileId en el campo FG).

### Flujo de datos

**Webhook (tiempo real)**: `Telegram → api.php` → extraer `chat_id` + header `X-Telegram-Bot-Api-Secret-Token` → buscar conexión por `(chat_id, webhook_secret)` en ConfigManager → crear clientes per-conexión → `$handler->processUpdate()`. Si no hay conexión → HTTP 403 (sin fallback legacy). Async opcional: buffer a `tmp/buffer/` con `connection_slug`.

**Handler**: `processUpdate()` → `processMessage()` → `fromWebhook()` (→ NormalizedMessage) → descargar media si hay → `messageExists()` (dedup) → `toWikiFields()` → `createTrackerItem()`. Si `mediaGroupId` set: `registerOrLookupAlbum()` → si álbum existente, `appendMediaToTrackerItem()` y return (no crear item nuevo); si es la primera foto, `completeAlbumRegistration()` tras crear.

**Import (ZIP export)**: `admin.php → import.php` → extraer ZIP (validar path traversal) → parsear `result.json` → `fromExport()` → `toWikiFields()` → `createTrackerItem()`.

**Mensajes editados**: `edited_message`/`edited_channel_post` → `processEditedMessage()` busca item existente por `(chat_id, message_id)` y aplica `updateTrackerItem()` con solo Text+EditedDate+Reactions. Si no existe, crea item nuevo.

---

## Convenciones y reglas

### Patrones comunes
- Siempre usar `bootstrap.php` como punto de entrada (en trackerGram)
- Siempre usar los clientes existentes, no hacer curl directo
- Siempre usar `MessageMapper` para transformación de datos
- Los logs van con `error_log()` y `log_message()`
- `log_message()` respeta `DEBUG_MODE`: `debug.log` solo si `DEBUG_MODE=true` o `$force=true` (error crítico); `error_log()` siempre

### Convenciones de código
- PHP 8.0+ (usa `match`, `str_starts_with()`, named arguments)
- Inyección de dependencias por constructor (clases instanciables)
- Sin namespace, sin autoloading — `require_once` directo
- Sin comentarios innecesarios — código autoexplicativo

---

## Qué NO hacer

> Estas son cosas que **ya pasaron y costaron arreglar**. No las repitas.

### Arquitectura
- **No agregar lógica de negocio en `api.php`** — Es solo entry point. Toda la lógica va en `WebhookHandler`.
- **No usar `curl` directamente** — Usar `TelegramClient` y `TikiWikiClient`.
- **No usar JSON en POST a API de TikiWiki** — La API NO mergea JSON body a `$_POST`. Todos los POST que crean o modifican recursos (trackers, fields, galleries) deben usar `application/x-www-form-urlencoded`.
- **No usar `GET /api/trackers/{id}` para obtener field definitions** — Ese endpoint devuelve **items**, no campos. Usar `GET /api/trackers/{id}/fields`. Confirmado en `ApiBridge.php` (`action=list_items`).
- **No crear variables globales** — Usar `static` dentro de funciones o pasar por parámetros.
- **No asumir que HTTP 200 significa éxito** en actualizaciones de FG field options — `POST /api/trackers/{id}/fields/{id}` requiere `name` + `type` + `option[...]` en el body. Si falta `name`, TikiWiki salta el guardado pero igual responde 200. Verificar con `GET /api/trackers/{id}/fields` después.
- **No requerir archivos individuales** — Siempre usar `require_once 'bootstrap.php'`.
- **No duplicar lógica de parsing entre webhook e import** — Ambos convergen en `MessageMapper::toWikiFields()` vía `NormalizedMessage`. Webhook usa `fromWebhook()`, import usa `fromExport()`.
- **No depender del modo legado** — El modo legacy (constantes en `.env`) fue eliminado. Todas las conexiones se configuran desde el panel admin y se persisten en `setup.json`. `api.php` rechaza con 403 si no hay conexión.
- **No usar `htmlspecialchars()` en `toWikiFields()`** — Los datos van a la API via `http_build_query()` (form-urlencoded). `htmlspecialchars()` convierte `"` en `&quot;` y se guarda LITERALMENTE. El escape HTML es de la capa de vista (Smarty), no de la API.
- **Siempre aplicar `strip_tags()` a texto de usuario antes de enviarlo a TikiWiki** — Los mensajes de Telegram son texto plano (el formato viaja como `entities[]`, no HTML). Elimina `<script>`, `<img onerror=...>`, etc. Aplica a: `Text`, `MediaCaption`, `DisplayName`, `FirstName`, `LastName`, `ChatTitle`, `TopicTitle`, `Username`, `Reactions`. No a campos numéricos (`Location`, `MediaSize`). Ver `design/999-a-tener-en-cuenta.md §5`.

### Telegram
- **No intentar usar `getForumTopic`** — No existe en la Telegram Bot API. Resolución de topics por cache + fallback.
- **No asumir que `message_id` es único sin `chat_id`** — Deduplicación es por par `(chat_id, message_id)`.
- **No usar `sleep()` en reintentos** — Usar `usleep()`.
- **No descargar archivos sin verificar tamaño** — Límite de 20MB (`MEDIA_DOWNLOAD_MAX_SIZE`).
- **No asumir que el bot recibe todos los mensajes del grupo** — Por defecto los bots tienen **Privacy Mode** y solo ven mensajes de sistema, comandos, menciones y replies. Para recibir todos, el bot debe ser **administrador del grupo** o deshabilitar privacy mode en BotFather (`/setprivacy` → Disable) y re-agregarlo. Ver: https://core.telegram.org/bots/features#privacy-mode
- **BUG-001**: `findByWebhookSecret()` no debe devolver la primera conexión que encuentra — priorizar pendientes (`chat_id=0`), si no ordenar por `created_at` desc.
- **BUG-002**: `pending_update_count` de Telegram siempre ≥1 durante `/estado` (el Bot API cuenta el update actual como pendiente hasta el 200 OK). Workaround: ocultar pending bajo (<10) en `/estado`.

### Seguridad
- **No exponer credenciales en URLs o logs** — Los tokens en URLs de descarga se filtraban. `configure_webhook` debe usar POST body, no GET query string.
- **No comparar contraseñas en texto plano** — Se usa `password_verify()` con bcrypt.
- **No saltar validación CSRF en admin POST** — Todas las acciones mutantes la requieren.
- **No extraer ZIPs sin validar path traversal** — Verificar que ningún entry contenga `..`.
- **No usar `innerHTML` en el JS del admin** — Riesgo XSS. Usar `textContent` (o `safeRender()` para datos de APIs externas).

### General
- **No modificar `.env` manualmente en producción** — Usar el panel de admin.
- **No cargar archivos completos en memoria** — Usar chunks para descargas.
- **No actualizar código sin actualizar esta documentación** — AGENTS.md debe reflejar el estado real.

---

## Verificación

- **Sintaxis PHP**: `php -l <archivo>.php` antes de dar por terminado un cambio.
- **Logs**: con `DEBUG_MODE=true` en `.env`, `debug.log` registra el detalle (rotación a 10MB). Errores críticos siempre van a `error_log()`.
- **Panel admin**: botón "🧪 Test" en cada conexión verifica credenciales TikiWiki + webhook.
- **Import de prueba**: subir un ZIP pequeño de export para validar el flujo de importación.
- **Dedup (webhook)**: editar un mensaje en el grupo y verificar que NO se crea un item duplicado (debe actualizar el existente).

---

## Git y Versionado

- `main` es la **rama única de trabajo y producción**. Los releases se marcan con tags `vX.Y.Z` sobre main.
- `mono` es la **versión monoTiki legacy** (antes `qpch`): un solo TikiWiki/grupo/bot. Congelada — solo bugfixes críticos. Hotfixes: tags `mono-vX.Y.Z`.
- Para experimentar algo riesgoso: branch temporal (`git checkout -b experimento`), mergear a main y borrar.
- **Al cambiar de versión**, actualizar OBLIGATORIAMENTE `TRACKERGRAM_VERSION` en `config.php` (fuente de verdad mostrada en la UI del admin), en el mismo commit del release.
- **No hacer `git commit`/`git push` sin que el usuario lo pida explícitamente.**

---

## Dónde está la información

| Si la tarea involucra... | Consulta |
|---|---|
| Historial de cambios / versiones | `CAMBIOS.md` |
| Roadmap / pendientes / bugs conocidos | `roadmap.md` |
| Schema completo del tracker + INI manual | `TECHNICAL.md` (apéndice) |
| Arquitectura detallada, flujos, lecciones | `TECHNICAL.md` |
| Instalación / deploy / servidores conocidos | `INSTALL.md` |
| Uso final, tipos de mensaje soportados | `README.md` |
| Mantenimiento de documentación | `docs/CONTRIBUTING.md` |
| Seguridad TikiWiki (SQLi, XSS) | `design/999-a-tener-en-cuenta.md` |
| Feature nueva / diseño exploratorio | `design/` (activos) |
| Decisiones históricas implementadas | `design/archived/` |
| Auditorías históricas | `reports/` (ya consolidadas en roadmap) |
| Código interno de TikiWiki 27.5 | `..\TikiWiki\` + agente `@tiki-expert` |

---

## Mantenimiento de Este Archivo

Actualizar cuando: se agrega una funcionalidad principal, se modifica la estructura de archivos, se cambia la visión/filosofía, se alcanza una nueva versión, o un nuevo desarrollador/agente se une al proyecto.

**No actualizar** por fixes de bugs menores — esos van a CAMBIOS.md.