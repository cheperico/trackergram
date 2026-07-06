<?php
/**
 * CollectSessionManager — Maneja sesiones activas del formulario /gather
 *
 * Persiste el estado de cada sesión en un archivo JSON con LOCK_EX para
 * prevenir race conditions entre requests concurrentes.
 *
 * Cada sesión se identifica por "chatId_userId" y contiene:
 *  - chatId: int
 *  - userId: int
 *  - formMessageId: int (ID del mensaje del formulario en el chat)
 *  - awaiting: string|null (qué campo estamos esperando, null si ninguno)
 *  - fields: array<string, array{label: string, value: mixed, type: string}>
 */
class CollectSessionManager
{
    private string $storageFile;

    public function __construct()
    {
        $dir = defined('TEMP_DIR') ? TEMP_DIR : __DIR__ . '/tmp';
        $this->storageFile = $dir . '/collect_sessions.json';
    }

    /**
     * Obtener una sesión por clave "chatId_userId"
     */
    public function get(string $key): ?array
    {
        return $this->withLock(function(array $sessions) use ($key): array {
            return $sessions; // solo lectura
        })[$key] ?? null;
    }

    /**
     * Guardar/actualizar una sesión
     */
    public function set(string $key, array $data): void
    {
        $this->withLock(function(array $sessions) use ($key, $data): array {
            $data['last_activity'] = time();
            $sessions[$key] = $data;
            return $sessions;
        });
    }

    /**
     * Eliminar una sesión
     */
    public function delete(string $key): void
    {
        $this->withLock(function(array $sessions) use ($key): array {
            unset($sessions[$key]);
            return $sessions;
        });
    }

    /**
     * Listar claves de sesiones activas
     * @return string[]
     */
    public function listKeys(): array
    {
        return array_keys($this->withLock(fn(array $s) => $s));
    }

    /**
     * Purgar sesiones con más de 1 hora de inactividad.
     */
    private function gcSessions(array $sessions): array
    {
        $now = time();
        $maxAge = 3600; // 1 hora
        $countBefore = count($sessions);
        $sessions = array_filter($sessions, function(array $session) use ($now, $maxAge): bool {
            $lastActivity = $session['last_activity'] ?? $session['created_at'] ?? 0;
            return ($now - $lastActivity) < $maxAge;
        });
        $purged = $countBefore - count($sessions);
        if ($purged > 0) {
            log_message("trackerGram: CollectSessionManager purged {$purged} expired sessions (>{$maxAge}s)");
        }
        return $sessions;
    }

    /**
     * Operación atómica: LOCK_EX, read, callback, write, unlock
     */
    private function withLock(callable $mutate): array
    {
        $fp = fopen($this->storageFile, 'c+');
        if (!$fp) {
            $error = error_get_last();
            $msg = $error ? $error['message'] : 'unknown error';
            log_message("trackerGram: CollectSessionManager no pudo abrir {$this->storageFile}: {$msg}", true);
            return $mutate([]);
        }
        flock($fp, LOCK_EX);

        $content = stream_get_contents($fp);
        $sessions = [];
        if ($content !== false && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $sessions = $decoded;
            }
        }

        // GC de sesiones expiradas ANTES de aplicar la mutación
        $sessions = $this->gcSessions($sessions);

        $sessions = $mutate($sessions);

        rewind($fp);
        $written = fwrite($fp, json_encode($sessions, JSON_PRETTY_PRINT));
        if ($written !== false) {
            ftruncate($fp, ftell($fp));
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        return $sessions;
    }
}
