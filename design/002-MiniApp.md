# 002 — Mini App / Structured Data Collection (Kobo-lite)

> **Fecha**: 10/06/2026
> **Estado**: Exploración / Diseño preliminar
> **Sesión**: Orquestador principal con usuario (cheperico)
> **Tags**: miniapp, kobo, offline, formularios, estructura, comunidades

---

## Propósito

trackerGram empezó como un **capturador pasivo** de mensajes de Telegram: todo lo que se habla en un grupo se guarda automáticamente en un tracker de TikiWiki. Es un backup conversacional con metadata (multimedia, GPS, reacciones, etc.).

La pregunta que disparó esta exploración es: **¿cómo convertir trackerGram en una herramienta de recolección de datos estructurados, comparable (modestamente) a KoboToolbox?**

> **Objetivo final**: Tener una wiki con mucha información y muchos proyectos trabajando a la vez. **TikiWiki es el lugar final de la información y desde donde se propaga al mundo.**

### Usuarios target

- **Comunidades** (no-técnicas pero dispuestas a aprender)
- **Trabajo de campo** con registro de observaciones, monitoreo, relevamientos
- **Múltiples proyectos** simultáneos, cada uno con su estructura de datos

---

## Lo que YA tenemos (state of the art del tracker Messages)

### Campos existentes en el tracker por defecto

| Categoría | Campos | Tipo Tiki | Qué captura |
|-----------|--------|-----------|-------------|
| **Identidad** | `TelegramMessageId`, `ChatId`, `TopicId`, `UserId`, `Username`, `DisplayName` | text | Quién, dónde, cuándo |
| **Contenido** | `Text` (textarea), `MessageType` | text/textarea | El mensaje en sí |
| **Ubicación** | `Location` | geolocation (G) | GPS lat/lon/zoom |
| **Multimedia** | `Media` (FG), `MediaUrl`, `FileUrl`, `MediaType`, `MediaSize`, `MediaWidth`, `MediaHeight`, `MediaDuration`, `MediaCaption` | FG + text/number/duration | Fotos, videos, audios, docs + metadatos |
| **Temporal** | `MessageDate` (datetime), `EditedDate` | f + text | Cuándo se envió/editó |
| **Relacional** | `ReplyToId`, `Reactions` | text | Respuestas y reacciones |
| **Contexto** | `ChatTitle`, `TopicTitle` | text | Grupo y topic |

### Lo que funciona hoy

- ✅ Webhook en tiempo real: mensajes → TikiWiki
- ✅ Importación de exports ZIP (histórico)
- ✅ Soporte multimedia: fotos, videos, audio, documentos, stickers, notas de voz
- ✅ Topics (forums) con resolución de nombres
- ✅ Reacciones formateadas
- ✅ Service messages (tópicos, miembros, pins)
- ✅ Deduplicación por `(chat_id, message_id)`
- ✅ Seguridad: CSRF, rate limiting, path traversal, XSS fix
- ✅ Panel de admin web
- ✅ Botón GPS nativo de Telegram (`KeyboardButton.requestLocation`)
- ✅ Media groups (álbumes)

### Lo que NO podemos hacer hoy

- ❌ **Agrupar múltiples datos bajo una misma entidad** (foto + GPS + texto descriptivo + pH = 3 items sueltos, no 1 registro de planta)
- ❌ **Campos estructurados custom** (pH numérico con validación 0-14, especie con dropdown, altura en metros)
- ❌ **Jerarquía proyecto/subproyecto** (todo está en un solo tracker plano)
- ❌ **Schema dinámico** (los campos son fijos del tracker Messages)
- ❌ **Offline-first** (hoy depende de conexión para el webhook, aunque Telegram cola mensajes)
- ❌ **Validaciones en cliente** (tipos, rangos, obligatoriedad)

---

## El problema central: De "Mensajes" a "Entidades"

Hoy: 3 mensajes = 3 items sueltos

```
Msg 1: Foto de la planta          → Item #42
Msg 2: GPS -34.6, -58.3           → Item #43
Msg 3: "Planta X, ph 6.2, 1.5m"   → Item #44
```

Necesitamos: 1 registro = 1 item con todos los campos

```
Registro #17: Planta X
  ├── Proyecto: "Flora cuenca arroyo Z" (ItemLink)
  ├── Subproyecto: "Campo Y" (ItemLink)
  ├── GPS: -34.6, -58.3
  ├── Fotos: [img1.jpg, img2.jpg]
  ├── Especie: Ceibo (Dropdown)
  ├── pH: 6.2 (Number, 0-14)
  ├── Altura: 1.5 (Number)
  ├── Cobertura: 40% (Number)
  ├── Fecha observación: 2026-06-10 (Date)
  └── Observador: @usuario (User selector)
```

---

## Enfoques discutidos

### A. Mini App (WebView dentro de Telegram)

Un formulario HTML/JS/CSS que se abre dentro de Telegram al tocar un botón.

| Pros | Contras |
|------|---------|
| UI nativa (colores, botones, tema adaptativo) | ❌ **Offline poco confiable** — Service Worker no garantizado en WebView |
| Validaciones en JS (tipos, rangos, obligatoriedad) | Primera carga requiere internet |
| Selectores nativos (cámara, galería, GPS) | Background Sync no disponible en WebView |
| Experiencia "formulario" completa | Debug difícil |
| | ~5+ sesiones de desarrollo |

**Veredicto**: Descartado como solución primaria por el problema de offline. Posible como **complemento opcional** en el futuro.

### B. PWA (Progressive Web App) externa

Aplicación web instalable, con Service Worker + IndexedDB + Background Sync.

| Pros | Contras |
|------|---------|
| ✅ **Offline real** (SW cache, IndexedDB persist, BG Sync) | Sale de Telegram (hay que abrir app aparte) |
| Debug en Chrome DevTools normal | Hay que instalar (o al menos cargar una vez) |
| Stack web estándar, sin dependencia de Telegram | ~5-6 sesiones de desarrollo |
| Control total del caché y sync | |

**Veredicto**: Opción técnicamente sólida, pero requiere bastante desarrollo y el usuario sale de Telegram.

### C. Keywords sueltas (1 campo por mensaje)

`!!!Distancia 30 metros` → un campo por mensaje.

| Pros | Contras |
|------|---------|
| ✅ **100% offline** — Telegram cola mensajes localmente | ❌ **1 mensaje = 1 campo** — fragmentado |
| 0 frontend nuevo | Sin validaciones |
| 30 min de desarrollo | Múltiples mensajes por registro = desorden |
| ReplyKeyboard evita typos | |

**Veredicto**: Demasiado limitado para reemplazo de Kobo.

### D. Formulario en mensaje único (⭐ Recomendado)

`!!!planta ph=6.2 especie=Ceibo altura=1.5` + foto + GPS

| Pros | Contras |
|------|---------|
| ✅ **100% offline** (Telegram cola todo) | Caption limitado a 1024 caracteres (si adjunta foto) |
| ✅ **1 mensaje = 1 registro completo** | Sin validaciones en cliente |
| ✅ Fotos/audio nativos (adjuntos) | Tipeo manual de claves |
| ✅ GPS con botón nativo de Telegram | |
| ✅ ~1 sesión de desarrollo (parser) | |
| ✅ No requiere frontend nuevo | |
| ✅ Mantenimiento mínimo | |
| ✅ ReplyKeyboard evita typos en claves | |

### E. ReplyKeyboard + Keywords anidadas (Variante de D)

Botones que insertan `clave=` en el input, combinación de `!!!tipo` con múltiples `clave=valor`.

| Pros | Contras |
|------|---------|
| ✅ Cero typos (botones pre-escritos) | Si hay muchas claves, el teclado se satura |
| ✅ Offline nativo | |
| ✅ Rápido (3-5 seg por registro) | |
| ✅ Extensible con Inline Query si el teclado crece | |

---

## Decisiones de arquitectura

### El ciclo de vida completo (dos fases)

**Fase 1: Configuración / Diseño** (online, con acceso a TikiWiki)

```
Usuario crea grupo Telegram
  → Agrega bot trackerGram
    → Bot trae campos base predefinidos
      → Usuario define SUS campos adicionales (interactivamente)
        → Sistema crea/actualiza tracker en TikiWiki
          → Bot devuelve: "Tus claves son: ph, altura, especie..."
```

**Fase 2: Producción / Recolección** (offline en campo → online al volver)

```
Usuario en el campo (sin internet)
  → Abre Telegram, grupo del proyecto
    → Envía: !!!planta ph=6.2 especie=Ceibo + foto + GPS
      → Telegram cola el mensaje localmente
        → Usuario vuelve a la base, conecta WiFi
          → Mensaje sale → Webhook → Parser → Tracker TikiWiki
            → Registro creado con todos los campos poblados
```

### TikiWiki como fuente de verdad (bidireccional)

El schema de datos debe poderse definir tanto:

1. **Forward**: Bot wizard → crea campos en TikiWiki → devuelve claves al usuario
2. **Reverse**: Admin agrega campos en TikiWiki web → Bot lee via API → actualiza claves disponibles

**Mecanismo**: `SchemaRegistry.syncFromTikiWiki(trackerId)` via `GET /api/trackers/{id}/fields`

### Mapeo permName → clave amigable

| permName en TikiWiki | Clave para usuario | Regla |
|---------------------|-------------------|-------|
| `observacionPlantaPH` | `ph` | Sacar prefijo + lowercase |
| `observacionPlantaEspecie` | `especie` | Idem |
| `observacionPlantaDiametroCopa` | `diametro_copa` | CamelCase → snake_case |

---

## Capacidades de la API REST de TikiWiki

Verificadas contra la documentación OpenAPI embebida en `wiki.chela.org.ar/api/`:

### Endpoints relevantes

| Operación | Endpoint | Método | Content-Type |
|-----------|----------|--------|--------------|
| Crear tracker | `/trackers` | POST | form-urlencoded |
| Listar fields | `/trackers/{id}/fields` | GET | — |
| **Crear campo** | `/trackers/{id}/fields` | POST | form-urlencoded |
| **Actualizar campo** (opciones) | `/trackers/{id}/fields/{fid}` | POST | form-urlencoded |
| Crear item | `/trackers/{id}/items` | POST | form-urlencoded |
| Subir archivo | `/galleries/upload` | POST | multipart |

### Tipos de campo soportados

| Código | Tipo | Uso |
|--------|------|-----|
| `r` | Item Link | Relación a otro tracker (proyecto, subproyecto) |
| `d` / `D` | Dropdown / Dropdown+Otro | Lista de opciones predefinidas (especie, tipo) |
| `FG` | File Gallery | Fotos, documentos (múltiples archivos) |
| `G` | Geolocation | GPS lat/lon/zoom |
| `n` | Numeric | Número decimal con validación min/max/dec |
| `f` / `j` | Date/Time / Date Picker | Fecha y hora |
| `DUR` | Duration | Duración en segundos (hh:mm:ss) |
| `a` | Text Area | Texto largo |
| `t` | Text Field | Texto corto |

### Opciones configurables por campo via `option[]`

| Tipo | Opciones típicas |
|------|------------------|
| Item Link (`r`) | `trackerId`, `fieldId`, `linkToItem`, `displayFieldsList`, `status` |
| Dropdown (`d`) | `options=Valor1,Valor2,Valor3` |
| File Gallery (`FG`) | `galleryId`, `count`, `excessBehavior` |
| Numeric (`n`) | `decimals`, `min`, `max` |

### Flujo de creación de tracker con campos complejos

```
1. POST /trackers → crea contenedor (name, description, fieldPrefix)
2. POST /trackers/{id}/fields → crea campo (name, permName, type)
3. POST /trackers/{id}/fields/{fid} → configura opciones (option[])
4. Repetir 2-3 para cada campo
```

> ⚠️ No existe una llamada única que cree tracker + todos los campos + opciones en un solo request. El `createTracker()` actual de trackerGram que POSTea JSON con `fields` inline funciona para casos básicos, pero **no es un comportamiento documentado** y no se garantiza para opciones complejas (ItemLink, Dropdown con opciones, FG con galleryId).

---

## Estrategias para la creación de estructura

### Opción X: Trackers creados manualmente en TikiWiki (admin técnico)

- Admin crea trackers base: `Observaciones_Plantas`, `Observaciones_Aves`, `Observaciones_Agua`, `Proyectos`, `Subproyectos`
- Bot solo lee schema via API y popula items
- **Ventaja**: Robusto, schema diseñado con cuidado, Pretty Templates configurados
- **Desventaja**: Requiere admin técnico cada vez que se necesita un tracker nuevo

### Opción Y: Wizard interactivo en Telegram que crea tracker via API

- Bot guía al usuario paso a paso: nombre del proyecto, tipo de registro, campos, opciones
- Bot hace múltiples llamadas API para crear tracker + campos + opciones
- **Ventaja**: Autoservicio, no requiere admin técnico
- **Desventaja**: Muchas llamadas API encadenadas, frágil si una falla, permisos de admin requeridos

### Opción Z: Híbrido (mixta)

- Trackers "de dominio" creados a mano por admin (Observaciones_Plantas, etc.)
- Proyectos/Subproyectos creados por wizard del bot (items en trackers maestros, simples)
- Si se necesita un tracker nuevo → admin lo crea → bot lo detecta via `/sync_campos`

**Pendiente de resolver**: Cuál es la mejor estrategia.

---

## Componentes de software necesarios

### Nuevos (identificados)

| Componente | Rol |
|-----------|-----|
| `SchemaRegistry.php` | Lee schema desde TikiWiki (`syncFromTikiWiki()`), cachea localmente, mapea permName ↔ clave amigable, valida tipos |
| `StructuredMessageParser.php` | Parsea `!!!tipo clave=valor...` + extrae adjuntos (foto, GPS) del mensaje → NormalizedMessage |
| `BotCommandHandler.php` (extender) | Comandos `/nuevo_proyecto`, `/campos`, `/sync_campos`, `/plantilla`, `/proyecto_actual` |
| `SessionManager.php` | Estado de conversación para wizards interactivos (sesión por usuario: paso actual, datos acumulados) |

### A modificar

| Archivo | Cambio |
|---------|--------|
| `MessageMapper.php` | Agregar `parseStructuredMessage()`, `fromStructuredMessage()` |
| `WebhookHandler.php` | Detectar `!!!tipo` al inicio, bifurcar a structured flow |
| `TikiWikiClient.php` | `createTrackerField()`, `updateFieldOptions()`, `getTrackerFields()` (genérico), `createObservationItem()` |
| `bootstrap.php` | Wiring de nuevos componentes |

---

## Comparativa de enfoques (resumen)

| Criterio | Keywords sueltas (C) | Msg único (D) | ReplyKbd (E) | Mini App (A) | PWA (B) |
|----------|---------------------|---------------|--------------|--------------|---------|
| Offline real | ✅ | ✅ | ✅ | ❌ | ✅ |
| 1 msg = 1 registro | ❌ | ✅ | ✅ | ✅ | ✅ |
| Fotos/audio nativos | ✅ | ✅ | ✅ | ✅ | ✅ |
| GPS nativo | ✅ | ✅ | ✅ | ✅ | ✅ |
| Validaciones cliente | ❌ | ❌ | ❌ | ✅ | ✅ |
| Sin frontend nuevo | ✅ | ✅ | ✅ | ❌ | ❌ |
| Dev effort | 30 min | ~1 sesión | ~1 sesión | 5+ sesiones | 5-6 sesiones |
| Mantenimiento | Bajo | Bajo | Bajo | Alto | Alto |
| UX comunidad | ❌ fragmentado | ✅ | ✅ | ✅ | ❌ sale de TG |

---

## Preguntas abiertas

- ¿Cuál es la mejor estrategia para crear trackers? (manual vs wizard vs híbrido)
- ¿Un tracker por dominio (Plantas, Aves, Agua) o un tracker único con discriminador `entityType`?
- ¿Manejo de sesiones para wizards interactivos: archivo JSON, SQLite, o en memoria?
- ¿Cómo se manejan ediciones a mensajes `!!!tipo` ya enviados? (edited_message → update item)
- ¿Límite de caption de 1024 caracteres: suficiente para formularios típicos o hay que planificar workaround con archivo .txt adjunto?)
- **Mini App viability**: El diseño original la descartó por offline poco confiable, pero `Telegram.WebApp.DeviceStorage` y `CloudStorage` ofrecen almacenamiento local + reintento. ¿Vale la pena reconsiderarla como alternativa/complemento al mensaje estructurado? **(pendiente de definición)** <!-- actualizado 16/06/2026 -->

---

## Pendiente para próxima sesión

- Decidir estrategia de creación de trackers (opción X, Y o Z)
- Definir lista de `entityType` iniciales (Planta, Ave, Suelo, Agua, Otro)
- Definir campos base por tipo
- Implementar `SchemaRegistry.syncFromTikiWiki()` + pruebas contra `wiki.chela.org.ar`
- Implementar parser de `!!!tipo clave=valor...` en MessageMapper
- Diseñar ReplyKeyboard dinámico basado en schema
