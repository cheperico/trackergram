# Template Wiki Feed — trackerGram

> Páginas wiki de TikiWiki para mostrar mensajes del tracker 22 como feed tipo chat.
> Versión: v0.2.1 — Creado: 09/06/2026

---

## 📄 Página 1: Template por-item `plantillaTrackergram`

Wiki page usada como `tplwiki` en el `{TRACKERLIST}`. Se aplica a **cada item** individualmente.

```smarty
{* ============================================================
   PLANTILLA TRACKERGRAM - Cada burbuja de mensaje
   ============================================================ *}

<div class="tgram-bubble {$f_181|lower}">

  {* ── CABECERA ── *}
  <div class="tgram-header">
    <strong class="tgram-name">{$f_196}</strong>
    {if $f_178}
      <span class="tgram-username">@{$f_178}</span>
    {/if}
    {if $f_175}
      <span class="tgram-topic">{$f_175}</span>
    {/if}
    <span class="tgram-date">{$f_176|tiki_short_datetime}</span>
  </div>

  {* ── TEXTO ── *}
  {if $f_182}
    <div class="tgram-text">{$f_182}</div>
  {/if}

  {* ── MULTIMEDIA ── *}
  {if $f_184}
    <div class="tgram-media">
      {if $f_181 == 'photo' || $f_181 == 'sticker'}
        <a href="{$f_184}" target="_blank"><img src="{$f_184}" /></a>
      {elseif $f_181 == 'video' || $f_181 == 'video_note'}
        {ldelim}HTML(){rdelim}<video src="{$f_184}" controls></video>{ldelim}/HTML{rdelim}
      {elseif $f_181 == 'audio' || $f_181 == 'voice'}
        {ldelim}HTML(){rdelim}<audio src="{$f_184}" controls></audio>{ldelim}/HTML{rdelim}
      {else}
        <a href="{$f_184}" target="_blank" class="tgram-download">📎 {$f_186}</a>
      {/if}
    </div>
    {if $f_188}
      <div class="tgram-caption"><em>{$f_188}</em></div>
    {/if}
  {/if}

  {* ── UBICACIÓN ── *}
  {if $f_189}
    <div class="tgram-location">
      {wikiplugin _name="map" coords=$f_189}{/wikiplugin}
    </div>
  {/if}

  {* ── RESPUESTA ── *}
  {if $f_194}
    <div class="tgram-reply">↪️ En respuesta al msg #{$f_194}</div>
  {/if}

  {* ── EDITADO ── *}
  {if $f_193}
    <div class="tgram-edited">✏️ Editado {$f_193|tiki_short_datetime}</div>
  {/if}

  {* ── REACCIONES ── *}
  {if $f_195}
    <div class="tgram-reactions">{$f_195}</div>
  {/if}

</div>
```

### Mapeo fieldId → variable

| fieldId | Variable | permName | Muestra |
|---------|----------|----------|---------|
| 171 | `$f_171` | telegrammessageTelegramMessageId | — |
| 172 | `$f_172` | telegrammessageChatId | — |
| 173 | `$f_173` | telegrammessageChatTitle | — |
| 174 | `$f_174` | telegrammessageTopicId | — |
| 175 | `$f_175` | telegrammessageTopicTitle | 🏷️ Topic |
| 176 | `$f_176` | telegrammessageMessageDate | 🕐 Fecha |
| 177 | `$f_177` | telegrammessageUserId | — |
| 178 | `$f_178` | telegrammessageUsername | @usuario |
| 179 | `$f_179` | telegrammessageFirstName | — |
| 180 | `$f_180` | telegrammessageLastName | — |
| 196 | `$f_196` | telegrammessageDisplayName | 👤 Nombre |
| 181 | `$f_181` | telegrammessageMessageType | Tipo (photo, video, etc.) |
| 182 | `$f_182` | telegrammessageText | 💬 Texto |
| 183 | `$f_183` | telegrammessageMedia (FG) | — |
| 184 | `$f_184` | telegrammessageMediaUrl | 🖼️ URL del archivo |
| 185 | `$f_185` | telegrammessageFileUrl | — |
| 186 | `$f_186` | telegrammessageMediaType | MIME type |
| 187 | `$f_187` | telegrammessageMediaSize | Tamaño en bytes |
| 188 | `$f_188` | telegrammessageMediaCaption | Caption del media |
| 189 | `$f_189` | telegrammessageLocation | 📍 Ubicación GPS |
| 190 | `$f_190` | telegrammessageMediaWidth | Ancho |
| 191 | `$f_191` | telegrammessageMediaHeight | Alto |
| 192 | `$f_192` | telegrammessageMediaDuration | Duración |
| 193 | `$f_193` | telegrammessageEditedDate | ✏️ Editado |
| 194 | `$f_194` | telegrammessageReplyToId | ↪️ Responde a |
| 195 | `$f_195` | telegrammessageReactions | Reacciones |

---

## 📄 Página 2: Página principal del feed

Wiki page que contiene el `{TRACKERLIST}` + CSS. Ejemplo: `ChatTelegram`.

```
{HTML()}
<style>
.tgram-feed {
  max-width: 780px;
  margin: 0 auto;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.tgram-bubble {
  background: #fff;
  padding: 12px 16px;
  margin: 8px 0;
  box-shadow: 0 1px 3px rgba(0,0,0,0.08);
  border-left: 4px solid #e8e8e8;
  transition: box-shadow 0.2s;
}

.tgram-bubble:hover {
  box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}

.tgram-bubble.photo { border-left-color: #4CAF50; }
.tgram-bubble.video { border-left-color: #2196F3; }
.tgram-bubble.audio { border-left-color: #FF9800; }
.tgram-bubble.voice { border-left-color: #9C27B0; }
.tgram-bubble.sticker { border-left-color: #00BCD4; }
.tgram-bubble.system { border-left-color: #9E9E9E; background: #fafafa; }
.tgram-bubble.document { border-left-color: #795548; }
.tgram-bubble.location { border-left-color: #F44336; }
.tgram-bubble.animation { border-left-color: #FF5722; }

.tgram-header {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  margin-bottom: 6px;
  font-size: 0.9em;
}

.tgram-name {
  color: #1a73e8;
  font-size: 1em;
  font-weight: 600;
}

.tgram-username {
  color: #888;
  font-size: 0.85em;
}

.tgram-topic {
  background: #e8f0fe;
  color: #1a73e8;
  padding: 1px 8px;
  font-size: 0.8em;
}

.tgram-date {
  color: #aaa;
  font-size: 0.8em;
  margin-left: auto;
  white-space: nowrap;
}

.tgram-text {
  line-height: 1.5;
  color: #222;
  word-wrap: break-word;
  white-space: pre-wrap;
}

.tgram-media {
  margin-top: 8px;
  overflow: hidden;
}

.tgram-media img {
  width: 100%;
  display: block;
}

.tgram-media video {
  width: 100%;
  display: block;
  background: #000;
}

.tgram-media audio {
  width: 100%;
  margin-top: 4px;
}

.tgram-caption {
  margin-top: 4px;
  font-size: 0.9em;
  color: #555;
}

.tgram-reply {
  margin-top: 6px;
  font-size: 0.85em;
  color: #666;
  padding: 4px 8px;
  background: #f5f5f5;
  border-left: 3px solid #1a73e8;
}

.tgram-edited {
  margin-top: 2px;
  font-size: 0.75em;
  color: #bbb;
  font-style: italic;
}

.tgram-reactions {
  margin-top: 6px;
  font-size: 0.9em;
  padding: 3px 10px;
  background: #f0f0f0;
  display: inline-block;
}

.tgram-location {
  margin-top: 6px;
  overflow: hidden;
}

.tgram-download {
  display: inline-block;
  margin-top: 4px;
  padding: 6px 12px;
  background: #f5f5f5;
  text-decoration: none;
  color: #1a73e8;
  font-size: 0.9em;
}

.tgram-feed .pagination {
  text-align: center;
  margin: 16px 0;
}

.tgram-feed .pagination a {
  padding: 4px 12px;
  margin: 0 2px;
  border: 1px solid #ddd;
  text-decoration: none;
  color: #1a73e8;
}

.tgram-feed .pagination a:hover {
  background: #e8f0fe;
}
</style>
{HTML}

{DIV(class="tgram-feed")}
{TRACKERLIST(
    trackerId="22",
    fields="171:172:173:174:175:176:177:178:179:180:181:182:183:184:185:186:187:188:189:190:191:192:193:194:195:196",
    sort_mode="f_176_desc",
    max="50",
    showpagination="y",
    status="opc",
    tplwiki="plantillaTrackergram"
) /}
{DIV}
```

---

## Requisitos en TikiWiki

| Requisito | Dónde |
|-----------|-------|
| Plugin **HTML** habilitado | Admin → Editing and Plugins → Plugins → `wikiplugin_html` |
| Plugin HTML **aprobado** | `tiki-plugins.php` → Approve |
| Template con permiso `tiki_p_use_as_template` | Admin → Wiki → Pages → `plantillaTrackergram` → Permissions |
| Plugin **TRACKERLIST** habilitado | Normalmente viene activado por defecto |

---

## Notas técnicas

- Se usó `{HTML()}<style>` porque `{CSS()}` **NO existe como plugin** en TikiWiki 27.x
- `$f_184` (mediaUrl) se popula desde PHP tras cada upload exitoso de media (WebhookHandler + import)
- `$f_182` se muestra sin `|wiki` porque el texto ya fue escapado con `htmlspecialchars()` en el servidor
- `white-space: pre-wrap` en `.tgram-text` respeta los saltos de línea originales
- Los fieldIds se obtienen de Admin → Trackers → (tracker) → Fields
- No se usa el plugin `img` de TikiWiki porque `$f_183` en TRACKERLIST no garantiza devolver un fileId numérico
