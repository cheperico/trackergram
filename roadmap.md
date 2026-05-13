# trackerGram - Roadmap

## Estado del Proyecto

- **Estado**: Activo - En desarrollo
- **Última versión**: v0.1.3
- **Funcionalidad principal**: Recibe webhooks de Telegram y envía mensajes directamente a TikiWiki trackers

## Pendientes

### Funcionalidades

- [~] **Creación automática de tracker**: Parcialmente implementado
   - Panel admin: crear nuevo tracker con nombre → genera campos automáticamente
   - Tipos de campo: implementación básica (no todos los tipos documentados)
   - Pendiente: corregir tipos de campo (FG, G, D) y valores por defecto
   - Pendiente: conectar correctamente la UI de "Crear Tracker" en admin.php
- [ ] **Sistema de etiquetas**: Extraer hashtags (#etiqueta) de mensajes de Telegram y guardarlos en campo del tracker para conectar con el sistema de tags de TikiWiki
- [x] **Importar export de Telegram**: Importar conversaciones desde JSON exportado de Telegram
   - Implementado: procesamiento por upload ZIP
   - Pendiente: optimizar rendimiento (27 msgs/2min es lento para exports grandes)
   - Opciones futuras: batch API, procesamiento async, cacheo de conexiones
   - **Por FTP + carpeta local**: Recomendado para exports muy grandes - subir por FTP y procesar en chunks sin límites de upload

### Seguridad

- [ ] **Deduplicación por (chat_id, message_id)**: Actualmente filtra solo por message_id - puede fallar entre diferentes chats
  - [x] ~~Deduplicación básica~~ ✅ Parcialmente implementado
- [x] ~~checkAuth() antes de procesar acciones mutantes~~ ✅
- [x] ~~Validación CSRF en import.php~~ ✅ Agregado en v0.1.3
- [x] ~~setup_webhook.php protegido (solo CLI/localhost)~~ ✅
- [x] ~~TELEGRAM_WEBHOOK_SECRET obligatorio con hash_equals()~~ ✅
- [x] ~~Logs de debug condicionados por DEBUG_MODE~~ ✅
- [ ] **Rotar token del bot**: Si ya se usó con media real, el token quedó expuesto en URLs de archivos guardados en TikiWiki
- [ ] **Limitar tamaño de descarga de media**: Descargas completas en memoria pueden agotar recursos
- [ ] **Hash de contraseña admin**: Usar password_hash()/password_verify() en vez de comparación en claro
- [ ] **Forzar HTTPS**: No permitir TIKIWIKI_API_URL en http://
- [ ] **No guardar URLs con token**: Descargar media via proxy o usar token limitado en lugar de guardar URLs con token de Telegram
- [ ] **Límite de tamaño de archivos**: Evitar DoS por archivos grandes (descarga completa en memoria)
- [ ] **ALLOWED_CHAT_IDS por defecto**: No aceptar todos los chats, permitir solo los autorizados
- [ ] **display_errors=0 en admin**: Apagar errores visibles en producción
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