# trackerGram — Roadmap Unificado

> ⚠️ **Documento único de referencia**. Consolidado desde:
> - `roadmap.md` (anterior)
> - `reports/architecture_efficiency_report.md`
> - `reports/feature_suggestions_report.md`
> - `reports/informe de GPT.md`
> - `reports/security_audit_report.md`
> - `reports/template-wiki-feed.md`
> - `CAMBIOS.md`
> - `reports/2024-06-28-code-review-exhaustivo.md`
> - Code Review Arq & Seg Julio 2026 (CodeRabbit)
> - Code Review Data Flow Julio 2026 (CodeRabbit)

---

## Estado del Proyecto

| | |
|---|---|
| **Versión actual** | v0.6.0 |
| **Estado** | Beta funcional, desarrollo activo |
| **Instancias activas** | Dev (tracker 26) · Prod (tracker 22) |
| **Filosofía** | Sin DB con servidor · JSON files para estado local (no SQLite) · PHP puro sin framework · MVP pragmático |

### Lo que ya funciona sólido

- ✅ Webhook tiempo real → TikiWiki (multi-conexión)
- ✅ Import exports ZIP (incluyendo batch con progreso)
- ✅ Soporte multimedia: fotos, videos, audio, documentos, stickers, notas de voz
- ✅ Topics (forums) con resolución de nombres
- ✅ Reacciones a mensajes
- ✅ Service messages (creación topics, miembros, pins, etc.)
- ✅ Creación automática de trackers con todos los campos
- ✅ Panel admin clásico + moderno con progress bar
- ✅ Deduplicación por (chat_id, message_id)
- ✅ Seguridad: CSRF, rate limiting, hash contraseñas, path traversal, XSS
- ✅ Auto-reparación de galería (repairFgGallery)
- ✅ Inyección de dependencias (clases instanciables por conexión)
- ✅ NormalizedMessage como modelo único
- ✅ Gallery resolution via endpoint `/fields`
- ✅ Timeouts separados upload (60s) / api (30s)
- ✅ debug.log respeta DEBUG_MODE ($force para críticos)
- ✅ Álbumes/grupos de medios (mediaGroup) en webhook (cada foto en su propio item; caption propagada entre fotos del mismo álbum)
- ✅ Cache de topics por chatId:threadId
- ✅ Cache de gallery ID por tracker
- ✅ Webhook Secret obligatorio (rechaza si vacío)
- ✅ change_password funcional (fuera de checkAuth)
- ✅ my_chat_member handler + auto leaveChat para chats no autorizados
- ✅ **Arquitectura multi-conexión**: múltiples bots/wikis/trackers desde una instalación
- ✅ **Async processing por conexión**: toggle en admin, per-connection
- ✅ **.env simplificado**: solo config global; credenciales en setup.json
- ✅ **Service messages completos**: cobertura total webhook e import (incluye `remove_members`, `joined`, `new_chat_photo`, `delete_chat_photo`)
- ✅ **Creación de tracker desde admin panel**: shell + fields + galería (auto o existente) + field prefix
- ✅ **Fan-out**: mismo mensaje a múltiples trackers duplicando conexión (con try-catch individual, error en una conexión no rompe las demás)
- ✅ **Cache auto-detección field prefix**: flag `field_prefix_checked` evita llamadas API repetidas en cada carga de admin y cada webhook
- ✅ **FG field options vía API**: `updateFgFieldOptions()` con `name`+`type` requeridos
- ✅ **Auto-población bot_name/chat_title** en cards de conexión
- ✅ **Chat_id con -100 en import**: corrección del prefijo `-100` para supergrupos (el export JSON de Telegram Desktop omite el `-100` en el `id` raíz)
- ✅ **ReplyToId con texto del original (Opción B)**: en webhook extrae `reply_to_message.text` (gratis), en import busca el texto via API. Guarda `#42 - "texto..."` en el campo ReplyToId existente.
- ✅ **Health check visible en cards de conexión**: cada tarjeta muestra estado del webhook vía `getWebhookInfo()` (✅ configurado, ❌ no configurado, ⚠️ con errores).
- ✅ **Verificación post-creación de FG field**: `updateFgFieldOptions()` verifica con `GET /fields` que el galleryId se haya guardado (workaround del bug de TikiWiki que responde HTTP 200 aunque falle).
- ✅ **Field descriptions en API**: todos los campos del tracker se crean con `description` descriptivo enviado a la API de TikiWiki.
- ✅ **Auto-detección de field prefix**: si el prefix almacenado en `setup.json` es `telegrammessage` (default), el sistema lo verifica contra los campos reales del tracker vía API y lo corrige automáticamente si es distinto. Se persiste tras el primer webhook. Cobertura: webhook, async worker, import.
- ✅ **Hashtags como etiquetas (Freetags)**: `#tags` extraídos de mensajes de Telegram (webhook e import) guardados en campo tipo `F` (Freetags). Se integran al ecosistema de etiquetas de TikiWiki (tag cloud, búsqueda).
- ✅ **Deduplicación pre-create con edit detection**: antes de crear un item en import, busca si ya existe por (chat_id, message_id). Si existe y tiene editedDate distinto, actualiza solo Text+EditedDate+Reactions.
- ✅ **Polls/quizzes enriquecidos desde export**: el import parsea `answers[]` con `voters` reales del export ZIP, generando texto tipo `📊 Pregunta\n• Opción A: 5 votos\nTotal: 8 votos`. Reemplaza el placeholder del webhook.
- ✅ **updateTrackerItem()**: método en TikiWikiClient para reflejar edits de Telegram. Solo actualiza Text+EditedDate+Reactions (nunca Media/MessageType/Location) para evitar pérdida por exports parciales.
- ✅ **toWikiFieldsEdit()**: genera SOLO campos editables (Text, EditedDate, Reactions), seguro para usar con exports parciales.
- ✅ **edited_message ruteado por webhook**: `edited_message` y `edited_channel_post` se procesan vía `processEditedMessage()`, que busca item existente y aplica update seguro (solo Text+EditedDate+Reactions) o crea item nuevo si no existe.
- ✅ **safeRender() en admin.php**: toda renderización de datos de APIs externas usa `textContent` + nodos `<br>` en vez de `innerHTML`, eliminando riesgo XSS.
- ✅ **configure_webhook usa POST**: el token de bot viaja en body HTTP, no en URL query string.
- ✅ **htmlspecialchars eliminado de fromWebhook()**: captions y titles se guardan crudos; el escape HTML es responsabilidad de la capa de vista.
- ✅ **Rate limiting con flock(LOCK_EX)**: `fopen('c+')` + `flock()` reemplaza a `file_get_contents()`/`file_put_contents()` sin lock, eliminando race condition entre requests concurrentes.
- ✅ **Detección de migración grupo→supergrupo**: `migrate_to_chat_id` detectado automáticamente y `chat_id` actualizado en la conexión. Soporte para post-migración con `migrate_from_chat_id` y auto-asignación por heurística (basic→supergroup).
- ✅ **GC de archivos rate limit**: limpieza probabilística (1%) de archivos `tmp/tg_rate_*` con más de 1 hora de inactividad.
- ✅ **ConfigManager::load() con flock(LOCK_SH)**: lectura de `setup.json` con lock compartido, previniendo JSON truncado por escritura concurrente.
- ✅ **TOCTOU en dedup eliminado**: lock exclusivo por `(chatId:messageId)` serializa la creación de items, cerrando la ventana entre verificación e inserción.
- ✅ **Fan-out con 502 en fallo total**: si todas las conexiones fallan, responde HTTP 502 para que Telegram reintente.
- ✅ **messageExists() con null en error**: timeout/5xx de TikiWiki ya no se interpreta como "mensaje no existe", evitando duplicados transitorios.
- ✅ **SSRF DNS rebinding prevenido**: TikiWikiClient resuelve hostname a IP en construcción y fuerza cURL a usar esa IP mediante `CURLOPT_RESOLVE` en todos los 23 calls curl. Un ataque de DNS rebinding no puede redirigir la conexión a IP interna.
- ✅ **Host header poisoning prevenido**: `CURLOPT_RESOLVE` + no-follow-redirects garantiza que el Host header siempre deriva del hostname original, no del IP de conexión. Host header no puede ser manipulado por DNS rebinding.
- ✅ **SSL verification forzada en todos los calls curl de TikiWikiClient**: `createCurlHandle()` centraliza `CURLOPT_SSL_VERIFYPEER` y `CURLOPT_SSL_VERIFYHOST` para toda la clase.
- ✅ **ConfigManager::validateConnectionData() más estricta**: rechaza hostnames sin resolución DNS, reporta IP detectada en errores de IP privada.
- ✅ **Escritura atómica de buffer async**: `api.php` escribe a `.json.tmp` + `rename()` atómico. Si el proceso crashea, no queda `.json` truncado en la cola.
- ✅ **Lock directo sobre .json en worker**: reemplazado lock separado (`fopen('x')`) por `flock(LOCK_EX | LOCK_NB)` sobre el archivo `.json` mismo. Si el worker crashea, el SO libera el lock y otro worker puede retomar el evento.
- ✅ **GC de buffer**: `cleanupDoneFiles()` barre `.failed*`, `.lock` y `.tmp` viejos además de `.done`.
- ✅ **resolveHostToIp() helper**: nueva función que resuelve hostname a IP con fallback IPv6 (dns_get_record DNS_AAAA).
- ✅ **Admin rate limit con flock(LOCK_EX)**: checkRateLimit/incrementFailedLogin/resetFailedLogin refactorizadas con fopen('c+') + flock, helper readWriteRateData().
- ✅ **generateWebhookUrl() sanitizada**: host header validado con regex, warning si difiere de SERVER_NAME.
- ✅ **Dedup lock files se eliminan post-uso**: @unlink después de fclose en ambos puntos de salida de processMessage().
- ✅ **TelegramClient::setWebhook()**: nuevo método, admin.php lo usa en vez de curl directo.
- ✅ **Cache-Control: no-store en get_connection**: evita que tokens queden en cachés intermedias.
- ✅ **createCurlHandle() fix**: reparada recursión infinita (llamaba a $this->createCurlHandle() en vez de curl_init()).
- ✅ **#20 Desarme admin.php — Fase A**: CSS/JS extraídos a `admin.css` (211 líneas), `admin.js` (558 líneas), `admin_import.js` (166 líneas). admin.php reducido de 2529 a ~1597 líneas.
- ✅ **#20 Desarme admin.php — Fase B**: Handlers POST extraídos a `admin_handlers.php` (508 líneas). admin.php reducido a ~1114 líneas (-56% del original). Handlers ejecutados ANTES de loops pesados (AJAX en ms). `$connectionsSafe` construido al final. ValidateCSRF con JSON+403. Sin doble escape.

---

## Prioridades Reales

### 🔴 Fase 1: Lo que más duele ahora (días)

*(Todos los items de Fase 1 completados ✅)*

### 🟡 Fase 2: Robustez (1-2 semanas)

*(Todos los items de Fase 2 completados ✅)*

### 🟢 Fase 3: Bugs + Robustez (mediano plazo)

| # | Item | Esfuerzo | Dependencias |
|---|------|----------|--------------|
| 6 | **Chat_id unificado para imports con migración** | 2 sesiones | Los exports de Telegram pueden incluir migración grupo→supergrupo. Los mensajes pre-migración tienen IDs negativos, los post-migración IDs positivos. El chat_id también cambia. Decidir estrategia (un solo chat_id para todo el grupo, o bifurcar) e implementar detección de service messages `migrate_to_supergroup`/`migrate_from_group`. |
| 6b | **Reply: link clickeable + texto del original** | 1-2 sesiones | El enlace al mensaje respondido ahora usa `preg_match` (modifier nativo de TikiWiki) para extraer el itemId en templates `tplwiki`. Pendientes: (1) probar que funcione en TikiWiki real (cache de templates_c/), (2) Opción A alternativa: campo `ReplyToText` separado para evitar concatenar texto en `ReplyToId`. Para trackers existentes requiere migración. Referencia: `opt/visualizacion-lcc2026.md`, `opt/visualizacion-tiki.md`. |
| 7 | **Mensajes estructurados con prefijos** | 2-3 sesiones | Parser en MessageMapper para mensajes tipo `GPS user coord` o `#tag texto`. |
| 8 | **Manejo de errores estandarizado** | 2-3 sesiones | Excepciones de dominio (`ConfigException`, `TelegramException`, `TikiWikiException`, `ImportException`). |
| 9 | **Import CLI asíncrono** | 2 sesiones | Script CLI para exports grandes sin timeout HTTP. |
| 10 | **Álbumes/grupos de medios en un solo item** | 2-3 sesiones | Agrupar fotos del mismo `media_group_id` en UN item del tracker con múltiples archivos en el campo FG. Actualmente cada foto crea su propio item. Requiere: (1) método para actualizar items existentes en TikiWikiClient, (2) lógica de detección de grupo y update vs create, (3) concurrencia (fotos llegan casi simultáneas). |
| 11 | **Nombre de archivo desde file_path de getFile() cuando falta file_name** | 1 sesión | Cuando Telegram no envía `file_name`, el fallback actual es `mimeToExtension()` → `Documento.mp4`. Mejora: cachear la respuesta de `getFile()` (ya se llama en `getFileUrl()`, cero calls extra) y extraer `basename(file_path)`. Ej: `file_path: "documents/video_2025_mp4.mp4"` → `fileName: "video_2025_mp4.mp4"`. No es el nombre original del usuario pero es más descriptivo que `Documento.mp4` y tiene extensión real. **Implementación**: (1) agregar cache interno `$fileInfoCache` en `TelegramClient`, (2) método `getFileInfo()` que cachea, (3) refactor `getFileUrl()` para usarlo, (4) en `MessageMapper::fromWebhook()` documentos/animations, intentar `basename(file_path)` antes de caer a `'Documento'`. |
| 12 | **Backoff exponencial en GET requests de TikiWikiClient** | 1 sesión | Las requests GET (`messageExists`, `findItemByMessageId`, `getTrackerItem`) no tienen retry. Si TikiWiki está sobrecargado, el webhook falla sin reintentar. Añadir backoff como ya existe para uploads. |
| **F3-1** | **Hashtags con regex en vez de substr()** | 1 sesión | `extractHashtags()` usa `substr()` con offset UTF-16 de Telegram, que se desalinea si hay emojis antes del hashtag. Reemplazar con `preg_match_all('/#(\w+)/u', $text)`. Código: MessageMapper.php:43. Code Review: Data Flow #1. |
| **F3-2** | **SSRF fail-closed en initCurlResolve()** | 30 min | Si el hostname resuelve a IP privada, la función solo loguea y sigue. Debe lanzar `RuntimeException`. Código: TikiWikiClient.php:186-189. Code Review: Arq&Seg #1. |
| **F3-3** | **Race condition álbumes: lock atómico captions** | 1 sesión | `loadMediaGroupCaptions()` suelta `LOCK_SH` antes de que `saveMediaGroupCaptions()` adquiera `LOCK_EX`. Álbumes de 5+ fotos concurrenes pierden captions. Fix: `fopen('c+')` + `flock(LOCK_EX)` sostenido para todo el ciclo read-modify-write. Código: WebhookHandler.php:769-788. Code Review: Data Flow #3. |
| **F3-4** | **Race condition topics: mismo fix que F3-3** | 1 sesión | `getTopicName()` y escrituras de `topic_names.json` sin locks atómicos. Mismo patrón que álbumes. Código: WebhookHandler.php:47-53,253,305,311. Code Review: Data Flow #4. |
| **F3-5** | **Rate limiting key por secret_token en vez de IP** | 30 min | Todos los webhooks vienen de IPs de Telegram → rate limit compartido entre conexiones. Cambiar key de `md5($ip)` a `md5($secretToken)`. Código: api.php:27. Code Review: Arq&Seg #2. |
| **F3-6** | **N+1 calls field prefix con flag prefixVerified** | 1 sesión | `resolveFieldPrefix()` hace GET /fields aunque prefix ya esté verificado, en cada mensaje con media. Fix: flag `prefixVerified` que `setFieldPrefix()` marque como true. Código: TikiWikiClient.php:50-56. Code Review: Data Flow #2. |
| **F3-7** | **Archivos temporales a TEMP_DIR** | 30 min | `topic_names.json` y `media_group_captions.json` en `__DIR__` en vez de `TEMP_DIR`. Código: WebhookHandler.php:47,762. Code Review: Arq&Seg #7. |
| **F3-8** | **Eliminar operador @ en puntos críticos** | 1 sesión | `@rename`, `@fopen`, `@unlink` silencian errores de disco/permisos. Worker puede reprocesar infinitamente si `@rename` falla. Fix: sacar `@` y loguear con `error_get_last()`. Archivos: worker.php, api.php, varios. Code Review: Arq&Seg #8. |
| **F3-9** | **Reply-To cache local (chat_id,message_id)→itemId** | 1-2 sesiones | Si el mensaje original se creó hace ms, el índice de TikiWiki puede no tenerlo. Cache local en JSON elimina la llamada API y evita el miss. Código: WebhookHandler.php:346, TikiWikiClient.php:592. Code Review: Data Flow #4. |

### 🔵 Fase 4: Refactor + Deuda Técnica (largo plazo)

| # | Item | Esfuerzo | Notas |
|---|------|----------|-------|
| 11 | **Mini App** (Telegram Web App) | 5+ sesiones | Frontend embebido + backend. Formulario rico. Ver `design/002-MiniApp.md`. |
| 12 | **Dashboard de métricas** | 2-3 sesiones | Mensajes procesados, errores, media subidos por conexión. |
| 13 | **Tests unitarios** | Continuo | MessageMapper, WebhookHandler, clientes. |
| 14 | **PSR-4 autoloading** | 1 sesión | Mover clases a `src/`, autoloader. |
| 15 | **Transcripción de voz / OCR** | 3-4 sesiones | Whisper + OCR. Dependencias externas. |
| 16 | **SQLite para cola async y rate limiting** (evaluación) | 1 sesión | **Opcional.** Evaluar si vale la pena migrar tmp/buffer/ y rate limiting de archivos JSON a SQLite. Prioridad mínima — los archivos actuales funcionan para el volumen esperado. No aplica a setup.json ni topic cache. Code Review: Arq&Seg #9. Postergado. |
| 17 | **Rotación de logs por fecha** | 1 sesión | Además de por tamaño. |
| 18 | **Expulsar bot desde admin panel** | 1 sesión | Botón para sacar el bot de un grupo directamente desde la interface, sin tener que hacerlo desde Telegram. |
| 19 | **JsonFileStorage utility class** | 1-2 sesiones | Centralizar acceso a archivos JSON con `flock()` (LOCK_EX/LOCK_SH). Resolvería race conditions en rate limiting, ConfigManager, topics cache y media group captions (F3-3, F3-4) de un solo golpe. |
| **F4-1** | **WebhookHandler refactor (God Object, 823 líneas)** | 2-3 sesiones | Extraer `CommandRouter` (comandos /ayuda /estado), `MediaProcessor` (download + upload + álbumes), `DeduplicationService` (locks TOCTOU). WebhookHandler queda como fachada. Code Review: Arq&Seg #6. |
| **F4-2** | **HTTP Keep-Alive con curl_reset()** | 1 sesión | Reutilizar handle curl en TikiWikiClient y TelegramClient con `curl_reset()` para habilitar HTTP Keep-Alive y evitar handshake TLS en cada llamada. Code Review: Arq&Seg #4. |
| **F4-3** | **Head-of-Line blocking en worker.php** | 2-3 sesiones | Worker single-thread: si TikiWiki tarda 30s en upload, la cola se bloquea. Evaluar `curl_multi_exec()` para push concurrente o múltiples workers con lock granular. Code Review: Arq&Seg #5. |

### ⚪ Fase 5: Pendientes de reevaluación (muy baja prioridad)

Items que no justifican implementación hoy pero se documentan por si el contexto cambia. Requieren re-evaluación antes de arrancar.

| # | Item | Notas |
|---|------|-------|
| 1 | **Botón "Detectar prefix" en admin** | Con la auto-detección automática en el primer webhook, un botón manual es redundante. Solo tendría sentido si hubiera casos donde no se pueda enviar un mensaje para gatillar la detección. |
| 2 | **Flag `prefix_confirmed` para evitar re-detección** | La auto-detección ya persiste el prefix corregido a `setup.json` después del primer webhook, por lo que no hay re-detección en requests subsiguientes. El flag no agrega valor. |
| 3 | **Campo `field_prefix` visible/editable en modal de edición de conexión** | El modal de Webhook no incluye `field_prefix`. Con la auto-detección ya no es necesario editarlo manualmente. Solo tendría sentido si se quiere ver el valor detectado por transparencia. |

---

## Service Messages — Cobertura Real

| Evento | Webhook | Import | Notas |
|--------|---------|--------|-------|
| `forum_topic_created` / `topic_created` | ✅ | ✅ | |
| `forum_topic_edited` / `topic_edit` | ✅ | ✅ | |
| `forum_topic_closed/reopened` | ✅ | ✅ | |
| `new_chat_members` / `invite_members` | ✅ | ✅ | |
| `left_chat_member` / `left` | ✅ | ✅ | |
| `pinned_message` / `pin_message` | ✅ | ✅ | |
| `group_chat_created` / `supergroup_chat_created` / `create_group` | ✅ | ✅ | |
| `new_chat_title` / `title_edit` | ✅ | ✅ | |
| `new_chat_photo` / `delete_chat_photo` | ✅ | ✅ | |
| `remove_members` | ✅ | ✅ | |
| `joined` | ✅ | ✅ | |
| `message_reaction` / `message_reaction_count` | ✅ | ✅ (embebidas) | En import vienen como campo `reactions[]` dentro del mensaje, no como evento separado. Ya se parsea en `fromExport()`. |

**Pendientes:**
*(ninguno — cobertura completa)*

---

## Bugs Conocidos

| ID | Descripción | Estado |
|----|-------------|--------|
| BUG-001 | `findByWebhookSecret()` devolvía primera conexión en vez de la pendiente | ✅ **Arreglado** en v0.5.8 |
| BUG-002 | `pending_update_count` incluye el update actual durante `/estado` | ⚠️ Workaround (ocultar pending <10). Fix posta: restar 1 al pending o health check externo. |
| BUG-003 | **debug.log no se escribe en producción** — Cuando `DEBUG_MODE=false`, `log_message()` solo escribe si `$force=true`. Pero `$force` solo se usa en errores críticos. Eventos importantes (mensajes procesados, errores de API, detecciones) no quedan registrados, haciendo imposible troubleshootear sin activar debug mode. | Pendiente de diagnóstico. Posible fix: log levels (INFO/WARN/ERROR) o rotación agresiva y siempre-escribir con límite de tamaño. |
| BUG-004 | **Hashtags corruptos con emojis** — `extractHashtags()` usa `substr()` con offset UTF-16 de Telegram. Si hay emojis antes del hashtag, el offset se desalinea y extrae texto corrupto. Código: MessageMapper.php:43. Code Review: Data Flow #1. | ✅ Fix listo para implementar (F3-1). |
| BUG-005 | **Race condition en álbumes** — `loadMediaGroupCaptions()` suelta `LOCK_SH` antes de que `saveMediaGroupCaptions()` adquiera `LOCK_EX`. Álbumes de 5+ fotos pierden captions. Código: WebhookHandler.php:769-788. Code Review: Data Flow #3. | Pendiente de implementar (F3-3). |
| BUG-006 | **Race condition en topics** — `getTopicName()` sin lock de lectura; escrituras con TOCTOU. Mismo bug que BUG-005. Código: WebhookHandler.php:47-53,253,305,311. Code Review: Data Flow #4. | Pendiente de implementar (F3-4). |

## Cosas que NO vamos a hacer (por ahora)

| Item | Motivo |
|------|--------|
| Base de datos local con servidor (MySQL/PostgreSQL) | Rompe la filosofía "sin DB". TikiWiki es el almacenamiento. |
| Framework PHP (Laravel/Symfony) | Overhead innecesario para un puente de ~10 archivos. |
| Soporte multi-idioma | No agrega valor al caso de uso actual. |
| Modo espejo (vs archivo) | Decidido: trackerGram es **archivo inmutable con eventos**. Los editados/borrados se guardan como eventos adicionales, no modifican el original. |
| Reproducción de mensajes previos a nuevo tracker | El flujo real (export ZIP manual) ya cubre este caso. No agrega valor tenerlo integrado. |
| Long Polling (getUpdates) en vez de Webhook | Webhook + async worker es la arquitectura correcta para hosting compartido (sin proceso persistente). Long Polling requeriría proceso CLI long-running con supervisor/systemd. Descartado en code review Arq&Seg #10. |

---

## Diseños en Progreso

Los documentos en `design/` contienen exploraciones detalladas de features que están en discusión. Cuando un diseño madura lo suficiente (solo necesita retoques de implementación), pasa a la sección de Prioridades de este roadmap.

| Documento | Estado | Feature |
|-----------|--------|---------|
| `design/001-configuracion-inversa-via-telegram.md` | Exploración | Configurar trackerGram desde Telegram con comandos |
| `design/002-MiniApp.md` | Exploración | Frontend embebido para recolección estructurada de datos |
| `design/003-arquitectura-multi.md` | ✅ Implementado | Multi-conexión (routing, field mapping, etc.) |

Los reportes históricos en `reports/` se conservan como referencia de investigaciones pasadas. Los items accionables ya están consolidados en este documento.

> **Última actualización**: 06/07/2026
