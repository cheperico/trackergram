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

- Versión: **v0.1.3** (beta funcional)
- Funcionalidad principal: operativa
- Importación ZIP: operativa pero lenta para exports grandes
- Creación automática de trackers: parcial (tipos de campo incompletos)

---

## Estructura de Archivos del Proyecto

### Archivos PHP Principales (en la raíz del proyecto)

| Archivo | Propósito |
|---------|-----------|
| `api.php` | **Punto de entrada principal**. Recibe webhooks de Telegram, procesa mensajes y envía a TikiWiki. ~900 líneas - mezcla muchas responsabilidades. |
| `admin.php` | **Interfaz de administración web**. Panel para configurar bot, crear trackers, importar exports, ver logs. |
| `import.php` | **Script de importación**. Procesa archivos ZIP exportados de Telegram y crea items en TikiWiki. |
| `config.php` | **Carga de configuración**. Lee variables de entorno y define constantes globales. |
| `setup_webhook.php` | **Script de configuración inicial**. Configura el webhook de Telegram automáticamente. Solo ejecutable desde CLI o localhost. |

### Archivos de Documentación

| Archivo | Qué contiene | Cuándo actualizar |
|---------|----------------|-------------------|
| `README.md` | Guía para usuarios finales. Cómo instalar, configurar, usar. | Cuando cambia la UI o el flujo de uso |
| `TECHNICAL.md` | Documentación técnica. Arquitectura, estructura, dependencias, decisiones de diseño. | Cuando cambia la arquitectura o se agregan features |
| `INSTALL.md` | Instrucciones de instalación paso a paso. | Cuando cambia el proceso de instalación |
| `CAMBIOS.md` | Changelog. Lista de cambios por versión. | Cada release o fix significativo |
| `roadmap.md` | Estado del proyecto y pendientes. Lista de features, bugs, mejoras pendientes. | Cuando se completa algo o se agrega pendiente |
| `CONTEXTO.md` | **Este archivo**. Guía de entrada para nuevos miembros. | Cuando cambia la estructura o visión del proyecto |

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
- Soporte para múltiples tipos de mensaje (texto, foto, video, audio, documento, sticker, ubicación, contacto)
- Deduplicación de mensajes (por message_id)
- Importación de exports ZIP
- Subida de archivos multimedia a TikiWiki galleries
- Panel de administración web
- CSRF protection en formularios

### ⚠️ Parcial

- **Creación automática de trackers**: Crea el tracker pero los tipos de campo no son exactamente los documentados (FG, G, D)
- **Importación**: Funciona pero es lenta (~2 min para 27 mensajes)

### ❌ Pendiente

- Deduplicación por (chat_id + message_id)
- display_errors=0 en producción
- Password hashing para admin
- Rate limiting
- ALLOWED_CHAT_IDS por defecto
- Optimización de importación para exports grandes

---

## Para Nuevo Integrante del Proyecto

### Primeros pasos recomendados

1. **Leer este archivo** (contexto.md)
2. **Leer README.md** para entender el uso
3. **Leer TECHNICAL.md** para entender la arquitectura
4. **Ejecutar setup_webhook.php** para ver el sistema en acción
5. **Revisar roadmap.md** para ver qué hay pendiente

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

- `api.php` tiene ~900 líneas y mezcla muchas responsabilidades
- Falta refactorización para separar en módulos (TelegramClient, TikiWikiClient, etc.)
- Algunos tipos de campo en creación automática de tracker no coinciden con documentación
- Deduplicación actual solo por message_id (no considera chat_id)

### Por qué está así

- El proyecto creció orgánicamente desde un script simple
- Priorizó funcionalidad sobre arquitectura al principio
- Ahora que está funcionando, hay deuda técnica que pagar

---

## Mantenimiento de Este Archivo

Este archivo debe actualizarse cuando:

1. Se agrega una nueva funcionalidad principal
2. Se modifica la estructura de archivos
3. Se cambia la visión o filosofía del proyecto
4. Se alcanza una nueva versión (actualizar versión actual)
5. Un nuevo desarrollador se une al proyecto

**No actualizar** por cambios menores o fixes de bugs - esos van a CAMBIOS.md.