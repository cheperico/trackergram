# 007 — PWA offline para cargas estructuradas en tracker

> **Fecha**: 04/07/2026
> **Estado**: Exploración / Diseño preliminar
> **Tags**: pwa, offline, formularios, recolecta, mobile, tracker

---

## Propósito

trackerGram tiene dos mecanismos de entrada de datos:

- **Pasivo**: Webhook de Telegram — captura mensajes de un grupo en tiempo real
- **Activo**: `/gather` — formulario interactivo dentro de Telegram con inline keyboards

Ambos requieren **conectividad permanente** y **estar dentro de Telegram**. Esto es una limitación cuando:

1. El usuario está en una zona sin señal (campo, sierra, interior de un edificio)
2. El usuario no tiene Telegram o no quiere usarlo para cargar datos
3. Se necesita llenar múltiples formularios en lote (relevamiento, monitoreo, censo)

**Alternativa explorada**: en lugar de embeber el formulario en Telegram (Mini App), ofrecerlo como **PWA independiente** que funciona offline y sincroniza cuando hay conexión.

---

## Arquitectura propuesta

```
┌─────────────────────────────────────────┐
│              PWA (browser)              │
│  ┌──────────────────────────────────┐  │
│  │        Service Worker            │  │
│  │  Cache: HTML+CSS+JS+manifest    │  │
│  └──────────────────────────────────┘  │
│  ┌──────────────────────────────────┐  │
│  │      IndexedDB / localStorage    │  │
│  │  ┌──────┐ ┌──────┐ ┌──────────┐ │  │
│  │  │Forms │ │Queue │ │SyncLog  │ │  │
│  │  └──────┘ └──────┘ └──────────┘ │  │
│  └──────────────────────────────────┘  │
└──────────────┬──────────────────────────┘
               │ sync cuando hay conexión
               ▼
┌─────────────────────────────────────────┐
│         trackerGram API (api.php)        │
│   auth → NormalizedMessage → toWikiFields│
│   → TikiWikiClient::createTrackerItem() │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│         TikiWiki (tracker)               │
│   Almacenamiento final                   │
└─────────────────────────────────────────┘
```

### Componentes nuevos (lado PWA)

| Archivo | Qué hace |
|---------|----------|
| `pwa/index.html` | Entry point de la PWA — login + lista de formularios |
| `pwa/app.js` | Lógica offline-first: queue, sync, IndexedDB |
| `pwa/sw.js` | Service Worker: cachea la app, intercepta fetch |
| `pwa/manifest.json` | Web app manifest para homescreen |
| `pwa/form-editor.html` | Editor visual de campos del formulario (opcional) |

### Backend (trackerGram existente, cambios mínimos)

| Archivo | Cambio |
|---------|--------|
| `api.php` | Nuevo endpoint `submit_form` que recibe JSON, crea `NormalizedMessage`, manda a TikiWiki |
| `api.php` | Endpoint `get_tracker_fields` que devuelve la definición de campos de un tracker (para que la PWA sepa qué formulario renderizar) |
| Opcional: new file | Auth simple por token para la PWA (independiente del admin) |

---

## Flujo offline

### 1. Usuario abre la PWA

- Sirve desde browser o homescreen
- Service Worker cachea todo en la primera carga (App Shell pattern)
- Sin conexión: carga desde cache — misma experiencia

### 2. Usuario carga datos

- Llena el formulario con los campos que definió
- Fotos: se guardan como `dataURL` en IndexedDB (o mejor: se toman con `<input type="file">` y se guarda el File en IndexedDB via `FileReader`)
- Geolocalización: `navigator.geolocation.getCurrentPosition()` → guarda coordenadas
- Todo se guarda en una **cola offline** (IndexedDB)

### 3. Sync automatizado

Cuando `navigator.onLine` cambia a `true` + evento `online`:

```
Por cada item en la cola offline:
  1. POST a api.php/submit_form
  2. Si éxito → mover a SyncLog (con timestamp)
  3. Si falla → reintentar con backoff, máximo 3 intentos
  4. Si falla permanentemente → marcar como failed en SyncLog
```

### 4. Feedback al usuario

- Indicador visual en la PWA: "5 pendientes de sincronizar"
- Badge: `navigator.setAppBadge(count)` (API moderna, fallback a número en UI)
- Log de sync: qué se subió, qué falló

---

## Integración con trackerGram

La PWA **no requiere cambios en TikiWikiClient ni MessageMapper** porque reutiliza el mismo flujo:

```
PWA → api.php/submit_form
  → Recibe JSON con:
      {
        "auth_token": "...",
        "tracker_id": 26,
        "fields": {
          "nombre": "Juan",
          "apellido": "Pérez",
          "foto": "data:image/jpeg;base64,...",
          "latitud": -31.42,
          "longitud": -64.18
        }
      }
  → Api crea NormalizedMessage → toWikiFields()
  → createTrackerItem()
```

La PWA puede:

- Pedirle a la API qué campos tiene un tracker (`get_tracker_fields`) y renderizar los inputs automáticamente
- O tener formularios hardcodeados (más simple, menos mantenimiento)

### Primer enfoque recomendado: formularios configurables

El tracker ya define sus campos con `getTrackerFieldDefinitions()`. La PWA podría:

1. Llamar `GET /api/trackers/{id}/fields` (endpoint existente de TikiWiki)
2. Renderizar un input por cada campo según su tipo:
   - `t` → `<input type="text">`
   - `a` → `<textarea>`
   - `n` → `<input type="number">`
   - `G` → botón "📍 Usar ubicación actual"
   - `FG` → `<input type="file" accept="image/*">`
   - `F` → `<input type="text" placeholder="etiqueta1 etiqueta2">`
3. El usuario completa y guarda
4. La PWA cachea localmente y sincroniza después

---

## Trade-offs vs Telegram Mini App

| Aspecto | Telegram Mini App | PWA independiente |
|---------|-------------------|-------------------|
| **Offline** | ❌ No funciona sin conexión | ✅ Offline-first, sync diferido |
| **Entrada** | Hay que abrir Telegram + buscar el bot + iniciar Mini App | Link directo, homescreen, o escaneo de QR |
| **Descubrimiento** | El bot la puede pushear al grupo | Hay que compartir la URL |
| **Notificaciones** | El bot puede enviar mensajes | Push API (permiso del usuario) |
| **Autenticación** | Telegram auth (tácita, desde el chat) | Token propio o login simple |
| **Multimedia** | Webview limitado (sandbox) | Full File API + Camera API |
| **Geolocalización** | Solo si el bot la pide (y Telegram la aprueba en el webview) | `navigator.geolocation` directo |
| **Complejidad** | Media (hay que embeber webview) | Baja (HTML + JS vanilla, sin SDK) |
| **Código nuevo** | Frontend embebido + backend existente | Frontend standalone + endpoint chico en api.php |
| **Mantenimiento** | Atado a cambios de Telegram | Independiente |

---

## Consideraciones técnicas

### Service Worker

```js
// sw.js — App Shell pattern
const CACHE = 'trackergram-v1';
const PRECACHE = [
  '/pwa/index.html',
  '/pwa/app.js',
  '/pwa/manifest.json',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(PRECACHE)));
  self.skipWaiting();
});

self.addEventListener('fetch', e => {
  e.respondWith(
    caches.match(e.request).then(r => r || fetch(e.request))
  );
});
```

### IndexedDB (cola offline)

```js
// app.js
const DB_NAME = 'trackergram-offline';
const DB_VERSION = 1;

function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = e => {
      const db = e.target.result;
      db.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
      db.createObjectStore('synclog', { keyPath: 'id', autoIncrement: true });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
```

### Sync cuando hay conexión

```js
// Detectar conectividad y sincronizar
window.addEventListener('online', async () => {
  const db = await openDB();
  const tx = db.transaction('queue', 'readonly');
  const items = await tx.store.getAll();
  for (const item of items) {
    try {
      const res = await fetch('/api.php/submit_form', {
        method: 'POST',
        body: JSON.stringify(item),
      });
      if (res.ok) {
        // Mover a synclog
        const delTx = db.transaction('queue', 'readwrite');
        delTx.store.delete(item.id);
        const logTx = db.transaction('synclog', 'readwrite');
        logTx.store.add({ ...item, syncedAt: Date.now() });
      }
    } catch (e) {
      // Reintentar después
    }
  }
});
```

---

## MVP mínimo

### Fase 1 — PWA funcional (1 sesión)

1. `pwa/index.html` con formulario hardcodeado (nombre, apellido, foto, ubicación)
2. `pwa/app.js` con IndexedDB + sync loop
3. `pwa/sw.js` con App Shell cache
4. `pwa/manifest.json`
5. Endpoint `api.php/submit_form` que autentica por token y manda a TikiWiki

### Fase 2 — Formularios dinámicos (2 sesiones)

1. La PWA fetchea los campos del tracker desde TikiWiki API
2. Renderiza inputs dinámicamente según tipo de campo
3. Soporte para múltiples trackers/conexiones

### Fase 3 — UX completa (1 sesión)

1. Indicador visual de items pendientes
2. Badge en homescreen (API setAppBadge)
3. Log de sync accesible desde la PWA
4. QR para compartir URL de la PWA

---

## Lo que NO cambia

- **TikiWikiClient**: no se toca — es quien recibe los items ya parseados
- **MessageMapper**: no se toca — `toWikiFields()` transforma `NormalizedMessage` igual que siempre
- **ConfigManager**: no se toca — las conexiones siguen en `setup.json`
- **Admin panel**: no se toca — la PWA tiene su propia autenticación

La PWA es **una nueva puerta de entrada** al mismo pipeline de datos existente.
