# 999 — A Tener en Cuenta: Seguridad en trackerGram ↔ TikiWiki

> **Contexto**: Análisis de seguridad realizado sobre TikiWiki 27.5 (trackerlib.php) + superficie de ataque trackerGram.
> **Fecha**: Junio 2026
> **Última revisión**: Julio 2026 (v0.6.2)
> **Aplica a**: trackerGram — puente Telegram → TikiWiki
> **Propósito**: Documentar vulnerabilidades conocidas de TikiWiki + vectores de inyección que trackerGram podría introducir al escribir datos de Telegram en TikiWiki.

---

## Resumen

Dos riesgos distintos:

1. **SQL injection en TikiWiki** (vulnerabilidad confirmada en `list_items()`). trackerGram se protege usando solo API REST y nunca llama a funciones internas de listado.

2. **Inyección de código malicioso a través de los valores de campos del tracker**. Los mensajes de Telegram (texto, captions, nombres de usuario) se guardan LITERALMENTE en TikiWiki. Si TikiWiki renderiza esos valores sin escaparlos (template Smarty, vista HTML, exportación), código como `<script>alert(1)</script>` podría ejecutarse en el navegador de quien vea el tracker. trackerGram debe asegurarse de que el contenido que escribe no pueda ser interpretado como código ejecutable.

---

## 1. Vulnerabilidad Confirmada: SQL Injection en `list_items()`

| Dónde | Detalle |
|---|---|
| **Archivo** | `lib/trackers/trackerlib.php` línea 1320 |
| **Función** | `list_items()` |
| **Tipo** | SQL Injection en JOIN (blind exploitation posible) |

### Causa

El parámetro `$sort_mode` se parte por `_` y el segundo fragmento (`$asort_mode`) se concatena directamente al SQL sin sanitizar:

```php
$sort_tables = ' LEFT JOIN (`tiki_tracker_item_fields` sttif)'
    . ' ON (tti.`itemId` = sttif.`itemId`'
    . (! empty($asort_mode) ? " AND sttif.`fieldId` = $asort_mode" : '')
    . ')';
```

### Vectores de ataque

- **`{TRACKERLIST}` plugin**: acepta `tr_sort_mode` vía `$_REQUEST` sin validación (wikiplugin_trackerlist.php:1639-1640)
- **`list_items()` directo**: cualquier llamada que pase `$sort_mode` sin sanitizar

### Ejemplo

Request: `?tr_sort_mode=f_1 OR 1=1_asc`

Produce:
```sql
LEFT JOIN (tiki_tracker_item_fields sttif)
    ON (tti.itemId = sttif.itemId AND sttif.fieldId = 1 OR 1=1)
```

El `OR 1=1` anula el filtro por fieldId, mostrando TODOS los items.

### Exploitabilidad

| Factor | Detalle |
|---|---|
| Autenticación | No requerida — `tiki_p_view_trackers` suele estar en nivel basic |
| Requiere tracker activo | Sí — necesita al menos un fieldId existente |
| Tipo de campo | La mayoría NO sobreescriben `$sort_tables` |
| Limitación | El payload no puede contener `_` (delimitador del split) |
| Ataque realista | Blind SQL injection vía respuestas booleanas (OR + condición) |

---

## 2. Camino de Escritura: Seguro

`replace_item()` → `insertOrUpdate()` en trackerlib.php:2437 usa **bind vars** (placeholders `?`).

```php
$this->itemFields()->insertOrUpdate(
    ['value' => $value],
    ['itemId' => (int) $itemId, 'fieldId' => (int) $field['fieldId']]
);
```

✅ Los valores de mensajes de Telegram se guardan literalmente sin riesgo de inyección.

---

## 3. Tracker_Query (ORM moderno): Seguro

Usa placeholders `?` y tiene check de seguridad explícito que rechaza keys no permitidas (Tracker/Query.php:820-862).

---

## 4. `parse_filter()`: Seguro

Construye condiciones con `?` y `$bindvars` (trackerlib.php:3333).

---

## 5. Inyección de Código vía Valores de Campos del Tracker

### El problema

trackerGram toma contenido de mensajes de Telegram y lo guarda en campos del tracker de TikiWiki. Ese contenido puede incluir texto arbitrario, incluyendo HTML malicioso:

```
Mensaje en Telegram:  <script>alert('XSS')</script>
Se guarda en tracker:  <script>alert('XSS')</script>  (literal)
```

Si TikiWiki renderiza ese valor sin escaparlo en alguna vista (tracker list, item view, export, email notification, RSS feed), el script se ejecuta.

### ¿Quién debe escapar?

| Capa | Responsable | Status en TikiWiki |
|------|-------------|-------------------|
| API REST (escritura) | **No debe escapar** | Recibe datos crudos, los guarda con bind vars |
| Vista Smarty (lectura) | **Debe escapar** | Templates usan `{$field.value|escape}` o similar |
| trackerGram `toWikiFields()` | ⚠️ **Defensa en profundidad** | No debe confiar ciegamente en que TikiWiki escape siempre |

### Telegram: texto plano, sin HTML

La Telegram Bot API envía mensajes como texto plano. El formato (negrita, itálica, links) se representa como `entities[]` separado del texto:

```json
{
  "text": "Hola <b>mundo</b>",
  "entities": [
    {"type": "bold", "offset": 5, "length": 5}
  ]
}
```

El texto `"Hola <b>mundo</b>"` NO es HTML — son caracteres literales `<`, `b`, `>`. Si alguien escribe `<script>` en Telegram, es texto plano que trackerGram guarda textualmente. `strip_tags()` no pierde información legítima porque Telegram **nunca envía HTML como formato**.

### Mitigación: `strip_tags()` en escritura

Aplicar `strip_tags()` a todo texto de usuario ANTES de enviarlo a TikiWiki elimina cualquier etiqueta HTML:

| Campo | Contenido | strip_tags |
|-------|-----------|------------|
| `Text` | Mensaje de Telegram | ✅ Seguro — Telegram es texto plano |
| `MediaCaption` | Descripción de archivo | ✅ Seguro |
| `DisplayName` / `FirstName` / `LastName` | Nombre de usuario | ✅ Seguro |
| `ChatTitle` / `TopicTitle` | Nombre del grupo/tema | ✅ Seguro (controlado por admin del grupo, pero defensa en profundidad) |
| `Username` | @username | ✅ Seguro |
| `Reactions` | Reacciones formateadas (salida controlada por trackerGram) | ✅ Seguro |
| `Location` | Coordenadas GPS | ✅ Número, sin HTML |

**`strip_tags()` vs `htmlspecialchars()`**: `strip_tags()` remueve etiquetas HTML del input antes de guardarlo. `htmlspecialchars()` convierte caracteres especiales a entidades HTML. En la capa de API, `htmlspecialchars()` está contraindicado porque convierte `"` en `&quot;` que se guarda literalmente. `strip_tags()` es seguro porque Telegram no tiene HTML legítimo que preservar.

### Defensa actual

Por el momento trackerGram **no aplica `strip_tags()`** y confía en que TikiWiki escape correctamente en sus templates Smarty. Esta sección documenta la recomendación de implementar `strip_tags()` como defensa en profundidad.

### Mecanismos adicionales

1. **NUNCA renderizar valores de campos del tracker como HTML en el admin de trackerGram** — usar `safeRender()` con `textContent` (✅ ya implementado, fix XSS previo).
2. **Content-Security-Policy** en `.htaccess` (✅ ya implementado, CSP header).
3. **Validar que TikiWiki escape en todos los templates** (responsabilidad del admin de TikiWiki).

---

## 6. Implicaciones para trackerGram

### Riesgo por operación

| Operación | Riesgo | Motivo |
|---|---|---|
| Crear items (webhook) | **Bajo** | Storage usa bind vars |
| Actualizar items | **Bajo** | Mismo mecanismo seguro |
| Consultar items con filtros desde Telegram | **MEDIO** | Si `sort_mode` viene del input del usuario de Telegram |
| Usar `{TRACKERLIST}` o `list_items()` directo | **ALTO** | Plugin vulnerable vía `$_REQUEST`; API REST es segura |

### Recomendaciones obligatorias

1. **NO usar `{TRACKERLIST}` ni `list_items()` directo** con parámetros que vienen del usuario de Telegram
2. **Usar SIEMPRE la API REST** de TikiWiki para consultas:
   ```
   /tiki-ajax_services.php?controller=tracker&action=list_items
   ```
   La API REST filtra inputs con `$input->int()`, `$input->word()`, etc.
3. **Validar tipos**: convertir a entero cualquier parámetro numérico antes de pasarlo
4. **Mínimo privilegio**: el bot debe conectarse con un usuario Tiki que tenga solo:
   - `tiki_p_tracker_view` (ver items)
   - `tiki_p_create_tracker_items` (crear items)
   - **Sin** `tiki_p_admin_trackers`
5. **Rate limiting**: limitar consultas por usuario de Telegram (ej: máx 30/min)
6. **Logging**: registrar todas las operaciones del bot para auditoría
7. **NUNCA pasar parámetros crudos** de mensajes de Telegram a funciones de listado de TikiWiki

### Regla de oro

| Si tu bot hace... | Usá... | Por qué |
|---|---|---|
| Escribir en trackers | `replace_item()`, API REST, `Tracker_Query` | Siempre usan bind vars ✅ |
| Leer trackers | API REST de Tiki (`Services/Tracker/Controller`) | Filtra inputs con `$input->int()` ✅ |
| **NO USES** | `$_REQUEST`, `list_items()` directo, `{TRACKERLIST}` | Validación incompleta ❌ |

---

## 7. Evaluación contra trackerGram Actual (v0.6.2)

### Resumen

**trackerGram ya es seguro frente a SQL injection en TikiWiki.** El código actual usa la API REST de TikiWiki para TODAS las operaciones, tanto de lectura como de escritura. Nunca llama a `list_items()` internamente ni usa el plugin `{TRACKERLIST}`.

**Pendiente**: defensa en profundidad contra inyección de código vía valores de campos (XSS). Actualmente trackerGram confía en que TikiWiki escape correctamente en sus vistas. Ver §5.

### Análisis operación por operación

#### Escritura (crear/actualizar items) ✅ Seguro

| Flujo | Cómo se hace | Por qué es seguro |
|-------|-------------|-------------------|
| Webhook (`api.php` → `WebhookHandler`) | `TikiWikiClient::createTrackerItem()` → POST `fields[permName]=valor` vía `http_build_query()` | Va por API REST que usa `Tracker_Query` ORM con bind vars. El contenido del mensaje de Telegram se serializa a form-urlencoded. |
| Actualizar item (editado) | `TikiWikiClient::updateTrackerItem()` → POST `fields[permName]=valor` | Ídem. Solo Text+EditedDate+Reactions. |
| Subir archivo | `TikiWikiClient::uploadFile()` → `POST /api/galleries/upload` | API REST. Sube a file gallery por token. |
| Adjuntar media a álbum | `TikiWikiClient::appendMediaToTrackerItem()` | Modifica `option[files]` del campo FG vía `POST /api/trackers/{id}/fields/{id}`. Idempotente: chequea fileId duplicado. |
| Import (`import.php`) | Mismo `createTrackerItem()` | Ídem. El texto del export ZIP va como valor de campo. |
| Async worker (`worker.php`) | Mismo `createTrackerItem()` desde buffer JSON | Ídem. Los datos viajan serializados y se recrean como POST fields. |
| Crear tracker | `TikiWikiClient::createTracker()` → `POST /api/trackers` | API REST. Field prefix sanitizado con regex `[a-z0-9]+`, max 16 chars. |

#### Lectura (consultar items) ✅ Seguro

| Flujo | Cómo se hace | Por qué es seguro |
|-------|-------------|-------------------|
| **Deduplicación** (`messageExists()`) | `GET /api/trackers/{id}/items?filter[fields][...]=valor` | Usa API REST de TikiWiki. El controlador filtra inputs con `$input->int()`, `$input->word()`. `$messageId` type-hinted `int\|string`, `$chatId` como `?int`. |
| **Deduplicación mejorada** (`findItemByMessageId()`) | `GET /api/trackers/{id}/items?filter[fields][...]=valor&maxRecords=1` | Misma API REST. Ambos parámetros con `urlencode()`. Type hints: `int\|string $messageId`, `int $chatId`. |
| **Obtener item individual** (`getTrackerItem()`) | `GET /api/trackers/{id}/items/{itemId}` | API REST. `$itemId` es `int`. |
| **Auto-detección de prefix** (`resolveFieldPrefix()`, `loadTrackerFields()`) | `GET /api/trackers/{id}/fields` | API REST de solo lectura. No acepta parámetros de usuario. |
| **Gallery ID** (`getMediaGalleryId()`) | `GET /api/trackers/{id}/fields` (cacheado) | Ídem. |
| **Sincronizar campos** (`synchronizeTrackerFields()`) | `GET /api/trackers/{id}/fields` + `POST /api/trackers/{id}/fields` | Crea campos faltantes vía API REST. Input validado. |
| **Versión TikiWiki** (`getVersion()`) | `GET /api/tikiinfo` | Solo lectura, no acepta parámetros. |

#### Configuración (admin) ✅ Seguro

| Flujo | Por qué es seguro |
|-------|------------------|
| **Crear tracker** | El field prefix se sanitiza con `preg_replace('/[^a-zA-Z0-9]/', '', $rawPrefix)` antes de persistir. Solo alfanumérico. |
| **Test conexión** | Usa API REST de TikiWiki para verificar credenciales. No expone parámetros de listado. |
| **Config webhook** | Llama a API de Telegram, no de TikiWiki. |

### Mecanismos defensivos adicionales ya presentes

1. **No existe `sort_mode`** en ningún endpoint de trackerGram — no hay forma de que ese vector entre
2. **No se usa `{TRACKERLIST}`** — ni como plugin wiki ni como endpoint
3. **Valores type-hinted** — `findItemByMessageId(int|string $messageId, int $chatId)` fuerza coerción a tipos. Todos los IDs son `int`.
4. **Prefix validado** — solo `[a-z0-9]+`, max 16 chars, debe empezar con letra (doble capa: admin + server-side)
5. **Token por conexión** — cada conexión tiene su propio token TikiWiki, limitando daño si uno se compromete
6. **Sin DB con servidor** — cero superficie de ataque del lado de trackerGram. Estado local en JSON, no SQLite.
7. **SSRF / DNS rebinding prevention** — `CURLOPT_RESOLVE` fuerza la IP resuelta en todos los calls curl. `resolveHostToIp()` soporta IPv4 e IPv6. `validateConnectionData()` reporta IP privada.
8. **SSL verification forzada** — `CURLOPT_SSL_VERIFYPEER=true` + `CURLOPT_SSL_VERIFYHOST=2` en TODOS los 23+ handles curl de TikiWikiClient.
9. **Lock TOCTOU** — exclusión mutua por `(chatId:messageId)` serializa creación de items entre webhooks concurrentes.
10. **Rate limiting con flock** — `fopen('c+')` + `flock(LOCK_EX)` en vez de `file_put_contents()` sin lock. GC probabilístico (1% por request) de rate files stale.
11. **Token masking en admin** — las conexiones se renderizan con tokens truncados (`1234...5678`) en HTML, no expuestos.
12. **Host header sanitizado** — `generateWebhookUrl()` rechaza hosts con caracteres inválidos. Fallback a `SERVER_NAME`.
13. **Content-Length check** — api.php rechaza payloads > 1MB (DoS protection).
14. **Fan-out con try-catch individual** — si una conexión falla, no rompe las demás.

---

## 8. Evaluación contra el Roadmap

Proyección de cada item del roadmap actual sobre el riesgo de SQL injection:

### ✅ Fase 2: Robustez (completada)

| # | Item | ¿Introduce riesgo? | Estado |
|---|------|-------------------|--------|
| 2 | **Health check en admin** | ❌ No | ✅ Implementado. Solo admin autenticado. Verifica conexión vía API REST/Telegram. |
| 3 | **Mensajes editados/borrados** | ❌ No | ✅ Implementado. `processEditedMessage()` actualiza solo Text+EditedDate+Reactions vía `updateTrackerItem()` (API REST). |
| 4 | **Verificación post-creación de FG field** | ❌ No | ✅ Implementado. Usa `GET /api/trackers/{id}/fields` (API REST). |
| 5 | **Reproducción de mensajes previos a nuevo tracker (import)** | ❌ No | ✅ Implementado. Lee historial de **Telegram** (no TikiWiki) y escribe items nuevos en TikiWiki. Solo escritura. |

### 🟢 Fase 3: Features grandes

| # | Item | ¿Introduce riesgo? | Estado |
|---|------|-------------------|--------|
| 6 | **Mensajes estructurados con prefijos** | ❌ No | ⏳ Pendiente. Parser en `MessageMapper` solo. Transforma texto → campos. No consulta TikiWiki. |
| 7 | **Manejo de errores estandarizado** | ❌ No | ✅ Implementado. `exceptions.php` con jerarquía `TrackerGramException`. No toca queries. |
| 8 | **Import CLI asíncrono** | ❌ No | ⏳ Pendiente. Mismo flujo que import.php pero sin timeout HTTP. Solo escritura. |
| 9 | **Mega-import / chunked** | ❌ No | ✅ Implementado. `import.php` con `handleProcess()` chunked + NDJSON + barra de progreso. Solo escritura. |
| 10 | **Álbumes atómicos** | ❌ No | ✅ Implementado. Buffer `media_group_album.json` con flock. Solo escritura. |

### 🔵 Fase 4: Visión

| # | Item | ¿Introduce riesgo? | Evaluación |
|---|------|-------------------|------------|
| 11 | **Mini App** (Web App) | ⚠️ **MEDIO** | Si incluye búsqueda/consulta de items donde el usuario de Telegram controle filtros, podría exponerse. Ver recomendaciones en §6. |
| 12 | **Dashboard de métricas** | ❌ Bajo | Admin-only. Consultas a API REST predefinidas. |
| 13-18 | Tests, autoloading, transcripción, SQLite, logs, expulsar bot | ❌ No | No tocan queries a TikiWiki. |

### Recomendaciones específicas para la Mini App (Item 11)

Si la Mini App incluye funcionalidad de **buscar/consultar items** del tracker:

1. **Solo API REST** — toda query debe ir por `/api/trackers/{id}/items` con filtros predefinidos
2. **Whitelist de filtros** — no dejar que el usuario pase fieldNames arbitrarios. Mapear intenciones a filtros
3. **Sanitizar input** — convertir a `int` IDs, usar `urlencode()` para strings que van en URL
4. **Nunca construir URLs de query concatenando strings** del usuario de Telegram
5. **El frontend de la Mini App** debe ser solo presentación; la lógica de filtrado va en backend (trackerGram)

---

## 9. Conclusión para trackerGram

### Estado actual: ✅ bajo control

trackerGram **ya cumple** con la regla de oro del informe de seguridad:

| Operación | Usa | Estatus |
|-----------|-----|---------|
| Escribir items | API REST (`POST /api/trackers/{id}/items`) | ✅ Seguro |
| Leer items | API REST (`GET /api/trackers/{id}/items?filter=...`) | ✅ Seguro |
| Configurar | Admin autenticado + validación | ✅ Seguro |

### Lo que no hay que perder de vista

| Riesgo | Cuándo preocuparse | Acción preventiva |
|--------|-------------------|-------------------|
| XSS via valores de campos | Si TikiWiki no escapa en todos sus templates (custom Smarty, export, notificaciones) | `strip_tags()` en trackerGram antes de enviar a TikiWiki (ver §5) |
| Mini App con búsqueda | Si Item 11 agrega consultas desde usuario Telegram | Validar todos los inputs antes de pasarlos a la API REST |
| Plugin `{TRACKERLIST}` | Si alguien pone un TRACKERLIST en una página wiki pública visible | trackerGram no controla el contenido de TikiWiki — es riesgo del admin de TikiWiki |
| `list_items()` directo | Si algún futuro refactor toca trackerlib internas | Nunca implementar. Siempre API REST. |

### Checklist para incorporar en PR de nuevas features

- [ ] ¿La feature lee datos de TikiWiki? → Debe ir por API REST
- [ ] ¿La feature recibe input del usuario de Telegram? → Validar tipo + sanitizar
- [ ] ¿La feature construye URLs de API con datos variables? → Usar `http_build_query()` o concatenación con valores validados
- [ ] ¿La feature expone algún endpoint nuevo? → Revisar que no acepte `sort_mode`, `filterfield` sin validar
- [ ] ¿La feature guarda texto de usuario en campos del tracker? → Aplicar `strip_tags()` antes de enviar

---

## Referencias

- `lib/trackers/trackerlib.php` — función `list_items()` (línea ~1320)
- `lib/wiki-plugins/wikiplugin_trackerlist.php` — parámetro `tr_sort_mode` (línea ~1639)
- `lib/trackers/Tracker/Query.php` — ORM moderno seguro (línea ~820-862)
- API REST: `/tiki-ajax_services.php?controller=tracker&action=list_items`
- Código trackerGram: `TikiWikiClient.php` (funciones `messageExists`, `findItemByMessageId`, `getTrackerItem`, `createTrackerItem`, `updateTrackerItem`, `appendMediaToTrackerItem`, `loadTrackerFields`, `getMediaGalleryId`, `synchronizeTrackerFields`)
