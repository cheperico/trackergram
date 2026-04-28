# trackerGram

Integración directa de Telegram con TikiWiki trackers.

## Descripción

trackerGram es una aplicación independiente que recibe webhooks de Telegram y envía mensajes directamente a TikiWiki trackers para aprovechar sus capacidades de indexado y búsqueda. Reutiliza el código aprendido en cheLegram (extractMessageData, manejo de topics, etc.) pero sin dependencia de cheLegram.

## Arquitectura

```
Telegram → trackerGram → TikiWiki Tracker
```

### Arquitectura Distribuida (Opcional)

Para evitar bloqueos de firewall o problemas de red, puedes implementar una arquitectura distribuida:

```
Telegram → trackerGram (Servidor A) → TikiWiki (Servidor B)
```

**Ventajas:**
- Evita bloqueos de IPs de Telegram en el firewall del servidor de TikiWiki
- Permite separar la carga de procesamiento de webhooks del almacenamiento
- Flexibilidad para escalar componentes independientemente

**Configuración:**
1. Instala trackerGram en un servidor accesible desde Telegram (Servidor A)
2. Configura TikiWiki en otro servidor (Servidor B)
3. En el `.env` de trackerGram, apunta `TIKIWIKI_API_URL` al servidor de TikiWiki
4. Configura el webhook de Telegram para apuntar al Servidor A

**Ejemplo:**
- trackerGram: `https://trackergram.cheps.chela.org.ar`
- TikiWiki: `https://wiki.chela.org.ar`
- Webhook: `https://trackergram.cheps.chela.org.ar/api.php`
- TikiWiki API: `https://wiki.chela.org.ar/api/`

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

Los logs se guardan en `debug.log` cuando `DEBUG_MODE` está habilitado en `config.php`.

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

- **Secret Token de Webhook**: Validación del header `X-Telegram-Bot-Api-Secret-Token` para prevenir spoofing de webhooks
- **Credenciales en .env**: Las credenciales se almacenan en `.env` (protegido por `.htaccess`)
- **Sanitización XSS**: Campos de texto sanitizados con `htmlspecialchars()` antes de enviar a TikiWiki
- **CORS deshabilitado**: No permite peticiones cross-origin
- **Headers de seguridad HTTP**: X-Frame-Options, X-Content-Type-Options, X-XSS-Protection configurados
- **Archivos sensibles protegidos**: `.env`, `config.php`, `test_webhook.php` bloqueados por `.htaccess`
- **Permisos de archivos**: `.env` debe tener permisos 600 o 640

### Roadmap de Seguridad

**Mejoras planeadas:**
- Rate limiting para prevenir abuso del webhook
- Validación más estricta de contenido (longitud máxima, caracteres permitidos)
- Logging de intentos de acceso no autorizados
- Verificación de firma de mensajes de Telegram (opcional)
- IP whitelisting para restringir acceso a endpoints administrativos
- Monitoreo de anomalías en patrones de mensajes

### Roadmap de Funcionalidades

**Mejoras planeadas:**
- **Mejorar interpretación de mensajes**: Expandir soporte para tipos de mensajes de Telegram (encuestas, ubicaciones, contactos, etc.)
- **Lectura de topic ID**: Mostrar nombre del topic en lugar del ID numérico, o agregar columnas separadas para ID y nombre
- **Lectura de nombre de chat ID**: Mostrar nombre del chat en lugar del ID numérico, o agregar columnas separadas para ID y nombre
- **Manejo de mensajes no soportados**: Corregir el problema donde mensajes no soportados se muestran como link a "Mensaje no soportado" en lugar de mostrar información útil
- **Evitar duplicado de mensajes**: Implementar deduplicación basada en message_id para evitar que el mismo mensaje se envíe múltiples veces a TikiWiki
- **Interfaz web de configuración**: Crear interfaz minimalista (sin CSS) para configurar credenciales del bot, token de API de TikiWiki, y otros parámetros desde el navegador
- **Autenticación para interfaz de configuración**: Implementar autenticación básica (usuario/contraseña) para proteger el acceso a la interfaz de configuración
- **Actualización de webhook desde interfaz**: Integrar botón en la interfaz para actualizar automáticamente el webhook de Telegram cuando se cambie la URL del servidor

## Autores

- cheperico
- Cascade (SWE-1.5 y SWE-1.6 - Cognition AI Assistant)

## Licencia

MIT License - Ver archivo principal del proyecto para detalles completos.
