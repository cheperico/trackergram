# 014 — Visualización de reply-to en el feed de chat

> **Fecha**: 29/07/2026
> **Estado**: Implementado en v0.6.6 — Plan B. Modal descartado.
> **Tags**: reply-to, visualización, template, Smarty, modal

---

## Índice

1. [Problema](#1-problema)
2. [Estado actual](#2-estado-actual)
3. [Plan B — Preview inline sin navegación](#3-plan-b--preview-inline-sin-navegación)
4. [Próximo: Análisis de modal](#4-próximo-análisis-de-modal)

---

## 1. Problema

El campo `ReplyToId` se guarda con información útil (`#itemId - fecha - "texto preview"`), pero la visualización actual en el feed tipo chat (template Smarty + TRACKERLIST) no funciona bien:

- El modal actual usa `tiki-view_tracker_item.php?itemId=X&modal=1` que **no soporta `modal=1`** y carga el layout completo de TikiWiki adentro del modal.
- `$.openModal()` puede no estar disponible en todos los contextos.
- No hay una preview visual clara del mensaje al que se responde.

---

## 2. Estado actual

### 2.1 Formato del campo `ReplyToId`

| Escenario | Contenido |
|-----------|-----------|
| Webhook + resuelto + con texto | `#42 - "Hola, te mando el doc"` |
| Webhook + resuelto + sin texto | `#42` |
| Webhook + NO resuelto + texto capturado | `"Hola, te mando el doc"` |
| Import + resuelto + texto obtenido vía API | `#42 - "Hola, te mando el doc"` |
| Import + NO resuelto | `4823` (messageId crudo) |

### 2.2 Template Smarty actual

```smarty
{if $f_194}
  <div class="tgram-reply">
    ↪️ Respuesta a:
    {if preg_match('/^#(\d+)/', $f_194, $matches)}
      <a href="tiki-view_tracker_item.php?itemId={$matches[1]}&modal=1" class="tgram-reply-link tgram-reply-modal">{$f_194}</a>
    {else}
      <a href="tiki-view_tracker.php?trackerId=22&filterfield=telegrammessageTelegramMessageId&filtervalue={$f_194|escape:'url'}" class="tgram-reply-link tgram-reply-modal">{$f_194}</a>
    {/if}
  </div>
{/if}
```

### 2.3 Problemas conocidos

1. **`tiki-view_tracker_item.php` no soporta `modal=1`** — Confirmado en código fuente de TikiWiki 27.5. Siempre renderiza el layout completo (header, sidebar, footer).
2. **`$.openModal()` puede fallar** — Depende de jQuery + TikiJS, no siempre disponible en páginas con TRACKERLIST.
3. **Preview no diferenciada** — El texto preview está pegado al link, no hay un bloque visual separado.
4. **Sin fecha del original** — `ReplyToId` no incluye fecha/hora del mensaje respondido, solo itemId y texto.

---

## 3. Plan B — Preview inline sin navegación

> Enfoque 100% estático: sin links, sin JS, sin modal. El reply se muestra como una quote decorativa dentro de la burbuja.

### 3.1 Formato propuesto del campo

Agregar `- fecha` al formato actual:

```
#42 - 2024-01-15 14:30 - "Hola, te mando el doc"
```

### 3.2 Cambios necesarios en PHP

| Archivo | Cambio |
|---------|--------|
| `NormalizedMessage.php` | Agregar `replyToDate` como campo transiente (string) |
| `MessageMapper.php` `fromWebhook()` | Capturar `reply_to_message.date` y guardarlo en `$msg->replyToDate` |
| `WebhookHandler.php` | Incluir fecha formateada en el string de `replyToId` (ej: `#42 - 2024-01-15 14:30 - "texto"`) |
| `import.php` | Al resolver reply via `getTrackerItem()`, extraer también `MessageDate` e incluirla formateada |

### 3.3 Template Smarty propuesto

```smarty
{* ── RESPUESTA (preview inline, sin link) ── *}
{if $f_194}
  <div class="tgram-reply">
    <div class="tgram-reply-line"></div>
    <div class="tgram-reply-content">
      <span class="tgram-reply-header">↪️ En respuesta a
        {if preg_match('/^#(\d+)/', $f_194, $m)}#{$m[1]}{/if}
        {if preg_match('/ - (\d{4}-\d{2}-\d{2} \d{2}:\d{2}) - /', $f_194, $m)}
          · {$m[1]}
        {/if}
      </span>
      {if preg_match('/"(.+)"/', $f_194, $m)}
        <div class="tgram-reply-text">{$m[1]}</div>
      {/if}
    </div>
    <div class="tgram-reply-line"></div>
  </div>
{/if}
```

### 3.4 CSS propuesto

```css
.tgram-reply {
  margin: 4px 0 8px 0;
  font-size: 0.85em;
}

.tgram-reply-line {
  border-top: 1px solid #ddd;
  margin: 2px 0;
}

.tgram-reply-line:last-child {
  margin-top: 4px;
}

.tgram-reply-header {
  color: #888;
  font-size: 0.85em;
}

.tgram-reply-text {
  color: #555;
  font-style: italic;
  padding-left: 8px;
  border-left: 3px solid #1a73e8;
  margin: 2px 0;
  white-space: pre-wrap;
  word-wrap: break-word;
}
```

### 3.5 Coverage tras cambios

| Escenario | Se muestra |
|-----------|------------|
| `#42 - 2024-01-15 14:30 - "texto"` | header: `↪️ #42 · 2024-01-15 14:30` + texto en cursiva |
| `#42` | header: `↪️ #42` (sin fecha, sin texto) |
| `"texto"` (no resuelto) | solo texto en cursiva (sin header) |
| `4823` (raw) | no coincide ningún regex → no se muestra nada |

### 3.6 Ventajas y desventajas

**Ventajas:**
- ✅ 0 JavaScript, 0 dependencias
- ✅ 100% seguro, no expone tokens
- ✅ Funciona aunque jQuery/TikiJS no estén cargados
- ✅ La preview es clara: se ve el texto original y la fecha de referencia
- ✅ El lector tiene suficiente info para buscar manualmente el mensaje original

**Desventajas:**
- ❌ No hay acceso directo al mensaje original desde el feed
- ❌ Si `ReplyToId` es `4823` (raw messageId sin resolver), no se muestra nada (caso borde de imports)

---

## 4. Análisis de modal — Descartado

Se analizó la viabilidad de mostrar el item original del reply en un modal.

### 4.1 Intentos analizados

| Endpoint | Funciona | Problema |
|----------|:--------:|----------|
| `tiki-view_tracker_item.php?itemId=X&modal=1` | ❌ | El archivo **no soporta `modal=1`** ni en 27.5 ni en 29.2. Siempre renderiza layout completo. |
| `tiki-ajax_services.php?controller=tracker&action=preview_item&trackerId=X&itemId=X&modal=1` | ✅ Técnicamente sí | Renderiza solo el body del modal (sin header/sidebar/footer). Usa `action_preview_item()`. |

### 4.2 Por qué se descarta el modal

Aunque el service endpoint funciona, la experiencia de usuario no es buena:

1. **Banner "preview" engañoso**: `action_preview_item()` está diseñado para previsualizar items ANTES de guardarlos. Siempre muestra: *"Note: Remember that this is only a preview, and has not yet been saved!"* — el item ya existe, el mensaje es incorrecto.
2. **Demasiada información**: Renderiza TODOS los campos del tracker vía `{trackerfields}`, no solo los relevantes (chatId, userId, MediaSize, etc. no aportan nada al usuario del feed).
3. **Formato tabla genérica**: A menos que el tracker tenga Pretty Tracker configurado, se ve como tabla de datos, no como burbuja de chat.
4. **trackerId hardcodeado**: El template necesita saber el trackerId para armar la URL. Hoy está hardcodeado en cada template.

### 4.3 Comparativa entre 27.5 y 29.2

Ambas versiones se comportan igual. No hay cambios relevantes en 29.2 que mejoren la experiencia:

| Aspecto | 27.5 | 29.2 |
|---------|:----:|:----:|
| `tiki-view_tracker_item.php` soporta `modal=1` | ❌ | ❌ (solo tiene modal para comments) |
| `tiki-ajax_services.php` + `preview_item` | ✅ | ✅ |
| `$.openModal()` disponible | ✅ | ✅ |

### 4.4 Decisión

**Se descarta el modal.** La preview inline (Plan B, sección 3) es la solución elegida: 0 JS, 0 dependencias, funciona siempre, y el usuario tiene suficiente información (texto + fecha) para buscar el mensaje original si lo necesita.

---

## 5. Implementación (completada en v0.6.6)

### 5.1 Cambios en PHP (backend) — ✅ Implementados

| Archivo | Cambio | Prioridad |
|---------|--------|-----------|
| `NormalizedMessage.php` | Agregar `replyToDate` como campo transiente (string) | Alta |
| `MessageMapper.php` `fromWebhook()` | Capturar `reply_to_message.date` → `$msg->replyToDate` | Alta |
| `WebhookHandler.php` | Incluir fecha formateada en el string de `replyToId` (`#42 - 2024-01-15 14:30 - "texto"`) | Alta |
| `import.php` | Al resolver reply via `getTrackerItem()`, extraer también `MessageDate` e incluirla formateada | Alta |

### 5.2 Cambios en templates (visualización) — ✅ Implementados

| Archivo | Cambio |
|---------|--------|
| `opt/visualizacion-tiki.md` | Template: reemplazar bloque reply con preview inline sin link. CSS: agregar estilos de quote. JS: remover handler de modal. |
| `opt/visualizacion-lcc2026.md` | Idem, adaptado a fieldIds de lcc2026. |

### 5.3 Flecos post-implementación (pendientes)

- Probar edge cases: reply sin texto, reply a mensaje borrado, imports con `4823` crudo.
- Considerar si el caso `4823` (raw messageId sin resolver) merece un mensaje tipo "↪️ Responde a un mensaje no importado" en vez de no mostrar nada.
