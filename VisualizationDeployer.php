<?php
/**
 * VisualizationDeployer — Asistente de deploy de visualización para trackerGram
 * 
 * Compila un template base Smarty con placeholders → fieldIds reales del tracker,
 * genera la página TRACKERLIST y deploya ambas como páginas wiki en TikiWiki.
 * 
 * API REST usada (vía TikiWikiClient, con SSRF protection):
 *   GET  /api/trackers/{id}/fields        → field definitions
 *   GET  /api/wiki/page/{page}            → existencia de página
 *   POST /api/wiki                        → crear página
 *   POST /api/wiki/page/{page}            → actualizar página
 */
class VisualizationDeployer
{
    /**
     * Mapeo placeholder → sufijo de permName (se antepone field_prefix).
     */
    const PLACEHOLDER_MAP = [
        // — Contenido —
        'DISPLAY_NAME'      => 'DisplayName',
        'USERNAME'          => 'Username',
        'MESSAGE_DATE'      => 'MessageDate',
        'MESSAGE_TYPE'      => 'MessageType',
        'TEXT'              => 'Text',
        'CHAT_TITLE'        => 'ChatTitle',
        'TOPIC_TITLE'       => 'TopicTitle',
        // — Multimedia —
        'MEDIA_URL'         => 'MediaUrl',
        'MEDIA_TYPE'        => 'MediaType',
        'MEDIA_CAPTION'     => 'MediaCaption',
        'MEDIA_WIDTH'       => 'MediaWidth',
        'MEDIA_HEIGHT'      => 'MediaHeight',
        'MEDIA_DURATION'    => 'MediaDuration',
        'LOCATION'          => 'Location',
        // — Referencias —
        'REPLY_TO_ID'       => 'ReplyToId',
        'EDITED_DATE'       => 'EditedDate',
        'REACTIONS'         => 'Reactions',
        'HASHTAGS'          => 'Hashtags',
        // — Identificadores (fieldIds siempre incluidos, no mostrados) —
        'MESSAGE_ID'        => 'TelegramMessageId',
        'CHAT_ID'           => 'ChatId',
        'TOPIC_ID'          => 'TopicId',
        'FILE_UNIQUE_ID'    => 'FileUniqueId',
        // — Alias (se resuelve al mismo field que MESSAGE_TYPE, clase lowercase) —
        'MESSAGE_TYPE_CLASS'=> 'MessageType',
    ];

    /**
     * Categorías para agrupar en la UI del selector.
     */
    const FIELD_CATEGORIES = [
        'basicos'     => ['DISPLAY_NAME', 'MESSAGE_TYPE', 'MESSAGE_DATE'],
        'texto'       => ['TEXT', 'CHAT_TITLE', 'TOPIC_TITLE'],
        'usuario'     => ['USERNAME'],
        'multimedia'  => ['MEDIA_URL', 'MEDIA_TYPE', 'MEDIA_CAPTION', 'MEDIA_WIDTH', 'MEDIA_HEIGHT', 'MEDIA_DURATION'],
        'referencias' => ['REPLY_TO_ID', 'EDITED_DATE', 'REACTIONS'],
        'tags'        => ['HASHTAGS'],
        'ubicacion'   => ['LOCATION'],
    ];

    /**
     * Placeholders seleccionados por defecto en el primer deploy.
     */
    const DEFAULT_SELECTED = [
        'DISPLAY_NAME', 'MESSAGE_TYPE', 'MESSAGE_DATE', 'TEXT',
        'MEDIA_URL', 'MEDIA_TYPE', 'MEDIA_CAPTION',
        'REPLY_TO_ID', 'EDITED_DATE', 'REACTIONS', 'HASHTAGS',
    ];

    /** Siempre resueltos aunque no estén seleccionados (necesarios para el template) */
    const ALWAYS_INCLUDE = ['MESSAGE_TYPE_CLASS'];

    private TikiWikiClient $tikiClient;
    private string $templateBaseDir;

    public function __construct(TikiWikiClient $tikiClient)
    {
        $this->tikiClient = $tikiClient;
        $this->templateBaseDir = __DIR__ . '/templates/visualization/';
    }

    /**
     * Obtener field definitions del tracker y devolver array con información
     * completa para el selector de la UI.
     *
     * @param int    $trackerId
     * @param string $prefix     Field prefix del tracker
     * @return array  [ 'fields' => [ [placeholder, permName, fieldId, exists, category], ... ],
     *                  'prefix' => string ]
     */
    public function getTrackerFieldsInfo(int $trackerId, string $prefix): array
    {
        $prefix = $prefix ?: 'telegrammessage';
        $fields = $this->tikiClient->getFieldDefinitions($trackerId);

        // Construir lookup permName → fieldId
        $permToId = $this->buildPermToIdMap($fields);

        // Armar resultado: por cada placeholder, ver si existe en el tracker
        $result = [];
        foreach (self::PLACEHOLDER_MAP as $placeholder => $suffix) {
            // Alias apunta al mismo campo que su original (solo para lookup)
            $permName = $prefix . $suffix;
            $fieldId = $permToId[$permName] ?? 0;

            $result[] = [
                'placeholder' => $placeholder,
                'permName'    => $permName,
                'fieldId'     => $fieldId,
                'exists'      => $fieldId > 0,
                'category'    => $this->getPlaceholderCategory($placeholder),
            ];
        }

        return ['fields' => $result, 'prefix' => $prefix];
    }

    /**
     * Resolver fieldIds del tracker.
     *
     * El fieldMap contiene TODOS los placeholders cuyo campo existe en el tracker
     * (sean o no seleccionados) — así los placeholders sueltos dentro de bloques
     * visibles (ej: {{MESSAGE_TYPE}} en {if} de media) siempre se resuelven y
     * nunca queda Smarty roto.
     *
     * @param int    $trackerId
     * @param string $prefix
     * @param array  $selectedPlaceholders  Lista de placeholders seleccionados
     * @return array  [
     *   'fieldMap' => [ placeholder => fieldId ]  (TODOS los existentes),
     *   'selected' => [ placeholder, ... ]        (seleccionados que existen),
     *   'missing'  => [ placeholder => permName ] (seleccionados que no existen),
     * ]
     */
    public function resolveFieldIds(int $trackerId, string $prefix, array $selectedPlaceholders): array
    {
        $prefix = $prefix ?: 'telegrammessage';
        $fields = $this->tikiClient->getFieldDefinitions($trackerId);
        $permToId = $this->buildPermToIdMap($fields);

        $fieldMap = [];
        $selected = [];
        $missing = [];

        foreach (self::PLACEHOLDER_MAP as $placeholder => $suffix) {
            $permName = $prefix . $suffix;
            $fieldId = $permToId[$permName] ?? 0;

            if ($fieldId > 0) {
                // Existe en el tracker → siempre resoluble
                $fieldMap[$placeholder] = $fieldId;
                // Seleccionado o siempre-incluido → bloque condicional visible
                if (in_array($placeholder, $selectedPlaceholders, true) || in_array($placeholder, self::ALWAYS_INCLUDE, true)) {
                    $selected[] = $placeholder;
                }
            } elseif (in_array($placeholder, $selectedPlaceholders, true)) {
                // Seleccionado pero no existe en el tracker
                $missing[$placeholder] = $permName;
            }
        }

        return [
            'fieldMap' => $fieldMap,
            'selected' => $selected,
            'missing'  => $missing,
        ];
    }

    /**
     * Compilar template base Smarty: reemplazar placeholders por $f_fieldId.
     *
     * Los bloques condicionales {{#PLACEHOLDER}}...{{/PLACEHOLDER}} se procesan
     * recursivamente (soportan anidamiento, ej: MEDIA_CAPTION dentro de MEDIA_URL).
     * Un bloque se mantiene solo si el placeholder está en $selectedPlaceholders
     * (y existe en fieldMap); si no, el bloque completo se elimina.
     *
     * @param string $templateContent      Contenido del template base con placeholders
     * @param array  $fieldMap             [ placeholder => fieldId ] — TODOS los existentes
     * @param array  $selectedPlaceholders Lista de bloques a mantener visibles
     * @return string  Smarty listo para deployar
     */
    public function compileTemplate(string $templateContent, array $fieldMap, array $selectedPlaceholders = []): string
    {
        // Procesar condicionales recursivamente (innermost first)
        $result = $this->compileConditionalBlocks($templateContent, $fieldMap, $selectedPlaceholders);

        // Reemplazar placeholders sueltos restantes (ej: {{MESSAGE_TYPE}} dentro de {if} Smarty)
        $result = $this->replacePlaceholders($result, $fieldMap);

        // Reemplazar timestamp
        $result = str_replace('{DATE}', date('Y-m-d H:i:s'), $result);

        return $result;
    }

    /**
     * Compilar página de visualización (TRACKERLIST).
     *
     * @param int    $trackerId
     * @param array  $fieldIds          Lista de fieldIds a incluir
     * @param string $templatePageName  Nombre de la página template en TikiWiki
     * @param string $sortFieldId       FieldId para ordenar (MessageDate)
     * @param int    $maxItems          Máximo items por página
     * @return string  Contenido wiki de la página de visualización
     */
    public function compileFeedPage(
        int $trackerId,
        array $fieldIds,
        string $templatePageName,
        string $sortFieldId = '',
        int $maxItems = 50
    ): string {
        $pageContent = file_get_contents($this->templateBaseDir . 'page_template_base.txt');
        if ($pageContent === false) {
            throw new RuntimeException('No se pudo leer page_template_base.txt');
        }

        $fieldIdsStr = implode(':', $fieldIds);

        // El sort_mode usa el formato f_{fieldId}_desc (ej: f_176_desc)
        $sortMode = $sortFieldId !== '' ? 'f_' . $sortFieldId : '';

        $replacements = [
            '{{TRACKER_ID}}'     => (string) $trackerId,
            '{{FIELD_IDS}}'      => $fieldIdsStr,
            '{{SORT_FIELD}}'     => $sortMode,
            '{{MAX_ITEMS}}'      => (string) $maxItems,
            '{{TEMPLATE_PAGE}}'  => $templatePageName,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $pageContent);
    }

    /**
     * Deployar páginas wiki en TikiWiki (crear si no existen, actualizar si existen).
     *
     * @param string $templateContent  Contenido Smarty compilado (página template)
     * @param string $feedContent      Contenido TRACKERLIST (página de visualización)
     * @param string $templatePageName Nombre de la página template
     * @param string $feedPageName     Nombre de la página de visualización
     * @return array  [ 'success' => bool, 'results' => [ [pageName, action, success, url, error?], ... ] ]
     */
    public function deployPages(
        string $templateContent,
        string $feedContent,
        string $templatePageName,
        string $feedPageName
    ): array {
        $results = [
            $this->deploySinglePage($templatePageName, $templateContent, 'Template Smarty'),
            $this->deploySinglePage($feedPageName, $feedContent, 'Página de visualización'),
        ];

        $allSuccess = true;
        foreach ($results as $r) {
            if (!$r['success']) {
                $allSuccess = false;
                break;
            }
        }

        return ['success' => $allSuccess, 'results' => $results];
    }

    /**
     * Cargar contenido del template base.
     */
    public function loadTemplateBase(): string
    {
        $path = $this->templateBaseDir . 'item_template_base.smarty';
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("No se pudo leer el template base: {$path}");
        }
        return $content;
    }

    // ─── Métodos privados ───

    /**
     * Procesar bloques condicionales {{#NAME}}...{{/NAME}} recursivamente.
     * Los bloques anidados se resuelven primero (innermost first), luego
     * el bloque padre. Si el placeholder no está en $selectedPlaceholders
     * (o no existe en fieldMap), se elimina todo el bloque.
     */
    private function compileConditionalBlocks(string $content, array $fieldMap, array $selectedPlaceholders): string
    {
        return preg_replace_callback(
            '/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s',
            function ($m) use ($fieldMap, $selectedPlaceholders) {
                $placeholder = $m[1];
                // Resolver bloques anidados primero
                $inner = $this->compileConditionalBlocks($m[2], $fieldMap, $selectedPlaceholders);
                if (isset($fieldMap[$placeholder]) && in_array($placeholder, $selectedPlaceholders, true)) {
                    $fid = $fieldMap[$placeholder];
                    $inner = $this->replacePlaceholders($inner, $fieldMap);
                    return "{if \$f_{$fid}}{$inner}{/if}";
                }
                // Placeholder no existe o no seleccionado: eliminar bloque
                return '';
            },
            $content
        );
    }

    /**
     * Reemplazar placeholders {{PLACEHOLDER}} por $f_fieldId.
     * Los placeholders no presentes en fieldMap se dejan intactos.
     */
    private function replacePlaceholders(string $content, array $fieldMap): string
    {
        return preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            function ($m) use ($fieldMap) {
                $placeholder = $m[1];
                return isset($fieldMap[$placeholder]) ? '$f_' . $fieldMap[$placeholder] : $m[0];
            },
            $content
        );
    }

    /**
     * Deployar una sola página wiki (crear o actualizar).
     */
    private function deploySinglePage(string $pageName, string $content, string $label): array
    {
        $exists = $this->tikiClient->wikiPageExists($pageName);

        if ($exists) {
            $result = $this->tikiClient->updateWikiPage(
                $pageName,
                $content,
                'Actualización automática desde trackerGram'
            );
            $action = 'update';
        } else {
            $result = $this->tikiClient->createWikiPage(
                $pageName,
                $content,
                $label . ' generado por trackerGram',
                'Creación automática desde trackerGram'
            );
            $action = 'create';
        }

        return [
            'pageName' => $pageName,
            'action'   => $action,
            'success'  => $result['success'],
            'httpCode' => $result['httpCode'],
            'url'      => $this->tikiClient->getBaseUrl() . 'tiki-index.php?page=' . rawurlencode($pageName),
            'error'    => $result['error'],
        ];
    }

    /**
     * Construir mapa permName → fieldId desde field definitions.
     * Soporta formatos de la API: fieldId (nuevo) o id (legacy).
     */
    private function buildPermToIdMap(array $fields): array
    {
        $map = [];
        foreach ($fields as $field) {
            $pn = $field['permName'] ?? '';
            $id = (int) ($field['fieldId'] ?? $field['id'] ?? 0);
            if ($pn !== '' && $id > 0) {
                $map[$pn] = $id;
            }
        }
        return $map;
    }

    /**
     * Obtener categoría de un placeholder.
     */
    private function getPlaceholderCategory(string $placeholder): string
    {
        foreach (self::FIELD_CATEGORIES as $cat => $placeholders) {
            if (in_array($placeholder, $placeholders, true)) {
                return $cat;
            }
        }
        return 'identificadores';
    }
}
