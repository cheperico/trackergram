# trackerGram

Puente entre Telegram y TikiWiki. Recibe mensajes de un grupo de Telegram y los guarda automáticamente en un tracker de TikiWiki.

## Qué Hace

- **Recibe mensajes en tiempo real**: Cuando alguien escribe en tu grupo de Telegram, el mensaje aparece en TikiWiki
- **Soporta todo tipo de contenido**: Texto, fotos, videos, audio, documentos, stickers, notas de voz, ubicaciones, contactos, encuestas
- **Respeta los topics**: Si tu grupo tiene topics (forums), cada mensaje se guarda con su topic correspondiente
- **Registra reacciones**: Las reacciones a mensajes también se guardan
- **Captura eventos del grupo**: Creación de topics, miembros que entran o salen, mensajes fijados, cambios de título
- **Importa historial**: Podés exportar conversaciones existentes desde Telegram e importarlas
- **Crea trackers automáticamente**: No necesitás configurar los campos a mano

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
| `remove_members` | ⬜ | ✅ | Miembros removidos |
| `joined` | ⬜ | ✅ | Miembro se unió (formato export) |

### Reacciones

| Tipo | Webhook | Import | Descripción |
|---|---|---|---|
| `message_reaction` | ✅ | ⬜ | Reacción a mensaje |
| `message_reaction_count` | ✅ | ⬜ | Conteo de reacciones |

Los tipos marcados como ⬜ son funcionalidades que están identificadas como pendientes en el roadmap.

## Campos del Tracker

Si querés crear el tracker manualmente en TikiWiki (sin usar el botón "Crear Tracker" del panel), los campos que necesitás son:

```ini
[FIELD1]
name = telegram_message_id
permName = telegrammessageTelegramMessageId
type = t
isMain = y
isMandatory = y
isTblVisible = y
isSearchable = y
isPublic = y

[FIELD2]
name = chat_id
permName = telegrammessageChatId
type = t

[FIELD3]
name = chat_title
permName = telegrammessageChatTitle
type = t
isTblVisible = y
isSearchable = y

[FIELD4]
name = topic_id
permName = telegrammessageTopicId
type = t

[FIELD5]
name = topic_title
permName = telegrammessageTopicTitle
type = t
isTblVisible = y
isSearchable = y

[FIELD6]
name = message_date
permName = telegrammessageMessageDate
type = f
isTblVisible = y
isSearchable = y

[FIELD7]
name = user_id
permName = telegrammessageUserId
type = t

[FIELD8]
name = username
permName = telegrammessageUsername
type = t
isTblVisible = y
isSearchable = y

[FIELD9]
name = first_name
permName = telegrammessageFirstName
type = t

[FIELD10]
name = last_name
permName = telegrammessageLastName
type = t

[FIELD11]
name = message_type
permName = telegrammessageMessageType
type = D
options = {"options":["text","photo","video","audio","document","sticker","voice","video_note","system","animation","contact","poll","location","other"]}

[FIELD12]
name = text
permName = telegrammessageText
type = a
isTblVisible = y
isSearchable = y

[FIELD13]
name = media
permName = telegrammessageMedia
type = FG
options = {"galleryId":36}
isTblVisible = y

[FIELD14]
name = media_url
permName = telegrammessageMediaUrl
type = t

[FIELD15]
name = file_url
permName = telegrammessageFileUrl
type = t

[FIELD16]
name = media_type
permName = telegrammessageMediaType
type = t

[FIELD17]
name = media_size
permName = telegrammessageMediaSize
type = n
isSearchable = y

[FIELD18]
name = media_caption
permName = telegrammessageMediaCaption
type = t

[FIELD19]
name = message_Location
permName = telegrammessageLocation
type = G
isTblVisible = y

[FIELD20]
name = media_width
permName = telegrammessageMediaWidth
type = n

[FIELD21]
name = media_height
permName = telegrammessageMediaHeight
type = n

[FIELD22]
name = media_duration
permName = telegrammessageMediaDuration
type = DUR

[FIELD23]
name = edited_date
permName = telegrammessageEditedDate
type = t

[FIELD24]
name = reply_to_id
permName = telegrammessageReplyToId
type = t

[FIELD25]
name = reactions
permName = telegrammessageReactions
type = a
isTblVisible = y
```

Podés copiar todo este bloque y pegarlo en **Admin → Trackers → Importar campos** al crear o editar un tracker.

## Qué Necesitás Antes de Empezar

1. **Un bot de Telegram** — Se crea gratis hablando con [@BotFather](https://t.me/BotFather)
2. **Un TikiWiki 21.x+** — Con la API habilitada y un token de acceso
3. **Un servidor con PHP 8.0+** — Apache o Nginx, accesible desde internet con HTTPS

Si no tenés nada de esto, la [guía de instalación](INSTALL.md) te explica paso a paso cómo conseguirlo.

## Instalación Rápida

1. Copiá los archivos de trackerGram a tu servidor web
2. Copiá `.env.example` a `.env` y completá tus credenciales
3. Accedé al panel de administración: `https://tu-dominio.com/trackergram/admin.php`
4. Configurá el bot token, TikiWiki URL y token
5. Creá un tracker desde el panel (o usá uno existente)
6. Actualizá el webhook con un clic

Para instrucciones detalladas, incluyendo cómo crear el bot y configurar TikiWiki, ver [INSTALL.md](INSTALL.md).

## Uso

### Una vez configurado

No necesitás hacer nada más. Cuando alguien escriba en el grupo de Telegram donde está tu bot, el mensaje aparecerá automáticamente en el tracker de TikiWiki.

### Panel de Administración

Accedé a `https://tu-dominio.com/trackergram/admin.php` con tus credenciales de admin. El panel tiene estas secciones:

| Sección | Qué hace |
|---|---|
| **1. Configuración general** | Token del bot, webhook secret, URL de TikiWiki |
| **2. Importar conversaciones** | Subí un ZIP exportado de Telegram para importar mensajes antiguos |
| **3. Tracker en directo** | Cambiá el ID del tracker que recibe los mensajes en tiempo real |
| **4. Crear Tracker** | Creá un tracker nuevo en TikiWiki con todos los campos automáticamente |

### Importar Conversaciones Antiguas

1. En Telegram: Settings > Export chat data > elegí el formato JSON
2. En el panel de admin, sección "Importar conversaciones": seleccioná el tracker destino y subí el ZIP
3. Esperá a que termine (puede tardar varios minutos dependiendo del tamaño)

## Problemas Comunes

### Los mensajes no llegan a TikiWiki

1. Verificá que el webhook esté activo: accedé a `https://api.telegram.org/bot<TU_TOKEN>/getWebhookInfo`
2. Revisá que el tracker ID sea correcto en el panel de admin
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

## Seguridad

trackerGram incluye las siguientes medidas de seguridad:

- **Secret Token**: Verifica que los webhooks vengan realmente de Telegram
- **CSRF**: Protege el panel de administración contra peticiones falsas
- **Contraseñas con hash**: Las contraseñas de admin se almacenan encriptadas
- **Rate limiting**: Limita la cantidad de intentos de login y peticiones al webhook
- **Protección contra path traversal**: Valida los archivos ZIP importados
- **Archivos sensibles protegidos**: `.env` y `config.php` bloqueados por `.htaccess`

## Documentación Completa

| Documento | Para quién |
|---|---|
| [INSTALL.md](INSTALL.md) | Cómo instalar paso a paso |
| [TECHNICAL.md](TECHNICAL.md) | Cómo está construido (para desarrolladores) |
| [AGENTS.md](AGENTS.md) | Contexto para agentes de IA (lectura obligatoria) |
| [roadmap.md](roadmap.md) | Qué falta por hacer |
| [CAMBIOS.md](CAMBIOS.md) | Historial de cambios por versión |

## Licencia

MIT License
