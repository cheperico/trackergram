# TRACKER_SCHEMA.md — Schema completo del tracker trackerGram

> Este archivo contiene la **definición completa de los 28 campos** del tracker de TikiWiki para trackerGram, en formato INI importable. Es el apéndice técnico extraído de `TECHNICAL-avanzado.md`.

## Requisitos del tracker

| Aspecto | Requisito |
|---|---|
| **Campos** | Debe tener **los 28 campos** listados abajo. Faltan → sincronizables con botón 🛠️ Sync. Sobran → no importa. |
| **Field prefix** | El permName de cada campo sigue el patrón `{prefix} + Sufijo`. El prefix por defecto es `telegrammessage`, pero puede ser cualquiera (ej: `soporte`, `qpch`, `equipo`). |
| **Auto-detección** | Si el prefix storeado es `telegrammessage`, el sistema lo verifica contra los campos reales vía API y lo corrige automáticamente. |
| **File Gallery** | El campo `{prefix}Media` (tipo `FG`) necesita un gallery ID asignado. Al crear el tracker desde el admin, se crea una galería automática. |
| **MessageType** | El campo `{prefix}MessageType` (tipo `t`) guarda valores: `text`, `photo`, `video`, `audio`, `document`, `sticker`, `voice`, `video_note`, `system`, `animation`, `contact`, `poll`, `quiz`, `location`, `other`. |
| **Mandatory** | Solo `{prefix}TelegramMessageId` es obligatorio (isMandatory). Los demás pueden estar vacíos. |

## Lista completa de campos

| # | PermName (sufijo) | Tipo | Descripción | Main | Mandatory | Searchable | TblVisible |
|---|---|---|---|---|---|---|---|
| 1 | `TelegramMessageId` | `t` (text) | ID único del mensaje en Telegram | ✅ | ✅ | ✅ | ✅ |
| 2 | `ChatId` | `t` (text) | ID del chat/grupo en Telegram | | | | |
| 3 | `ChatTitle` | `t` (text) | Título del chat o grupo | | | ✅ | ✅ |
| 4 | `TopicId` | `t` (text) | ID del tema/foro (0 si General) | | | | |
| 5 | `TopicTitle` | `t` (text) | Nombre del tema/foro | | | ✅ | ✅ |
| 6 | `UserId` | `t` (text) | ID numérico del usuario | | | | |
| 7 | `Username` | `t` (text) | @username del usuario | | | ✅ | ✅ |
| 8 | `FirstName` | `t` (text) | Nombre (en import: display name completo) | | | | |
| 9 | `LastName` | `t` (text) | Apellido (solo webhook) | | | | |
| 10 | `DisplayName` | `t` (text) | Nombre completo para mostrar (unificado) | | | ✅ | ✅ |
| 11 | `MessageType` | `t` (text) | Tipo de mensaje (ver values arriba) | | | | |
| 12 | `Text` | `a` (textarea) | Contenido del mensaje (incluye captions) | | | ✅ | ✅ |
| 13 | `MessageDate` | `f` (datetime) | Fecha/hora (timestamp UNIX) | | | ✅ | ✅ |
| 14 | `Media` | `FG` (file gallery) | Archivo multimedia adjunto | | | | ✅ |
| 15 | `MediaUrl` | `t` (text) | URL pública del archivo en TikiWiki | | | | |
| 16 | `FileUniqueId` | `t` (text) | Identificador universal del archivo en Telegram (file_unique_id, no expira entre bots) | | | | |
| 17 | `MediaType` | `t` (text) | Tipo MIME del archivo (ej: image/jpeg) | | | | |
| 18 | `MediaSize` | `n` (number) | Tamaño del archivo en bytes | | | ✅ | |
| 19 | `MediaCaption` | `t` (text) | Descripción asociada al media | | | | |
| 20 | `MediaWidth` | `n` (number) | Ancho en píxeles | | | | |
| 21 | `MediaHeight` | `n` (number) | Alto en píxeles | | | | |
| 22 | `MediaDuration` | `DUR` (duration) | Duración en segundos (hh:mm:ss) | | | | |
| 23 | `Location` | `G` (geolocation) | Coordenadas GPS (lon, lat, zoom) | | | | ✅ |
| 24 | `EditedDate` | `t` (text) | Timestamp UNIX de última edición | | | | |
| 25 | `MediaGroupId` | `t` (text) | ID del grupo de medios (media_group_id) | | | | |
| 26 | `ReplyToId` | `t` (text) | ID del mensaje al que responde | | | | |
| 27 | `Reactions` | `a` (textarea) | Reacciones formateadas (👍 3 · ❤️ 1) | | | | ✅ |
| 28 | `Hashtags` | `F` (freetags) | Hashtags como etiquetas (sin #) | | | ✅ | ✅ |

## INI para importar campos manualmente en TikiWiki

Si querés crear el tracker manualmente desde **Admin → Trackers → Crear/Editar → Importar campos**, copiá este bloque INI completo:

```ini
[FIELD1]
name = telegram_message_id
permName = telegrammessageTelegramMessageId
type = t
description = ID único del mensaje en Telegram
isMain = n
isMandatory = n
isTblVisible = y
isSearchable = n
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

[FIELD7]
name = username
permName = telegrammessageUsername
type = t
description = @username del usuario en Telegram
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

[FIELD9]
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

[FIELD10]
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
type = t
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
name = message_date
permName = telegrammessageMessageDate
type = f
description = Fecha/hora del mensaje (timestamp UNIX)
isTblVisible = n
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

[FIELD14]
name = media_caption
permName = telegrammessageMediaCaption
type = t
description = Texto de descripción asociado al archivo multimedia
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

[FIELD15]
name = media
permName = telegrammessageMedia
type = FG
options = {"galleryId":0}
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

[FIELD16]
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

[FIELD17]
name = file_unique_id
permName = telegrammessageFileUniqueId
type = t
description = Identificador universal del archivo en Telegram (file_unique_id, no expira entre bots)
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

[FIELD18]
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

[FIELD19]
name = media_size
permName = telegrammessageMediaSize
type = n
description = Tamaño del archivo adjunto en bytes
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

[FIELD20]
name = location
permName = telegrammessageLocation
type = G
description = Coordenadas GPS del mensaje (formato: lon, lat, zoom)
isTblVisible = n
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

[FIELD21]
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

[FIELD22]
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

[FIELD23]
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

[FIELD24]
name = media_group_id
permName = telegrammessageMediaGroupId
type = t
description = ID del grupo de medios (media_group_id) para álbumes de fotos/videos
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

[FIELD26]
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

[FIELD27]
name = reactions
permName = telegrammessageReactions
type = a
description = Reacciones al mensaje formateadas como texto (ej: 👍 3 · ❤️ 1)
isTblVisible = n
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

[FIELD28]
name = hashtags
permName = telegrammessageHashtags
type = F
description = Hashtags de Telegram como etiquetas (espacio-separados, sin #)
isTblVisible = y
isMain = n
isSearchable = y
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

> **Nota**: Reemplazá `telegrammessage` por tu field prefix si usás uno custom. Reemplazá `{"galleryId":0}` con el ID real de tu file gallery en TikiWiki. Después de crear el tracker, configurá el orden por defecto en **Admin → Trackers → [tu tracker] → Edit → Default Order**: `defaultOrderKey=-2` (fecha de creación), `defaultOrderDir=desc` (más nuevo primero).

## Referencias

| Recurso | URL |
|---|---|
| Telegram Bot API | https://core.telegram.org/bots/api |
| TikiWiki API | https://doc.tiki.org/API |
| Webhooks (explicación general) | https://webhooks.fyi/ |
