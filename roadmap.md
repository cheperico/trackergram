# trackerGram - Roadmap

## Estado del Proyecto

- **Estado**: Activo - En desarrollo
- **Última versión**: v0.1.7
- **Funcionalidad principal**: Recibe webhooks de Telegram y envía mensajes directamente a TikiWiki trackers

## Pendientes

### Seguridad - Prioridad Alta (implementar primero)

- [x] ~~TELEGRAM_WEBHOOK_SECRET obligatorio con hash_equals()~~ ✅
- [x] ~~checkAuth() antes de procesar acciones mutantes~~ ✅
- [x] ~~Validación CSRF en import.php~~ ✅
- [x] ~~setup_webhook.php protegido (solo CLI/localhost)~~ ✅
- [x] ~~**Hash de contraseña admin**: Usar password_hash()/password_verify() en vez de comparación en claro~~ ✅
- [x] ~~**Path Traversal en ZIP**: Validar nombres de archivos al extraer ZIPs subidos por usuarios~~ ✅
- [x] ~~**Limitar tamaño de descarga de media**: Descargas en chunks en lugar de cargar todo en memoria~~ ✅
- [x] ~~**display_errors=0 en admin**: Apagar errores visibles en producción~~ ✅

### Seguridad - Prioridad Media

- [x] ~~**Deduplicación por (chat_id, message_id)**: Actualmente filtra solo por message_id~~ ✅
- [x] ~~**ALLOWED_CHAT_IDS por defecto**: No aceptar todos los chats, permitir solo los autorizados~~ ✅
- [x] ~~**No guardar URLs con token**: Descargar media via proxy o usar token limitado~~ ✅
- [x] ~~**Rate limiting webhook**: Para prevenir abuso del endpoint~~ ✅

### Arquitectura - Mejoras Técnicas

- [x] **Separación de responsabilidades**: TikiWikiClient, TelegramClient, MessageMapper ✅
- [x] ~~**Integrar MessageMapper completamente**: Unificar transformación de mensajes en MessageMapper~~ ✅ (toWikiFields + createTrackerItem)
- [x] **Refactorizar api.php**: Extraer processUpdate y sendToTikiWiki a WebhookHandler ✅
- [x] ~~**Manejo de errores consistente**: Estandarizar retornos (excepciones en vez de null/false mixtos)~~ ✅

### Funcionalidades

- [x] **Creación automática de tracker**: Implementado con tipos de campo correctos (FG, G, D) ✅
- [x] **Importar export de Telegram**: Implementado (pendiente optimizar para exports grandes)

### Service Messages (Eventos del Grupo)
| Evento | Webhook | Import |
|--------|---------|--------|
| `forum_topic_created` / `action: topic_created` | ✅ | ✅ |
| `forum_topic_edited` / `action: topic_edit` | ✅ | ✅ |
| `forum_topic_closed/reopened` | ✅ | ✅ |
| `new_chat_members` / `action: invite_members` | ✅ | ✅ |
| `left_chat_member` / `action: left` | ✅ | ✅ |
| `pinned_message` / `action: pin_message` | ✅ | ✅ |
| `group_chat_created` / `supergroup_chat_created` / `action: create_group` | ✅ | ✅ |
| `new_chat_title` / `action: title_edit` | ✅ | ✅ |
| `new_chat_photo` / `delete_chat_photo` / `action: photo_edit` / `action: photo_delete` | ✅ | ⬜ |
| `action: remove_members` | ⬜ | ✅ |
| `action: joined` | ⬜ | ✅ |
| `message_reaction` / `message_reaction_count` | ✅ | ⬜ |

- [x] **Reacciones a mensajes**: Procesar updates de tipo `message_reaction` y `message_reaction_count` ✅
- [x] **Service messages faltantes en webhook**: `new_chat_title`, `new_chat_photo`, `delete_chat_photo` ✅
- [x] **Creación automática de tracker**: Tipos de campo corregidos (FG, G, etc.), UI conectada ✅
- [x] **Eliminar thin wrappers de api.php**: Funciones puente reemplazadas por llamadas directas a clientes ✅
- [x] **Eliminar código duplicado de import.php**: `uploadFileToTikiWiki` y `getGalleryIdForTracker` reemplazadas por `TikiWikiClient` ✅
- [x] **TELEGRAM_WEBHOOK_SECRET obligatorio en api.php**: Bloquea webhook si falta ✅
- [x] **Límites de seguridad en import ZIP**: Máx. 10000 archivos, 200 MB descomprimido, profundidad 10 ✅
- [x] **change_password funcional en admin.php**: Movido fuera de checkAuth() ✅
- [x] **XSS en admin.php**: innerHTML reemplazado por textContent en import JS ✅
- [x] **Cache de topics con chat_id**: Clave compuesta chatId:threadId, LOCK_EX en writes ✅
- [x] **Cache gallery ID por tracker**: Array keyed por tracker ID ✅
- [x] **Deduplicación atómica**: messageExists retorna int, post-insert verifica duplicados ✅
- [x] **IDs únicos en reacciones**: reaction_{chat}_{msg}_{user}_{date} en vez de -1*date ✅
- [x] **PHP 8.0+ en docs**: Actualizado README, INSTALL, TECHNICAL ✅
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

- [x] ~~**Manejo inconsistente de errores**: api.php tiene funciones que retornan null, otras retornan false~~ ✅
- [x] ~~**Código duplicado**: Detección de protocolo y construcción de URL del webhook duplicada en admin.php~~ ✅
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