# 004 — Trabajo sobre mensajes existentes (reply, edit, delete)

> **Fecha**: 20/06/2026
> **Estado**: Diseño — Fase 1 implementada ✅
> **Sesión**: Orquestador principal con usuario (cheperico)
> **Tags**: reply-to, edit, delete, mensajes, tracker

---

## Índice

1. [Contexto](#1-contexto)
2. [Problema general](#2-problema-general)
3. [Fase 1 — Reply-to: resolución de referencias](#3-fase-1--reply-to-resolución-de-referencias)
4. [Fase 2 — Editar mensajes (implementado)](#4-fase-2--editar-mensajes-implementado-en-v0511-v0512)
5. [Fase 3 — Borrar mensajes (postergado)](#5-fase-3--borrar-mensajes-postergado)
6. [Visualización (capa de instalación/deployment)](#6-visualización-capa-de-instalacióndeployment)
7. [Resumen de tareas](#7-resumen-de-tareas)
8. [Decisiones de diseño](#8-decisiones-de-diseño)

---

## 1. Contexto

trackerGram captura mensajes de Telegram y los almacena en un tracker de TikiWiki. Hasta ahora el flujo es estrictamente **append-only**: cada mensaje nuevo → un item nuevo en el tracker.

Sin embargo, los mensajes de Telegram no son estáticos. Pueden:

1. **Referenciar** a otro mensaje (reply-to)
2. **Modificarse** después de publicados (edited)
3. **Eliminarse** (deleted)

Cada una de estas operaciones implica **trabajar sobre mensajes que ya existen en el tracker**: ya sea para vincularlos, actualizarlos o marcarlos.

---

## 2. Problema general

| Operación | Webhook | Import | ¿Qué pasa hoy? |
|-----------|---------|--------|----------------|
| **Responder** | ✅ Guarda `replyToId` | ✅ Guarda `replyToId` | Guarda el message_id de Telegram como texto plano — no lleva al mensaje original |
| **Editar** | ✅ Implementado | ✅ Implementado | `edited_message` se rutea a `processEditedMessage()` desde v0.5.12 |
| **Borrar** | ❌ N/A | ❌ N/A | Telegram no notifica borrados en grupos; exports no incluyen mensajes borrados |

### 2.1 Principio general

trackerGram **no modifica items existentes** — sigue siendo append-only. Las excepciones se evalúan caso por caso:

| Operación | ¿Modifica un item existente? | Excepción |
|-----------|------------------------------|-----------|
| Reply-to | ❌ No. Solo afecta al mensaje nuevo, no al referenciado | — |
| Editar | ✅ Sí. Hay que actualizar el texto/edit_date del item | Requiere nueva capacidad en TikiWikiClient |
| Borrar | ✅ Sí. Marcar/eliminar el item | Requiere nueva capacidad + mecanismo de detección |

---

## 3. Fase 1 — Reply-to: resolución de referencias

### 3.1 Problema específico

Cuando un usuario responde a un mensaje en Telegram, el payload incluye `reply_to_message.message_id` (ej: `4823`). Hoy trackerGram lo guarda como texto plano en `telegrammessageReplyToId`.

El campo termina siendo un número sin contexto ni link. No se puede saber a qué mensaje referencia sin buscar manualmente en el tracker.

### 3.2 Solución

Resolver el `message_id` de Telegram al `itemId` del tracker **en el momento de captura**:

```
1. Llega mensaje con reply_to_message.message_id = 4823
2. Buscar en el tracker:
   GET /api/trackers/{id}/items?filter[fields][TelegramMessageId]=4823&filter[fields][ChatId]=-100123
3.   ✅ Si existe un item → guardar #127 (itemId del tracker)
4.   ❌ Si no existe   → guardar 4823 (raw message_id, fallback)
```

### 3.3 Formato de almacenamiento

| Se resolvió | Guarda | Significado |
|-------------|--------|-------------|
| ✅ Sí | `#127` | Item ID del tracker (link directo) |
| ❌ No | `4823` | Telegram message_id (búsqueda) |

El prefijo `#` distingue visualmente un itemId de un message_id. No se requiere campo nuevo — se reutiliza `telegrammessageReplyToId`.

### 3.4 Límite por chat_id

La búsqueda usa `(chat_id, message_id)` porque el `message_id` solo es único dentro de cada chat. Sin esto, dos grupos distintos con mensajes de ID `4823` causarían falsos positivos.

### 3.5 Limitación conocida

Si el mensaje original fue procesado vía async (worker.php) o se va a importar después con un ZIP, **todavía no existe en el tracker** cuando llega el reply. En ese caso cae al fallback (message_id sin resolver).

Posible mejora futura: resolución diferida.

---

## 4. Fase 2 — Editar mensajes (implementado en v0.5.11-0.5.12)

> **Estado**: ✅ Implementado. Ver `TikiWikiClient::updateTrackerItem()`, `MessageMapper::toWikiFieldsEdit()`, `WebhookHandler::processEditedMessage()`.

### 4.1 El problema

Telegram envía `edited_message` cuando un usuario modifica un mensaje. Desde v0.5.12 se rutea a `processEditedMessage()` en vez de ignorarse.

El tracker muestra el texto original, pero en Telegram el mensaje fue actualizado. Hay una discrepancia.

### 4.2 Lo que implicaría

| Aspecto | Impacto |
|---------|---------|
| **Arquitectura** | trackerGram es append-only → habría que agregar `updateTrackerItem()` en TikiWikiClient |
| **API de TikiWiki** | Requiere verificar si existe `PUT /api/trackers/{id}/items/{itemId}` o similar |
| **Deduplicación** | Ya existe `messageExists()` → necesitamos `findItemByMessageId()` que devuelva el itemId (útil también para reply-to) |
| **Permisos** | Requiere `tiki_p_modify_tracker_items` (no está en la lista actual) |
| **Qué campos actualizar** | `text`/`caption`, `editedDate` (el resto no cambia: autor, fecha, tipo, media) |
| **Import** | Los exports de Telegram incluyen `edited_unixtime` → misma lógica |

### 4.3 Preguntas abiertas

- ¿Actualizar el item existente o crear uno nuevo con versión?
- ¿Notificar de alguna forma que el mensaje fue editado? (hoy `editedDate` ya se guarda)
- ¿Qué pasa si el mensaje original fue borrado del tracker?

### 4.4 Decisión preliminar

No implementar hasta que se necesite concretamente. Reply-to es más prioritario y no requiere modificar items existentes.

---

## 5. Fase 3 — Borrar mensajes (postergado)

> **Estado**: En diseño — no implementar aún

### 5.1 El problema

En Telegram, cuando se borra un mensaje en un grupo:
- **No** se envía ninguna notificación a los bots (a menos que el mensaje fuera del bot)
- Los exports de Telegram simplemente no incluyen los mensajes borrados

### 5.2 Casos detectados

| Escenario | Detectable |
|-----------|------------|
| Bot borra su propio mensaje | Telegram notifica al bot |
| Admin borra un mensaje del bot | Telegram notifica al bot |
| Admin borra mensaje de otro usuario | ❌ No hay notificación |
| Usuario normal borra su mensaje | ❌ No hay notificación |

### 5.3 Posibles enfoques

| Opción | Pros | Contras |
|--------|------|---------|
| **Comando `/delete 123`** en el grupo | Control explícito, el usuario decide | Requiere implementar manejo de comandos |
| **Ignorar** (no hacer nada) | Simple, no requiere cambios | El tracker contiene mensajes que ya no existen en Telegram |
| **Webhook `deleted_message`** | No existe — Telegram no lo provee | — |

### 5.4 Decisión preliminar

No implementar. No hay forma confiable de detectar borrados. Si surge la necesidad, se puede evaluar un comando `/delete`.

---

## 6. Visualización (capa de instalación/deployment)

> ⚠️ **Esto no pertenece al código de trackerGram.** Pertenece a la instalación de TikiWiki donde está corriendo el tracker. trackerGram solo se encarga de almacenar el dato correctamente. Cada instalación decide cómo visualizarlo.

### 6.1 Lógica de renderizado (en TikiWiki)

Tanto si usás Pretty Tracker, TRACKERLIST, o un template Smarty personalizado:

```
Si el campo ReplyToId empieza con "#":
    → Es un itemId del tracker: link directo a tiki-view_tracker_item.php?itemId=XX
Si no:
    → Es un message_id de Telegram: link de búsqueda tiki-view_tracker.php?find=YY
```

### 6.2 Template Smarty (ejemplo para wiki.chela.org.ar)

En `opt/visualizacion-tiki.md` está el template que se usa actualmente. Cuando se implemente la Fase 1, ese template se puede actualizar para incluir el link de reply-to dentro de las burbujas de chat:

```
[Mensaje actual]
  ⤴ #127 (link al mensaje original)
```

### 6.3 Responsable

Cada instalación de trackerGram configura su propia visualización en TikiWiki. En el caso de chela.org.ar, lo hace cheperico. No va al repositorio de trackerGram.

---

## 7. Resumen de tareas

### Fase 1 — Reply-to (core trackerGram) ✅

| # | Tarea | Archivo | Estado |
|---|-------|---------|--------|
| 1 | Agregar `findItemByMessageId(int $trackerId, int $messageId, int $chatId): ?int` — busca item por (chat_id, message_id) y devuelve su itemId o null | `TikiWikiClient.php` | ✅ |
| 2 | En webhook, resolver reply_to antes de guardar: extraer replyToId, buscar itemId, formatear output | `WebhookHandler.php` | ✅ |
| 3 | En import, misma lógica de resolución | `import.php` | ✅ |

### Fase 2 — Editar mensajes (implementado ✅)

| # | Tarea | Estado |
|---|-------|--------|
| 4 | Investigar API de TikiWiki para modificar items (`PUT /api/trackers/{id}/items/{itemId}`) | ✅ Usa `POST /api/trackers/{id}/items/{itemId}` |
| 5 | Agregar `updateTrackerItem()` a TikiWikiClient | ✅ Implementado en v0.5.11 |
| 6 | Procesar `edited_message` en `processUpdate()` | ✅ Implementado en v0.5.12 |
| 7 | Evaluar permisos: `tiki_p_modify_tracker_items` | ✅ Agregado a `checkPermissions()` |

### Fase 3 — Visualización (instalación)

| # | Tarea | Responsable |
|---|-------|-------------|
| 8 | Modificar template Smarty para renderizar `#127` como link y `4823` como búsqueda | cheperico |

---

## 8. Decisiones de diseño

| Decisión | Opción | Motivo |
|----------|--------|--------|
| **Formato reply-to** | `#itemId` vs raw `message_id` | Distinción visual clara; prefijo `#` no tiene ambigüedad |
| **Campo reply-to** | Reutilizar `ReplyToId` existente | No requiere schema change ni migración |
| **Búsqueda reply-to** | Por `(chat_id, message_id)` | Previene falsos positivos entre grupos |
| **Resolución reply-to** | En captura, no en display | Más eficiente; el dato queda resuelto desde el momento en que se guarda |
| **Editar mensajes** | Implementado | Excepción a append-only: solo Text+EditedDate+Reactions se actualizan (no Media/MessageType/Location) |
| **Borrar mensajes** | Postergado | No hay forma confiable de detectar borrados |
| **Visualización** | Fuera de trackerGram | Es parte de la instalación de TikiWiki, no del core |

---

## Historial de cambios

| Fecha | Cambio |
|-------|--------|
| 20/06/2026 | Creación del documento (originalmente `004-reply-to.md`, renombrado) |
