# Cambios - Changelog

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