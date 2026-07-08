# 009 — Permisos por Tracker en TikiWiki: Restringir trackerGram a Trackers Específicos

> **Fecha**: 08/07/2026
> **Estado**: Diseño completo — pendiente de implementación
> **Sesión**: Orquestador principal con usuario (cheperico)
> **Tags**: permisos, seguridad, tikiwiki, api, whitelist, trackers, per-object
> **Decisión**: ⏳ Pendiente — esperar a revisar TikiWiki 30 para ver si el TODO del core ya fue resuelto upstream

---

## Índice

1. [Propósito](#1-propósito)
2. [Problema](#2-problema)
3. [Arquitectura de Permisos en TikiWiki](#3-arquitectura-de-permisos-en-tikiwiki)
4. [El Blocker: `action_list_items`](#4-el-blocker-action_list_items)
5. [Solución Propuesta: Parche al Core](#5-solución-propuesta-parche-al-core)
6. [Configuración desde la UI de TikiWiki](#6-configuración-desde-la-ui-de-tikiwiki)
7. [Impacto en trackerGram](#7-impacto-en-trackergram)
8. [Alternativas Consideradas](#8-alternativas-consideradas)
9. [Pregunta Abierta: TikiWiki 30](#9-pregunta-abierta-tikiwiki-30)

---

## 1. Propósito

Actualmente el token API de TikiWiki que usa trackerGram tiene `admin_trackers` global, lo que le da acceso a **todos** los trackers del sitio. Este documento describe cómo restringir trackerGram para que solo pueda leer/escribir en un conjunto específico de trackers (whitelist), usando el sistema de permisos por objeto (per-object permissions) de TikiWiki.

---

## 2. Problema

trackerGram necesita estos permisos de TikiWiki para funcionar:

| Permiso | ¿Para qué? |
|---------|-----------|
| `view_trackers` | Ver estructura del tracker (campos) |
| `create_tracker_items` | Crear items desde webhook/import |
| `modify_tracker_items` | Actualizar items (mensajes editados) |
| `attach_trackers` | Adjuntar archivos a items |

El problema es que **todos estos permisos se asignan globalmente** al usuario/grupo del token API. No hay forma (hoy) de decir "solo tracker #5 y #8, nada más".

---

## 3. Arquitectura de Permisos en TikiWiki

### Permisos globales vs per-object

TikiWiki soporta **permisos por objeto (per-object permissions)** para trackers. Se pueden asignar permisos específicos a un grupo sobre un tracker individual. La tabla es `users_objectpermissions`:

| groupo | perm | objectType | objectId |
|--------|------|------------|----------|
| TrackergramClients | `tiki_p_view_trackers` | tracker | `md5("tracker5")` |
| TrackergramClients | `tiki_p_create_tracker_items` | tracker | `md5("tracker5")` |

Cuando no hay registros para un tracker específico, el sistema cae al permiso global.

### Lo que funciona con per-object

| Operación | Endpoint API | Permiso que checkea |
|-----------|-------------|---------------------|
| Ver campos del tracker | `GET /trackers/{id}/fields` | `view_trackers` per-object ✅ |
| Crear items | `POST /trackers/{id}/items` | `create_tracker_items` per-object ✅ |
| Actualizar items | `POST /trackers/{id}/items/{itemId}` | `canModify()` per-object ✅ |
| Subir archivos | `POST /galleries/upload` | `upload_files` per-gallery ✅ |

### Lo que NO funciona con per-object

| Operación | Endpoint | Problema |
|-----------|----------|----------|
| Listar items (deduplicación) | `GET /trackers/{id}` | Requiere `admin_trackers` **global** ❌ |

---

## 4. El Blocker: `action_list_items`

El endpoint que trackerGram usa para deduplicación (`messageExists()` → `GET /api/trackers/{id}`) tiene este código en **TikiWiki** (`lib/core/Services/Tracker/Controller.php:695-702`):

```php
public function action_list_items($input)
{
    // TODO : Eventually, this method should filter according to the actual permissions, but because
    //        it is only to be used for tracker sync at this time, admin privileges are just fine.

    if (! Perms::get()->admin_trackers) {      // ← GLOBAL, ignora per-object
        throw new Services_Exception_Denied(tr('Reserved for tracker administrators'));
    }
    // ...
```

El TODO del equipo de TikiWiki dice explícitamente que **debería filtrar por permisos reales**, pero nunca lo implementaron.

### ¿Qué pasa si damos `admin_trackers` global?

Si al grupo `Trackergram Clients` le asignamos `admin_trackers` global, el token API puede:
- **Crear/leer items en TODOS los trackers** (no solo los whitelist)
- **Modificar campos de trackers**
- **Crear trackers nuevos**
- **Ver configuraciones internas**

Esto anula completamente el propósito de restringir por tracker.

---

## 5. Solución Propuesta: Parche al Core

El cambio para que `action_list_items` respete permisos per-object es **una línea**:

```php
// ANTES (global):
if (! Perms::get()->admin_trackers) {

// DESPUÉS (per-tracker):
if (! Perms::get('tracker', $trackerId)->view_trackers) {
```

Esto hace que:
- Si el grupo tiene `view_trackers` sobre el tracker #5 → puede listar items de #5
- Si NO tiene permisos sobre #8 → `GET /api/trackers/8` responde 403
- No necesita `admin_trackers` global

El `$trackerId` ya está disponible en esa línea (se usa en la línea 704: `$trackerId = $input->trackerId->int()`), solo hay que mover la validación después de obtener el ID.

### Contrapartida

Al ser un parche al core de TikiWiki, hay que mantenerlo en cada upgrade. Es una línea con comentario, fácil de re-aplicar con un script de deploy o un parche git.

---

## 6. Configuración desde la UI de TikiWiki

### Paso 1: Crear grupo y usuario

1. **Admin → Groups** → Crear grupo `TrackergramClients`
2. **Admin → Users** → Crear usuario `trackergram_bot`, asignarlo al grupo
3. **NO** asignar permisos de tracker globalmente al grupo

### Paso 2: Asignar permisos por tracker

Por cada tracker que trackerGram deba usar:

1. Ir a **Admin → Trackers → [editar tracker]**
2. Click en botón **"Permissions"**
3. Asignar al grupo `TrackergramClients`:
   - ☑ `tiki_p_view_trackers`
   - ☑ `tiki_p_create_tracker_items`
   - ☑ `tiki_p_modify_tracker_items`
   - ☑ `tiki_p_attach_trackers`
   - ☑ `tiki_p_tracker_view_attachments`

### Paso 3: Generar API Token

1. **Admin → API Tokens** → Crear token para `trackergram_bot`
2. Copiar token a trackerGram (configurarlo en admin → editar conexión)

### Paso 4: Aplicar parche

Modificar `lib/core/Services/Tracker/Controller.php` línea 700 como se indica en §5.

---

## 7. Impacto en trackerGram

### Código: cero cambios

trackerGram ya usa la API REST de TikiWiki para **todas** las operaciones. Si la API respeta per-object permissions, trackerGram se adapta automáticamente.

### Flujo afectado: `messageExists()`

| Hoy | Con el parche |
|-----|--------------|
| Token con `admin_trackers` global → `GET /api/trackers/{id}` funciona para cualquier tracker | Token sin admin global → `GET /api/trackers/{id}` funciona solo para trackers con `view_trackers` per-object |
| Si alguien configura un tracker no autorizado, el item se crea igual | Si alguien configura un tracker no autorizado, 403 y log de error |

### Comportamiento en error

Si el token no tiene permisos sobre un tracker configurado en trackerGram:

```
[Webhook] ERROR: Connection "mi-grupo" - TikiWiki API error: 403 Forbidden
→ El mensaje se pierde para ese tracker
→ Se loggea con level ERROR (siempre visible en debug.log)
→ Las demás conexiones no se ven afectadas (fan-out con try-catch individual)
```

Esto es aceptable: si el admin configura mal los permisos, lo descubre rápido porque los mensajes no aparecen.

---

## 8. Alternativas Consideradas

### Alternativa A: Cache local de messageIds (sin parche a TikiWiki)

Reemplazar `messageExists()` con un archivo JSON local que mapee `(chatId, messageId) → itemId`, como ya se hace con `topic_names.json` y `reply_cache.json`.

| Pro | Contra |
|-----|--------|
| Cero cambios en TikiWiki | Inconsistencia temporal entre cache y TikiWiki (si alguien borra items manualmente) |
| Elimina una llamada API por mensaje (mejor performance) | Complejidad extra en trackerGram |
| Token sin `admin_trackers` global | Hay que implementar GC, límite de entradas, etc. |

### Alternativa B: `admin_trackers` global + confiar en la UI

Simplemente asignar `admin_trackers` al grupo y confiar en que el admin de TikiWiki no va a crear trackers sensibles.

| Pro | Contra |
|-----|--------|
| Cero cambios técnicos | Cero seguridad — el token puede hacer cualquier cosa |
| Funciona hoy | No escala si hay múltiples admins o trackers sensibles |

### Alternativa C: Parche al core

La documentada en §5.

| Pro | Contra |
|-----|--------|
| Solución correcta según la intención del TODO | Hay que mantener el parche en upgrades |
| El token queda efectivamente restringido a trackers whitelist | Mínima fricción en deploy |

### Decisión

Se eligió la **Alternativa C (parche al core)** porque:
- Es la solución que el propio TikiWiki planeaba implementar (el TODO lo confirma)
- Es una sola línea de cambio, fácil de documentar y mantener
- No agrega complejidad a trackerGram
- Si en el futuro TikiWiki resuelve el TODO upstream, el parche se elimina

---

## 9. Pregunta Abierta: TikiWiki 30

**No implementar hasta revisar TikiWiki 30.**

Antes de aplicar el parche, hay que verificar si el TODO en `Controller.php` ya fue resuelto en la versión 30 de TikiWiki. Si es así:

- **No hace falta parchear** — la API ya respeta permisos per-object
- Solo sería necesario actualizar trackerGram a apuntar a la versión 30 de TikiWiki
- La configuración de permisos desde la UI (Paso 1-3 en §6) sigue siendo necesaria

Si NO fue resuelto en TikiWiki 30:
- Aplicar parche según §5
- Documentar el parche en el deploy para mantenerlo en upgrades futuros

### Cómo verificar

Cuando se tenga acceso al source de TikiWiki 30:

```bash
grep -n "admin_trackers" lib/core/Services/Tracker/Controller.php
```

- Si la línea 700 cambió de `Perms::get()->admin_trackers` a `Perms::get('tracker', $trackerId)->...` → ✅ Resuelto upstream
- Si sigue igual → Aplicar parche

---

## Referencias

- Código TikiWiki 27.5: `lib/core/Services/Tracker/Controller.php:695-702`
- Tabla de permisos: `users_objectpermissions` (per-object)
- Documentación TikiWiki: https://doc.tiki.org/Permissions
- Informe de seguridad trackerGram: `design/999-a-tener-en-cuenta.md` (sección 5: mínimos privilegios)
