# Informes de Gemini - trackerGram

Este documento compila los informes de seguridad, arquitectura y estrategia generados para el proyecto **trackerGram**.

## Índice
1. [Reporte de Seguridad](#reporte-de-seguridad-trackergram)
2. [Reporte de Arquitectura](#reporte-de-arquitectura-trackergram)
3. [Revisión Estratégica](#revisión-estratégica-trackergram)

---

# Reporte de Seguridad: trackerGram

Este informe detalla los hallazgos de seguridad encontrados tras una revisión del código fuente del proyecto trackerGram.

## 1. Vulnerabilidades Críticas y Altas

### 1.1. Denegación de Servicio (DoS) por Agotamiento de Memoria
- **Ubicación:** `api.php` (`downloadAndUploadMedia`) y `import.php` (extracción de ZIP).
- **Problema:** En `api.php`, los archivos multimedia de Telegram se descargan completamente en la memoria de PHP usando `file_get_contents` a través de `TelegramClient::getFileContent()`. Si un usuario envía un video de 50MB o 500MB, el proceso PHP consumirá toda esa memoria y fallará, pudiendo colgar el servidor. En `import.php`, se procesan archivos ZIP completos y el JSON se carga entero en memoria.
- **Recomendación:** Descargar archivos en *chunks* (streaming) directamente a un archivo temporal usando `curl` con la opción `CURLOPT_FILE`, en lugar de `CURLOPT_RETURNTRANSFER`.

### 1.2. Contraseñas y Autenticación en Texto Claro
- **Ubicación:** `admin.php`, `.env`.
- **Problema:** La contraseña del administrador se guarda y se compara en texto claro (`$_ENV['ADMIN_PASSWORD']`). Si un atacante consigue leer el archivo `.env` (por ejemplo, mediante una vulnerabilidad de LFI o un error de configuración del servidor web), obtendrá acceso inmediato al panel de administración.
- **Recomendación:** Almacenar un hash de la contraseña en el archivo `.env` (generado con `password_hash()`) y validarlo usando `password_verify()` en `admin.php`.

### 1.3. Limitación de Tasa (Rate Limiting) Débil
- **Ubicación:** `admin.php` (`checkRateLimit`).
- **Problema:** El control de intentos fallidos de login se basa exclusivamente en variables de sesión (`$_SESSION['login_attempts']`). Un atacante puede realizar un ataque de fuerza bruta simplemente no enviando la cookie de sesión o limpiándola en cada petición, evadiendo completamente la protección.
- **Recomendación:** Implementar el Rate Limiting basado en la dirección IP del usuario (ej. guardando intentos en un archivo temporal de cache o APCu), o restringir el acceso al archivo `admin.php` a IPs confiables (IP Whitelisting).

### 1.4. Posible Path Traversal en Extracción de ZIP
- **Ubicación:** `import.php` (`ZipArchive::extractTo`).
- **Problema:** El script extrae el contenido de un ZIP subido por el usuario sin validar los nombres de los archivos dentro del ZIP. Si un atacante sube un ZIP malicioso con archivos nombrados `../../../../var/www/html/shell.php`, podría sobreescribir archivos críticos del servidor (dependiendo de la versión de PHP/ZipArchive y los permisos del sistema).
- **Recomendación:** Iterar sobre los archivos del ZIP y extraerlos manualmente validando que el `filename` no contenga secuencias `../` ni rutas absolutas.

## 2. Vulnerabilidades Medias

### 2.1. Posible Cross-Site Scripting (XSS) en Panel de Administración
- **Ubicación:** `admin.php` (Línea 462).
- **Problema:** Si el servidor responde con un error no-JSON que contiene HTML malicioso (por ejemplo, inyectado vía un error de la API o del tracker_id), el código JS `resultDiv.innerHTML = '<p style="color: red;">Respuesta no válida: ' + text.substring(0, 200) + '</p>';` podría ejecutar scripts en el navegador del administrador.
- **Recomendación:** Escapar siempre el texto antes de insertarlo en el DOM usando `textContent` en lugar de `innerHTML`, o utilizar una función de escape HTML en JavaScript.

### 2.2. Exposición de Tokens de Telegram
- **Ubicación:** Base de datos de TikiWiki (Trackers).
- **Problema:** La URL de los archivos de Telegram (`getFileUrl`) incluye el `TELEGRAM_BOT_TOKEN`. Si estas URLs se guardan como texto en el Tracker de TikiWiki (`media_url` y `file_url`), cualquier persona con acceso de lectura al tracker en TikiWiki podrá ver y robar el token del bot de Telegram, obteniendo control total sobre el bot.
- **Recomendación:** No almacenar la URL original de Telegram en TikiWiki. Almacenar solo el `file_id` o la URL de la File Gallery de TikiWiki.

## 3. Prácticas Correctas Implementadas (Aspectos Positivos)
- **Validación de Webhook:** Se usa `hash_equals` para evitar ataques de *Timing* al verificar el `HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN`.
- **Protección CSRF:** Se ha implementado correctamente protección CSRF en los formularios críticos.
- **Sanitización hacia TikiWiki:** Se utiliza `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` antes de enviar textos a la API de TikiWiki.

## 4. Conclusión de Seguridad
El proyecto tiene las defensas básicas necesarias para un MVP, pero es altamente vulnerable a ataques de denegación de servicio (DoS) por agotamiento de recursos (RAM y disco) y requiere urgentemente asegurar el mecanismo de autenticación del administrador y la forma en que interactúa con el sistema de archivos al subir/procesar medios.

---

# Reporte de Arquitectura: trackerGram

Este informe analiza el diseño estructural del proyecto trackerGram y propone mejoras para asegurar su escalabilidad y mantenibilidad.

## 1. Patrones y Estructura Actual

El proyecto comenzó como un script procedimental (`api.php`) y está en un proceso de transición hacia una arquitectura más orientada a objetos (OOP) o basada en servicios. Se han extraído clases como `TelegramClient`, `TikiWikiClient` y `MessageMapper`.

### Aspectos Positivos
- **Separación Lógica:** La división del proyecto en endpoints específicos (`api.php` para webhook, `admin.php` para UI, `import.php` para batch) facilita encontrar los puntos de entrada.
- **Clases Cliente:** Encapsular la lógica de las APIs externas en `TelegramClient` y `TikiWikiClient` mejora la legibilidad y centraliza las llamadas cURL.

## 2. Problemas Arquitectónicos Identificados

### 2.1. Métodos Estáticos Globales y Falta de Inyección de Dependencias
- **Problema:** Clases como `TelegramClient` y `TikiWikiClient` están compuestas enteramente por métodos estáticos (ej. `TelegramClient::getFileUrl()`). Esto acopla fuertemente el código y hace que sea virtualmente imposible realizar pruebas unitarias (*Unit Testing*) usando mocks.
- **Impacto:** Dificultad para escalar, testear y mantener.
- **Recomendación:** Refactorizar estas clases para que sean instanciadas (objetos reales) y pasarlas como dependencias a los controladores o manejadores. Por ejemplo, en lugar de `TelegramClient::getFileContent()`, inyectar un `$telegramClient->getFileContent()`.

### 2.2. Código Duplicado y Uso Inconsistente del MessageMapper
- **Problema:** En la hoja de ruta se menciona `MessageMapper`, el cual existe e implementa lógica para formatear mensajes de Telegram hacia TikiWiki. Sin embargo, en `api.php` y en `import.php` se construye manualmente el array de campos para TikiWiki (`extractMessageData` en `api.php`, L178; y el bloque de construcción de array en `import.php`, L228).
- **Impacto:** Lógica de negocio duplicada. Si se añade un nuevo tipo de campo a TikiWiki, hay que modificarlo en al menos tres lugares distintos.
- **Recomendación:** Unificar toda la lógica de transformación de mensajes *exclusivamente* dentro de `MessageMapper`. `api.php` e `import.php` solo deben llamar a este mapper.

### 2.3. Funciones Procedimentales en el Punto de Entrada (`api.php`)
- **Problema:** `api.php` sigue siendo un script "fat" de más de 650 líneas que mezcla:
  - Manejo de HTTP (Lectura de `php://input`).
  - Lógica de autenticación del webhook.
  - Funciones de negocio (ej. `downloadAndUploadMedia`, `processUpdate`).
  - Control de flujo y reintentos (`for` loop en `processUpdate`).
- **Recomendación:** Implementar el patrón Front Controller o, al menos, extraer las funciones de `api.php` hacia una clase de servicio o controlador (ej. `WebhookHandler`), dejando a `api.php` únicamente como el punto que recibe el request y llama al controlador.

### 2.4. Manejo de Errores Inconsistente
- **Problema:** Se mezclan diferentes formas de manejar errores a través de la aplicación: 
  - Funciones que retornan `null` o `false`.
  - Respuestas HTTP directas con `die()` o `exit`.
  - Manejadores de excepciones globales (en `import.php`).
- **Impacto:** Dificultad para rastrear errores y crear flujos de control predecibles.
- **Recomendación:** Estandarizar el uso de Excepciones (`try/catch`) personalizadas (ej. `TelegramApiException`, `TikiWikiApiException`) y utilizar un Exception Handler global en el punto de entrada que loguee el error de forma segura y devuelva el formato de error correcto.

## 3. Conclusión de Arquitectura
El proyecto ha dado un buen primer paso hacia la modularización separando los clientes de las APIs. El siguiente paso crucial, para considerarlo maduro, es abandonar el patrón de métodos estáticos, aplicar **Inyección de Dependencias (DI)**, e integrar `MessageMapper` unificando el procesado de datos en una sola capa de negocio.

---

# Revisión Estratégica: trackerGram

Este informe evalúa el proyecto trackerGram desde una perspectiva de producto y estrategia general. Busca responder si el proyecto va en la dirección correcta y detectar requerimientos o casos de uso que no han sido totalmente considerados.

## 1. Validación de Propósito y Dirección
El propósito de trackerGram es actuar como un **puente entre Telegram y TikiWiki**, sin depender de una base de datos local. Esta decisión es un gran acierto estratégico porque:
- Mantiene el producto ligero y fácil de instalar.
- Se apoya en las potentes funciones de TikiWiki (Trackers, File Galleries, y búsqueda).
- Resuelve un problema real: el respaldo corporativo o comunitario de comunicaciones que suceden en plataformas de mensajería efímera.

## 2. Casos de Uso No Considerados (Puntos Ciegos)

A pesar de tener un buen MVP, hay varios escenarios naturales de Telegram que el sistema actual no maneja o ignora:

### 2.1. Edición y Eliminación de Mensajes
- **Situación actual:** El webhook inserta el mensaje en TikiWiki y nunca vuelve a interactuar con él. 
- **Problema:** En Telegram, los usuarios frecuentemente editan o eliminan mensajes. Cuando esto ocurre, Telegram envía *updates* de tipo `edited_message` o la acción correspondiente, pero trackerGram actualmente ignora estos eventos. Esto lleva a una inconsistencia de datos: el tracker de TikiWiki mantendrá la versión original (o el mensaje que el usuario eliminó) para siempre.
- **Sugerencia:** Si el objetivo es crear un "Archivo Inmutable" (Auditoría), esto es correcto, pero debe estar explícitamente documentado. Si el objetivo es tener un "Espejo" del chat, se debe agregar funcionalidad para actualizar o marcar como borrado un item (buscándolo por `telegrammessageTelegramMessageId`).

### 2.2. Importación de Exports Masivos (Escalabilidad del Producto)
- **Situación actual:** La interfaz de administración permite subir un archivo ZIP, el script lo descomprime y lo procesa en la misma solicitud HTTP.
- **Problema:** En grupos o chats personales con meses o años de historia, los exports de Telegram pesan múltiples Gigabytes y pueden contener decenas de miles de mensajes y fotos. El script de PHP inevitablemente agotará la memoria, el tiempo de ejecución (`max_execution_time`) o el límite de subida de Nginx/Apache.
- **Sugerencia:** La importación de ZIPs requiere un rediseño de producto. Se debe adoptar una estrategia de procesamiento asíncrono (ej. importar los archivos subiéndolos por FTP y tener un script CLI o un CronJob en PHP que los procese por lotes).

### 2.3. Gestión de Múltiples Chats (Multitenancy)
- **Situación actual:** Un único tracker de TikiWiki recibe todos los mensajes. El webhook procesa cualquier chat donde esté añadido el bot (a menos que se use `ALLOWED_CHAT_IDS`).
- **Problema:** Si el bot se incluye en varios grupos, los mensajes de todos esos grupos acabarán mezclados en el mismo tracker de TikiWiki. Aunque se guarde el `chat_id`, TikiWiki presentará todo revuelto por defecto en el listado del tracker.
- **Sugerencia:** Evaluar si el producto debería crear un Tracker distinto por cada `chat_id` de Telegram (Chat A va al Tracker A, Chat B va al Tracker B), o si debe documentarse fuertemente cómo crear "Filtros y Vistas" en TikiWiki usando módulos de plugin para aislar los mensajes por chat.

### 2.4. Integridad de los Archivos (Caducidad de Enlaces)
- **Situación actual:** Las URLs de descarga de Telegram (`media_url`) se guardan directamente en el Tracker. 
- **Problema:** Los enlaces de descarga generados por la API de Telegram (`https://api.telegram.org/file/bot<token>/...`) no son permanentes si el archivo en caché de Telegram desaparece, además de exponer el token. Aunque el bot sube las fotos a la *File Gallery* de TikiWiki (lo cual es excelente), el campo visual en el tracker todavía se nutre de datos de Telegram que pueden romperse.
- **Sugerencia:** El producto debe cortar completamente la dependencia visual del servidor de Telegram. Una vez subido el archivo a TikiWiki, la aplicación debería registrar en el tracker **únicamente** la URL interna o el ID de la File Gallery de TikiWiki.

## 3. Conclusión
El proyecto trackerGram va por muy buen camino logrando su propósito central. Para madurar hacia un producto "Production-Ready", las prioridades estratégicas deben ser **definir cómo se gestionan los mensajes editados/borrados**, y **rediseñar la función de importación masiva** para que sea asíncrona y no dependa de un script web sincrónico.
