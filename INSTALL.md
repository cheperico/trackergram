# trackerGram - Guía de Instalación

## Requisitos Previos

### Sistema Operativo
- Linux (Ubuntu 20.04+, CentOS 8+, Debian 10+)
- macOS 10.15+
- Windows 10+ (con WAMP/XAMPP)

### Servidor Web
- **Apache 2.4+** (recomendado) con mod_rewrite
- **Nginx 1.18+** (alternativa)

### PHP
- **PHP 7.4+** (recomendado 8.0+)
- Extensiones requeridas:
  ```bash
  # Ubuntu/Debian
  sudo apt install php php-curl php-json php-mbstring php-session

  # CentOS/RHEL
  sudo yum install php php-curl php-json php-mbstring

  # macOS (Homebrew)
  brew install php
  ```

### TikiWiki
- **TikiWiki 21.x+** instalado y configurado
- Acceso administrativo para crear trackers
- API habilitada

## Instalación Paso a Paso

### 1. Descargar los Archivos

```bash
# Clonar el repositorio (si está disponible)
git clone https://github.com/usuario/telegram-a-algo.git
cd telegram-a-algo/trackergram

# O descargar los archivos manualmente al directorio web
```

### 2. Configurar el Servidor Web

#### Apache

```bash
# Crear VirtualHost
sudo nano /etc/apache2/sites-available/trackergram.conf
```

```apache
<VirtualHost *:80>
    ServerName trackergram.dominio.com
    DocumentRoot /var/www/trackergram
    
    <Directory /var/www/trackergram>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/trackergram_error.log
    CustomLog ${APACHE_LOG_DIR}/trackergram_access.log combined
</VirtualHost>
```

```bash
# Habilitar el sitio y módulos
sudo a2ensite trackergram
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Nginx

```nginx
server {
    listen 80;
    server_name trackergram.dominio.com;
    root /var/www/trackergram;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
```

### 3. Configurar Permisos

```bash
# Establecer permisos correctos
sudo chown -R www-data:www-data /var/www/trackergram
sudo chmod -R 755 /var/www/trackergram
sudo chmod 600 /var/www/trackergram/.env
```

### 4. Configurar Variables de Entorno

```bash
# Copiar plantilla de configuración
cp .env.example .env
nano .env
```

```env
# Token del Bot de Telegram (obtenido de @BotFather)
TELEGRAM_BOT_TOKEN=1234567890:ABCdefGHIjklMNOpqrsTUVwxyz

# Secret Token para Webhook (generar uno seguro)
TELEGRAM_WEBHOOK_SECRET=tu_token_secreto_32_caracteres_aleatorio

# Configuración TikiWiki
TIKIWIKI_API_URL=https://wiki.dominio.com/api/
TIKIWIKI_TOKEN=tu_bearer_token_de_tikiwiki
TIKIWIKI_TRACKER_ID=1

# Credenciales de Administración
ADMIN_USERNAME=admin
ADMIN_PASSWORD=tu_contraseña_segura_aqui

# Configuración de la Aplicación
DEBUG_MODE=false
```

### 5. Configurar TikiWiki

#### 5.1 Crear Tracker

1. Acceder a TikiWiki como administrador
2. Ir a **Trackers** → **New Tracker**
3. Configurar tracker con los siguientes campos:

| Nombre del Campo | Permanent Name | Tipo |
|------------------|----------------|------|
| Telegram Message ID | telegrammessageTelegramMessageId | Text Field |
| Chat ID | telegrammessageChatId | Text Field |
| Topic ID | telegrammessageTopicId | Text Field |
| User ID | telegrammessageUserId | Text Field |
| Username | telegrammessageUsername | Text Field |
| First Name | telegrammessageFirstName | Text Field |
| Last Name | telegrammessageLastName | Text Field |
| Message Type | telegrammessageMessageType | Text Field |
| Text | telegrammessageText | Text Area |
| Media | telegrammessageMedia | **File Gallery** (vinculado a galería) |
| Media URL | telegrammessageMediaUrl | Text Field |
| File URL | telegrammessageFileUrl | Text Field |
| Media Type | telegrammessageMediaType | Text Field |
| Media Size | telegrammessageMediaSize | Text Field |
| Media Caption | telegrammessageMediaCaption | Text Field |
| Message Date | telegrammessageMessageDate | Text Field |

> **Nota**: El campo `telegrammessageMedia` debe ser de tipo **File Gallery** y estar vinculado a la galería donde se almacenarán los archivossubidos desde Telegram.

#### 5.2 Generar Token de API

1. Ir a **Admin** → **Security** → **API**
2. Crear nuevo token con permisos para:
   - `trackers` (leer/crear)
   - `tracker items` (crear)

### 6. Crear Bot de Telegram

1. Hablar con **@BotFather** en Telegram
2. Enviar `/newbot`
3. Seguir las instrucciones:
   ```
   Nombre del bot: trackerGram Bot
   Username: trackergram_bot (debe terminar en _bot)
   ```
4. Copiar el token generado
5. Configurar descripción, foto, etc. (opcional)

### 7. Configurar el Webhook

#### Opción A: Script Automático (Recomendado)

```bash
# Acceder al script desde el navegador
https://trackergram.dominio.com/setup_webhook.php
```

El script detectará automáticamente la URL y configurará el webhook.

#### Opción B: Configuración Manual

```bash
# Construir URL del webhook
WEBHOOK_URL="https://trackergram.dominio.com/api.php"
SECRET_TOKEN="tu_token_secreto_32_caracteres"

# Configurar webhook con curl
curl "https://api.telegram.org/bot<TU_BOT_TOKEN>/setWebhook?url=$WEBHOOK_URL&secret_token=$SECRET_TOKEN"
```

#### 7.1 Verificar Configuración

```bash
# Verificar estado del webhook
curl "https://api.telegram.org/bot<TU_BOT_TOKEN>/getWebhookInfo"
```

### 8. Probar la Instalación

#### 8.1 Probar Interfaz de Administración

1. Acceder a: `https://trackergram.dominio.com/admin.php`
2. Iniciar sesión con las credenciales configuradas
3. Verificar que se muestra la configuración actual

#### 8.2 Probar Webhook

```bash
# Enviar mensaje de prueba
curl -X POST https://trackergram.dominio.com/api.php \
  -H "Content-Type: application/json" \
  -H "X-Telegram-Bot-Api-Secret-Token: tu_token_secreto" \
  -d '{
    "update_id": 1,
    "message": {
      "message_id": 1,
      "from": {
        "id": 123456789,
        "first_name": "Test",
        "username": "test_user"
      },
      "chat": {
        "id": -100123456789,
        "type": "supergroup"
      },
      "date": 1640995200,
      "text": "Mensaje de prueba"
    }
  }'
```

#### 8.3 Verificar en TikiWiki

1. Acceder al tracker configurado
2. Verificar que aparece el nuevo item
3. Comprobar que todos los campos están poblados correctamente

## Configuración Avanzada

### SSL/TLS (HTTPS)

#### Apache con Let's Encrypt

```bash
# Instalar Certbot
sudo apt install certbot python3-certbot-apache

# Obtener certificado
sudo certbot --apache -d trackergram.dominio.com

# Renovación automática
sudo crontab -e
# Agregar: 0 12 * * * /usr/bin/certbot renew --quiet
```

#### Nginx con Let's Encrypt

```bash
# Instalar Certbot
sudo apt install certbot python3-certbot-nginx

# Obtener certificado
sudo certbot --nginx -d trackergram.dominio.com
```

### Firewall

#### UFW (Ubuntu)

```bash
# Permitir HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Permitir SSH (si es necesario)
sudo ufw allow 22/tcp

# Activar firewall
sudo ufw enable
```

#### iptables

```bash
# Permitir HTTP/HTTPS
sudo iptables -A INPUT -p tcp --dport 80 -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 443 -j ACCEPT

# Guardar reglas
sudo iptables-save > /etc/iptables/rules.v4
```

### Optimización de PHP

#### php.ini

```ini
; Aumentar límites para procesamiento de archivos
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 30
memory_limit = 256M

; Optimización para producción
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=4000
```

### Configuración de Logging

#### Logrotate

```bash
sudo nano /etc/logrotate.d/trackergram
```

```
/var/www/trackergram/debug.log {
    daily
    missingok
    rotate 7
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
    postrotate
        systemctl reload apache2
    endscript
}
```

## Verificación y Diagnóstico

### Checklist de Instalación

- [ ] PHP 7.4+ con extensiones requeridas
- [ ] Servidor web configurado
- [ ] Permisos de archivos correctos
- [ ] Variables de entorno configuradas
- [ ] Bot de Telegram creado
- [ ] Tracker en TikiWiki configurado
- [ ] Token de API de TikiWiki generado
- [ ] Webhook configurado
- [ ] SSL/TLS configurado (producción)
- [ ] Firewall configurado
- [ ] Logs funcionando
- [ ] Mensajes de prueba funcionando

### Comandos de Verificación

```bash
# Verificar configuración PHP
php -m | grep -E "(curl|json|mbstring)"

# Verificar permisos
ls -la /var/www/trackergram/.env

# Probar conexión a TikiWiki
curl -H "Authorization: Bearer $TIKIWIKI_TOKEN" \
     "$TIKIWIKI_API_URL/trackers/$TIKIWIKI_TRACKER_ID/items"

# Verificar logs
tail -f /var/www/trackergram/debug.log

# Verificar estado Apache
sudo systemctl status apache2

# Verificar errores de Apache
sudo tail -f /var/log/apache2/error.log
```

## Solución de Problemas

### Errores Comunes

#### 1. "500 Internal Server Error"

```bash
# Verificar logs de Apache
sudo tail -f /var/log/apache2/error.log

# Verificar sintaxis de PHP
php -l /var/www/trackergram/api.php

# Verificar permisos
ls -la /var/www/trackergram/
```

#### 2. "Webhook no responde"

```bash
# Verificar si el servidor está accesible
curl -I https://trackergram.dominio.com/api.php

# Verificar configuración del webhook
curl "https://api.telegram.org/bot<TU_TOKEN>/getWebhookInfo"

# Probar con secret token incorrecto (debe dar 403)
curl -X POST https://trackergram.dominio.com/api.php \
  -H "X-Telegram-Bot-Api-Secret-Token: incorrecto" \
  -d '{}'
```

#### 3. "Error al conectar a TikiWiki"

```bash
# Probar conexión manual
curl -v -H "Authorization: Bearer $TOKEN" \
     "$TIKIWIKI_API_URL/trackers/$TRACKER_ID/items"

# Verificar si el tracker existe
curl -H "Authorization: Bearer $TOKEN" \
     "$TIKIWIKI_API_URL/trackers/$TRACKER_ID"
```

#### 4. "Mensajes no llegan a TikiWiki"

```bash
# Activar debug mode
sed -i 's/DEBUG_MODE=false/DEBUG_MODE=true/' .env

# Enviar mensaje de prueba y revisar logs
tail -f /var/www/trackergram/debug.log

# Verificar campos del tracker
curl -H "Authorization: Bearer $TOKEN" \
     "$TIKIWIKI_API_URL/trackers/$TRACKER_ID/fields"
```

### Tips de Rendimiento

1. **Habilitar OPcache**: Aumenta rendimiento significativamente
2. **Configurar CDN**: Para archivos multimedia si hay mucho tráfico
3. **Monitorear recursos**: Usar `top`, `htop` para verificar uso de CPU/memoria
4. **Ajustar timeouts**: Si hay latencia alta, aumentar timeouts de cURL

## Mantenimiento

### Actualizaciones

```bash
# Respaldar configuración
cp .env .env.backup

# Actualizar archivos (si es un repositorio git)
git pull origin main

# Restaurar configuración
cp .env.backup .env

# Reiniciar servicios
sudo systemctl restart apache2
```

### Limpieza de Logs

```bash
# Limpiar logs antiguos
find /var/www/trackergram -name "*.log" -mtime +30 -delete

# Comprimir logs grandes
gzip /var/www/trackergram/debug.log.1
```

### Monitoreo

```bash
# Script de monitoreo simple
#!/bin/bash
# monitor_trackergram.sh

WEBHOOK_STATUS=$(curl -s "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/getWebhookInfo" | jq -r '.ok')
if [ "$WEBHOOK_STATUS" != "true" ]; then
    echo "ALERTA: Webhook no está funcionando"
fi

TIKIWIKI_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TIKIWIKI_TOKEN" "$TIKIWIKI_API_URL/trackers/$TIKIWIKI_TRACKER_ID")
if [ "$TIKIWIKI_STATUS" != "200" ]; then
    echo "ALERTA: No se puede conectar a TikiWiki"
fi
```

## Soporte y Ayuda

### Recursos

- **Documentación técnica**: `TECHNICAL.md`
- **README del proyecto**: `README.md`
- **Issues y soporte**: GitHub del proyecto

### Comunidad

- **Telegram**: Grupo de soporte del proyecto
- **Foro TikiWiki**: Para problemas específicos de TikiWiki
- **Stack Overflow**: Para problemas generales de PHP/Telegram

### Contacto

- **Email**: soporte@dominio.com
- **GitHub Issues**: Reportar bugs y solicitar features
