# Cambios - Changelog

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
- **Docs**: Agregados links de referencia de APIs en CONTEXTO.md

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