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
| **Versión** | v0.1.8 |
| **Estado** | Beta funcional, desarrollo activo |
| **Metodología** | Director humano + agentes de IA |

### Qué funciona

- ✅ Webhook en tiempo real: mensajes de Telegram → TikiWiki
- ✅ Importación de exports ZIP de Telegram
- ✅ Soporte multimedia: fotos, videos, audio, documentos, stickers, notas de voz
- ✅ Topics (forums) de Telegram con resolución de nombres
- ✅ Reacciones a mensajes
- ✅ Service messages (creación de topics, miembros, pins, etc.)
- ✅ Creación automática de trackers en TikiWiki
- ✅ Panel de administración web
- ✅ Deduplicación de mensajes
- ✅ Seguridad: CSRF, rate limiting, hash de contraseñas, path traversal protection
- ✅ Reacciones formateadas como texto legible (👍 3 · ❤️ 1)
- ✅ Links clickeables en text_entities (importación de exports)
- ✅ log_message() siempre escribe a debug.log con rotación automática
- ✅ Pretty Tracker template (PRETTY_TRACKER.md)

---

## 2. Decisiones Arquitectónicas

### Sin base de datos local

trackerGram no tiene base de datos propia. TikiWiki es el almacenamiento. La contrapartida es que toda la lógica de deduplicación, búsqueda y persistencia depende de la API de TikiWiki.

### PHP puro, sin framework

No hay Laravel, Symfony ni Composer. Archivos PHP que se incluyen directamente. La deuda técnica (sin autoloading PSR-4, sin tests unitarios fáciles) está identificada en el roadmap.

### Inyección de dependencias (implementada ✅)

`TikiWikiClient`, `TelegramClient`, `WebhookHandler` y `MessageMapper` son **instanciables** con dependencias inyectadas por constructor. Bootstrap define el wiring en `bootstrap.php`:

```php
$tikiWikiClient = new TikiWikiClient(TIKIWIKI_API_URL, TIKIWIKI_TOKEN);
$telegramClient = new TelegramClient(TELEGRAM_BOT_TOKEN);
$messageMapper = new MessageMapper();
$webhookHandler = new WebhookHandler($tikiWikiClient, $telegramClient, $messageMapper, TIKIWIKI_TRACKER_ID);
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

Todos los archivos en la raíz. No hay subdirectorios de código.

#### Entry Points HTTP

| Archivo | Qué hace | Cuándo se ejecuta |
|---|---|---|
| `bootstrap.php` | Carga config + clientes + handler | Siempre, primer include |
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

#### Soporte

| Archivo | Qué es |
|---|---|
| `.env` | Variables de entorno (credenciales) — NO versionar |
| `.env.example` | Plantilla de variables de entorno |
| `.htaccess` | Apache: seguridad, rewrite, límites PHP |
| `topic_names.json` | Cache local de nombres de topics (auto-generado) |
| `debug.log` | Logs de debug (rotación automática a 10MB, siempre escribe) |

#### Documentación

| Archivo | Propósito |
|---|---|
| `README.md` | Usuario final: qué es, cómo se usa |
| `INSTALL.md` | Instalación: requisitos, pasos, configuración |
| `TECHNICAL.md` | Desarrolladores: cómo está construido, tutorial |
| `AGENTS.md` | **Este archivo** — contexto para agentes de IA |
| `roadmap.md` | Pendientes, prioridades, bugs conocidos |
| `CAMBIOS.md` | Historial de cambios por versión |
| `PRETTY_TRACKER.md` | Guía de instalación y template para Pretty Tracker en TikiWiki |

### Orden recomendado de lectura del código

1. `bootstrap.php` — wiring de DI, entendés cómo se conecta todo
2. `config.php` — cómo se leen las credenciales y constantes
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

### Webhook (tiempo real)

```
Telegram → api.php → $webhookHandler->processUpdate()
    → $webhookHandler->processMessage()
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

### Variables de entorno (.env)

```
TELEGRAM_BOT_TOKEN=...
TELEGRAM_WEBHOOK_SECRET=...
CUSTOM_WEBHOOK_URL=...
TIKIWIKI_API_URL=https://wiki.chela.org.ar/api/
TIKIWIKI_TOKEN=...
TIKIWIKI_TRACKER_ID=12
ADMIN_USERNAME=...
ADMIN_PASSWORD=...
DEBUG_MODE=false
ALLOWED_CHAT_IDS=...
```

### Constantes definidas en config.php

| Constante | Valor |
|---|---|
| `TELEGRAM_BOT_TOKEN` | del .env |
| `TELEGRAM_API_URL` | `https://api.telegram.org/bot{token}/` |
| `TELEGRAM_WEBHOOK_SECRET` | del .env |
| `TIKIWIKI_API_URL` | del .env |
| `TIKIWIKI_TOKEN` | del .env |
| `TIKIWIKI_TRACKER_ID` | del .env (default 12) |
| `TIMEOUT_TIKIWIKI_API` | 30 segundos |
| `TIMEOUT_TELEGRAM_API` | 5 segundos |
| `MEDIA_DOWNLOAD_MAX_SIZE` | 20 MB |
| `RETRY_MAX_ATTEMPTS` | 2 |
| `RETRY_DELAY_MICROSECONDS` | 100000 (0.1s) |

### Servidores conocidos

| Servicio | URL |
|---|---|
| trackerGram webhook | https://trackergram.chelachela.duckdns.org |
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
- **No crear variables globales** — Usar `static` dentro de funciones o pasar por parámetros.
- **No requerir archivos individuales** — Siempre usar `require_once 'bootstrap.php'` (en trackerGram).
- **No duplicar lógica de parsing entre webhook e import** — Ambos convergen en `MessageMapper::toWikiFields()` vía `NormalizedMessage`. Webhook usa `fromWebhook()`, import usa `fromExport()`.

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

### Prioridad Alta

- [ ] **Mini App para reportes estructurados**: Interfaz web dentro de Telegram para crear items con texto + fotos + audio + ubicación en un solo envío
- [ ] **Mensajes estructurados con prefijos**: Detectar y parsear mensajes con prefijos especiales (ej: GPS, alertas)
- [x] **Inyección de dependencias**: Refactorizar clases estáticas en instanciables ✅
- [x] **Unificar parsers de mensajes**: Definir modelo intermedio único (NormalizedMessage) ✅
- [ ] **Estandarizar manejo de errores**: Excepciones de dominio

### Prioridad Media

- [ ] Sistema de etiquetas (hashtags)
- [ ] Mensajes editados/borrados
- [ ] Importación asíncrona para exports grandes
- [ ] Múltiples chats con trackers separados
- [ ] Tests unitarios
- [ ] PSR-4 autoloading

### Service Messages pendientes

- [ ] `new_chat_photo` / `delete_chat_photo` en importación
- [ ] `remove_members` en webhook
- [ ] `joined` en webhook

---

## 11. Historial de Cambios

Ver [CAMBIOS.md](CAMBIOS.md) para el detalle completo por versión.

### Resumen

| Versión | Cambio principal |
|---|---|---|
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

## Mantenimiento de Este Archivo

Actualizar cuando:
1. Se agrega una funcionalidad principal
2. Se modifica la estructura de archivos
3. Se cambia la visión o filosofía del proyecto
4. Se alcanza una nueva versión
5. Un nuevo desarrollador o agente se une al proyecto

**No actualizar** por fixes de bugs menores — esos van a CAMBIOS.md.
