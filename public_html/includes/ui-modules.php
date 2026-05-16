<?php
declare(strict_types=1);

function can_manage_ui_modules(): bool
{
    return can_view_super_admin_overview();
}

function ui_module_schema_errors(): array
{
    static $errors = null;

    if (is_array($errors)) {
        return $errors;
    }

    $errors = [];

    try {
        if (!db_table_exists('ui_modules')) {
            db_query(
                "CREATE TABLE IF NOT EXISTS `ui_modules` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `area_key` VARCHAR(80) NOT NULL,
                    `module_key` VARCHAR(100) NOT NULL,
                    `module_kind` VARCHAR(20) NOT NULL DEFAULT 'custom',
                    `title` VARCHAR(190) DEFAULT NULL,
                    `subtitle` VARCHAR(190) DEFAULT NULL,
                    `body` TEXT DEFAULT NULL,
                    `href` VARCHAR(500) DEFAULT NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_by_user_id` INT UNSIGNED NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `ux_ui_modules_area_module` (`area_key`, `module_key`),
                    KEY `idx_ui_modules_area_order` (`area_key`, `sort_order`, `id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!db_table_exists('crm_layout_fields')) {
            db_query(
                "CREATE TABLE IF NOT EXISTS `crm_layout_fields` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `area_key` VARCHAR(80) NOT NULL,
                    `field_key` VARCHAR(120) NOT NULL,
                    `field_kind` VARCHAR(20) NOT NULL DEFAULT 'custom',
                    `group_key` VARCHAR(100) DEFAULT NULL,
                    `label` VARCHAR(190) DEFAULT NULL,
                    `help_text` TEXT DEFAULT NULL,
                    `placeholder` VARCHAR(190) DEFAULT NULL,
                    `input_type` VARCHAR(40) DEFAULT NULL,
                    `options_json` LONGTEXT DEFAULT NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `is_required_hint` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_by_user_id` INT UNSIGNED NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `ux_crm_layout_fields_area_field` (`area_key`, `field_key`),
                    KEY `idx_crm_layout_fields_area_group_order` (`area_key`, `group_key`, `sort_order`, `id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!db_table_exists('crm_custom_field_values')) {
            db_query(
                "CREATE TABLE IF NOT EXISTS `crm_custom_field_values` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `entity_type` VARCHAR(80) NOT NULL,
                    `entity_id` INT UNSIGNED NOT NULL,
                    `field_key` VARCHAR(120) NOT NULL,
                    `value_text` TEXT DEFAULT NULL,
                    `value_json` LONGTEXT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `ux_crm_custom_field_values_entity_field` (`entity_type`, `entity_id`, `field_key`),
                    KEY `idx_crm_custom_field_values_field` (`field_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    } catch (Throwable $exception) {
        $errors[] = APP_DEBUG
            ? 'A modulbeállítások táblája nem érhető el: ' . $exception->getMessage()
            : 'A modulbeállítások táblája nem érhető el.';
    }

    return $errors;
}

function ui_module_table_ready(): bool
{
    return ui_module_schema_errors() === [];
}

function ui_module_areas(): array
{
    return [
        'admin_dashboard' => [
            'label' => 'Admin vezérlőpult',
            'description' => 'A belső admin kezdőoldal fő kártyái és gyors elérései.',
        ],
        'work_center' => [
            'label' => 'Munkaközpont',
            'description' => 'Az importált és helyi munkák listája, szűrői és adatlapkártyái.',
        ],
        'work_detail' => [
            'label' => 'Munka adatlap',
            'description' => 'Egy konkrét ügy vagy kivitelezési munka admin adatlapjának moduljai és mezőfeliratai.',
        ],
        'customer_record' => [
            'label' => 'Ügyféladatlap',
            'description' => 'Az ügyfélhez, igényhez, dokumentumokhoz és kapcsolattartáshoz tartozó látható mezők.',
        ],
        'mvm_documents' => [
            'label' => 'MVM dokumentumok',
            'description' => 'Az MVM dokumentumgenerálás, csomagok, ügyféldokumentumok és feltöltések feliratai.',
        ],
        'electrician_app_home' => [
            'label' => 'Szerelő app kezdőlap',
            'description' => 'A szerelői mobil nyitóképernyő gyors moduljai.',
        ],
        'electrician_work_detail' => [
            'label' => 'Szerelői munka adatlap',
            'description' => 'A kivitelezési munka adatlapján látható panelek sorrendje és feliratai.',
        ],
        'customer_portal' => [
            'label' => 'Ügyfélportál',
            'description' => 'Az ügyfél által látott adatbekérő, dokumentumfeltöltő és státusz felületek szövegei.',
        ],
        'contractor_portal' => [
            'label' => 'Kivitelezői portál',
            'description' => 'A külső kivitelezők munkafelületeinek moduljai és feliratai.',
        ],
    ];
}

function ui_module_area_label(string $areaKey): string
{
    $areas = ui_module_areas();

    return (string) ($areas[$areaKey]['label'] ?? $areaKey);
}

function ui_module_valid_area(string $areaKey): bool
{
    return array_key_exists($areaKey, ui_module_areas());
}

function ui_module_system_definitions(?string $areaKey = null): array
{
    $definitions = [
        'admin_dashboard' => [
            'overview_cards' => [
                'title' => 'Áttekintő kártyák',
                'subtitle' => '',
                'body' => 'A vezérlőpult fő belépési pontjai.',
                'href' => '',
                'sort_order' => 10,
                'variant' => '',
                'supports_href' => false,
            ],
            'work_queue' => [
                'title' => 'Munkák',
                'subtitle' => 'CRM',
                'body' => '',
                'href' => '/admin/minicrm-import',
                'sort_order' => 20,
                'variant' => '',
                'supports_href' => true,
            ],
            'mvm_documents' => [
                'title' => 'MVM dokumentumok',
                'subtitle' => 'Dokumentumok',
                'body' => '',
                'href' => '/admin/minicrm-import',
                'sort_order' => 30,
                'variant' => '',
                'supports_href' => true,
            ],
        ],
        'work_center' => [
            'work_list' => [
                'title' => 'Munkalisták',
                'subtitle' => 'Aktív ügyek',
                'body' => '',
                'href' => '',
                'sort_order' => 10,
                'variant' => '',
                'supports_href' => false,
            ],
            'filters' => [
                'title' => 'Szűrők és keresés',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 20,
                'variant' => '',
                'supports_href' => false,
            ],
            'archive' => [
                'title' => 'Archív munkák',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 30,
                'variant' => '',
                'supports_href' => false,
            ],
        ],
        'work_detail' => [
            'customer_facts' => [
                'title' => 'Ügyfél alapadatok',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 10,
                'variant' => '',
                'supports_href' => false,
            ],
            'workflow' => [
                'title' => 'Folyamat és státusz',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 20,
                'variant' => '',
                'supports_href' => false,
            ],
            'quotes' => [
                'title' => 'Árajánlatok',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 30,
                'variant' => '',
                'supports_href' => false,
            ],
            'documents' => [
                'title' => 'Dokumentumok és fájlok',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 40,
                'variant' => '',
                'supports_href' => false,
            ],
            'communication' => [
                'title' => 'Kapcsolattartás',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 50,
                'variant' => '',
                'supports_href' => false,
            ],
        ],
        'customer_record' => [
            'base_data' => [
                'title' => 'Ügyféladatok',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 10,
                'variant' => '',
                'supports_href' => false,
            ],
            'request_data' => [
                'title' => 'Igényadatok',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 20,
                'variant' => '',
                'supports_href' => false,
            ],
            'uploaded_documents' => [
                'title' => 'Ügyféldokumentumok',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 30,
                'variant' => '',
                'supports_href' => false,
            ],
            'notes' => [
                'title' => 'Megjegyzések',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 40,
                'variant' => '',
                'supports_href' => false,
            ],
        ],
        'mvm_documents' => [
            'payment_gate' => [
                'title' => 'MVM ügyindítás jóváhagyása',
                'subtitle' => 'Ügykezelési díj',
                'body' => '',
                'href' => '#mvm-payment-gate',
                'sort_order' => 10,
                'variant' => '',
                'supports_href' => false,
            ],
            'document_generator' => [
                'title' => 'MVM igénybejelentő kitöltése',
                'subtitle' => 'Sablon',
                'body' => '',
                'href' => '#mvm-docx-form',
                'sort_order' => 20,
                'variant' => '',
                'supports_href' => false,
            ],
            'manual_upload' => [
                'title' => 'Új MVM dokumentum',
                'subtitle' => 'Feltöltés',
                'body' => '',
                'href' => '',
                'sort_order' => 30,
                'variant' => '',
                'supports_href' => false,
            ],
            'work_summary' => [
                'title' => 'Munka adatai',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 40,
                'variant' => '',
                'supports_href' => false,
            ],
            'customer_documents' => [
                'title' => 'Dokumentum bekérése ügyféltől',
                'subtitle' => 'Ügyféldokumentum',
                'body' => '',
                'href' => '#customer-document-request-panel',
                'sort_order' => 50,
                'variant' => '',
                'supports_href' => false,
            ],
            'custom_fields' => [
                'title' => 'Egyedi CRM mezők',
                'subtitle' => '',
                'body' => '',
                'href' => '#mvm-custom-fields',
                'sort_order' => 55,
                'variant' => '',
                'supports_href' => false,
            ],
            'approval_package' => [
                'title' => 'MVM jóváhagyási PDF csomag',
                'subtitle' => '1. csomag',
                'body' => '',
                'href' => '',
                'sort_order' => 60,
                'variant' => '',
                'supports_href' => false,
            ],
            'execution_plan_package' => [
                'title' => 'Kiviteli terv PDF csomag',
                'subtitle' => '2. csomag',
                'body' => '',
                'href' => '#execution-plan-package-section',
                'sort_order' => 70,
                'variant' => '',
                'supports_href' => false,
            ],
            'technical_handover' => [
                'title' => 'Átadás-átvételi PDF csomag',
                'subtitle' => 'Műszaki átadás',
                'body' => '',
                'href' => '#technical-handover-section',
                'sort_order' => 80,
                'variant' => '',
                'supports_href' => false,
            ],
            'seal_removal' => [
                'title' => 'Plombabontási PDF csomag',
                'subtitle' => 'Plombabontás',
                'body' => '',
                'href' => '#seal-removal-section',
                'sort_order' => 90,
                'variant' => '',
                'supports_href' => false,
            ],
            'mailbox' => [
                'title' => 'MVM levelezés',
                'subtitle' => '',
                'body' => '',
                'href' => '#mvm-mailbox',
                'sort_order' => 100,
                'variant' => '',
                'supports_href' => false,
            ],
            'document_list' => [
                'title' => 'MVM dokumentumlista',
                'subtitle' => '',
                'body' => '',
                'href' => '#mvm-documents-list',
                'sort_order' => 110,
                'variant' => '',
                'supports_href' => false,
            ],
        ],
        'electrician_app_home' => [
            'quick_quote' => [
                'title' => 'Új ajánlat',
                'subtitle' => 'Gyors árajánlat',
                'body' => '',
                'href' => '/quick-quote',
                'sort_order' => 10,
                'variant' => 'primary',
                'supports_href' => true,
            ],
            'new_survey' => [
                'title' => 'Adatlap + fotók',
                'subtitle' => 'Új felmérés',
                'body' => '',
                'href' => '/electrician/work-request',
                'sort_order' => 20,
                'variant' => '',
                'supports_href' => true,
            ],
            'all_works' => [
                'title' => 'Teljes lista',
                'subtitle' => 'Minden munka',
                'body' => '',
                'href' => '/electrician/work-requests',
                'sort_order' => 30,
                'variant' => '',
                'supports_href' => true,
            ],
        ],
        'electrician_work_detail' => [
            'initial_data' => [
                'title' => 'Adatlap alapadatok javítása',
                'subtitle' => 'Folyamatban előtt',
                'body' => '',
                'href' => '',
                'sort_order' => 10,
                'variant' => '',
                'supports_href' => false,
            ],
            'payment_summary' => [
                'title' => 'Kivitelezéskor beszedendő összeg',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 20,
                'variant' => '',
                'supports_href' => false,
            ],
            'workflow' => [
                'title' => 'Munkafolyamat',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 30,
                'variant' => '',
                'supports_href' => false,
            ],
            'schedule_calendar' => [
                'title' => 'Kivitelezési naptár',
                'subtitle' => 'Csak hétköznap',
                'body' => 'Nyisd meg azokat a napokat, amikor vállalható a munka. A kiválasztott nap egyetlen munkanapként foglalódik.',
                'href' => '',
                'sort_order' => 40,
                'variant' => '',
                'supports_href' => false,
            ],
            'request_data' => [
                'title' => 'Ügyfél és igényadatok',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 50,
                'variant' => '',
                'supports_href' => false,
            ],
            'files' => [
                'title' => 'Ügyfél által feltöltött fájlok',
                'subtitle' => '',
                'body' => '',
                'href' => '',
                'sort_order' => 60,
                'variant' => '',
                'supports_href' => false,
            ],
            'customer_communication' => [
                'title' => 'Ügyfélkommunikáció',
                'subtitle' => '',
                'body' => 'Itt ugyanaz az ügyféllel folytatott levelezés látszik, amit az admin is lát. Ha az ügyfél válaszol, a válasz az azonosító alapján ehhez az adatlaphoz kerül.',
                'href' => '',
                'sort_order' => 70,
                'variant' => '',
                'supports_href' => false,
            ],
            'work_photos_before' => [
                'title' => 'Kivitelezés előtti kötelező fotók',
                'subtitle' => '',
                'body' => 'Ezeket a képeket a munka megkezdése előtt kell feltölteni.',
                'href' => '',
                'sort_order' => 80,
                'variant' => '',
                'supports_href' => false,
            ],
            'work_photos_after' => [
                'title' => 'Kivitelezés utáni kötelező fotók',
                'subtitle' => '',
                'body' => 'Az elkészült beavatkozási lap fotója is kötelező.',
                'href' => '',
                'sort_order' => 90,
                'variant' => '',
                'supports_href' => false,
            ],
        ],
    ];

    if ($areaKey !== null) {
        return $definitions[$areaKey] ?? [];
    }

    return $definitions;
}

function ui_module_default_definition(string $areaKey, string $moduleKey): ?array
{
    $definitions = ui_module_system_definitions($areaKey);

    return isset($definitions[$moduleKey]) && is_array($definitions[$moduleKey])
        ? $definitions[$moduleKey]
        : null;
}

function ui_module_rows_for_area(string $areaKey): array
{
    static $cache = [];

    if (isset($cache[$areaKey])) {
        return $cache[$areaKey];
    }

    if (!ui_module_valid_area($areaKey) || !ui_module_table_ready()) {
        $cache[$areaKey] = [];

        return [];
    }

    $rows = db_query(
        'SELECT *
         FROM `ui_modules`
         WHERE `area_key` = ?
         ORDER BY `sort_order` ASC, `id` ASC',
        [$areaKey]
    )->fetchAll();

    $byKey = [];

    foreach ($rows as $row) {
        $moduleKey = (string) ($row['module_key'] ?? '');

        if ($moduleKey === '') {
            continue;
        }

        $byKey[$moduleKey] = $row;
    }

    $cache[$areaKey] = $byKey;

    return $byKey;
}

function ui_module_effective_text(?string $storedValue, string $defaultValue): string
{
    $storedValue = $storedValue !== null ? trim($storedValue) : '';

    return $storedValue !== '' ? $storedValue : $defaultValue;
}

function ui_module_item_from_definition(string $areaKey, string $moduleKey, array $definition, ?array $row = null): array
{
    $storedTitle = $row !== null ? (string) ($row['title'] ?? '') : '';
    $storedSubtitle = $row !== null ? (string) ($row['subtitle'] ?? '') : '';
    $storedBody = $row !== null ? (string) ($row['body'] ?? '') : '';
    $storedHref = $row !== null ? (string) ($row['href'] ?? '') : '';
    $defaultHref = (string) ($definition['href'] ?? '');

    return [
        'area_key' => $areaKey,
        'module_key' => $moduleKey,
        'module_kind' => 'system',
        'is_system' => true,
        'is_custom' => false,
        'is_enabled' => true,
        'title' => ui_module_effective_text($storedTitle, (string) ($definition['title'] ?? $moduleKey)),
        'subtitle' => ui_module_effective_text($storedSubtitle, (string) ($definition['subtitle'] ?? '')),
        'body' => ui_module_effective_text($storedBody, (string) ($definition['body'] ?? '')),
        'href' => ui_module_normalize_href(ui_module_effective_text($storedHref, $defaultHref)),
        'sort_order' => $row !== null ? (int) ($row['sort_order'] ?? $definition['sort_order'] ?? 0) : (int) ($definition['sort_order'] ?? 0),
        'variant' => (string) ($definition['variant'] ?? ''),
        'supports_href' => !empty($definition['supports_href']),
        'default_title' => (string) ($definition['title'] ?? $moduleKey),
        'default_subtitle' => (string) ($definition['subtitle'] ?? ''),
        'default_body' => (string) ($definition['body'] ?? ''),
        'default_href' => $defaultHref,
        'stored_title' => $storedTitle,
        'stored_subtitle' => $storedSubtitle,
        'stored_body' => $storedBody,
        'stored_href' => $storedHref,
    ];
}

function ui_module_item_from_row(string $areaKey, array $row): array
{
    $moduleKey = (string) ($row['module_key'] ?? '');

    return [
        'area_key' => $areaKey,
        'module_key' => $moduleKey,
        'module_kind' => 'custom',
        'is_system' => false,
        'is_custom' => true,
        'is_enabled' => (int) ($row['is_enabled'] ?? 1) === 1,
        'title' => trim((string) ($row['title'] ?? '')) ?: 'Egyedi modul',
        'subtitle' => trim((string) ($row['subtitle'] ?? '')),
        'body' => trim((string) ($row['body'] ?? '')),
        'href' => ui_module_normalize_href((string) ($row['href'] ?? '')),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'variant' => '',
        'supports_href' => true,
        'default_title' => '',
        'default_subtitle' => '',
        'default_body' => '',
        'default_href' => '',
        'stored_title' => (string) ($row['title'] ?? ''),
        'stored_subtitle' => (string) ($row['subtitle'] ?? ''),
        'stored_body' => (string) ($row['body'] ?? ''),
        'stored_href' => (string) ($row['href'] ?? ''),
    ];
}

function ui_modules_for_area(string $areaKey, bool $includeDisabledCustom = false): array
{
    if (!ui_module_valid_area($areaKey)) {
        return [];
    }

    $definitions = ui_module_system_definitions($areaKey);
    $rows = ui_module_rows_for_area($areaKey);
    $items = [];

    foreach ($definitions as $moduleKey => $definition) {
        $items[] = ui_module_item_from_definition(
            $areaKey,
            (string) $moduleKey,
            $definition,
            isset($rows[$moduleKey]) && is_array($rows[$moduleKey]) ? $rows[$moduleKey] : null
        );
    }

    foreach ($rows as $moduleKey => $row) {
        if (isset($definitions[$moduleKey])) {
            continue;
        }

        $item = ui_module_item_from_row($areaKey, $row);

        if (!$includeDisabledCustom && !$item['is_enabled']) {
            continue;
        }

        $items[] = $item;
    }

    usort(
        $items,
        static function (array $first, array $second): int {
            $orderCompare = (int) $first['sort_order'] <=> (int) $second['sort_order'];

            if ($orderCompare !== 0) {
                return $orderCompare;
            }

            return strcmp((string) $first['module_key'], (string) $second['module_key']);
        }
    );

    return $items;
}

function ui_module_find_item(string $areaKey, string $moduleKey, bool $includeDisabledCustom = true): ?array
{
    foreach (ui_modules_for_area($areaKey, $includeDisabledCustom) as $item) {
        if ((string) $item['module_key'] === $moduleKey) {
            return $item;
        }
    }

    return null;
}

function ui_module_text(string $areaKey, string $moduleKey, string $field, string $defaultValue = ''): string
{
    $row = ui_module_rows_for_area($areaKey)[$moduleKey] ?? null;

    if (is_array($row) && array_key_exists($field, $row)) {
        return ui_module_effective_text((string) ($row[$field] ?? ''), $defaultValue);
    }

    $definition = ui_module_default_definition($areaKey, $moduleKey);

    if ($definition !== null && array_key_exists($field, $definition)) {
        return ui_module_effective_text((string) ($definition[$field] ?? ''), $defaultValue);
    }

    return $defaultValue;
}

function ui_module_sort_order(string $areaKey, string $moduleKey): int
{
    $item = ui_module_find_item($areaKey, $moduleKey, true);

    return is_array($item) ? (int) $item['sort_order'] : 0;
}

function ui_module_attrs(string $areaKey, string $moduleKey, string $classes): string
{
    $class = trim($classes . ' ui-configurable-module');
    $order = ui_module_sort_order($areaKey, $moduleKey);

    return 'class="' . h($class) . '" data-ui-module="' . h($moduleKey) . '" style="order: ' . $order . ';"';
}

function ui_module_normalize_href(string $href): string
{
    $href = trim($href);

    if ($href === '') {
        return '';
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $href)) {
        return '';
    }

    if (str_starts_with($href, '#')) {
        return $href;
    }

    if (str_starts_with($href, '/')) {
        return str_starts_with($href, '//') ? '' : $href;
    }

    $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $href : '';
}

function ui_module_public_url(string $href): string
{
    $href = ui_module_normalize_href($href);

    if ($href === '') {
        return '#';
    }

    if (str_starts_with($href, '/')) {
        return url_path($href);
    }

    return $href;
}

function ui_module_next_sort_order(string $areaKey): int
{
    $max = 0;

    foreach (ui_modules_for_area($areaKey, true) as $item) {
        $max = max($max, (int) $item['sort_order']);
    }

    return $max + 10;
}

function ui_module_persist_order(string $areaKey, string $moduleKey, int $sortOrder, string $moduleKind): void
{
    db_query(
        'INSERT INTO `ui_modules` (`area_key`, `module_key`, `module_kind`, `sort_order`, `is_enabled`)
         VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            `module_kind` = VALUES(`module_kind`),
            `sort_order` = VALUES(`sort_order`)',
        [$areaKey, $moduleKey, $moduleKind, $sortOrder]
    );
}

function ui_module_move(string $areaKey, string $moduleKey, string $direction): void
{
    $items = ui_modules_for_area($areaKey, true);
    $index = null;

    foreach ($items as $itemIndex => $item) {
        if ((string) $item['module_key'] === $moduleKey) {
            $index = $itemIndex;
            break;
        }
    }

    if ($index === null) {
        return;
    }

    $target = $items[$index];

    if ($direction === 'top') {
        if ($index === 0) {
            return;
        }

        $minOrder = min(array_map(static fn (array $item): int => (int) $item['sort_order'], $items));
        ui_module_persist_order($areaKey, $moduleKey, $minOrder - 10, (string) $target['module_kind']);

        return;
    }

    if ($direction === 'bottom') {
        if ($index === count($items) - 1) {
            return;
        }

        $maxOrder = max(array_map(static fn (array $item): int => (int) $item['sort_order'], $items));
        ui_module_persist_order($areaKey, $moduleKey, $maxOrder + 10, (string) $target['module_kind']);

        return;
    }

    $swapIndex = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);

    if ($swapIndex === null || !isset($items[$swapIndex])) {
        return;
    }

    $other = $items[$swapIndex];
    ui_module_persist_order($areaKey, $moduleKey, (int) $other['sort_order'], (string) $target['module_kind']);
    ui_module_persist_order($areaKey, (string) $other['module_key'], (int) $target['sort_order'], (string) $other['module_kind']);
}

function ui_module_save_fields(string $areaKey, string $moduleKey, string $moduleKind, array $fields): void
{
    $user = current_user();
    $userId = is_array($user) && isset($user['id']) ? (int) $user['id'] : null;

    db_query(
        'INSERT INTO `ui_modules`
            (`area_key`, `module_key`, `module_kind`, `title`, `subtitle`, `body`, `href`, `sort_order`, `is_enabled`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            `module_kind` = VALUES(`module_kind`),
            `title` = VALUES(`title`),
            `subtitle` = VALUES(`subtitle`),
            `body` = VALUES(`body`),
            `href` = VALUES(`href`),
            `sort_order` = VALUES(`sort_order`),
            `is_enabled` = VALUES(`is_enabled`)',
        [
            $areaKey,
            $moduleKey,
            $moduleKind,
            $fields['title'] ?? null,
            $fields['subtitle'] ?? null,
            $fields['body'] ?? null,
            $fields['href'] ?? null,
            (int) ($fields['sort_order'] ?? ui_module_sort_order($areaKey, $moduleKey)),
            !empty($fields['is_enabled']) ? 1 : 0,
            $userId,
        ]
    );
}

function ui_module_create_custom(string $areaKey, array $fields): string
{
    $moduleKey = 'custom_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $fields['sort_order'] = ui_module_next_sort_order($areaKey);
    $fields['is_enabled'] = 1;

    ui_module_save_fields($areaKey, $moduleKey, 'custom', $fields);

    return $moduleKey;
}

function ui_module_delete_custom(string $areaKey, string $moduleKey): void
{
    $item = ui_module_find_item($areaKey, $moduleKey, true);

    if (!is_array($item) || empty($item['is_custom'])) {
        return;
    }

    db_query(
        'DELETE FROM `ui_modules` WHERE `area_key` = ? AND `module_key` = ? AND `module_kind` = ?',
        [$areaKey, $moduleKey, 'custom']
    );
}

function crm_customization_schema_errors(): array
{
    return ui_module_schema_errors();
}

function crm_layout_field_definition(string $label, int $sortOrder, string $groupKey = 'general', string $inputType = 'text', string $helpText = '', bool $required = false): array
{
    return [
        'label' => $label,
        'help_text' => $helpText,
        'placeholder' => '',
        'input_type' => $inputType,
        'group_key' => $groupKey,
        'sort_order' => $sortOrder,
        'is_required_hint' => $required,
    ];
}

function crm_layout_field_system_definitions(?string $areaKey = null): array
{
    $definitions = [
        'work_detail' => [
            'customer_name' => crm_layout_field_definition('Ügyfél', 10, 'customer'),
            'mvm_uk_number' => crm_layout_field_definition('ÜK szám', 20, 'customer'),
            'work_note' => crm_layout_field_definition('Munka megjegyzés', 30, 'customer', 'textarea'),
            'email' => crm_layout_field_definition('Email', 40, 'customer', 'email'),
            'phone' => crm_layout_field_definition('Telefon', 50, 'customer', 'tel'),
            'site_address' => crm_layout_field_definition('Felhasználási / kivitelezési cím', 60, 'request'),
            'hrsz' => crm_layout_field_definition('HRSZ', 70, 'request'),
            'responsible' => crm_layout_field_definition('Felelős', 80, 'workflow'),
            'workflow_stage' => crm_layout_field_definition('Folyamat státusz', 90, 'workflow'),
            'electrician' => crm_layout_field_definition('Szerelő', 100, 'workflow'),
        ],
        'customer_record' => [
            'requester_name' => crm_layout_field_definition('Ügyfél neve', 10, 'base'),
            'email' => crm_layout_field_definition('Email', 20, 'base', 'email'),
            'phone' => crm_layout_field_definition('Telefon', 30, 'base', 'tel'),
            'site_postal_code' => crm_layout_field_definition('Felhasználási hely irányítószáma', 40, 'request'),
            'site_address' => crm_layout_field_definition('Felhasználási / kivitelezési cím', 50, 'request'),
            'hrsz' => crm_layout_field_definition('HRSZ', 60, 'request'),
            'mvm_uk_number' => crm_layout_field_definition('ÜK szám', 70, 'request'),
            'meter_serial' => crm_layout_field_definition('Mérő gyári szám', 80, 'request'),
            'request_type' => crm_layout_field_definition('Munka típusa', 90, 'request', 'select'),
            'project_name' => crm_layout_field_definition('Munka megnevezése', 100, 'request'),
        ],
        'mvm_documents' => [
            'source_requester_name' => crm_layout_field_definition('Ügyfél neve', 10, 'source'),
            'source_birth_name' => crm_layout_field_definition('Születési név', 20, 'source'),
            'source_mother_name' => crm_layout_field_definition('Anyja neve', 30, 'source'),
            'source_birth_place' => crm_layout_field_definition('Születési hely', 40, 'source'),
            'source_birth_date' => crm_layout_field_definition('Születési idő', 50, 'source'),
            'source_tax_number' => crm_layout_field_definition('Adószám', 60, 'source'),
            'source_mvm_uk_number' => crm_layout_field_definition('ÜK szám', 70, 'source'),
            'source_project_name' => crm_layout_field_definition('Munka megnevezése', 80, 'source'),
            'source_request_type' => crm_layout_field_definition('Munka típusa', 90, 'source', 'select'),
            'source_site_postal_code' => crm_layout_field_definition('Felhasználási hely irányítószáma', 100, 'source'),
            'source_site_address' => crm_layout_field_definition('Felhasználási / kivitelezési cím', 110, 'source'),
            'source_hrsz' => crm_layout_field_definition('HRSZ', 120, 'source'),
            'source_consumption_place_id' => crm_layout_field_definition('Fogyasztási hely azonosító', 130, 'source'),
            'source_meter_serial' => crm_layout_field_definition('Mérő gyári szám', 140, 'source'),
        ],
        'electrician_work_detail' => [
            'payment_total' => crm_layout_field_definition('Kivitelezéskor beszedendő összeg', 10, 'payment'),
            'calendar_date' => crm_layout_field_definition('Kivitelezési időpont', 20, 'schedule'),
            'work_status' => crm_layout_field_definition('Munka státusz', 30, 'workflow'),
            'customer_message' => crm_layout_field_definition('Ügyfélkommunikáció', 40, 'communication', 'textarea'),
        ],
        'customer_portal' => [
            'upload_title' => crm_layout_field_definition('Dokumentum feltöltése', 10, 'document_upload'),
            'upload_help' => crm_layout_field_definition('Feltöltési segítség', 20, 'document_upload', 'textarea'),
            'status_label' => crm_layout_field_definition('Státusz', 30, 'status'),
        ],
        'contractor_portal' => [
            'work_title' => crm_layout_field_definition('Munka címe', 10, 'work'),
            'site_address' => crm_layout_field_definition('Kivitelezési cím', 20, 'work'),
            'upload_files' => crm_layout_field_definition('Fájlok feltöltése', 30, 'documents'),
        ],
    ];

    if (function_exists('connection_request_upload_definitions')) {
        $sortOrder = 200;

        foreach (connection_request_upload_definitions() as $key => $definition) {
            $definitions['customer_record']['request_upload_' . (string) $key] = crm_layout_field_definition(
                (string) ($definition['label'] ?? $key),
                $sortOrder,
                'request_uploads',
                (string) (($definition['kind'] ?? '') === 'image' ? 'image' : 'file')
            );
            $sortOrder += 10;
        }
    }

    if (function_exists('mvm_document_types')) {
        $sortOrder = 300;

        foreach (mvm_document_types(true) as $key => $label) {
            $definitions['mvm_documents']['mvm_document_type_' . (string) $key] = crm_layout_field_definition(
                (string) $label,
                $sortOrder,
                'document_types',
                'select'
            );
            $sortOrder += 10;
        }
    }

    if (function_exists('customer_document_upload_definitions')) {
        $sortOrder = 500;

        foreach (customer_document_upload_definitions() as $key => $definition) {
            $definitions['mvm_documents']['customer_document_' . (string) $key] = crm_layout_field_definition(
                (string) ($definition['label'] ?? $key),
                $sortOrder,
                'customer_documents',
                (string) (($definition['kind'] ?? '') === 'image' ? 'image' : 'file')
            );
            $sortOrder += 10;
        }
    }

    if (function_exists('mvm_form_field_sections')) {
        $sortOrder = 700;

        foreach (mvm_form_field_sections() as $sectionKey => $section) {
            $definitions['mvm_documents']['mvm_section_' . (string) $sectionKey] = crm_layout_field_definition(
                (string) ($section['title'] ?? $sectionKey),
                $sortOrder,
                'mvm_form_sections',
                'section',
                (string) ($section['description'] ?? '')
            );
            $sortOrder += 10;

            foreach (($section['fields'] ?? []) as $fieldKey => $field) {
                $definitions['mvm_documents']['mvm_field_' . (string) $fieldKey] = crm_layout_field_definition(
                    (string) ($field['label'] ?? $fieldKey),
                    $sortOrder,
                    'mvm_form_fields',
                    (string) ($field['type'] ?? 'text'),
                    '',
                    !empty($field['required'])
                );
                $sortOrder += 10;
            }
        }
    }

    if (function_exists('mvm_technical_handover_form_field_definitions')) {
        $sortOrder = 1400;

        foreach (mvm_technical_handover_form_field_definitions() as $fieldKey => $field) {
            $definitions['mvm_documents']['mvm_field_' . (string) $fieldKey] = crm_layout_field_definition(
                (string) ($field['label'] ?? $fieldKey),
                $sortOrder,
                'technical_handover_fields',
                (string) ($field['type'] ?? 'text'),
                '',
                !empty($field['required'])
            );
            $sortOrder += 10;
        }
    }

    if ($areaKey !== null) {
        return $definitions[$areaKey] ?? [];
    }

    return $definitions;
}

function crm_layout_field_rows_for_area(string $areaKey): array
{
    static $cache = [];

    if (isset($cache[$areaKey])) {
        return $cache[$areaKey];
    }

    if (!ui_module_valid_area($areaKey) || !ui_module_table_ready()) {
        $cache[$areaKey] = [];

        return [];
    }

    $rows = db_query(
        'SELECT *
         FROM `crm_layout_fields`
         WHERE `area_key` = ?
         ORDER BY `sort_order` ASC, `id` ASC',
        [$areaKey]
    )->fetchAll();

    $byKey = [];

    foreach ($rows as $row) {
        $fieldKey = (string) ($row['field_key'] ?? '');

        if ($fieldKey === '') {
            continue;
        }

        $byKey[$fieldKey] = $row;
    }

    $cache[$areaKey] = $byKey;

    return $byKey;
}

function crm_layout_field_item_from_definition(string $areaKey, string $fieldKey, array $definition, ?array $row = null): array
{
    $storedLabel = $row !== null ? (string) ($row['label'] ?? '') : '';
    $storedHelp = $row !== null ? (string) ($row['help_text'] ?? '') : '';
    $storedPlaceholder = $row !== null ? (string) ($row['placeholder'] ?? '') : '';
    $defaultLabel = (string) ($definition['label'] ?? $fieldKey);
    $defaultHelp = (string) ($definition['help_text'] ?? '');
    $defaultPlaceholder = (string) ($definition['placeholder'] ?? '');

    return [
        'area_key' => $areaKey,
        'field_key' => $fieldKey,
        'field_kind' => 'system',
        'is_system' => true,
        'is_custom' => false,
        'is_enabled' => true,
        'label' => ui_module_effective_text($storedLabel, $defaultLabel),
        'help_text' => ui_module_effective_text($storedHelp, $defaultHelp),
        'placeholder' => ui_module_effective_text($storedPlaceholder, $defaultPlaceholder),
        'input_type' => (string) ($row['input_type'] ?? $definition['input_type'] ?? 'text'),
        'group_key' => (string) ($row['group_key'] ?? $definition['group_key'] ?? 'general'),
        'sort_order' => $row !== null ? (int) ($row['sort_order'] ?? $definition['sort_order'] ?? 0) : (int) ($definition['sort_order'] ?? 0),
        'is_required_hint' => $row !== null ? (int) ($row['is_required_hint'] ?? 0) === 1 : !empty($definition['is_required_hint']),
        'default_label' => $defaultLabel,
        'default_help_text' => $defaultHelp,
        'default_placeholder' => $defaultPlaceholder,
        'stored_label' => $storedLabel,
        'stored_help_text' => $storedHelp,
        'stored_placeholder' => $storedPlaceholder,
    ];
}

function crm_layout_field_item_from_row(string $areaKey, array $row): array
{
    $fieldKey = (string) ($row['field_key'] ?? '');

    return [
        'area_key' => $areaKey,
        'field_key' => $fieldKey,
        'field_kind' => 'custom',
        'is_system' => false,
        'is_custom' => true,
        'is_enabled' => (int) ($row['is_enabled'] ?? 1) === 1,
        'label' => trim((string) ($row['label'] ?? '')) ?: 'Egyedi mező',
        'help_text' => trim((string) ($row['help_text'] ?? '')),
        'placeholder' => trim((string) ($row['placeholder'] ?? '')),
        'input_type' => (string) ($row['input_type'] ?? 'text'),
        'group_key' => (string) ($row['group_key'] ?? 'custom'),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'is_required_hint' => (int) ($row['is_required_hint'] ?? 0) === 1,
        'default_label' => '',
        'default_help_text' => '',
        'default_placeholder' => '',
        'stored_label' => (string) ($row['label'] ?? ''),
        'stored_help_text' => (string) ($row['help_text'] ?? ''),
        'stored_placeholder' => (string) ($row['placeholder'] ?? ''),
    ];
}

function crm_layout_fields_for_area(string $areaKey, bool $includeDisabledCustom = false): array
{
    if (!ui_module_valid_area($areaKey)) {
        return [];
    }

    $definitions = crm_layout_field_system_definitions($areaKey);
    $rows = crm_layout_field_rows_for_area($areaKey);
    $items = [];

    foreach ($definitions as $fieldKey => $definition) {
        $items[] = crm_layout_field_item_from_definition(
            $areaKey,
            (string) $fieldKey,
            $definition,
            isset($rows[$fieldKey]) && is_array($rows[$fieldKey]) ? $rows[$fieldKey] : null
        );
    }

    foreach ($rows as $fieldKey => $row) {
        if (isset($definitions[$fieldKey])) {
            continue;
        }

        $item = crm_layout_field_item_from_row($areaKey, $row);

        if (!$includeDisabledCustom && !$item['is_enabled']) {
            continue;
        }

        $items[] = $item;
    }

    usort(
        $items,
        static function (array $first, array $second): int {
            $groupCompare = strcmp((string) $first['group_key'], (string) $second['group_key']);

            if ($groupCompare !== 0) {
                return $groupCompare;
            }

            $orderCompare = (int) $first['sort_order'] <=> (int) $second['sort_order'];

            if ($orderCompare !== 0) {
                return $orderCompare;
            }

            return strcmp((string) $first['field_key'], (string) $second['field_key']);
        }
    );

    return $items;
}

function crm_layout_field_find_item(string $areaKey, string $fieldKey, bool $includeDisabledCustom = true): ?array
{
    foreach (crm_layout_fields_for_area($areaKey, $includeDisabledCustom) as $item) {
        if ((string) $item['field_key'] === $fieldKey) {
            return $item;
        }
    }

    return null;
}

function crm_layout_field_sort_order(string $areaKey, string $fieldKey): int
{
    $item = crm_layout_field_find_item($areaKey, $fieldKey, true);

    return is_array($item) ? (int) $item['sort_order'] : 0;
}

function crm_layout_field_next_sort_order(string $areaKey): int
{
    $max = 0;

    foreach (crm_layout_fields_for_area($areaKey, true) as $item) {
        $max = max($max, (int) $item['sort_order']);
    }

    return $max + 10;
}

function crm_layout_field_persist_order(string $areaKey, string $fieldKey, int $sortOrder, string $fieldKind): void
{
    db_query(
        'INSERT INTO `crm_layout_fields` (`area_key`, `field_key`, `field_kind`, `sort_order`, `is_enabled`)
         VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            `field_kind` = VALUES(`field_kind`),
            `sort_order` = VALUES(`sort_order`)',
        [$areaKey, $fieldKey, $fieldKind, $sortOrder]
    );
}

function crm_layout_field_move(string $areaKey, string $fieldKey, string $direction): void
{
    $targetItem = crm_layout_field_find_item($areaKey, $fieldKey, true);

    if (!is_array($targetItem)) {
        return;
    }

    $groupKey = (string) ($targetItem['group_key'] ?? '');
    $items = array_values(array_filter(
        crm_layout_fields_for_area($areaKey, true),
        static fn (array $item): bool => (string) ($item['group_key'] ?? '') === $groupKey
    ));
    $index = null;

    foreach ($items as $itemIndex => $item) {
        if ((string) $item['field_key'] === $fieldKey) {
            $index = $itemIndex;
            break;
        }
    }

    if ($index === null) {
        return;
    }

    $target = $items[$index];

    if ($direction === 'top') {
        if ($index === 0) {
            return;
        }

        $minOrder = min(array_map(static fn (array $item): int => (int) $item['sort_order'], $items));
        crm_layout_field_persist_order($areaKey, $fieldKey, $minOrder - 10, (string) $target['field_kind']);

        return;
    }

    if ($direction === 'bottom') {
        if ($index === count($items) - 1) {
            return;
        }

        $maxOrder = max(array_map(static fn (array $item): int => (int) $item['sort_order'], $items));
        crm_layout_field_persist_order($areaKey, $fieldKey, $maxOrder + 10, (string) $target['field_kind']);

        return;
    }

    $swapIndex = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);

    if ($swapIndex === null || !isset($items[$swapIndex])) {
        return;
    }

    $other = $items[$swapIndex];
    crm_layout_field_persist_order($areaKey, $fieldKey, (int) $other['sort_order'], (string) $target['field_kind']);
    crm_layout_field_persist_order($areaKey, (string) $other['field_key'], (int) $target['sort_order'], (string) $other['field_kind']);
}

function crm_layout_field_save_fields(string $areaKey, string $fieldKey, string $fieldKind, array $fields): void
{
    $user = current_user();
    $userId = is_array($user) && isset($user['id']) ? (int) $user['id'] : null;

    db_query(
        'INSERT INTO `crm_layout_fields`
            (`area_key`, `field_key`, `field_kind`, `group_key`, `label`, `help_text`, `placeholder`, `input_type`, `sort_order`, `is_enabled`, `is_required_hint`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            `field_kind` = VALUES(`field_kind`),
            `group_key` = VALUES(`group_key`),
            `label` = VALUES(`label`),
            `help_text` = VALUES(`help_text`),
            `placeholder` = VALUES(`placeholder`),
            `input_type` = VALUES(`input_type`),
            `sort_order` = VALUES(`sort_order`),
            `is_enabled` = VALUES(`is_enabled`),
            `is_required_hint` = VALUES(`is_required_hint`)',
        [
            $areaKey,
            $fieldKey,
            $fieldKind,
            $fields['group_key'] ?? null,
            $fields['label'] ?? null,
            $fields['help_text'] ?? null,
            $fields['placeholder'] ?? null,
            $fields['input_type'] ?? 'text',
            (int) ($fields['sort_order'] ?? crm_layout_field_sort_order($areaKey, $fieldKey)),
            !empty($fields['is_enabled']) ? 1 : 0,
            !empty($fields['is_required_hint']) ? 1 : 0,
            $userId,
        ]
    );
}

function crm_layout_field_create_custom(string $areaKey, array $fields): string
{
    $fieldKey = 'custom_field_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $fields['sort_order'] = crm_layout_field_next_sort_order($areaKey);
    $fields['is_enabled'] = 1;

    crm_layout_field_save_fields($areaKey, $fieldKey, 'custom', $fields);

    return $fieldKey;
}

function crm_layout_field_delete_custom(string $areaKey, string $fieldKey): void
{
    $item = crm_layout_field_find_item($areaKey, $fieldKey, true);

    if (!is_array($item) || empty($item['is_custom'])) {
        return;
    }

    db_query(
        'DELETE FROM `crm_layout_fields` WHERE `area_key` = ? AND `field_key` = ? AND `field_kind` = ?',
        [$areaKey, $fieldKey, 'custom']
    );
}

function crm_custom_field_label(string $areaKey, string $fieldKey, string $defaultLabel): string
{
    $row = crm_layout_field_rows_for_area($areaKey)[$fieldKey] ?? null;

    if (!is_array($row)) {
        return $defaultLabel;
    }

    return ui_module_effective_text((string) ($row['label'] ?? ''), $defaultLabel);
}

function crm_custom_field_help_text(string $areaKey, string $fieldKey, string $defaultHelp = ''): string
{
    $row = crm_layout_field_rows_for_area($areaKey)[$fieldKey] ?? null;

    if (!is_array($row)) {
        return $defaultHelp;
    }

    return ui_module_effective_text((string) ($row['help_text'] ?? ''), $defaultHelp);
}

function crm_custom_field_placeholder(string $areaKey, string $fieldKey, string $defaultPlaceholder = ''): string
{
    $row = crm_layout_field_rows_for_area($areaKey)[$fieldKey] ?? null;

    if (!is_array($row)) {
        return $defaultPlaceholder;
    }

    return ui_module_effective_text((string) ($row['placeholder'] ?? ''), $defaultPlaceholder);
}

function crm_customize_connection_request_upload_definitions(array $definitions, string $areaKey = 'customer_record', string $prefix = 'request_upload_'): array
{
    foreach ($definitions as $key => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $definitions[$key]['label'] = crm_custom_field_label($areaKey, $prefix . (string) $key, (string) ($definition['label'] ?? $key));
    }

    return $definitions;
}

function crm_customize_document_type_labels(array $types): array
{
    foreach ($types as $key => $label) {
        $types[$key] = crm_custom_field_label('mvm_documents', 'mvm_document_type_' . (string) $key, (string) $label);
    }

    return $types;
}

function crm_customize_mvm_form_sections(array $sections): array
{
    foreach ($sections as $sectionKey => $section) {
        if (!is_array($section)) {
            continue;
        }

        $sectionFieldKey = 'mvm_section_' . (string) $sectionKey;
        $sections[$sectionKey]['title'] = crm_custom_field_label('mvm_documents', $sectionFieldKey, (string) ($section['title'] ?? $sectionKey));
        $sections[$sectionKey]['description'] = crm_custom_field_help_text('mvm_documents', $sectionFieldKey, (string) ($section['description'] ?? ''));

        foreach (($section['fields'] ?? []) as $fieldKey => $field) {
            if (!is_array($field)) {
                continue;
            }

            $crmFieldKey = 'mvm_field_' . (string) $fieldKey;
            $sections[$sectionKey]['fields'][$fieldKey]['label'] = crm_custom_field_label('mvm_documents', $crmFieldKey, (string) ($field['label'] ?? $fieldKey));
            $sections[$sectionKey]['fields'][$fieldKey]['placeholder'] = crm_custom_field_placeholder('mvm_documents', $crmFieldKey, (string) ($field['placeholder'] ?? ''));
        }
    }

    return $sections;
}

function crm_customize_mvm_field_definitions(array $definitions): array
{
    foreach ($definitions as $fieldKey => $field) {
        if (!is_array($field)) {
            continue;
        }

        $crmFieldKey = 'mvm_field_' . (string) $fieldKey;
        $definitions[$fieldKey]['label'] = crm_custom_field_label('mvm_documents', $crmFieldKey, (string) ($field['label'] ?? $fieldKey));
        $definitions[$fieldKey]['placeholder'] = crm_custom_field_placeholder('mvm_documents', $crmFieldKey, (string) ($field['placeholder'] ?? ''));
    }

    return $definitions;
}

function crm_layout_custom_fields_for_area(string $areaKey): array
{
    return array_values(array_filter(
        crm_layout_fields_for_area($areaKey, false),
        static fn (array $field): bool => !empty($field['is_custom'])
    ));
}

function crm_custom_field_value_rows(string $entityType, int $entityId): array
{
    static $cache = [];
    $cacheKey = $entityType . ':' . $entityId;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if ($entityType === '' || $entityId <= 0 || !ui_module_table_ready()) {
        $cache[$cacheKey] = [];

        return [];
    }

    $rows = db_query(
        'SELECT *
         FROM `crm_custom_field_values`
         WHERE `entity_type` = ? AND `entity_id` = ?',
        [$entityType, $entityId]
    )->fetchAll();

    $values = [];

    foreach ($rows as $row) {
        $fieldKey = (string) ($row['field_key'] ?? '');

        if ($fieldKey === '') {
            continue;
        }

        $values[$fieldKey] = (string) ($row['value_text'] ?? '');
    }

    $cache[$cacheKey] = $values;

    return $values;
}

function crm_custom_field_value(string $entityType, int $entityId, string $fieldKey): string
{
    $values = crm_custom_field_value_rows($entityType, $entityId);

    return (string) ($values[$fieldKey] ?? '');
}

function crm_custom_field_save_value(string $entityType, int $entityId, string $fieldKey, string $value): void
{
    if ($entityType === '' || $entityId <= 0 || $fieldKey === '' || !ui_module_table_ready()) {
        return;
    }

    $value = trim($value);

    if ($value === '') {
        db_query(
            'DELETE FROM `crm_custom_field_values`
             WHERE `entity_type` = ? AND `entity_id` = ? AND `field_key` = ?',
            [$entityType, $entityId, $fieldKey]
        );

        return;
    }

    db_query(
        'INSERT INTO `crm_custom_field_values` (`entity_type`, `entity_id`, `field_key`, `value_text`)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            `value_text` = VALUES(`value_text`),
            `value_json` = NULL',
        [$entityType, $entityId, $fieldKey, $value]
    );
}

function crm_custom_field_save_post_values(string $entityType, int $entityId, string $areaKey, array $source): void
{
    $customFields = crm_layout_custom_fields_for_area($areaKey);

    foreach ($customFields as $field) {
        $fieldKey = (string) ($field['field_key'] ?? '');

        if ($fieldKey === '') {
            continue;
        }

        $inputName = 'crm_custom_field_' . $fieldKey;
        $value = '';

        if (($field['input_type'] ?? '') === 'section') {
            continue;
        }

        if (($field['input_type'] ?? '') === 'checkbox') {
            $value = !empty($source[$inputName]) ? '1' : '';
        } else {
            $rawValue = $source[$inputName] ?? '';
            $value = is_array($rawValue) ? implode(', ', array_map('strval', $rawValue)) : (string) $rawValue;
        }

        crm_custom_field_save_value($entityType, $entityId, $fieldKey, $value);
    }
}
