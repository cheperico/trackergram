# 999 — A Tener en Cuenta: Seguridad en Consultas a TikiWiki

> **Contexto**: Análisis de seguridad realizado sobre TikiWiki 27.5 (trackerlib.php).
> **Fecha**: Junio 2026
> **Aplica a**: trackerGram — puente Telegram → TikiWiki
> **Propósito**: Documentar vulnerabilidades conocidas del lado de TikiWiki que afectan las decisiones de diseño de trackerGram.

---

## Resumen

TikiWiki es **seguro para escribir** (crear/actualizar items) pero tiene una **vulnerabilidad de SQL injection confirmada en el camino de lectura** (listado de items). trackerGram debe diseñarse para **solo usar la API REST** de TikiWiki y **nunca pasar parámetros crudos de Telegram a funciones internas de listado**.

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

## 5. Implicaciones para trackerGram

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

## 6. Evaluación contra trackerGram Actual (v0.5.4)

### Resumen

**trackerGram ya es seguro frente a esta vulnerabilidad.** El código actual usa la API REST de TikiWiki para TODAS las operaciones, tanto de lectura como de escritura. Nunca llama a `list_items()` internamente ni usa el plugin `{TRACKERLIST}`.

### Análisis operación por operación

#### Escritura (crear/actualizar items) ✅ Seguro

| Flujo | Cómo se hace | Por qué es seguro |
|-------|-------------|-------------------|
| Webhook (`api.php` → `WebhookHandler`) | `TikiWikiClient::createTrackerItem()` → POST `fields[permName]=valor` vía `http_build_query()` | Va por API REST que usa `Tracker_Query` ORM con bind vars. El contenido del mensaje de Telegram se serializa a form-urlencoded. |
| Import (`import.php`) | Mismo `createTrackerItem()` | Ídem. El texto del export ZIP va como valor de campo. |
| Async worker (`worker.php`) | Mismo `createTrackerItem()` desde buffer JSON | Ídem. Los datos viajan serializados y se recrean como POST fields. |

#### Lectura (consultar items) ✅ Seguro

| Flujo | Cómo se hace | Por qué es seguro |
|-------|-------------|-------------------|
| **Deduplicación** (`messageExists()`) | `GET /api/trackers/{id}/items?filter[fields][...]=valor` | Usa API REST de TikiWiki. El controlador filtra inputs con `$input->int()`, `$input->word()`. Además, `$messageId` y `$chatId` están type-hinted como `int`. |
| **Auto-detección de prefix** (`resolveFieldPrefix()`) | `GET /api/trackers/{id}/fields` | API REST de solo lectura. No acepta parámetros de usuario. |
| **Gallery ID** (`getMediaGalleryId()`) | `GET /api/trackers/{id}/fields` | Ídem. |

#### Configuración (admin) ✅ Seguro

| Flujo | Por qué es seguro |
|-------|------------------|
| **Crear tracker** | El field prefix se sanitiza con `preg_replace('/[^a-zA-Z0-9]/', '', $rawPrefix)` antes de persistir. Solo alfanumérico. |
| **Test conexión** | Usa API REST de TikiWiki para verificar credenciales. No expone parámetros de listado. |
| **Config webhook** | Llama a API de Telegram, no de TikiWiki. |

### Mecanismos defensivos adicionales ya presentes

1. **No existe `sort_mode`** en ningún endpoint de trackerGram — no hay forma de que ese vector entre
2. **No se usa `{TRACKERLIST}`** — ni como plugin wiki ni como endpoint
3. **Valores type-hinted** — `messageExists(int $messageId, ?int $chatId)` fuerza coerción a entero
4. **Prefix validado** — solo `[a-z0-9]+` (admin lo sanitiza; auto-detección lee de campos TikiWiki que son alfanuméricos por definición)
5. **Token por conexión** — cada conexión tiene su propio token TikiWiki, limitando daño si uno se compromete
6. **Sin DB con servidor** — cero superficie de ataque del lado de trackerGram. Estado local en JSON, no SQLite (binario, no human-editable, dependencia de ext-sqlite3).

---

## 7. Evaluación contra el Roadmap

Proyección de cada item del roadmap actual sobre el riesgo de SQL injection:

### 🟡 Fase 2: Robustez

| # | Item | ¿Introduce riesgo? | Evaluación |
|---|------|-------------------|------------|
| 2 | **Health check en admin** | ❌ No | Solo admin autenticado. Verifica conexión vía API REST/Telegram. |
| 3 | **Mensajes editados/borrados** | ❌ No | Estrategia definida: archivo inmutable con eventos. Los editados/borrados son **eventos adicionales** (crear items nuevos), no modifican ni consultan items existentes. |
| 4 | **Verificación post-creación de FG field** | ❌ No | Usa `GET /api/trackers/{id}/fields` (API REST). Ya planeado. |
| 5 | **Reproducción de mensajes previos a nuevo tracker** | ❌ No | Lee historial de **Telegram** (no TikiWiki) y escribe items nuevos en TikiWiki. Solo escritura. |

### 🟢 Fase 3: Features grandes

| # | Item | ¿Introduce riesgo? | Evaluación |
|---|------|-------------------|------------|
| 6 | **Mensajes estructurados con prefijos** | ❌ No | Parser en `MessageMapper` solo. Transforma texto → campos. No consulta TikiWiki. |
| 7 | **Manejo de errores estandarizado** | ❌ No | Excepciones de dominio. No toca queries. |
| 8 | **Import CLI asíncrono** | ❌ No | Mismo flujo que import.php pero sin timeout HTTP. Solo escritura. |

### 🔵 Fase 4: Visión

| # | Item | ¿Introduce riesgo? | Evaluación |
|---|------|-------------------|------------|
| 9 | **Mini App** (Web App) | ⚠️ **MEDIO** | Si incluye búsqueda/consulta de items donde el usuario de Telegram controle filtros, podría exponerse. Ver recomendaciones abajo. |
| 10 | **Dashboard de métricas** | ❌ Bajo | Admin-only. Consultas a API REST predefinidas. |
| 11-16 | Tests, autoloading, transcripción, SQLite, logs, expulsar bot | ❌ No | No tocan queries a TikiWiki. |

### Recomendaciones específicas para la Mini App (Item 9)

Si la Mini App incluye funcionalidad de **buscar/consultar items** del tracker:

1. **Solo API REST** — toda query debe ir por `/api/trackers/{id}/items` con filtros predefinidos
2. **Whitelist de filtros** — no dejar que el usuario pase fieldNames arbitrarios. Mapear intenciones a filtros
3. **Sanitizar input** — convertir a `int` IDs, usar `urlencode()` para strings que van en URL
4. **Nunca construir URLs de query concatenando strings** del usuario de Telegram
5. **El frontend de la Mini App** debe ser solo presentación; la lógica de filtrado va en backend (trackerGram)

---

## 8. Conclusión para trackerGram

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
| Mini App con búsqueda | Si Item 9 agrega consultas desde usuario Telegram | Validar todos los inputs antes de pasarlos a la API REST |
| Plugin `{TRACKERLIST}` | Si alguien pone un TRACKERLIST en una página wiki pública visible | trackerGram no controla el contenido de TikiWiki — es riesgo del admin de TikiWiki |
| `list_items()` directo | Si algún futuro refactor toca trackerlib internas | Nunca implementar. Siempre API REST. |

### Checklist para incorporar en PR de nuevas features

- [ ] ¿La feature lee datos de TikiWiki? → Debe ir por API REST
- [ ] ¿La feature recibe input del usuario de Telegram? → Validar tipo + sanitizar
- [ ] ¿La feature construye URLs de API con datos variables? → Usar `http_build_query()` o concatenación con valores validados
- [ ] ¿La feature expone algún endpoint nuevo? → Revisar que no acepte `sort_mode`, `filterfield` sin validar

---

## Referencias

- `lib/trackers/trackerlib.php` — función `list_items()` (línea ~1320)
- `lib/wiki-plugins/wikiplugin_trackerlist.php` — parámetro `tr_sort_mode` (línea ~1639)
- `lib/trackers/Tracker/Query.php` — ORM moderno seguro (línea ~820-862)
- API REST: `/tiki-ajax_services.php?controller=tracker&action=list_items`
- Código trackerGram: `TikiWikiClient.php` (funciones `messageExists`, `createTrackerItem`, `loadTrackerFields`)
