# 001 — Configuración inversa vía Telegram

> **Fecha**: 10/06/2026
> **Estado**: Exploración / Diseño preliminar
> **Sesión**: Orquestador principal con usuario (cheperico)

---

## Problema

Hoy trackerGram se configura exclusivamente desde dos lugares:

1. **Archivo `.env`** — edición manual en el servidor
2. **`admin.php`** — panel web con login

Ambos requieren acceso al servidor o al navegador. La idea es poder **configurar trackerGram desde Telegram**, mandándole comandos al bot.

**Caso de uso inicial**: La persona agrega el bot a un grupo, le pasa la URL de TikiWiki, un token de API, etc., y el bot se configura solo.

---

## Hallazgo clave durante el diseño

No todo usuario que configure trackerGram desde Telegram va a tener un token de TikiWiki con permisos de admin. Hoy el código **asume permisos de admin** en varias operaciones:

| Operación | Endpoint | ¿Requiere admin? |
|-----------|----------|-------------------|
| Crear tracker | `POST /api/trackers` | ✅ Sí |
| Crear galería | `POST /api/galleries` | ✅ Sí |
| Actualizar campo FG | `POST /api/trackers/{id}/fields/{fid}` | ✅ Sí |
| Leer fields | `GET /api/trackers/{id}/fields` | ❌ No |
| Crear item | `POST /api/trackers/{id}/items` | ❌ No |
| Listar items | `GET /api/trackers/{id}/items` | ❌ No |
| Subir archivo | `POST /api/galleries/upload` | ❌ No (depende de la galería) |

**Conclusión**: El bot debe detectar automáticamente los permisos del token y bifurcar el flujo de setup. Un token limitado no puede crear trackers ni galerías, pero sí usar existentes.

---

## Arquitectura propuesta

### Nuevos archivos

| Archivo | Rol |
|---------|-----|
| `design/*.md` | Documentos de diseño incremental (este directorio) |
| `ConfigManager.php` | Persistencia de configuración en `setup.json` (formato JSON, soporta multi-chat) |
| `BotCommandHandler.php` | Procesa comandos `/start`, `/setup`, `/status`, `/help`, etc. |
| `PermissionChecker.php` | Detecta el tier de permisos del token de TikiWiki |
| `setup.json` | Archivo de configuración persistente (alternativa/complemento a `.env`) |

### Archivos a modificar

| Archivo | Cambio |
|---------|--------|
| `TelegramClient.php` | Agregar `sendMessage()`, `sendChatAction()` |
| `WebhookHandler.php` | Detectar comandos `/xxx` y delegar a BotCommandHandler |
| `TikiWikiClient.php` | Manejo explícito de 403, `listTrackers()`, `validateTrackerStructure()`, auto-reparación condicional |
| `bootstrap.php` | Instanciar ConfigManager, BotCommandHandler, PermissionChecker |
| `config.php` | Cargar `setup.json` como complemento a `.env` |
| `AGENTS.md` | Documentar existencia de `design/` |

### Formato de `setup.json`

```json
{
  "version": 1,
  "chats": {
    "-100123456789": {
      "chat_title": "Mi Grupo",
      "tikiwiki_api_url": "https://wiki.ejemplo.org/api/",
      "tikiwiki_token": "abc123...",
      "tikiwiki_tracker_id": 12,
      "media_gallery_id": 36,
      "permission_tier": "user",
      "webhook_secret": "auto-generado",
      "configured_at": "2026-06-10T12:00:00Z",
      "configured_by": 12345
    }
  },
  "admin_password_hash": "$2y$10$...",
  "debug_mode": false
}
```

### PermissionTier

```php
enum PermissionTier {
    case Admin;  // Puede crear trackers, galerías, auto-reparar
    case User;   // Solo leer/escribir items + subir archivos a galerías existentes
}
```

Detección automática: probar `POST /api/trackers` con payload mínimo → si da 403 es `User`, si da 200/201 es `Admin`.

---

## Comandos del bot

| Comando | Descripción | Solo admin del grupo |
|---------|-------------|-------------------|
| `/start` | Mensaje de bienvenida | No |
| `/setup` | Inicia wizard de configuración | Sí |
| `/status` | Muestra estado actual | No |
| `/tracker <id>` | Cambia el tracker activo | Sí |
| `/reconfigure` | Reinicia configuración | Sí |
| `/help` | Ayuda | No |
| `/cancel` | Cancela wizard activo | Sí |

---

## Decisiones abiertas

- **Webhook vs polling**: Con webhook se necesita una config mínima inicial (al menos TELEGRAM_BOT_TOKEN + TELEGRAM_WEBHOOK_SECRET). Con polling se podría llegar a cero config inicial, pero es más complejo.
- **Setup en grupo vs privado**: En el grupo es más directo ("configurar este grupo"). En privado permitiría configurar múltiples grupos desde un solo chat.
- **Multi-grupo desde el vamos**: El diseño de `setup.json` con `"chats": {...}` lo soporta naturalmente. Cada grupo puede tener su propio tracker y wiki.

---

## Pendiente para próxima sesión

- Definir exactamente cómo se detectan los permisos en PermissionChecker
- Decidir si arrancamos con webhook o polling
- Definir el flujo exacto del wizard `/setup` en ambos tiers (admin y user)
- Implementar Fase 1: `sendMessage()` en TelegramClient + ConfigManager
