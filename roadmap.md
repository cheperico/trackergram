# trackerGram - Roadmap

## Estado del Proyecto

- **Estado**: Activo - En desarrollo
- **Última versión**: v0.1.2
- **Funcionalidad principal**: Recibe webhooks de Telegram y envía mensajes directamente a TikiWiki trackers

## Pendientes

### Funcionalidades

- [x] **Creación automática de tracker**: Crear tracker con campos via API de TikiWiki
  - Panel admin: crear nuevo tracker con nombre → genera campos automáticamente
  - Tipos de campo TikiWiki: `t` (text), `a` (textarea), `n` (numeric), `f` (datetime), `D` (dropdown+other), `FG` (files), `G` (location)
  - Campos del tracker:
    - `t` (position 0-180) - Telegram Message ID, Chat ID, Chat Title, Topic ID, Topic Title, User ID, Username, First Name, Last Name, Media URL, File URL, Media Type, Media Caption
    - `f` (position 50) - Message Date (fecha y hora)
    - `D` (position 100) - Message Type con opciones: text, photo, video, audio, document, sticker, voice, video_note, system
    - `a` (position 110) - Text
    - `FG` (position 120) - Media (archivos, requiere galleryId en opciones)
    - `n` (position 160) - Media Size
    - `G` (position 180) - Location (ubicación geográfica)
- [ ] **Sistema de etiquetas**: Extraer hashtags (#etiqueta) de mensajes de Telegram y guardarlos en campo del tracker para conectar con el sistema de tags de TikiWiki
- [ ] **Importar export de Telegram**: Importar conversaciones desde JSON exportado de Telegram (para carga masiva de datos históricos)
  - **Por zip**: Requiere aumentar límites de PHP (para exports pequeños)
    - Mostrar límites claramente en panel (lenguaje usuario + datos técnicos)
    - Después de procesar, borrar archivo importado
  - **Por FTP + carpeta local**: Recomendado - subir export por FTP a una carpeta y procesar desde ahí (sin límites de upload, procesamiento en chunks)

### Seguridad

- [ ] **Rate limiting webhook**: Para prevenir abuso del endpoint (importante para producción)
- [ ] **Logging de accesos no autorizados**: Registrar intentos de acceso fallidos
- [ ] **IP whitelisting para admin**: Restringir acceso a interfaz administrativa por IP

### Bugs

- [ ] **Manejo inconsistente de errores**: api.php tiene funciones que retornan null, otras retornan false
- [ ] **Código duplicado**: Detección de protocolo y construcción de URL del webhook duplicada en admin.php
- [x] ~~**Fix api.php line 298**: Log message has incorrect spacing~~ ✅ Corregido
- [x] ~~**Fix api.php line 278**: media_url assigned to both media_url and file_url fields unintentionally~~ ✅ Corregido
- [x] ~~**Fix api.php line 299**: Remove or fix sleep(1) in retry loop~~ ✅ Corregido - ahora usa usleep
- [x] ~~**Deduplicación de mensajes**: Implementar basada en message_id~~ ✅ Corregido en v0.1.1
- [x] ~~**Fix api.php line 232**: Fix log truncation that could leave incomplete HTML tags~~ ✅ Ya no aplica
- [x] ~~**Fix admin.php line 177**: Increase input size for bot token~~ ✅ Ya tiene size="60"
- [x] ~~**Fix setup_webhook.php**: Inconsistent requirement~~ ✅ Ya funciona correctamente

### Mejoras Técnicas (Refactorización)

#### Prioridad Alta
- Extraer clases para APIs externas (TelegramClient, TikiWikiClient)
- Implementar patrón de inyección de dependencias

#### Prioridad Media
- Agregar tests unitarios
- Implementar PSR-4 autoloading
- Crear documentación de API interna

#### Prioridad Baja
- Agregar tipos estrictos (strict_types)
- Agregar anotaciones de tipo para arrays (phpdoc)

#### ✅ Completado
- [x] Eliminar variables globales ($mediaGalleryIdCache) - Implementado con static dentro de función
- [x] Type hints en funciones
- [x] Constantes de configuración
- [x] Logging unificado

### Monitoreo

- [ ] **Métricas de uso**: Cantidad de mensajes, uso de recursos, performance

---

## Historial de Versiones

### v0.1.1 (Completado)
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

### v0.1.2 (Completado)
- Creación automática de tracker via API de TikiWiki
- Fix: ModSecurity bloqueaba peticiones sin User-Agent
- Fix: Corrección de endpoint y tipos de campo para API de TikiWiki

### v0.1.0 (Completado)
- Subida de archivos multimedia a TikiWiki file gallery
- Los archivos se vinculan al campo `telegrammessageMedia` del tracker
- El galleryId se obtiene dinámicamente desde la configuración del tracker via API

### v0.0.1 (Completado)
- Primera versión funcional
- Webhook endpoint para Telegram
- Integración básica con TikiWiki trackers
- Interfaz de administración