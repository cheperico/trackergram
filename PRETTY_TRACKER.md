# Pretty Tracker para trackerGram

Guía para activar la visualización personalizada de los mensajes de Telegram en TikiWiki.

---

## ¿Qué cambia?

| Sin Pretty Tracker | Con Pretty Tracker |
|---|---|
| Tabla genérica con campos uno abajo del otro | Diseño tipo chat: autor, fecha, contenido, multimedia |
| `telegrammessageMessageDate: 1770488323` | `Federico · 04 Jun 2026 14:30` |
| `telegrammessageReactions: [{"emoji":"👍","count":1}]` | `👍 1` |
| `telegrammessageEditedDate: 1770488548` | `✏️ Editado 04 Jun 2026 14:35` (solo si fue editado) |

---

## Instalación (3 pasos)

### Paso 1: Crear la Wiki Page del template

1. Andá a tu TikiWiki y creá una página nueva:
   ```
   https://wiki.chela.org.ar/tiki-editpage.php?page=TrackerGramMessageView
   ```

2. **Copiá TODO el contenido** del bloque "Template para copiar" más abajo.

3. **Pegalo** en el editor y **guardá** la página.

### Paso 2: Darle permiso a la página

> ⚠️ **Sin esto el template no funciona** — se ve el código crudo (`{$f_variable}`).

1. Andá a la página que creaste → **Actions (engranaje) → Permissions**
2. Agregá el permiso **`tiki_p_use_as_template`** a **Anonymous**
3. Opcional: sacá `tiki_p_edit` a Anonymous para que nadie la modifique

### Paso 3: Configurar el tracker

1. Andá al admin del tracker:
   ```
   https://wiki.chela.org.ar/tiki-admin_trackers.php?trackerId=12
   ```

2. Buscá la sección **Format** (puede estar colapsada, clickeala para expandirla).

3. Completá los campos:

   | Campo | Valor |
   |---|---|
   | **Section format** | `Configured` (en el dropdown) |
   | **Template to display an item** | `tplwiki:TrackerGramMessageView` |
   | **Template to edit an item** | (dejalo vacío) |

   > ⚠️ Usá **`tplwiki:`** (no `wiki:`). El prefijo `tplwiki:` evita que el wiki parser modifique el template (escapa `&&`, agrega `<br />`, etc.) y lo pasa directo a Smarty.

4. **Guardá.**

---

## Verificar

Andá a cualquier item del tracker:
```
https://wiki.chela.org.ar/tiki-view_tracker_item.php?itemId=ID_DEL_ITEM&trackerId=12
```

✅ **Funciona** → ves el mensaje con diseño lindo (autor, fecha, contenido, reacciones)

❌ **No funciona** → ves `{$f_telegrammessageText}` crudo → revisá permisos del Paso 2

---

## Template para copiar

> 📌 Este template ya tiene el tracker ID **12** configurado. Si tu tracker usa otro ID, cambiá `trackerId=12` por el número correspondiente en el link de reply.

Copiá TODO desde aquí 👇

```
{if $f_telegrammessageTopicTitle && $f_telegrammessageTopicTitle != 'General'}
<div style="background:#f0f4ff; border-left:4px solid #4a76a8; padding:4px 10px; margin-bottom:8px; font-size:0.9em;">
  📂 Topic: {$f_telegrammessageTopicTitle|escape}
</div>
{/if}

<div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
  <strong style="font-size:1.1em;">{$f_telegrammessageFirstName|escape} {$f_telegrammessageLastName|escape}</strong>
  {if $f_telegrammessageUsername}
    <span style="color:#888;">(@{$f_telegrammessageUsername|escape})</span>
  {/if}
  <span style="color:#999; font-size:0.85em; margin-left:auto;">
    {$f_telegrammessageMessageDate|tiki_short_datetime}
  </span>
</div>

{if $f_telegrammessageEditedDate}
<div style="color:#b8860b; font-size:0.85em; margin-bottom:4px;">
  ✏️ Editado {$f_telegrammessageEditedDate|tiki_short_datetime}
</div>
{/if}

{if $f_telegrammessageReplyToId}
<div style="background:#f9f9f9; border-left:3px solid #ccc; padding:4px 10px; margin:6px 0; font-size:0.9em;">
  💬 En respuesta al mensaje
  <a href="tiki-view_tracker.php?trackerId=12&amp;filterfield=telegrammessageTelegramMessageId&amp;exactvalue={$f_telegrammessageReplyToId|escape:url}">
    #{$f_telegrammessageReplyToId|escape}
  </a>
</div>
{/if}

{if $f_telegrammessageText}
<div style="margin:8px 0; line-height:1.5;">
  {$f_telegrammessageText}
</div>
{/if}

{if $f_telegrammessageMessageType === 'photo' || $f_telegrammessageMessageType === 'sticker'}
<div style="margin:8px 0;">
  <img src="{$f_telegrammessageMediaUrl|escape}" alt="{$f_telegrammessageMessageType|escape}"
       style="max-width:100%; max-height:400px; border-radius:4px;">
  {if $f_telegrammessageMediaCaption}
    <div style="color:#555; font-size:0.9em; margin-top:4px;">
      📷 {$f_telegrammessageMediaCaption|escape}
    </div>
  {/if}
</div>
{elseif $f_telegrammessageMessageType === 'video' || $f_telegrammessageMessageType === 'animation'}
<div style="margin:8px 0;">
  <video controls style="max-width:100%; max-height:400px; border-radius:4px;">
    <source src="{$f_telegrammessageMediaUrl|escape}">
  </video>
  {if $f_telegrammessageMediaDuration}
    <div style="color:#888; font-size:0.85em;">⏱ Duración: {$f_telegrammessageMediaDuration}</div>
  {/if}
</div>
{elseif $f_telegrammessageMessageType === 'audio' || $f_telegrammessageMessageType === 'voice'}
<div style="margin:8px 0;">
  <audio controls style="width:100%;">
    <source src="{$f_telegrammessageMediaUrl|escape}">
  </audio>
  {if $f_telegrammessageMediaDuration}
    <div style="color:#888; font-size:0.85em;">⏱ Duración: {$f_telegrammessageMediaDuration}</div>
  {/if}
</div>
{elseif $f_telegrammessageMessageType === 'document'}
<div style="margin:8px 0; padding:8px; background:#f5f5f5; border:1px solid #ddd; border-radius:4px;">
  📎 Documento:
  <a href="{$f_telegrammessageMediaUrl|escape}" target="_blank">
    {$f_telegrammessageMediaCaption|default:$f_telegrammessageMediaType|escape}
  </a>
  {if $f_telegrammessageMediaSize}
    <span style="color:#888; font-size:0.85em;">({$f_telegrammessageMediaSize} bytes)</span>
  {/if}
</div>
{elseif $f_telegrammessageMessageType === 'location'}
<div style="margin:8px 0;">
  📍 Ubicación: {$f_telegrammessageLocation}
</div>
{/if}

{if $f_telegrammessageReactions}
<div style="margin:8px 0; padding:6px 10px; background:#fff5f5; border-radius:4px; display:inline-block;">
  {$f_telegrammessageReactions}
</div>
{/if}

{if $f_telegrammessageMessageType === 'system'}
<div style="margin:8px 0; padding:6px 10px; background:#f0f0f0; border-radius:4px; font-style:italic; color:#666;">
  {$f_telegrammessageText}
</div>
{/if}

<div style="margin-top:12px; padding-top:8px; border-top:1px solid #eee; font-size:0.8em; color:#aaa;">
  ID: {$f_telegrammessageTelegramMessageId} · Tipo: {$f_telegrammessageMessageType}
  {if $f_telegrammessageChatTitle}
     · {$f_telegrammessageChatTitle|escape}
  {/if}
</div>
```

---

## Solución de problemas

| Síntoma | Causa | Solución |
|---|---|---|
| Se ve `{$f_...}` crudo | Falta permiso `tiki_p_use_as_template` | Asignarlo a Anonymous (Paso 2) |
| "Template not found" | Nombre mal escrito en la config | Verificar `tplwiki:TrackerGramMessageView` exacto |
| El template se ve sin datos | El permName no coincide | Revisar nombres de campos en el tracker |
| Error de sintaxis Smarty | Error en el template | Verificar que no haya `{~` `~}` ni `{# #}` |
| Link de reply no funciona | trackerId incorrecto | Cambiar `trackerId=12` por el ID real |

### Errores comunes al pegar el template

**Error: `{# ... #}` → Smarty syntax error**
La causa: el template anterior tenía comentarios `{# #}` que no son válidos en Smarty.
La solución: usá la versión actual del template (sin `{# #}`).

**Error: `{~if~}` → Unexpected "~"**
La causa: el wiki parser no procesa `{~` desde Tiki 6 (hace 15 años).
La solución: el template actual usa `{if}` (sin tilde) y el tracker configurado con `tplwiki:`.

---

## Referencias

- [Documentación oficial de Pretty Tracker](https://doc.tiki.org/Pretty-Tracker)
- [Pretty Tracker How-To](https://doc.tiki.org/Pretty-Tracker-HowTo)
- `tplwiki:` vs `wiki:`: `lib/smarty_tiki/resource.tplwiki.php` (pasa raw a Smarty)
