# trackerGram — Guía de Instalación

**Repositorio**: https://github.com/cheperico/trackergram

## Requisitos

- **PHP 8.0+** con extensiones: `curl`, `json`, `mbstring`, `session`, `zip`
- **Apache 2.4+** con `mod_rewrite` (o Nginx)
- **HTTPS** obligatorio (Telegram requiere URLs seguras para webhooks)
- **TikiWiki 21.x+** con API habilitada

### Verificar extensiones PHP

```bash
php -m | grep -E "(curl|json|mbstring|zip)"
```

---

## Paso 1: Crear el Bot de Telegram

1. Abrí Telegram y buscá [@BotFather](https://t.me/BotFather)
2. Enviá el comando `/newbot`
3. Seguí las instrucciones:
   - Elegí un nombre para tu bot (ej: "trackerGram Bot")
   - Elegí un username que termine en `_bot` (ej: `trackergram_bot`)
4. BotFather te dará un **token** — copialo, lo vas a necesitar

---

## Paso 2: Preparar TikiWiki

1. Accedé a tu TikiWiki como administrador
2. **Habilitar la API**: Admin → Security → API → activar

3. **Crear grupo, usuario y permisos** — trackerGram necesita permisos específicos, no alcanza con ser admin general. Seguí estos pasos:

   ### 3a. Crear el grupo `trackerGram`
   **Admin → Grupos → Crear un nuevo grupo**
   - Nombre: `trackerGram`
   - Descripción: Usuarios de la API de trackerGram
   - Heredar de: ninguno (o `Registered`)

   ### 3b. Asignar permisos al grupo
   **Admin → Grupos → trackerGram → Permisos** — Agregar:

   | Permiso | Ámbito | Para qué |
   |---------|--------|----------|
   | `tiki_p_view_trackers` | Objeto (tracker) | Leer fields del tracker |
   | `tiki_p_create_tracker_items` | Objeto (tracker) | Crear items (mensajes) |
   | `tiki_p_upload_files` | Objeto (file gallery) | Subir fotos/videos/audios |
   | `tiki_p_view_file_gallery` | Objeto (file gallery) | Acceder a la galería |
   | `tiki_p_admin_trackers` | **Global** ⚠️ | Deduplicación, crear/editar fields, asignar galería |
   | `tiki_p_admin_file_galleries` | Objeto (file gallery) | Crear galerías automáticamente |

   > **⚠️ Importante**: `tiki_p_admin_trackers` debe ser **Global**, no por objeto. Si solo se asigna a nivel de tracker individual, la deduplicación fallará con error 403.

   ### 3c. Crear el usuario
   **Admin → Usuarios → Crear un nuevo usuario**
   - Usuario: `trackergram` (por ejemplo)
   - Contraseña: elegí una segura
   - Grupos: agregar a `trackerGram`

   ### 3d. Crear el token de API
   **Admin → Seguridad → API → Crear token**
   - Usuario asociado: `trackergram`
   - Permisos: marcar todos (los reales los controla el grupo)

4. Copiá el token y la URL de la API (debe terminar en `/api/`)

> **Opcional**: Podés crear el tracker manualmente o dejar que trackerGram lo cree automáticamente (Paso 5). Para la lista completa de campos del tracker, ver [README.md → Campos del Tracker](README.md#campos-del-tracker).

---

## Paso 3: Descargar y Configurar trackerGram

1. Copiá todos los archivos de trackerGram a tu servidor web
2. Copiá la plantilla de configuración:
   ```bash
   cp .env.example .env
   ```
3. Editá `.env` con las credenciales de admin y configuración global:
    ```env
    ADMIN_USERNAME=admin
    ADMIN_PASSWORD=una_contraseña_segura
    DEBUG_MODE=false
    ASYNC_PROCESSING=false
    
    # NOTA: Las credenciales de bots y wikis se configuran desde
    # el panel de admin (setup.json). .env solo tiene config global.
    ```
4. Configurá los permisos:
   ```bash
   sudo chown -R www-data:www-data /ruta/a/trackergram
   sudo chmod -R 755 /ruta/a/trackergram
   sudo chmod 600 /ruta/a/trackergram/.env
   ```

---

## Paso 4: Crear una Conexión en el Panel

1. Accedé a `https://tu-dominio.com/trackergram/admin.php`
2. Iniciá sesión con las credenciales del `.env`
3. En la pestaña **Webhook**, hacé clic en **"+ Agregar conexión"**
4. Completá los datos:
   - **Nombre**: Un nombre legible (ej: "QPCH Producción")
   - **Bot Token**: El token que te dio BotFather
   - **Webhook Secret**: Un string secreto único para este webhook
   - **Chat ID**: ID del grupo de Telegram (obtenelo con [@userinfobot](https://t.me/userinfobot))
   - **TikiWiki API URL**: `https://wiki.ejemplo.com/api/`
   - **TikiWiki Token**: Token de API de TikiWiki
   - **Tracker ID**: ID del tracker destino
5. Una vez creada la conexión, hacé clic en **"🌐 Webhook"** para configurar el webhook automáticamente
6. Hacé clic en **"🧪 Test"** para verificar que todo funciona

### Verificar el webhook manualmente

```bash
curl "https://api.telegram.org/bot<TU_TOKEN>/getWebhookInfo"
```

Deberías ver `"ok": true` y `"url"` apuntando a `https://tu-dominio.com/trackergram/api.php`.

---

## Paso 5: Verificar que Funciona

1. **Panel de admin**: Accedé a `https://tu-dominio.com/trackergram/admin.php` — deberías ver tus conexiones
2. **Crear tracker** (si no tenés uno): Desde los botones de la conexión, usá **"Crear Tracker"** o hacélo manualmente en TikiWiki
3. **Agregá el bot al grupo**: El bot debe ser miembro del grupo cuyo `chat_id` configuraste
4. **Enviar un mensaje de prueba**: Escribí algo en el grupo de Telegram donde está tu bot
5. **Verificar en TikiWiki**: Abrí el tracker y deberías ver el mensaje como un nuevo item

### Si no funciona

1. Activá `DEBUG_MODE=true` en `.env`
2. Revisá `debug.log` para ver errores
3. Verificá los logs del servidor web:
   ```bash
   sudo tail -f /var/log/apache2/error.log
   ```
4. Probá el webhook manualmente: `curl "https://api.telegram.org/bot<TU_TOKEN>/getWebhookInfo"`

---

## Configuración Avanzada (Opcional)

### SSL/TLS con Let's Encrypt

```bash
# Apache
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d tu-dominio.com

# Nginx
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d tu-dominio.com
```

### Firewall (UFW)

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### IPs de Telegram (si usás firewall restrictivo)

Si tu firewall bloquea IPs no conocidas, necesitás permitir las subredes de Telegram:
- 149.154.160.0/20
- 91.108.4.0/22

### php.ini recomendado

```ini
upload_max_filesize = 200M
post_max_size = 200M
max_execution_time = 300
memory_limit = 512M
```

> **Nota**: Los valores dependen del uso. Para importar exports ZIP grandes (≥500MB) se necesita `max_execution_time` alto y suficiente memoria. El archivo `.htaccess` incluido ya configura estos límites para los endpoints HTTP.

### Límites internos de trackerGram

Estos límites se definen como constantes en `config.php`:

| Constante | Valor | Descripción |
|---|---|---|
| `MEDIA_DOWNLOAD_MAX_SIZE` | 20 MB | Máximo tamaño de archivo multimedia que se descarga de Telegram |
| `MAX_ZIP_UNCOMPRESSED_SIZE` | 500 MB | Máximo tamaño total descomprimido de un ZIP importado |
| `TIMEOUT_TIKIWIKI_UPLOAD` | 60 s | Timeout para subir archivos a TikiWiki |
| `TIMEOUT_TIKIWIKI_API` | 30 s | Timeout general para llamadas a la API de TikiWiki |

---

## Paso 6 (Opcional): Configurar la Vista Wiki Feed

trackerGram incluye una plantilla Smarty para mostrar los mensajes del tracker como un feed tipo chat en una página wiki de TikiWiki.

1. Creá dos páginas wiki en TikiWiki:
   - `plantillaTrackergram` — contiene el template Smarty por-item
   - `ChatTelegram` (o el nombre que quieras) — página principal con el `{TRACKERLIST}` + CSS

2. Copiá el contenido de `opt/visualizacion-tiki.md` según la sección que corresponda (investigación e implementación están separadas).

3. Asegurate de que los plugins necesarios estén habilitados en TikiWiki:
   - `wikiplugin_html` (habilitado + aprobado)
   - `wikiplugin_mediaplayer` (para audio/video)
   - `wikiplugin_trackerlist` (normalmente activo por defecto)

4. Ajustá el `trackerId="22"` en el `{TRACKERLIST}` al ID real de tu tracker.

Ver [opt/visualizacion-tiki.md](opt/visualizacion-tiki.md) para la documentación completa y código de las plantillas.

---

| Problema | Qué hacer |
|---|---|
| **500 Internal Server Error** | Revisá logs de Apache y sintaxis PHP: `php -l api.php` |
| **Webhook no responde** | Verificá que la URL sea pública con HTTPS. Probá con `curl -I https://tu-dominio.com/trackergram/api.php` |
| **Error al conectar a TikiWiki** | Usá el botón "🧪 Test" en la conexión para verificar las credenciales |
| **Mensajes duplicados** | Verificá que el webhook secret esté bien configurado en la conexión |
| **Contraseña de admin olvidada** | Editá `.env` y cambiá `ADMIN_PASSWORD` manualmente |
| **No llegan mensajes nuevos** | Verificá que el bot sea miembro del grupo y que el webhook esté activo (`getWebhookInfo`) |
