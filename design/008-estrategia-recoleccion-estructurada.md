# 008 — Estrategia de Recolección Estructurada + Diseño de TikiPickIt

> **Documento unificado** — Consolida y reemplaza los documentos `002-MiniApp.md`, `007-pwa-offline-formularios.md` y `010-tikipickit-pwa-recoleccion.md`.
> Los originales se mantienen como referencia histórica.
>
> **Última actualización**: 09/07/2026
> **Tags**: recolección, formularios, offline, gather, pwa, miniapp, tikipickit, tracker

---

## Índice

1. [Propósito](#1-propósito)
2. [El problema: de mensajes sueltos a entidades](#2-el-problema-de-mensajes-sueltos-a-entidades)
3. [Lo que YA tenemos](#3-lo-que-ya-tenemos)
4. [Infraestructura offline de TikiWiki (existente)](#4-infraestructura-offline-de-tikiwiki-existente)
5. [Prerrequisitos (comunes a cualquier enfoque)](#5-prerrequisitos-comunes-a-cualquier-enfoque)
6. [Todos los enfoques comparados](#6-todos-los-enfoques-comparados)
7. [TikiPickIt: Diseño detallado](#7-tikipickit-diseño-detallado)
8. [Comparativa consolidada](#8-comparativa-consolidada)
9. [Decisiones de arquitectura](#9-decisiones-de-arquitectura)
10. [Historial de decisiones](#10-historial-de-decisiones)
11. [Preguntas abiertas](#11-preguntas-abiertas)
12. [Próximos pasos](#12-próximos-pasos)
13. [Referencias](#13-referencias)

---

## 1. Propósito

trackerGram empezó como un **capturador pasivo** de mensajes de Telegram: todo lo que se habla en un grupo se guarda automáticamente en un tracker de TikiWiki. Es un backup conversacional con metadata.

Este documento explora y define cómo convertirlo en una **herramienta de recolección activa de datos estructurados**, comparable (modestamente) a KoboToolbox, con capacidad offline para trabajo de campo.

El resultado es **TikiPickIt**: una PWA independiente que permite cargar datos estructurados en trackers de TikiWiki sin conexión a internet, complementaria a trackerGram.

### Objetivo final

> Tener una wiki con mucha información y muchos proyectos trabajando a la vez.
> **TikiWiki es el lugar final de la información y desde donde se propaga al mundo.**

### Usuarios target

- **Comunidades** (no-técnicas pero dispuestas a aprender)
- **Trabajo de campo** con registro de observaciones, monitoreo, relevamientos
- **Múltiples proyectos** simultáneos, cada uno con su estructura de datos

---

## 2. El problema: de mensajes sueltos a entidades

### Hoy: 3 mensajes = 3 items sueltos

```
Msg 1: Foto de la planta          → Item #42
Msg 2: GPS -34.6, -58.3           → Item #43
Msg 3: "Planta X, ph 6.2, 1.5m"   → Item #44
```

### Necesitamos: 1 registro = 1 item con todos los campos

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

### Lo que NO podemos hacer hoy

| Carencia | Detalle |
|----------|---------|
| ❌ Agrupar datos bajo una entidad | Foto + GPS + texto + pH = items sueltos, no 1 registro |
| ❌ Campos estructurados custom | pH con validación 0-14, especie con dropdown, altura en metros |
| ❌ Jerarquía proyecto/subproyecto | Todo está en un solo tracker plano |
| ❌ Schema dinámico | Campos fijos del tracker Messages |
| ❌ Offline-first | Dependencia de conexión para webhook (aunque Telegram cola mensajes) |
| ❌ Validaciones en cliente | Tipos, rangos, obligatoriedad |

---

## 3. Lo que YA tenemos

### trackerGram (puente Telegram → TikiWiki)

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
- ✅ `/gather` prototype: formulario interactivo con inline keyboards + persistencia a TikiWiki
- ✅ Arquitectura multi-conexión (múltiples bots, wikis, trackers)
- ✅ Rate limiting con flock(LOCK_EX)
- ✅ SSRF DNS rebinding prevention

### TikiWiki (backend de almacenamiento)

- ✅ API REST completa (CRUD trackers, fields, items, galleries, files)
- ✅ Autenticación Bearer token + OAuth2
- ✅ PWA experimental con Service Worker + cola offline IndexedDB
- ✅ Sincronización bidireccional entre instancias (SyncController)
- ✅ Exportación de datos en múltiples formatos (JSON, NDJSON, CSV, YAML)

---

## 4. Infraestructura offline de TikiWiki (existente)

TikiWiki ya tiene componentes de offline/PWA que cambian radicalmente la viabilidad de este proyecto.

### Componentes existentes

| Componente | Archivo | Qué hace |
|------------|---------|----------|
| **Service Worker** | `sw.js` (196 líneas) | Cachea páginas wiki e items de tracker (GET) |
| **App Shell** | `lib/pwa/app.js` | Lógica de sincronización, cola offline en IndexedDB (`post_cache`) |
| **Página fallback** | `lib/pwa/offline.html` | Respaldo cuando no hay conexión |
| **SyncController** | `lib/core/Services/Tracker/SyncController.php` (456 líneas) | Sincronización bidireccional entre instancias Tiki con detección de conflictos |
| **OAuth2** | `lib/core/Services/ApiBridge.php` | Autenticación de API completa |
| **Export JSON/NDJSON/CSV** | `Tabular/Writer/*.php` | Exportación de datos en formatos portables |

### Lo que el PWA de TikiWiki ya hace offline

- Intercepta POST/PUT y los guarda en IndexedDB (`post_cache`)
- Cuando volvés online, muestra botón de sincronización
- Al hacer click, reproduce todas las peticiones en orden

### Lo que NO hace (y construiría TikiPickIt)

| Carencia | Solución TikiPickIt |
|----------|---------------------|
| No descarga estructura de campos | Cachear `GET /api/trackers/{id}/fields` en IndexedDB |
| No tiene UI de ingreso de datos offline | Formulario dinámico renderizado desde schema |
| No tiene manejo de conflictos en cliente | Patrón SyncController adaptado + `modifiedSince` |
| No permite seleccionar/switchear entre trackers | Dashboard + pills |
| No tiene validación local de datos | Validación de tipos, rangos, obligatoriedad en JS |
| No maneja credenciales de API | Pantalla de Settings con token persistido |

### Endpoint clave para sync incremental

| Endpoint | Método | Para qué |
|----------|--------|----------|
| `GET /api/trackers/{id}?modifiedSince=<timestamp>` | GET | Obtener solo items modificados desde X |

---

## 5. Prerrequisitos (comunes a cualquier enfoque)

Independientemente del enfoque elegido, hay prerrequisitos que aplican a todos porque dependen de la API de TikiWiki y de los permisos del token.

### 5.1 Permisos de TikiWiki necesarios

El token de API necesita estos permisos mínimos:

| Permiso | ¿Necesario? | ¿Para qué? |
|---------|-------------|------------|
| `tiki_p_admin_trackers` | ✅ Sí | Leer fields del tracker (`GET /api/trackers/{id}/fields`), crear campos |
| `tiki_p_create_tracker_items` | ✅ Sí | Crear items en el tracker |
| `tiki_p_upload_files` | ✅ Sí | Subir archivos a file galleries |
| `tiki_p_view_trackers` | ✅ Implícito | `testConnection()` lo verifica via `GET /api/trackers` |
| `tiki_p_admin_file_galleries` | ❌ No necesario | Solo para crear galerías (acción única de setup) |

**Conclusión**: El set mínimo actual (`admin_trackers` + `create_tracker_items` + `upload_files`) ya cubre la funcionalidad de lectura de schemas y creación de items.

### 5.2 Endpoints API de TikiWiki

#### Endpoints existentes

| Endpoint | Método | Content-Type | Qué devuelve |
|----------|--------|--------------|-------------|
| `GET /api/trackers` | GET | — | Lista de trackers (id, name, description) |
| `GET /api/trackers/{id}/fields` | GET | — | Definiciones de campos (permName, type, fieldId, options, isMandatory) |
| `GET /api/trackers/{id}` | GET | — | Info del tracker + items (soporta `modifiedSince`) |
| `GET /api/trackers/{id}/items/{itemId}` | GET | — | Un item específico |
| `GET /api/trackers/{id}/dump` | GET | — | Export CSV de todos los items |
| `POST /api/trackers/{id}/items` | POST | form-urlencoded | Crear item |
| `POST /api/trackers/{id}/items/{itemId}` | POST | form-urlencoded | Actualizar item |
| `DELETE /api/trackers/{id}/items/{itemId}` | DELETE | — | Eliminar item |
| `POST /api/galleries/upload` | POST | multipart/form-data | Subir archivo |

#### Autenticación

Header: `Authorization: Bearer <token>`

El token se crea en TikiWiki (Admin → API Tokens) y se asocia a un usuario de TikiWiki. Ese usuario define qué trackers puede ver y modificar.

### 5.3 Brecha actual en TikiWikiClient (trackerGram)

| Método | Estado | Notas |
|--------|--------|-------|
| `loadTrackerFields(int $trackerId)` | ✅ Existe (private) | Cacheado por tracker |
| `getTrackerInfo(int $trackerId)` | ✅ Existe (public) | Devuelve nombre, descripción |
| `testConnection()` | ✅ Existe (public) | Obtiene datos de `GET /api/trackers` pero no los retorna |
| `listTrackers()` | ❌ No existe | `testConnection()` obtiene los datos pero no los retorna |
| `createTrackerItem()` | ✅ Existe (public) | POST fields a tracker |

> **Nota**: TikiPickIt se conecta directo a TikiWiki API, no via trackerGram. Por lo tanto las brechas de TikiWikiClient no afectan a TikiPickIt. Se listan aquí para referencia del proyecto trackerGram.

### 5.4 Estructura de datos devuelta por la API

#### Field schema (`GET /api/trackers/{id}/fields`)

```json
{
  "fields": [
    {
      "fieldId": 123,
      "name": "TelegramMessageId",
      "permName": "telegrammessageTelegramMessageId",
      "type": "t",
      "options": null,
      "isMandatory": false,
      "description": "ID único del mensaje en Telegram"
    },
    {
      "fieldId": 125,
      "name": "Media",
      "permName": "telegrammessageMedia",
      "type": "FG",
      "options": "[{\"name\":\"galleryId\",\"value\":\"36\"}]",
      "isMandatory": false
    },
    {
      "fieldId": 126,
      "name": "Location",
      "permName": "telegrammessageLocation",
      "type": "G",
      "options": null,
      "isMandatory": false
    }
  ]
}
```

**Claves críticas para renderizar formularios**:

| Clave | Tipo | Para qué sirve |
|-------|------|----------------|
| `permName` | string | Identificador único del campo. Se usa como key al enviar datos. |
| `type` | string | Código de tipo TikiWiki: `t`, `a`, `n`, `f`, `D`, `G`, `FG`, `r`, `d`, `F` |
| `name` | string | Nombre visible del campo (para UI humana). |
| `options` | string/null | Opciones serializadas JSON (galleryId para FG, opciones para dropdown, etc.) |
| `isMandatory` | bool | Si el campo es obligatorio. |
| `description` | string/null | Texto de ayuda. |

**Mapeo type → input HTML**:

| Type TikiWiki | Input HTML | Opciones |
|---------------|-----------|----------|
| `t` (text) | `<input type="text">` | — |
| `a` (textarea) | `<textarea>` | rows |
| `n` (number) | `<input type="number">` | min, max, step desde `options` |
| `f` / `j` (datetime) | `<input type="datetime-local">` | — |
| `D` (date) | `<input type="date">` | — |
| `G` (geolocation) | Botón "📍 Usar ubicación actual" | Lat + lon generados por GPS |
| `FG` (file gallery) | `<input type="file" accept="image/*">` | Múltiples archivos |
| `r` (item link) | `<select>` con items de otro tracker | `trackerId` desde `options` |
| `d` / `D` (dropdown) | `<select>` | Opciones desde `options` |
| `F` (freetags) | `<input type="text" placeholder="tag1 tag2">` | — |
| `DUR` (duration) | `<input type="number" min="0">` segundos | — |

---

## 6. Todos los enfoques comparados

### A. Mini App (WebView dentro de Telegram)

Formulario HTML/JS/CSS embebido en Telegram al tocar un botón.

| Pros | Contras |
|------|---------|
| UI nativa (colores, botones, tema adaptativo) | ❌ **Offline poco confiable** — Service Worker no garantizado en WebView |
| Validaciones en JS | Background Sync no disponible en WebView |
| Selectores nativos (cámara, galería, GPS) | Debug difícil |

**Veredicto**: Descartado como solución primaria por offline no confiable.

### B. PWA externa (desde cero)

Aplicación web instalable con Service Worker + IndexedDB + Background Sync.

| Pros | Contras |
|------|---------|
| ✅ **Offline real** | Sale de Telegram |
| Stack web estándar | ~5-6 sesiones de desarrollo |

**Veredicto**: Superado por enfoque G (TikiWiki ya tiene la mitad del código).

### C. Keywords sueltas (1 campo por mensaje)

`!!!Distancia 30 metros` → un campo por mensaje.

| Pros | Contras |
|------|---------|
| ✅ **100% offline** — Telegram cola mensajes | ❌ **1 mensaje = 1 campo** — fragmentado |
| 0 frontend nuevo | Sin validaciones |

**Veredicto**: Demasiado limitado para reemplazo de Kobo.

### D. Formulario en mensaje único (⭐ Recomendado para flujo Telegram)

`!!!planta ph=6.2 especie=Ceibo` + foto + GPS

| Pros | Contras |
|------|---------|
| ✅ **100% offline** (Telegram cola todo) | Caption limitado a 1024 caracteres (con foto) |
| ✅ **1 mensaje = 1 registro** | Sin validaciones en cliente |
| ✅ Fotos/audio/GPS nativos | Tipeo manual de claves |
| ✅ ~1 sesión de desarrollo | |

**Veredicto**: Mejor opción para flujo **dentro de Telegram**. Complementario a TikiPickIt.

### E. ReplyKeyboard + Keywords (Variante de D)

Botones que insertan `clave=` en el input.

| Pros | Contras |
|------|---------|
| ✅ Cero typos | Si hay muchas claves, el teclado se satura |
| ✅ Offline nativo | |
| ✅ Rápido (3-5 seg por registro) | |

### F. `/gather` — Formulario interactivo con Inline Keyboard

Prototipo implementado: bot hace preguntas una por una con botones inline.

| Pros | Contras |
|------|---------|
| ✅ Experiencia guiada (paso a paso) | ❌ **Offline: NO funciona** — callback_query no se encola |
| ✅ Validaciones inmediatas | Sesión se pierde si cierra Telegram |
| ✅ Sin frontend nuevo | |
| ✅ ~2 sesiones (ya hecho) | |

**Veredicto**: Excelente experiencia guiada para uso **online** dentro de Telegram.

### G. PWA TikiPickIt — extendiendo infraestructura existente de TikiWiki (⭐ RECOMENDADO)

Partir del ecosistema TikiWiki (API REST, OAuth2, estructura de campos) y construir una PWA standalone con formularios dinámicos offline.

| Pros | Contras |
|------|---------|
| ✅ **Offline real** (SW + IndexedDB) | Sale de Telegram (pero es el objetivo) |
| ✅ **~2-3 sesiones de desarrollo** (vs 5-6 desde cero) | Requiere token de API de TikiWiki |
| ✅ TikiWiki ya tiene API, auth, export, schema | |
| ✅ Sin depender de trackerGram | |
| ✅ Mismo tracker puede recibir datos de Telegram + PWA | |

**Veredicto**: Enfoque elegido para TikiPickIt. Ver diseño detallado en §7.

### H. Tiki local + SyncController

Instancia local de TikiWiki que clona el tracker remoto, trabaja offline y sincroniza con `sync_edit()`.

| Pros | Contras |
|------|---------|
| ✅ Código 100% existente | Requiere TikiWiki corriendo localmente |
| ✅ Bidireccional con detección de conflictos | No práctico para móvil |

**Veredicto**: Complementario para laptop de campo. No es solución mobile.

---

## 7. TikiPickIt: Diseño detallado

### 7.1 Arquitectura

```
┌──────────────────────────────────────────────────┐
│                   TikiPickIt PWA                   │
│                                                    │
│  ┌────────────┐  ┌──────────┐  ┌──────────────┐  │
│  │ Settings    │  │Dashboard │  │ Formulario   │  │
│  │ (API + Tkn) │  │(Trackers)│  │ (campos)     │  │
│  └────────────┘  └──────────┘  └──────────────┘  │
│                                                    │
│  ┌──────────────────────────────────────────────┐  │
│  │        Service Worker (sw.js)                │  │
│  │  Cache: HTML, CSS, JS, schemas, icons        │  │
│  └──────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────┐  │
│  │        IndexedDB                              │  │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────────┐ │  │
│  │  │ trackers │ │ schemas  │ │ queue        │ │  │
│  │  │ (cache)  │ │ (fields) │ │ (pendientes) │ │  │
│  │  └──────────┘ └──────────┘ └──────────────┘ │  │
│  └──────────────────────────────────────────────┘  │
└──────────────────┬───────────────────────────────┘
                   │ HTTPS / Bearer Token
                   ▼
┌──────────────────────────────────────────────────┐
│              TikiWiki API                         │
│  tiki-api.php → ApiBridge → Tracker Controller   │
│                                                    │
│  GET  /api/trackers              → lista          │
│  GET  /api/trackers/{id}/fields → schema         │
│  POST /api/trackers/{id}/items  → crear item     │
│  POST /api/galleries/upload     → subir archivo  │
└──────────────────────────────────────────────────┘
```

**TikiPickIt es 100% estático**: HTML+CSS+JS. No requiere servidor propio. Se sirve desde cualquier hosting estático o desde el mismo dominio de TikiWiki.

**Sin frameworks**: JavaScript vanilla, IndexedDB nativa, CSS custom. ~40KB total.

### 7.2 Componentes de la app

| Archivo | Propósito | Tamaño aprox |
|---------|-----------|-------------|
| `tikipickit/index.html` | Entry point, shell | ~5 KB |
| `tikipickit/app.js` | Lógica: navegación, form, sync, IndexedDB | ~15-20 KB |
| `tikipickit/sw.js` | Service Worker: cache + offline intercept | ~5 KB |
| `tikipickit/manifest.json` | Web App Manifest | ~1 KB |
| `tikipickit/icons/` | Iconos para homescreen | ~10 KB |

### 7.3 Flujo de usuario

#### Primera vez (setup)

```
1. Usuario abre TikiPickIt
2. Ve pantalla de configuración
3. Ingresa URL de TikiWiki + Bearer Token
4. TikiPickIt prueba conexión: GET /api/trackers
5. "✅ Conectado — 12 trackers encontrados"
6. Guarda credenciales en localStorage
7. Pre-descarga schemas de todos los trackers a IndexedDB
```

#### Uso diario (online)

```
1. Abre → Dashboard con tarjetas de trackers + pendientes
2. Toca "🌿 Plantas" → formulario renderizado desde schema cachead
3. Pills en header: [🏠] [🌿 Plantas] [💧 Agua] [🐦 Aves]
4. Llena campos, adjunta foto, GPS automático
5. Toca "💾 Guardar" → POST directo a TikiWiki
```

#### Uso diario (offline)

```
1. Abre → Dashboard (desde cache)
2. Toca tracker → formulario (schema desde IndexedDB)
3. Llena campos, adjunta foto
4. Toca "💾 Guardar" → cola en IndexedDB
5. Badge: "3 pendientes de sincronizar"
6. Al volver → WiFi → sync automático
```

### 7.4 Pantallas

#### 7.4.1 Settings (configuración inicial y admin de credenciales)

```
┌─────────────────────────────────┐
│  ⚙️ Configuración               │
│                                 │
│  URL de TikiWiki:               │
│  [https://wiki.chela.org.ar   ] │
│                                 │
│  Bearer Token API:              │
│  [••••••••••••••••••••••••] 👁  │
│                                 │
│  [🔌 Probar conexión]           │
│  ✅ Conectado — 12 trackers     │
│                                 │
│  ─── Preferencias ───           │
│                                 │
│  Iniciar en:                    │
│  ○ Dashboard                    │
│  ○ Último tracker usado         │
│                                 │
│  Descargar schemas:             │
│  ☑ Todos al iniciar sesión      │
│  ☐ Solo cuando los abro         │
│                                 │
│  Trackers visibles:             │
│  ☑ 🌿 Observaciones Plantas     │
│  ☑ 💧 Calidad de Agua           │
│  ☐ 🐦 Aves (oculto)            │
│                                 │
│  [💾 Guardar configuración]     │
└─────────────────────────────────┘
```

**Características**:
- Token se muestra/oculta con toggle 👁
- "Probar conexión" hace GET /api/trackers de validación
- Preferencias: inicio en dashboard vs último tracker, descarga automática vs manual de schemas
- **Whitelist de trackers**: el usuario puede ocultar trackers que no usa
- Credenciales en localStorage (cifrado opcional vía `crypto.subtle.encrypt()` futuro)

#### 7.4.2 Dashboard (selector de tracker)

```
┌─────────────────────────────────┐
│  📋 TikiPickIt          [⚙️]   │
│                                 │
│  📦 5 pendientes de sincronizar │
│                                 │
│  ┌─────────────────────────┐   │
│  │ 🌿 Observaciones Plantas │   │
│  │ 🕐 Hoy · 12 pendientes   │   │
│  │ 📊 8 campos              │   │
│  └─────────────────────────┘   │
│  ┌─────────────────────────┐   │
│  │ 💧 Calidad de Agua       │   │
│  │ 🕐 Ayer · 3 pendientes   │   │
│  │ 📊 12 campos             │   │
│  └─────────────────────────┘   │
│  ┌─────────────────────────┐   │
│  │ 🐦 Aves Avistadas        │   │
│  │ ✓ Sincronizado           │   │
│  │ 📊 6 campos              │   │
│  └─────────────────────────┘   │
└─────────────────────────────────┘
```

**Criterios de visibilidad de trackers** (combinables):
- **a) Todos los accesibles**: GET /api/trackers devuelve todos los que el token puede ver
- **b) Whitelist**: En settings, el usuario oculta/muestra trackers individualmente
- **c) Flag "coleccionable"**: Convención de nombre, categoría o campo marcador

**Indicadores por tarjeta**:
| Estado | Color | Condición |
|--------|-------|-----------|
| ✅ Sincronizado | Verde | 0 pendientes |
| 🟡 Con pendientes | Amarillo | < 10 items en cola |
| 🔴 Con errores | Rojo | ≥ 10 o error en último sync |

**Badge global** "📦 5 pendientes" arriba del dashboard.

#### 7.4.3 Formulario de carga

```
┌─────────────────────────────────┐
│ [🏠] [🌿 Plantas] [💧 Agua] [🐦]│
│                                 │
│  🌿 Nuevo registro: Plantas     │
│                                 │
│  📝 Especie *                   │
│  [Ceibo                    ▼]   │
│                                 │
│  📏 Altura (m) *               │
│  [1.5                     ]    │
│                                 │
│  🔢 pH                         │
│  [6.2]  (0-14)                 │
│                                 │
│  📍 Ubicación GPS               │
│  [📍 Usar ubicación actual]     │
│  -34.6, -58.3                  │
│                                 │
│  📷 Foto                        │
│  [📷 Tomar foto] [📁 Adjuntar]  │
│  IMG_20260709.jpg              │
│                                 │
│  📝 Notas                       │
│  [___________________________] │
│  [___________________________] │
│                                 │
│  ───────────────────────────    │
│  Pendientes para este tracker:  │
│  12 items · [🔄 Sincronizar]    │
│                                 │
│  [💾 Guardar]  [× Cancelar]     │
└─────────────────────────────────┘
```

**Header de pills**: permite cambiar de tracker sin volver al dashboard.

| Pill | Acción |
|------|--------|
| 🏠 | Volver al dashboard |
| 🌿 (resaltado) | Tracker actual |
| 💧 🐦 | Cambiar a otro tracker |

**Si hay más de 5 trackers**: los pills muestran los últimos usados + "..." que abre selector modal.

**Validaciones locales**:
- Campos obligatorios (*) marcados visualmente, submit bloqueado si faltan
- Rangos numéricos validados (pH 0-14, altura > 0)
- Dropdown solo acepta opciones predefinidas

### 7.5 Opciones de navegación entre trackers

#### Opción 1: Dashboard + Pills (⭐ diseño primario elegido)

Dashboard para vista general, pills en formulario para cambio rápido.

```
┌──────────────────┐     ┌──────────────────────┐
│ 📋 Dashboard     │     │ [🏠] [🌿] [💧] [🐦]  │
│                  │     ├──────────────────────┤
│ [🌿] 12 pends    │     │ Formulario Plantas   │
│ [💧]  3 pends    │     │ ...                  │
│ [🐦]  0 pends    │     │                      │
│                  │     │ [💾 Guardar]         │
└──────────────────┘     └──────────────────────┘
     toca tarjeta               pills para cambiar
```

#### Opción 2: Dropdown persistente (alternativa configurable)

Selector de tracker siempre visible. Layout más compacto.

```
┌─────────────────────────────────┐
│ [🌿 Observaciones Plantas  ▼]  │
├─────────────────────────────────┤
│ Formulario...                   │
└─────────────────────────────────┘
```

#### Opción 3: Tabs/Pills sin dashboard

Para pocos trackers (≤5). Sin pantalla de inicio.

```
┌─────────────────────────────────┐
│ [🌿 Plantas] [💧 Agua] [🐦 Aves] │
├─────────────────────────────────┤
│ Formulario del tracker activo   │
└─────────────────────────────────┘
```

#### Opción 4: Proyectos (futuro)

Trackers agrupados bajo proyectos padre con ItemLink. No para MVP.

### 7.6 Flujo offline detallado

#### Service Worker (sw.js)

Estrategia: **Cache First para assets, Network First para API**.

```
Instalación:
  → Precachea: index.html, app.js, manifest.json, icons

Fetch:
  GET (assets: html, js, css, png):
    → Cache First

  GET (API: /api/trackers, /api/trackers/{id}/fields):
    → Network First con fallback a cache
    → Si fetch ok → cache + responder
    → Si no → cache (schema previo)

  POST (API: /api/trackers/{id}/items):
    → Siempre fetch
    → Si falla → serializar + guardar en IndexedDB queue
    → Responder 200 OK (confirmación local)
```

#### IndexedDB — Schema de datos local

```javascript
const DB_NAME = 'tikipickit';
const DB_VERSION = 1;

// Object Stores:
// 1. trackers: { id, name, description, lastSync }
// 2. schemas: { trackerId, fields[], lastFetched }
// 3. queue: { id, trackerId, data, files[], createdAt, retries }
// 4. synclog: { id, trackerId, result, syncedAt }
// 5. prefs: { key, value }
// 6. trackerMeta: { trackerId, hidden, order, lastTracker }
```

#### Ciclo de sincronización

```
Evento 'online' → disparar sync:

1. Leer cola de IndexedDB (order by createdAt ASC)
2. Por cada item:
   a. Si tiene files: POST /api/galleries/upload → obtener fileId
   b. Construir form-urlencoded con fields
   c. POST /api/trackers/{id}/items
   d. Si 200/201 → mover a synclog, eliminar de queue
   e. Si 4xx → mover a synclog como "rejected" (no reintentar)
   f. Si 5xx/network → retries < 3: dejar en cola; ≥ 3: failed
3. Actualizar dashboard (contadores, badges)
```

#### Detección de conectividad

- `navigator.onLine` + eventos `online`/`offline`
- Health check periódico: GET /api/trackers cada 30s si hay pendientes
- Badge: `navigator.setAppBadge(pendingCount)`

### 7.7 Cómo se envía un item a TikiWiki

```
POST /api/trackers/26/items
Authorization: Bearer <token>
Content-Type: application/x-www-form-urlencoded

fields[observacionPlantasEspecie]=Ceibo
&fields[observacionPlantasAltura]=1.5
&fields[observacionPlantasPH]=6.2
&fields[observacionPlantasGPS]=-34.6,-58.3,15
&fields[observacionPlantasNotas]=Ejemplar en floración
```

### 7.8 Cómo se sube un archivo

```
POST /api/galleries/upload?galleryId=42
Authorization: Bearer <token>
Content-Type: multipart/form-data

file: <binary>
```

El `galleryId` se obtiene del field schema: cada campo tipo `FG` tiene su `galleryId` en `options`.

### 7.9 Compatibilidad mobile

| Feature | Chrome Android | Safari iOS |
|---------|---------------|------------|
| Service Worker | ✅ | ✅ |
| IndexedDB | ✅ | ✅ |
| Cache API | ✅ | ✅ |
| `navigator.onLine` | ✅ | ✅ |
| `navigator.geolocation` | ✅ | ✅ (HTTPS) |
| `<input type="file">` | ✅ | ✅ |
| `navigator.setAppBadge` | ✅ | ✅ |
| Add to Homescreen | ✅ | ✅ (Share Sheet) |

### 7.10 Plan de implementación

#### Fase 1: MVP funcional (1 sesión)

| Item | Descripción |
|------|-------------|
| `index.html` | Shell con Dashboard + Settings |
| `app.js` | Núcleo: IndexedDB init, fetch trackers, fetch schema, render form, queue, sync |
| `sw.js` | Service Worker con cache + offline intercept |
| `manifest.json` | Manifest para instalación |
| Formulario dinámico | Renderizar inputs desde schema (text, number, textarea, dropdown, file) |
| Guardar online | POST item directo a TikiWiki |
| Guardar offline | Queue en IndexedDB + badge |

**Al final**: Usuario puede ver trackers, llenar formularios, guardar online y offline.

#### Fase 2: UX completa (1 sesión)

| Item | Descripción |
|------|-------------|
| GPS | Botón "Usar ubicación" con `navigator.geolocation` |
| Fotos | Cámara + galería via `<input type="file" accept="image/*">` |
| Validaciones | Tipos, rangos, obligatoriedad en cliente |
| Sync automático | Disparar sync en evento `online` |
| Badge homescreen | `navigator.setAppBadge()` |
| Pills en header | Navegación rápida entre trackers |
| Whitelist | Ocultar/mostrar trackers en settings |

#### Fase 3: Refinamiento (1 sesión)

| Item | Descripción |
|------|-------------|
| Cifrado de token | Opcional, con PIN |
| Sincronización bidireccional | `modifiedSince` para recibir cambios de otros |
| Log de sync | Historial accesible desde el dashboard |
| Tema oscuro | `prefers-color-scheme: dark` |

---

## 8. Comparativa consolidada

| Criterio | C. Keywords | D. Msg único | E. ReplyKbd | F. /gather | A. Mini App | B. PWA *original* | **G. TikiPickIt** | H. Tiki local |
|----------|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Offline real** | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | **✅** | ✅ |
| **1 msg = 1 registro** | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | **✅** | ✅ |
| **Fotos/audio nativos** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **✅** | ✅ |
| **GPS nativo** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **✅** | ✅ |
| **Validaciones cliente** | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ | **✅** | ✅ |
| **Experiencia guiada** | ❌ | ❌ | Parcial | ✅ | ✅ | ✅ | **✅** | ✅ |
| **Sin frontend nuevo** | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | **~1 sesión** | ✅ |
| **Dentro de Telegram** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | **❌** | ❌ |
| **Dentro ecosistema Tiki** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | **✅** | ✅ |
| **Dev effort** | 30 min | ~1 sesión | ~1 sesión | ~2 sesiones | 5+ sesiones | 5-6 sesiones | **~2-3 sesiones** | Setup local |
| **Mantenimiento** | Bajo | Bajo | Bajo | Medio | Alto | Alto | **Medio** | Bajo |
| **Código existente** | 0% | 0% | 0% | 100% prototipo | 0% | 0% | **~50% (API + schema)** | 100% |
| **Estado actual** | No impl. | No impl. | No impl. | ✅ Prototipo | Descartado | No impl. | **Diseño completo** | Existe en Tiki |

---

## 9. Decisiones de arquitectura

### 9.1 TikiPickIt es standalone, no parte de trackerGram

| Decisión | Fundamento |
|----------|------------|
| TikiPickIt se conecta **directo a TikiWiki API** con Bearer token | trackerGram no necesita ser proxy de API cuando TikiWiki ya expone OAuth2 completo y CRUD REST |
| trackerGram y TikiPickIt son proyectos **independientes** | Resuelven problemas distintos: Telegram→TikiWiki vs formularios offline→TikiWiki |
| El mismo tracker puede recibir datos de ambos | Una conexión trackerGram y TikiPickIt pueden apuntar al mismo tracker sin conflicto |

### 9.2 Integración futura con trackerGram

Ambos pueden convivir en una misma UI:

```
trackerGram (admin.php)
  ├── Pestaña: Webhook
  ├── Pestaña: Importar
  └── Pestaña: 🆕 TikiPickIt (iframe o link)
```

- TikiPickIt se despliega como carpeta estática en el webroot de TikiWiki
- trackerGram agrega una pestaña que embebe o linkea a TikiPickIt
- Las credenciales pueden compartirse (el token de `setup.json` se precarga en TikiPickIt)
- Un tracker puede recibir datos del webhook de Telegram Y de formularios TikiPickIt

### 9.3 TikiWiki como fuente de verdad (bidireccional)

1. **Forward**: Bot wizard → crea campos en TikiWiki → TikiPickIt los lee
2. **Reverse**: Admin agrega campos en TikiWiki web → TikiPickIt detecta en próximo sync

### 9.4 Mapeo permName → nombre amigable

| permName | Nombre para usuario |
|----------|---------------------|
| `observacionPlantaPH` | pH |
| `observacionPlantaEspecie` | Especie |
| `observacionPlantaDiametroCopa` | Diámetro de copa |

Regla: Sacar prefijo + lowercase + CamelCase a espacios.

### 9.5 Estrategia de creación de trackers (pendiente)

| Opción | Cómo | Pros | Contras |
|--------|------|------|---------|
| **X — Manual** | Admin crea en TikiWiki web | Robusto, schema diseñado con cuidado | Requiere admin técnico |
| **Y — Wizard** | Bot guía en Telegram | Autoservicio | Frágil, muchas llamadas API |
| **Z — Híbrido** | Trackers base a mano, proyectos por wizard | Balance | Depende de definición |

### 9.6 CORS

Si TikiPickIt se sirve desde el mismo dominio que TikiWiki (ej: `wiki.chela.org.ar/tikipickit/`), no hay CORS. Recomendación MVP: servir desde el webroot de TikiWiki.

---

## 10. Historial de decisiones

| Fecha | Decisión | Fundamento |
|-------|----------|------------|
| 10/06/2026 | Descartar Mini App como solución primaria | Offline poco confiable (WebView sin SW) |
| 10/06/2026 | Elegir Enfoque D (mensaje único) como recomendado para Telegram | Mejor balance offline/esfuerzo/UX |
| 04/07/2026 | Diseñar PWA como alternativa offline-first | Offline real con IndexedDB + SW |
| 04/07/2026 | Diseñar `/gather` como experimento de UX guiada | Probar interacción paso a paso |
| 08/07/2026 | Implementar `/gather` (prototipo funcional) | Bugs corregidos, guardar persiste a TikiWiki |
| 08/07/2026 | Confirmar: `/gather` NO funciona offline | Telegram no encola callback_query |
| 08/07/2026 | Análisis de permisos: set mínimo cubre lectura de schemas | No se necesitan permisos adicionales |
| 08/07/2026 | Unificar documentos 002 + 007 en 008 | Centralizar decisiones y prerrequisitos |
| 09/07/2026 | Descubrir infraestructura PWA existente de TikiWiki | SW, cola offline, OAuth2, SyncController, modifiedSince |
| 09/07/2026 | **Elegir enfoque G (TikiPickIt) como solución principal** | TikiWiki ya tiene API + auth + schema. Dev effort: ~2-3 sesiones |
| 09/07/2026 | TikiPickIt es standalone, no vía trackerGram | trackerGram no necesita ser proxy de API |
| 09/07/2026 | Diseño primario de navegación: Dashboard + Pills | Mejor balance vista general / cambio rápido |
| 09/07/2026 | Unificar 008 + 010 en un solo documento | Estrategia y diseño son parte del mismo proceso |

---

## 11. Preguntas abiertas

### Estrategia general

- ⏳ **¿Convergencia?** TikiPickIt y trackerGram pueden alimentar el mismo tracker. ¿Tiene sentido como estrategia?
- ⏳ **OAuth2**: ¿Los usuarios del grupo de Telegram tienen cuenta en TikiWiki? Si no, ¿crearles una o usar token compartido?
- ⏳ **¿Flag "coleccionable"?** ¿Cómo marca el admin que un tracker es apto para TikiPickIt? ¿Convención de nombre? ¿Categoría? ¿Campo?
- ⏳ **¿Token por usuario o compartido?** Si el grupo comparte un token, todos ven los mismos trackers.

### Técnicas

- ⏳ **CORS**: Si TikiPickIt no se sirve desde el mismo dominio, ¿configuramos CORS o proxy?
- ⏳ **File upload offline**: Fotos como blobs en IndexedDB. ¿Tamaño máximo? ¿Compresión previa?
- ⏳ **Conflictos**: ¿Qué pasa si dos usuarios crean items offline y sincronizan? TikiWiki asigna itemId autoincremental, no debería haber conflicto en create. ¿Y en update?
- ⏳ **Estado real del PWA de TikiWiki**: ¿Está activo en wiki.chela.org.ar? ¿Hay que habilitar `pwa_feature`?

### Telegram offline

- ⏳ **Offline `/gather`**: ¿Adaptar a ReplyKeyboard para que funcione offline? ReplyKeyboard envía texto normal que Telegram sí encola. Trade-off: perderíamos botones inline.

### Ediciones y límites

- ⏳ ¿Cómo manejar ediciones a mensajes `!!!tipo` ya enviados? (edited_message → update item)
- ⏳ Límite de caption de 1024 caracteres con foto: ¿suficiente o planificar workaround?

---

## 12. Próximos pasos

### Inmediatos

1. ✅ **Documentar estrategia y diseño** (este documento)
2. ✅ **Descubrir infraestructura PWA existente de TikiWiki** (estudio de factibilidad)
3. ✅ **Elegir enfoque: TikiPickIt (G)** como solución principal de recolección offline
4. ⏳ **Decidir**: TikiPickIt + mensaje único Telegram (D) como complemento, o solo TikiPickIt
5. ⏳ Estudiar PWA existente de TikiWiki: estado real de `pwa_feature` en wiki.chela.org.ar

### Fase 1 — MVP (1 sesión)

6. ⏳ Crear carpeta `tikipickit/` con `index.html`, `app.js`, `sw.js`, `manifest.json`
7. ⏳ Implementar Settings: formulario de URL + token + probar conexión
8. ⏳ Implementar Dashboard: fetch trackers de API, mostrar tarjetas
9. ⏳ Implementar formulario dinámico: renderizar inputs desde schema de campos
10. ⏳ Implementar guardado online (POST directo a TikiWiki) + offline (cola IndexedDB)
11. ⏳ Probar contra wiki.chela.org.ar

### Fase 2 — UX (1 sesión)

12. ⏳ GPS, fotos, validaciones locales
13. ⏳ Sync automático al detectar online + badge
14. ⏳ Pills de navegación entre trackers
15. ⏳ Whitelist de trackers en settings

### Fase 3 — Refinamiento (1 sesión)

16. ⏳ Sincronización bidireccional con `modifiedSince`
17. ⏳ Cifrado de token, log de sync, tema oscuro

### Integración con trackerGram (futuro)

18. ⏳ Agregar pestaña TikiPickIt en admin.php
19. ⏳ Compartir credenciales entre trackerGram y TikiPickIt

---

## 13. Referencias

### Documentos relacionados

- `002-MiniApp.md` — Exploración original de Mini App (10/06/2026, consolidado en 008)
- `007-pwa-offline-formularios.md` — Diseño original de PWA (04/07/2026, consolidado en 008)
- `010-tikipickit-pwa-recoleccion.md` — Diseño detallado de TikiPickIt (09/07/2026, consolidado en 008)
- `999-a-tener-en-cuenta.md` — Seguridad en consultas a TikiWiki (vulnerabilidad SQLi)

### Archivos de TikiWiki relevantes

- `sw.js` — Service Worker existente (196 líneas)
- `lib/pwa/app.js` — Cola offline existente con Dexie
- `lib/pwa/offline.html` — Página de respaldo offline
- `lib/core/Services/ApiBridge.php` — Ruteo de API REST
- `lib/core/Services/Tracker/Controller.php` — Lógica de tracker en API
- `lib/core/Services/Tracker/SyncController.php` — Sincronización bidireccional (456 líneas)
- `lib/core/Services/Tracker/Tabular/Writer/JsonWriter.php` — Export JSON
- `lib/prefs/pwa.php` — Preference definitions para PWA

### APIs

- [TikiWiki API Documentation](https://doc.tiki.org/API)
- [MDN: Service Worker API](https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API)
- [MDN: IndexedDB API](https://developer.mozilla.org/en-US/docs/Web/API/IndexedDB_API)
- [W3C: Badging API](https://w3c.github.io/badging/)
- [Telegram Bot API](https://core.telegram.org/bots/api)
