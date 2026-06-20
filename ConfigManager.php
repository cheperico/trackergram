<?php
/**
 * ConfigManager - Gestor de configuración multi-conexión (setup.json)
 * Maneja CRUD de conexiones, migración desde .env y persistencia atómica.
 */
class ConfigManager
{
    private string $configPath;
    private array $data = ['version' => 2, 'connections' => []];
    private bool $loaded = false;

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? __DIR__ . '/config/setup.json';
    }

    // --- Carga y persistencia ---

    public function load(): bool
    {
        if ($this->loaded) {
            return true;
        }

        if (!file_exists($this->configPath)) {
            $this->tryMigrateFromEnv();
            $this->loaded = true;
            return true;
        }

        $content = @file_get_contents($this->configPath);
        if ($content === false) {
            return false;
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_message("ConfigManager: JSON inválido en {$this->configPath}: " . json_last_error_msg(), true);
            return false;
        }

        if (!is_array($decoded)) {
            log_message("ConfigManager: Estructura inválida en {$this->configPath}", true);
            return false;
        }

        $this->data = $decoded + ['version' => 2, 'connections' => []];
        if (!isset($this->data['connections']) || !is_array($this->data['connections'])) {
            $this->data['connections'] = [];
        }

        $this->loaded = true;

        // Migración: auto-generar webhook_secret para conexiones existentes sin él
        // (loaded=true antes para evitar recursión si save() chequea loaded)
        $dirty = false;
        foreach ($this->data['connections'] as $slug => &$conn) {
            if (empty($conn['webhook_secret'])) {
                // Si otra conexión con el mismo bot_token ya tiene secret, reusarlo
                $reused = false;
                foreach ($this->data['connections'] as $otherSlug => $otherConn) {
                    if ($otherSlug !== $slug && !empty($otherConn['webhook_secret']) && ($otherConn['bot_token'] ?? '') === ($conn['bot_token'] ?? '')) {
                        $conn['webhook_secret'] = $otherConn['webhook_secret'];
                        log_message("ConfigManager: webhook_secret reusado de conexión '{$otherSlug}' para '{$slug}' (mismo bot_token)");
                        $reused = true;
                        break;
                    }
                }
                if (!$reused) {
                    $conn['webhook_secret'] = bin2hex(random_bytes(16));
                    log_message("ConfigManager: webhook_secret auto-generado para conexión existente '{$slug}' durante load()");
                }
                $dirty = true;
            }
        }
        unset($conn);
        if ($dirty) {
            $this->save();
        }

        return true;
    }

    public function save(): bool
    {
        if (!$this->loaded) {
            $this->load();
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            log_message("ConfigManager: Error al codificar JSON: " . json_last_error_msg(), true);
            return false;
        }

        $written = @file_put_contents($this->configPath, $json, LOCK_EX);
        if ($written === false) {
            log_message("ConfigManager: No se pudo escribir {$this->configPath}", true);
            return false;
        }

        // Intentar permisos restrictivos (no aplica en Windows, pero no falla)
        @chmod($this->configPath, 0600);

        return true;
    }

    // --- Migración desde .env ---

    private function tryMigrateFromEnv(): void
    {
        $envPath = __DIR__ . '/.env';
        if (!file_exists($envPath)) {
            return;
        }

        $env = $this->parseEnvFile($envPath);
        $botToken = $env['TELEGRAM_BOT_TOKEN'] ?? '';
        $tikiApiUrl = $env['TIKIWIKI_API_URL'] ?? '';

        if ($botToken === '' || $tikiApiUrl === '') {
            return;
        }

        $connection = [
            'name' => 'default',
            'enabled' => true,
            'async_processing' => !empty($env['ASYNC_PROCESSING']) && $env['ASYNC_PROCESSING'] === 'true',
            'bot_token' => $botToken,
            'webhook_secret' => $env['TELEGRAM_WEBHOOK_SECRET'] ?? '',
            'chat_id' => 0,
            'tiki_api_url' => rtrim($tikiApiUrl, '/') . '/',
            'tiki_api_token' => $env['TIKIWIKI_TOKEN'] ?? '',
            'tracker_id' => (int) ($env['TIKIWIKI_TRACKER_ID'] ?? 1),
        ];

        $slug = $this->generateSlug($connection['name']);
        $this->data['connections'][$slug] = $connection;

        // Marcar como cargado ANTES de save() para evitar recursión infinita
        $this->loaded = true;
        $this->save();

        log_message("ConfigManager: Migración automática desde .env → setup.json (slug: {$slug})");
    }

    private function parseEnvFile(string $path): array
    {
        $result = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $line) {
            if (strlen($line) > 2 && ord($line[0]) === 0xEF && ord($line[1]) === 0xBB && ord($line[2]) === 0xBF) {
                $line = substr($line, 3);
            }
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }
            if (strpos($trimmed, '=') !== false) {
                [$name, $value] = explode('=', $trimmed, 2);
                $result[trim($name)] = trim($value);
            }
        }
        return $result;
    }

    // --- CRUD de conexiones ---

    public function listConnections(): array
    {
        $this->load();
        return $this->data['connections'];
    }

    public function getConnection(string $slug): ?array
    {
        $this->load();
        return $this->data['connections'][$slug] ?? null;
    }

    /**
     * Buscar TODAS las conexiones que matchean un chat_id + webhook_secret
     * Útil para fan-out: un mensaje enviado a múltiples trackers
     * @param int $chatId ID del chat de Telegram
     * @param string|null $webhookSecret Secret token del webhook
     * @return array[] Array de conexiones, cada una con '_slug'
     */
    public function findAllByChatId(int $chatId, ?string $webhookSecret = null): array
    {
        $this->load();
        $results = [];
        foreach ($this->data['connections'] as $slug => $conn) {
            if (empty($conn['enabled'])) {
                continue;
            }
            if (($conn['chat_id'] ?? 0) !== $chatId) {
                continue;
            }

            $hasConnSecret = !empty($conn['webhook_secret']);
            $hasReqSecret = $webhookSecret !== null && $webhookSecret !== '';

            if ($hasConnSecret) {
                if ($hasReqSecret && hash_equals($conn['webhook_secret'], $webhookSecret)) {
                    $results[] = $conn + ['_slug' => $slug];
                }
                continue;
            } else {
                log_message("ConfigManager: Conexión '{$slug}' sin webhook_secret — aceptando request. " .
                    "Se recomienda editar la conexión para generar un secret automáticamente.", true);
                $results[] = $conn + ['_slug' => $slug];
            }
        }
        return $results;
    }

    /**
     * Buscar primera conexión por chat_id + webhook_secret (sin fan-out)
     * @param int $chatId ID del chat de Telegram
     * @param string|null $webhookSecret Secret token del webhook
     * @return array|null Conexión + '_slug' o null si no hay match
     */
    public function findByChatId(int $chatId, ?string $webhookSecret = null): ?array
    {
        $this->load();
        foreach ($this->data['connections'] as $slug => $conn) {
            // Solo conexiones habilitadas
            if (empty($conn['enabled'])) {
                continue;
            }
            // Debe coincidir chat_id
            if (($conn['chat_id'] ?? 0) !== $chatId) {
                continue;
            }

            $hasConnSecret = !empty($conn['webhook_secret']);
            $hasReqSecret = $webhookSecret !== null && $webhookSecret !== '';

            if ($hasConnSecret) {
                // La conexión requiere validación: el request debe tener el secret correcto
                if ($hasReqSecret && hash_equals($conn['webhook_secret'], $webhookSecret)) {
                    return $conn + ['_slug' => $slug];
                }
                // secret incorrecto o ausente → no es match
                continue;
            } else {
                // Conexión sin webhook_secret — aceptar el request pero advertir
                log_message("ConfigManager: Conexión '{$slug}' sin webhook_secret — aceptando request. " .
                    "Se recomienda editar la conexión para generar un secret automáticamente.", true);
                return $conn + ['_slug' => $slug];
            }
        }
        return null;
    }

    public function saveConnection(array $data): string
    {
        $this->load();

        $this->validateConnectionData($data);

        $name = trim($data['name']);
        $slug = $data['slug'] ?? '';

        if ($slug !== '' && isset($this->data['connections'][$slug])) {
            // Edición: mantener el slug original, aunque el nombre haya cambiado
            $slug = $data['slug'];
        } else {
            // Creación: generar slug desde el nombre, evitando colisiones
            $slug = $this->generateSlug($name);
            $originalSlug = $slug;
            $counter = 1;
            while (isset($this->data['connections'][$slug]) && ($this->data['connections'][$slug]['name'] ?? '') !== $name) {
                $counter++;
                $slug = $originalSlug . '-' . $counter;
            }
        }

        $now = date('c');
        $isNew = !isset($this->data['connections'][$slug]);

        // Auto-generar webhook_secret si está vacío
        // Prioridad: 1) Mantener el actual si existe (edición sin cambios)
        //             2) Reusar el de otra conexión con el mismo bot_token
        //             3) Generar uno nuevo
        $webhookSecret = trim((string) ($data['webhook_secret'] ?? ''));
        if ($webhookSecret === '') {
            // Edición: mantener secret actual
            if (!$isNew && !empty($this->data['connections'][$slug]['webhook_secret'])) {
                $webhookSecret = $this->data['connections'][$slug]['webhook_secret'];
            } else {
                // Buscar otra conexión con el mismo bot_token (un bot → un webhook → mismo secret)
                $botToken = trim((string) ($data['bot_token'] ?? ''));
                if ($botToken !== '') {
                    foreach ($this->data['connections'] as $otherSlug => $otherConn) {
                        if ($otherSlug !== $slug && !empty($otherConn['webhook_secret']) && ($otherConn['bot_token'] ?? '') === $botToken) {
                            $webhookSecret = $otherConn['webhook_secret'];
                            log_message("ConfigManager: webhook_secret reusado de conexión '{$otherSlug}' para '{$name}' (mismo bot_token)");
                            break;
                        }
                    }
                }
                if ($webhookSecret === '') {
                    $webhookSecret = bin2hex(random_bytes(16));
                    log_message("ConfigManager: webhook_secret auto-generado para conexión '{$name}'");
                }
            }
        }

        $connection = [
            'name' => $name,
            'enabled' => $data['enabled'] ?? true,
            'async_processing' => !empty($data['async_processing']),
            'bot_token' => trim((string) ($data['bot_token'] ?? '')),
            'bot_name' => trim((string) ($data['bot_name'] ?? '')),
            'webhook_secret' => $webhookSecret,
            'chat_id' => (int) ($data['chat_id'] ?? 0),
            'chat_title' => trim((string) ($data['chat_title'] ?? '')),
            'tiki_api_url' => rtrim(trim((string) ($data['tiki_api_url'] ?? '')), '/') . '/',
            'tiki_api_token' => trim((string) ($data['tiki_api_token'] ?? '')),
            'tracker_id' => (int) ($data['tracker_id'] ?? 0),
            'field_prefix' => trim((string) ($data['field_prefix'] ?? 
                ($isNew ? 'telegrammessage' : ($this->data['connections'][$slug]['field_prefix'] ?? 'telegrammessage'))
            )),
        ];

        if ($isNew) {
            $connection['created_at'] = $now;
        } else {
            $connection['created_at'] = $this->data['connections'][$slug]['created_at'] ?? $now;
        }
        $connection['updated_at'] = $now;

        $this->data['connections'][$slug] = $connection;
        $this->save();

        return $slug;
    }

    /**
     * Actualizar campos específicos de una conexión existente.
     * Útil para bot_name, chat_title, etc. sin alterar el resto.
     */
    public function updateConnectionFields(string $slug, array $fields): bool
    {
        $this->load();
        if (!isset($this->data['connections'][$slug])) {
            return false;
        }
        foreach ($fields as $key => $value) {
            $this->data['connections'][$slug][$key] = $value;
        }
        $this->data['connections'][$slug]['updated_at'] = date('c');
        return $this->save();
    }

    public function deleteConnection(string $slug): bool
    {
        $this->load();
        if (!isset($this->data['connections'][$slug])) {
            return false;
        }
        unset($this->data['connections'][$slug]);
        return $this->save();
    }

    /**
     * Buscar conexión incompleta (chat_id=0) por webhook_secret.
     * Se usa en detección pasiva: el admin creó la conexión sin chat_id,
     * el bot recibe mensajes y avisa qué chats detectó.
     */
    public function findByWebhookSecretPending(string $secret): ?array
    {
        $this->load();
        foreach ($this->data['connections'] as $slug => $conn) {
            if (!empty($conn['webhook_secret']) && hash_equals($conn['webhook_secret'], $secret)) {
                // Debe tener chat_id vacío/cero (pendiente de asignar)
                if (empty($conn['chat_id'])) {
                    return $conn + ['_slug' => $slug];
                }
            }
        }
        return null;
    }

    /**
     * Buscar cualquier conexión por webhook_secret, independientemente de chat_id.
     * Sirve para detectar cuando un bot conocido (con secret válido) es agregado
     * a un grupo nuevo que aún no está configurado.
     */
    public function findByWebhookSecret(string $secret): ?array
    {
        $this->load();
        foreach ($this->data['connections'] as $slug => $conn) {
            if (!empty($conn['webhook_secret']) && hash_equals($conn['webhook_secret'], $secret)) {
                return $conn + ['_slug' => $slug];
            }
        }
        return null;
    }

    /**
     * Duplicar una conexión existente con nombre modificado.
     * La copia se crea deshabilitada para que el usuario la configure antes de activar.
     */
    public function duplicateConnection(string $slug): ?string
    {
        $this->load();
        $original = $this->data['connections'][$slug] ?? null;
        if (!$original) {
            return null;
        }

        // Nombre: "Original (copia)" o "Original (copia 2)" si ya existe
        $baseName = $original['name'];
        $counter = 1;
        do {
            $newName = $baseName . ' (copia' . ($counter > 1 ? ' ' . $counter : '') . ')';
            $newSlug = $this->generateSlug($newName);
            $counter++;
        } while (isset($this->data['connections'][$newSlug]));

        $now = date('c');
        $dupe = $original;
        $dupe['name'] = $newName;
        $dupe['enabled'] = false; // deshabilitada por defecto
        $dupe['created_at'] = $now;
        $dupe['updated_at'] = $now;

        $this->data['connections'][$newSlug] = $dupe;
        $this->save();

        return $newSlug;
    }

    // --- Estado ---

    public function enableConnection(string $slug): bool
    {
        $this->load();
        if (!isset($this->data['connections'][$slug])) {
            return false;
        }
        $this->data['connections'][$slug]['enabled'] = true;
        $this->data['connections'][$slug]['updated_at'] = date('c');
        return $this->save();
    }

    public function disableConnection(string $slug): bool
    {
        $this->load();
        if (!isset($this->data['connections'][$slug])) {
            return false;
        }
        $this->data['connections'][$slug]['enabled'] = false;
        $this->data['connections'][$slug]['updated_at'] = date('c');
        return $this->save();
    }

    // --- Validación ---

    private function validateConnectionData(array $data): void
    {
        $errors = [];

        if (!isset($data['name']) || trim($data['name']) === '') {
            $errors[] = 'name es obligatorio';
        }

        if (!isset($data['bot_token']) || trim($data['bot_token']) === '') {
            $errors[] = 'bot_token es obligatorio';
        }

        if (!isset($data['tiki_api_url']) || trim($data['tiki_api_url']) === '') {
            $errors[] = 'tiki_api_url es obligatorio';
        } elseif (!str_starts_with(trim($data['tiki_api_url']), 'https://')) {
            $errors[] = 'tiki_api_url debe empezar con https:// (no se permiten conexiones HTTP no seguras)';
        } else {
            // Validación anti-SSRF: bloquear IPs privadas/reservadas
            $host = parse_url(trim($data['tiki_api_url']), PHP_URL_HOST);
            if ($host !== null) {
                $ip = gethostbyname($host);
                if ($ip !== $host) { // si es un hostname que resuelve
                    $isPrivate = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
                    if ($isPrivate) {
                        $errors[] = 'tiki_api_url no puede apuntar a una IP privada o reservada';
                    }
                }
            }
        }

        if (!isset($data['tracker_id']) || !is_numeric($data['tracker_id'])) {
            $errors[] = 'tracker_id es obligatorio y debe ser numérico';
        }

        if ($errors !== []) {
            throw new InvalidArgumentException('Datos de conexión inválidos: ' . implode(', ', $errors));
        }
    }

    // --- Utilidades ---

    private function generateSlug(string $name): string
    {
        // Transliterar caracteres acentuados y especiales a ASCII
        $translit = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'À' => 'a', 'È' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u',
            'Ä' => 'a', 'Ë' => 'e', 'Ï' => 'i', 'Ö' => 'o', 'Ü' => 'u',
            'Ñ' => 'n', 'Ç' => 'c',
        ];
        $slug = strtr(strtolower(trim($name)), $translit);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'connection';
    }
}