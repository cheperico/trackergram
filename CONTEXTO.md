# CONTEXTO.md — Guía de Entrada Obligatoria

> **⚠️ LECTURA OBLIGATORIA** — No toques código, no hagas cambios, no respondas issues sin haber leído este documento completo. Esto aplica tanto para personas como para agentes de IA.

---

## Qué es trackerGram

trackerGram es un puente entre **Telegram** y **TikiWiki**. Recibe mensajes de un grupo de Telegram (vía webhook o importación de exports) y los guarda automáticamente como items en un tracker de TikiWiki.

**Problema que resuelve**: Centralizar conversaciones de Telegram en TikiWiki para que sean buscables, indexables y permanezcan accesibles fuera de la plataforma de mensajería.

**Filosofía del proyecto**:
- Sin base de datos local — TikiWiki es el almacenamiento
- PHP puro, sin framework — simplicidad sobre complejidad
- MVP pragmático — funcionalidad primero, perfección después
- Iteración rápida — funciona, luego se mejora

---

## Estado Actual del Proyecto

| | |
|---|---|
| **Versión** | v0.1.7 |
| **Estado** | Beta funcional |
| **Desarrollo** | Activo |
| **Metodología** | Director humano + agentes de IA |

### Qué funciona
- ✅ Webhook en tiempo real: mensajes de Telegram → TikiWiki
- ✅ Importación de exports ZIP de Telegram
- ✅ Soporte multimedia: fotos, videos, audio, documentos, stickers, notas de voz
- ✅ Topics (forums) de Telegram con resolución de nombres
- ✅ Reacciones a mensajes
- ✅ Service messages (creación de topics, miembros, pins, etc.)
- ✅ Creación automática de trackers en TikiWiki
- ✅ Panel de administración web
- ✅ Deduplicación de mensajes
- ✅ Seguridad: CSRF, rate limiting, hash de contraseñas, path traversal protection

### Qué falta (resumen)
- Sistema de etiquetas (hashtags)
- Mensajes estructurados con prefijos (GPS, alertas)
- Manejo de mensajes editados/borrados
- Importación asíncrona para exports grandes
- Soporte para múltiples chats con trackers separados
- Refactorización pendiente: inyección de dependencias, tests unitarios

### Dónde ver el detalle
- **Qué falta y prioridades**: [roadmap.md](roadmap.md)
- **Historial de cambios por versión**: [CAMBIOS.md](CAMBIOS.md)

---

## Mapa de Navegación de la Documentación

Cada archivo tiene un público y propósito definido. Usá el correcto:

| Archivo | Para quién | Qué contiene |
|---|---|---|
| **CONTEXTO.md** | Todo nuevo miembro (humano o IA) | Visión general, estado, decisiones clave, mapa del proyecto |
| **README.md** | Usuario final | Qué es, cómo se usa, solución de problemas comunes |
| **INSTALL.md** | Quien necesita instalarlo | Requisitos, pasos de instalación, configuración, verificación |
| **TECHNICAL.md** | Desarrolladores | Cómo está construido, por qué se decidió así, tutorial paso a paso |
| **roadmap.md** | Equipo de desarrollo | Pendientes, prioridades, bugs conocidos |
| **CAMBIOS.md** | Todos | Historial de cambios por versión |

**Regla**: Si querés usar el programa → README.md. Si querés instalarlo → INSTALL.md. Si querés entender cómo está hecho o modificarlo → TECHNICAL.md.

---

## Decisiones Arquitectónicas Clave

Estas son las decisiones fundamentales que explican por qué el proyecto está como está. Entenderlas evita intentar "arreglar" lo que no está roto o repetir errores del pasado.

### Sin base de datos local

trackerGram no tiene base de datos propia. TikiWiki es el almacenamiento. Esto simplifica la infraestructura: un solo servidor PHP, sin MySQL/PostgreSQL adicional. La contrapartida es que toda la lógica de deduplicación, búsqueda y persistencia depende de la API de TikiWiki.

### PHP puro, sin framework

No hay Laravel, Symfony ni Composer. El proyecto son archivos PHP que se incluyen directamente. Esto fue deliberado: el proyecto creció desde un script simple y se mantuvo así para no agregar complejidad innecesaria. La deuda técnica que esto genera (sin autoloading PSR-4, sin tests unitarios fáciles) está identificada en el roadmap.

### Clases estáticas

`TikiWikiClient`, `TelegramClient`, `WebhookHandler` usan métodos estáticos. No hay inyección de dependencias. Esto dificulta el testing unitario y es la refactorización de mayor prioridad en el roadmap. Se hizo así porque en el momento priorizamos funcionalidad sobre arquitectura.

### TikiWiki como almacenamiento

TikiWiki no es la plataforma más moderna, pero es la que el proyecto tiene como target. La API de TikiWiki tiene particularidades (campos con "permanent names", file galleries, etc.) que el código maneja. No asumir que funciona como una API REST convencional.

### Desarrollado con asistencia de IA

El director del proyecto (cheperico) no es programador profesional. Utiliza agentes de IA para implementar funcionalidades. Esto tiene implicancias:
- El código funciona pero puede no seguir patrones convencionales
- Las sesiones largas de IA pierden contexto — por eso la documentación es crítica
- La validación final siempre requiere pruebas humanas
- Los agentes de IA que tomen este proyecto deben leer esta guía antes de tocar código

---

## Estructura del Código

Todos los archivos están en la raíz del proyecto. No hay subdirectorios de código.

### Entry Points (puntos de entrada HTTP)

| Archivo | Qué hace | Cuándo se ejecuta |
|---|---|---|
| `bootstrap.php` | Carga config + clientes + handler | Siempre, es el primer include |
| `api.php` | Recibe webhooks de Telegram | Cuando Telegram envía un mensaje |
| `admin.php` | Panel de administración web | Cuando un humano abre la URL |
| `import.php` | Procesa exports ZIP de Telegram | Cuando un humano sube un ZIP desde el admin |

### Clientes y Lógica

| Archivo | Responsabilidad |
|---|---|
| `config.php` | Carga `.env`, define constantes globales |
| `TikiWikiClient.php` | Comunicación con API de TikiWiki (crear items, subir archivos, crear trackers) |
| `TelegramClient.php` | Comunicación con API de Telegram (descargar archivos, info de chats) |
| `MessageMapper.php` | Transforma mensajes de Telegram al formato de campos de TikiWiki |
| `WebhookHandler.php` | Orquesta todo: valida, resuelve topics, descarga media, envía a TikiWiki |

### Archivos de Soporte

| Archivo | Qué es |
|---|---|
| `.env` | Variables de entorno (credenciales) — NO versionar |
| `.env.example` | Plantilla de variables de entorno |
| `.htaccess` | Configuración de Apache (seguridad, rewrite, límites PHP) |
| `topic_names.json` | Cache local de nombres de topics (se genera automáticamente) |
| `debug.log` | Logs de debug (se genera si `DEBUG_MODE=true`) |

### Orden recomendado de lectura del código

1. `bootstrap.php` — 10 líneas, entendés qué se carga
2. `config.php` — cómo se leen las credenciales y constantes
3. `api.php` — entry point del webhook, delega en WebhookHandler
4. `WebhookHandler.php` — el corazón: processUpdate → processMessage → sendToTikiWiki
5. `MessageMapper.php` — cómo se transforman los datos
6. `TikiWikiClient.php` — cómo se envían a TikiWiki
7. `TelegramClient.php` — cómo se descargan archivos de Telegram
8. `import.php` — flujo alternativo de importación
9. `admin.php` — interfaz web de configuración

---

## Para Agentes de IA

Este proyecto fue desarrollado con asistencia de LLMs. Si sos un agente de IA tomando este proyecto, tené en cuenta:

### Contexto de desarrollo
- El director humano valida todo — no asumas que tu código es correcto sin que lo revise
- Las sesiones largas pierden contexto — referí siempre a los archivos específicos que modificás
- Este documento existe para compensar la pérdida de contexto entre sesiones

### Patrones comunes
- Siempre usar `bootstrap.php` como punto de entrada
- Siempre usar los clientes existentes, no hacer curl directo
- Siempre usar `MessageMapper` para transformación de datos
- Los logs van con `error_log()` y `log_message()`

### Qué NO hacer

> Estas son cosas que **ya pasaron y costaron arreglar**. El objetivo es que no las repitas.

**Arquitectura:**
- **No agregar lógica de negocio en `api.php`** — Es solo entry point. Toda la lógica va en `WebhookHandler`. Ya se refactorizó para esto.
- **No usar `curl` directamente** — Usar `TelegramClient` y `TikiWikiClient`. Hay código duplicado que ya se eliminó una vez.
- **No crear variables globales** — Usar `static` dentro de funciones o pasar por parámetros.
- **No requerir archivos individuales** — Siempre usar `require_once 'bootstrap.php'`.
- **No duplicar lógica de parsing entre webhook e import** — Ambos caminos deben converger en `MessageMapper::toWikiFields()`.

**Telegram:**
- **No intentar usar `getForumTopic`** — No existe en la Telegram Bot API. La resolución de topics se hace por cache + fallback.
- **No asumir que `message_id` es único sin `chat_id`** — La deduplicación es por par `(chat_id, message_id)`.
- **No usar `sleep()` en reintentos** — Usar `usleep()`.
- **No descargar archivos sin verificar tamaño** — Hay un límite de 20MB (`MEDIA_DOWNLOAD_MAX_SIZE`).

**Seguridad:**
- **No exponer credenciales en URLs o logs** — Los tokens en URLs de descarga se filtraban.
- **No comparar contraseñas en texto plano** — Se usa `password_verify()` con bcrypt.
- **No saltar validación CSRF en admin POST** — Todas las acciones mutantes la requieren.
- **No extraer ZIPs sin validar path traversal** — Verificar que ningún entry contenga `..`.
- **No usar `innerHTML` en el JS del admin** — Riesgo XSS. Usar `textContent`.

**General:**
- **No modificar `.env` manualmente en producción** — Usar el panel de admin.
- **No cargar archivos completos en memoria** — Usar chunks para descargas.
- **No actualizar código sin actualizar esta documentación** — CONTEXTO.md debe reflejar el estado real.

---

## Referencias de API

| API | Documentación |
|---|---|
| Telegram Bot API | https://core.telegram.org/bots/api |
| TikiWiki API | https://doc.tiki.org/API |

---

## Mantenimiento de Este Archivo

Actualizar cuando:
1. Se agrega una funcionalidad principal
2. Se modifica la estructura de archivos
3. Se cambia la visión o filosofía del proyecto
4. Se alcanza una nueva versión
5. Un nuevo desarrollador o agente se une al proyecto

**No actualizar** por fixes de bugs menores — esos van a CAMBIOS.md.
