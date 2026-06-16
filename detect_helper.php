<?php
/**
 * trackerGram - Helper para detección pasiva de chats Telegram
 *
 * Gestiona tmp/chats_detectados.json:
 * - Detecciones: chats donde está el bot pero sin chat_id asignado
 * - Ignorados: chats que el admin descartó (no se vuelven a detectar)
 *
 * Uso:
 *   addDetection($slug, $chatId, $chatTitle)     → detecta un chat
 *   assignDetection($slug, $chatId)               → admin asigna a conexión
 *   ignoreChat($slug, $chatId)                    → admin descarta
 *   getDetections()                                → todas las detecciones activas
 */

define('DETECT_FILE', __DIR__ . '/tmp/chats_detectados.json');

// ── Carga/Save ──

function loadDetections(): array
{
    if (!file_exists(DETECT_FILE)) {
        return ['detections' => [], 'ignored' => []];
    }
    $raw = @file_get_contents(DETECT_FILE);
    if ($raw === false) {
        return ['detections' => [], 'ignored' => []];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['detections' => [], 'ignored' => []];
}

function saveDetections(array $data): bool
{
    $dir = dirname(DETECT_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return (bool) @file_put_contents(DETECT_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ── Detectar chat ──

/**
 * Registrar detección de un chat para una conexión pendiente (chat_id=0).
 * No hace nada si el chat ya fue ignorado para esta conexión.
 */
function addDetection(string $slug, int $chatId, string $chatTitle): void
{
    $data = loadDetections();

    // Si ya fue ignorado, salir
    if (!empty($data['ignored'][$slug]) && in_array($chatId, $data['ignored'][$slug], true)) {
        return;
    }

    // Inicializar array si no existe
    if (!isset($data['detections'][$slug])) {
        $data['detections'][$slug] = [];
    }

    // Buscar si ya existe (actualizar timestamp + contador)
    $found = false;
    foreach ($data['detections'][$slug] as &$det) {
        if ($det['chat_id'] === $chatId) {
            $det['detected_at'] = date('c');
            $det['chat_title'] = $chatTitle;
            $det['detected_count'] = ($det['detected_count'] ?? 1) + 1;
            $found = true;
            break;
        }
    }
    unset($det);

    if (!$found) {
        $data['detections'][$slug][] = [
            'chat_id' => $chatId,
            'chat_title' => $chatTitle,
            'detected_at' => date('c'),
            'detected_count' => 1,
        ];
    }

    saveDetections($data);
}

// ── Consulta ──

/**
 * Obtener todas las detecciones activas (no ignoradas).
 * Retorna: [ ['slug' => ..., 'chat_id' => ..., 'chat_title' => ..., 'detected_at' => ..., 'detected_count' => ...], ... ]
 */
function getDetections(): array
{
    $data = loadDetections();
    $result = [];
    foreach ($data['detections'] as $slug => $detections) {
        foreach ($detections as $det) {
            $result[] = [
                'slug' => $slug,
                'chat_id' => $det['chat_id'],
                'chat_title' => $det['chat_title'],
                'detected_at' => $det['detected_at'],
                'detected_count' => $det['detected_count'] ?? 1,
            ];
        }
    }
    return $result;
}

/**
 * Obtener detecciones agrupadas por slug.
 * Retorna: [ slug => [ [chat_id, chat_title, ...], ... ] ]
 */
function getDetectionsBySlug(): array
{
    $data = loadDetections();
    return $data['detections'] ?? [];
}

// ── Acciones del admin ──

/**
 * Asignar un chat_id detectado a una conexión.
 * Actualiza setup.json y remueve la detección.
 *
 * @return array{success: bool, message: string}
 */
function assignDetection(string $slug, int $chatId): array
{
    $config = new ConfigManager();
    $conn = $config->getConnection($slug);
    if (!$conn) {
        return ['success' => false, 'message' => 'Conexión no encontrada'];
    }

    // Verificar que la conexión tenga datos Tiki antes de asignar
    if (empty(trim($conn['tiki_api_url'] ?? '')) || empty($conn['tracker_id'])) {
        return [
            'success' => false,
            'message' => 'Completá primero la configuración de TikiWiki (API URL, Token y Tracker ID) antes de asignar un chat.',
        ];
    }

    // Remover detección PRIMERO para evitar orphan si falla el guardado
    $data = loadDetections();
    if (isset($data['detections'][$slug])) {
        $data['detections'][$slug] = array_values(array_filter(
            $data['detections'][$slug],
            fn($d) => $d['chat_id'] !== $chatId
        ));
        if (empty($data['detections'][$slug])) {
            unset($data['detections'][$slug]);
        }
    }
    saveDetections($data);

    try {
        // Actualizar chat_id en la conexión (después de remover detección)
        $conn['chat_id'] = $chatId;
        $config->saveConnection($conn + ['slug' => $slug]);
    } catch (\Exception $e) {
        // Si falla, la detección ya se perdió pero se redetectará al próximo mensaje
        return ['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()];
    }

    return ['success' => true, 'message' => 'Chat #' . $chatId . ' asignado a "' . $slug . '"'];
}

/**
 * Ignorar un chat detectado: no volver a mostrarlo.
 */
function ignoreChat(string $slug, int $chatId): bool
{
    $data = loadDetections();

    // Agregar a ignorados
    if (!isset($data['ignored'][$slug])) {
        $data['ignored'][$slug] = [];
    }
    if (!in_array($chatId, $data['ignored'][$slug], true)) {
        $data['ignored'][$slug][] = $chatId;
    }

    // Remover de detecciones activas
    if (isset($data['detections'][$slug])) {
        $data['detections'][$slug] = array_values(array_filter(
            $data['detections'][$slug],
            fn($d) => $d['chat_id'] !== $chatId
        ));
        if (empty($data['detections'][$slug])) {
            unset($data['detections'][$slug]);
        }
    }

    return saveDetections($data);
}
