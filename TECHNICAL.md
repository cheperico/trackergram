# TECHNICAL.md — Cómo está construido trackerGram

> Este documento es para personas que quieren entender **cómo** y **por qué** se construyó trackerGram de esta manera. No es una referencia de funciones ni un manual de instalación. Es una explicación paso a paso del razonamiento detrás de cada decisión técnica. Si te enfrentás a un problema similar, este documento debería darte las herramientas para resolverlo.

---

## El Problema

Tenemos un grupo de Telegram donde se generan conversaciones importantes. Queremos que esas conversaciones vivan también en TikiWiki — un wiki con trackers — para que sean buscables, indexables y permanezcan accesibles fuera de Telegram.

El desafío: Telegram y TikiWiki hablan idiomas completamente distintos. Telegram envía webhooks con JSON. TikiWiki espera POSTs con campos específicos. Alguien tiene que traducir.

---

## Paso 1: Recibir mensajes de Telegram

### ¿Qué es un webhook?

Imaginate dos formas de saber si llegó correo:

1. **Polling**: Abrís tu casilla de correo cada 2 segundos para ver si hay algo nuevo. Ineficiente, lento, gastás recursos.
2. **Webhook**: El servidor de correo te avisa cuando llega un mensaje. Solo actuás cuando hay algo nuevo.

Telegram usa webhooks. Cuando alguien escribe en un grupo donde está tu bot, Telegram hace un POST a una URL que vos le diste. Esa URL es tu servidor, y el POST contiene un JSON con todos los datos del mensaje.

### El primer problema: la URL debe ser pública

Telegram no puede hacer un POST a `localhost`. Necesita una URL pública con HTTPS. Esto significa que tu servidor tiene que ser accesible desde internet.

Las opciones son:
- Un servidor con dominio propio y certificado SSL
- Un túnel como ngrok o Cloudflare Tunnel para desarrollo local
- DuckDNS u otro servicio de DNS dinámico con Let's Encrypt

### Cómo recibe el mensaje trackerGram (v0.4.0+ — multi-conexión)

El archivo `api.php` es el punto de entrada. Recibe updates de **todos los grupos** donde está el bot. No hay un webhook por grupo — el webhook es **uno solo por bot**.

#### Cómo se rutea cada mensaje a la conexión correcta

Cada update de Telegram incluye el `chat.id` del grupo que lo originó. `api.php` usa **dos datos** para encontrar la conexión:

1. `X-Telegram-Bot-Api-Secret-Token` (header) → identifica el bot (verifica que el request es legítimo)
2. `chat.id` (del JSON del update) → identifica el grupo

```
Update de GroupA con secret=XYZ
  → findAllByChatId(GroupA_id, XYZ)
  → busca conexiones con chat_id=GroupA_id AND webhook_secret=XYZ
  → encuentra CONEXIÓN A ✓

Update de GroupB con secret=XYZ
  → findAllByChatId(GroupB_id, XYZ)
  → busca conexiones con chat_id=GroupB_id AND webhook_secret=XYZ
  → encuentra CONEXIÓN B ✓
```

#### webhook_secret compartido

**Importante**: Un bot tiene UN solo webhook con UN solo `secret_token`. Si dos conexiones usan el mismo `bot_token`, deben compartir el mismo `webhook_secret`. Si no, la última en configurar el webhook deja a la otra afuera.

`ConfigManager` lo maneja automáticamente: al crear una conexión con un `bot_token` que ya existe, reusa el `webhook_secret` de la conexión existente.

#### Fan-out: mismo mensaje a múltiples trackers

Usando `findAllByChatId()`, si dos conexiones tienen el mismo `(chat_id, webhook_secret)` pero diferente `tracker_id`, el mismo mensaje se envía a ambos trackers. Útil para duplicar mensajes a wikis diferentes.

#### El código real

```php
$configManager = new ConfigManager();
$allFound = $configManager->findAllByChatId((int) $chatId, $secretToken);

if (empty($allFound)) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden: no connection for this chat']));
}

// Fan-out: procesar el update para TODAS las conexiones que matcheen
foreach ($allFound as $found) {
    $tikiClient = new TikiWikiClient(
        apiUrl: $found['tiki_api_url'],
        token: $found['tiki_api_token']
    );
    $tgClient = new TelegramClient(botToken: $found['bot_token']);
    
    $handler = new WebhookHandler(
        tikiWikiClient: $tikiClient,
        telegramClient: $tgClient,
        messageMapper: new MessageMapper(),
        trackerId: (int) $found['tracker_id']
    );
    $handler->processUpdate($update);
}
```

**Por qué está así**: `api.php` no tiene lógica de negocio. Solo valida que la petición venga de Telegram (secret token), limita la cantidad de peticiones (rate limiting), busca la conexión por `(chat_id, secret)`, y delega en `WebhookHandler`. Esto se hizo en la refactorización v0.1.7 — antes, `api.php` tenía cientos de líneas de lógica mezclada.

**Lección aprendida**: Separar el "recibir la petición" del "procesar los datos" hace que el código sea más fácil de entender y modificar.

---

## Paso 2: Entender el formato de los mensajes

### El JSON que envía Telegram

Cuando Telegram hace un POST, el cuerpo es algo así:

```json
{
  "update_id": 123456789,
  "message": {
    "message_id": 42,
    "from": { "id": 987654321, "first_name": "Juan", "username": "juan" },
    "chat": { "id": -1001234567890, "title": "Mi Grupo", "type": "supergroup" },
    "date": 1700000000,
    "text": "Hola grupo!"
  }
}
```

Pero esto es solo el caso más simple. Un mensaje puede tener:
- `photo` — un array de fotos (Telegram envía varias resoluciones)
- `video` — un video con su `file_id`
- `document` — un archivo adjunto
- `sticker` — un sticker
- `voice` — una nota de voz
- `location` — coordenadas GPS
- `forum_topic_created` — un mensaje de sistema que crea un topic
- `message_thread_id` — el ID del topic al que pertenece el mensaje
- Y muchos más...

### Cómo detectamos el tipo de mensaje

La clase `MessageMapper` tiene un método `fromWebhook()` que recibe el array del mensaje y devuelve un objeto `NormalizedMessage`:

```php
$normalized = $messageMapper->fromWebhook($message);
// $normalized->messageType = 'photo', 'video', 'text', 'system', etc.
// $normalized->fileId = el ID del archivo en Telegram
// $normalized->text = texto descriptivo
```

El enfoque es simple: chequear cada campo posible en orden de prioridad. Si tiene `photo`, es una foto. Si tiene `video`, es un video. Si no tiene ninguno de los campos conocidos, es "otro".

**Por qué no usar switch o match**: Porque los campos de Telegram no son mutuamente excluyentes. Un mensaje puede tener `document` Y `caption`. El orden de chequeo importa.

---

## Paso 3: Enviar a TikiWiki

### ¿Qué es un tracker en TikiWiki?

Un tracker es como una base de datos de formularios. Cada "item" es un registro con campos. Para trackerGram, cada mensaje de Telegram se convierte en un item del tracker.

Los campos del tracker tienen "permanent names" — identificadores únicos que la API usa para referirse a ellos. Por ejemplo: `telegrammessageTelegramMessageId`, `telegrammessageText`, etc.

### El mapeo de campos

`$messageMapper->toWikiFields()` toma un `NormalizedMessage` y lo convierte al formato que TikiWiki espera:

```php
$prefix = $this->getFieldPrefix(); // 'telegrammessage', 'qpch', etc.
$fields = [
    "fields[{$prefix}TelegramMessageId]" => 42,
    "fields[{$prefix}ChatId]" => -1001234567890,
    "fields[{$prefix}Text]" => 'Hola grupo!',
    // ...
];
```

**Por qué `fields[permName]`**: La API de TikiWiki no acepta JSON para crear items. Espera un POST con `application/x-www-form-urlencoded` donde cada campo tiene el formato `fields[nombreDelCampo]`. Esto es particular de TikiWiki y no es estándar en APIs REST.

### Auto-detección de field prefix

Cuando el tracker se crea con el botón "Crear Tracker", el usuario elige un prefix (ej: `soporte`, `qpch`, `equipo`). El prefix por defecto es `telegrammessage`.

El sistema **detecta automáticamente el prefix real** si el almacenado es el default (`telegrammessage`). `TikiWikiClient::resolveFieldPriority()`:
1. Si el prefix almacenado NO es `telegrammessage`, confía en él (el usuario lo configuró explícitamente).
2. Si es `telegrammessage` y el flag `field_prefix_checked` no existe, fetchea `GET /api/trackers/{id}/fields`.
3. Busca campos cuyos permNames terminen en sufijos conocidos y extrae el prefijo común.
4. Si el detectado difiere del almacenado, lo persiste a `setup.json` y marca `field_prefix_checked: true`.

**Cache**: La auto-detección se ejecuta UNA SOLA VEZ por conexión. El flag `field_prefix_checked` evita llamadas API en cada request. Esto cubre admin.php, api.php, worker.php e import.php.

### El cliente de TikiWiki

`$tikiWikiClient->createTrackerItem()` hace el POST a la API (los archivos multimedia se suben antes a la file gallery y se vinculan via el campo FG en `$fields`):

```php
$url = $this->apiUrl . "trackers/$trackerId/items";
// POST con http_build_query($fields)
// Header: Authorization: Bearer $TOKEN
```

> (En el código original esto era `TIKIWIKI_API_URL`, una constante global. Desde v0.4.0, la URL de la API viene de la conexión en `setup.json` y se inyecta en `TikiWikiClient`.)

**Lección aprendida**: TikiWiki a veces devuelve errores PHP como HTML con status 200. Por eso verificamos que la respuesta tenga `itemId` en JSON — si no lo tiene, algo falló aunque el HTTP diga 200.

---

## Paso 4: Manejar archivos multimedia

### El flujo

1. Telegram envía un `file_id` en el webhook
2. Pedimos a Telegram la URL de descarga (`getFile`)
3. Descargamos el archivo a un temp local
4. Lo subimos a la file gallery de TikiWiki
5. Vinculamos el archivo al campo `telegrammessageMedia` del tracker

### El problema del tamaño

Telegram permite archivos de hasta 20MB. Si descargamos un archivo de 20MB y lo cargamos entero en memoria, podemos saturar el servidor.

**Solución**: Verificamos el tamaño antes de descargar usando un HEAD request, y durante la descarga con streaming cancelamos si el contenido excede el límite:

```php
curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
$contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
if ($contentLength > MEDIA_DOWNLOAD_MAX_SIZE) {
    // Rechazar antes de descargar
}
```

Además, durante la descarga real con streaming, hay un callback que aborta si el acumulado supera el límite, manejando casos donde Telegram no reporta el `Content-Length` en el HEAD.

### Cómo encuentra TikiWiki dónde guardar el archivo

Cada tracker tiene una file gallery asociada en su configuración. `TikiWikiClient::getMediaGalleryId()` consulta la configuración del tracker, busca el campo `telegrammessageMedia` de tipo `FG` (File Gallery), y extrae el gallery ID de sus opciones.

**Optimización**: Este ID se cachea en memoria (`static $mediaGalleryIdCache`) para no consultar la API de TikiWiki en cada mensaje.

### Archivos excluidos en exports

Cuando se exporta un chat de Telegram sin incluir los archivos multimedia, los mensajes tienen texto como `(File not included. Change data exporting settings to download.)`. `MessageMapper::isMediaExcluded()` detecta este patrón y evita intentar subir archivos inexistentes. El mensaje se guarda igual, solo sin el campo Media.

---

## Paso 5: Los Topics (Forums)

### El problema

En grupos de Telegram con topics, cada mensaje tiene un `message_thread_id`. Pero Telegram **no envía el nombre del topic** en cada mensaje. Y no existe un método `getForumTopic` en la API de Telegram para obtener el nombre a partir del ID.

Entonces: ¿cómo sabemos el nombre del topic?

### La solución en 3 niveles

**Nivel 1: El mensaje de creación**

Cuando se crea un topic, Telegram envía un mensaje especial con `forum_topic_created` que incluye el nombre:

```json
{
  "message_thread_id": 42,
  "forum_topic_created": { "name": "Anuncios" }
}
```

Si un mensaje responde a este mensaje de creación (`reply_to_message.forum_topic_created`), podemos obtener el nombre.

**Nivel 2: Cache local**

Cuando detectamos un `forum_topic_created`, guardamos el nombre en un archivo `topic_names.json`:

```json
{
  "-1001234567890:42": "Anuncios",
  "-1001234567890:99": "General"
}
```

La clave es `chatId:messageThreadId`. Los mensajes posteriores leen de este archivo.

**Nivel 3: Fallback**

Si no hay cache ni mensaje de creación:
- Si hay `message_thread_id`: usamos `Topic-XX` como identificador
- Si no hay `message_thread_id`: usamos `General`

**Lección aprendida**: Intentamos usar `getChat()` de la API de Telegram para obtener info del topic, pero no funciona para forums. La cache local es la solución más confiable.

---

## Paso 6: Evitar duplicados

### El problema

Telegram puede enviar el mismo webhook dos veces. Si no verificamos, terminamos con mensajes duplicados en TikiWiki.

### La solución: deduplicación por (chat_id, message_id)

Antes de crear un item, verificamos si ya existe:

```php
if ($tikiWikiClient->messageExists($trackerId, $message['message_id'], $chatId) > 0) {
    return; // Ya existe, saltar
}
```

> (El `$trackerId` viene de la conexión; antes era la constante global `TIKIWIKI_TRACKER_ID`.)

`messageExists` hace un GET a la API de TikiWiki filtrando por `telegrammessageTelegramMessageId` Y `telegrammessageChatId`. Retorna la cantidad de items encontrados.

**Lección aprendida**: Al principio solo filtrábamos por `message_id`. Pero dos chats diferentes pueden tener mensajes con el mismo ID. La deduplicación correcta es por el par `(chat_id, message_id)`.

### Race conditions

Incluso con verificación previa, puede haber race conditions: dos webhooks del mismo mensaje llegan casi al mismo tiempo, ambos pasan la verificación, y ambos crean el item.

**Solución**: Verificación post-insert:

```php
if ($tikiWikiClient->messageExists(...) > 1) {
    log_message("WARNING: duplicado detectado post-insert — posible race condition", true);
}
```

Esto no previene el duplicado pero lo detecta para logging.

### Edit detection: capturar cambios en mensajes

Desde v0.5.11, trackerGram **no solo evita duplicados sino que detecta edits**:

**El problema**: Los mensajes de Telegram se pueden editar después de enviados. Si el webhook recibe un update con `edited_message`, queremos reflejar ese cambio en TikiWiki.

**La solución dual**:

1. **Webhook**: Cuando `api.php` recibe un update con `edited_message`, detecta que es una edición (el campo `edited_date` no es el mismo) y llama a `$tikiWikiClient->updateTrackerItem()` en vez de `createTrackerItem()`. Usa `toWikiFieldsEdit()` que solo genera los campos que pueden cambiar: `Text`, `EditedDate`, `Reactions`. **Nunca** toca `Media`, `MessageType` ni `Location` — eso sería destructivo.

2. **Import**: Durante la importación de un ZIP, `import.php` sigue el mismo patrón: antes de crear un item, llama a `findItemByMessageId()` para verificar si ya existe. Si existe, compara `editedDate` y decide:
   - Si difiere → `updateTrackerItem()` (edit detectado)
   - Si el item existente tiene `MessageType='other'` y su texto es "no capturada en tiempo real..." → es un poll placeholder del webhook. Lo enriquece con datos reales del export.

```php
$itemId = $tikiWikiClient->findItemByMessageId($trackerId, $msgId, $chatId);
if ($itemId) {
    // Existe → verificar si necesita update
    $existing = $tikiWikiClient->getTrackerItem($trackerId, $itemId);
    if (esEdit(...) or esPollEnrichment(...)) {
        $tikiWikiClient->updateTrackerItem($trackerId, $itemId, $editFields);
    }
} else {
    // No existe → crear
    $tikiWikiClient->createTrackerItem($trackerId, $fields);
}
```

**Métodos clave**:
- `findItemByMessageId()` — busca un item por `{prefix}TelegramMessageId` (y opcionalmente ChatId)
- `getTrackerItem()` — obtiene un item completo con todos sus fields (para comparar edit date)
- `updateTrackerItem()` — hace `POST /api/trackers/{id}/items/{itemId}` con solo los campos a cambiar
- `toWikiFieldsEdit()` — genera solo `Text`, `EditedDate`, `Reactions` (seguro para updates)

**Por qué `toWikiFieldsEdit()` es restringido**: Si el webhook capturó un mensaje con foto, y la importación del export no tiene la foto (porque se excluyeron del export), un update completo pisaría el media existente con vacío. EditFields protege contra eso.

### ReplyToId con texto del mensaje original

El campo `telegrammessageReplyToId` originalmente solo guardaba el ID del mensaje al que se respondía. Desde v0.5.7, también incluye el texto del mensaje original:

- **Webhook**: Telegram envía `reply_to_message.text` (o `caption`) **gratis** en el update. Extraemos el texto y lo concatenamos: `#42 - "texto del mensaje"`.
- **Import**: No tenemos acceso directo al texto. Resolvemos el reply buscando el ID en el tracker vía `TikiWikiClient::getTrackerItem()`.
- **Fallback**: Si no se encuentra el item referenciado, se guarda solo el texto sin referencia.

Esto permite ver el contexto de la respuesta sin tener que abrir el mensaje original.

---

## Paso 7: Reintentos

### El problema

La API de TikiWiki puede fallar temporalmente (timeout, error 500, etc.). No queremos perder un mensaje por un fallo transitorio.

### Retry en creación de items

```php
for ($i = 0; $i < RETRY_MAX_ATTEMPTS; $i++) {
    if ($tikiWikiClient->createTrackerItem(...)) {
        return true;
    }
    usleep(RETRY_DELAY_MICROSECONDS); // 0.1 segundos
}
```

**Lección aprendida**: Al principio usábamos `sleep(1)` — un segundo de espera. En un servidor con muchos mensajes, esto se acumula y satura. Cambiamos a `usleep(100000)` (0.1 segundos) que es suficiente para la mayoría de los casos sin bloquear.

### Retry en descarga de media

Desde v0.5.7, `downloadAndUploadMedia()` también tiene reintentos: hasta 3 intentos con backoff progresivo. Esto cubre fallos transitorios en la descarga de archivos de Telegram (timeouts de red, servidores temporariamente caídos). Si los 3 intentos fallan, el mensaje se guarda igual pero sin el archivo multimedia.

---

## Paso 8: Importar historial

### El formato de export de Telegram

Cuando exportás un chat desde Telegram, obtenés un ZIP con:
- `result.json` — todos los mensajes
- Archivos multimedia (fotos, videos, etc.)

El `result.json` tiene una estructura diferente al webhook:

```json
{
  "name": "Mi Grupo",
  "id": 1234567890,
  "messages": [
    {
      "id": 42,
      "type": "message",
      "from": "Juan",
      "date": "2024-01-15T10:30:00",
      "text": "Hola"
    }
  ]
}
```

### Las diferencias con el webhook

| Aspecto | Webhook | Export ZIP |
|---|---|---|
| ID del mensaje | `message_id` (int) | `id` (int) |
| Usuario | `from.id`, `from.first_name` | `from` (string), `from_id` (string) |
| Fecha | `date` (timestamp) | `date` (string ISO) |
| Archivos | `file_id` (referencia) | `photo`, `file` (nombres de archivo) |
| Topics | `message_thread_id` | `reply_to_message_id` → buscar en `topic_created` |

### Cómo resolvimos las diferencias

`import.php` tiene su propio parser porque el formato es distinto. Pero ambos caminos convergen en `MessageMapper::toWikiFields()` para enviar a TikiWiki.

**Optimización importante**: Al principio, para cada mensaje escaneábamos recursivamente todo el ZIP para encontrar el archivo multimedia. Con exports grandes, esto era lentísimo. La solución: indexar todos los archivos una sola vez al inicio:

```php
$fileIndex = [];
foreach ($dirIterator as $f) {
    $fileIndex[$f->getFilename()] = $f->getPathname();
}
// Luego: $filePath = $fileIndex[$fileName] ?? '';
```

De O(n × m) a O(n + m).

### Chat_id y IDs negativos en exports

El export de Telegram Desktop tiene dos peculiaridades importantes:

1. **Chat_id sin prefijo `-100`**: Para supergrupos, el `id` raíz del `result.json` viene sin el prefijo `-100` (ej: `4299700952` en vez de `-1004299700952`). Un fix en `import.php` detecta `private_supergroup`/`private_channel` y antepone `-100` automáticamente. El webhook no tiene este problema porque recibe el chat_id directo de la Bot API.

2. **IDs de mensaje negativos**: Los mensajes del grupo **antes** de una migración a supergrupo tienen IDs negativos (ej: `-999907142`). Los IDs positivos comienzan después de la migración. Esto no es un error de Telegram — es su forma de distinguir el período pre-migración. La deduplicación por `(chat_id, message_id)` funciona correctamente porque los rangos negativos y positivos no se solapan.

3. **Service messages de migración**: La frontera entre pre y post-migración está marcada por eventos `migrate_to_supergroup` (en el grupo viejo) y `migrate_from_group` (en el supergrupo nuevo). Ambos se importan correctamente pero no se pueden recibir por webhook (el webhook solo ve el supergrupo post-migración).

### Polls: webhook placeholder vs import enriquecido

**El problema**: La Telegram Bot API **no envía los votos** de una encuesta en tiempo real. Cuando un `poll` llega por webhook, solo tiene la pregunta y las opciones, pero `total_voter_count = 0`. Guardar eso en TikiWiki es casi inútil.

**La solución híbrida**:

| Camino | Qué se guarda |
|---|---|
| **Webhook** | `MessageType = 'other'` — se marca con texto "Encuesta no capturada en tiempo real. Usar importación de export ZIP para ver resultados completos." |
| **Import** | `MessageType = 'poll'` — se parsean los `answers[]` del export con su `voters` real. El texto generado: `📊 Pregunta\n• Opción A: 5 votos\n• Opción B: 3 votos\nTotal: 8 votos` |

**Enriquecimiento post-import**: Si el mensaje ya existe en el tracker (capturado por webhook), `import.php` lo detecta como poll placeholder (por `MessageType='other'` y contenido "no capturada...") y llama a `updateTrackerItem()` para reemplazar el texto con los datos reales y corregir `MessageType` a `poll`.

El método `fromExport()` en `MessageMapper` soporta dos formatos de export:
- **Schema oficial**: `answers[]` con `voters` (Telegram Desktop moderno)
- **Fallback legacy**: `options[]` (exports más antiguos)

---

## Arquitectura del Proyecto

### Diagrama de flujo

```
Telegram
    ↓ (webhook POST)
api.php ← valida token, rate limit
    ↓
WebhookHandler::processUpdate()
    ↓ (dispatch)
WebhookHandler::processMessage()
    ↓
1. Validar campos requeridos
2. Resolver topic (cache → fallback)
3. Resolver reply: buscar itemId en tracker + extraer texto del original
4. Verificar duplicado
5. MessageMapper::fromWebhook() → extraer datos
6. downloadAndUploadMedia() → descargar de Telegram, subir a TikiWiki
7. sendToTikiWikiWithRetries() → crear item con reintentos
```

### Cómo se relacionan los archivos (v0.4.0+)

```
bootstrap.php
    ├── config.php          → carga .env, define constantes globales
    ├── NormalizedMessage.php
    ├── TikiWikiClient.php  → comunicación con TikiWiki
    ├── TelegramClient.php  → comunicación con Telegram
    ├── MessageMapper.php   → transformación de datos
    └── WebhookHandler.php  → orquesta todo

api.php → ConfigManager → clientes por conexión → WebhookHandler::processUpdate()
admin.php → ConfigManager → clientes por conexión (test, create)
import.php → clientes locales desde formulario → MessageMapper::toWikiFields()
worker.php → ConfigManager → clientes por conexión → WebhookHandler
```

**No hay un wiring central**. Cada entry point crea sus propios clientes desde las credenciales de la conexión en `setup.json`. Esto permite tener múltiples bots, wikis y trackers desde una misma instalación.

### Deuda técnica (v0.5.11)

Items **ya resueltos**:
- ✅ **Inyección de dependencias**: Clases instanciables con dependencias inyectadas por constructor, sin wiring central en bootstrap.
- ✅ **Modelo intermedio único**: `NormalizedMessage` entre ambos parsers.
- ✅ **Multi-conexión**: Múltiples bots, wikis y trackers desde una instalación.
- ✅ **Async per-conexión**: Cada conexión puede procesar síncrona o asíncronamente.
- ✅ **Sin modo legacy**: No hay constantes de credenciales en `.env`; todo viaja en `setup.json`.
- ✅ **Health check en admin**: Cada tarjeta de conexión muestra estado del webhook vía `getWebhookInfo()`.
- ✅ **FG field options verificación**: `updateFgFieldOptions()` verifica con GET /fields si el galleryId se guardó (workaround bug TikiWiki).
- ✅ **Auto-detección field prefix**: El sistema detecta el prefix real del tracker y corrige `setup.json` automáticamente.
- ✅ **Cache field_prefix_checked**: La auto-detección se ejecuta UNA vez, no en cada page load.
- ✅ **Fan-out con try-catch**: Si una conexión falla en el fan-out, no rompe las demás.
- ✅ **Reintentos en downloadAndUploadMedia**: Hasta 3 intentos con backoff progresivo para evitar pérdidas transitorias.
- ✅ **Chat_id -100 en import**: Detección de supergrupos y corrección del prefijo.
- ✅ **ReplyToId con texto del original**: Webhook aprovecha `reply_to_message.text` (gratis), import busca por API.
- ✅ **Field descriptions enviadas a TikiWiki**: Todos los campos del tracker se crean con `description` en la API.
- ✅ **Edit detection**: Edits de Telegram reflejados en TikiWiki via `updateTrackerItem()` + `toWikiFieldsEdit()`.
- ✅ **Polls enriquecidos**: Webhook guarda placeholder "usar import", import enriquece con voters reales.
- ✅ **Dedup con edit detection en import**: `findItemByMessageId()` antes de crear, actualiza si existe y cambió.
- ✅ **Freetags para hashtags**: `#tags` extraídos como campo tipo `F` en webhook e import.
- ✅ **Botón Sync en admin**: Crea campos faltantes del tracker automáticamente.
- ✅ **checkPermissions sin side effects**: Test de permisos usa `DELETE /api/galleries/99999999/delete` (no crea galerías reales).
- ✅ **Webhook_secret compartido**: Conexiones con mismo `bot_token` reusan el mismo `webhook_secret`.
- ✅ **BUG-001 fix**: `findByWebhookSecret()` prioriza conexiones pendientes; `assignDetection()` no sobrescribe `chat_id`.
- ✅ **Media upload timeout separado**: 60s para upload vs 30s para API general.
- ✅ **Accesibilidad ARIA completa**: Roles, landmarks, focus trap, aria-live en admin.php.

Items aún pendientes:
- ⬜ **Race condition en rate limiting**: `api.php` escribe archivos rate limit sin `LOCK_EX`. Potencial corrupción bajo alta concurrencia.
- ⬜ **Race condition en ConfigManager::load()**: Sin flock, puede leer datos inconsistentes si dos procesos escriben simultáneamente.
- ⬜ **TOCTOU en dedup webhook**: Entre `messageExists()` y `createTrackerItem()` hay una ventana donde otro webhook puede insertar el mismo mensaje.
- ⬜ **DNS Rebinding SSRF**: `TikiWikiClient` valida URL pero no resuelve contra IPs internas. Proteger con `CURLOPT_RESOLVE`.
- ⬜ **GC de archivos rate limit**: Los archivos de rate limiting se acumulan. No hay cleanup automático.
- ⬜ **Manejo de errores inconsistente**: Algunas funciones retornan `null`, otras `false`, otras usan `die()`.
- ⬜ **Backoff exponencial en GET**: `messageExists()` y `getTrackerItem()` hacen GET sin backoff. Bajo error 429/503, saturan la API.
- ⬜ **Tests unitarios**: Las clases son instanciables y testeables, pero faltan los tests. JsonFileStorage utility como primer candidato.
- ⬜ **PSR-4 autoloading**: Sin autoloader, todo se incluye con `require_once`.

---

## Seguridad: Lo que aprendimos

### Secret Token del Webhook

Telegram permite configurar un `secret_token` que se envía en el header `X-Telegram-Bot-Api-Secret-Token`. Esto verifica que la petición realmente viene de Telegram y no de alguien que descubrió tu URL de webhook.

**Sin esto**: Cualquiera que conozca tu URL puede enviar mensajes falsos.

### CSRF en el Admin Panel

El panel de administración (`admin.php`) genera un token CSRF por sesión que se incluye en cada formulario. Sin este token, las peticiones POST son rechazadas.

**Sin esto**: Un atacante podría hacer que un admin logueado ejecute acciones sin saberlo.

### Hash de Contraseñas

Las contraseñas de admin se almacenan como hash bcrypt (`$2y$...`). Si la contraseña está en texto plano (versiones viejas), se convierte a hash automáticamente en el primer login.

### Path Traversal en ZIPs

Al extraer un ZIP, verificamos que ningún archivo tenga `..` en su nombre o comience con `/`. Sin esto, un ZIP malicioso podría sobrescribir archivos del servidor.

### Rate Limiting

Tanto el webhook como el login del admin tienen rate limiting por IP. Sin esto, un atacante podría hacer fuerza bruta o saturar el servidor.

---

---

## Lecciones Aprendidas

### Problemas que surgieron y cómo los resolvimos

| Problema | Causa | Solución |
|---|---|---|
| Mensajes duplicados | Deduplicación solo por `message_id` | Deduplicar por par `(chat_id, message_id)` |
| ModSecurity bloqueaba peticiones | Sin User-Agent en curl | Agregar `User-Agent: Mozilla/5.0` |
| TypeError en `getTopicName` | Cache leída como string vacío | Validar tipo antes de acceder |
| `getForumTopic` no funciona | No existe en la API de Telegram | Cache local + fallback |
| Import lento en exports grandes | Escaneo recursivo por cada mensaje | Indexar archivos una sola vez |
| Token de Telegram en logs | URLs de descarga contenían el token | Descargar por proxy, no loguear URLs |
| XSS en admin panel | `innerHTML` con datos del usuario | Cambiar a `textContent` |
| Password en texto plano | Sin hash en `.env` | `password_hash()` con bcrypt |
| Path traversal en ZIP | Sin validación de nombres de archivo | Verificar que no contengan `..` |
| Race conditions en duplicados | Dos webhooks simultáneos | Verificación post-insert |
| `sleep(1)` bloqueaba mucho | Reintentos con sleep de 1 segundo | Cambiar a `usleep(100000)` |
| Doble `/api.php` en URL | Bug en `generateWebhookUrl()` | Corregir lógica de construcción |

### Lo que haríamos diferente

1. ✅ ~~Inyección de dependencias desde el inicio~~ — **Ya implementado** (v0.2.0)
2. ⬜ **Tests unitarios desde el principio**: Muchos bugs se hubieran detectado automáticamente.
3. ✅ ~~Un modelo intermedio único para mensajes~~ — **Ya implementado** (NormalizedMessage, v0.1.9)
4. ⬜ **Excepciones de dominio**: En vez de mezclar `null`, `false` y `die()`, usar excepciones tipadas.

---

## Apéndice: Schema completo del tracker para trackerGram

Este apéndice describe **qué campos debe tener un tracker de TikiWiki** para ser compatible con trackerGram, y cómo crearlo manualmente (sin usar el botón "Crear Tracker" del admin).

### Requisitos del tracker

| Aspecto | Requisito |
|---|---|
| **Campos** | Debe tener **los 26 campos** listados abajo. Faltan → sincronizables con botón 🛠️ Sync. Sobran → no importa. |
| **Field prefix** | El permName de cada campo sigue el patrón `{prefix} + Sufijo`. El prefix por defecto es `telegrammessage`, pero puede ser cualquiera (ej: `soporte`, `qpch`, `equipo`). |
| **Auto-detección** | Si el prefix storeado es `telegrammessage`, el sistema lo verifica contra los campos reales vía API y lo corrige automáticamente. |
| **File Gallery** | El campo `{prefix}Media` (tipo `FG`) necesita un gallery ID asignado. Al crear el tracker desde el admin, se crea una galería automática. |
| **Dropdown MessageType** | El campo `{prefix}MessageType` (tipo `D`) debe tener las options: `["text","photo","video","audio","document","sticker","voice","video_note","system","animation","contact","poll","location","other"]`. |
| **Mandatory** | Solo `{prefix}TelegramMessageId` es obligatorio (isMandatory). Los demás pueden estar vacíos. |

### Lista completa de campos

| # | PermName (sufijo) | Tipo | Descripción | Main | Mandatory | Searchable | TblVisible |
|---|---|---|---|---|---|---|---|
| 1 | `TelegramMessageId` | `t` (text) | ID único del mensaje en Telegram | ✅ | ✅ | ✅ | ✅ |
| 2 | `ChatId` | `t` (text) | ID del chat/grupo en Telegram | | | | |
| 3 | `ChatTitle` | `t` (text) | Título del chat o grupo | | | ✅ | ✅ |
| 4 | `TopicId` | `t` (text) | ID del tema/foro (0 si General) | | | | |
| 5 | `TopicTitle` | `t` (text) | Nombre del tema/foro | | | ✅ | ✅ |
| 6 | `UserId` | `t` (text) | ID numérico del usuario | | | | |
| 7 | `Username` | `t` (text) | @username del usuario | | | ✅ | ✅ |
| 8 | `FirstName` | `t` (text) | Nombre (en import: display name completo) | | | | |
| 9 | `LastName` | `t` (text) | Apellido (solo webhook) | | | | |
| 10 | `DisplayName` | `t` (text) | Nombre completo para mostrar (unificado) | | | ✅ | ✅ |
| 11 | `MessageType` | `D` (dropdown) | Tipo de mensaje (ver options arriba) | | | | |
| 12 | `Text` | `a` (textarea) | Contenido del mensaje (incluye captions) | | | ✅ | ✅ |
| 13 | `MessageDate` | `f` (datetime) | Fecha/hora (timestamp UNIX) | | | ✅ | ✅ |
| 14 | `Media` | `FG` (file gallery) | Archivo multimedia adjunto | | | | ✅ |
| 15 | `MediaUrl` | `t` (text) | URL pública del archivo en TikiWiki | | | | |
| 16 | `FileUrl` | `t` (text) | URL original en Telegram | | | | |
| 17 | `MediaType` | `t` (text) | Tipo MIME del archivo (ej: image/jpeg) | | | | |
| 18 | `MediaSize` | `n` (number) | Tamaño del archivo en bytes | | | ✅ | |
| 19 | `MediaCaption` | `t` (text) | Descripción asociada al media | | | | |
| 20 | `MediaWidth` | `n` (number) | Ancho en píxeles | | | | |
| 21 | `MediaHeight` | `n` (number) | Alto en píxeles | | | | |
| 22 | `MediaDuration` | `DUR` (duration) | Duración en segundos (hh:mm:ss) | | | | |
| 23 | `Location` | `G` (geolocation) | Coordenadas GPS (lon, lat, zoom) | | | | ✅ |
| 24 | `EditedDate` | `t` (text) | Timestamp UNIX de última edición | | | | |
| 25 | `ReplyToId` | `t` (text) | ID del mensaje al que responde | | | | |
| 26 | `Reactions` | `a` (textarea) | Reacciones formateadas (👍 3 · ❤️ 1) | | | | ✅ |
| 27 | `Hashtags` | `F` (freetags) | Hashtags como etiquetas (sin #) | | | ✅ | ✅ |

### INI para importar campos manualmente en TikiWiki

Si querés crear el tracker manualmente desde **Admin → Trackers → Crear/Editar → Importar campos**, copiá este bloque INI completo:

```ini
[FIELD1]
name = telegram_message_id
permName = telegrammessageTelegramMessageId
type = t
description = ID único del mensaje en Telegram
isMain = y
isMandatory = y
isTblVisible = y
isSearchable = y
isPublic = y
isHidden = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD2]
name = chat_id
permName = telegrammessageChatId
type = t
description = ID del chat/grupo en Telegram
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD3]
name = chat_title
permName = telegrammessageChatTitle
type = t
description = Título del chat o grupo
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD4]
name = topic_id
permName = telegrammessageTopicId
type = t
description = ID del tema o foro (0 si es General)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD5]
name = topic_title
permName = telegrammessageTopicTitle
type = t
description = Nombre del tema o foro
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD6]
name = message_date
permName = telegrammessageMessageDate
type = f
description = Fecha/hora del mensaje (timestamp UNIX)
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD7]
name = user_id
permName = telegrammessageUserId
type = t
description = ID numérico del usuario que envió el mensaje
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD8]
name = username
permName = telegrammessageUsername
type = t
description = @username del usuario en Telegram
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD9]
name = first_name
permName = telegrammessageFirstName
type = t
description = Nombre del usuario (en import: display name completo)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD10]
name = last_name
permName = telegrammessageLastName
type = t
description = Apellido del usuario (solo disponible en webhook)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD11]
name = display_name
permName = telegrammessageDisplayName
type = t
description = Nombre completo para mostrar (unificado webhook e import)
isMain = n
isSearchable = y
isTblVisible = y
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD12]
name = message_type
permName = telegrammessageMessageType
type = D
options = {"options":["text","photo","video","audio","document","sticker","voice","video_note","system","animation","contact","poll","location","other"]}
description = Tipo de mensaje: text, photo, video, audio, document, sticker, voice, system, etc.
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD13]
name = text
permName = telegrammessageText
type = a
description = Contenido textual del mensaje (incluye captions de media)
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD14]
name = media
permName = telegrammessageMedia
type = FG
options = {"galleryId":0}
description = Archivo multimedia adjunto (referencia a File Gallery de TikiWiki)
isTblVisible = y
isMain = n
isSearchable = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD15]
name = media_url
permName = telegrammessageMediaUrl
type = t
description = URL pública del archivo multimedia en TikiWiki
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD16]
name = file_url
permName = telegrammessageFileUrl
type = t
description = URL original del archivo en los servidores de Telegram
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD17]
name = media_type
permName = telegrammessageMediaType
type = t
description = Tipo MIME del archivo adjunto (ej: image/jpeg, video/mp4)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD18]
name = media_size
permName = telegrammessageMediaSize
type = n
description = Tamaño del archivo adjunto en bytes
isSearchable = y
isMain = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD19]
name = media_caption
permName = telegrammessageMediaCaption
type = t
description = Texto de descripción asociado al archivo multimedia
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD20]
name = message_Location
permName = telegrammessageLocation
type = G
description = Coordenadas GPS del mensaje (formato: lon, lat, zoom)
isTblVisible = y
isMain = n
isSearchable = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD21]
name = media_width
permName = telegrammessageMediaWidth
type = n
description = Ancho de la imagen/video en píxeles
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD22]
name = media_height
permName = telegrammessageMediaHeight
type = n
description = Alto de la imagen/video en píxeles
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD23]
name = media_duration
permName = telegrammessageMediaDuration
type = DUR
description = Duración del audio/video/voice en segundos (se muestra como hh:mm:ss)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD24]
name = edited_date
permName = telegrammessageEditedDate
type = t
description = Fecha de última edición (timestamp UNIX, vacío si no fue editado)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD25]
name = reply_to_id
permName = telegrammessageReplyToId
type = t
description = ID del mensaje al que responde (para conversaciones en hilo)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD26]
name = reactions
permName = telegrammessageReactions
type = a
description = Reacciones al mensaje formateadas como texto (ej: 👍 3 · ❤️ 1)
isTblVisible = y
isMain = n
isSearchable = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD27]
name = hashtags
permName = telegrammessageHashtags
type = F
description = Hashtags de Telegram como etiquetas (espacio-separados, sin #)
isTblVisible = y
isMain = n
isSearchable = y
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y
```

> **Nota**: Reemplazá `telegrammessage` por tu field prefix si usás uno custom. Reemplazá `{"galleryId":0}` con el ID real de tu file gallery en TikiWiki.

---

## Referencias

| Recurso | URL |
|---|---|
| Telegram Bot API | https://core.telegram.org/bots/api |
| TikiWiki API | https://doc.tiki.org/API |
| Webhooks (explicación general) | https://webhooks.fyi/ |
