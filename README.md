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
- **Multi-conexión**: Un mismo webhook atiende múltiples bots, wikis y trackers. Cada conexión se rutea por `(chat_id + webhook_secret)`. Compartir el mismo `bot_token` entre conexiones es válido (el `webhook_secret` se reusa automáticamente).

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
| `poll` | ✅ | ✅ | Encuesta |
| `animation` | ✅ | ✅ | GIF/animación |

### Eventos del grupo (service messages)

| Evento | Webhook | Import | Descripción |
|---|---|---|---|
| `forum_topic_created` | ✅ | ✅ | Topic creado |
| `forum_topic_edited` | ✅ | ✅ | Topic renombrado |
| `forum_topic_closed` | ✅ | ✅ | Topic cerrado |
| `forum_topic_reopened` | ✅ | ✅ | Topic reabierto |
| `group_chat_created` | ✅ | ✅ | Grupo creado |
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

## Campos del Tracker

Si querés crear el tracker manualmente en TikiWiki (sin usar el botón "Crear Tracker" del panel), los campos que necesitás son:

```ini
[FIELD1]
name = telegram_message_id
permName = telegrammessageTelegramMessageId
type = t
description = ID único del mensaje en Telegram
isMain = y
isMandatory = y
isTblVisible = y
isSearchable = y
isPublic = y
isHidden = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD2]
name = chat_id
permName = telegrammessageChatId
type = t
description = ID del chat/grupo en Telegram
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD3]
name = chat_title
permName = telegrammessageChatTitle
type = t
description = Título del chat o grupo
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD4]
name = topic_id
permName = telegrammessageTopicId
type = t
description = ID del tema o foro (0 si es General)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD5]
name = topic_title
permName = telegrammessageTopicTitle
type = t
description = Nombre del tema o foro
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD6]
name = message_date
permName = telegrammessageMessageDate
type = f
description = Fecha/hora del mensaje (timestamp UNIX)
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD7]
name = user_id
permName = telegrammessageUserId
type = t
description = ID numérico del usuario que envió el mensaje
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD8]
name = username
permName = telegrammessageUsername
type = t
description = @username del usuario en Telegram
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD9]
name = first_name
permName = telegrammessageFirstName
type = t
description = Nombre del usuario (en import: display name completo)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD10]
name = last_name
permName = telegrammessageLastName
type = t
description = Apellido del usuario (solo disponible en webhook)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD26]
name = display_name
permName = telegrammessageDisplayName
type = t
description = Nombre completo para mostrar (unificado webhook e import)
isMain = n
isSearchable = y
isTblVisible = y
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD11]
name = message_type
permName = telegrammessageMessageType
type = D
options = {"options":["text","photo","video","audio","document","sticker","voice","video_note","system","animation","contact","poll","location","other"]}
description = Tipo de mensaje: text, photo, video, audio, document, sticker, voice, system, etc.
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD12]
name = text
permName = telegrammessageText
type = a
description = Contenido textual del mensaje (incluye captions de media)
isTblVisible = y
isSearchable = y
isMain = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD13]
name = media
permName = telegrammessageMedia
type = FG
options = {"galleryId":36}
description = Archivo multimedia adjunto (referencia a File Gallery de TikiWiki)
isTblVisible = y
isMain = n
isSearchable = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD14]
name = media_url
permName = telegrammessageMediaUrl
type = t
description = URL pública del archivo multimedia en TikiWiki
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD15]
name = file_url
permName = telegrammessageFileUrl
type = t
description = URL original del archivo en los servidores de Telegram
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD16]
name = media_type
permName = telegrammessageMediaType
type = t
description = Tipo MIME del archivo adjunto (ej: image/jpeg, video/mp4)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD17]
name = media_size
permName = telegrammessageMediaSize
type = n
description = Tamaño del archivo adjunto en bytes
isSearchable = y
isMain = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD18]
name = media_caption
permName = telegrammessageMediaCaption
type = t
description = Texto de descripción asociado al archivo multimedia
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD19]
name = message_Location
permName = telegrammessageLocation
type = G
description = Coordenadas GPS del mensaje (formato: lon, lat, zoom)
isTblVisible = y
isMain = n
isSearchable = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD20]
name = media_width
permName = telegrammessageMediaWidth
type = n
description = Ancho de la imagen/video en píxeles
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD21]
name = media_height
permName = telegrammessageMediaHeight
type = n
description = Alto de la imagen/video en píxeles
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD22]
name = media_duration
permName = telegrammessageMediaDuration
type = DUR
description = Duración del audio/video/voice en segundos (se muestra como hh:mm:ss)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD23]
name = edited_date
permName = telegrammessageEditedDate
type = t
description = Fecha de última edición (timestamp UNIX, vacío si no fue editado)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD24]
name = reply_to_id
permName = telegrammessageReplyToId
type = t
description = ID del mensaje al que responde (para conversaciones en hilo)
isMain = n
isSearchable = n
isTblVisible = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y

[FIELD25]
name = reactions
permName = telegrammessageReactions
type = a
description = Reacciones al mensaje formateadas como texto (ej: 👍 3 · ❤️ 1)
isTblVisible = y
isMain = n
isSearchable = n
isPublic = y
isHidden = n
isMandatory = n
isMultilingual = n
descriptionIsParsed = n
excludeFromNotification = n
visibleInViewMode = y
visibleInEditMode = y
visibleInHistoryMode = y
```
Podés copiar todo este bloque y pegarlo en **Admin → Trackers → Importar campos** al crear o editar un tracker.

## Qué Necesitás Antes de Empezar

1. **Un bot de Telegram** — Se crea gratis hablando con [@BotFather](https://t.me/BotFather). Necesitás el token.
2. **Un TikiWiki 21.x+** — Con la API habilitada, un token de acceso y un tracker (o dejá que trackerGram lo cree automáticamente).
3. **Un servidor con PHP 8.0+** — Apache o Nginx, accesible desde internet con HTTPS.

Cada vinculación (bot + wiki + tracker) se configura desde el panel de admin como una **conexión**. Podés tener múltiples bots y wikis desde una misma instalación. Incluso podés tener **varias conexiones con el mismo bot** pero diferentes grupos de Telegram y diferentes wikis — el webhook es único por bot, pero `api.php` rutea cada mensaje al tracker correcto según el grupo de origen.

Si no tenés nada de esto, la [guía de instalación](INSTALL.md) te explica paso a paso.

## Configurar Permisos en TikiWiki

trackerGram necesita un usuario de API con permisos específicos en TikiWiki. Lo ideal es crear un **grupo** dedicado y un **usuario** para ese grupo.

### 1. Crear el grupo `trackerGram`

En TikiWiki: **Admin → Grupos → Crear un nuevo grupo**

| Campo | Valor |
|-------|-------|
| Nombre del grupo | `trackerGram` |
| Descripción | Usuarios de la API de trackerGram |
| Heredar de | `Registered` (o ningún grupo) |

### 2. Asignar permisos al grupo

En TikiWiki: **Admin → Grupos → trackerGram → Permisos**

Agregá estos permisos al grupo:

| Permiso | Ámbito | ¿Para qué sirve? |
|---------|--------|-----------------|
| `tiki_p_view_trackers` | Objeto (tracker) | Leer fields del tracker y listar trackers |
| `tiki_p_create_tracker_items` | Objeto (tracker) | Crear items nuevos (mensajes) |
| `tiki_p_upload_files` | Objeto (file gallery) | Subir fotos, videos, audios, etc. |
| `tiki_p_view_file_gallery` | Objeto (file gallery) | Acceder a la galería de archivos |
| `tiki_p_admin_trackers` | **Global** ⚠️ | Consultar items (deduplicación), actualizar field FG options, crear/editar fields |
| `tiki_p_admin_file_galleries` | Objeto (file gallery) | Crear galerías automáticamente (auto-reparación) |

> **⚠️ Importante**: `tiki_p_admin_trackers` **debe asignarse a nivel GLOBAL** (en la solapa "Permisos" del grupo, no desde un tracker individual). La API de TikiWiki exige este permiso global para listar items. Sin esto, la deduplicación falla y los mensajes se duplicarían.

### 3. Crear el usuario

En TikiWiki: **Admin → Usuarios → Crear un nuevo usuario**

| Campo | Valor |
|-------|-------|
| Nombre de usuario | `trackergram` |
| Contraseña | Elegí una segura |
| Grupos | Agregar al grupo `trackerGram` |

### 4. Crear el token de API

En TikiWiki: **Admin → Seguridad → API → Crear token**

| Campo | Valor |
|-------|-------|
| Token | Dejá que TikiWiki lo genere automáticamente |
| Usuario asociado | `trackergram` |
| Permisos del token | Marcar todos (los permisos reales los controla el grupo) |

### 5. Copiar la URL del API y el token

La URL de la API debe terminar en `/api/`. Ejemplo:
```
https://wiki.ejemplo.org/api/
```

Estos dos datos los vas a necesitar en el panel de admin de trackerGram al crear una conexión.

## Instalación Rápida

1. Copiá los archivos de trackerGram a tu servidor web
2. Copiá `.env.example` a `.env` y completá solo usuario/contraseña de admin
3. Accedé al panel de administración: `https://tu-dominio.com/trackergram/admin.php`
4. Creá una **conexión** desde el panel: nombre, bot token, webhook secret, TikiWiki URL, token y tracker ID
5. Configurá el webhook con un clic desde la misma conexión (botón "🌐 Webhook")
   - Si ya hay otra conexión con el mismo `bot_token`, el `webhook_secret` se reusa automáticamente
   - No necesitás configurar el webhook más de una vez por bot
6. Verificá que funcione con el botón "🧪 Test"
7. Agregá el bot al grupo de Telegram — ¡los mensajes empiezan a llegar al tracker automáticamente!

Para instrucciones detalladas, incluyendo cómo crear el bot y configurar TikiWiki por primera vez, ver [INSTALL.md](INSTALL.md).

## Uso

### Una vez configurado

No necesitás hacer nada más. Cuando alguien escriba en el grupo de Telegram donde está tu bot, el mensaje aparecerá automáticamente en el tracker de TikiWiki.

### Panel de Administración

Accedé a `https://tu-dominio.com/trackergram/admin.php` con tus credenciales de admin. El panel tiene dos pestañas:

| Pestaña | Qué hace |
|---|---|
| **Webhook** | Administrá las conexiones: creá, editá, habilitá/deshabilitá cada vinculación entre un bot de Telegram y un tracker de TikiWiki. Configurá el webhook, probá la conexión, creá trackers automáticamente. |
| **Importar** | Seleccioná una conexión o ingresá datos manualmente, subí un ZIP exportado de Telegram para importar mensajes antiguos al mismo tracker. |

### Importar Conversaciones Antiguas

1. En Telegram: Settings > Export chat data > elegí el formato JSON
2. En el panel de admin, pestaña "Importar": seleccioná la conexión destino (autocompleta TikiWiki + tracker) o ingresá manualmente
3. Subí el ZIP y esperá a que termine (barra de progreso con lotes de 50 mensajes)

## Problemas Comunes

### Los mensajes no llegan a TikiWiki

1. Verificá que el webhook esté activo: accedé al panel de admin y usá el botón "🌐 Webhook" o consultá manualmente: `https://api.telegram.org/bot<TU_TOKEN>/getWebhookInfo`
2. Revisá que el tracker ID y las credenciales Tiki sean correctas en el panel de admin
3. Activá `DEBUG_MODE=true` en `.env` y revisá `debug.log`

### Error al conectar a TikiWiki

1. Verificá que la URL de la API de TikiWiki sea correcta (debe terminar en `/api/`)
2. Verificá que el token de TikiWiki sea válido
3. Probá acceder a la API desde tu navegador: `https://wiki.ejemplo.com/api/trackers/1`

### El webhook no responde

1. La URL debe ser **pública** y con **HTTPS** — no funciona con localhost
2. Verificá que el servidor web esté funcionando
3. Si usás firewall, aseguráte de que las IPs de Telegram estén permitidas:
   - 149.154.160.0/20
   - 91.108.4.0/22

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
