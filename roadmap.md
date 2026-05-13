# trackerGram - Roadmap

## Estado del Proyecto

- **Estado**: Activo - En desarrollo
- **Última versión**: v0.1.5
- **Funcionalidad principal**: Recibe webhooks de Telegram y envía mensajes directamente a TikiWiki trackers

## Pendientes

### Seguridad - Prioridad Alta (implementar primero)

- [x] ~~TELEGRAM_WEBHOOK_SECRET obligatorio con hash_equals()~~ ✅
- [x] ~~checkAuth() antes de procesar acciones mutantes~~ ✅
- [x] ~~Validación CSRF en import.php~~ ✅
- [x] ~~setup_webhook.php protegido (solo CLI/localhost)~~ ✅
- [ ] **Hash de contraseña admin**: Usar password_hash()/password_verify() en vez de comparación en claro
- [ ] **Path Traversal en ZIP**: Validar nombres de archivos al extraer ZIPs subidos por usuarios
- [ ] **Limitar tamaño de descarga de media**: Descargas en chunks en lugar de cargar todo en memoria
- [ ] **display_errors=0 en admin**: Apagar errores visibles en producción

### Seguridad - Prioridad Media

- [ ] **Deduplicación por (chat_id, message_id)**: Actualmente filtra solo por message_id
- [ ] **ALLOWED_CHAT_IDS por defecto**: No aceptar todos los chats, permitir solo los autorizados
- [ ] **No guardar URLs con token**: Descargar media via proxy o usar token limitado
- [ ] **Rate limiting webhook**: Para prevenir abuso del endpoint

### Arquitectura - Mejoras Técnicas

- [x] **Separación de responsabilidades**: TikiWikiClient, TelegramClient, MessageMapper ✅
- [ ] **Integrar MessageMapper completamente**: Unificar transformación de mensajes en MessageMapper
- [ ] **Refactorizar api.php**: Extraer processUpdate y sendToTikiWiki a clientes
- [ ] **Manejo de errores consistente**: Estandarizar retornos (excepciones en vez de null/false mixtos)

### Funcionalidades

- [~] **Creación automática de tracker**: Parcialmente implementado
   - Pendiente: corregir tipos de campo (FG, G, D) y valores por defecto
   - Pendiente: conectar correctamente la UI de "Crear Tracker" en admin.php
- [x] **Importar export de Telegram**: Implementado (pendiente optimizar para exports grandes)
- [ ] **Sistema de etiquetas**: Extraer hashtags de mensajes
- [ ] **Mensajes estructurados con prefijos**: Detectar y parsear mensajes con prefijos especiales que contienen datos estructurados
  - Ejemplo: "📍GPS fabian.ciclista 34.051628,-118.240126,14.3" → extrae "fabian.ciclista" como nombre/usuario y coordenadas al campo ubicación
  - Implementar parser configurable en MessageMapper
  - Permitir definir patrones regex para diferentes tipos de mensajes (GPS, alertas, etc.)

### Estrategia - Pendientes (para después)

- [ ] **Mensajes editados/borrados**: Manejar updates de tipo edited_message y deleted
- [ ] **Importación asíncrona**: Procesar exports grandes por FTP + CLI en vez de HTTP
- [ ] **Múltiples chats**: Crear trackers separados por chat_id o implementar filtros

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