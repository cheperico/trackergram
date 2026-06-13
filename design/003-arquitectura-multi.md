# 003 — Arquitectura Multi: Vinculaciones, Routing y Escalabilidad

> **Fecha**: 11/06/2026
> **Estado**: Diseño preliminar — discusión abierta
> **Sesión**: Orquestador principal con usuario (cheperico)
> **Tags**: multi, vinculacion, binding, arquitectura, setup

---

## Índice

1. [Problema](#1-problema)
2. [La Vinculación como Unidad Mínima](#2-la-vinculación-como-unidad-mínima)
3. [Estructura de una Vinculación](#3-estructura-de-una-vinculación)
4. [Opciones de Procesamiento (por vinc. — feature menor)](#4-opciones-de-procesamiento-por-vinc--feature-menor)
5. [Mapeo de Campos (field mapping)](#5-mapeo-de-campos-field-mapping)
6. [Temas Abiertos (A-H)](#6-temas-abiertos-a-h)
7. [Glosario](#7-glosario)

---

## 1. Problema

Hoy trackerGram se configura con variables globales en `.env`:

```ini
TELEGRAM_BOT_TOKEN=un_solo_bot
TIKIWIKI_API_URL=https://wiki.chela.org.ar/api/
TIKIWIKI_TOKEN=un_solo_token
TIKIWIKI_TRACKER_ID=12
```

Esto implica que **una instalación de trackerGram = un bot → una wiki → un tracker**.

El usuario necesita gestionar múltiples grupos de Telegram apuntando a distintos trackers (incluso distintas wikis) desde una sola instalación. Ejemplo concreto:

- Grupo "Qué Pasa CheLA?" → Tracker 22 en wiki.chela.org.ar
- Grupo "Proyecto Flora" → Tracker 35 en wiki.chela.org.ar
- Grupo "Monitoreo Agua" → Tracker 12 en wiki.test.org.ar

Cada una de esas líneas es una **vinculación** entre un punto de entrada (Telegram) y un punto de destino (TikiWiki).

---

## 2. La Vinculación como Unidad Mínima

La vinculación (o **binding**) es el átomo de configuración de trackerGram. Representa una conexión activa entre un grupo de Telegram y un tracker en TikiWiki.

```
┌───────────────────────────────────────────┐
│              VINCULACIÓN                   │
│                                           │
│  Identidad:                               │
│    - ID único (slug: "qpch_prod")         │
│    - Nombre legible ("QPCH Producción")   │
│    - Estado (enabled / disabled)          │
│                                           │
│  Telegram:                                │
│    - bot_token                            │
│    - chat_id                              │
│                                           │
│  TikiWiki:                                │
│    - api_url                              │
│    - api_token (implica un usuario)       │
│    - permission_tier (admin | user)       │
│                                           │
│  Tracker:                                 │
│    - tracker_id                           │
│    - media_gallery_id (auto-resuelto)     │
│                                           │
│  (Opcional futuro):                       │
│    - field_mapping                        │
│    - options (procesamiento, filtros)     │
└───────────────────────────────────────────┘
```

### 2.1 Decisiones de diseño (vinculación)

| Decisión | Opción tomada | Motivo |
|----------|--------------|--------|
| **Unidad mínima** | Binding | Es la relación más pequeña que tiene sentido configurar |
| **Identidad** | ID slug + nombre legible | El slug permite referenciar desde API/config; el nombre es para humanos |
| **Estado** | enabled/disabled | Permite pausar un binding sin borrar la configuración |
| **Bot token por binding** | Sí | Porque podría haber múltiples bots distintos |
| **Chat ID por binding** | Sí | Un mismo bot puede estar en varios grupos ≠ cada grupo es un binding distinto |
| **Webhook secret por binding** | No en la estructura inicial | Se resuelve por binding al configurar webhook |
| **Permission tier** | admin / user | Detectado automáticamente probando `POST /api/trackers` (ver design/001) |
| **Gallery ID** | No se persiste obligatoriamente | Se resuelve automáticamente desde el tracker via API `/fields` y se cachea |

---

## 3. Estructura de una Vinculación

### 3.1 Formato completo propuesto

```json
{
  "version": 2,
  "bindings": {
    "qpch_prod": {
      "name": "QPCH Producción",
      "description": "Grupo Qué Pasa CheLA → Tracker oficial 22",
      "enabled": true,
      "created_at": "2026-06-11T10:00:00Z",
      "updated_at": "2026-06-11T10:00:00Z",

      "telegram": {
        "bot_token": "123456:ABC-DEF1234ghIklmNOPqrSTUvwxYz",
        "chat_id": -100123456789,
        "webhook_secret": "un_secreto_por_binding"
      },

      "tikiwiki": {
        "api_url": "https://wiki.chela.org.ar/api/",
        "api_token": "token_asociado_a_usuario_wiki",
        "permission_tier": "admin"
      },

      "tracker": {
        "tracker_id": 22
      }
    }
  }
}
```

### 3.2 Archivo de persistencia

| Aspecto | Propuesta |
|---------|-----------|
| **Archivo** | `setup.json` en la raíz de trackerGram |
| **Formato** | JSON (humano legible, editable a mano) |
| **Permisos** | `0600` (igual que `.env`) |
| **Coexistencia con `.env`** | `.env` queda para config global: `ADMIN_USERNAME/PASSWORD`, `DEBUG_MODE`, `ASYNC_PROCESSING` |
| **Migración** | Si existe `.env` con valores de bot/wiki/tracker y NO existe `setup.json`, se genera automáticamente una vinculación "default" al leer el admin |

### 3.3 Consideraciones de seguridad

- Los `bot_token` y `api_token` viajan en texto plano en `setup.json`
- El archivo debe tener permisos restrictivos (no accesible vía web)
- Si el atacante tiene acceso al filesystem, ya puede leer `.env` hoy → no empeora la situación
- Posible mejora futura: cifrado simétrico de tokens con clave maestro en `.env`

---

## 4. Opciones de Procesamiento (por vinc. — feature menor)

Funcionalidad postergada, no bloqueante para multi. Queda registrada para cuando se implemente.

```json
"options": {
    "include_reactions": true,
    "include_service_messages": true,
    "include_edits": false,
    "include_media": true,
    "filter_topics": [],
    "hashtags_as_tags": false,
    "rate_limit_messages_per_min": 30
}
```

| Opción | Default | Descripción |
|--------|---------|-------------|
| `include_reactions` | true | Procesar `message_reaction` y `message_reaction_count` |
| `include_service_messages` | true | Service messages (miembros, pins, topics) |
| `include_edits` | false | Mensajes editados → nuevo item en tracker |
| `include_media` | true | Descargar y subir archivos multimedia |
| `filter_topics` | [] | IDs de topics a incluir (vacío = todos) |
| `hashtags_as_tags` | false | Extraer #tags a campo separado |
| `rate_limit_messages_per_min` | 30 | Límite de mensajes/minuto desde este grupo |

---

## 5. Mapeo de Campos (field mapping)

### 5.1 El problema

Hoy `MessageMapper::toWikiFields()` escribe SIEMPRE a los mismos `permNames`:

```
fields[telegrammessageTelegramMessageId]
fields[telegrammessageText]
fields[telegrammessageLocation]
...
```

Si el tracker destino tiene OTRO schema (otro prefijo, estructura distinta, campos renombrados), el mapeo falla.

### 5.2 Casos de uso

#### A) Default (tracker Telegram Messages)
Mapeo implícito como hoy. No necesita configuración porque el schema es el que trackerGram mismo crea.

#### B) Tracker existente con otros permNames
Un tracker creado manualmente en TikiWiki con campos distintos. Ej:
```
messageId  (en vez de telegrammessageTelegramMessageId)
contenido  (en vez de telegrammessageText)
ubicacion  (en vez de telegrammessageLocation)
```

#### C) Structured Data / MiniApp (design/002)
Los mensajes `!!!planta ph=6.2 especie=Ceibo` necesitan mapear cada clave a un campo distinto del tracker:
```
ph       → fields[observacionPlantaPH]
especie  → fields[observacionPlantaEspecie]
altura   → fields[observacionPlantaAltura]
```

### 5.3 Enfoque por capas (sugerido)

| Capa | Nombre | Cuándo | ¿Bloqueante para multi? |
|------|--------|--------|:-----------------------:|
| 1 | **Default** | Mapeo implícito `telegrammessage*` | No, ya existe |
| 2 | **Bind mapping** | Por vinculación: tabla de correspondencia entre campos de Telegram y permNames del tracker | 📌 **A evaluar** |
| 3 | **Structured** | Parser `!!!tipo clave=valor` + mapeo semántico | No (MiniApp) |

### 5.4 Formato tentativo para Capa 2

```json
"field_mapping": {
    "messageId":    "fields[telegrammessageTelegramMessageId]",
    "chatId":       "fields[telegrammessageChatId]",
    "chatTitle":    "fields[telegrammessageChatTitle]",
    "text":         "fields[contenido]",
    "location":     "fields[ubicacion]",
    "media":        "fields[telegrammessageMedia]",
    "mediaUrl":     "fields[telegrammessageMediaUrl]",
    "date":         "fields[fecha]",
    "userId":       "fields[autor_id]",
    "username":     "fields[autor_username]",
    "displayName":  "fields[autor_nombre]",
    "messageType":  "fields[tipo_mensaje]",
    "reactions":    "fields[reacciones]"
}
```

> ⚠️ **Pendiente de estudio**: Analizar casos reales de trackers existentes para entender la variedad de schemas que necesitan mapeo. Esto determinará si el field mapping es necesario para multi o puede postergarse.

---

## 6. Temas Abiertos (A-H)

### A. Multi-bot vs single-bot

**Pregunta**: ¿Una instalación de trackerGram puede manejar múltiples bots de Telegram, o cada bot requiere su propia instalación?

**A favor de multi-bot**:
- Un solo despliegue maneja todos los bots
- Panel de admin unificado

**En contra**:
- Complejidad: el webhook de Telegram no incluye `bot_token` en el payload
- Los secret tokens de webhook son por URL, no por bot
- Si un bot es comprometido, afecta a todos

**Posibles soluciones**:
1. **URL diferenciada**: Cada bot tiene su propia URL de webhook (`/api.php?bot=bot_a`, `/api.php?bot=bot_b`). api.php usa el parámetro para identificar el bot.
2. **Por chat_id**: El chat_id es único dentro de cada bot. Si vinculamos binding por (bot_token, chat_id), podríamos tener bindings de distintos bots. Pero api.php no sabe qué bot recibió el mensaje.
3. **X-Telegram-Bot-Api-Secret-Token**: Cada webhook se configura con un secret distinto. api.php puede usar ese header para identificar el bot.

**Decisión**: ⬜ Pendiente

### B. Identificación del webhook

**Pregunta**: Cuando api.php recibe un POST, ¿cómo sabe a qué binding pertenece?

**Opciones**:

| Opción | Mecanismo | Pros | Contras |
|--------|-----------|------|---------|
| B1 | `chat_id` → lookup en bindings | Simple, no requiere cambios en webhook | No funciona si dos bindings tienen mismo chat_id (solo posible con distintos bots, ver A) |
| B2 | Secret token header → lookup | Más robusto | Requiere que cada binding tenga webhook_secret configurado |
| B3 | URL con parámetro | Explícito | Requiere URLs distintas por bot |

**Decisión**: ⬜ Pendiente (depende de A)

### C. Migración .env → setup.json

**Pregunta**: ¿Cómo conviven `.env` y `setup.json`?

**Opción C1: setup.json reemplaza a .env para bindings**
- `.env` se queda solo con `ADMIN_*`, `DEBUG_MODE`, `ASYNC_PROCESSING`
- `setup.json` contiene los bindings
- Si existe `.env` con valores de bot/wiki/tracker y no existe `setup.json`, se migra automáticamente generando un binding "default"
- El admin muestra una notación: "Configuración migrada de .env a setup.json"

**Opción C2: Coexistencia total**
- Si existe `.env` con `TELEGRAM_BOT_TOKEN`, se usa para un binding implícito "legacy"
- `setup.json` agrega bindings adicionales
- Complejidad: ¿qué pasa si ambos definen el mismo chat_id?

**Decisión**: ⬜ Pendiente (propuesta: C1)

### D. Admin UI — CRUD de vinculaciones

**Pregunta**: ¿Cómo se gestionan los bindings desde el panel web?

**Requerimientos mínimos**:
- [ ] Listar vinculaciones (tabla con nombre, estado, chat, tracker)
- [ ] Crear nueva (formulario completo con Telegram + TikiWiki + Tracker)
- [ ] Editar existente
- [ ] Habilitar/deshabilitar (toggle rápido)
- [ ] Eliminar (con confirmación)
- [ ] Health check por binding ("Probar conexión")

**Dudas**:
- ¿Cada binding muestra su propia URL de webhook?
- ¿El botón "Actualizar webhook" es por binding o global?
- ¿La importación de ZIP permite seleccionar el binding destino?

**Decisión**: ⬜ Pendiente

### E. WebhookHandler multi

**Pregunta**: ¿Un solo WebhookHandler con lookup dinámico o una instancia por binding?

**Opción E1: Handler único con lookup**
```php
class WebhookHandler {
    public function processUpdate(array $update, string $bindingId): void {
        $binding = $this->configManager->getBinding($bindingId);
        // Usar TikiWikiClient configurado para este binding
    }
}
```

**Opción E2: Factory de handlers**
```php
class WebhookHandlerFactory {
    public function createForBinding(Binding $binding): WebhookHandler {
        $tikiClient = new TikiWikiClient($binding->tikiwiki->api_url, ...);
        return new WebhookHandler($tikiClient, ...);
    }
}
```

**Decisión**: ⬜ Pendiente (propuesta: E1 por simplicidad)

### F. Worker async + bindings

**Pregunta**: ¿Cómo sabe el worker de qué binding es cada evento buffereado?

Hoy `worker.php` lee archivos `event_timestamp_hash.json` del buffer y los pasa a `$webhookHandler->processUpdate()`.

Solución: incluir el `binding_id` en el nombre o contenido del archivo buffer.

```json
// tmp/buffer/event_1712345678_a1b2c3d4.json
{
    "binding_id": "qpch_prod",
    "update": { ... payload completo de Telegram ... }
}
```

**Decisión**: ⬜ Pendiente (pero parece straightforward)

### G. Field mapping — ¿necesario desde ahora?

**Pregunta**: Para que el multi funcione, ¿necesitamos field mapping desde el vamos?

**Depende del caso**:
- Si todos los trackers destino usan el mismo schema (`telegrammessage*`), no hace falta. Solo cambia el `tracker_id`.
- Si algún tracker destino tiene campos con otros `permNames`, necesitamos mapeo.

**Propuesta**: Empezar multi SIN field mapping (asume schema default). Agregar field mapping cuando surja la necesidad real con un tracker con schema distinto.

**Decisión**: ⬜ Pendiente (propuesta: postergar)

### H. Tags / metadata en bindings

**Pregunta**: ¿Sirve etiquetar bindings?

```json
"tags": ["produccion", "chela", "general"]
```

Para:
- Filtrar en admin
- Agrupar por proyecto
- Búsqueda rápida

**Decisión**: ⬜ Pendiente (feature menor, postergar)

---

---

---

## 7. Decisiones de Diseño (Confirmadas)

> Tomadas el 13/06/2026 en sesión con usuario.

### 7.1 Panel de administración

| Decisión | Valor |
|----------|-------|
| **Estructura** | Dos secciones/pestañas: **Webhook** e **Importar** |
| **Webhook** | CRUD de conexiones + configurar webhook automáticamente |
| **Importar** | Pendiente de diseñar (postergado) |

### 7.2 Multi-bot

| Decisión | Valor |
|----------|-------|
| **Soporte desde el vamos** | ✅ Sí |
| **Identificación de conexión** | Por `chat_id` + `webhook_secret` (header `X-Telegram-Bot-Api-Secret-Token`) |
| **Configuración de webhook** | Desde el panel de admin, llamando `setWebhook()` por cada bot |

**Flujo multi-bot en api.php:**
```
1. Llega POST a api.php
2. Leer X-Telegram-Bot-Api-Secret-Token del header
3. Buscar conexión por (chat_id, webhook_secret)
   - Si hay una sola coincidencia → esa es
   - Si el secret_token coincide → desambigua entre bots distintos
   - Si solo hay chat_id → única conexión posible
4. Usar la configuración de esa conexión (tiki_url, tiki_token, tracker_id)
```

### 7.3 Representación visual del admin (Webhook)

Paneo acordado:

```
┌─────────────────────────────────────────┐
│  trackerGram Admin                      │
│                                         │
│  [🌐 Webhook]  [📥 Importar]           │
│                                         │
│  ─────────────────────────────────────  │
│                                         │
│  + Agregar conexión                     │
│                                         │
│  ┌────────────────────────────────────┐ │
│  │ QPCH Producción            ● Activo│ │
│  │ Bot: @trackergram_bot             │ │
│  │ Grupo: Qué Pasa CheLA?            │ │
│  │ Tracker: #22 · wiki.chela.org.ar  │ │
│  │ [⚙️ Editar] [🔄 Webhook] [🧪 Test] │ │
│  └────────────────────────────────────┘ │
│                                         │
│  ┌────────────────────────────────────┐ │
│  │ Flora Test               ○ Inactivo│ │
│  │ Bot: @bot_test                     │ │
│  │ Grupo: Proyecto Flora              │ │
│  │ Tracker: #35 · wiki.test.org       │ │
│  │ [⚙️ Editar] [🔄 Webhook] [🧪 Test] │ │
│  └────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```

### 7.5 Relación Importación ↔ Webhook

La importación no es independiente — es un **backfill** sobre una conexión existente.

**Flujo lógico:**
```
1. Creás conexión (bot + chat + wiki + tracker)
   → Webhook empieza a capturar mensajes NUEVOS
2. Importás ZIP de Telegram a la MISMA conexión
   → Puebla el tracker con mensajes ANTERIORES a la llegada del bot
3. Resultado: tracker con historial COMPLETO
```

**En la UI:** La importación no es una pestaña separada, sino una **acción sobre una conexión**:
```
🌐 Webhook
  └── QPCH Prod
        ├── ⚙️ Editar
        ├── 📥 Importar ZIP (backfill)
        ├── 🔄 Configurar webhook
        └── 🧪 Test
```

El formulario de importar se autocompleta con los datos Tiki + tracker de la conexión. Solo pide el ZIP.

### 7.4 Estructura de setup.json (final)

```json
{
  "version": 2,
  "connections": {
    "qpch-prod": {
      "name": "QPCH Producción",
      "enabled": true,
      "bot_token": "123456:ABC-DEF...",
      "webhook_secret": "secreto_generado",
      "chat_id": -100123456789,
      "tiki_api_url": "https://wiki.chela.org.ar/api/",
      "tiki_api_token": "token123...",
      "tracker_id": 22
    }
  }
}
```

---

## 7b. Decisión Final: Estructura de una Conexión

> Acordado con usuario el 11/06/2026. El término "conexión" reemplaza a "vinculación/binding" como lenguaje del dominio.

### Campos definitivos

| # | Campo | Tipo | Obligatorio | Descripción |
|---|-------|------|:-----------:|-------------|
| 1 | `name` | string | ✅ | Nombre visible en el panel (ej: "QPCH Prod") |
| 2 | `bot_token` | string | ✅ | API Key del bot de Telegram |
| 3 | `webhook_secret` | string | ✅ | Secret token para verificar el webhook |
| 4 | `chat_id` | integer | ✅ | ID del grupo de Telegram |
| 5 | `tiki_api_url` | string | ✅ | Dirección de la API de TikiWiki |
| 6 | `tiki_api_token` | string | ✅ | Token de acceso a la API de TikiWiki |
| 7 | `tracker_id` | integer | ✅ | ID del tracker destino |
| 8 | `enabled` | boolean | ✅ | Activo (true) / Inactivo (false) |

### Combinaciones posibles desde la UI

| Conexión | Bot Token | Chat ID | Tiki URL | Token Tiki | Tracker |
|----------|-----------|---------|----------|------------|---------|
| QPCH Prod | A | -100123 | wiki.chela.org.ar | token_a | 22 |
| QPCH Stats | **A** | **-100123** | **wiki.chela.org.ar** | **token_a** | **25** |
| Flora Test | A | -100456 | wiki.test.org.ar | token_b | 5 |
| Flora Prod | **B** | **-100789** | **wiki.chela.org.ar** | **token_a** | **35** |

> En negrita lo que cambia respecto a la fila anterior. Esto muestra que con 6 parámetros variables se cubren todas las combinaciones multi.

---

## 8. Pendiente: Obtención del Chat ID

### El problema

Para crear una conexión necesitás el `chat_id` del grupo de Telegram. Pero para obtener el `chat_id` necesitás agregar el bot al grupo primero. Si el bot no tiene una conexión activa con ese chat_id, el handler `my_chat_member` lo hace salir automáticamente.

Es un problema **huevo o la gallina**.

### Posibles soluciones

| Opción | Descripción | Pros | Contras |
|--------|-------------|------|---------|
| **Grace period** | El bot espera N minutos antes de salir de un chat no configurado. Tiempo suficiente para que el admin cree la conexión. | Simple, no requiere UI compleja | Ventana de tiempo donde el bot está en un chat sin procesar |
| **Modo "escucha"** | Botón en admin: "Escuchar nuevos grupos por 10 min". Durante ese período, el bot NO sale de ningún grupo nuevo, y registra los chat_id en una tabla temporal. | Control explícito | Requiere estado global |
| **Comando /start en privado** | El usuario chatea con el bot en privado, agrega el bot al grupo, y el bot le informa el chat_id por privado. | El usuario no necesita el panel | Requiere comando `/start` + chat privado |
| **Forzar leaveChat manual** | El bot nunca sale automáticamente. El admin tiene un botón "Forzar salida" en el panel. | Simple | El bot queda en todos lados hasta que el admin decida |
| **Webhook entrante → auto-create** | Si llega un `my_chat_member` de un grupo nuevo, el bot crea automáticamente una conexión esqueleto (sin tracker configurado) y espera configuración. | Experiencia fluida | Más complejo de implementar |

### Decisión

⬜ **Pendiente** — Se evaluará al implementar la UI de conexiones.

---

## 7. Glosario

| Término | Definición |
|---------|-----------|
| **Binding / Vinculación** | Configuración que conecta un grupo de Telegram con un tracker de TikiWiki. Unidad mínima de configuración. |
| **Slug** | Identificador alfanumérico único de un binding (ej: `qpch_prod`). |
| **Permission Tier** | Nivel de permisos del token de TikiWiki: `admin` (puede crear trackers/galerías) o `user` (solo escribir items). |
| **Field Mapping** | Correspondencia entre campos de un NormalizedMessage y los permNames de un tracker TikiWiki. |
| **ConfigManager** | Componente que lee/escribe `setup.json` y resuelve bindings. |
| **Router** | Lógica que, dado un webhook entrante, determina a qué binding pertenece. |

---

## Historial de cambios

| Fecha | Cambio |
|------|--------|
| 11/06/2026 | Creación del documento. Discusión de arquitectura multi con usuario. |
