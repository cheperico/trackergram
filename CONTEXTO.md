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

- Versión: **v0.1.7** (beta funcional)
- Funcionalidad principal: operativa
- Importación ZIP: operativa, optimizada con índices y límites de seguridad
- Creación automática de trackers: operativa con tipos de campo correctos (FG, G, D)
- Arquitectura: api.php como entry point puro, lógica en WebhookHandler + clientes

---

## Estructura de Archivos del Proyecto

### Archivos PHP Principales (en la raíz del proyecto)

| Archivo | Propósito |
|---------|-----------|
| `api.php` | **Punto de entrada HTTP**. Recibe webhooks de Telegram, valida auth + rate limit, delega en WebhookHandler. |
| `WebhookHandler.php` | **Lógica de negocio**. Procesa mensajes, reacciones, topics, descarga y sube media. |
| `admin.php` | **Interfaz de administración web**. Panel para configurar bot, crear trackers, importar exports. |
| `import.php` | **Importación de exports**. Procesa archivos ZIP exportados de Telegram y crea items en TikiWiki. |
| `config.php` | **Carga de configuración**. Lee variables de entorno y define constantes globales. |
| `bootstrap.php` | **Carga centralizada de dependencias**. Todos los entry points requieren este archivo. |

### Clientes/Clases (en la raíz del proyecto)

| Archivo | Propósito |
|---------|-----------|
| `WebhookHandler.php` | Lógica de negocio del webhook | Cuando se agrega o modifica procesamiento de mensajes |
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

El estado detallado del proyecto (completado, parcial, pendiente) se mantiene en **[roadmap.md](roadmap.md)**. El historial de cambios por versión está en **[CAMBIOS.md](CAMBIOS.md)**.

A modo de resumen: la funcionalidad principal (recibir mensajes de Telegram y enviarlos a TikiWiki) está operativa. Lo mismo la importación de exports ZIP. Para el detalle fino de qué falta y qué prioridad tiene, ver `roadmap.md`.

---

## Para Nuevo Integrante del Proyecto

### Primeros pasos recomendados

1. **Leer este archivo** (contexto.md)
2. **Leer README.md** para entender el uso
3. **Leer TECHNICAL.md** para entender el razonamiento detrás de las soluciones
4. **Revisar roadmap.md** para ver qué hay pendiente

### Por dónde empezar a leer el código

Si querés entender el flujo completo, seguí este orden:

1. **`api.php`** — entry point del webhook. Recibe POST, valida auth + rate limit, delega en `WebhookHandler::processUpdate()`.
2. **`WebhookHandler.php`** — orquesta todo: `processMessage()` valida, resuelve topics, chequea duplicados, llama a extractMessageData() y sendToTikiWikiWithRetries().
3. **`TikiWikiClient.php`** — comunicación con TikiWiki (crear items, subir archivos, verificar duplicados).
4. **`TelegramClient.php`** — comunicación con Telegram (descarga de archivos).
5. **`MessageMapper.php`** — transformación entre formatos (Webhook → TikiWiki, import → TikiWiki).
6. **`import.php`** — flujo de importación de exports ZIP.

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

- Las clases cliente usan métodos estáticos — no hay inyección de dependencias, lo que dificulta el testing unitario
- Webhook e importación tienen parsers separados (WebhookHandler vs import.php) que comparten lógica de mapeo pero con formatos de entrada diferentes

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