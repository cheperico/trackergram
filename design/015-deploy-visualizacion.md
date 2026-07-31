# 015 — Deploy automático de visualización desde trackerGram

> **Fecha**: 29/07/2026
> **Estado**: ✅ Implementado (v0.7.0)
> **Tags**: visualización, deploy, template, Smarty, API TikiWiki
> **Roadmap**: V-1 (fusión V-1+V-2 originales)

---

## Índice

1. [Problema](#1-problema)
2. [Solución propuesta](#2-solución-propuesta)
3. [Experiencia de usuario](#3-experiencia-de-usuario)
4. [Arquitectura](#4-arquitectura)
5. [Template base](#5-template-base)
6. [Compilación](#6-compilación)
7. [Selector de campos](#7-selector-de-campos)
8. [Nombres de páginas](#8-nombres-de-páginas)
9. [Archivos existentes](#9-archivos-existentes)
10. [Preguntas abiertas](#10-preguntas-abiertas)
11. [Próximos pasos](#11-próximos-pasos)

---

## 1. Problema

Cada vez que se crea un nuevo tracker en TikiWiki para trackerGram, hay que:

1. Ir a TikiWiki, crear dos páginas wiki a mano
2. Buscar los fieldIds uno por uno en Admin → Trackers → Fields
3. Copiar el template Smarty desde `opt/visualizacion-tiki.md`
4. Reemplazar manualmente cada `$f_XX` por el fieldId correcto
5. Hardcodear el `trackerId` en el TRACKERLIST
6. Verificar permisos (`tiki_p_use_as_template`)
7. Habilitar plugins HTML, mediaplayer

Si hay más de un tracker (como hoy: tracker 22 y lcc2026), el trabajo se duplica. Y si el template mejora, hay que actualizar N trackers manualmente.

---

## 2. Solución propuesta

Un asistente en el admin de trackerGram que permite:

1. **Seleccionar qué campos** del tracker aparecen en el feed
2. **Personalizar el nombre** de las páginas wiki que se van a crear
3. **Generar y subir automáticamente** dos páginas wiki vía API de TikiWiki:
   - La **página template** (tplwiki) con el Smarty de cada burbuja
   - La **página de visualización** con el `{TRACKERLIST(...)}` que usa el template

Todo desde el panel admin, sin tocar TikiWiki.

---

## 3. Experiencia de usuario

### 3.1 Acceso

Cada tarjeta de conexión en el admin tiene un botón: **🎨 Visualización**

Al clickearlo, se abre un modal/pantalla con el asistente.

### 3.2 Asistente paso a paso

```
┌─────────────────────────────────────────────────────┐
│  🎨 Deployar visualización                          │
│  Conexión: Grupo Soporte · Tracker 22                │
│                                                     │
│  ── Paso 1: Nombrar las páginas ──                   │
│                                                     │
│  Plantilla (template Smarty):                        │
│  [plantillaGrupoSoporte        ]  ↓  (slug auto)     │
│                                                     │
│  Página de visualización (TRACKERLIST):              │
│  [FeedGrupoSoporte             ]  ↓  (slug auto)     │
│                                                     │
│  ── Paso 2: Elegir campos a mostrar ──               │
│                                                     │
│  ☑ Todos / □ Ninguno                               │
│                                                     │
│  ┌─ Contenido ─────────────────────────────────┐    │
│  │ ☑ Mostrar nombre (DisplayName)              │    │
│  │ ☑ Mostrar texto (Text)                      │    │
│  │ ☑ Mostrar fecha (MessageDate)               │    │
│  │ ☐ Mostrar nombre de usuario (Username)      │    │
│  │ ☐ Mostrar título del chat (ChatTitle)       │    │
│  │ ☐ Mostrar reacciones (Reactions)            │    │
│  │ ☐ Mostrar hashtags (Hashtags)               │    │
│  └─────────────────────────────────────────────┘    │
│                                                     │
│  ┌─ Multimedia ────────────────────────────────┐    │
│  │ ☑ Mostrar multimedia (imagen/video/audio)   │    │
│  │ ☐ Mostrar caption del media                 │    │
│  │ ☐ Mostrar tipo MIME                         │    │
│  └─────────────────────────────────────────────┘    │
│                                                     │
│  ┌─ Referencias ───────────────────────────────┐    │
│  │ ☐ Mostrar hashtags como etiquetas (Freetag) │    │
│  │ ☑ Mostrar reply-to (preview inline)         │    │
│  │ ☐ Mostrar editado                           │    │
│  └─────────────────────────────────────────────┘    │
│                                                     │
│  ── Paso 3: Revisar y confirmar ──                   │
│                                                     │
│  Se van a crear/actualizar:                          │
│  • plantillaGrupoSoporte → con 8 campos seleccionados│
│  • FeedGrupoSoporte → TRACKERLIST + paginación       │
│                                                     │
│  [🔄 Vista previa]  [✅ Deployar]                    │
└─────────────────────────────────────────────────────┘
```

### 3.3 Vista previa

Un botón "Vista previa" que muestra cómo va a quedar el template antes de subirlo, renderizando un mensaje de ejemplo con los campos seleccionados.

### 3.4 Re-deploy

Si ya existe una visualización deployada, el botón cambia a **🔄 Actualizar**. Al abrir el modal, los campos ya vienen marcados según el deploy anterior y los nombres de página precargados.

---

## 4. Arquitectura

### 4.1 Componentes

| Componente | Archivo | Responsabilidad |
|------------|---------|-----------------|
| `VisualizationDeployer` | Nuevo: `VisualizationDeployer.php` | Orquesta todo: obtiene fields, compila, sube páginas |
| Template base | `templates/visualization/item_template_base.smarty` | Template Smarty con placeholders |
| Página base | `templates/visualization/page_template_base.txt` | Esqueleto de la página TRACKERLIST con placeholders |
| Handler admin | `admin_handlers.php` | Handler `deploy_visualization` (POST) + `get_tracker_fields` (GET) |
| JS admin | `admin.js` | Modal del asistente, selector de campos, vista previa |
| CSS admin | `admin.css` | Estilos del modal |

### 4.2 Flujo detallado

```
1. Usuario clickea "Visualización" en una conexión
   → admin.js abre el modal
   → GET admin.php?action=get_tracker_fields&slug=grupo-soporte
      → admin_handlers.php llama a TikiWikiClient->getFieldDefinitions()
      → Devuelve JSON con campos agrupados por categoría

2. Usuario selecciona campos, escribe nombres, clickea "Deployar"
   → POST admin.php?action=deploy_visualization
      Body: { slug, templatePageName, feedPageName, selectedFields: [...] }

3. admin_handlers.php:
   a. Instancia VisualizationDeployer
   b. Obtiene fieldIds reales vía API (cacheados en setup.json)
   c. Compila template base → Smarty con fieldIds seleccionados
   d. Compila página principal → TRACKERLIST con campos seleccionados
   e. POST a API TikiWiki para crear/actualizar ambas páginas
   f. Responde { success: true, urls: [urlPlantilla, urlFeed] }

4. admin.js muestra resultado con links a las páginas creadas
```

### 4.3 API de TikiWiki para páginas ✅ Confirmada

La API REST de TikiWiki 27.5 **sí incluye endpoints para páginas wiki**, accesibles con el mismo Bearer token que trackerGram ya usa.

| Acción | Endpoint | Método | Parámetros clave |
|--------|----------|--------|-----------------|
| **Crear** | `POST /api/wiki` | POST | `pageName`, `data`, `description`, `comment` |
| **Actualizar** | `POST /api/wiki/page/{page}` | POST | `data` (contenido), `comment`, `is_minor` |
| **Obtener** | `GET /api/wiki/page/{page}` | GET | — |
| **Listar** | `GET /api/wiki` | GET | `find`, `maxRecords` |

**Ejemplo creación**:
```
POST /api/wiki
Authorization: Bearer {token}
Content-Type: application/x-www-form-urlencoded

pageName=plantillaGrupoSoporte
&data={template compilado}
&description=Template para trackerGram - v0.6.6
&comment=Deploy automático desde trackerGram
```

**Ejemplo actualización**:
```
POST /api/wiki/page/plantillaGrupoSoporte
Authorization: Bearer {token}
Content-Type: application/x-www-form-urlencoded

data=Nuevo contenido del template
&comment=Actualizando template
```

**Permiso necesario**: `tiki_p_edit` — crear/editar páginas wiki. El usuario asociado al token debe tenerlo.

**Confirmado en código fuente** (27-07-2026):
- `ApiBridge.php` define las rutas `wiki`, `wiki-create`, `wiki-view`, `wiki-update`, etc.
- `lib/core/Services/Wiki/Controller.php` implementa `action_create_update_page()` que maneja tanto create como update.
- El controller usa `$tikilib->create_page()` y `$tikilib->update_page()`.
- El filtro `pagename` sanitiza `pageName` adecuadamente.
- El token Bearer se autentica en `tiki-setup_base.php` mediante `TikiLib::lib('api_token')->validToken()`.

---

## 5. Template base

### 5.1 Formato

Usa placeholders con doble llave `{{...}}`. No es Smarty válido — es un formato intermedio que trackerGram procesa para generar el Smarty final.

Cada placeholder se mapea a un permName del tracker. El deployer reemplaza `{{DISPLAY_NAME}}` por `$f_196` (o el fieldId que corresponda).

```smarty
{* Template base generado por trackerGram — no editar manualmente *}
{* Generado: {DATE} | Conexión: {CONNECTION_NAME} *}

<div id="item-{$f_itemId}" class="tg-message {{MESSAGE_TYPE_CLASS}}">

  <div class="tg-header">
    {{#DISPLAY_NAME}}
      <strong class="tg-name">{{DISPLAY_NAME}}</strong>
    {{/DISPLAY_NAME}}
    {{#USERNAME}}
      <span class="tg-username">@{{USERNAME}}</span>
    {{/USERNAME}}
    {{#MESSAGE_DATE}}
      <span class="tg-date">{{MESSAGE_DATE}}</span>
    {{/MESSAGE_DATE}}
  </div>

  {{#CHAT_TITLE}}
    <div class="tg-chat">{{CHAT_TITLE}}</div>
  {{/CHAT_TITLE}}

  {{#TEXT}}
    <div class="tg-text">{{TEXT}}</div>
  {{/TEXT}}

  {{#MEDIA_URL}}
    <div class="tg-media">
      {if {{MESSAGE_TYPE}} == 'photo' || {{MESSAGE_TYPE}} == 'sticker'}
        <a href="{{MEDIA_URL}}" data-box="shadowbox[tg];type=img"><img src="{{MEDIA_URL}}" /></a>
      {/if}
      {if {{MESSAGE_TYPE}} == 'video' || {{MESSAGE_TYPE}} == 'video_note'}
        {wikiplugin _name="html"}<video src="{{MEDIA_URL}}" controls style="width:100%;height:auto">{/wikiplugin}
      {/if}
      {if {{MESSAGE_TYPE}} == 'animation'}
        {wikiplugin _name="html"}<video src="{{MEDIA_URL}}" autoplay loop muted playsinline style="width:100%;height:auto">{/wikiplugin}
      {/if}
      {if {{MESSAGE_TYPE}} == 'audio' || {{MESSAGE_TYPE}} == 'voice'}
        {wikiplugin _name="mediaplayer" src={{MEDIA_URL}} type={{MEDIA_TYPE}}}{/wikiplugin}
      {/if}
      {if {{MESSAGE_TYPE}} == 'document'}
        {if preg_match('#^image/#', {{MEDIA_TYPE}})}
          <a href="{{MEDIA_URL}}" data-box="shadowbox[tg];type=img"><img src="{{MEDIA_URL}}" /></a>
        {elseif preg_match('#^video/#', {{MEDIA_TYPE}})}
          {wikiplugin _name="html"}<video src="{{MEDIA_URL}}" controls style="width:100%;height:auto">{/wikiplugin}
        {else}
          <a href="{{MEDIA_URL}}" target="_blank" class="tg-download">{{MEDIA_TYPE}}</a>
        {/if}
      {/if}
    </div>
    {{#MEDIA_CAPTION}}
      <div class="tg-caption"><em>{{MEDIA_CAPTION}}</em></div>
    {{/MEDIA_CAPTION}}
  {{/MEDIA_URL}}

  {{#LOCATION}}
    <div class="tg-location">
      {wikiplugin _name="map" coords={{LOCATION}}}{/wikiplugin}
    </div>
  {{/LOCATION}}

  {{#REPLY_TO_ID}}
    <div class="tg-reply">(...)</div>
  {{/REPLY_TO_ID}}

  {{#EDITED_DATE}}
    <div class="tg-edited">✏️ Editado {{EDITED_DATE}}</div>
  {{/EDITED_DATE}}

  {{#REACTIONS}}
    <hr class="my-2">
    <div class="tg-reactions">{{REACTIONS}}</div>
  {{/REACTIONS}}

  {{#HASHTAGS}}
    <div class="tg-hashtags">{{HASHTAGS}}</div>
  {{/HASHTAGS}}

</div>
```

### 5.2 Mapeo placeholder → permName

| Placeholder | permName | Categoría | Dependencias |
|-------------|----------|-----------|--------------|
| `{{DISPLAY_NAME}}` | `{prefix}DisplayName` | Contenido | — |
| `{{USERNAME}}` | `{prefix}Username` | Contenido | — |
| `{{MESSAGE_DATE}}` | `{prefix}MessageDate` | Contenido | — |
| `{{MESSAGE_TYPE}}` | `{prefix}MessageType` | Contenido | — |
| `{{MESSAGE_TYPE_CLASS}}` | `{prefix}MessageType` (lowercase) | Contenido | — |
| `{{TEXT}}` | `{prefix}Text` | Contenido | — |
| `{{CHAT_TITLE}}` | `{prefix}ChatTitle` | Contenido | — |
| `{{TOPIC_TITLE}}` | `{prefix}TopicTitle` | Contenido | — |
| `{{MEDIA_URL}}` | `{prefix}MediaUrl` | Multimedia | Requiere `MESSAGE_TYPE` |
| `{{MEDIA_TYPE}}` | `{prefix}MediaType` | Multimedia | Requiere `MEDIA_URL` |
| `{{MEDIA_CAPTION}}` | `{prefix}MediaCaption` | Multimedia | Requiere `MEDIA_URL` |
| `{{MEDIA_WIDTH}}` | `{prefix}MediaWidth` | Multimedia | — |
| `{{MEDIA_HEIGHT}}` | `{prefix}MediaHeight` | Multimedia | — |
| `{{MEDIA_DURATION}}` | `{prefix}MediaDuration` | Multimedia | — |
| `{{LOCATION}}` | `{prefix}Location` | Multimedia | — |
| `{{REPLY_TO_ID}}` | `{prefix}ReplyToId` | Referencias | — |
| `{{EDITED_DATE}}` | `{prefix}EditedDate` | Referencias | — |
| `{{REACTIONS}}` | `{prefix}Reactions` | Referencias | — |
| `{{HASHTAGS}}` | `{prefix}Hashtags` | Referencias | — |

### 5.3 Condicionales

`{{#PLACEHOLDER}}...contenido...{{/PLACEHOLDER}}` → se traduce a `{if $f_XX}...contenido...{/if}` en Smarty.

---

## 6. Compilación

### 6.1 Algoritmo

```
1. Cargar template base desde templates/visualization/item_template_base.smarty
2. Obtener field definitions del tracker via GET /api/trackers/{id}/fields
3. Para cada campo seleccionado por el usuario:
   a. Buscar fieldId cuyo permName coincida (ej: telegrammessageDisplayName → 196)
   b. Reemplazar {{PLACEHOLDER}} por $f_{fieldId}
   c. Si el placeholder no está seleccionado, eliminar todo el bloque {{#...}}...{{/...}}
4. Para condicionales: {{#PLACEHOLDER}} → {if $f_{fieldId}}, {{/PLACEHOLDER}} → {/if}
5. Generar el Smarty final
```

### 6.2 Si un campo seleccionado no existe en el tracker

Si el usuario seleccionó "Mostrar reacciones" pero el tracker no tiene campo `{prefix}Reactions`, se omite silenciosamente con una advertencia en el resultado del deploy ("Campo 'Reactions' no encontrado en el tracker — omitido").

### 6.3 Página de visualización

Se genera la página TRACKERLIST:

```
{DIV(class="tg-feed")}
{TRACKERLIST(
    trackerId="{TRACKER_ID}",
    fields="{FIELD_IDS}",
    sort_mode="{SORT_FIELD}_desc",
    max="{MAX_ITEMS}",
    showpagination="y",
    status="opc",
    tplwiki="{TEMPLATE_PAGE}"
) /}
{DIV}
```

Donde:
- `{TRACKER_ID}` → de setup.json
- `{FIELD_IDS}` → lista de fieldIds de los campos seleccionados (para acelerar la consulta)
- `{SORT_FIELD}` → fieldId de MessageDate
- `{MAX_ITEMS}` → configurable (default 50)
- `{TEMPLATE_PAGE}` → nombre que el usuario eligió para la plantilla

También se incluye el CSS inline (como hoy), usando el prefijo `tg-` para los selectores.

---

## 7. Selector de campos

### 7.1 Lógica de agrupación

Los campos se agrupan en categorías según su permName:

| Categoría | Campos incluidos |
|-----------|-----------------|
| **Básicos** | DisplayName, MessageType, MessageDate |
| **Texto** | Text, ChatTitle, TopicTitle |
| **Usuario** | Username, FirstName, LastName, UserId |
| **Multimedia** | MediaUrl, MediaType, MediaCaption, MediaWidth, MediaHeight, MediaDuration |
| **Referencias** | ReplyToId, EditedDate, Reactions |
| **Tags** | Hashtags |
| **Ubicación** | Location |
| **Identificadores** | MessageId, ChatId, TopicId, FileUniqueId |

### 7.2 Defaults

Por defecto, al abrir el asistente por primera vez, vienen preseleccionados:
- DisplayName, MessageType, MessageDate, Text
- MediaUrl, MediaType, MediaCaption
- ReplyToId, EditedDate, Reactions, Hashtags

Igual que el template actual de `opt/visualizacion-tiki.md`.

### 7.3 Persistencia

La selección de campos se persiste en `setup.json` para cada conexión, así al re-deployar se mantienen las preferencias:

```json
{
  "slug": "grupo-soporte",
  "visualization_fields": ["DISPLAY_NAME", "TEXT", "MESSAGE_DATE", "MEDIA_URL", ...],
  "visualization_template_page": "plantillaGrupoSoporte",
  "visualization_feed_page": "FeedGrupoSoporte"
}
```

---

## 8. Nombres de páginas

### 8.1 Defaults auto-generados

| Página | Default |
|--------|---------|
| Template (tplwiki) | `plantilla{SlugConexion}` — ej: `plantillaGrupoSoporte` |
| Feed (TRACKERLIST) | `Feed{SlugConexion}` — ej: `FeedGrupoSoporte` |

### 8.2 Validación

- Solo caracteres alfanuméricos + underscore (nombres de página wiki)
- Sin espacios
- Máximo 100 caracteres
- No pueden empezar con número

### 8.3 Conflicto de nombres

Antes de deployar, trackerGram verifica si las páginas ya existen en TikiWiki (vía API):
- Si no existen → las crea
- Si existen → las actualiza (previo aviso al usuario)

---

## 9. Archivos existentes

| Archivo | Qué pasa con él |
|---------|----------------|
| `opt/visualizacion-tiki.md` | Se mantiene como documentación de referencia histórica. El source de verdad pasa a `templates/visualization/`. |
| `opt/visualizacion-lcc2026.md` | Idem. Se agrega nota: "reemplazado por deploy automático desde v0.7.0". |
| `opt/docker.md` y `opt/shared_hosting.md` | Sin cambios. |

---

## 10. Preguntas abiertas

### 10.1 API de páginas wiki de TikiWiki ✅ Resuelta

**Sí existe.** `POST /api/wiki` (crear) y `POST /api/wiki/page/{page}` (actualizar) funcionan con el mismo Bearer token que trackerGram ya usa. Ver sección 4.3 para detalle completo.

Confirmado en: `ApiBridge.php` rutas wiki-create y wiki-update, `lib/core/Services/Wiki/Controller.php` action_create_update_page().

### 10.2 CSS del template

El template base actual genera burbujas con clases `tg-message`, `tg-header`, etc. El CSS puede vivir:
- **Inline en la página de visualización** (como hoy)
- **En el tema de TikiWiki** (como mostró el usuario con su plantilla `tn-*`)
- En ambos lados a la vez (el deploy puede generarlo en la página y el usuario moverlo al tema si quiere)

### 10.3 Permisos

El usuario de TikiWiki necesita `tiki_p_edit_page` para crear/editar páginas. Si el token no tiene ese permiso, el deploy falla con un mensaje claro.

### 10.4 Plugins TikiWiki necesarios

El template usa `{wikiplugin _name="html"}`, `{wikiplugin _name="mediaplayer"}`, etc. El deploy podría verificar si están habilitados (o documentar que el admin de TikiWiki los active).

---

## 11. Implementación ✅

1. ✅ **Investigar API de páginas wiki** — **Confirmada**: `POST /api/wiki` y `POST /api/wiki/page/{page}`
2. ✅ **Crear template base** en `templates/visualization/item_template_base.smarty`
3. ✅ **Implementar `VisualizationDeployer`**:
   - `resolveFieldIds(int $trackerId, string $prefix, array $selected): array` — obtiene field definitions del tracker. fieldMap = TODOS los existentes, selected = bloques visibles
   - `compileTemplate(string $templateBase, array $fieldMap, array $selected): string` — reemplaza placeholders, recursivo para condicionales anidados
   - `compileFeedPage(int $trackerId, array $fieldIds, string $templatePageName, string $sortFieldId, int $maxItems): string` — genera TRACKERLIST
   - `deployPages(string $templateContent, string $feedContent, string $templatePageName, string $feedPageName): array` — POST a /api/wiki (crear/actualizar)
4. ✅ **Agregar handlers en admin_handlers.php**:
   - `get_visualization_fields` — POST AJAX, devuelve campos agrupados + preferencias guardadas
   - `deploy_visualization` — POST AJAX, ejecuta el deploy y persiste preferencias
5. ✅ **Armar UI del asistente** en admin.js + admin.php (modal con selector de campos, nombres, deploy)
6. ✅ **Persistir preferencias** en setup.json (visualization_fields, visualization_template_page, visualization_feed_page, visualization_max_items)
7. ⏳ **Probar**: deployar a tracker 22 y lcc2026 desde el admin (requiere verificación manual en TikiWiki con usuario con tiki_p_edit)
