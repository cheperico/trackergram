# TikiPickIt — Roadmap / Issues

> Archivo exclusivo de `tikipickit/`. Las features que involucran trackerGram van en
> `trackergram/roadmap.md`. Este es solo para TikiPickIt.

---

## 🔴 Crítico: OAuth2 sin refresh token offline prolongado

### Problema
El flujo OAuth2 usa:
- **Access token**: expira en 1 hora (configurable en TikiWiki vía League OAuth2 Server)
- **Refresh token**: expira típicamente en 30 días (configurable)

Si el usuario está offline más tiempo del que dura el **refresh token**, al volver online:

```
syncAll()
  → apiFetch()
    → oauth2EnsureValidToken()
      → access token expired → oauth2RefreshToken()
        → refresh token también expiró → ERROR
        → oauth2ClearTokens()
        → throw "Sesión expirada. Iniciá sesión nuevamente."
```

**Resultado**: items offline **no se sincronizan** hasta que el usuario re-autentique vía OAuth2.
Esto contradice el propósito fundamental de TikiPickIt: recolectar datos en terreno con
conectividad impredecible.

### Riesgo real
- Si el refresh token expira en 30 días: usuario de campo en zona remota por >30 días pierde
  la capacidad de sincronizar hasta volver a autenticar
- Si el refresh token expira en 7 días (configuración conservadora): el problema es más probable

### Solución propuesta
**Híbrido**: OAuth2 como default + token manual como fallback cuando OAuth2 falla.

```
apiFetch()
  → intentar OAuth2 (refresh automático si expiró)
    → si falla (refresh expirado):
      → caer a token manual (si existe)
      → marcar en UI: "⚠️ OAuth2 expirado — sincronizando con token manual"
    → si también falla:
      → marcar item como "requiere re-login"
      → toast permanente "⛔ Iniciá sesión OAuth2 de nuevo"
```

**Además**:
- Mover token manual de `localStorage` a `IndexedDB` (misma store que prefs OAuth2)
- Mostrar en UI cuál método se usó en la última sincronización
- Al re-autenticar OAuth2, reintentar items fallidos automáticamente

**Tags**: `oauth2`, `offline`, `blocker`

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

## 🟢 Media prioridad

### T6 — OAuth2 state con timestamp y expiry
- **Qué**: El `state` anti-CSRF ya usa CSPRNG. Agregar timestamp de expiración (3 min desde generación)
  y validarlo en el callback. Si expiró, mostrar error y no intercambiar el code.
- **Ubicación**: `oauth2BuildAuthorizeUrl()` + `oauth2HandleCallback()`
- **Por qué**: Defensa en profundidad. Un atacante que intercepte el `state` antes de la redirección
  tiene una ventana de oportunidad.
- **Tags**: `security`, `oauth2`

### T7 — PNG icons para manifest.json
- **Qué**: `manifest.json` referencia `icon-192.png` y `icon-512.png` que no existen en el repo
  (solo hay SVG). Generar PNGs desde el SVG o reemplazar las referencias por SVG.
- **Tags**: `pwa`, `manifest`

### T8 — Rate limit configurable (token bucket)
- **Qué**: Hoy el rate limit de sync es un hardcoded `3000ms`. Hacerlo configurable desde settings
  o usar un token bucket que permita bursts de 2-3 items.
- **Tags**: `sync`, `ux`, `performance`

### T9 — `retries` visibles en cada item de la cola
- **Qué**: En el badge de pendientes del dashboard, mostrar no solo cantidad de items sino también
  cuántos tienen intentos fallidos vs nuevos.
- **Tags**: `ux`, `sync`

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
