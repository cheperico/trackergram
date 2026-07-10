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
- `tiki-browse_freetags.php` solo produce HTML
- `tiki-ajax_services.php?listonly=tags` requiere query no vacía y solo devuelve 10 resultados

## Alternativas evaluadas

| Opción | Descripción | Pros | Contras |
|--------|------------|------|---------|
| **Script PHP en TikiWiki** | Crear `tg_freetags.php` en la raíz del TikiWiki que use `FreetagLib::silly_list()` y devuelva JSON | ✅ Obtiene todos los tags<br>✅ Usa librerías nativas<br>✅ No modifica el core<br>✅ Bajo esfuerzo | ❌ Archivo extra que mantener al actualizar TikiWiki |
| **SQL directo desde trackerGram** | Conectar a la BD de TikiWiki desde trackerGram y consultar `tiki_freetags` | ✅ Completo y rápido<br>✅ No toca TikiWiki | ❌ Va contra la filosofía del proyecto (sin BD con servidor)<br>❌ Requiere credenciales de BD |
| **Scraping HTML** | Parsear `tiki-browse_freetags.php` con DOM | ✅ No requiere nada extra | ❌ Frágil (cambia con themes/versiones) |
| **ajax_services parcial** | Llamar `listonly=tags&q=a`, `q=b`, etc. | ✅ No requiere nada extra | ❌ Solo 10 tags por llamada<br>❌ No cubre todos los caracteres |

## Decisión
**Pendiente.** Ninguna opción es ideal sin modificar TikiWiki o romper la filosofía del proyecto. Se documenta para retomarlo cuando haya una necesidad concreta o aparezca una alternativa mejor.

## Posible implementación futura
Si se opta por el script PHP, sería:
1. Crear `tg_freetags.php` en el servidor TikiWiki
2. Asignar `tiki_p_view_freetags` al token API
3. Agregar `getAllFreetags()` a `TikiWikiClient` que llame al nuevo endpoint
4. Agregar comando `/etiquetas` en `WebhookHandler::handleCommand()`
