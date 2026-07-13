# 013 — Cambios de medio adjunto en mensajes editados

## Problema

Cuando un mensaje de Telegram es editado y se cambia el archivo adjunto (foto, video, documento, etc.), trackerGram no refleja ese cambio:

- **Webhook `edited_message`**: roto porque `findItemByMessageId()` usa `filter[fields]` que la API de TikiWiki no soporta → nunca encuentra el item original → crea duplicado en vez de actualizar.
- **Import de export**: el bloque edit (`$shouldUpdate`) solo actualiza Text + EditedDate + Reactions, nunca toca Media, MediaUrl, etc.

## Análisis técnico

### ¿Cómo detectar que el medio cambió?

**En el webhook (Bot API):**
- `edited_message` incluye el objeto media completo con `file_id` y `file_unique_id` nuevos si el archivo cambió
- Pero `edited_message` también se dispara por reacciones y otros cambios internos (issue #773 del Bot API)
- Se puede detectar cambio real comparando `file_unique_id` del edit vs. el almacenado en TikiWiki (campo `{prefix}FileUniqueId`)

**En el export de Telegram Desktop:**
- **No incluye `file_unique_id`** (confirmado en schema oficial, solo rutas relativas a archivos ZIP)
- `edited_unixtime` se setea incluso por reacciones (bug confirmado issue #30647, mayo 2026). No es confiable como indicador de edit real.
- La primera reacción en un mensaje siempre genera `edited_unixtime`, y si hay reacciones después de un edit real, sobreescriben la timestamp del edit.

### ¿Cómo actualizar el campo FG en TikiWiki?

- `POST /api/trackers/{trackerId}/items/{itemId}` (update) **reemplaza completamente** el valor del campo FG. No hace merge/append.
- Para hacer append (ej: álbumes), hay que: `GET /api/trackers/{trackerId}/items/{itemId}` → extraer `fields[].value` del FG (comma-separado) → concatenar nuevo fileId → PUT con el resultado.

## Estrategias consideradas para import

| Enfoque | Pros | Contras |
|---------|------|---------|
| **A. No re-subir media en edits** (actual) | Simple, no desperdicia uploads ni pisadas de FG | No refleja cambios de medio en import |
| **B. Subir solo si FG del item está vacío** | Solo completa lo que al webhook le faltó (fallback seguro) | No captura "cambiaron la foto después del webhook" |
| **C. Comparar hash/size del archivo** | Detecta cambios reales con precisión | Caro (requiere descargar de TikiWiki para comparar), complejo, no sirve si el export excluyó el archivo |
| **D. Re-subir siempre que haya `edited_unixtime` + archivo** | Aplica siempre | **Falsos positivos por reacciones** (bug `edited_unixtime`), desperdicia uploads, pisa FG con el mismo archivo |
| **E. Arreglar webhook `edited_message`** | Cambios en tiempo real, con `file_unique_id` para comparación precisa | Requiere resolver `findItemByMessageId()` primero |

## Decisión (Julio 2026)

Se descarta implementar cambios de medio en **import** por ahora. El bug de `edited_unixtime` por reacciones hace que cualquier enfoque basado en `edited_unixtime` produzca falsos positivos.

En su lugar, se prioriza:

**1. Arreglar webhook `edited_message`** — usando cache local de messageIds (ver roadmap F4-7). Esto permite:
   - Encontrar el item original por `(chatId, messageId)`
   - Comparar `file_unique_id` del edit vs. el almacenado
   - Si cambió: descargar nueva media, reemplazar FG, actualizar campos
   - Si no cambió: solo actualizar Text + EditedDate + Reactions

**2. En import, enfoque B (conservador):** solo subir media para mensajes existentes si el campo FG está vacío (el webhook no pudo descargar el original). No reemplazar FG si ya tiene contenido, aunque haya `edited_unixtime`.

## Referencias

- Bot API `edited_message`: https://core.telegram.org/bots/api#update
- Bot API `editMessageMedia`: https://core.telegram.org/bots/api#editmessagemedia
- Bug `edited_unixtime` por reacciones: https://github.com/telegramdesktop/tdesktop/issues/30647
- `edited_message` no solo contenido: https://github.com/tdlib/telegram-bot-api/issues/773
- Schema export: https://core.telegram.org/import-export#message
- API TikiWiki update item: `POST /api/trackers/{trackerId}/items/{itemId}` (reemplaza FG)
- API TikiWiki delete item: `DELETE /api/trackers/{trackerId}/items/{itemId}` (existe)
- Ítem roadmap F4-7: cache local de messageIds para dedup sin API
- Design previo: `012-borrado-por-reconciliacion.md`
