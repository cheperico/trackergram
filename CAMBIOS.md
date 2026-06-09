# Cambios - Changelog

## v0.2.1
- **Feat**: Vista wiki tipo feed para tracker 22 — implementado con `{TRACKERLIST(tplwiki="plantillaTrackergram")}` más template Smarty personalizado con diseño tipo burbuja de chat.
- **Feat**: Multimedia con HTML5 directo — imágenes ocupan 100% del ancho, videos y audios con reproductores `<video controls>` y `<audio controls>` nativos del browser.
- **Fix**: `mediaUrl` (`telegrammessageMediaUrl`) ahora se popula automáticamente tanto en webhook como en import — antes nunca se guardaba.
  - WebhookHandler: construye URL `tiki-download_file.php?fileId=X` tras cada upload exitoso
  - Import (handleProcess y handleFull): mismo tratamiento con `media_url` en contexto
  - MessageMapper::fromExport(): toma `media_url` del contexto y lo pasa a `NormalizedMessage`
- **Docs**: PRETTY_TRACKER.md actualizado — la solución TRACKERLIST + tplwiki está implementada y operativa.
- **Chore**: Documentación de la sesión de diseño de template wiki en `reports/template-wiki-feed.md`

## v0.2.0
- **Feat**: Nuevo campo `telegrammessageDisplayName` — nombre para mostrar unificado entre webhook e import.
  - Webhook: concatena `firstName + " " + lastName`
  - Import: copia el `from` original del export
  - `firstName`/`lastName` se conservan como datos crudos originales
- **Fix**: Import — inicialización de `$filePath` para evitar "Undefined variable" cuando un mensaje no tiene media.
- **Fix**: Seguridad P2 — XSS en admin moderno, DoS en webhook, límite 20MB real en descarga de media, token leak en redirects, validación de tamaño en import.
- **Feat**: Import chunked — `import.php` ahora soporta `mode=extract` y `mode=process`. Admin moderno con barra de progreso y procesamiento por lotes de 100 mensajes.
- **Fix**: `log_message()` mejorado con mejor manejo de errores de escritura y rotación.
- **Chore**: `.htaccess` actualizado con límites para imports grandes (200M, 1024M, tiempo ilimitado).
- **Chore**: Límites de import aumentados (20000 archivos, 500MB descomprimido).
- **Docs**: README.md y AGENTS.md actualizados con el nuevo campo displayName.

## v0.1.9
- **Fix**: Galería equivocada en import/webhook — `getMediaGalleryId()` ahora parsea todos los formatos de options que devuelve la API (array asociativo, array legacy, JSON string). Nueva `extractGalleryIdFromOptions()`.
- **Fix**: WebhookHandler ahora pasa `trackerId` a `getMediaGalleryId()` en vez de depender del default del `.env`.
- **Fix**: Import — `fromExport()` ya no parte el nombre con `explode()`. Usa el display name completo como `firstName` para consistencia con webhook.
- **Fix**: Import — `userId` ahora usa regex para extraer el ID numérico de prefijos `user/chat/channel` en vez del frágil `str_replace('user', ...)`.
- **Feat**: `uploadedFileIds` ahora es `array` en vez de `?string`. Mensajes con múltiples archivos se pasan al campo FG como comma-separated.
- **Feat**: `createTracker()` ahora crea una file gallery via `POST /api/galleries` y configura el campo FG con `count=0` (ilimitado) y `galleryId`.
- **Feat**: Nuevos métodos `createGallery()` y `updateFgFieldOptions()` en `TikiWikiClient.php`.
- **Docs**: AGENTS.md actualizado con nuevos features y schema de campos.

## v0.1.7
- **Arquitectura**: `api.php` simplificado a entry point HTTP puro (61 líneas). Toda la lógica extraída a `WebhookHandler.php`.
- **Architectura**: `bootstrap.php` creado con carga centralizada de dependencias. Todos los entry points lo usan.
- **Fix**: `debug.log` ahora funciona correctamente — reemplazados todos los `error_log()` por `log_message()` en el código.
- **Arquitectura**: `MessageMapper::fromWebhook()` unifica detección de tipo de mensaje en webhook.
- **Seguridad**: `TELEGRAM_WEBHOOK_SECRET` ahora obligatorio — bloquea webhook si no está configurado.
- **Seguridad**: ZIP import ahora valida cantidad de archivos (máx. 10000), tamaño descomprimido (200 MB) y profundidad de carpetas (10).
- **Fix**: `change_password` en admin.php movido fuera de `checkAuth()` — ahora funciona correctamente.
- **Fix**: XSS en admin.php — reemplazado `innerHTML` por `textContent` + clases CSS en resultados de import.
- **Fix**: Cache de topics ahora usa clave `chatId:threadId` (evita colisiones entre múltiples chats).
- **Fix**: Cache de gallery ID ahora discrimina por tracker (array `[$trackerId => $galleryId]`).
- **Fix**: Deduplicación post-insert detecta race conditions (cuenta items después de crear).
- **Fix**: IDs de reacciones ahora usan identificadores únicos hash-based (`reaction_{chat}_{msg}_{user}_{date}`).
- **Docs**: PHP 7.4+ actualizado a 8.0+ en toda la documentación.
- **Docs**: Eliminado `setup_webhook.php` (obsoleto, reemplazado por admin.php).
- **Docs**: Reportes externos movidos a `reports/`.
- **Docs**: AGENTS.md actualizado con nueva arquitectura (WebhookHandler, sin referencias a líneas obsoletas).

## v0.1.6
- **Seguridad**: Hash de contraseña admin con `password_hash()`/`password_verify()`. Migración automática desde texto plano.
- **Seguridad**: Path traversal en ZIP - validación de nombres de archivo al extraer exports.
- **Seguridad**: Descarga de multimedia en chunks con límite de 20MB (ya no carga todo en memoria).
- **Seguridad**: Rate limiting en webhook (máx 30 req/min por IP).
- **Seguridad**: URLs de Telegram con token ya no se guardan en TikiWiki.
- **Seguridad**: ALLOWED_CHAT_IDS configurable desde .env.
- **Arquitectura**: `sendToTikiWiki()` e `importItemToTikiWiki()` refactorizados para usar `MessageMapper::toWikiFields()` + `TikiWikiClient::createTrackerItem()`. Eliminado curl duplicado.
- **Fix**: `display_errors` condicional a DEBUG_MODE en admin.php e import.php.
- **Fix**: Deduplicación ahora considera (chat_id, message_id), no solo message_id.
- **Fix**: `ZipArchive::$numEntries` → `$numFiles` (PHP 8.2).

## v0.1.5
- **Fix**: TypeError fatal en PHP 8.5 al pasar string `'general'` donde se esperaba `int` (`getTopicName`)
- **Fix**: Eliminada llamada API a `getForumTopic` (no existe en Telegram Bot API)
- **Fix**: Admin.php ya no pisa `CUSTOM_WEBHOOK_URL` al hacer "Actualizar Webhook"
- **Fix**: Reorganizado admin panel en secciones lógicas: Configuración general (Telegram + TikiWiki), Importar, Tracker del webhook, Webhook, Crear Tracker. Cada formulario guarda solo sus campos.
- **Docs**: Agregada sección "Resolución de Nombres de Topics" en TECHNICAL.md
- **Docs**: Agregados links de referencia de APIs en AGENTS.md

## v0.1.4
- Refactorización: Separación de responsabilidades en clientes (TikiWikiClient, TelegramClient, MessageMapper)
- Fix: error de integración en la refactorización (braces extraviados, código residual)
- Importación de exports de Telegram: procesamiento de archivos ZIP exportados
- Soporte para varios tipos de mensaje: texto, foto, video, audio, documento, sticker
- Extracción de topics desde mensajes de tipo service (topic_created)
- Subida de archivos multimedia a TikiWiki durante importación
- Fix: conversion de fecha a UNIX timestamp para TikiWiki API
- Fix: manejo de texto como array (formatos complejos de Telegram)
- Fix: limpiar directorios recursivamente al finalizar importación
- Fix: usar file_name y media_type para detectar tipo de archivo correctamente

## v0.1.2
- Creación automática de tracker con campos via API de TikiWiki
- Interfaz reorganizada con índice y secciones (tracker en directo, importación)
- **Seguridad**: Deduplicación corregida - ahora filtra por message_id específico
- **Seguridad**: checkAuth() ejecutado antes de procesar cualquier acción
- **Seguridad**: setup_webhook.php protegido - solo CLI/localhost
- **Seguridad**: TELEGRAM_WEBHOOK_SECRET obligatorio con hash_equals()
- **Seguridad**: Logs de debug condicionados por DEBUG_MODE
- Fix: ModSecurity bloqueaba peticiones sin User-Agent
- Fix: Tipos de campo correctos para API de TikiWiki (t, a, n, f, D, FG)
- Fix: Validación de secret token solo en peticiones de webhook
- Fix: Evitar ejecución de webhook al incluir api.php como librería
- Detección automática de nombres de topics desde reply_to_message.forum_topic_created
- Fix detección HTTPS con proxies (X-Forwarded-Proto)
- Simplificación: webhook se actualiza automáticamente desde URL del servidor

## v0.1.1
- Deduplicación de mensajes basada en message_id
- Agregado soporte para ubicaciones, contactos, encuestas, animations
- Captura de nombre del chat (chat_title)
- Título del topic (topic_title)
- Mejor manejo de mensajes no soportados (muestra el tipo)
- Refactorización: type hints en funciones, constantes de configuración, logging unificado
- Fix: Token validation con `:` en tokens de Telegram
- Fix: Campo CUSTOM_WEBHOOK_URL para especificar URL del webhook manualmente
- Fix: Bug de doble `/api.php` en generateWebhookUrl()
- Fix: Campo de ubicación (geolocation) no se enviaba a TikiWiki

## v0.1.0
- Subida de archivos multimedia a TikiWiki file gallery
- Los archivos se vinculan al campo `telegrammessageMedia` del tracker
- El galleryId se obtiene dinámicamente desde la configuración del tracker via API

## v0.0.1
- Primera versión funcional
- Webhook endpoint para Telegram
- Integración básica con TikiWiki trackers
- Interfaz de administración
- Autenticación con rate limiting y sesión segura
- CSRF protection en formularios
- Validación de inputs