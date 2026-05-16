# trackerGram

Integración directa de Telegram con TikiWiki trackers.

## Descripción

trackerGram es una aplicación que recibe webhooks de Telegram y envía mensajes directamente a TikiWiki trackers. También permite importar conversaciones desde exports de Telegram.

## Arquitectura

```
Telegram → trackerGram → TikiWiki Tracker
         ↓
Importar exports ZIP → TikiWiki Tracker
```

## Características

- **Webhook endpoint**: Recibe actualizaciones de Telegram en tiempo real
- **Envío directo a TikiWiki**: Mensajes enviados directamente a trackers
- **Manejo de topics**: Soporte completo para forum topics de Telegram
- **Soporte multimedia**: Fotos, videos, audio, documentos, stickers, notas de voz
- **Importación de exports**: Importa conversaciones desde archivos ZIP exportados de Telegram
- **Creación automática de trackers**: Crea trackers con todos los campos necesarios via API
- **Interfaz de administración**: Panel web para configurar y administrar
- **Reintentos automáticos**: 2 intentos en caso de falla de envío
- **Timeouts optimizados**: Configurables para evitar saturación
- **Sin base de datos local**: No requiere almacenamiento local, solo envía a TikiWiki

## Requisitos

- PHP 8.0+
- Apache con mod_rewrite
- Bot de Telegram (creado con @BotFather)
- TikiWiki 21.x+ con API habilitada
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
   - `telegrammessageChatTitle` (opcional - nombre del grupo)
   - `telegrammessageTopicId`
   - `telegrammessageTopicTitle` (opcional - título del topic)
   - `telegrammessageUserId`
   - `telegrammessageUsername`
   - `telegrammessageFirstName`
   - `telegrammessageLastName`
   - `telegrammessageMessageType`
   - `telegrammessageText`
   - `telegrammessageMedia` (campo de tipo archivo vinculado a la galería)
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
CUSTOM_WEBHOOK_URL=  # Opcional: URL custom para el webhook (ej: https://otro-servidor.com/api.php)
TIKIWIKI_API_URL=http://localhost/tikigram/api/
TIKIWIKI_TOKEN=tu_token_tikiwiki_aqui
TIKIWIKI_TRACKER_ID=1
DEBUG_MODE=false
   ```
4. Configura `ALLOWED_CHAT_IDS` en `config.php` si quieres restringir a chats específicos

### 4. Configurar Webhook de Telegram

**Opción A: Usar la interfaz de administración (recomendado)**

1. Accede a: `https://tu-dominio.com/trackergram/admin.php`
2. Inicia sesión con las credenciales configuradas
3. Ve a la sección "3. Webhook de Telegram"
4. Haz clic en "Actualizar Webhook"

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

### 5. Interfaz de Administración

trackerGram incluye una interfaz web para configurar y administrar:

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
4. La interfaz tiene las siguientes secciones:
   - **1. Configuración de Telegram**: Token del bot, webhook secret, URL custom
   - **2. Tracker en directo**: ID del tracker que recibe mensajes del webhook
   - **3. Webhook de Telegram**: Actualizar webhook automáticamente
   - **4. Tracker de importación**: Importar exports ZIP de Telegram
   - **5. Crear Tracker en TikiWiki**: Crear tracker con todos los campos automáticamente

### 6. Crear Tracker Automáticamente (Opcional)

En lugar de crear el tracker manualmente, puedes usar la sección "5. Crear Tracker en TikiWiki" del panel de administración. El script creará un tracker con todos los campos necesarios:
- Telegram Message ID, Chat ID, Chat Title, Topic ID, Topic Title
- User ID, Username, First Name, Last Name
- Message Type, Text, Media
- Media Type, Media Size, Media Caption, Location, Message Date

### 7. Importar Conversaciones (Opcional)

Para importar conversaciones históricas desde Telegram:
1. Exporta el chat desde Telegram (Settings > Export chat data)
2. En la sección "4. Tracker de importación" del admin
3. Selecciona el tracker destino y sube el archivo ZIP
4. Los mensajes se importarán con sus archivos multimedia

## Estructura de Archivos

```
trackergram/
├── config.php          # Configuración del proyecto
├── bootstrap.php       # Carga centralizada de dependencias
├── api.php             # Webhook endpoint de Telegram
├── WebhookHandler.php  # Lógica de negocio del webhook
├── admin.php           # Interfaz de administración
├── import.php          # Importación de exports de Telegram
├── .env                # Variables de entorno (credenciales)
├── .env.example        # Plantilla de variables de entorno
├── .htaccess           # Configuración Apache
├── README.md           # Documentación para usuarios
├── TECHNICAL.md        # Documentación técnica
├── INSTALL.md          # Guía de instalación
├── CAMBIOS.md          # Changelog
├── roadmap.md          # Roadmap del proyecto
├── CONTEXTO.md         # Guía para nuevos integrantes
├── reports/            # Reportes externos
└── debug.log           # Logs (se crea automáticamente si DEBUG_MODE=true)
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

## Autores y Desarrollo

- **cheperico**: Dirección del proyecto, pruebas y validación
- **OpenCode (minimax-m2.5-free / deepseek-v4-flash-free)**: Asistencia de programación
- **Cascade (SWE-1.5 y SWE-1.6 - Cognition AI Assistant)**: Asistencia de programación
- **Gemini (Google)**: Reportes de seguridad, arquitectura y revisión estratégica
- **OpenAI Codex (GPT-based)**: Revisiones de código

### Sobre el desarrollo

Este proyecto fue desarrollado con asistencia de LLMs. El director del proyecto (cheperico) no es programador profesional y utiliza agentes de IA para implementar las funcionalidades. Esta metodología de desarrollo tiene características particulares:

- Los LLMs son buenos para implementar features específicas, optimizar código y buscar bugs
- Pueden escribir código funcional pero pueden perder contexto en sesiones largas
- La validación final siempre requiere pruebas humanas

## Licencia

MIT License - Ver archivo principal del proyecto para detalles completos.
