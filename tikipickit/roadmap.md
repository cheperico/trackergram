# TikiPickIt — Roadmap / Issues

> Archivo exclusivo de `tikipickit/`. Las features que involucran trackerGram van en
> `trackergram/roadmap.md`. Este es solo para TikiPickIt.

---

## 🔴 ~~Crítico: OAuth2 sin refresh token offline prolongado~~ ✅ Resuelto

**Análisis**: El refresh token de League OAuth2 Server **rota** (se emite uno nuevo cada vez que se refresca). El viejo se revoca. El TTL del nuevo es `ahora + 1 mes`. Por lo tanto:

- Si el usuario se conecta al menos 1 vez al mes, el refresh token **nunca expira** (sliding window)
- El único caso problemático es >30 días **sin ninguna conexión** a TikiWiki
- Para ese caso de borde, el **token manual con expiración de 7 días** sirve como respaldo
- Items offline nunca se pierden — quedan en la cola y se reintentan al re-autenticar

**Decisión**: No implementar el híbrido. El riesgo es bajo y está cubierto por:
- Token manual con 7 días de expiración (implementado)
- Vista de errores permite reintentar items fallidos (T1 implementado)
- El mensaje de error de OAuth2 refresh fallido es claro

---

## 🟡 Alta prioridad

### T1 — Vista de errores y reintento manual
- **Qué**: Mostrar `synclog` en una vista accesible desde el dashboard. Cada item fallido
  tiene botón "Reintentar" individual y "Reintentar todos".
- **Por qué**: Hoy los errores se registran en IndexedDB y desaparecen. El usuario no tiene
  visibilidad ni control sobre items que fallaron.
- **Tags**: `ux`, `sync`, `mvp`

### T2 — Back-off exponencial en retry de API calls
- **Qué**: En vez de 3 reintentos fijossin pausa, usar back-off exponencial (500ms, 1s, 2s, 4s...)
  con jitter aleatorio.
- **Ubicación**: `saveItem()` catch + `processItem()` catch
- **Por qué**: Reduce carga en servidor TikiWiki ante errores transitorios. Mejora chances de éxito.
- **Tags**: `performance`, `sync`

### T3 — Ordenar cola por `createdAt`
- **Qué**: En `syncAll()`, ordenar `STATE.queue` por `item.createdAt` ascendente antes de procesar.
- **Por qué**: Hoy se procesa en orden de inserción en el array (que depende de cómo se cargó desde
  IndexedDB). No hay garantía de orden FIFO.
- **Tags**: `sync`, `data-integrity`

### T4 — GC de IndexedDB
- **Qué**: Limpieza automática de datos viejos:
  - `synclog` > 30 días: borrar
  - `queue` con `retries >= 3` y > 7 días: borrar
  - `schemas` sin usar > 7 días: refrescar
- **Cuándo**: Al iniciar (`init()`) y después de cada sync exitoso
- **Por qué**: IndexedDB no tiene TTL. Los datos acumulados ocupan espacio en dispositivos móviles.
- **Tags**: `maintenance`, `storage`

### T5 — Loading spinner global en init
- **Qué**: Mientras `init()` ejecuta los `Promise.all` (loadPrefs, loadTrackers, loadSchemas, loadQueue),
  mostrar un spinner/skeleton en vez de pantalla en blanco.
- **Por qué**: Con trackers lentos o muchas conexiones, la UI se ve congelada.
- **Tags**: `ux`

---

## 🟢 Media prioridad ✅ Completado

| Item | Estado |
|------|--------|
| **T6** — OAuth2 state con timestamp y expiry | ✅ 10/07/2026 |
| **T7** — Icons SVG para manifest (reemplazó PNGs inexistentes) | ✅ 10/07/2026 |
| **T8** — Rate limit configurable (1-60s, default 3s) | ✅ 10/07/2026 |
| **T9** — Retries visibles en badge del dashboard | ✅ 10/07/2026 |

---

## ⚪ Baja prioridad / Futuro

### T10 — Blobs de archivos en IndexedDB (B2 enhancement)
- **Qué**: Guardar el contenido de archivos (fotos, videos) como `Blob` en IndexedDB para poder
  re-subirlos al sincronizar. Hoy los items con FG se guardan offline **sin archivo**.
- **Desafío**: `File` y `Blob` no se serializan naturalmente a IndexedDB. Requiere usar `FileReader`
  para leer como `ArrayBuffer` y store. Límite de storage en móviles (5-50MB).
- **Tags**: `files`, `offline`, `storage`

### T11 — Tests automatizados
- **Qué**: Jest + jsdom para lógica (queueItem, saveItem, validaciones) y Cypress/Playwright para
  flujos PWA completos (offline → online → sync).
- **Tags**: `quality`, `tests`

### T12 — Modularización del código
- **Qué**: Extraer `app.js` en módulos: `dom.js` (UI helpers), `api.js` (REST calls), `queue.js`
  (cola offline + sync), `oauth2.js` (OAuth2 flow), `gps.js`.
- **Por qué**: 1200 líneas en un archivo es mantenible pero al llegar a 2000+ empieza a doler.
- **Tags**: `refactor`, `quality`

### T13 — i18n (internacionalización)
- **Qué**: Centralizar todos los strings visibles en un objeto `i18n`. Hoy hay strings en español
  hardcodeadas en templates, validaciones y toasts.
- **Tags**: `i18n`, `ux`

### T14 — Accesibilidad ARIA
- **Qué**: Roles, aria-label, focus management, contraste, prefers-reduced-motion.
- **Tags**: `a11y`, `ux`

---

## ✅ Ya cubierto (del code review externo)

Items que el code review señaló pero que **ya están implementados o no aplican**:

| Observación | Estado | Detalle |
|-------------|--------|---------|
| XSS en `innerHTML` datos externos | ✅ Implementado | Todos los `innerHTML` pasan por `esc()`. Fijado en S1-S6 del hardening. |
| SW intercepta POST (data loss) | ✅ Implementado | SW ignora POST/PUT/DELETE desde v2 (C2). |
| GPS lat/lng invertidos | ✅ Implementado | Fijado en C3 (lon,lat al guardar). |
| Fechas sin timestamp UNIX | ✅ Implementado | Fijado en C4 (conversión en submitForm). |
| CSP / security headers | ✅ Implementado | `.htaccess` con CSP, HSTS, X-Frame-Options, etc. (S3). |
| Error sanitization | ✅ Implementado | Mensajes genéricos por código HTTP, body solo a console (S4). |
| Token OAuth2 en IndexedDB | ✅ Implementado | OAuth2 tokens en prefs store (O8). |
| OAuth2 state CSPRNG | ✅ Implementado | `crypto.getRandomValues()` (S7). |
| Rate limiting 3s | ✅ Implementado | `_lastSyncTime` (S9). |
| redirectUri correcta | ✅ No aplica | Usa `window.location.origin + window.location.pathname` que es exactamente lo que OAuth2 necesita. |
| `prefer_related_applications` | ❌ No implementar | En PWA standalone, ese flag redirige a Play Store. No tenemos app nativa. |
| DOMContentLoaded doble init | ❌ No ocurre | `init()` se llama una sola vez desde dentro de `DOMContentLoaded`. No hay doble registro. |

---

## 📐 Decisiones de diseño

### 09/07/2026 — Híbrido OAuth2 + Token manual

**Problema**: Token manual en `localStorage` es accesible a extensiones/XSS (B4).
OAuth2 es más seguro pero sus refresh tokens expiran, rompiendo offline prolongado.

**Decisión**: Mantener ambos modos. OAuth2 como default recomendado, token manual como
fallback automático cuando el refresh token expira mientras se está offline.

**Trade-off**: El token manual sigue siendo menos seguro que OAuth2 (no expira).
Mitigación: moverlo de `localStorage` a `IndexedDB`, mismo store que OAuth2.

**Alternativa descartada**: OAuth2-only. Riesgo de perder items offline por refresh expirado
es incompatible con el propósito de TikiPickIt.

### 10/07/2026 — Token manual con expiración de 7 días

**Problema**: El token manual en localStorage nunca expiraba. Si un atacante lo obtenía (XSS,
extensión maliciosa), tenía acceso permanente a la API de TikiWiki con los permisos del token.

**Decisión**: El token manual ahora expira a los **7 días** de haber sido ingresado. Al expirar,
se borra solo de localStorage y el usuario ve el campo vacío para ingresar uno nuevo.
Los items offline **no se pierden** — quedan en la cola.

**Por qué 7 días**: Si el usuario usa OAuth2 (default recomendado), el token manual no se necesita.
Solo se usa como fallback (ej: >30 días sin conexión, o TikiWiki sin OAuth Client configurado).
7 días es suficiente para resolver el problema puntual sin riesgo de exposición prolongada.

**Trade-off**: El usuario debe tener el token guardado (gestor de contraseñas, Telegram) para
re-ingresarlo cada 7 días si no usa OAuth2.
