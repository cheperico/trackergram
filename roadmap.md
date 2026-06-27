# trackerGram — Roadmap Unificado

> ⚠️ **Documento único de referencia**. Consolidado desde:
> - `roadmap.md` (anterior)
> - `reports/architecture_efficiency_report.md`
> - `reports/feature_suggestions_report.md`
> - `reports/informe de GPT.md`
> - `reports/security_audit_report.md`
> - `reports/template-wiki-feed.md`
> - `CAMBIOS.md`

---

## Estado del Proyecto

| | |
|---|---|
| **Versión actual** | v0.5.9 |
| **Estado** | Beta funcional, desarrollo activo |
| **Instancias activas** | Dev (tracker 26) · Prod (tracker 22) |
| **Filosofía** | Sin DB con servidor · JSON files para estado local (no SQLite) · PHP puro sin framework · MVP pragmático |

### Lo que ya funciona sólido

- ✅ Webhook tiempo real → TikiWiki (multi-conexión)
- ✅ Import exports ZIP (incluyendo batch con progreso)
- ✅ Soporte multimedia: fotos, videos, audio, documentos, stickers, notas de voz
- ✅ Topics (forums) con resolución de nombres
- ✅ Reacciones a mensajes
- ✅ Service messages (creación topics, miembros, pins, etc.)
- ✅ Creación automática de trackers con todos los campos
- ✅ Panel admin clásico + moderno con progress bar
- ✅ Deduplicación por (chat_id, message_id)
- ✅ Seguridad: CSRF, rate limiting, hash contraseñas, path traversal, XSS
- ✅ Auto-reparación de galería (repairFgGallery)
- ✅ Inyección de dependencias (clases instanciables por conexión)
- ✅ NormalizedMessage como modelo único
- ✅ Gallery resolution via endpoint `/fields`
- ✅ Timeouts separados upload (60s) / api (30s)
- ✅ debug.log respeta DEBUG_MODE ($force para críticos)
- ✅ Álbumes/grupos de medios (mediaGroup) en webhook (cada foto en su propio item; caption propagada entre fotos del mismo álbum)
- ✅ Cache de topics por chatId:threadId
- ✅ Cache de gallery ID por tracker
- ✅ Webhook Secret obligatorio (rechaza si vacío)
- ✅ change_password funcional (fuera de checkAuth)
- ✅ my_chat_member handler + auto leaveChat para chats no autorizados
- ✅ **Arquitectura multi-conexión**: múltiples bots/wikis/trackers desde una instalación
- ✅ **Async processing por conexión**: toggle en admin, per-connection
- ✅ **.env simplificado**: solo config global; credenciales en setup.json
- ✅ **Service messages completos**: cobertura total webhook e import (incluye `remove_members`, `joined`, `new_chat_photo`, `delete_chat_photo`)
- ✅ **Creación de tracker desde admin panel**: shell + fields + galería (auto o existente) + field prefix
- ✅ **Fan-out**: mismo mensaje a múltiples trackers duplicando conexión (con try-catch individual, error en una conexión no rompe las demás)
- ✅ **Cache auto-detección field prefix**: flag `field_prefix_checked` evita llamadas API repetidas en cada carga de admin y cada webhook
- ✅ **FG field options vía API**: `updateFgFieldOptions()` con `name`+`type` requeridos
- ✅ **Auto-población bot_name/chat_title** en cards de conexión
- ✅ **Chat_id con -100 en import**: corrección del prefijo `-100` para supergrupos (el export JSON de Telegram Desktop omite el `-100` en el `id` raíz)
- ✅ **ReplyToId con texto del original (Opción B)**: en webhook extrae `reply_to_message.text` (gratis), en import busca el texto via API. Guarda `#42 - "texto..."` en el campo ReplyToId existente.
- ✅ **Health check visible en cards de conexión**: cada tarjeta muestra estado del webhook vía `getWebhookInfo()` (✅ configurado, ❌ no configurado, ⚠️ con errores).
- ✅ **Verificación post-creación de FG field**: `updateFgFieldOptions()` verifica con `GET /fields` que el galleryId se haya guardado (workaround del bug de TikiWiki que responde HTTP 200 aunque falle).
- ✅ **Field descriptions en API**: todos los campos del tracker se crean con `description` descriptivo enviado a la API de TikiWiki.
- ✅ **Auto-detección de field prefix**: si el prefix almacenado en `setup.json` es `telegrammessage` (default), el sistema lo verifica contra los campos reales del tracker vía API y lo corrige automáticamente si es distinto. Se persiste tras el primer webhook. Cobertura: webhook, async worker, import.
- ✅ **Hashtags como etiquetas (Freetags)**: `#tags` extraídos de mensajes de Telegram (webhook e import) guardados en campo tipo `F` (Freetags). Se integran al ecosistema de etiquetas de TikiWiki (tag cloud, búsqueda).

---

## Prioridades Reales

### 🔴 Fase 1: Lo que más duele ahora (días)

Items con impacto inmediato en la operación del día a día.

| # | Item | Esfuerzo | Notas |
|   |------|----------|-------|
| 1 | **Detección de migración grupo→supergrupo en webhook** | 1 sesión | Detectar `migrate_to_chat_id` en updates de Telegram y actualizar `chat_id` en `setup.json` automáticamente. Sin esto, si el grupo migra, el webhook deja de reconocerlo. También manejar error 400 de Bot API con `parameters.migrate_to_chat_id`. |

### 🟡 Fase 2: Robustez (1-2 semanas)

| # | Item | Esfuerzo | Notas |
|   |------|----------|-------|
| 1 | **Mensajes editados/borrados** | 2 sesiones | Estrategia: archivo inmutable con eventos. Los editados/borrados son eventos adicionales. |
| 2 | **Reproducción de mensajes previos a nuevo tracker** | 2 sesiones | Script/acción para re-enviar mensajes anteriores de un chat a un tracker recién creado. |

### 🟢 Fase 3: Features grandes / robustez (mediano plazo)

| # | Item | Esfuerzo | Dependencias |
|---|------|----------|--------------|
| 6 | **Chat_id unificado para imports con migración** | 2 sesiones | Los exports de Telegram pueden incluir migración grupo→supergrupo. Los mensajes pre-migración tienen IDs negativos, los post-migración IDs positivos. El chat_id también cambia. Decidir estrategia (un solo chat_id para todo el grupo, o bifurcar) e implementar detección de service messages `migrate_to_supergroup`/`migrate_from_group`. |
| 6b | **Reply: link clickeable + texto del original** | 1-2 sesiones | El enlace al mensaje respondido no funciona en tplwiki (el Smarty de TikiWiki no ejecuta `preg_match`/`regex_replace` como se espera). **Dos pendientes**: (1) lograr link clickeable al item padre, (2) mostrar texto del mensaje original. Posible solución: campo `ReplyToText` separado (poblado en webhook desde `reply_to_message.text`, en import desde API de TikiWiki). Para trackers existentes requiere migración. Referencia: `opt/visualizacion-lcc2026.md`. |
| 7 | **Mensajes estructurados con prefijos** | 2-3 sesiones | Parser en MessageMapper para mensajes tipo `GPS user coord` o `#tag texto`. |
| 8 | **Manejo de errores estandarizado** | 2-3 sesiones | Excepciones de dominio (`ConfigException`, `TelegramException`, `TikiWikiException`, `ImportException`). |
| 9 | **Import CLI asíncrono** | 2 sesiones | Script CLI para exports grandes sin timeout HTTP. |
| 10 | **Álbumes/grupos de medios en un solo item** | 2-3 sesiones | Agrupar fotos del mismo `media_group_id` en UN item del tracker con múltiples archivos en el campo FG. Actualmente cada foto crea su propio item. Requiere: (1) método para actualizar items existentes en TikiWikiClient, (2) lógica de detección de grupo y update vs create, (3) concurrencia (fotos llegan casi simultáneas). |

### 🔵 Fase 4: Visión (largo plazo)

| # | Item | Esfuerzo | Notas |
|---|------|----------|-------|
| 11 | **Mini App** (Telegram Web App) | 5+ sesiones | Frontend embebido + backend. Formulario rico. Ver `design/002-MiniApp.md`. |
| 12 | **Dashboard de métricas** | 2-3 sesiones | Mensajes procesados, errores, media subidos por conexión. |
| 13 | **Tests unitarios** | Continuo | MessageMapper, WebhookHandler, clientes. |
| 14 | **PSR-4 autoloading** | 1 sesión | Mover clases a `src/`, autoloader. |
| 15 | **Transcripción de voz / OCR** | 3-4 sesiones | Whisper + OCR. Dependencias externas. |
| 16 | **SQLite para cola async y rate limiting** (evaluación) | 1 sesión | **Opcional.** Evaluar si vale la pena migrar tmp/buffer/ y rate limiting de archivos JSON a SQLite. Prioridad mínima — los archivos actuales funcionan para el volumen esperado. No aplica a setup.json ni topic cache. |
| 17 | **Rotación de logs por fecha** | 1 sesión | Además de por tamaño. |
| 18 | **Expulsar bot desde admin panel** | 1 sesión | Botón para sacar el bot de un grupo directamente desde la interface, sin tener que hacerlo desde Telegram. |

### ⚪ Fase 5: Pendientes de reevaluación (muy baja prioridad)

Items que no justifican implementación hoy pero se documentan por si el contexto cambia. Requieren re-evaluación antes de arrancar.

| # | Item | Notas |
|---|------|-------|
| 1 | **Botón "Detectar prefix" en admin** | Con la auto-detección automática en el primer webhook, un botón manual es redundante. Solo tendría sentido si hubiera casos donde no se pueda enviar un mensaje para gatillar la detección. |
| 2 | **Flag `prefix_confirmed` para evitar re-detección** | La auto-detección ya persiste el prefix corregido a `setup.json` después del primer webhook, por lo que no hay re-detección en requests subsiguientes. El flag no agrega valor. |
| 3 | **Campo `field_prefix` visible/editable en modal de edición de conexión** | El modal de Webhook no incluye `field_prefix`. Con la auto-detección ya no es necesario editarlo manualmente. Solo tendría sentido si se quiere ver el valor detectado por transparencia. |

---

## Service Messages — Cobertura Real

| Evento | Webhook | Import | Notas |
|--------|---------|--------|-------|
| `forum_topic_created` / `topic_created` | ✅ | ✅ | |
| `forum_topic_edited` / `topic_edit` | ✅ | ✅ | |
| `forum_topic_closed/reopened` | ✅ | ✅ | |
| `new_chat_members` / `invite_members` | ✅ | ✅ | |
| `left_chat_member` / `left` | ✅ | ✅ | |
| `pinned_message` / `pin_message` | ✅ | ✅ | |
| `group_chat_created` / `supergroup_chat_created` / `create_group` | ✅ | ✅ | |
| `new_chat_title` / `title_edit` | ✅ | ✅ | |
| `new_chat_photo` / `delete_chat_photo` | ✅ | ✅ | |
| `remove_members` | ✅ | ✅ | |
| `joined` | ✅ | ✅ | |
| `message_reaction` / `message_reaction_count` | ✅ | ✅ (embebidas) | En import vienen como campo `reactions[]` dentro del mensaje, no como evento separado. Ya se parsea en `fromExport()`. |

**Pendientes:**
*(ninguno — cobertura completa)*

---

## Bugs Conocidos

| ID | Descripción | Estado |
|----|-------------|--------|
| BUG-001 | `findByWebhookSecret()` devolvía primera conexión en vez de la pendiente | ✅ **Arreglado** en v0.5.8 |
| BUG-002 | `pending_update_count` incluye el update actual durante `/estado` | ⚠️ Workaround (ocultar pending <10). Fix posta: restar 1 al pending o health check externo. |

## Cosas que NO vamos a hacer (por ahora)

| Item | Motivo |
|------|--------|
| Base de datos local con servidor (MySQL/PostgreSQL) | Rompe la filosofía "sin DB". TikiWiki es el almacenamiento. |
| Framework PHP (Laravel/Symfony) | Overhead innecesario para un puente de ~10 archivos. |
| Soporte multi-idioma | No agrega valor al caso de uso actual. |
| Modo espejo (vs archivo) | Decidido: trackerGram es **archivo inmutable con eventos**. Los editados/borrados se guardan como eventos adicionales, no modifican el original. |

---

## Diseños en Progreso

Los documentos en `design/` contienen exploraciones detalladas de features que están en discusión. Cuando un diseño madura lo suficiente (solo necesita retoques de implementación), pasa a la sección de Prioridades de este roadmap.

| Documento | Estado | Feature |
|-----------|--------|---------|
| `design/001-configuracion-inversa-via-telegram.md` | Exploración | Configurar trackerGram desde Telegram con comandos |
| `design/002-MiniApp.md` | Exploración | Frontend embebido para recolección estructurada de datos |
| `design/003-arquitectura-multi.md` | ✅ Implementado | Multi-conexión (routing, field mapping, etc.) |

Los reportes históricos en `reports/` se conservan como referencia de investigaciones pasadas. Los items accionables ya están consolidados en este documento.

> **Última actualización**: 26/06/2026
