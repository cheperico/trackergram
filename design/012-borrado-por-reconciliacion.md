# 012 — Borrado de mensajes por reconciliación en import

## Problema

Cuando se importa un export ZIP de Telegram, los mensajes que fueron **borrados** en el grupo desde el último export siguen existiendo en TikiWiki. No hay forma de detectar borrados vía webhook (Telegram no notifica cuando se borra un mensaje).

El export es una foto del estado actual. Si un mensaje no está en el export, puede ser porque:
- Fue borrado
- El export es parcial (rango de fechas) y el mensaje no entró

## Solución propuesta

Checkbox en el formulario de import: *"Esta exportación cubre el historial completo — borrar de Tiki lo que no esté en el export"*. Solo cuando el usuario lo marca, se ejecuta la reconciliación.

### Flujo

```
Extracción (handleExtract):
  - Si checkbox marcado → construir Set con TODOS los IDs del export
    ["-100123:1", "-100123:2", ..., "-100123:50000"]
  - Persistir a tmp/all_msg_ids.json (~2-5 MB para 100k msgs)

Procesamiento (handleProcess, último batch):
  - Cargar all_msg_ids.json
  - getAllTrackerItems()
  - Por cada item, si "chatId:msgId" NO está en el Set →
    deleteTrackerItem(itemId)
  - Nuevo contador $deleted → se muestra en el resumen

TikiWikiClient:
  - Nuevo método deleteTrackerItem(trackerId, itemId): bool
    DELETE /api/trackers/{trackerId}/items/{itemId}
    (ruta trackeritems-delete en TikiWiki 27.5)
```

### Consideraciones

| Aspecto | Detalle |
|---------|---------|
| Export parcial | Usuario NO marca checkbox → no hay reconciliación |
| IDs negativos | `rawChatIdToFinal()` normaliza a chat_id con -100 durante extract |
| Memoria | Set de 100k keys: ~2-3 MB en PHP (array de strings) |
| Confirmación | Se puede mostrar preview opcional: "Se borrarán N items" |
| Error de permisos | `deleteTrackerItem()` puede fallar si el token no tiene permisos → log + contador failed |
| Concurrente con webhook | Si entre extract y process llegan nuevos mensajes vía webhook, esos items se crearon DESPUÉS del export → no están en el Set → serían candidatos a borrado. **FALSO POSITIVO**. |

### ⚠️ Falso positivo: mensajes creados por webhook entre extract y process

Este es el riesgo principal. Si el webhook está activo y recibe mensajes entre que se genera el export y se completa el import, esos mensajes:
- Están en TikiWiki (recién creados por webhook)
- NO están en el Set de IDs del export
- → Serían borrados incorrectamente

**Mitigaciones posibles:**
1. **Solo en handleFull** (modo legacy, todo en una request) — no hay ventana entre extract y process. Seguro.
2. **En handleProcess con advertencia**: mostrar mensaje "Si el webhook está activo, mensajes recibidos durante la importación podrían borrarse incorrectamente. Desactivar webhook primero."
3. **Marcar items por fecha**: solo borrar items con `MessageDate` <= fecha del export. Si el webhook creó items después, tienen fecha posterior y no se borran.
4. **Cache de messageIds post-process**: después del import, actualizar `all_msg_ids.json` con los IDs conocidos para futura reconciliación.

### Alternativa: borrado manual por comando

En vez de checkbox en import, un comando tipo `/sync` desde Telegram:
- El bot pide confirmación
- Trae todos los IDs del grupo via `getChatHistory` (lento, paginado, rate-limited)
- Cruza contra el tracker
- Borra los que faltan

Ventaja: no depende del export, funciona con datos vivos de Telegram.
Desventaja: `getChatHistory` es lento para grupos grandes, Telegram rate-limita.

### Decisión

Por ahora **no se implementa**. Los riesgos de falsos positivos (especialmente con webhook concurrente) son altos y las mitigaciones agregan complejidad. Si aparece un caso de uso concreto donde el usuario necesita borrado y puede garantizar export completo + webhook desactivado, se retoma este diseño.

### Referencias

- Ruta API: `DELETE /api/trackers/{trackerId}/items/{itemId}` → `trackeritems-delete` → `action_remove_item`
- `ApiBridge.php` línea 153: `$routes->add('trackeritems-delete', ...)`
- Ítem roadmap F4-2 (borrado de mensajes)
