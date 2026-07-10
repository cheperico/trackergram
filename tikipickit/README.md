# TikiPickIt

**Carga de datos offline en trackers de TikiWiki, desde el navegador o como PWA instalable en el móvil.**

TikiPickIt te permite crear items en trackers de TikiWiki directamente desde el teléfono o la computadora, sin conexión a internet si es necesario. Los datos se guardan localmente y se sincronizan automáticamente cuando hay conectividad.

---

## Índice

- [Cómo funciona](#cómo-funciona)
- [Instalación](#instalación)
  - [Requisitos](#requisitos)
  - [Pasos](#pasos)
  - [Token manual (rápido)](#opción-a-token-manual-rápido)
  - [OAuth2 (recomendado)](#opción-b-oauth2-recomendado--tokens-rotativos-más-seguro)
  - [Instalación PWA](#instalación-como-pwa-recomendado-en-móvil)
- [Uso](#uso)
  - [Configuración inicial](#1-configuración-inicial)
  - [Dashboard](#2-dashboard)
  - [Formulario de carga](#3-formulario-de-carga)
  - [Sincronización](#4-sincronización)
- [Tipos de campo soportados](#tipos-de-campo-de-tikiwiki-soportados)
- [Limitaciones (MVP)](#limitaciones-conocidas-mvp)
- [Seguridad](#seguridad)
- [Arquitectura](#arquitectura)
- [Preguntas frecuentes](#preguntas-frecuentes)
- [Desarrollo](#desarrollo)
  - [Archivos principales](#archivos-principales)
  - [Documentos de diseño](#documentos-de-diseño)
- [Licencia](#licencia)

---

## Cómo funciona

1. **Conectás** tu TikiWiki ingresando URL y un Bearer token de API
2. **Seleccionás** el tracker donde querés cargar datos
3. **Completás** el formulario con los campos del tracker (texto, números, fechas, GPS, fotos, dropdowns, etc.)
4. **Guardás** — si hay internet, se crea el item en TikiWiki al instante. Si no, se encola localmente.
5. **Sincronizás** después tocando "Sincronizar" o automáticamente al reconectar.

---

## Instalación

### Requisitos

- Un servidor web con Apache (o cualquier servidor que sirva archivos estáticos)
- PHP **no es necesario**: TikiPickIt es 100% frontend
- Un sitio TikiWiki (v24+) con API habilitada y un token de API generado

### Pasos

1. **Copiar la carpeta `tikipickit/`** a tu servidor web:
   ```
   /var/www/html/tikipickit/   (Linux)
   o la carpeta que corresponda en tu hosting
   ```

2. **Asegurar HTTPS**: TikiPickIt necesita HTTPS para funcionar como PWA, para OAuth2 y para acceder al GPS del dispositivo.

3. **Configurar CORS en TikiWiki** (si TikiPickIt está en un dominio distinto a TikiWiki):
   Desde el panel de admin de TikiWiki, habilitar CORS para el dominio donde instalaste TikiPickIt.

#### Opción A: Token manual (rápido)

4. **Generar un token de API** en tu TikiWiki:
   - Admin → API Tokens → Crear nuevo token
   - El token necesita permisos: `tiki_p_admin_trackers` + `tiki_p_create_tracker_items` + `tiki_p_upload_files` (mínimo)

5. **Abrir** `https://tudominio.com/tikipickit/` en el navegador e ingresar URL + token.

#### Opción B: OAuth2 (recomendado — tokens rotativos, más seguro)

4. **Crear un OAuth Client** en tu TikiWiki:
   - Admin → OAuth Server → Crear Client
   - Nombre: `TikiPickIt`
   - Redirect URI: La URL exacta donde esté instalado TikiPickIt (ej: `https://tudominio.com/tikipickit/`)
   - Guardar y copiar **Client ID** y **Client Secret**

5. **Abrir** `https://tudominio.com/tikipickit/` e ingresar:
   - URL de TikiWiki
   - Client ID y Client Secret (en la sección OAuth2)
   - Tocar **"Iniciar sesión con TikiWiki"** → te redirige a TikiWiki para login + consentimiento
   - Al volver, los tokens se guardan automáticamente con refresh periódico

### Instalación como PWA (recomendado en móvil)

1. Abrí TikiPickIt en Chrome/Edge/Safari
2. En Android: "Agregar a pantalla de inicio" (menú ⋮ → Install app)
3. En iOS: Compartir → "Agregar a pantalla de inicio"
4. Se abre como una app nativa, sin la barra del navegador.

---

## Uso

### 1. Configuración inicial

Al abrir TikiPickIt por primera vez, ves la pantalla de configuración:

- **URL de TikiWiki**: `https://wiki.tudominio.org`
- **Bearer Token**: El token generado en Admin → API Tokens
- Tocá **"Probar conexión"** para verificar que todo funciona

En **Preferencias** podés:
- Elegir si iniciar en el dashboard o en el último tracker usado
- Activar/desactivar descarga automática de schemas
- Ocultar trackers que no necesitás (whitelist)

### 2. Dashboard

Muestra todos los trackers disponibles con:
- Nombre y cantidad de campos
- Estado de sincronización (✓ al día / N pendientes)
- Emoji identificatorio automático según el nombre

Tocá cualquier tracker para abrir el formulario de carga.

### 3. Formulario de carga

Cada tracker muestra un formulario dinámico con los campos definidos en TikiWiki:

| Tipo de campo | Cómo se ingresa |
|---|---|
| Texto | Campo de texto simple |
| Texto largo | Área de texto |
| Número | Input numérico con límites si están configurados |
| Fecha | Selector de fecha calendario |
| Fecha y hora | Selector de fecha + hora |
| Desplegable | Menú desplegable con opciones predefinidas |
| Etiquetas (freetags) | Texto con etiquetas separadas por espacio |
| Geolocalización | Botón "📍 Usar ubicación actual" (toma coordenadas del GPS) |
| Archivo adjunto | Selector de archivo (foto, video, documento) |
| Enlace a item | ID numérico de otro item del tracker referenciado |

### 4. Sincronización

- El badge en el header muestra la cantidad de items pendientes
- Tocá "Sincronizar" para enviar todos los items pendientes
- La sincronización es automática al reconectar si hay items en cola
- Cada item se reintenta hasta 3 veces antes de pasar a la bitácora de errores

---

## Tipos de campo de TikiWiki soportados

Todos los siguientes tipos se renderizan automáticamente:

| Código | Tipo | Soporte |
|---|---|---|
| `t` | Text | ✅ |
| `a` | Textarea | ✅ |
| `n` | Number | ✅ (con min/max/step) |
| `f` | Datetime | ✅ (convertido a timestamp UNIX) |
| `j` | Datetime | ✅ |
| `D` | Date | ✅ |
| `d` | Dropdown | ✅ |
| `F` | Freetags | ✅ |
| `G` | Geolocation | ✅ (formato `lon,lat,zoom`) |
| `FG` | File Gallery | ✅ (subida a galleryId configurado) |
| `r` | Item Link | ✅ |
| `M` | Multilingual | ✅ (como texto) |

---

## Limitaciones conocidas (MVP)

| Limitación | Detalle |
|---|---|
| **Archivos en modo offline** | Los archivos adjuntos no se pueden re-subir al sincronizar (el objeto `File` no se persiste en IndexedDB). Se crea el item sin el archivo. |
| **Token manual en localStorage** | Con token manual, la credencial persiste en localStorage. Usar OAuth2 para tokens rotativos que se guardan en IndexedDB. |
| **Solo creación** | No se pueden editar ni borrar items existentes desde TikiPickIt (versión actual). |
| **Sin sincronización bidireccional** | No detecta cambios hechos por otros usuarios en TikiWiki. |
| **Sin tests automatizados** | Validación manual requerida antes de usar en producción. |

---

## Seguridad

TikiPickIt implementa múltiples capas de protección:

| Medida | Detalle |
|---|---|
| **CSP** | Content-Security-Policy restringe scripts, conexiones y framing |
| **HSTS** | Strict-Transport-Security fuerza HTTPS por 1 año |
| **X-Frame-Options: DENY** | Previene clickjacking |
| **X-Content-Type-Options** | Previene MIME sniffing |
| **OAuth2 state** | Parámetro anti-CSRF generado con `crypto.getRandomValues()` |
| **Sanitización outputs** | Toda interpolación HTML pasa por `esc()` (XSS prevention) |
| **Error sanitization** | Errores de API muestran mensajes genéricos, no cuerpos de respuesta |
| **URL validation** | Solo URLs HTTPS (o localhost para desarrollo) |
| **Rate limiting** | Máximo 1 sincronización cada 3 segundos |
| **SW scope** | Service Worker solo intercepta requests al mismo origen |
| **Permissions-Policy** | Solo geolocation y camera permitidas |

## Arquitectura

```
┌──────────────┐     HTTPS      ┌──────────────┐
│  TikiPickIt   │ ────────────→  │  TikiWiki    │
│  (PWA/SPA)    │   Bearer token │  API REST    │
│               │ ←────────────  │              │
│  IndexedDB    │     JSON       │  MySQL/PDO   │
│  (cola local) │                │  (backend)   │
└──────────────┘                └──────────────┘
```

- **Sin servidor intermedio**: conexión directa del browser a TikiWiki
- **Offline-first**: IndexedDB como almacenamiento local
- **Service Worker**: cache de assets y respuestas API GET
- **Sin PHP, sin base de datos, sin framework**: 100% frontend estático

---

## Preguntas frecuentes

### ¿Necesito PHP o una base de datos?
No. TikiPickIt es HTML + JavaScript + CSS. Solo necesita un servidor web que sirva archivos estáticos.

### ¿Necesito trackerGram?
No. TikiPickIt y trackerGram son independientes. Podés usar solo TikiPickIt sin trackerGram, o ambos.

### ¿Mis datos están seguros?
Con **OAuth2**: el access token dura 1 hora y se refresca automáticamente. Los tokens se guardan en IndexedDB, no en localStorage. Si se filtra el token, solo es válido por 1 hora. El refresh token dura 1 mes y se puede revocar desde Admin → OAuth Server.

Con **token manual**: viaja en el header `Authorization` de cada request (HTTPS). En el navegador queda en `localStorage` — no compartas el dispositivo ni el token. Recomendado migrar a OAuth2.

### ¿Funciona sin internet?
Sí. Los formularios se cargan desde el cache del Service Worker. Los datos se guardan en IndexedDB. Se sincronizan automáticamente al reconectar.

### ¿Qué navegadores soporta?
Chrome, Firefox, Safari y Edge en sus versiones modernas (últimos 2 años). Para PWA, Android Chrome o iOS Safari.

---

## Desarrollo

```bash
# No hay build step. Editá los archivos directamente.
# Serví localmente con cualquier servidor estático:

python -m http.server 8080
# o
npx serve .
```

### Archivos principales

| Archivo | Qué hace |
|---|---|
| `index.html` | Estructura de la SPA + estilos CSS |
| `app.js` | Toda la lógica de la aplicación |
| `sw.js` | Service Worker (cache offline) |
| `manifest.json` | Configuración de la PWA |
| `.htaccess` | Configuración Apache (cache, HTTPS) |
| `agents.md` | Contexto para asistentes de IA |

### Documentos de diseño

Ver `design/008-estrategia-recoleccion-estructurada.md` en la raíz del proyecto para el diseño completo.

---

## Licencia

MIT — Parte del ecosistema [trackerGram](https://github.com/cheperico/trackergram).
