# 011 — Comando /etiquetas en el bot

## Estado
**Documentado, pendiente de implementación.**

## Objetivo
Que el bot de Telegram responda al comando `/etiquetas` con la lista de todos los freetags disponibles en TikiWiki.

## Problema
TikiWiki 27.5 **no tiene ningún endpoint REST** que devuelva la lista de freetags:
- No existe `GET /api/tags` ni `GET /api/freetags`
- No hay `Services_Freetag_Controller` registrado en `ApiBridge.php`
- No hay RSS/feed de freetags
- El plugin `{LIST}` no soporta `type="freetag"` (solo filtra objetos por tag)
- El plugin `{MODULE}` produce HTML, no datos estructurados
- `tiki-browse_freetags.php` solo produce HTML
- `tiki-ajax_services.php?listonly=tags` requiere query no vacía y solo devuelve 10 resultados

## Alternativas evaluadas

| Opción | Descripción | Pros | Contras |
|--------|------------|------|---------|
| **Script PHP en TikiWiki** ⭐ | Crear `tg_freetags.php` en la raíz del TikiWiki que use `FreetagLib::silly_list()` y devuelva JSON | ✅ Obtiene todos los tags<br>✅ Usa librerías nativas<br>✅ No modifica el core<br>✅ Bajo esfuerzo<br>✅ Sin credenciales extra | ❌ Archivo extra que mantener al actualizar TikiWiki |
| **SQL directo desde trackerGram** | Conectar a la BD de TikiWiki desde trackerGram y consultar `tiki_freetags` | ✅ Completo y rápido<br>✅ No toca TikiWiki | ❌ Va contra la filosofía del proyecto (sin BD con servidor)<br>❌ Requiere credenciales de BD (más crítico) |
| **Scraping HTML** | Parsear `tiki-browse_freetags.php` o `{MODULE}` con DOM | ✅ No requiere nada extra | ❌ Frágil (cambia con themes/versiones) |
| **ajax_services parcial** | Llamar `listonly=tags&q=a`, `q=b`, etc. | ✅ No requiere nada extra | ❌ Solo 10 tags por llamada<br>❌ No cubre todos los caracteres |

## Decisión
**Opción destacada: Script PHP en el servidor TikiWiki.** Es la más equilibrada: no requiere credenciales de BD, no modifica el core, usa las librerías nativas de TikiWiki, y es un archivo trivial (10 líneas). 

Como condición de instalación es razonable: copiar un archivo al servidor TikiWiki y asignar un permiso al token API. Mucho más liviano que compartir credenciales SQL.

## Implementación futura

### En TikiWiki (servidor)
Crear `tg_freetags.php` en la raíz del TikiWiki:

```php
<?php
require_once 'tiki-setup.php';
$access->check_feature('feature_freetags');
$access->check_permission('tiki_p_view_freetags');
$freetaglib = TikiLib::lib('freetag');
header('Content-Type: application/json');
echo json_encode($freetaglib->silly_list(9999));
```

Además:
- Asignar `tiki_p_view_freetags` al grupo del token API (Admin → Grupos)
- Verificar que `feature_freetags` esté activada (Admin → Features)

### En trackerGram
1. Agregar método `getAllFreetags()` a `TikiWikiClient` que haga GET al nuevo endpoint
2. Agregar comando `/etiquetas` en `WebhookHandler::handleCommand()`
3. El handler recibe JSON, lo formatea como texto y responde por Telegram

### Caché opcional
Para evitar llamar al endpoint en cada `/etiquetas`, se puede cachear el resultado localmente (ej: `known_tags.json` con TTL de 5-10 minutos), siguiendo el patrón existente de `topic_names.json`.
