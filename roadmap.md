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
| **Versión actual** | v0.4.0 |
| **Estado** | Beta funcional, desarrollo activo |
| **Instancias activas** | Dev (tracker 12) · Prod (tracker 22) |
| **Filosofía** | Sin DB local · PHP puro sin framework · MVP pragmático |

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
- ✅ Álbumes/grupos de medios (mediaGroup) en webhook
- ✅ Cache de topics por chatId:threadId
- ✅ Cache de gallery ID por tracker
- ✅ Webhook Secret obligatorio (rechaza si vacío)
- ✅ change_password funcional (fuera de checkAuth)
- ✅ my_chat_member handler + auto leaveChat para chats no autorizados
- ✅ **Arquitectura multi-conexión**: múltiples bots/wikis/trackers desde una instalación
- ✅ **Async processing por conexión**: toggle en admin, per-connection
- ✅ **.env simplificado**: solo config global; credenciales en setup.json

---

## Prioridades Reales

### 🔴 Fase 1: Lo que más duele ahora (días)

Items con impacto inmediato en la operación del día a día.

| # | Item | Esfuerzo | Por qué ahora |
|---|------|----------|---------------|
| 1 | **Service messages faltantes en webhook** — `remove_members`, `joined`, `new_chat_photo/delete_chat_photo` | 1 sesión | Son agujeros en el historial. El resto de service messages ya funciona. |
| 2 | **Toggle my_chat_member en admin** — desactivar temporalmente el auto-leaveChat (ej: 10 min) para agregar el bot a un grupo nuevo | 1 sesión | Rompe el huevo y la gallina de "configurar ID vs agregar el bot". |
| 3 | **Hashtags como etiquetas** | 1 sesión | Extraer `#tags` a campo separado. Mejora búsqueda en TikiWiki. |

### 🟡 Fase 2: Robustez (1-2 semanas)

| # | Item | Esfuerzo | Notas |
|---|------|----------|-------|
| 4 | **Service messages faltantes en import** — `new_chat_photo/delete_chat_photo`, `message_reaction` | 1 sesión | |
| 5 | **Health check en admin** | 1 sesión | Estado del webhook, test conexión por conexión. |
| 6 | **Mensajes editados/borrados** | 2 sesiones | Estrategia: archivo inmutable con eventos. Los editados/borrados son eventos adicionales. |

### 🟢 Fase 3: Features grandes (mediano plazo)

| # | Item | Esfuerzo | Dependencias |
|---|------|----------|--------------|
| 7 | **Mensajes estructurados con prefijos** | 2-3 sesiones | Parser en MessageMapper para mensajes tipo `GPS user coord` o `#tag texto`. |
| 8 | **Manejo de errores estandarizado** | 2-3 sesiones | Excepciones de dominio (`ConfigException`, `TelegramException`, `TikiWikiException`, `ImportException`). |
| 9 | **Import CLI asíncrono** | 2 sesiones | Script CLI para exports grandes sin timeout HTTP. |

### 🔵 Fase 4: Visión (largo plazo)

| # | Item | Esfuerzo | Notas |
|---|------|----------|-------|
| 10 | **Mini App** (Telegram Web App) | 5+ sesiones | Frontend embebido + backend. Formulario rico. Ver `design/002-MiniApp.md`. |
| 11 | **Dashboard de métricas** | 2-3 sesiones | Mensajes procesados, errores, media subidos por conexión. |
| 12 | **Tests unitarios** | Continuo | MessageMapper, WebhookHandler, clientes. |
| 13 | **PSR-4 autoloading** | 1 sesión | Mover clases a `src/`, autoloader. |
| 14 | **Transcripción de voz / OCR** | 3-4 sesiones | Whisper + OCR. Dependencias externas. |
| 15 | **SQLite cache / dedup local** | 2 sesiones | Evaluar cuando el volumen lo justifique. |
| 16 | **Rotación de logs por fecha** | 1 sesión | Además de por tamaño.

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
| `new_chat_photo` / `delete_chat_photo` | ✅ | ⬜ **Pendiente** | |
| `remove_members` | ⬜ **Pendiente** | ✅ | |
| `joined` | ⬜ **Pendiente** | ✅ | |
| `message_reaction` / `message_reaction_count` | ✅ | ⬜ **Pendiente** | |

**Pendientes:**
- [ ] `new_chat_photo` / `delete_chat_photo` en importación
- [ ] `remove_members` en webhook
- [ ] `joined` en webhook
- [ ] `message_reaction` / `message_reaction_count` en importación

---

## Cosas que NO vamos a hacer (por ahora)

| Item | Motivo |
|------|--------|
| Base de datos local (MySQL/PostgreSQL) | Rompe la filosofía "sin DB". TikiWiki es el almacenamiento. |
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

> **Última actualización**: 16/06/2026
