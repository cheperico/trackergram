# 004 — Reply-To: resolución y visualización de mensajes referenciados

> **Fecha**: 20/06/2026
> **Estado**: Diseño — pendiente de implementación
> **Sesión**: Orquestador principal con usuario (cheperico)
> **Tags**: reply-to, vinculación, tracker, visualización

---

## Índice

1. [Contexto](#1-contexto)
2. [Problema](#2-problema)
3. [Solución propuesta (core trackerGram)](#3-solución-propuesta-core-trackergram)
4. [Visualización (instancia-specific)](#4-visualización-instancia-specific)
5. [Tareas](#5-tareas)
6. [Decisión de diseño](#6-decisión-de-diseño)

---

## 1. Contexto

Cuando un usuario responde a un mensaje en Telegram, el payload del webhook incluye `reply_to_message` con el `message_id` del mensaje original.

Hoy trackerGram extrae ese dato y lo guarda como texto plano en el campo `telegrammessageReplyToId`:

```
reply_to_message.message_id = 4823  →  fields[ReplyToId] = "4823"
```

El problema es que **"4823" no sirve de nada** en el tracker — es solo un número sin contexto ni link.

## 2. Problema

| Aspecto | Situación actual |
|---------|-----------------|
| Dato guardado | `message_id` de Telegram (ej: `4823`) |
| Utilidad en el tracker | Nula — es un número sin referencia |
| Link al original | No existe |
| Hilo de conversación | No se puede reconstruir |

## 3. Solución propuesta (core trackerGram)

### 3.1 Resolución en tiempo de captura

Cuando trackerGram procesa un mensaje (webhook o import), si tiene `reply_to`, debe:

```
1. Extraer reply_to_message.message_id (ej: 4823)
2. Buscar en el tracker: ¿existe un item con TelegramMessageId=4823 y ChatId=X?
   usando: GET /api/trackers/{id}/items?filter[fields][TelegramMessageId]=4823&filter[fields][ChatId]=-100123
3.   ✅ Si existe → guardar #127 (itemId del tracker)
4.   ❌ Si no existe → guardar 4823 (raw message_id, fallback)
```

### 3.2 Formato de almacenamiento

| Se resolvió | Guarda | Significado |
|-------------|--------|-------------|
| ✅ Sí | `#127` | Item ID del tracker (link directo) |
| ❌ No | `4823` | Telegram message_id (búsqueda) |

El prefijo `#` distingue visualmente un itemId de tracker de un message_id de Telegram. No se necesita un campo nuevo — se reutiliza `telegrammessageReplyToId`.

### 3.3 Límite por chat_id

La búsqueda se hace por `(chat_id, message_id)` porque el `message_id` solo es único dentro de cada chat. Esto evita falsos positivos si dos grupos distintos tienen mensajes con el mismo ID.

### 3.4 Limitación conocida

Si el mensaje original fue procesado vía async (worker.php) o se va a importar después con un ZIP, **todavía no existe en el tracker** cuando llega el reply. En ese caso cae al fallback (message_id sin resolver).

Posible mejora futura: resolución diferida (re-procesar replies no resueltos cuando aparezca el mensaje original, o un job batch que los resuelva).

---

## 4. Visualización (capa de instalación/deployment)

> ⚠️ **Esto no pertenece al código de trackerGram.** Pertenece a la instalación de TikiWiki donde está corriendo el tracker. trackerGram solo se encarga de almacenar el dato correctamente (`#127` o `4823`). Cada instalación decide cómo visualizarlo.

### 4.1 Lógica de renderizado (en TikiWiki)

Tanto si usás Pretty Tracker, TRACKERLIST, o un template Smarty personalizado, la lógica es la misma:

```
Si el campo ReplyToId empieza con "#":
    → Es un itemId del tracker: link directo a tiki-view_tracker_item.php?itemId=XX
Si no:
    → Es un message_id de Telegram: link de búsqueda tiki-view_tracker.php?find=YY
```

### 4.2 Template Smarty para wiki.chela.org.ar (ejemplo concreto)

En `opt/visualizacion-tiki.md` está el template que se usa actualmente en chela.org.ar. Cuando se implemente la Fase 1, ese template se actualiza para incluir el link de reply-to.

Formato esperado dentro de la burbuja de chat:

```
[Mensaje actual]
  ⤴ #127 (link al mensaje original)
```

### 4.3 Responsable

La implementación de la visualización la hace el responsable de cada instalación de trackerGram (en este caso cheperico). No es código que pertenezca al repositorio de trackerGram.

---

## 5. Tareas

### Fase 1 — Core trackerGram

| # | Tarea | Archivo | Esfuerzo |
|---|-------|---------|----------|
| 1 | Agregar `findItemByMessageId(int $trackerId, int $messageId, int $chatId): ?int` — busca un item por (chat_id, message_id) y devuelve su itemId, o null si no existe | `TikiWikiClient.php` | 🟢 bajo |
| 2 | En `WebhookHandler::processMessage()`, resolver reply_to antes de guardar: extraer replyToId, buscar itemId, formatear output | `WebhookHandler.php` | 🟢 bajo |
| 3 | En `import.php`, misma lógica de resolución durante el procesamiento de mensajes | `import.php` | 🟢 bajo |

### Fase 2 — Visualización (instancia chela.org.ar)

| # | Tarea | Responsable |
|---|-------|-------------|
| 4 | Modificar template Smarty para renderizar `#127` como link y `4823` como búsqueda | cheperico |

---

## 6. Decisión de diseño

| Decisión | Opción | Motivo |
|----------|--------|--------|
| **Formato** | `#itemId` vs raw `message_id` | Distinción visual clara; prefijo `#` no tiene ambigüedad |
| **Campo** | Reutilizar `ReplyToId` existente | No requiere schema change ni migración |
| **Búsqueda** | Por `(chat_id, message_id)` | Previene falsos positivos entre grupos |
| **Visualización** | Fuera de trackerGram | Es parte de la instancia, no del core |
| **Resolución** | En captura, no en display | Más eficiente; el dato queda resuelto desde el momento en que se guarda |

---

## Historial de cambios

| Fecha | Cambio |
|-------|--------|
| 20/06/2026 | Creación del documento |
