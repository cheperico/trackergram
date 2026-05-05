# trackerGram

Integración directa de Telegram con TikiWiki trackers.

## Descripción

trackerGram es una aplicación independiente que recibe webhooks de Telegram y envía mensajes directamente a TikiWiki trackers para aprovechar sus capacidades de indexado y búsqueda. Reutiliza el código aprendido en cheLegram (extractMessageData, manejo de topics, etc.) pero sin dependencia de cheLegram.

## Arquitectura

```
Telegram → trackerGram → TikiWiki Tracker
```

## Características

- **Webhook endpoint**: Recibe actualizaciones de Telegram en tiempo real
- **Envío directo a TikiWiki**: Mensajes enviados directamente a trackers
- **Manejo de topics**: Soporte completo para forum topics de Telegram
- **Soporte multimedia**: Fotos, videos, audio, documentos, stickers, notas de voz
- **Reintentos automáticos**: 2 intentos en caso de falla de envío
- **Timeouts optimizados**: 5 segundos para cURL y file_get_contents para evitar saturación
- **Logging**: Registro de errores y operaciones
- **Sin base de datos local**: No requiere almacenamiento local, solo envía a TikiWiki

## Requisitos

- PHP 7.4+
- Apache con mod_rewrite
- Bot de Telegram (creado con @BotFather)
- TikiWiki instalado y configurado con trackers
- Token de API de TikiWiki

## Instalación

### 1. Crear Bot de Telegram

1. Habla con @BotFather en Telegram
2. Usa el comando `/newbot`
3. Sigue las instrucciones para crear tu bot
4. Copia el token del bot

### 2. Configurar TikiWiki

1. Asegúrate de tener TikiWiki instalado
2. Crea un tracker para mensajes de Telegram
3. Configura los campos del tracker con los permanent names:
   - `telegrammessageTelegramMessageId`
   - `telegrammessageChatId`
   - `telegrammessageTopicId`
   - `telegrammessageUserId`
   - `telegrammessageUsername`
   - `telegrammessageFirstName`
   - `telegrammessageLastName`
   - `telegrammessageMessageType`
   - `telegrammessageText`
   - `telegrammessageMedia` (campo de tipo archivo vinculado a la galería)
   - `telegrammessageMediaUrl`
   - `telegrammessageFileUrl`
   - `telegrammessageMediaType`
   - `telegrammessageMediaSize`
   - `telegrammessageMediaCaption`
   - `telegrammessageMessageDate`
4. Genera un token de API en TikiWiki

### 3. Configurar trackerGram

1. Copia los archivos de trackerGram a tu servidor web
2. Copia `.env.example` a `.env`
3. Configura las variables en `.env`:
   ```env
TELEGRAM_BOT_TOKEN=tu_token_aqui
TELEGRAM_WEBHOOK_SECRET=tu_token_secreto_webhook_aqui
TIKIWIKI_API_URL=http://localhost/tikigram/api/
TIKIWIKI_TOKEN=tu_token_tikiwiki_aqui
TIKIWIKI_TRACKER_ID=1
DEBUG_MODE=false
   ```
4. Configura `ALLOWED_CHAT_IDS` en `config.php` si quieres restringir a chats específicos

### 4. Configurar Webhook de Telegram

**Opción A: Usar el script automático (recomendado)**

1. Accede al script desde tu navegador:
   ```
   https://tu-dominio.com/trackergram/setup_webhook.php
   ```
2. El script detectará automáticamente la URL del servidor y configurará el webhook

**Opción B: Configuración manual**

1. Determina la URL pública de tu webhook:
   ```
   https://tu-dominio.com/trackergram/api.php
   ```
2. Genera un token secreto para el webhook (32 caracteres alfanuméricos recomendado)
3. Configura el webhook usando Telegram API con el secret token:
   ```
   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://tu-dominio.com/trackergram/api.php&secret_token=TU_TOKEN_SECRETO
   ```
4. Verifica que el webhook esté configurado:
   ```
   https://api.telegram.org/bot<TOKEN>/getWebhookInfo
   ```

### 5. Interfaz de Administración (Opcional)

trackerGram incluye una interfaz web minimalista para configurar credenciales y actualizar el webhook:

1. Configura las credenciales de administración en `.env`:
   ```env
   ADMIN_USERNAME=admin
   ADMIN_PASSWORD=tu_contraseña_segura
   ```
2. Accede a la interfaz desde tu navegador:
   ```
   https://tu-dominio.com/trackergram/admin.php
   ```
3. Inicia sesión con las credenciales configuradas
4. Desde la interfaz puedes:
   - Editar credenciales del bot de Telegram
   - Editar credenciales de TikiWiki
   - Actualizar el webhook de Telegram automáticamente

## Estructura de Archivos

```
trackergram/
├── config.php          # Configuración del proyecto
├── api.php             # Webhook endpoint de Telegram
├── admin.php           # Interfaz de administración (opcional)
├── setup_webhook.php   # Script para configurar webhook automáticamente
├── .env                # Variables de entorno (credenciales)
├── .env.example        # Plantilla de variables de entorno
├── .htaccess           # Configuración Apache
├── README.md           # Este archivo
└── debug.log           # Logs (se crea automáticamente)
```

## Uso

Una vez configurado, trackerGram funcionará automáticamente:

1. Cuando se envía un mensaje a un grupo de Telegram, Telegram envía el webhook a trackerGram
2. trackerGram procesa el mensaje y extrae los datos
3. trackerGram envía el mensaje al tracker de TikiWiki
4. El mensaje aparece en TikiWiki y puede ser buscado e indexado

## Configuración

### config.php

- `TELEGRAM_BOT_TOKEN`: Token del bot de Telegram
- `TELEGRAM_API_URL`: URL de la API de Telegram
- `TIKIWIKI_API_URL`: URL de la API de TikiWiki
- `TIKIWIKI_TOKEN`: Token de API de TikiWiki
- `TIKIWIKI_TRACKER_ID`: ID del tracker de TikiWiki
- `ALLOWED_CHAT_IDS`: Array de chat_ids permitidos (vacío = todos)
- `DEBUG_MODE`: Habilitar/deshabilitar logging

### .env

Las credenciales sensibles se almacenan en `.env`:
- `TELEGRAM_BOT_TOKEN`: Token del bot de Telegram
- `TIKIWIKI_API_URL`: URL de la API de TikiWiki
- `TIKIWIKI_TOKEN`: Token de API de TikiWiki
- `TIKIWIKI_TRACKER_ID`: ID del tracker de TikiWiki

## Logging

Los logs se guardan en `debug.log` cuando `DEBUG_MODE=true` está configurado en `.env`.

## Troubleshooting

### Mensajes no llegan a TikiWiki

1. Verifica que el webhook esté configurado correctamente:
   ```
   https://api.telegram.org/bot<TOKEN>/getWebhookInfo
   ```
2. Revisa `debug.log` para errores
3. Verifica que el token de TikiWiki sea correcto
4. Verifica que el tracker ID sea correcto
5. Verifica que los permanent names de campos coincidan

### Error de conexión a TikiWiki

1. Verifica que TikiWiki esté accesible
2. Verifica que la URL de la API sea correcta
3. Verifica que el token de API sea válido
4. Revisa los logs de TikiWiki

### Webhook no responde

1. Verifica que la URL del webhook sea pública y accesible
2. Verifica que el servidor web esté funcionando
3. Revisa los logs del servidor web
4. Verifica que el archivo `.htaccess` esté configurado correctamente
5. Verifica que el secret token coincida entre `.env` y la configuración del webhook

### Error "Connection timed out" en Telegram

Si Telegram reporta "Connection timed out" al intentar conectar con el webhook:

1. **Verifica el firewall del servidor**: El firewall puede estar bloqueando las IPs de Telegram
2. **IPs de Telegram**: Asegúrate de que estas subredes estén permitidas:
   - 149.154.160.0/20
   - 91.108.4.0/22
3. **Contacta al soporte del hosting**: Si no tienes acceso al firewall, solicita que agreguen las IPs de Telegram a la whitelist

### Error 406 de ModSecurity

Si ModSecurity bloquea las peticiones a TikiWiki:

1. Revisa los logs de ModSecurity para identificar la regla que está bloqueando
2. Crea una regla de exclusión específica para las peticiones de trackerGram a TikiWiki
3. O desactiva ModSecurity temporalmente para diagnóstico (no recomendado en producción)

## Seguridad

### Características de Seguridad Implementadas

- **Secret Token de Webhook**: Validación del header `X-Telegram-Bot-Api-Secret-Token` para prevenir spoofing de webhooks
- **Credenciales en .env**: Las credenciales se almacenan en `.env` (protegido por `.htaccess`)
- **Sanitización XSS**: Campos de texto sanitizados con `htmlspecialchars()` antes de enviar a TikiWiki
- **CORS deshabilitado**: No permite peticiones cross-origin
- **Headers de seguridad HTTP**: X-Frame-Options, X-Content-Type-Options, X-XSS-Protection configurados
- **Archivos sensibles protegidos**: `.env`, `config.php`, `test_webhook.php` bloqueados por `.htaccess`
- **Permisos de archivos**: `.env` debe tener permisos 600 o 640

### Mejoras de Seguridad (v0.0.1)

- **Autenticación robusta en admin.php**: 
  - Requerimiento obligatorio de contraseña ADMIN_PASSWORD
  - Rate limiting: 5 intentos máximos, bloqueo de 15 minutos
  - Timeout de sesión: 30 minutos de inactividad
  - Regeneración periódica de ID de sesión (cada 15 minutos)
- **Protección CSRF**: Tokens CSRF en todos los formularios de administración
- **Validación estricta de inputs**: 
  - URLs: Validación de formato http/https sin espacios
  - Tokens: Solo caracteres alfanuméricos, guiones y guiones bajos
  - Números: Enteros positivos validados
  - Strings: Sin caracteres de control, longitud máxima 1000 caracteres
- **Sesión segura**: 
  - Cookies HttpOnly, Secure y SameSite Strict
  - Modo estricto de sesión habilitado
  - Timeout automático y destrucción de sesión
- **Configuración segura**:
  - DEBUG_MODE configurable vía .env (antes hardcoded true)
  - Paths absolutos para evitar problemas de contexto
  - Detección robusta de HTTPS compatible con diferentes servidores
- **Validación de archivos**: Manejo seguro de archivos .env con líneas malformadas

### Roadmap de Seguridad

#### ✅ Completado (v0.0.1)
- **CRITICAL**: Fix admin.php line 23 - Add validation before explode to handle .env lines without '='
- **CRITICAL**: Fix admin.php line 45 - Require ADMIN_PASSWORD to be set (currently allows empty password)
- **CRITICAL**: Add CSRF protection to admin.php forms
- **CRITICAL**: Add rate limiting to login attempts (brute force protection)
- **CRITICAL**: Fix HTTPS detection in admin.php and setup_webhook.php (currently only checks 'on')
- **CRITICAL**: Add session security (timeout, secure cookie flags)
- **CRITICAL**: Fix config.php line 56 - Make DEBUG_MODE configurable via .env instead of hardcoded true
- **CRITICAL**: Fix config.php line 8 - Use absolute path (__DIR__) for .env file
- **CRITICAL**: Add input validation for all POST data in admin.php (prevent arbitrary content injection)
- **CRITICAL**: Fix setup_webhook.php line 92 - Incorrect verification logic (checks has_custom_certificate instead of secret token)

#### 🔄 Próximas Mejoras
- Rate limiting para prevenir abuso del webhook (API endpoint)
- Logging de intentos de acceso no autorizados
- Verificación de firma de mensajes de Telegram (opcional)
- IP whitelisting para restringir acceso a endpoints administrativos
- Monitoreo de anomalías en patrones de mensajes
- Validación más estricta de contenido (longitud máxima, caracteres permitidos) - parcialmente implementado
- **Refactorización del código**: Ver archivo `para_mejoras_tecnicas.md` para recomendaciones de arquitectura, separación en clases, typed PHP y mejoras de estructura

### Roadmap de Funcionalidades

#### ✅ Completado (v0.0.1)
- **Interfaz web de configuración**: Interfaz minimalista para configurar credenciales desde navegador
- **Autenticación para interfaz de configuración**: Autenticación básica (usuario/contraseña) implementada
- **Actualización de webhook desde interfaz**: Botón para actualizar webhook automáticamente

#### ✅ Completado (v0.1.0)
- **Subida de archivos multimedia a TikiWiki**: Los archivos (fotos, videos, audio, documentos, stickers, notas de voz) se suben automáticamente a la file gallery vinculada al campo `telegrammessageMedia`. El galleryId se obtiene dinámicamente desde la configuración del tracker via API.

#### 🔄 Próximas Mejoras
- **Mejorar interpretación de mensajes**: Expandir soporte para tipos de mensajes de Telegram (encuestas, ubicaciones, contactos, etc.)
- **Lectura de topic ID**: Mostrar nombre del topic en lugar del ID numérico, o agregar columnas separadas para ID y nombre
- **Lectura de nombre de chat ID**: Mostrar nombre del chat en lugar del ID numérico, o agregar columnas separadas para ID y nombre
- **Manejo de mensajes no soportados**: Corregir el problema donde mensajes no soportados se muestran como link a "Mensaje no soportado" en lugar de mostrar información útil
- **Evitar duplicado de mensajes**: Implementar deduplicación basada en message_id para evitar que el mismo mensaje se envíe múltiples veces a TikiWiki
- **Mejoras técnicas de código**: Ver archivo `para_mejoras_tecnicas.md` para roadmap de refactorización (separación en clases, typed PHP, eliminar variables globales, etc.)

#### 🐛 Bugs por Corregir
- ~~**Fix api.php line 298**: Log message has incorrect spacing~~ ✅ Corregido en v1.2
- ~~**Fix api.php line 278**: media_url assigned to both media_url and file_url fields unintentionally~~ ✅ Corrección parcial - ahora se usa `media_url` correctamente
- ~~**Fix api.php line 299**: Remove or fix sleep(1) in retry loop~~ ✅ Corregido - ahora usa usleep
- **Fix api.php lines 263-283**: Add validation that required fields exist in $message array before accessing
- ~~**Fix api.php line 232**: Fix log truncation that could leave incomplete HTML tags~~ ✅ Ya no aplica (formato de texto cambiado)
- **Fix api.php**: Make error handling consistent (some functions return null, others return false)
- **Fix admin.php line 177**: Increase input size for bot token (currently 50, should accommodate longer tokens)
- **Fix admin.php**: Remove duplicate code for protocol detection and webhook URL construction
- **Fix setup_webhook.php lines 24-26**: Inconsistent requirement - script requires TELEGRAM_WEBHOOK_SECRET but api.php allows webhook without it

## Autores y Desarrollo

- **cheperico**: Dirección del proyecto, pruebas y validación
- **OpenCode (minimax-m2.5-free)**: Asistencia de programación
- **Cascade (SWE-1.5 y SWE-1.6 - Cognition AI Assistant)**: Asistencia de programación

### Sobre el desarrollo

Este proyecto fue desarrollado con asistencia de LLMs. El director del proyecto (cheperico) no es programador profesional y utiliza agentes de IA para implementar las funcionalidades. Esta metodología de desarrollo tiene características particulares:

- Los LLMs son buenos para implementar features específicas, optimizar código y buscar bugs
- Pueden escribir código funcional pero pueden perder contexto en sesiones largas
- La validación final siempre requiere pruebas humanas

## Licencia

MIT License - Ver archivo principal del proyecto para detalles completos.
