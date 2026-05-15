# contexto.md - Guía del Proyecto trackerGram

## Qué es este archivo

Este archivo es el punto de entrada para cualquier persona nueva en el proyecto (desarrollador, colaborador, o agente de IA). Aquí encontrás la visión general, cómo está estructurado el proyecto, y dónde buscar información específica.

**Este archivo no se modifica solo** - cada vez que se hace un cambio significativo en el proyecto, este documento debe actualizarse para reflejar el nuevo estado.

---

## Visión General del Proyecto

### Qué hace trackerGram

trackerGram es un puente entre **Telegram** y **TikiWiki**. Su función principal es:

1. **Recibir mensajes de Telegram** vía webhook y enviarlos directamente a un tracker de TikiWiki
2. **Importar conversaciones históricas** desde exports ZIP de Telegram
3. **Crear trackers automáticamente** con los campos necesarios

### Filosofía del Proyecto

- **Sin base de datos local** - usa TikiWiki como almacenamiento
- **MVP pragmático** - prioriza funcionalidad sobre perfección arquitectónica
- **Iteración rápida** - funcionalidad core primero, refactorización después

### Estado Actual

- Versión: **v0.1.6** (beta funcional)
- Funcionalidad principal: operativa
- Importación ZIP: operativa, optimizada con índice de archivos y descarga en chunks
- Creación automática de trackers: parcial (tipos de campo incompletos)
- Arquitectura: separada en clientes (TikiWikiClient, TelegramClient, MessageMapper)

---

## Estructura de Archivos del Proyecto

### Archivos PHP Principales (en la raíz del proyecto)

| Archivo | Propósito |
|---------|-----------|
| `api.php` | **Punto de entrada principal**. Recibe webhooks de Telegram, procesa mensajes y envía a TikiWiki. Delega a los clientes especializados. |
| `admin.php` | **Interfaz de administración web**. Panel para configurar bot, crear trackers, importar exports, ver logs. |
| `import.php` | **Script de importación**. Procesa archivos ZIP exportados de Telegram y crea items en TikiWiki. |
| `config.php` | **Carga de configuración**. Lee variables de entorno y define constantes globales. |
| `setup_webhook.php` | **Script de configuración inicial**. Configura el webhook de Telegram automáticamente. Solo ejecutable desde CLI o localhost. |

### Clientes/Clases (en la raíz del proyecto)

| Archivo | Propósito |
|---------|-----------|
| `TikiWikiClient.php` | **Cliente para TikiWiki**. Encapsula toda la comunicación con la API de TikiWiki: trackers, galleries, items. |
| `TelegramClient.php` | **Cliente para Telegram**. Encapsula la comunicación con la API de Telegram: descarga de archivos, info de chats. |
| `MessageMapper.php` | **Mapeador de mensajes**. Transforma mensajes de Telegram al formato de campos de TikiWiki tracker. |

### Archivos de Documentación

| Archivo | Qué contiene | Cuándo actualizar |
|---------|----------------|-------------------|
| `README.md` | Guía para usuarios finales. Cómo instalar, configurar, usar. Puede tener secciones técnicas y no técnicas. | Cuando cambia la UI o el flujo de uso. |
| `TECHNICAL.md` | **Documentación para programadores**. Explica el razonamiento detrás de las soluciones implementadas: qué problema se resolvió, por qué se hizo de cierta manera, cómo se relacionan los componentes. Un programador debería poder leerlo y entender "ah, así fue como lo resolvieron". No es una lista de funciones ni una referencia técnica. | Cuando se agrega una solución nueva, se cambia un enfoque existente, o se descubre que algo no funcionaba como se pensaba. |
| `INSTALL.md` | Instrucciones de instalación paso a paso. | Cuando cambia el proceso de instalación |
| `CAMBIOS.md` | Changelog. Lista de cambios por versión. | Cada release o fix significativo |
| `roadmap.md` | Estado del proyecto y pendientes. Lista de features, bugs, mejoras pendientes. | Cuando se completa algo o se agrega pendiente |
| `CONTEXTO.md` | **Este archivo**. Guía de entrada para nuevos miembros: visión general, cómo está organizado el proyecto, y referencias a fuentes de información (documentación de APIs, links útiles, etc.). | Cuando cambia la estructura o visión del proyecto, o se agregan nuevas referencias externas. |

### Archivos de Configuración

| Archivo | Propósito |
|---------|-----------|
| `.env` | Variables de entorno reales (NO versionar) |
| `.env.example` | Plantilla de variables de entorno |
| `.htaccess` | Configuración de Apache (límites PHP, rewrite) |
| `.gitignore` | Archivos ignorados por git |

---

## Cómo Funciona el Sistema

### Flujo 1: Webhook (api.php)

```
Telegram → Webhook (api.php) → Procesamiento → TikiWiki API → Tracker Item
```

1. Telegram envía POST a `api.php` cuando hay nuevos mensajes
2. `api.php` valida el secret token
3. Parsing del mensaje (texto, foto, video, ubicación, etc.)
4. Descarga de archivos multimedia (si hay)
5. Sube archivos a TikiWiki file gallery
6. Crea item en el tracker con todos los campos
7. Responde a Telegram con OK

### Flujo 2: Importación (import.php)

```
Admin (ZIP) → import.php → Extrae ZIP → Parsea result.json → TikiWiki API
```

1. Usuario selecciona archivo ZIP desde admin.php
2. `import.php` extrae el ZIP
3. Lee `result.json` del export
4. Procesa cada mensaje y lo envía a TikiWiki
5. Limpia archivos temporales

### Flujo 3: Creación de Tracker (admin.php → TikiWiki API)

1. Usuario ingresa nombre del tracker en admin
2. `admin.php` llama a TikiWiki API para crear tracker
3. Crea campos automáticamente según schema definido

---

## Funcionalidades Actuales y su Estado

### ✅ Completado

- Webhook endpoint para Telegram
- Envío de mensajes a TikiWiki trackers
- Soporte para múltiples tipos de mensaje (texto, foto, video, audio, documento, sticker, ubicación, contacto, encuesta, animación)
- Service messages del grupo (miembros que entran/salen, topics creados/renombrados, mensajes fijados)
- Deduplicación de mensajes por (chat_id, message_id)
- Importación de exports ZIP con índice de archivos
- Subida de archivos multimedia a TikiWiki galleries (descarga en chunks, límite 20MB)
- Panel de administración web con secciones independientes
- CSRF protection en formularios
- Hash de contraseña admin con bcrypt
- Rate limiting en webhook y login
- ALLOWED_CHAT_IDS configurable desde .env
- Los tokens de Telegram ya no se guardan en TikiWiki
- Path traversal prevention en extracción de ZIP

### ⚠️ Parcial

- **Creación automática de trackers**: Crea el tracker pero los tipos de campo no son exactamente los documentados (FG, G, D)
- **Importación**: Funciona pero exports muy grandes (>1GB) pueden requerir procesamiento asíncrono
- **Refactorización**: sendToTikiWiki delegado a clientes, processUpdate aún tiene lógica de orquestación

### ❌ Pendiente (ver roadmap.md para detalle)

- Reacciones a mensajes (message_reaction)
- Service messages: photo_edit, photo_delete
- Mensajes editados/borrados (edited_message)
- Sistema de etiquetas (hashtags)
- Mensajes estructurados con prefijos (GPS, alertas)

---

## Para Nuevo Integrante del Proyecto

### Primeros pasos recomendados

1. **Leer este archivo** (contexto.md)
2. **Leer README.md** para entender el uso
3. **Leer TECHNICAL.md** para entender el razonamiento detrás de las soluciones
4. **Revisar roadmap.md** para ver qué hay pendiente

### Por dónde empezar a leer el código

Si querés entender el flujo completo, seguí este orden:

1. **`api.php`** desde la línea 615 hacia abajo — es el punto de entrada del webhook. Ahí se recibe el POST de Telegram y se llama a `processUpdate()`.
2. **`api.php::processUpdate()`** (línea 492) — orquesta todo: valida el mensaje, resuelve el topic, chequea duplicados, envía a TikiWiki.
3. **`api.php::extractMessageData()`** (línea 158) — parsea el mensaje de Telegram y clasifica su tipo (texto, foto, video, etc.).
4. **`TikiWikiClient.php`** — cómo se comunica con TikiWiki.
5. **`TelegramClient.php`** — cómo se comunica con Telegram.
6. **`MessageMapper.php`** — cómo se transforman los datos entre formatos.
7. **`import.php`** — el flujo de importación de exports ZIP.

### Entorno de desarrollo local

El proyecto corre en PHP 8.0+ con extensiones `curl`, `json`, `mbstring`, `session` y `zip`. Para probar localmente:

```bash
php -S localhost:8000
```

Y configurar un túnel con ngrok o similar para recibir webhooks de Telegram. Ver `INSTALL.md` para la configuración completa.

### Dónde buscar ayuda

- **Bugs conocidos**: Revisar `roadmap.md` sección "Bugs"
- **Cambios recientes**: Revisar `CAMBIOS.md`
- **Cómo hacer un cambio**: Agregar entrada a `CAMBIOS.md`, actualizar `roadmap.md` si corresponde

### Convenciones del proyecto

- Commits en español o inglés (ser consistente)
- push a main después de testing local
- PHP 8.0+ target
- Usar type hints donde sea posible
- Mantener DEBUG_MODE conditional para logs

---

## Notas sobre la Estructura Actual

### Problemas conocidos (técnico)

- `api.php` tiene ~620 líneas y `processUpdate()` sigue siendo una función monolítica que mezcla validación, deduplicación, reintentos y orquestación
- Las clases cliente usan métodos estáticos — no hay inyección de dependencias, lo que dificulta el testing unitario
- Algunos tipos de campo en creación automática de tracker no coinciden con documentación (FG, G, D)
- `importItemToTikiWiki` e `import.php` comparten lógica con `api.php` pero con formatos de entrada diferentes

### Por qué está así

- El proyecto creció orgánicamente desde un script simple
- Priorizó funcionalidad sobre arquitectura al principio
- Ahora que está funcionando, hay deuda técnica que pagar

---

## Referencias de API

| API | Documentación |
|-----|---------------|
| **Telegram Bot API** | [https://core.telegram.org/bots/api](https://core.telegram.org/bots/api) |
| **TikiWiki API (chela.org.ar)** | [https://wiki.chela.org.ar/api/](https://wiki.chela.org.ar/api/) |

---

## Mantenimiento de Este Archivo

Este archivo debe actualizarse cuando:

1. Se agrega una nueva funcionalidad principal
2. Se modifica la estructura de archivos
3. Se cambia la visión o filosofía del proyecto
4. Se alcanza una nueva versión (actualizar versión actual)
5. Un nuevo desarrollador se une al proyecto

**No actualizar** por cambios menores o fixes de bugs - esos van a CAMBIOS.md.