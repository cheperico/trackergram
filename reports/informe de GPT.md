# Informe de GPT - Revisión integral de trackerGram

Fecha de revisión: 2026-05-16  
Proyecto revisado: `trackergram`  
Archivo de entrada usado: `AGENTS.md`

## Resumen ejecutivo

trackerGram ya tiene una base funcional razonable para un MVP: recibe webhooks de Telegram, transforma mensajes, sube media a TikiWiki y permite importar exports históricos. La evolución reciente se nota: `api.php` quedó reducido a un entry point liviano, la lógica principal pasó a `WebhookHandler.php`, y existen clientes dedicados para Telegram y TikiWiki.

La prioridad actual no debería ser agregar muchas funcionalidades nuevas, sino estabilizar tres frentes:

1. Seguridad operativa: cerrar el webhook si no hay secret, endurecer el importador ZIP, proteger mejor el admin y evitar exposición de datos sensibles en logs o respuestas.
2. Correctitud funcional: corregir dependencias faltantes en `admin.php` e `import.php`, arreglar el flujo de cambio de contraseña, y asegurar que importación, creación de tracker y media funcionen de punta a punta.
3. Arquitectura: consolidar el mapeo de mensajes, introducir una capa de servicios testeable y mover imports grandes a un proceso CLI/asíncrono.

Verificación realizada:

- Se ejecutó lint PHP sobre todos los archivos `.php`; no hay errores de sintaxis.
- No se leyó ni se volcó el contenido de `.env`, aunque se verificó que existe y que `.gitignore` lo ignora.
- `README.md` tiene cambios previos no hechos por esta revisión; no fue modificado.

---

## 1. Revisión de seguridad

### Hallazgos de prioridad alta

#### 1.1. El secret del webhook sigue siendo opcional

Ubicación: `api.php`

El código registra un error si `TELEGRAM_WEBHOOK_SECRET` está vacío, pero aun así acepta webhooks sin verificar el header `X-Telegram-Bot-Api-Secret-Token`. Esto contradice el roadmap, donde figura como obligatorio.

Impacto:

- Cualquier cliente que conozca la URL puede enviar JSON falso al webhook.
- Si `ALLOWED_CHAT_IDS` está vacío, el riesgo aumenta porque también se acepta cualquier `chat_id`.
- Incluso con `ALLOWED_CHAT_IDS`, un atacante puede falsificar el `chat.id` dentro del JSON si no hay secret.

Recomendación:

- En producción, fallar cerrado: si `TELEGRAM_WEBHOOK_SECRET` está vacío, responder `500` o `403` y no procesar.
- Permitir modo inseguro solo explícitamente, por ejemplo `ALLOW_INSECURE_WEBHOOK=true`, y mostrarlo como advertencia fuerte en admin.

#### 1.2. `ALLOWED_CHAT_IDS` por defecto acepta todos los chats

Ubicación: `config.php`, `.env.example`, `WebhookHandler.php`

El comentario actual dice que vacío equivale a procesar todos los chats. Para un puente que guarda información en TikiWiki, conviene que el comportamiento por defecto sea más conservador.

Impacto:

- Si el bot se agrega accidentalmente a otro grupo, trackerGram puede archivar conversaciones no previstas.
- En combinación con un webhook sin secret, permite inyección de mensajes arbitrarios.

Recomendación:

- Hacer que `ALLOWED_CHAT_IDS` sea obligatorio para producción.
- En admin, mostrar estado de seguridad: secret configurado, chats permitidos, HTTPS, permisos de `.env`.
- Permitir "todos los chats" solo con una variable explícita, por ejemplo `ALLOW_ALL_CHATS=true`.

#### 1.3. El importador ZIP es vulnerable a zip bombs y abuso de recursos

Ubicación: `import.php`

Hay una mejora correcta contra path traversal básico: se rechazan entradas con `..` o ruta absoluta Unix. Sin embargo, todavía faltan controles importantes:

- No se valida tamaño total descomprimido.
- No se valida cantidad máxima de archivos.
- No se valida profundidad de carpetas.
- No se rechazan rutas absolutas estilo Windows, por ejemplo `C:\...`.
- No se normalizan separadores `\`.
- Se lee `result.json` completo en memoria.

Impacto:

- Un administrador autenticado, o una sesión admin comprometida, puede agotar disco, memoria o tiempo de ejecución con un ZIP especialmente armado.
- Un export legítimo muy grande puede romper el proceso HTTP.

Recomendación:

- Antes de extraer, calcular `numFiles`, `statIndex()['size']` y `statIndex()['comp_size']`.
- Definir límites de cantidad de archivos, tamaño descomprimido total y tamaño de `result.json`.
- Normalizar nombres con `/` y `\`, rechazar `:`, rutas absolutas y nombres vacíos.
- Para imports grandes, mover a CLI/asíncrono.

#### 1.4. `admin.php` e `import.php` tienen dependencias faltantes

Ubicaciones:

- `admin.php` llama a `TikiWikiClient::createTracker()` pero solo carga `config.php`.
- `import.php` llama a `TikiWikiClient` y `MessageMapper` pero solo carga `config.php`.

Impacto:

- Crear tracker desde admin puede fallar fatalmente en runtime.
- Importar ZIP puede fallar al llegar a `TikiWikiClient::getMediaGalleryId()` o `MessageMapper::toWikiFields()`.
- En producción, errores fatales pueden terminar exponiendo trazas si el entorno no está bien configurado.

Recomendación:

- Agregar `require_once 'TikiWikiClient.php';` y `require_once 'MessageMapper.php';` donde corresponda.
- A mediano plazo, usar autoload simple o Composer.

#### 1.5. El cambio de contraseña está ubicado dentro de `checkAuth()`

Ubicación: `admin.php`, bloque `change_password`

El formulario existe en la UI, pero el procesamiento quedó insertado dentro del flujo de login de `checkAuth()`. Si el usuario ya está autenticado, `checkAuth()` retorna `true` y el bloque de cambio de contraseña no se ejecuta.

Impacto:

- La UI promete una acción sensible que probablemente no funciona.
- Puede generar falsa sensación de rotación de credenciales.

Recomendación:

- Mover el bloque `change_password` fuera de `checkAuth()`, después de validar autenticación y CSRF.
- Regenerar sesión luego de cambiar contraseña.
- Opcional: pedir contraseña actual antes de aceptar una nueva.

### Hallazgos de prioridad media

#### 1.6. Rate limit de login basado solo en sesión

Ubicación: `admin.php`

Los intentos fallidos se guardan en `$_SESSION`. Un atacante puede evadirlo descartando cookies o iniciando sesiones nuevas.

Recomendación:

- Agregar rate limit por IP/usuario en archivo temporal, APCu o TikiWiki.
- Considerar allowlist de IP para `admin.php` en instalaciones chicas.

#### 1.7. Posible XSS en resultados de importación

Ubicación: `admin.php`, JavaScript de `importExport()`

El código inserta respuestas con `innerHTML`, incluyendo `data.error` y texto de respuestas inválidas. Si alguna respuesta contiene HTML controlable, podría ejecutarse en el navegador del administrador.

Recomendación:

- Construir nodos DOM con `textContent`.
- Si se necesita formato, escapar explícitamente antes de usar `innerHTML`.

#### 1.8. Host header usado para generar webhook

Ubicación: `admin.php`, `generateWebhookUrl()`

La URL automática usa `$_SERVER['HTTP_HOST']`. Esto puede producir una URL incorrecta o manipulable si el proxy/web server no valida el header `Host`.

Recomendación:

- Preferir `CUSTOM_WEBHOOK_URL` como fuente canónica.
- Agregar `APP_BASE_URL` obligatorio en producción.
- Validar host contra una allowlist.

#### 1.9. Tokens enviados por HTTP si TikiWiki API usa `http://`

Ubicación: validación de `TIKIWIKI_API_URL` en `admin.php`

La validación permite `http://` y `https://`. En producción, un bearer token de TikiWiki no debería viajar por HTTP.

Recomendación:

- Requerir HTTPS para `TIKIWIKI_API_URL` y `CUSTOM_WEBHOOK_URL` salvo modo desarrollo explícito.

#### 1.10. Logs con posible información sensible o privada

Ubicaciones: `TikiWikiClient.php`, `WebhookHandler.php`, `config.php`

Se registran respuestas de TikiWiki y metadatos de archivos/mensajes. Puede ser útil para debug, pero en producción puede exponer contenido privado, IDs internos, nombres o detalles de fallos.

Recomendación:

- Redactar tokens y limitar cuerpos de respuesta.
- Separar logs operativos de logs de debug.
- Evitar guardar texto completo de mensajes salvo que `DEBUG_MODE` esté activo.

#### 1.11. Descarga de media depende de `Content-Length`

Ubicación: `WebhookHandler::downloadAndUploadMedia()`

Se hace una consulta `HEAD` para leer `CURLINFO_CONTENT_LENGTH_DOWNLOAD`. Si el servidor no informa tamaño, o informa `-1`, el límite de 20 MB puede no aplicarse correctamente durante la descarga real.

Recomendación:

- Validar también `file_size` del objeto Telegram cuando esté disponible.
- Durante streaming, abortar si bytes descargados supera `MEDIA_DOWNLOAD_MAX_SIZE`.
- Cerrar explícitamente el handle de archivo temporal.

### Buenas prácticas ya presentes

- Uso de `hash_equals()` para comparar el secret cuando está configurado.
- Sesión admin con `httponly`, `SameSite=Strict`, strict mode y regeneración periódica.
- CSRF en formularios y en `import.php`.
- `.env`, logs y Markdown bloqueados en `.htaccess` para Apache.
- `.env` está en `.gitignore` y no aparece en `git ls-files`.
- Media de Telegram se sube a TikiWiki y no se guarda la URL con token en el tracker.
- Deduplicación por `(chat_id, message_id)` en el flujo webhook.

---

## 2. Revisión de arquitectura, eficiencia y legibilidad

### Lo que está bien encaminado

#### 2.1. `api.php` ya es un entry point chico

El contexto histórico decía que `api.php` era monolítico, pero ahora está mucho mejor: recibe JSON, valida secret, aplica rate limit y delega en `WebhookHandler`.

Esto es una mejora importante porque deja claro dónde termina HTTP y dónde empieza la lógica del dominio.

#### 2.2. Separación en clientes

`TikiWikiClient.php` y `TelegramClient.php` centralizan llamadas externas. Esto evita tener `curl` repetido por todo el proyecto y facilita encontrar problemas de integración.

#### 2.3. `MessageMapper` concentra parte de la transformación

`MessageMapper::fromWebhook()` y `MessageMapper::toWikiFields()` son una base útil para estabilizar el contrato entre Telegram y TikiWiki.

#### 2.4. El proyecto mantiene una filosofía simple

La decisión de no tener base de datos local sigue siendo coherente con el objetivo del MVP. Reduce instalación, superficie operativa y mantenimiento.

### Problemas y mejoras recomendadas

#### 2.5. Falta un autoload o bootstrap común

Ahora cada entry point decide qué cargar. Eso ya produjo fallos: `admin.php` e `import.php` usan clases no requeridas.

Recomendación:

- Crear `bootstrap.php` que cargue `config.php`, clientes, mapper y funciones compartidas.
- O adoptar Composer con PSR-4 si el proyecto va a crecer.

#### 2.6. Demasiados métodos estáticos y constantes globales

El uso de `TikiWikiClient::...`, `TelegramClient::...` y constantes globales hace que el código sea simple al principio, pero difícil de testear y simular.

Recomendación incremental:

1. Crear clases instanciables con configuración inyectada.
2. Mantener wrappers estáticos temporalmente para no romper todo.
3. Escribir tests contra instancias con clientes HTTP fake.

#### 2.7. `admin.php` mezcla autenticación, acciones, persistencia y HTML

El archivo administra sesiones, valida formularios, guarda `.env`, llama APIs externas y renderiza HTML/JS. Funciona para MVP, pero ya está costando: `change_password` quedó en el lugar equivocado y `checkAuth()` se llama dos veces.

Recomendación:

- Separar helpers de sesión/auth.
- Crear handlers por acción: `saveGeneral`, `saveTracker`, `updateWebhook`, `createTracker`, `changePassword`.
- Mantener una sola llamada a `checkAuth()` por request.

#### 2.8. El importador duplica lógica del webhook

`import.php` detecta tipos, topics, nombres, fechas y media con lógica propia. Luego recién usa `MessageMapper::toWikiFields()` al final. En paralelo, `MessageMapper` tiene métodos para webhook y métodos más viejos para exports.

Riesgo:

- Webhook e importación pueden producir items diferentes para mensajes equivalentes.
- Agregar campos nuevos obliga a tocar varios lugares.

Recomendación:

- Definir un modelo intermedio único, por ejemplo `NormalizedMessage`.
- Hacer dos parsers de entrada: `TelegramWebhookParser` y `TelegramExportParser`.
- Hacer una sola salida: `TikiWikiTrackerMapper`.

#### 2.9. Escapado HTML en capa de persistencia

`MessageMapper::toWikiFields()` aplica `htmlspecialchars()` antes de enviar datos a TikiWiki. Esto evita algunos XSS si TikiWiki renderiza sin escapar, pero también mezcla almacenamiento con presentación y puede producir doble escape o pérdida de fidelidad del mensaje original.

Recomendación:

- Decidir explícitamente si el tracker guarda texto crudo o texto seguro para render.
- Idealmente guardar crudo y escapar al mostrar.
- Si TikiWiki requiere texto escapado, documentarlo como contrato y testearlo.

#### 2.10. Cache de topics no considera `chat_id`

Ubicación: `WebhookHandler.php`, `topic_names.json`

La cache usa `message_thread_id` como clave. Si el bot trabaja con varios chats, dos chats podrían tener el mismo `message_thread_id` y pisarse nombres.

Recomendación:

- Usar clave compuesta: `$chatId . ':' . $messageThreadId`.
- Escribir con lock (`LOCK_EX`) para evitar corrupción por requests concurrentes.

#### 2.11. Cache de gallery ID ignora cambios de tracker

Ubicación: `TikiWikiClient::$mediaGalleryIdCache`

El cache guarda un único `mediaGalleryId`, aunque `getMediaGalleryId()` acepta `$trackerId`. Si un proceso consulta más de un tracker, puede reutilizar una galería equivocada.

Recomendación:

- Cachear por tracker: `private static array $mediaGalleryIdCacheByTracker = [];`.

#### 2.12. Deduplicación no es atómica

Ubicación: `WebhookHandler::processMessage()`

El flujo hace `messageExists()` y luego `createTrackerItem()`. Si Telegram reintenta dos requests en paralelo, ambos pueden pasar la consulta y crear duplicados.

Recomendación:

- Si TikiWiki lo permite, agregar constraint o campo único por `(chat_id, message_id)`.
- Si no, crear una capa idempotente usando un tracker auxiliar, archivo lock o update_id.

#### 2.13. Reacciones usan IDs sintéticos frágiles

Ubicación: `WebhookHandler::processMessageReaction()` y `processMessageReactionCount()`

Se usa `-1 * date` como `message_id`. Dos eventos en el mismo segundo pueden colisionar, y no queda una relación estructurada fuerte con el mensaje original.

Recomendación:

- Guardar tipo de evento, `original_message_id`, usuario y fecha como campos separados.
- Usar un ID sintético compuesto, por ejemplo hash de `chat_id + original_message_id + user_id + date + tipo`.

#### 2.14. Documentación de versión PHP desactualizada

Ubicaciones: `TECHNICAL.md`, `INSTALL.md`, código PHP

La documentación menciona PHP 7.4+, pero el código usa características de PHP 8 como `str_starts_with()` y `match`.

Recomendación:

- Declarar PHP 8.0+ como requisito mínimo.
- Idealmente validar al inicio con `PHP_VERSION_ID`.

#### 2.15. Manejo de errores inconsistente

Aunque el roadmap marca este punto como resuelto, el código todavía mezcla:

- `false`/`null` como errores.
- `die()`/`exit`.
- `http_response_code()` directo.
- `set_error_handler()` en import.
- Logs y respuestas JSON construidas a mano.

Recomendación:

- Definir excepciones de dominio: `ConfigException`, `TelegramException`, `TikiWikiException`, `ImportException`.
- Cada entry point debería convertir excepciones al formato HTTP correcto.

---

## 3. Revisión de objetivos, implementación y rumbo del producto

### Lectura del objetivo

trackerGram busca ser un puente liviano entre Telegram y TikiWiki. Su valor está en convertir conversaciones informales o efímeras en registros consultables, archivables y estructurados dentro de TikiWiki, sin introducir una base de datos adicional.

Esa estrategia sigue siendo buena para el estado actual del proyecto. TikiWiki ya aporta almacenamiento, búsqueda, permisos, trackers y file galleries. trackerGram debería enfocarse en capturar, normalizar y entregar datos de manera confiable.

### Decisión estratégica pendiente: archivo o espejo

Hoy el sistema se comporta más como archivo que como espejo:

- Inserta mensajes nuevos.
- No actualiza mensajes editados.
- No marca mensajes borrados.
- No tiene reconciliación posterior.

Esto no está mal, pero debe decidirse y documentarse.

Opción A: archivo inmutable

- Ventaja: simple, auditable, resistente a cambios posteriores.
- Implica: los editados/borrados se guardan como eventos adicionales, no modifican el original.

Opción B: espejo del chat

- Ventaja: TikiWiki refleja el estado actual de Telegram.
- Implica: hay que buscar y actualizar items existentes, manejar borrados, conflictos y permisos.

Recomendación:

- Para el MVP, elegir "archivo inmutable con eventos". Es más coherente con TikiWiki como memoria histórica y evita complejidad temprana.

### Estrategia de implementación recomendada

#### Fase 1: estabilización inmediata

Objetivo: que la versión funcional sea confiable y segura.

Acciones:

- Hacer obligatorio `TELEGRAM_WEBHOOK_SECRET` en producción.
- Corregir `require_once` faltantes en `admin.php` e `import.php`.
- Mover y arreglar `change_password`.
- Proteger el JS del admin contra XSS.
- Endurecer ZIP import: límites de tamaño, cantidad de archivos y rutas Windows.
- Añadir prueba manual/documentada de: webhook texto, webhook media, crear tracker, importar ZIP chico.

#### Fase 2: unificación del dominio

Objetivo: que webhook e importación produzcan los mismos datos.

Acciones:

- Crear un modelo normalizado de mensaje.
- Separar parsers por origen.
- Centralizar schema de campos TikiWiki en una única clase/archivo.
- Agregar fixtures JSON de Telegram webhook y export.
- Agregar tests unitarios de mapeo.

#### Fase 3: importación robusta

Objetivo: importar conversaciones grandes sin depender de un request HTTP largo.

Acciones:

- Crear comando CLI `php import_cli.php --zip=... --tracker=...`.
- Procesar en lotes.
- Guardar progreso en archivo JSON o en un tracker auxiliar de TikiWiki.
- Permitir reanudar imports.
- Mostrar estado desde admin.

#### Fase 4: producto y operación

Objetivo: pasar de script funcional a herramienta mantenible.

Acciones:

- Panel de salud: webhook configurado, última recepción, último error, TikiWiki reachable, gallery detectada.
- Botón "probar conexión Telegram" y "probar conexión TikiWiki".
- Modo diagnóstico que no exponga tokens.
- Métricas mínimas: mensajes procesados, duplicados, errores, media subidos.
- Documentar backup/restore y rotación de logs.

### Nuevas funcionalidades valiosas

#### 3.1. Manejo de mensajes editados y borrados

Implementar `edited_message` como evento adicional o actualización del item, según la decisión archivo/espejo.

Para archivo inmutable:

- Crear nuevo item tipo `edited_message`.
- Guardar `original_message_id`.
- Guardar texto nuevo.

Para espejo:

- Buscar item original.
- Actualizar campos.
- Registrar historial si TikiWiki lo permite.

#### 3.2. Importador con reanudación

Muy útil para exports reales. Debe registrar:

- ZIP o carpeta origen.
- Tracker destino.
- Total de mensajes.
- Último índice procesado.
- Errores por mensaje.
- Media pendientes.

#### 3.3. Ruteo por chat

Opciones:

- Un tracker por chat.
- Un tracker único con vistas/filtros por `chat_id`.
- Configuración explícita `CHAT_TRACKER_MAP=-1001:12,-1002:15`.

Para mantener la filosofía sin DB local, el mapa puede vivir en `.env` o en un tracker de configuración.

#### 3.4. Hashtags y etiquetas

Extraer hashtags de texto y captions para poblar un campo `telegrammessageTags`. Es una mejora pequeña con impacto grande en búsqueda.

#### 3.5. Mensajes estructurados con patrones

El roadmap ya menciona prefijos como GPS. Esto puede convertirse en una feature potente:

- Definir patrones configurables.
- Extraer campos como nombre, coordenadas, tipo de alerta, prioridad.
- Guardar tanto texto original como campos derivados.

#### 3.6. Transcripción y OCR opcional

Para audios, imágenes y documentos:

- Transcribir notas de voz.
- OCR de imágenes.
- Guardar texto extraído en campos separados.

Conviene dejarlo como módulo opcional, porque agrega dependencias y posibles costos.

#### 3.7. Gestión de privacidad

Como el proyecto archiva conversaciones, debería tener una postura clara:

- Qué chats están autorizados.
- Qué usuarios saben que se archiva.
- Cómo se elimina o anonimiza contenido.
- Qué permisos tiene el tracker en TikiWiki.

### Riesgos de producto

#### Imports HTTP grandes

Es el mayor riesgo de escalabilidad. Aunque funcione con ZIPs chicos, no va a sostener años de historial con media pesada.

#### Ambigüedad de objetivo

Si no se define "archivo" vs "espejo", cada nueva feature va a empujar en direcciones distintas.

#### Dependencia del schema del tracker

Si los campos de TikiWiki cambian o se crean mal, el sistema falla de forma difícil de diagnosticar. Hace falta un verificador de schema.

---

## Lista priorizada de acciones

### Urgente

1. Hacer obligatorio `TELEGRAM_WEBHOOK_SECRET` o bloquear procesamiento si falta.
2. Agregar `require_once` faltantes en `admin.php` e `import.php`.
3. Arreglar `change_password` en `admin.php`.
4. Reemplazar `innerHTML` por `textContent` en errores de importación.
5. Endurecer validación ZIP contra zip bombs y rutas Windows.

### Corto plazo

1. Requerir PHP 8.0+ en documentación e instalación.
2. Crear `bootstrap.php`.
3. Cachear topics por `chat_id:thread_id`.
4. Cachear gallery ID por tracker.
5. Crear health check de configuración.
6. Agregar fixtures y tests para `MessageMapper`.

### Mediano plazo

1. Definir modelo `NormalizedMessage`.
2. Unificar webhook e importación sobre el mismo mapper.
3. Crear importador CLI con progreso y reanudación.
4. Definir estrategia de mensajes editados/borrados.
5. Agregar tags/hashtags y parser de mensajes estructurados.

### Largo plazo

1. Inyección de dependencias y clientes HTTP testeables.
2. Cola/reintentos para fallos temporales de TikiWiki.
3. Panel de operación con métricas.
4. Módulos opcionales de OCR/transcripción.
5. Configuración multi-chat más rica.

---

## Conclusión

trackerGram está bien orientado: el objetivo es claro, el MVP ya tiene forma, y la separación reciente en `WebhookHandler`, clientes y mapper fue un buen paso. El proyecto está en el momento exacto en que conviene pagar deuda técnica selectiva antes de seguir sumando funcionalidades.

La recomendación principal es tratar la próxima versión como una versión de estabilización: cerrar riesgos de seguridad, corregir acciones admin/import que pueden romperse en runtime, unificar el modelo de mensajes y preparar el camino para imports grandes. Después de eso, las nuevas funcionalidades como hashtags, mensajes estructurados, edición/borrado e importación reanudable van a caer sobre una base mucho más amable.
