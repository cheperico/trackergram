# AGENTS.md — TikiPickIt: Contexto para Agentes de IA

> **LECTURA OBLIGATORIA** antes de tocar cualquier código en `tikipickit/`.
>
> 🔐 **Postura de seguridad**: Este proyecto sigue los mismos estándares que trackerGram.
> Toda contribución debe mantener o mejorar las protecciones existentes.
> Ver [§9 Seguridad](#9-seguridad) para las reglas exactas.

---

## 1. ¿Qué es TikiPickIt?

PWA standalone para **carga de datos offline-first** en trackers de TikiWiki. Se conecta directamente a la API REST de TikiWiki (Bearer token), **sin pasar por trackerGram**.

**Problema que resuelve**: Recolectar datos estructurados en terreno (campo, obra, relevamiento) con o sin conexión. Los datos se guardan localmente en IndexedDB y se sincronizan cuando hay conectividad.

**Complementa a trackerGram**: trackerGram es el puente Telegram→TikiWiki para mensajes de chat; TikiPickIt es una herramienta de carga directa desde el browser/móvil.

**Estado**: MVP funcional en desarrollo activo.

> 📋 **Roadmap/Issues exclusivo de TikiPickIt**: `tikipickit/roadmap.md`
> Consular antes de arrancar cualquier feature nueva. Contiene items pendientes priorizados,
> decisiones de diseño y el estado de observaciones de code review.

---

## 2. Decisiones Arquitectónicas

### Sin framework
JavaScript vanilla (ES6+), CSS vanilla, sin dependencias externas. IndexedDB nativa, sin Dexie ni wrapper.

### Offline-first con IndexedDB
- Los datos se guardan localmente primero (cola de sincronización)
- Sincronización manual con botón "Sincronizar" o automática al reconectar
- Sin detección de cambios remotos (add-only, no edit/delete offline)

### Service Worker solo para lectura
- **GET assets**: cache-first (app shell)
- **GET API**: network-first, fallback a cache para respuestas 503 con `{ offline: true }`
- **POST/PUT/DELETE**: NUNCA interceptados por el SW (v2+). app.js maneja errores de red y encola offline.
- Cache solo respuestas GET exitosas (status 2xx)

### Conexión directa a TikiWiki API
- No usa trackerGram como middleware
- Bearer token en `Authorization` header
- Autenticación via token de API de TikiWiki (Admin → API Tokens)
- Token se persiste en localStorage para tokens manuales

### OAuth2 (Authorization Code Flow)
- **Flujo implementado**: Authorization Code + Refresh Token (sin PKCE — TikiWiki 27.5 no lo soporta)
- **Endpoints usados**:
  - `GET /api/oauth/authorize` — redirige al usuario para login + consentimiento
  - `POST /api/oauth/access_token` — canjea code por tokens, y refresca tokens
- **Almacenamiento**: Tokens en IndexedDB (prefs store), no en localStorage. Más seguro contra XSS.
- **Refresh automático**: Antes de cada `apiFetch()`, `oauth2EnsureValidToken()` verifica si el token expiró y lo refresca si es necesario. Margen de 2 minutos antes del expiry.
- **Client credentials**: El usuario crea un OAuth Client en Admin → OAuth Server con redirect_uri apuntando a la URL de TikiPickIt.
- **Dos modos**: `STATE.oauthMode = true/false` — el usuario puede usar token manual u OAuth2
- **State CSRF**: Se usa parámetro `state` con valor aleatorio almacenado en `sessionStorage` para prevenir ataques CSRF en el callback.

### TikiWiki API endpoints usados

| Endpoint | Método | Uso |
|---|---|---|
| `trackers` | GET | Listar trackers accesibles |
| `trackers/{id}/fields` | GET | Obtener schema de campos del tracker |
| `trackers/{id}/items` | POST | Crear item (form-urlencoded: `fields[permName]=valor`) |
| `galleries/upload` | POST | Subir archivo (multipart: `file` + `galleryId` query param) |
| `oauth/authorize` | GET | Inicio del flujo OAuth2 (redirect del browser) |
| `oauth/access_token` | POST | Canje de code por tokens; refresh de tokens |

### Concurrencia
- IndexedDB con **transacciones serializadas** (una operación de DB por llamada a función)
- `syncAll()` con mutex (`_syncing`) para evitar ejecución concurrente
- Cola de items procesada secuencialmente (uno por uno)

---

## 3. Estructura de Archivos

| Archivo | Rol | Líneas |
|---|---|---|---|
| `index.html` | SPA: 5 vistas (loading, settings, dashboard, form, errors) + toast + CSS inline | ~270 |
| `app.js` | Toda la lógica: IndexedDB, API calls, settings, dashboard, renderer de forms, envío, cola offline, sync, GPS, OAuth2, init | ~1400 |
| `sw.js` | Service Worker: cache-first para assets, network-first para GET API, ignora POST | 87 |
| `manifest.json` | Web App Manifest para instalación PWA | 21 |
| `.htaccess` | Apache: cache headers, HTTPS redirect, seguridad | 44 |
| `icons/icon-192.svg` | Icono vectorial 192x192 | — |
| `icons/icon-512.svg` | Icono vectorial 512x512 | — |
| `roadmap.md` | Issues y roadmap exclusivo de TikiPickIt | ~220 |
| `README.md` | Documentación de usuario (markdown) | 242 |
| `README.html` | Misma documentación en HTML (servida al browser) | — |

> ⚠️ **README dual**: `README.md` es la fuente de verdad para la documentación de usuario.
> `README.html` es una conversión a HTML del mismo contenido (se sirve al navegador).
> **Siempre que se modifique `README.md`, hay que actualizar `README.html` también.**

### Orden recomendado de lectura

1. `index.html` → estructura de vistas y estilos
2. `app.js` en orden de secciones:
   - State (línea 6)
   - IndexedDB helpers (línea 41)
   - API REST calls (línea 122)
   - Settings (línea 176)
   - Dashboard (línea 252)
   - Form renderer (línea 333)
   - Form submission (línea 490)
   - Offline queue (línea 591)
   - GPS (línea 711)
   - Init (línea 779)
3. `sw.js` → estrategia de cache

---

## 4. Flujo de Datos

### Carga inicial
```
init()
  → loadPrefs()    (IndexedDB prefs)
  → loadTrackers() (GET /api/trackers)
  → loadSchemas()  (GET /api/trackers/{id}/fields, lazy)
  → renderDashboard() o openForm(lastTrackerId)
  → syncAll() si hay items pendientes y online
```

### Guardar item (online)
```
submitForm()
  → validar campos obligatorios
  → convertir fechas a timestamp UNIX
  → saveItem()
    → uploadFile() (GET /api/galleries/upload, si hay FG)
    → createItem() (POST /api/trackers/{id}/items)
    → toast éxito
    → si falla: queueItem() (encola offline)
```

### Guardar item (offline)
```
submitForm()
  → validar
  → saveItem() detecta !STATE.online
  → queueItem() (guarda en IndexedDB + STATE.queue array)
  → toast "📦 Guardado offline"
```

### Sincronización
```
syncAll()
  → mutex _syncing (no ejecutar concurrentemente)
  → process(0) secuencial
    → processItem(item)
      → createItem() (re-intenta)
      → si ok: dbDelete('queue', item.id)
      → si error y retries < 3: dbPut('queue', item) + retry
      → si error y retries >= 3: dbPut('synclog', item) + descarta
  → al terminar: renderDashboard() + toast
```

### Auto-sync al reconectar
```
window.addEventListener('online')
  → STATE.online = true
  → if queue.length > 0: syncAll()
```

---

## 5. Tipos de Campo Soportados

| Tipo TikiWiki | Input HTML | Notas |
|---|---|---|
| `t` (text) | `<input type="text">` | Default para tipos no reconocidos |
| `a` (textarea) | `<textarea>` | — |
| `n` (number) | `<input type="number">` | Soporta min, max, decimals de options |
| `f` (datetime) | `<input type="datetime-local">` | Se convierte a UNIX timestamp al enviar |
| `j` (datetime) | `<input type="datetime-local">` | Idem `f` |
| `D` (date) | `<input type="date">` | Se convierte a UNIX timestamp al enviar |
| `d` (dropdown) | `<select>` | Options desde `f.options` |
| `F` (freetags) | `<input type="text">` | Tags separados por espacio |
| `G` (geolocation) | Botón GPS + hidden input | Formato `lon,lat,zoom` |
| `FG` (file gallery) | `<input type="file">` | Sube a galleryId, guarda fileId |
| `r` (item link) | `<input type="text">` | ID numérico del item referenciado |
| `M` (multilingual) | `<input type="text">` | Se renderiza como text default |

**No soportados aún**: `h` (header), `s` (static text), `u` (user selector), `e` (email), `y` (country selector), `w` (webservice).

---

## 6. IndexedDB Schema

Base: `tikipickit` (v1)

| Store | keyPath | Propósito |
|---|---|---|
| `trackers` | `trackerId` | Cache de lista de trackers |
| `schemas` | `trackerId` | Cache de fields por tracker (con `lastFetched`) |
| `queue` | `id` (autoIncrement) | Items pendientes de sincronizar |
| `synclog` | `id` (autoIncrement) | Historial de sync (éxitos y fallos) |
| `prefs` | `key` | Preferencias del usuario |
| `trackerMeta` | `trackerId` | Metadata offline por tracker |

---

## 7. Estado Conocido y Deuda Técnica

### Bugs conocidos
- **B2 (aceptado)**: Files perdidos en resync offline — el objeto `File` del browser no se serializa a IndexedDB. Al sincronizar, los campos FG se guardan sin archivo. Toast informativo mostrado.

### Funcionalidades implementadas recientemente
- **Vista de errores (T1)**: Vista con synclog visible, botones "Reintentar" individual y "Reintentar todos"
- **Back-off exponencial (T2)**: `withRetry()` wrapper con 500ms base + jitter, usado en saveItem y processItem
- **Orden FIFO (T3)**: Cola ordenada por createdAt antes de procesar
- **GC IndexedDB (T4)**: Limpieza automática de synclog >30 días y queue stale >7 días
- **Loading spinner (T5)**: Vista de carga visible mientras init() resuelve trackers y schemas
- **OAuth2 state expiry (T6)**: State anti-CSRF con expiración de 3 minutos
- **Rate limit configurable (T8)**: Intervalo entre syncs configurable desde settings (1-60s, default 3s)
- **Retries visibles (T9)**: Badge muestra cantidad de reintentos fallidos
- **Token manual con expiración (7 días)**: El token manual se borra solo tras 7 días. Constante `MANUAL_TOKEN_TTL` en app.js. Timestamp `tp_token_created` en localStorage.

### Bugs corregidos
- **B4 (corregido vía OAuth2)**: Token en `localStorage` en texto plano. Con OAuth2 los tokens se guardan en IndexedDB (prefs) con expiry de 1 hora y refresh automático. El token manual sigue siendo opcional y ahora expira a los 7 días.

### Limitaciones MVP
- No hay edición offline de items existentes (solo creación)
- No hay detección de cambios remotos (add-only)
- No hay soporte para arrays/múltiples valores por campo
- Cache de schemas no expira automáticamente (refresh manual desde configuración)
- Sin tests automatizados

### No implementado aún (futuro)
- Sincronización bidireccional (vía `SyncController` de TikiWiki + `modifiedSince`)
- Store de blobs de archivos en IndexedDB (T10)
- Pills de navegación rápida entre trackers (más de 3 recientes → dropdown)
- Export offline de datos
- Tests automatizados (T11)

---

## 8. Reglas para Agentes de IA

### Convenciones de código
- **Sin dependencias externas**: JS vanilla, sin npm, sin Composer, sin frameworks
- **Sin módulos ES**: Los archivos se cargan con `<script>` en el HTML, no con `import`
- **Sin transpilación**: ES6+ moderno que corre nativamente en Chrome/Firefox/Safari móvil
- **Funciones helpers cortas**: Una responsabilidad por función
- **Variables globales mínimas**: `STATE`, `_db`, `_syncing`
- **Comentarios con secciones**: `/* ===== 1. SECCIÓN ===== */` para navegación rápida
- **Usar `esc()` para todo output HTML**: XSS prevention
- **DOM references via `$()`**: `const $ = id => document.getElementById(id)`

### Reglas de IndexedDB
- **No abrir múltiples transacciones concurrentes** sobre el mismo store (puede deadlock en algunos browsers)
- Cada helper de DB (`dbPut`, `dbGet`, etc.) abre su propia transacción y cierra la DB al terminar
- Cachear conexión en `_db` para evitar re-aperturas (lazy init en `dbOpen()`)

### Reglas del Service Worker
- **POST/PUT/DELETE**: NUNCA interceptar. Dejar que app.js maneje errores naturalmente → queueItem
- **GET API**: network-first, cachear solo respuestas exitosas (status 2xx)
- **GET assets**: cache-first para app shell (HTML, JS, CSS, icons, manifest)
- Precache al instalar: assets básicos de la app

### Reglas de GPS
- TikiWiki espera **lon,lat,zoom** (longitud, latitud, nivel de zoom)
- `navigator.geolocation` devuelve `latitude, longitude` — invertir al guardar

### Reglas de Fechas
- TikiWiki espera timestamps **UNIX en segundos**
- Los inputs HTML5 `datetime-local`/`date` devuelven strings ISO — convertir con `new Date(val).getTime() / 1000`
- Tipos: `f`, `j`, `D`

### Reglas de la API de TikiWiki
- **Usar `application/x-www-form-urlencoded`** para crear items, no JSON
  - `fields[permName]=valor` en el body
- **File Gallery upload**: multipart con query param `galleryId`
  - `POST /api/galleries/upload?galleryId={id}`
- **Endpoint de fields**: `GET /api/trackers/{id}/fields` (NO `/api/trackers/{id}` que devuelve items)
- **Bearer token en header**: `Authorization: Bearer {token}`
- Los errores HTTP pueden devolver texto plano o HTML — truncar a 200 chars

### Reglas de OAuth2
- **Endpoints**:
  - `GET /api/oauth/authorize?response_type=code&client_id=...&redirect_uri=...&scope=basic&state=...`
  - `POST /api/oauth/access_token` (form-urlencoded): canjea code, refresca tokens
- **TTL**: Access token 1 hora, Refresh token 1 mes
- **State anti-CSRF**: Generar random string, guardar en `sessionStorage`, validar al callback
- **Redirect URI**: Debe coincidir EXACTAMENTE con la registrada en el OAuth Client de TikiWiki
- **No requiere PKCE**: TikiWiki 27.5 no soporta PKCE grant
- **Refresh automático**: `oauth2EnsureValidToken()` se llama antes de cada `apiFetch()` si `STATE.oauthMode=true`. Refresca si quedan < 2 minutos de vida.

### File Gallery
- El `galleryId` se extrae de `f.options` (JSON con formato `[{"name":"galleryId","value":"123"}]`)
- Si un campo FG no tiene `galleryId`, se muestra warning y se omite

---

## 9. Historial de Cambios

### 10/07/2026 — Features alta prioridad + media (T1-T9)

| ID | Tipo | Descripción | Archivo |
|----|------|-------------|---------|
| T1 | 🆕 Feature | Vista de errores con synclog + reintentar items individual/todos | `app.js`, `index.html` |
| T2 | 🚀 Mejora | Back-off exponencial en retry de API calls (withRetry) | `app.js` |
| T3 | 🚀 Mejora | Cola ordenada por createdAt (FIFO) | `app.js` |
| T4 | 🚀 Mejora | GC de IndexedDB (synclog >30d, queue stale >7d) | `app.js` |
| T5 | 🚀 Mejora | Loading spinner durante init() | `index.html`, `app.js` |
| T6 | 🛡️ Seguridad | OAuth2 state con expiry de 3 min en sessionStorage | `app.js` |
| T7 | 🐛 Fix | PNG icons faltantes reemplazados por SVGs en manifest | `manifest.json`, `icons/` |
| T8 | 🚀 Mejora | Rate limit configurable (1-60s, default 3s) | `index.html`, `app.js` |
| T9 | 🚀 Mejora | Retries visibles en badge del dashboard | `app.js` |
| — | 🛡️ Seguridad | Token manual con expiración de 7 días (tp_token_created) | `app.js` |
| — | 📋 Doc | Creado roadmap.md exclusivo de TikiPickIt | `roadmap.md` |

### 09/07/2026 — Security hardening (v4)

Hardening de seguridad siguiendo estándares de trackerGram. Auditoría completa y 10+ fixes.

| ID | Severidad | Hallazgo | Fix |
|----|-----------|----------|-----|
| S1 | 🔴 Crítico | `opts.min`/`opts.max` sin `esc()` en renderFields (XSS) | `esc(String(...))` agregado |
| S2 | 🔴 Crítico | `gOpts.galleryId` sin `esc()` en hidden input (XSS) | `esc(String(...))` agregado |
| S3 | 🟡 Alta | Sin CSP, X-Frame-Options, X-Content-Type-Options, HSTS | Agregados todos los security headers al `.htaccess` |
| S4 | 🟡 Alta | Errores de API exponen cuerpo de respuesta (info leakage) | Mensajes genéricos por código HTTP, body solo a console |
| S5 | 🟡 Alta | URL de TikiWiki no validada (aceptaba HTTP, no-URLs) | `isValidTikiUrl()` exige HTTPS |
| S6 | 🟡 Alta | `tId` en Item Link sin `esc()` (XSS desde schema) | `esc(String(...))` agregado |
| S7 | 🟡 Alta | OAuth2 state con `Math.random()` (predecible) | `crypto.getRandomValues()` (CSPRNG) |
| S8 | 🟡 Alta | SW intercepta requests a cualquier origen | `isOwnOrigin()` check en SW |
| S9 | 🟡 Media | Sin rate limiting en syncAll (posible DoS) | Mínimo 3s entre syncs |
| S10 | 🟢 Baja | manifest.json sin `"scope"` | Agregado `"scope": "."` |

### 09/07/2026 — OAuth2 + Refresh Token (v3)

Implementado flujo OAuth2 Authorization Code + Refresh Token para autenticación sin token manual.

| ID | Tipo | Descripción | Archivo |
|----|------|-------------|---------|
| O1 | 🆕 Feature | `oauth2Login()` — redirect a authorize endpoint con state CSRF | `app.js` |
| O2 | 🆕 Feature | `oauth2HandleCallback()` — captura code del callback URL | `app.js` |
| O3 | 🆕 Feature | `oauth2ExchangeCode()` — canjea code por access + refresh tokens | `app.js` |
| O4 | 🆕 Feature | `oauth2RefreshToken()` — refresh automático (1 hora TTL, 30 días refresh) | `app.js` |
| O5 | 🆕 Feature | `oauth2EnsureValidToken()` — chequea validez antes de cada apiFetch | `app.js` |
| O6 | 🆕 Feature | `oauth2Logout()` — limpia tokens y vuelve a settings | `app.js` |
| O7 | 🆕 Feature | Settings UI con campos Client ID/Secret + botones Login/Logout | `index.html` |
| O8 | 🛡️ Seguridad | Tokens OAuth2 guardados en IndexedDB (prefs), no en localStorage | `app.js` |
| O9 | 🛡️ Seguridad | Parámetro `state` con `sessionStorage` anti-CSRF en callback | `app.js` |
| B4 | ✅ Corregido | Token en localStorage mitigado — OAuth2 usa IndexedDB + refresh | `app.js` |

### 09/07/2026 — Code review fixes (v2 del código)

| ID | Severidad | Descripción | Archivo |
|----|-----------|-------------|---------|
| C1 | 🔴 Crítico | XSS en `renderWhitelist()` — `t.name` sin escapar | `app.js` |
| C2 | 🔴 Crítico | SW devolvía falso éxito en POST offline (data loss) | `sw.js` (reescrito) |
| C3 | 🔴 Crítico | GPS lat/lng invertidos (TikiWiki espera lon,lat) | `app.js` |
| C4 | 🟡 Alto | Fechas sin convertir a timestamp UNIX | `app.js` |
| B1 | 🟡 Medio | `syncAll()` sin mutex — ejecución concurrente | `app.js` |
| B3 | 🟡 Medio | `dbPut` en `queueItem()` sin `.catch()` | `app.js` |
| B5 | 🟡 Medio | `apiFetch()` sin timeout (30s default con AbortController) | `app.js` |
| B6 | 🟢 Bajo | Dead code: `case 'D'` en dropdown (inalcanzable) | `app.js` |
| B7 | 🟢 Bajo | Conexión IndexedDB no cacheada | `app.js` |

---

## 9. Seguridad

TikiPickIt sigue los mismos estándares que trackerGram. Las siguientes protecciones están implementadas y deben mantenerse:

### 9.1 XSS Prevention

| Regla | Implementación |
|---|---|
| **Siempre usar `esc()`** en todo `${}` de template literals que contengan datos de usuario, API, o schema | Función `esc()` en app.js (línea ~1025) |
| **NUNCA usar `innerHTML`** con datos no sanitizados | Auditado: todos los `innerHTML` usan `esc()` o son strings estáticos |
| **`textContent`** para mostrar datos de usuario (archivos, errores, coordenadas) | Usado en showStatus, toast, gps-coords, file-name |

### 9.2 OWASP Top 10 mitigaciones

| Riesgo | Mitigación |
|---|---|
| **A1 - Injection** | Todos los inputs de API escapados con `esc()`. OAuth2 state con CSPRNG (`crypto.getRandomValues`). |
| **A2 - Broken Auth** | OAuth2 con refresh token (1h + 1mes). State anti-CSRF en `sessionStorage`. |
| **A3 - Data Exposure** | Errores de API sanitizados (códigos HTTP con mensajes genéricos). Body completo solo a console.error. |
| **A5 - Broken Access Control** | URL validada como HTTPS. Token manual opcional. OAuth2 como modo recomendado. |
| **A6 - Misconfiguration** | CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy en `.htaccess`. |
| **A7 - XSS** | Ver §9.1. CSP bloquea inline scripts y eval. `frame-ancestors 'none'` previene clickjacking. |
| **A9 - Known Vulns** | Sin dependencias externas (JS vanilla, sin npm). Service Worker no intercepta POST. |

### 9.3 Reglas de hardening

1. **URL validation**: `isValidTikiUrl()` exige `https:` (o `localhost`/`127.0.0.1` para desarrollo).
2. **Error sanitization**: `_apiFetch()` nunca muestra el cuerpo de la respuesta HTTP al usuario. Usa mensajes genéricos por código de estado.
3. **Rate limiting**: `syncAll()` no se ejecuta más de una vez cada N ms (default 3000, configurable desde settings).
4. **SW scope**: SW solo intercepta requests al mismo origen (`isOwnOrigin()`). No intercepta POST/PUT/DELETE.
5. **CSP**: `default-src 'self'`, `connect-src 'self' https:`, `frame-ancestors 'none'`, `script-src 'self'`.
6. **HSTS**: 1 año + subdominios.
7. **X-Frame-Options**: `DENY` — no se puede embeder en iframes.
8. **Permissions-Policy**: Solo geolocation y camera permitidas (cuando el usuario interactúa).
9. **OAuth2 state**: Generado con `crypto.getRandomValues()` (CSPRNG), no `Math.random()`.
10. **Token storage**: OAuth2 tokens en IndexedDB (prefs). Token manual en localStorage (opcional, documentado como deuda).

### 9.4 Lo que NO hacer

- **No usar `Math.random()`** para valores de seguridad (state, nonces, etc.) — usar `crypto.getRandomValues()`
- **No exponer cuerpos de respuesta HTTP** en la UI — sanitizar por código de estado
- **No confiar en datos de `f.options`** (schema) sin `esc()` — puede contener XSS
- **No permitir HTTP** en la URL de TikiWiki — solo HTTPS en producción
- **No interceptar POST/PUT/DELETE** en el Service Worker — data loss
- **No cachear respuestas HTTP no exitosas** en el SW — datos corruptos
- **No usar `innerHTML`** sin `esc()` — usar `textContent` cuando sea posible

---

## 10. Referencias

### Documentos de diseño
- `design/008-estrategia-recoleccion-estructurada.md` — Estrategia unificada de recolección offline
- `design/archived/002-MiniApp.md` — Exploración de Mini App (descartada)
- `design/archived/007-pwa-offline-formularios.md` — Diseño original PWA
- `design/archived/010-tikipickit-pwa-recoleccion.md` — Diseño detallado TikiPickIt (consolidado en 008)

### Código relacionado en trackerGram
- `TikiWikiClient.php` — `loadTrackerFields()`, `testConnection()`, `checkPermissions()`
- `TECHNICAL.md` — Arquitectura general del ecosistema

### APIs
- [TikiWiki API](https://doc.tiki.org/API)
- [MDN: IndexedDB](https://developer.mozilla.org/en-US/docs/Web/API/IndexedDB_API)
- [MDN: Service Worker](https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API)
- [MDN: Geolocation API](https://developer.mozilla.org/en-US/docs/Web/API/Geolocation_API)
