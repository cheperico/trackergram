# Cambios - Changelog

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