# trackerGram

Puente entre Telegram y TikiWiki. Recibe mensajes de un grupo de Telegram y los guarda automáticamente en un tracker de TikiWiki.

**Repositorio**: https://github.com/cheperico/trackergram

## Qué Hace

- **Recibe mensajes en tiempo real**: Cuando alguien escribe en tu grupo de Telegram, el mensaje aparece en TikiWiki
- **Soporta todo tipo de contenido**: Texto, fotos, videos, audio, documentos, stickers, notas de voz, ubicaciones, contactos, encuestas
- **Respeta los topics**: Si tu grupo tiene topics (forums), cada mensaje se guarda con su topic correspondiente
- **Registra reacciones**: Las reacciones a mensajes también se guardan
- **Captura eventos del grupo**: Creación de topics, miembros que entran o salen, mensajes fijados, cambios de título
- **Importa historial**: Podés exportar conversaciones existentes desde Telegram e importarlas
- **Crea trackers automáticamente**: No necesitás configurar los campos a mano
- **Captura mensajes editados**: Si un mensaje se edita en Telegram, trackerGram detecta el cambio y actualiza el texto en TikiWiki automáticamente
- **Importa historial con enriquecimiento de polls**: Los exports ZIP de Telegram se importan con datos reales de votantes en encuestas y quizzes
- **Multi-conexión**: Un mismo webhook atiende múltiples bots, wikis y trackers. Cada conexión se rutea por `(chat_id + webhook_secret)`. Compartir el mismo `bot_token` entre conexiones es válido (el `webhook_secret` se reusa automáticamente).
- **Auto-detección de field prefix**: Detecta automáticamente el prefijo de campos del tracker, sin configuración manual

## Qué Necesitás Antes de Empezar

1. **Un bot de Telegram** — Se crea gratis hablando con [@BotFather](https://t.me/BotFather). Necesitás el token.
2. **Un TikiWiki 21.x+** — Con la API habilitada, un token de acceso y un tracker (o dejá que trackerGram lo cree automáticamente).
3. **Un servidor con PHP 8.0+** — Apache o Nginx, accesible desde internet con HTTPS.
4. **⚠️ El bot debe ser administrador del grupo** — Para recibir **todos los mensajes** (texto, fotos, videos, etc.), el bot necesita ser administrador del grupo de Telegram. Los bots por defecto tienen **Privacy Mode** habilitado, lo que significa que solo ven mensajes de sistema (miembros que entran/salen, topics, pins) y comandos. Hacé admin al bot desde los ajustes del grupo con permisos mínimos (solo "Leer mensajes"). Ver [Privacy Mode](https://core.telegram.org/bots/features#privacy-mode) en la documentación oficial de Telegram.

Cada vinculación (bot + wiki + tracker) se configura desde el panel de admin como una **conexión**. Podés tener múltiples bots y wikis desde una misma instalación.

## Instalación Rápida

1. Copiá los archivos de trackerGram a tu servidor web
2. Copiá `.env.example` a `.env` y completá solo usuario/contraseña de admin
3. Accedé al panel de administración: `https://tu-dominio.com/trackergram/admin.php`
4. Creá una **conexión** desde el panel: nombre, bot token, webhook secret, TikiWiki URL, token y tracker ID
5. Configurá el webhook con un clic desde la misma conexión (botón "🌐 Webhook")
   - Si ya hay otra conexión con el mismo `bot_token`, el `webhook_secret` se reusa automáticamente
6. Verificá que funcione con el botón "🧪 Test"
7. Agregá el bot al grupo de Telegram — ¡los mensajes empiezan a llegar al tracker automáticamente!

Para instrucciones detalladas, incluyendo cómo crear el bot, configurar permisos de TikiWiki y configurar el tracker, ver [INSTALL.md](INSTALL.md).

## Tipos de Mensaje Soportados

### Contenido de mensajes

| Tipo | Webhook | Import | Descripción |
|---|---|---|---|
| `text` | ✅ | ✅ | Texto plano |
| `photo` | ✅ | ✅ | Foto |
| `video` | ✅ | ✅ | Video |
| `audio` | ✅ | ✅ | Archivo de audio |
| `document` | ✅ | ✅ | Documento (PDF, Word, etc.) |
| `sticker` | ✅ | ✅ | Sticker |
| `voice` | ✅ | ✅ | Nota de voz |
| `video_note` | ✅ | ✅ | Video circular |
| `location` | ✅ | ✅ | Ubicación con coordenadas |
| `contact` | ✅ | ✅ | Contacto compartido |
| `poll` | ⚠️ | ✅ | Encuesta — en webhook se marca como "no capturada en tiempo real" (sin datos de votos). Import de export ZIP la enriquece con opciones y votantes reales |
| `animation` | ✅ | ✅ | GIF/animación |

### Eventos del grupo (service messages)

| Evento | Webhook | Import | Descripción |
|---|---|---|---|---|
| `forum_topic_created` | ✅ | ✅ | Topic creado |
| `forum_topic_edited` | ✅ | ✅ | Topic renombrado |
| `forum_topic_closed` | ✅ | ✅ | Topic cerrado |
| `forum_topic_reopened` | ✅ | ✅ | Topic reabierto |
| `group_chat_created` | ✅ | ✅ | Grupo creado |
| `migrate_to_supergroup` | 🚫 | ✅ | Grupo migra a supergrupo (solo en imports históricos) |
| `migrate_from_group` | 🚫 | ✅ | Inicio de supergrupo post-migración (solo en imports históricos) |
| `new_chat_title` | ✅ | ✅ | Título del grupo cambiado |
| `new_chat_photo` | ✅ | ✅ | Foto del grupo actualizada |
| `delete_chat_photo` | ✅ | ✅ | Foto del grupo eliminada |
| `new_chat_members` | ✅ | ✅ | Miembros que se unen |
| `left_chat_member` | ✅ | ✅ | Miembro que se va |
| `pinned_message` | ✅ | ✅ | Mensaje fijado |
| `invite_members` / `add_members` | ✅ | ✅ | Miembros invitados |
| `remove_members` | ✅ | ✅ | Miembros removidos |
| `joined` | ✅ | ✅ | Miembro se unió (formato export) |

### Reacciones

| Tipo | Webhook | Import | Descripción |
|---|---|---|---|---|
| `message_reaction` | ✅ | ✅ (embebidas) | Reacción a mensaje — en webhook llega como evento aparte, en import vienen dentro del mensaje |
| `message_reaction_count` | ✅ | ✅ (embebidas) | Conteo de reacciones |

> **Nota**: Las reacciones en importación vienen embebidas como campo `reactions[]` dentro de cada mensaje, no como eventos separados. Ya se importan automáticamente con cada mensaje.

## Uso

### Una vez configurado

No necesitás hacer nada más. Cuando alguien escriba en el grupo de Telegram donde está tu bot, el mensaje aparecerá automáticamente en el tracker de TikiWiki.

### Panel de Administración

Accedé a `https://tu-dominio.com/trackergram/admin.php` con tus credenciales de admin. El panel tiene tres pestañas:

| Pestaña | Qué hace |
|---|---|
| **Webhook** | Administrá las conexiones: creá, editá, habilitá/deshabilitá cada vinculación entre un bot de Telegram y un tracker de TikiWiki. Configurá el webhook, probá la conexión, creá trackers automáticamente. |
| **Importar** | Seleccioná una conexión o ingresá datos manualmente, subí un ZIP exportado de Telegram para importar mensajes antiguos al mismo tracker. |
| **Crear Tracker** | Creá un tracker nuevo en TikiWiki con todos los campos necesarios, sin salir del panel. |

### Importar Conversaciones Antiguas

1. En Telegram: Settings → Advanced → Export Telegram data → elegí el formato JSON (sin photos para agilizar si es muy grande)
2. En el panel de admin, pestaña "Importar": seleccioná la conexión destino (autocompleta TikiWiki + tracker) o ingresá manualmente
3. Subí el ZIP y esperá a que termine (barra de progreso con lotes de 50 mensajes)

## Campos del Tracker

El tracker usa **26 campos** con permNames que siguen el patrón `{prefix}TelegramMessageId`, `{prefix}ChatId`, etc. El prefijo por defecto es `telegrammessage` (auto-detectable por conexión).

| PermName (sufijo) | Tipo | Descripción |
|---|---|---|
| `TelegramMessageId` | `t` (text) | ID único del mensaje en Telegram |
| `ChatId` | `t` (text) | ID del chat/grupo en Telegram |
| `ChatTitle` | `t` (text) | Título del chat o grupo |
| `TopicId` | `t` (text) | ID del tema/foro (0 si General) |
| `TopicTitle` | `t` (text) | Nombre del tema/foro |
| `UserId` | `t` (text) | ID numérico del usuario |
| `Username` | `t` (text) | @username en Telegram |
| `FirstName` | `t` (text) | Nombre (en import: display name completo) |
| `LastName` | `t` (text) | Apellido (solo webhook) |
| `DisplayName` | `t` (text) | Nombre completo (unificado) |
| `MessageType` | `D` (dropdown) | Tipo: text, photo, video, audio, document, sticker, voice, etc. |
| `Text` | `a` (textarea) | Contenido del mensaje (incluye captions) |
| `MessageDate` | `f` (datetime) | Fecha/hora (timestamp UNIX) |
| `Media` | `FG` (file gallery) | Archivo multimedia adjunto |
| `MediaUrl` | `t` (text) | URL pública del archivo en TikiWiki |
| `FileUrl` | `t` (text) | URL original en Telegram |
| `MediaType` | `t` (text) | Tipo MIME |
| `MediaSize` | `n` (number) | Tamaño en bytes |
| `MediaCaption` | `t` (text) | Descripción del media |
| `MediaWidth` / `MediaHeight` | `n` (number) | Dimensiones en píxeles |
| `MediaDuration` | `DUR` (duration) | Duración en segundos (hh:mm:ss) |
| `Location` | `G` (geolocation) | Coordenadas GPS (lon, lat, zoom) |
| `EditedDate` | `t` (text) | Timestamp de última edición |
| `ReplyToId` | `t` (text) | ID del mensaje al que responde |
| `Reactions` | `a` (textarea) | Reacciones formateadas (👍 3 · ❤️ 1) |
| `Hashtags` | `F` (freetags) | Hashtags como etiquetas |

> **Alternativa más fácil**: Usá la pestaña "Crear Tracker" del panel de admin — genera todos los campos automáticamente.
>
> Si necesitás la configuración INI completa para importar campos manualmente en TikiWiki, consultá el [Apéndice en TECHNICAL.md](TECHNICAL.md#apéndice-schema-completo-del-tracker-para-trackergram).

## Problemas Comunes

### Los mensajes no llegan a TikiWiki

1. Verificá que el webhook esté activo: accedé al panel de admin y usá el botón "🌐 Webhook" o consultá manualmente: `https://api.telegram.org/bot<TU_TOKEN>/getWebhookInfo`
2. Revisá que el tracker ID y las credenciales Tiki sean correctas en el panel de admin
3. **Si solo llegan mensajes de sistema** (miembros, topics, pins) pero **no los mensajes de texto**: el bot no es administrador del grupo. Por defecto Telegram limita lo que ve el bot (Privacy Mode). Hacé admin al bot desde los ajustes del grupo.
4. Activá `DEBUG_MODE=true` en `.env` y revisá `debug.log`

### Error al conectar a TikiWiki

1. Verificá que la URL de la API de TikiWiki sea correcta (debe terminar en `/api/`)
2. Verificá que el token de TikiWiki sea válido
3. Probá acceder a la API desde tu navegador: `https://wiki.ejemplo.com/api/trackers/1`

### El webhook no responde

1. La URL debe ser **pública** y con **HTTPS** — no funciona con localhost
2. Verificá que el servidor web esté funcionando
3. Si usás firewall, aseguráte de que las IPs de Telegram estén permitidas (fuente oficial: https://core.telegram.org/resources/cidr.txt):
   - 91.108.56.0/22, 91.108.4.0/22, 91.108.8.0/22, 91.108.16.0/22, 91.108.12.0/22
   - 149.154.160.0/20
   - 91.105.192.0/23, 91.108.20.0/22, 185.76.151.0/24

### Error 406 de ModSecurity

Si tu servidor tiene ModSecurity activado, puede bloquear las peticiones a TikiWiki. Contactá a tu hosting para que agreguen una excepción.

## Documentación Completa

| Documento | Para quién |
|---|---|
| [INSTALL.md](INSTALL.md) | Cómo instalar paso a paso |
| [TECHNICAL.md](TECHNICAL.md) | Cómo está construido (para desarrolladores y curiosos) |
| [AGENTS.md](AGENTS.md) | Contexto para agentes de IA (lectura obligatoria para IA) |
| [roadmap.md](roadmap.md) | Qué falta por hacer, prioridades |
| [CAMBIOS.md](CAMBIOS.md) | Historial de cambios por versión |
| `design/*` | Documentos de diseño exploratorio (features en discusión) |
| `opt/visualizacion-tiki.md` | Template Smarty para feed tipo chat en TikiWiki |

## Licencia

MIT License
