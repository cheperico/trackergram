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
| **Versión actual** | v0.2.3 |
| **Estado** | Beta funcional, desarrollo activo |
| **Instancias activas** | Dev (tracker 12) · Prod (tracker 22) |
| **Filosofía** | Sin DB local · PHP puro sin framework · MVP pragmático |

### Lo que ya funciona sólido

- ✅ Webhook tiempo real → TikiWiki
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
- ✅ Inyección de dependencias (clases instanciables)
- ✅ NormalizedMessage como modelo único
- ✅ Gallery resolution via endpoint `/fields`
- ✅ Timeouts separados upload (60s) / api (30s)
- ✅ debug.log respeta DEBUG_MODE ($force para críticos)
- ✅ Álbumes/grupos de medios (mediaGroup) en webhook
- ✅ Cache de topics por chatId:threadId
- ✅ Cache de gallery ID por tracker
- ✅ Webhook Secret obligatorio (rechaza si vacío)
- ✅ change_password funcional (fuera de checkAuth)

---

## Prioridades Reales

### 🔴 Fase 1: Lo que más duele ahora (días)

Items con impacto inmediato en la operación del día a día.

| # | Item | Esfuerzo | Por qué ahora |
|---|------|----------|---------------|
| 1 | **Router multi-grupo** — mapear diferentes chats → diferentes trackers/wikis | 3-5 sesiones | Tenés dos instancias (dev/prod). Un solo trackerGram debería manejar N grupos sin duplicar instalación. Es la feature más pedida implícitamente. |
| 2 | **Service messages faltantes** (webhook) | 1 sesión | `remove_members`, `joined`, `new_chat_photo` no se capturan en webhook. Son agujeros en el historial. |
| 3 | **Hashtags como etiquetas** | 1 sesión | Extraer `#tags` a campo separado. Mejora búsqueda en TikiWiki. Bajo esfuerzo, alto impacto. |

### 🟡 Fase 2: Robustez (1-2 semanas)

Items que evitan problemas futuros y mejoran la mantenibilidad.

| # | Item | Esfuerzo | Notas |
|---|------|----------|-------|
| 4 | **Service messages faltantes** (import) | 1 sesión | `new_chat_photo/delete_chat_photo`, `message_reaction` en importación |
| 5 | **Health check en admin** | 1 sesión | Botones "Probar conexión Telegram" y "Probar conexión TikiWiki". Estado del webhook. Especialmente útil con múltiples rutas. |
| 6 | **Aislar archivos temporales** | 1 sesión | Usar `__DIR__ . '/tmp/'` en vez de `sys_get_temp_dir()`. Los temp files (rate limiting, media groups, logs) en shared hosting son visibles por otros usuarios. |

### 🟠 Fase 3: Features grandes (mediano plazo)

| # | Item | Esfuerzo | Dependencias |
|---|------|----------|--------------|
| 7 | **Mensajes estructurados con prefijos** | 2-3 sesiones | Parser en MessageMapper para mensajes tipo `GPS user coord` o `#tag texto`. Requiere definir formato y patrones. |
| 8 | **Manejo de errores estandarizado** | 2-3 sesiones | Reemplazar mezcla de `null`/`false`/`die()`/`http_response_code()` por excepciones de dominio (`ConfigException`, `TelegramException`, `TikiWikiException`, `ImportException`). Mejora testabilidad y debugging. |
| 9 | **Import CLI asíncrono** | 2 sesiones | Script CLI que procesa exports grandes sin depender de timeout HTTP. Reanudable. Complementa el chunked actual. |

### 🔵 Fase 4: Visión (largo plazo)

| # | Item | Esfuerzo | Notas |
|---|------|----------|-------|
| 10 | **Mini App** (Telegram Web App) | 5+ sesiones | Frontend embebido en Telegram + backend. Crear items estructurados con formulario rico. Feature enorme, no subestimar. |
| 11 | **Mensajes editados/borrados** | 2 sesiones | Primero definir estrategia: ¿archivo inmutable o espejo? Después implementar. |
| 12 | **Procesamiento async** (buffer + worker) | 3-4 sesiones | api.php responde 200 al toque, guarda payload en cola, worker cron procesa. Elimina dependencia de latencia de TikiWiki. Requiere refactor de WebhookHandler. |
| 13 | **Dashboard de métricas** | 2-3 sesiones | Mensajes procesados, errores, media subidos, estado por ruta. |
| 14 | **Tests unitarios** | Continuo | MessageMapper, WebhookHandler, clientes. Ideal después del refactor de errores (Fase 3). |
| 15 | **PSR-4 autoloading** | 1 sesión | Mover clases a `src/`, autoloader. Baja prioridad porque el proyecto funciona sin esto. |
| 16 | **Transcripción de voz / OCR** | 3-4 sesiones | Whisper para audios, OCR para imágenes. Dependencias externas (API keys). |
| 17 | **SQLite cache / dedup local** | 2 sesiones | Cache local de deduplicación para no pegarle a TikiWiki en cada mensaje. Evaluar cuando el volumen lo justifique. |
| 18 | **Rotación de logs por fecha** | 1 sesión | Además de por tamaño. Baja prioridad, la rotación actual funciona. |

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

## Referencias Archivadas

Los reports originales se mantienen en `reports/` como referencia histórica, pero **este documento es la fuente de verdad** para prioridades. Los items de los reports que ya están implementados, re-priorizados o descartados no se trasladan aquí.

> **Última actualización**: 09/06/2026
