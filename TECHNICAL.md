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

### Cómo recibe el mensaje trackerGram

El archivo `api.php` es el punto de entrada. Es intencionalmente simple:

```php
require_once 'bootstrap.php';

// Validar secret token
$secretToken = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals(TELEGRAM_WEBHOOK_SECRET, $secretToken)) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

// Rate limiting
// ...

// Delegar en WebhookHandler (inyectado en bootstrap.php)
$webhookHandler->processUpdate($update);
```

**Por qué está así**: `api.php` no tiene lógica de negocio. Solo valida que la petición venga de Telegram (secret token), limita la cantidad de peticiones (rate limiting), y delega en `WebhookHandler`. Esto se hizo en la refactorización v0.1.7 — antes, `api.php` tenía cientos de líneas de lógica mezclada.

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
$fields = [
    'fields[telegrammessageTelegramMessageId]' => 42,
    'fields[telegrammessageChatId]' => -1001234567890,
    'fields[telegrammessageText]' => 'Hola grupo!',
    // ...
];
```

**Por qué `fields[permName]`**: La API de TikiWiki no acepta JSON para crear items. Espera un POST con `application/x-www-form-urlencoded` donde cada campo tiene el formato `fields[nombreDelCampo]`. Esto es particular de TikiWiki y no es estándar en APIs REST.

### El cliente de TikiWiki

`$tikiWikiClient->createTrackerItem()` hace el POST a la API (o `createTrackerItemWithMedia()` si incluye archivos multimedia):

```php
$url = TIKIWIKI_API_URL . "trackers/$trackerId/items";
// POST con http_build_query($fields)
// Header: Authorization: Bearer $TOKEN
```

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

**Solución**: Verificamos el tamaño antes de descargar usando un HEAD request:

```php
curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
$contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
if ($contentLength > MEDIA_DOWNLOAD_MAX_SIZE) {
    // Rechazar
}
```

Esto evita descargar archivos que exceden el límite.

### Cómo encuentra TikiWiki dónde guardar el archivo

Cada tracker tiene una file gallery asociada en su configuración. `TikiWikiClient::getMediaGalleryId()` consulta la configuración del tracker, busca el campo `telegrammessageMedia` de tipo `FG` (File Gallery), y extrae el gallery ID de sus opciones.

**Optimización**: Este ID se cachea en memoria (`static $mediaGalleryIdCache`) para no consultar la API de TikiWiki en cada mensaje.

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
if ($tikiWikiClient->messageExists(TIKIWIKI_TRACKER_ID, $message['message_id'], $chatId) > 0) {
    return; // Ya existe, saltar
}
```

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

---

## Paso 7: Reintentos

### El problema

La API de TikiWiki puede fallar temporalmente (timeout, error 500, etc.). No queremos perder un mensaje por un fallo transitorio.

### La solución

```php
for ($i = 0; $i < RETRY_MAX_ATTEMPTS; $i++) {
    if ($tikiWikiClient->createTrackerItem(...)) {
        return true;
    }
    usleep(RETRY_DELAY_MICROSECONDS); // 0.1 segundos
}
```

**Lección aprendida**: Al principio usábamos `sleep(1)` — un segundo de espera. En un servidor con muchos mensajes, esto se acumula y satura. Cambiamos a `usleep(100000)` (0.1 segundos) que es suficiente para la mayoría de los casos sin bloquear.

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
3. Verificar duplicado
4. MessageMapper::fromWebhook() → extraer datos
5. downloadAndUploadMedia() → descargar de Telegram, subir a TikiWiki
6. sendToTikiWikiWithRetries() → crear item con reintentos
```

### Cómo se relacionan los archivos

```
bootstrap.php
    ├── config.php          → carga .env, define constantes
    ├── TikiWikiClient.php  → comunicación con TikiWiki
    ├── TelegramClient.php  → comunicación con Telegram
    ├── MessageMapper.php   → transformación de datos
    └── WebhookHandler.php  → orquesta todo

api.php → bootstrap.php → $webhookHandler->processUpdate()
admin.php → bootstrap.php → $tikiWikiClient->createTracker()
import.php → bootstrap.php → $messageMapper->toWikiFields() + $tikiWikiClient
```

### Deuda técnica (actual — v0.2.2)

Items **ya resueltos** en versiones recientes:
- ✅ **Inyección de dependencias**: `TikiWikiClient`, `TelegramClient`, `WebhookHandler` y `MessageMapper` son instanciables con dependencias inyectadas por constructor. Ver `bootstrap.php` para el wiring.
- ✅ **Modelo intermedio único**: `NormalizedMessage` es el modelo único entre ambos parsers. Webhook usa `fromWebhook()`, import usa `fromExport()`, ambos convergen en `toWikiFields()`.

Items aún pendientes:
- ⬜ **Manejo de errores inconsistente**: Algunas funciones retornan `null`, otras `false`, otras usan `die()`. Pendiente migrar a excepciones de dominio (ver roadmap).
- ⬜ **Tests unitarios**: Las clases son instanciables y testeables, pero faltan los tests.
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

## Ejecución y Debug con Docker

> Esta sección es informal. La iremos mejorando con el tiempo.

trackerGram se ejecuta en un contenedor Docker con Apache + PHP. Los comandos más útiles:

```bash
# Ver los últimos 30 logs
docker compose logs --tail 30

# Ver logs en tiempo real
docker compose logs -f

# Reiniciar el contenedor
docker compose down
docker compose up -d

# Ver logs de un servicio específico
docker compose logs -f trackergram
```

### Permisos de archivos

Docker y el host pueden tener conflictos de permisos. Los archivos que trackerGram necesita escribir (`.env`, `topic_names.json`, `debug.log`) deben tener permisos adecuados:

```bash
# Si hay problemas de permisos en Linux:
sudo chown -R www-data:www-data /ruta/a/trackergram
sudo chmod -R 755 /ruta/a/trackergram
sudo chmod 600 /ruta/a/trackergram/.env
```

Si el contenedor no puede escribir en `.env` o `topic_names.json`, el panel de administración fallará al guardar configuración.

### DEBUG_MODE

Activar `DEBUG_MODE=true` en `.env` para ver logs detallados en `debug.log`:

```bash
# Ver logs en tiempo real
docker compose logs -f
tail -f debug.log
```

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

## Referencias

| Recurso | URL |
|---|---|
| Telegram Bot API | https://core.telegram.org/bots/api |
| TikiWiki API | https://doc.tiki.org/API |
| Webhooks (explicación general) | https://webhooks.fyi/ |
