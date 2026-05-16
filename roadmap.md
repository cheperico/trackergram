# trackerGram — Roadmap

## Estado del Proyecto

- **Versión actual**: v0.1.7
- **Estado**: Beta funcional, desarrollo activo
- **Historial de cambios**: [CAMBIOS.md](CAMBIOS.md)

---

## Pendientes

### Funcionalidades

#### Prioridad Alta
- [ ] **Mensajes estructurados con prefijos**: Detectar y parsear mensajes con prefijos especiales que contienen datos estructurados
  - Ejemplo: "GPS fabian.ciclista 34.051628,-118.240126,14.3" → extrae coordenadas al campo ubicación
  - Implementar parser configurable en MessageMapper
  - Permitir definir patrones regex para diferentes tipos de mensajes

#### Prioridad Media
- [ ] **Sistema de etiquetas**: Extraer hashtags de mensajes y almacenarlos como campos separados
- [ ] **Mensajes editados/borrados**: Manejar updates de tipo `edited_message` y deleted
- [ ] **Importación asíncrona**: Procesar exports grandes por FTP + CLI en vez de HTTP request
- [ ] **Múltiples chats**: Crear trackers separados por chat_id o implementar filtros

---

### Arquitectura — Refactorización

#### Prioridad Alta
- [ ] **Inyección de dependencias**: Refactorizar clases estáticas en instanciables con configuración inyectada (TikiWikiClient, TelegramClient, WebhookHandler)
- [ ] **Unificar parsers de mensajes**: WebhookHandler e import.php tienen lógica duplicada. Definir un modelo intermedio único (NormalizedMessage) y dos parsers específicos (TelegramWebhookParser, TelegramExportParser)
- [ ] **Estandarizar manejo de errores**: Usar excepciones de dominio (ConfigException, TelegramException, TikiWikiException, ImportException) en vez de mezclar null/false/die/http_response_code

#### Prioridad Media
- [ ] **Tests unitarios**: Agregar tests para MessageMapper, WebhookHandler, y clientes
- [ ] **PSR-4 autoloading**: Mover clases a directorio `src/` y usar autoloader
- [ ] **Documentación de API interna**: PHPDoc completo en todas las clases y métodos

#### Prioridad Baja
- [ ] **Tipos estrictos**: Agregar `declare(strict_types=1)` en todos los archivos
- [ ] **Anotaciones de tipo para arrays**: PHPDoc detallado para arrays

---

### Service Messages — Cobertura

| Evento | Webhook | Import |
|---|---|---|
| `forum_topic_created` / `topic_created` | ✅ | ✅ |
| `forum_topic_edited` / `topic_edit` | ✅ | ✅ |
| `forum_topic_closed/reopened` | ✅ | ✅ |
| `new_chat_members` / `invite_members` | ✅ | ✅ |
| `left_chat_member` / `left` | ✅ | ✅ |
| `pinned_message` / `pin_message` | ✅ | ✅ |
| `group_chat_created` / `supergroup_chat_created` / `create_group` | ✅ | ✅ |
| `new_chat_title` / `title_edit` | ✅ | ✅ |
| `new_chat_photo` / `delete_chat_photo` | ✅ | ⬜ |
| `remove_members` | ⬜ | ✅ |
| `joined` | ⬜ | ✅ |
| `message_reaction` / `message_reaction_count` | ✅ | ⬜ |

**Pendientes de service messages:**
- [ ] `new_chat_photo` / `delete_chat_photo` en importación
- [ ] `remove_members` en webhook
- [ ] `joined` en webhook

---

### Monitoreo

- [ ] **Métricas de uso**: Cantidad de mensajes procesados, uso de recursos, performance
