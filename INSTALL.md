# trackerGram — Guía de Instalación

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
3. **Crear un token de API**: Admin → Security → API → crear nuevo token con permisos para:
   - `trackers` (leer/crear)
   - `tracker items` (crear)
4. Copiá el token y la URL de la API (debe terminar en `/api/`)

> **Opcional**: Podés crear el tracker manualmente o dejar que trackerGram lo cree automáticamente (Paso 5).

---

## Paso 3: Descargar y Configurar trackerGram

1. Copiá todos los archivos de trackerGram a tu servidor web
2. Copiá la plantilla de configuración:
   ```bash
   cp .env.example .env
   ```
3. Editá `.env` con tus credenciales:
   ```env
   TELEGRAM_BOT_TOKEN=1234567890:ABCdefGHIjklMNOpqrsTUVwxyz
   TELEGRAM_WEBHOOK_SECRET=un_token_secreto_aleatorio_de_32_caracteres
   TIKIWIKI_API_URL=https://wiki.ejemplo.com/api/
   TIKIWIKI_TOKEN=tu_token_de_tikiwiki
   TIKIWIKI_TRACKER_ID=1
   ADMIN_USERNAME=admin
   ADMIN_PASSWORD=una_contraseña_segura
   DEBUG_MODE=false
   ```
4. Configurá los permisos:
   ```bash
   sudo chown -R www-data:www-data /ruta/a/trackergram
   sudo chmod -R 755 /ruta/a/trackergram
   sudo chmod 600 /ruta/a/trackergram/.env
   ```

---

## Paso 4: Configurar el Webhook

### Opción A: Panel de administración (recomendado)

1. Accedé a `https://tu-dominio.com/trackergram/admin.php`
2. Iniciá sesión con las credenciales del `.env`
3. Configurá el bot token y webhook secret en "Configuración general"
4. Hacé clic en **"Actualizar Webhook"**

### Opción B: Manual con curl

```bash
curl "https://api.telegram.org/bot<TU_TOKEN>/setWebhook?url=https://tu-dominio.com/trackergram/api.php&secret_token=TU_SECRET"
```

### Verificar el webhook

```bash
curl "https://api.telegram.org/bot<TU_TOKEN>/getWebhookInfo"
```

Deberías ver `"ok": true` y `"url"` con tu URL.

---

## Paso 5: Verificar que Funciona

1. **Panel de admin**: Accedé a `https://tu-dominio.com/trackergram/admin.php` — deberías ver la configuración
2. **Crear tracker** (si no tenés uno): En el panel, sección "Crear Tracker", escribí un nombre y hacé clic en crear
3. **Enviar un mensaje de prueba**: Escribí algo en el grupo de Telegram donde está tu bot
4. **Verificar en TikiWiki**: Abrí el tracker y deberías ver el mensaje como un nuevo item

### Si no funciona

1. Activá `DEBUG_MODE=true` en `.env`
2. Revisá `debug.log` para ver errores
3. Verificá los logs del servidor web:
   ```bash
   sudo tail -f /var/log/apache2/error.log
   ```

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
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 30
memory_limit = 256M
```

---

## Ejecución con Docker

Si usás Docker para desarrollo:

```bash
# Ver logs
docker compose logs --tail 30

# Logs en tiempo real
docker compose logs -f

# Reiniciar
docker compose down
docker compose up -d
```

### Permisos en Docker

Si el contenedor no puede escribir archivos (`.env`, `topic_names.json`, `debug.log`):

```bash
sudo chown -R www-data:www-data /ruta/a/trackergram
```

---

## Solución de Problemas

| Problema | Qué hacer |
|---|---|
| **500 Internal Server Error** | Revisá logs de Apache y sintaxis PHP: `php -l api.php` |
| **Webhook no responde** | Verificá que la URL sea pública con HTTPS. Probá con `curl -I https://tu-dominio.com/api.php` |
| **Error al conectar a TikiWiki** | Probá manualmente: `curl -H "Authorization: Bearer $TOKEN" "$TIKIWIKI_API_URL/trackers/$TRACKER_ID"` |
| **Mensajes duplicados** | Verificá que `TELEGRAM_WEBHOOK_SECRET` esté configurado correctamente |
| **Contraseña de admin olvidada** | Editá `.env` y cambiá `ADMIN_PASSWORD` manualmente |
