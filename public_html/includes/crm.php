<?php
declare(strict_types=1);

function db_table_exists(string $table): bool
{
    $statement = db_query(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
        [DB_NAME, $table]
    );

    return (bool) $statement->fetchColumn();
}

function db_column_exists(string $table, string $column): bool
{
    $statement = db_query(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [DB_NAME, $table, $column]
    );

    return (bool) $statement->fetchColumn();
}

function db_enum_contains(string $table, string $column, string $value): bool
{
    $statement = db_query(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [DB_NAME, $table, $column]
    );
    $columnType = (string) ($statement->fetchColumn() ?: '');

    return str_contains($columnType, "'" . $value . "'");
}

function contractor_schema_errors(): array
{
    $errors = [];

    if (!db_table_exists('contractors')) {
        $errors[] = 'Hianyzik a contractors tabla.';
    }

    if (!db_column_exists('customers', 'created_by_user_id')) {
        $errors[] = 'Hianyzik a customers.created_by_user_id oszlop.';
    }

    if (!db_column_exists('connection_requests', 'submitted_by_user_id')) {
        $errors[] = 'Hianyzik a connection_requests.submitted_by_user_id oszlop.';
    }

    if (!db_column_exists('connection_requests', 'request_type')) {
        $errors[] = 'Hianyzik a connection_requests.request_type oszlop.';
    }

    if (!db_enum_contains('users', 'role', 'general_contractor')) {
        $errors[] = 'A users.role mezoben meg nincs engedelyezve a general_contractor szerepkor.';
    }

    return $errors;
}

function electrician_schema_errors(): array
{
    $errors = [];

    if (!db_enum_contains('users', 'role', 'electrician')) {
        $errors[] = 'A users.role mezőben még nincs engedélyezve az electrician szerepkör.';
    }

    if (!db_table_exists('electricians')) {
        $errors[] = 'Hiányzik az electricians tábla.';
    }

    if (!db_column_exists('connection_requests', 'assigned_electrician_user_id')) {
        $errors[] = 'Hiányzik a connection_requests.assigned_electrician_user_id oszlop.';
    }

    if (!db_column_exists('connection_requests', 'electrician_status')) {
        $errors[] = 'Hiányzik a connection_requests.electrician_status oszlop.';
    }

    if (!db_table_exists('connection_request_work_files')) {
        $errors[] = 'Hiányzik a connection_request_work_files tábla.';
    }

    return $errors;
}

function format_money(float|int|string $amount): string
{
    return number_format((float) $amount, 0, ',', ' ') . ' Ft';
}

function quote_price_sections(): array
{
    return [
        'MVM-nek fizetendő' => [
            'title' => 'MVM-nek fizetendő tételek',
            'total_label' => 'MVM-nek fizetendő összes költség (bruttó)',
        ],
        'Ügykezelési díjak' => [
            'title' => 'Ügykezelési díjak',
            'total_label' => 'Ügykezelési díjak összesen (bruttó)',
        ],
        'Regisztrált villanyszerelői tételek' => [
            'title' => 'Regisztrált villanyszerelői tételek',
            'total_label' => 'Regisztrált villanyszerelői tételek összesen (bruttó)',
        ],
        'Villanyszerelői szakmunkás tételek' => [
            'title' => 'Villanyszerelői szakmunkás tételek',
            'total_label' => 'Villanyszerelői szakmunkás tételek összesen (bruttó)',
        ],
    ];
}

function quote_quantity_options(): array
{
    return range(0, 100);
}

function quote_quantity_value(mixed $value): int
{
    $string = trim((string) $value);

    if (!preg_match('/^(100|[0-9]{1,2})$/', $string)) {
        return 0;
    }

    return (int) $string;
}

function quote_section_order(string $category): int
{
    $index = array_search($category, array_keys(quote_price_sections()), true);

    return $index === false ? count(quote_price_sections()) : (int) $index;
}

function quote_normalize_category(string $category): string
{
    $sections = quote_price_sections();

    if (array_key_exists($category, $sections)) {
        return $category;
    }

    $legacyMap = [
        'MVM/Demasz sajat dijak' => 'MVM-nek fizetendő',
        'MVM/Démász saját díjak' => 'MVM-nek fizetendő',
        'MVM Partneri tevékenységek' => 'Regisztrált villanyszerelői tételek',
        'MVM/Demasz partneri munkadijak' => 'Regisztrált villanyszerelői tételek',
        'MVM/Démász partneri munkadíjak' => 'Regisztrált villanyszerelői tételek',
        'Villanyszerelői munkák' => 'Villanyszerelői szakmunkás tételek',
        'Mert elmeno kiepites' => 'Villanyszerelői szakmunkás tételek',
        'Mért elmenő kiépítés' => 'Villanyszerelői szakmunkás tételek',
    ];

    return $legacyMap[$category] ?? array_key_first($sections);
}

function quote_effective_category(string $category, string $name = ''): string
{
    if (quote_fee_request_item_kind_by_name($name) !== null) {
        return 'Ügykezelési díjak';
    }

    return quote_normalize_category($category);
}

function quote_lines_with_effective_categories(array $lines): array
{
    foreach ($lines as &$line) {
        $line['category'] = quote_effective_category((string) ($line['category'] ?? ''), (string) ($line['name'] ?? ''));
    }
    unset($line);

    return $lines;
}

function quote_category_totals(array $lines): array
{
    $totals = array_fill_keys(array_keys(quote_price_sections()), 0.0);

    foreach ($lines as $line) {
        $category = quote_effective_category((string) ($line['category'] ?? ''), (string) ($line['name'] ?? ''));
        $totals[$category] = ($totals[$category] ?? 0.0) + (float) ($line['line_gross'] ?? 0);
    }

    return $totals;
}

function quote_electrician_due_amount(array $lines): float
{
    $totals = quote_category_totals($lines);

    return round(
        (float) ($totals['Regisztrált villanyszerelői tételek'] ?? 0)
        + (float) ($totals['Villanyszerelői szakmunkás tételek'] ?? 0),
        2
    );
}

function quote_electrician_due_breakdown(array $lines): array
{
    $totals = quote_category_totals($lines);

    return [
        'registered' => (float) ($totals['Regisztrált villanyszerelői tételek'] ?? 0),
        'specialist' => (float) ($totals['Villanyszerelői szakmunkás tételek'] ?? 0),
        'total' => quote_electrician_due_amount($lines),
    ];
}

function connection_request_electrician_due_breakdown(int $requestId): array
{
    $quote = accepted_quote_for_connection_request($requestId);

    if ($quote === null) {
        return [
            'quote' => null,
            'registered' => 0.0,
            'specialist' => 0.0,
            'total' => 0.0,
        ];
    }

    return ['quote' => $quote] + quote_electrician_due_breakdown(quote_lines((int) $quote['id']));
}

function connection_request_schedule_schema_errors(): array
{
    try {
        if (!db_table_exists('connection_request_schedule_slots')) {
            db_query(
                "CREATE TABLE IF NOT EXISTS `connection_request_schedule_slots` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `connection_request_id` INT UNSIGNED NOT NULL,
                    `work_date` DATE NOT NULL,
                    `status` ENUM('open', 'booked', 'closed') NOT NULL DEFAULT 'open',
                    `source` ENUM('electrician', 'customer', 'admin', 'system') NOT NULL DEFAULT 'system',
                    `note` TEXT DEFAULT NULL,
                    `created_by_user_id` INT UNSIGNED NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `ux_connection_request_schedule_day` (`connection_request_id`, `work_date`),
                    KEY `idx_connection_request_schedule_request` (`connection_request_id`, `status`, `work_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    } catch (Throwable $exception) {
        return [
            APP_DEBUG
                ? 'A kivitelezési naptár tábla létrehozása nem sikerült: ' . $exception->getMessage()
                : 'A kivitelezési naptár adatbázis-tábla létrehozása szükséges.',
        ];
    }

    return [];
}

function connection_request_schedule_is_ready(): bool
{
    return connection_request_schedule_schema_errors() === [];
}

function connection_request_schedule_token(array $request): string
{
    $secret = defined('DB_PASS') ? (string) DB_PASS : (defined('DB_NAME') ? (string) DB_NAME : APP_NAME);
    $payload = (int) ($request['id'] ?? 0) . '|' . (string) ($request['email'] ?? '') . '|' . (string) ($request['created_at'] ?? '') . '|' . $secret;

    return substr(hash('sha256', $payload), 0, 32);
}

function connection_request_schedule_url(array $request): string
{
    return absolute_url('/schedule?request_id=' . (int) $request['id'] . '&token=' . rawurlencode(connection_request_schedule_token($request)));
}

function connection_request_schedule_valid_date(string $date): bool
{
    $date = trim($date);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    try {
        $day = new DateTimeImmutable($date);
    } catch (Throwable) {
        return false;
    }

    return $day->format('Y-m-d') === $date && (int) $day->format('N') <= 5;
}

function connection_request_schedule_slots(int $requestId): array
{
    if (!connection_request_schedule_is_ready()) {
        return [];
    }

    return db_query(
        'SELECT * FROM `connection_request_schedule_slots`
         WHERE `connection_request_id` = ?
         ORDER BY `work_date` ASC',
        [$requestId]
    )->fetchAll();
}

function connection_request_booked_schedule_slot(int $requestId): ?array
{
    if (!connection_request_schedule_is_ready()) {
        return null;
    }

    $slot = db_query(
        'SELECT * FROM `connection_request_schedule_slots`
         WHERE `connection_request_id` = ? AND `status` = ?
         ORDER BY `work_date` ASC
         LIMIT 1',
        [$requestId, 'booked']
    )->fetch();

    return is_array($slot) ? $slot : null;
}

function connection_request_schedule_slot_actor_label(array $slot, array $request): string
{
    if ((string) ($slot['status'] ?? '') !== 'booked') {
        return '';
    }

    $source = (string) ($slot['source'] ?? '');

    if ($source === 'customer') {
        $customerName = trim((string) ($request['requester_name'] ?? ''));
        $customerEmail = trim((string) ($request['email'] ?? ''));
        $customerPhone = trim((string) ($request['phone'] ?? ''));
        $parts = array_values(array_filter([$customerName, $customerPhone, $customerEmail], static fn (string $part): bool => $part !== ''));

        return 'Ügyfél foglalta: ' . ($parts !== [] ? implode(' · ', $parts) : 'az ügyfél');
    }

    if ($source === 'electrician') {
        $createdByUserId = (int) ($slot['created_by_user_id'] ?? 0);
        $currentUser = current_user();

        if (is_array($currentUser) && $createdByUserId > 0 && (int) ($currentUser['id'] ?? 0) === $createdByUserId) {
            return 'Szerelő tette be: te';
        }

        $electrician = $createdByUserId > 0 ? find_electrician_by_user($createdByUserId) : null;
        $user = $createdByUserId > 0 ? find_user_by_id($createdByUserId) : null;
        $actorName = trim((string) ($electrician['name'] ?? $user['name'] ?? $user['email'] ?? ''));

        return 'Szerelő tette be' . ($actorName !== '' ? ': ' . $actorName : '.');
    }

    if ($source === 'admin') {
        return 'Admin tette be.';
    }

    return 'Rendszer által foglalt időpont.';
}

function connection_request_schedule_upsert_slot(int $requestId, string $date, string $status, string $source, ?int $userId = null, string $note = ''): array
{
    $schemaErrors = connection_request_schedule_schema_errors();

    if ($schemaErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $schemaErrors)];
    }

    if (!connection_request_schedule_valid_date($date)) {
        return ['ok' => false, 'message' => 'Csak hétköznapi kivitelezési nap választható.'];
    }

    if (!in_array($status, ['open', 'booked', 'closed'], true)) {
        return ['ok' => false, 'message' => 'Érvénytelen naptárstátusz.'];
    }

    if (!in_array($source, ['electrician', 'customer', 'admin', 'system'], true)) {
        $source = 'system';
    }

    if ($status === 'booked') {
        db_query(
            'UPDATE `connection_request_schedule_slots`
             SET `status` = ?, `source` = ?, `updated_at` = CURRENT_TIMESTAMP
             WHERE `connection_request_id` = ? AND `status` = ?',
            ['open', 'system', $requestId, 'booked']
        );
    }

    db_query(
        'INSERT INTO `connection_request_schedule_slots`
            (`connection_request_id`, `work_date`, `status`, `source`, `note`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            `status` = VALUES(`status`),
            `source` = VALUES(`source`),
            `note` = VALUES(`note`),
            `created_by_user_id` = COALESCE(VALUES(`created_by_user_id`), `created_by_user_id`)',
        [$requestId, $date, $status, $source, trim($note) !== '' ? trim($note) : null, $userId]
    );
    $statusLabel = match ($status) {
        'booked' => 'lefoglalva',
        'closed' => 'lezárva',
        default => 'szabadra nyitva',
    };
    record_connection_request_activity(
        $requestId,
        'schedule',
        'Kivitelezési naptár módosítva',
        connection_request_schedule_day_label($date) . ' - ' . $statusLabel . '.'
    );

    return ['ok' => true, 'message' => 'A kivitelezési naptár frissült.'];
}

function connection_request_schedule_day_label(string $date): string
{
    try {
        $day = new DateTimeImmutable($date);
    } catch (Throwable) {
        return $date;
    }

    $labels = [
        1 => 'hétfő',
        2 => 'kedd',
        3 => 'szerda',
        4 => 'csütörtök',
        5 => 'péntek',
    ];

    return $day->format('Y.m.d.') . ' ' . ($labels[(int) $day->format('N')] ?? '');
}

function connection_request_schedule_weekdays(int $days = 30): array
{
    $dates = [];
    $day = new DateTimeImmutable('today');

    while (count($dates) < $days) {
        if ((int) $day->format('N') <= 5) {
            $dates[] = $day->format('Y-m-d');
        }

        $day = $day->modify('+1 day');
    }

    return $dates;
}

function dependency_status(): array
{
    return [
        'dompdf' => class_exists('\\Dompdf\\Dompdf'),
        'phpmailer' => class_exists('\\PHPMailer\\PHPMailer\\PHPMailer'),
        'phpspreadsheet' => class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet'),
        'zip' => class_exists('ZipArchive'),
    ];
}

function ensure_storage_dir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

function normalize_quote_price_item(array $item): array
{
    $name = (string) ($item['name'] ?? '');
    $feeType = quote_fee_request_item_kind_by_name($name);

    if ($feeType !== null) {
        $option = service_fee_request_option($feeType);
        $item['category'] = 'Ügykezelési díjak';

        if ($option !== null) {
            $item['name'] = (string) $option['name'];
            $item['unit_price'] = (float) $option['gross'];
        }
    }

    return $item;
}

function document_allowed_extensions(): array
{
    return [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
}

function validate_portal_file_upload(array $file, string $label, bool $imageOnly = false): array
{
    $errors = [];

    if (!uploaded_file_is_present($file)) {
        $errors[] = $label . ' feltöltése kötelező.';
        return $errors;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = $label . ': a feltöltés sikertelen.';
        return $errors;
    }

    if (($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
        $errors[] = $label . ': túl nagy fájl. Maximum 8 MB engedélyezett.';
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = document_allowed_extensions();

    if (!isset($allowed[$extension])) {
        $errors[] = $label . ': nem engedélyezett fájltípus. Használható: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, WEBP.';
        return $errors;
    }

    if ($imageOnly && !in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $errors[] = $label . ': ehhez csak kép tölthető fel.';
        return $errors;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $mimeType = $tmpName !== '' && function_exists('mime_content_type') ? (mime_content_type($tmpName) ?: '') : '';
    $officeExtensions = ['doc', 'docx', 'xls', 'xlsx'];
    $mimeTolerated = $mimeType === ''
        || $mimeType === $allowed[$extension]
        || in_array($mimeType, ['application/octet-stream', 'application/zip'], true)
        || (in_array($extension, $officeExtensions, true) && str_contains($mimeType, 'officedocument'));

    if (!$mimeTolerated) {
        $errors[] = $label . ': a fájl típusa nem egyezik a kiterjesztéssel.';
    }

    return $errors;
}

function normalize_download_document_data(array $source): array
{
    return [
        'title' => trim((string) ($source['title'] ?? '')),
        'category' => trim((string) ($source['category'] ?? 'MVM dokumentum')),
        'description' => trim((string) ($source['description'] ?? '')),
        'sort_order' => (int) ($source['sort_order'] ?? 0),
        'is_active' => array_key_exists('is_active', $source) ? 1 : 0,
    ];
}

function validate_download_document_data(array $data, ?array $file): array
{
    $errors = [];

    if ($data['title'] === '') {
        $errors[] = 'A dokumentum neve kötelező.';
    }

    if (!uploaded_file_is_present($file)) {
        $errors[] = 'A dokumentumfájl feltöltése kötelező.';
        return $errors;
    }

    if (($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
        $errors[] = 'Túl nagy fájl. Maximum 8 MB engedélyezett.';
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

    if (!isset(document_allowed_extensions()[$extension])) {
        $errors[] = 'Nem engedélyezett fájltípus. Használható: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, WEBP.';
    }

    return $errors;
}

function create_download_document(array $data, array $file): int
{
    ensure_storage_dir(DOWNLOAD_DOCUMENT_PATH);

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $extension = $extension === 'jpeg' ? 'jpg' : $extension;
    $storedName = 'document-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $targetPath = DOWNLOAD_DOCUMENT_PATH . '/' . $storedName;

    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Nem sikerült menteni a feltöltött dokumentumot.');
    }

    $mimeType = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: '') : '';
    $user = current_user();

    db_query(
        'INSERT INTO `download_documents`
            (`title`, `category`, `description`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`, `is_active`, `sort_order`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $data['title'],
            $data['category'] !== '' ? $data['category'] : 'MVM dokumentum',
            $data['description'] !== '' ? $data['description'] : null,
            (string) $file['name'],
            $storedName,
            $targetPath,
            $mimeType !== '' ? $mimeType : (document_allowed_extensions()[$extension] ?? 'application/octet-stream'),
            (int) $file['size'],
            (int) $data['is_active'],
            (int) $data['sort_order'],
            is_array($user) ? (int) $user['id'] : null,
        ]
    );

    return (int) db()->lastInsertId();
}

function default_download_document_definitions(): array
{
    return [
        [
            'title' => 'Nyilatkozat adatlap',
            'category' => 'MVM nyomtatvány',
            'description' => 'MVM Démász nyilatkozatokhoz használható adatlap. Kitöltve az ügyfélportálon vagy az adott munkához tartozó dokumentumfeltöltésnél lehet visszatölteni.',
            'original_name' => 'MVM_Demasz_Nyilatkozatok_adatlap_N31400-05.pdf',
            'stored_name' => 'mvm-demasz-nyilatkozatok-adatlap-n31400-05.pdf',
            'asset_path' => PUBLIC_ROOT . '/assets/documents/mvm-demasz-nyilatkozatok-adatlap-n31400-05.pdf',
            'sort_order' => 20,
        ],
    ];
}

function ensure_default_download_documents(): void
{
    if (!db_table_exists('download_documents')) {
        return;
    }

    foreach (default_download_document_definitions() as $definition) {
        upsert_download_document_from_local_file($definition);
    }
}

function upsert_download_document_from_local_file(array $definition): void
{
    $sourcePath = (string) ($definition['asset_path'] ?? '');

    if ($sourcePath === '' || !is_file($sourcePath)) {
        return;
    }

    ensure_storage_dir(DOWNLOAD_DOCUMENT_PATH);

    $storedName = basename((string) ($definition['stored_name'] ?? basename($sourcePath)));
    $targetPath = DOWNLOAD_DOCUMENT_PATH . '/' . $storedName;
    $sourceSize = (int) filesize($sourcePath);

    if (!is_file($targetPath) || (int) filesize($targetPath) !== $sourceSize) {
        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Nem sikerült elérhetővé tenni az alap dokumentumot: ' . (string) ($definition['title'] ?? $storedName));
        }
    }

    $mimeType = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: '') : '';
    $title = trim((string) ($definition['title'] ?? pathinfo($sourcePath, PATHINFO_FILENAME)));
    $category = trim((string) ($definition['category'] ?? 'MVM dokumentum'));
    $description = trim((string) ($definition['description'] ?? ''));
    $originalName = trim((string) ($definition['original_name'] ?? basename($sourcePath)));

    $existing = db_query(
        'SELECT `id` FROM `download_documents` WHERE `title` = ? LIMIT 1',
        [$title]
    )->fetch();

    if (is_array($existing)) {
        db_query(
            'UPDATE `download_documents`
             SET `category` = ?,
                 `description` = ?,
                 `original_name` = ?,
                 `stored_name` = ?,
                 `storage_path` = ?,
                 `mime_type` = ?,
                 `file_size` = ?,
                 `is_active` = ?,
                 `sort_order` = ?
             WHERE `id` = ?',
            [
                $category !== '' ? $category : 'MVM dokumentum',
                $description !== '' ? $description : null,
                $originalName !== '' ? $originalName : basename($sourcePath),
                $storedName,
                $targetPath,
                $mimeType !== '' ? $mimeType : 'application/pdf',
                $sourceSize,
                1,
                (int) ($definition['sort_order'] ?? 0),
                (int) $existing['id'],
            ]
        );
        return;
    }

    db_query(
        'INSERT INTO `download_documents`
            (`title`, `category`, `description`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`, `is_active`, `sort_order`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $title,
            $category !== '' ? $category : 'MVM dokumentum',
            $description !== '' ? $description : null,
            $originalName !== '' ? $originalName : basename($sourcePath),
            $storedName,
            $targetPath,
            $mimeType !== '' ? $mimeType : 'application/pdf',
            $sourceSize,
            1,
            (int) ($definition['sort_order'] ?? 0),
            null,
        ]
    );
}

function download_documents(bool $onlyActive = true): array
{
    if (!db_table_exists('download_documents')) {
        return [];
    }

    if ($onlyActive) {
        return db_query(
            'SELECT * FROM `download_documents`
             WHERE `is_active` = ?
             ORDER BY `sort_order` ASC, `category` ASC, `title` ASC, `id` DESC',
            [1]
        )->fetchAll();
    }

    return db_query(
        'SELECT * FROM `download_documents`
         ORDER BY `is_active` DESC, `sort_order` ASC, `category` ASC, `title` ASC, `id` DESC'
    )->fetchAll();
}

function find_download_document(int $id, bool $onlyActive = true): ?array
{
    if (!db_table_exists('download_documents')) {
        return null;
    }

    $sql = 'SELECT * FROM `download_documents` WHERE `id` = ?';
    $params = [$id];

    if ($onlyActive) {
        $sql .= ' AND `is_active` = ?';
        $params[] = 1;
    }

    $sql .= ' LIMIT 1';
    $statement = db_query($sql, $params);
    $document = $statement->fetch();

    return is_array($document) ? $document : null;
}

function delete_download_document(int $id): array
{
    $document = find_download_document($id, false);

    if ($document === null) {
        return ['ok' => false, 'message' => 'A törlendő dokumentum nem található.'];
    }

    db_query('DELETE FROM `download_documents` WHERE `id` = ?', [$id]);
    delete_storage_files([(string) ($document['storage_path'] ?? '')]);

    return ['ok' => true, 'message' => 'A dokumentum törölve.'];
}

function normalize_download_document_email_data(array $source): array
{
    return [
        'document_id' => (int) ($source['document_id'] ?? 0),
        'recipient_name' => trim((string) ($source['recipient_name'] ?? '')),
        'recipient_email' => trim((string) ($source['recipient_email'] ?? '')),
        'message' => trim((string) ($source['message'] ?? '')),
    ];
}

function validate_download_document_email_data(array $data): array
{
    $errors = [];

    if ((int) $data['document_id'] <= 0 || find_download_document((int) $data['document_id'], true) === null) {
        $errors[] = 'Válassz egy elküldhető dokumentumot.';
    }

    if (!filter_var((string) $data['recipient_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Érvényes ügyfél email címet adj meg.';
    }

    return $errors;
}

function send_download_document_to_customer(array $data): array
{
    $data = normalize_download_document_email_data($data);
    $errors = validate_download_document_email_data($data);

    if ($errors !== []) {
        return ['ok' => false, 'message' => implode(' ', $errors)];
    }

    $document = find_download_document((int) $data['document_id'], true);

    if ($document === null || !is_file((string) ($document['storage_path'] ?? ''))) {
        return ['ok' => false, 'message' => 'A kiválasztott dokumentum fájlja nem található.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $recipientEmail = (string) $data['recipient_email'];
    $recipientName = (string) $data['recipient_name'];
    $downloadUrl = absolute_url('/documents/file?id=' . (int) $document['id']);
    $registrationUrl = absolute_url('/register');
    $subject = APP_NAME . ' - ' . (string) $document['title'];
    $lead = 'Csatoltan küldjük a kért dokumentumot. A Mező Energy Kft. a mérőhelyi ügyintézésben, az MVM/Démász dokumentáció előkészítésében, az árajánlatok kezelésében és a kivitelezési folyamatok koordinálásában segít, hogy az ügyintézés átláthatóan és egy helyen követhető legyen.';
    $sections = [
        [
            'title' => 'Küldött dokumentum',
            'rows' => [
                ['label' => 'Dokumentum neve', 'value' => (string) $document['title']],
                ['label' => 'Kategória', 'value' => (string) $document['category']],
                ['label' => 'Letöltési link', 'value' => $downloadUrl],
            ],
        ],
        [
            'title' => 'Regisztráció előnyei',
            'items' => [
                'Saját ügyfélprofilból beküldhető a munkaigény és visszatölthetők a kitöltött dokumentumok.',
                'A folyamat állapota, az üzenetek és a feltöltött fájlok egy adatlapon maradnak.',
                'Az adminisztráció és a szerelői egyeztetés gyorsabban követhető.',
            ],
        ],
    ];

    if ((string) $data['message'] !== '') {
        $sections[] = [
            'title' => 'Kiegészítő üzenet',
            'lead' => (string) $data['message'],
        ];
    }

    $actions = [
        ['label' => 'Regisztráció indítása', 'url' => $registrationUrl],
        ['label' => 'Dokumentum letöltése', 'url' => $downloadUrl],
        ['label' => 'Dokumentumtár megnyitása', 'url' => absolute_url('/documents')],
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->Subject = $subject;
        apply_branded_email($mail, 'Kért dokumentum és regisztrációs lehetőség', $lead, $sections, $actions, $recipientName);
        $mail->addAttachment((string) $document['storage_path'], (string) $document['original_name']);
        $mail->send();

        if (db_table_exists('email_logs')) {
            db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, $recipientEmail, $subject, 'sent']);
        }

        return ['ok' => true, 'message' => 'A dokumentum emailben elküldve az ügyfélnek.'];
    } catch (Throwable $exception) {
        if (db_table_exists('email_logs')) {
            db_query(
                'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
                [null, $recipientEmail, $subject, 'failed', $exception->getMessage()]
            );
        }

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A dokumentum email küldése sikertelen.'];
    }
}

function split_full_name(string $name): array
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];

    if (count($parts) <= 1) {
        return ['', $name];
    }

    $lastName = array_shift($parts);

    return [(string) $lastName, implode(' ', $parts)];
}

function normalize_customer_data(array $source): array
{
    return [
        'is_legal_entity' => array_key_exists('is_legal_entity', $source) ? (int) $source['is_legal_entity'] : 0,
        'requester_name' => trim((string) ($source['requester_name'] ?? '')),
        'birth_name' => trim((string) ($source['birth_name'] ?? '')),
        'company_name' => trim((string) ($source['company_name'] ?? '')),
        'tax_number' => trim((string) ($source['tax_number'] ?? '')),
        'phone' => trim((string) ($source['phone'] ?? '')),
        'email' => trim((string) ($source['email'] ?? '')),
        'postal_address' => trim((string) ($source['postal_address'] ?? '')),
        'postal_code' => trim((string) ($source['postal_code'] ?? '')),
        'city' => trim((string) ($source['city'] ?? '')),
        'mailing_address' => trim((string) ($source['mailing_address'] ?? '')),
        'mother_name' => trim((string) ($source['mother_name'] ?? '')),
        'birth_place' => trim((string) ($source['birth_place'] ?? '')),
        'birth_date' => trim((string) ($source['birth_date'] ?? '')),
        'contact_data_accepted' => array_key_exists('contact_data_accepted', $source) ? (int) $source['contact_data_accepted'] : 0,
        'source' => trim((string) ($source['source'] ?? '')),
        'status' => trim((string) ($source['status'] ?? 'Új érdeklődő')),
        'notes' => trim((string) ($source['notes'] ?? '')),
    ];
}

function validate_customer_data(array $data, bool $requireConsent = false): array
{
    $errors = [];

    if ($data['requester_name'] === '') {
        $errors[] = 'A név megadása kötelező.';
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Érvényes email cím megadása kötelező.';
    }

    foreach (['phone' => 'Telefonszám', 'postal_address' => 'Postai cím', 'postal_code' => 'Irányítószám', 'city' => 'Település'] as $key => $label) {
        if ($data[$key] === '') {
            $errors[] = $label . ' megadása kötelező.';
        }
    }

    if ($requireConsent) {
        foreach ([
            'birth_name' => 'Születési név',
            'mother_name' => 'Anyja neve',
            'birth_place' => 'Születési hely',
            'birth_date' => 'Születési idő',
        ] as $key => $label) {
            if ($data[$key] === '') {
                $errors[] = $label . ' megadása kötelező.';
            }
        }
    }

    if ($requireConsent && (int) $data['contact_data_accepted'] !== 1) {
        $errors[] = 'Az adatkezelési hozzájárulás elfogadása kötelező.';
    }

    return $errors;
}

function create_customer(array $data, ?int $userId = null, ?int $createdByUserId = null): int
{
    db_query(
        'INSERT INTO `customers`
            (`user_id`, `created_by_user_id`, `is_legal_entity`, `requester_name`, `birth_name`, `company_name`, `tax_number`, `phone`, `email`,
             `postal_address`, `postal_code`, `city`, `mailing_address`, `mother_name`, `birth_place`,
             `birth_date`, `contact_data_accepted`, `source`, `status`, `notes`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $userId,
            $createdByUserId,
            $data['is_legal_entity'],
            $data['requester_name'],
            $data['birth_name'] !== '' ? $data['birth_name'] : null,
            $data['company_name'] !== '' ? $data['company_name'] : null,
            $data['tax_number'] !== '' ? $data['tax_number'] : null,
            $data['phone'],
            $data['email'],
            $data['postal_address'],
            $data['postal_code'],
            $data['city'],
            $data['mailing_address'] !== '' ? $data['mailing_address'] : null,
            $data['mother_name'] !== '' ? $data['mother_name'] : null,
            $data['birth_place'] !== '' ? $data['birth_place'] : null,
            $data['birth_date'] !== '' ? $data['birth_date'] : null,
            $data['contact_data_accepted'],
            $data['source'] !== '' ? $data['source'] : null,
            $data['status'] !== '' ? $data['status'] : 'Új érdeklődő',
            $data['notes'] !== '' ? $data['notes'] : null,
        ]
    );

    return (int) db()->lastInsertId();
}

function update_customer(int $id, array $data): void
{
    db_query(
        'UPDATE `customers`
         SET `is_legal_entity` = ?, `requester_name` = ?, `birth_name` = ?, `company_name` = ?, `tax_number` = ?, `phone` = ?,
             `email` = ?, `postal_address` = ?, `postal_code` = ?, `city` = ?, `mailing_address` = ?,
             `mother_name` = ?, `birth_place` = ?, `birth_date` = ?, `contact_data_accepted` = ?,
             `source` = ?, `status` = ?, `notes` = ?
         WHERE `id` = ?',
        [
            $data['is_legal_entity'],
            $data['requester_name'],
            $data['birth_name'] !== '' ? $data['birth_name'] : null,
            $data['company_name'] !== '' ? $data['company_name'] : null,
            $data['tax_number'] !== '' ? $data['tax_number'] : null,
            $data['phone'],
            $data['email'],
            $data['postal_address'],
            $data['postal_code'],
            $data['city'],
            $data['mailing_address'] !== '' ? $data['mailing_address'] : null,
            $data['mother_name'] !== '' ? $data['mother_name'] : null,
            $data['birth_place'] !== '' ? $data['birth_place'] : null,
            $data['birth_date'] !== '' ? $data['birth_date'] : null,
            $data['contact_data_accepted'],
            $data['source'] !== '' ? $data['source'] : null,
            $data['status'] !== '' ? $data['status'] : 'Új érdeklődő',
            $data['notes'] !== '' ? $data['notes'] : null,
            $id,
        ]
    );
}

function find_customer(int $id): ?array
{
    $statement = db_query('SELECT * FROM `customers` WHERE `id` = ? LIMIT 1', [$id]);
    $customer = $statement->fetch();

    return is_array($customer) ? $customer : null;
}

function find_customer_by_user(int $userId): ?array
{
    $statement = db_query('SELECT * FROM `customers` WHERE `user_id` = ? LIMIT 1', [$userId]);
    $customer = $statement->fetch();

    return is_array($customer) ? $customer : null;
}

function find_claimable_customer_by_email(string $email): ?array
{
    $email = trim($email);

    if ($email === '') {
        return null;
    }

    $statement = db_query(
        'SELECT c.*
         FROM `customers` c
         WHERE LOWER(c.`email`) = LOWER(?)
           AND (c.`user_id` IS NULL OR c.`user_id` = 0)
         ORDER BY CASE WHEN EXISTS (
                SELECT 1
                FROM `quotes` q
                WHERE q.`customer_id` = c.`id`
                  AND q.`status` = ?
             ) THEN 1 ELSE 0 END DESC,
             c.`created_at` DESC,
             c.`id` DESC
         LIMIT 1',
        [$email, 'accepted']
    );
    $customer = $statement->fetch();

    return is_array($customer) ? $customer : null;
}

function current_customer(): ?array
{
    $user = current_user();

    if (!is_array($user)) {
        return null;
    }

    if (!empty($user['customer_id'])) {
        return find_customer((int) $user['customer_id']);
    }

    return find_customer_by_user((int) $user['id']);
}

function normalize_contractor_data(array $source): array
{
    return [
        'contractor_name' => trim((string) ($source['contractor_name'] ?? '')),
        'company_name' => trim((string) ($source['company_name'] ?? '')),
        'tax_number' => trim((string) ($source['tax_number'] ?? '')),
        'contact_name' => trim((string) ($source['contact_name'] ?? '')),
        'phone' => trim((string) ($source['phone'] ?? '')),
        'email' => trim((string) ($source['email'] ?? '')),
        'postal_address' => trim((string) ($source['postal_address'] ?? '')),
        'postal_code' => trim((string) ($source['postal_code'] ?? '')),
        'city' => trim((string) ($source['city'] ?? '')),
        'notes' => trim((string) ($source['notes'] ?? '')),
    ];
}

function validate_contractor_data(array $data): array
{
    $errors = [];

    foreach ([
        'contractor_name' => 'Generálkivitelező neve',
        'contact_name' => 'Kapcsolattartó neve',
        'phone' => 'Telefonszám',
        'postal_address' => 'Postai cím',
        'postal_code' => 'Irányítószám',
        'city' => 'Település',
    ] as $key => $label) {
        if ($data[$key] === '') {
            $errors[] = $label . ' megadása kötelező.';
        }
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Érvényes email cím megadása kötelező.';
    }

    return $errors;
}

function find_contractor_by_user(int $userId): ?array
{
    $statement = db_query('SELECT * FROM `contractors` WHERE `user_id` = ? LIMIT 1', [$userId]);
    $contractor = $statement->fetch();

    return is_array($contractor) ? $contractor : null;
}

function current_contractor(): ?array
{
    $user = current_user();

    if (!is_array($user)) {
        return null;
    }

    return find_contractor_by_user((int) $user['id']);
}

function normalize_electrician_data(array $source): array
{
    return [
        'name' => trim((string) ($source['name'] ?? '')),
        'phone' => trim((string) ($source['phone'] ?? '')),
        'email' => trim((string) ($source['email'] ?? '')),
        'notes' => trim((string) ($source['notes'] ?? '')),
        'is_active' => array_key_exists('is_active', $source) ? 1 : 0,
    ];
}

function validate_electrician_data(array $data, bool $passwordRequired = true, string $password = ''): array
{
    $errors = [];

    if ($data['name'] === '') {
        $errors[] = 'A szerelő neve kötelező.';
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Érvényes email cím megadása kötelező.';
    }

    if ($passwordRequired && strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'A jelszó legalább ' . PASSWORD_MIN_LENGTH . ' karakter legyen.';
    }

    return $errors;
}

function find_electrician_by_user(int $userId): ?array
{
    if (!db_table_exists('electricians')) {
        return null;
    }

    $statement = db_query('SELECT * FROM `electricians` WHERE `user_id` = ? LIMIT 1', [$userId]);
    $electrician = $statement->fetch();

    return is_array($electrician) ? $electrician : null;
}

function current_electrician(): ?array
{
    $user = current_user();

    if (!is_array($user)) {
        return null;
    }

    return find_electrician_by_user((int) $user['id']);
}

function create_electrician_account(array $electricianData, string $password): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        db_query(
            'INSERT INTO `users` (`name`, `email`, `password_hash`, `is_admin`, `role`, `customer_id`)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $electricianData['name'],
                $electricianData['email'],
                password_hash($password, PASSWORD_DEFAULT),
                0,
                'electrician',
                null,
            ]
        );

        $userId = (int) $pdo->lastInsertId();

        db_query(
            'INSERT INTO `electricians` (`user_id`, `name`, `phone`, `email`, `is_active`, `notes`)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $electricianData['name'],
                $electricianData['phone'] !== '' ? $electricianData['phone'] : null,
                $electricianData['email'],
                (int) $electricianData['is_active'],
                $electricianData['notes'] !== '' ? $electricianData['notes'] : null,
            ]
        );

        $pdo->commit();

        return $userId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function electrician_users(bool $onlyActive = true): array
{
    if (!db_table_exists('electricians')) {
        return [];
    }

    $sql = 'SELECT e.*, u.id AS user_id, u.name AS user_name, u.email AS user_email
            FROM `electricians` e
            INNER JOIN `users` u ON u.id = e.user_id';
    $params = [];

    if ($onlyActive) {
        $sql .= ' WHERE e.is_active = ?';
        $params[] = 1;
    }

    $sql .= ' ORDER BY e.is_active DESC, e.name ASC, e.id DESC';

    return db_query($sql, $params)->fetchAll();
}

function create_contractor_account(array $contractorData, string $password): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        db_query(
            'INSERT INTO `users` (`name`, `email`, `password_hash`, `is_admin`, `role`, `customer_id`)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $contractorData['contact_name'],
                $contractorData['email'],
                password_hash($password, PASSWORD_DEFAULT),
                0,
                'general_contractor',
                null,
            ]
        );

        $userId = (int) $pdo->lastInsertId();

        db_query(
            'INSERT INTO `contractors`
                (`user_id`, `contractor_name`, `company_name`, `tax_number`, `contact_name`, `phone`, `email`,
                 `postal_address`, `postal_code`, `city`, `notes`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $contractorData['contractor_name'],
                $contractorData['company_name'] !== '' ? $contractorData['company_name'] : null,
                $contractorData['tax_number'] !== '' ? $contractorData['tax_number'] : null,
                $contractorData['contact_name'],
                $contractorData['phone'],
                $contractorData['email'],
                $contractorData['postal_address'],
                $contractorData['postal_code'],
                $contractorData['city'],
                $contractorData['notes'] !== '' ? $contractorData['notes'] : null,
            ]
        );

        $pdo->commit();

        return $userId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function contractor_customers(int $contractorUserId): array
{
    return db_query(
        'SELECT * FROM `customers`
         WHERE `created_by_user_id` = ?
         ORDER BY `created_at` DESC, `id` DESC',
        [$contractorUserId]
    )->fetchAll();
}

function all_customers(): array
{
    if (
        db_table_exists('electricians')
        && db_table_exists('contractors')
        && db_column_exists('connection_requests', 'assigned_electrician_user_id')
    ) {
        $statement = db_query(
            'SELECT c.*, u.role AS owner_role, u.name AS owner_user_name, u.email AS owner_user_email,
                    au.id AS account_user_id, au.role AS account_role, au.name AS account_user_name, au.email AS account_user_email,
                    e.name AS owner_electrician_name, ct.contractor_name AS owner_contractor_name,
                    ae.name AS assigned_electrician_name
             FROM `customers` c
             LEFT JOIN `users` u ON u.id = c.created_by_user_id
             LEFT JOIN `users` au ON au.id = c.user_id
             LEFT JOIN `electricians` e ON e.user_id = c.created_by_user_id
             LEFT JOIN `contractors` ct ON ct.user_id = c.created_by_user_id
             LEFT JOIN (
                SELECT cr.customer_id, cr.assigned_electrician_user_id
                FROM `connection_requests` cr
                INNER JOIN (
                    SELECT customer_id, MAX(id) AS request_id
                    FROM `connection_requests`
                    WHERE assigned_electrician_user_id IS NOT NULL
                    GROUP BY customer_id
                ) latest ON latest.request_id = cr.id
             ) assigned_request ON assigned_request.customer_id = c.id
             LEFT JOIN `electricians` ae ON ae.user_id = assigned_request.assigned_electrician_user_id
             ORDER BY c.created_at DESC, c.id DESC'
        );

        return $statement->fetchAll();
    }

    $statement = db_query('SELECT * FROM `customers` ORDER BY `created_at` DESC, `id` DESC');

    return $statement->fetchAll();
}

function db_fetch_int_column(string $sql, array $params = []): array
{
    return array_values(array_map(
        static fn (mixed $value): int => (int) $value,
        db_query($sql, $params)->fetchAll(PDO::FETCH_COLUMN)
    ));
}

function db_in_placeholders(array $values): string
{
    return implode(', ', array_fill(0, count($values), '?'));
}

function collect_string_column(string $sql, array $params = []): array
{
    return array_values(array_filter(array_map(
        static fn (mixed $value): string => trim((string) $value),
        db_query($sql, $params)->fetchAll(PDO::FETCH_COLUMN)
    )));
}

function delete_storage_files(array $paths): int
{
    $storageRoot = realpath(STORAGE_PATH);

    if ($storageRoot === false) {
        return 0;
    }

    $storageRoot = rtrim(str_replace('\\', '/', $storageRoot), '/');
    $deleted = 0;

    foreach (array_unique($paths) as $path) {
        $path = trim((string) $path);

        if ($path === '') {
            continue;
        }

        $realPath = realpath($path);

        if ($realPath === false || !is_file($realPath)) {
            continue;
        }

        $normalizedPath = str_replace('\\', '/', $realPath);

        if (!str_starts_with($normalizedPath, $storageRoot . '/')) {
            continue;
        }

        if (@unlink($realPath)) {
            $deleted++;
        }
    }

    return $deleted;
}

function delete_customer_with_related_data(int $customerId): array
{
    $customer = find_customer($customerId);

    if ($customer === null) {
        throw new RuntimeException('Az ügyfél nem található.');
    }

    $requestIds = db_fetch_int_column('SELECT `id` FROM `connection_requests` WHERE `customer_id` = ?', [$customerId]);
    $quoteIds = db_fetch_int_column('SELECT `id` FROM `quotes` WHERE `customer_id` = ?', [$customerId]);

    if ($requestIds !== [] && db_column_exists('quotes', 'connection_request_id')) {
        $requestQuoteIds = db_fetch_int_column(
            'SELECT `id` FROM `quotes` WHERE `connection_request_id` IN (' . db_in_placeholders($requestIds) . ')',
            $requestIds
        );
        $quoteIds = array_values(array_unique(array_merge($quoteIds, $requestQuoteIds)));
    }

    $userIds = [];

    if (users_table_exists()) {
        $userParams = [$customerId];
        $userSql = 'SELECT `id` FROM `users` WHERE `role` = ? AND (`customer_id` = ?';
        array_unshift($userParams, 'customer');

        if (!empty($customer['user_id'])) {
            $userSql .= ' OR `id` = ?';
            $userParams[] = (int) $customer['user_id'];
        }

        $userSql .= ')';
        $userIds = db_fetch_int_column($userSql, $userParams);
    }

    $filePaths = [];

    if ($quoteIds !== []) {
        $quotePlaceholders = db_in_placeholders($quoteIds);
        $filePaths = array_merge(
            $filePaths,
            collect_string_column('SELECT `pdf_path` FROM `quotes` WHERE `id` IN (' . $quotePlaceholders . ') AND `pdf_path` IS NOT NULL', $quoteIds),
            collect_string_column('SELECT `storage_path` FROM `quote_photos` WHERE `quote_id` IN (' . $quotePlaceholders . ')', $quoteIds)
        );
    }

    if ($requestIds !== []) {
        $requestPlaceholders = db_in_placeholders($requestIds);
        $filePaths = array_merge(
            $filePaths,
            collect_string_column('SELECT `storage_path` FROM `connection_request_files` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds)
        );

        if (db_table_exists('connection_request_work_files')) {
            $filePaths = array_merge(
                $filePaths,
                collect_string_column('SELECT `storage_path` FROM `connection_request_work_files` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds)
            );
        }

        if (db_table_exists('connection_request_documents')) {
            $filePaths = array_merge(
                $filePaths,
                collect_string_column('SELECT `storage_path` FROM `connection_request_documents` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds)
            );
        }

        if (db_table_exists('connection_request_mvm_forms')) {
            $filePaths = array_merge(
                $filePaths,
                collect_string_column('SELECT `sketch_storage_path` FROM `connection_request_mvm_forms` WHERE `connection_request_id` IN (' . $requestPlaceholders . ') AND `sketch_storage_path` IS NOT NULL', $requestIds)
            );
        }
    }

    if (db_table_exists('connection_request_documents')) {
        $filePaths = array_merge(
            $filePaths,
            collect_string_column('SELECT `storage_path` FROM `connection_request_documents` WHERE `customer_id` = ?', [$customerId])
        );
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        if ($quoteIds !== []) {
            $quotePlaceholders = db_in_placeholders($quoteIds);
            db_query('DELETE FROM `email_logs` WHERE `quote_id` IN (' . $quotePlaceholders . ')', $quoteIds);
            db_query('DELETE FROM `quote_lines` WHERE `quote_id` IN (' . $quotePlaceholders . ')', $quoteIds);
            db_query('DELETE FROM `quote_photos` WHERE `quote_id` IN (' . $quotePlaceholders . ')', $quoteIds);
        }

        if ($requestIds !== []) {
            $requestPlaceholders = db_in_placeholders($requestIds);

            if (db_table_exists('mvm_email_messages')) {
                db_query('DELETE FROM `mvm_email_messages` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds);
            }

            if (db_table_exists('mvm_email_threads')) {
                db_query('DELETE FROM `mvm_email_threads` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds);
            }

            if (db_table_exists('connection_request_activity_logs')) {
                db_query('DELETE FROM `connection_request_activity_logs` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds);
            }

            if (db_table_exists('connection_request_mvm_forms')) {
                db_query('DELETE FROM `connection_request_mvm_forms` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds);
            }

            if (db_table_exists('connection_request_documents')) {
                db_query('DELETE FROM `connection_request_documents` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds);
            }

            if (db_table_exists('connection_request_work_files')) {
                db_query('DELETE FROM `connection_request_work_files` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds);
            }

            db_query('DELETE FROM `connection_request_files` WHERE `connection_request_id` IN (' . $requestPlaceholders . ')', $requestIds);
            db_query('DELETE FROM `connection_requests` WHERE `id` IN (' . $requestPlaceholders . ')', $requestIds);
        }

        if (db_table_exists('connection_request_documents')) {
            db_query('DELETE FROM `connection_request_documents` WHERE `customer_id` = ?', [$customerId]);
        }

        if ($quoteIds !== []) {
            db_query('DELETE FROM `quotes` WHERE `id` IN (' . db_in_placeholders($quoteIds) . ')', $quoteIds);
        }

        db_query('DELETE FROM `surveys` WHERE `customer_id` = ?', [$customerId]);

        if (db_table_exists('customer_addresses')) {
            db_query('DELETE FROM `customer_addresses` WHERE `customer_id` = ?', [$customerId]);
        }

        if ($userIds !== []) {
            if (password_reset_table_exists()) {
                db_query('DELETE FROM `password_reset_tokens` WHERE `user_id` IN (' . db_in_placeholders($userIds) . ')', $userIds);
            }

            db_query('DELETE FROM `users` WHERE `id` IN (' . db_in_placeholders($userIds) . ') AND `role` = ?', array_merge($userIds, ['customer']));
        }

        db_query('DELETE FROM `customers` WHERE `id` = ?', [$customerId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    return [
        'customer_name' => (string) ($customer['requester_name'] ?? ''),
        'requests' => count($requestIds),
        'quotes' => count($quoteIds),
        'users' => count($userIds),
        'files' => delete_storage_files($filePaths),
    ];
}

function delete_connection_request_with_related_data(int $requestId): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        throw new RuntimeException('Az adatlap nem található.');
    }

    $quoteIds = db_table_exists('quotes') && db_column_exists('quotes', 'connection_request_id')
        ? db_fetch_int_column('SELECT `id` FROM `quotes` WHERE `connection_request_id` = ?', [$requestId])
        : [];
    $surveyIds = $quoteIds !== []
        ? db_fetch_int_column('SELECT `survey_id` FROM `quotes` WHERE `id` IN (' . db_in_placeholders($quoteIds) . ') AND `survey_id` IS NOT NULL', $quoteIds)
        : [];
    $filePaths = [];

    if ($quoteIds !== []) {
        $quotePlaceholders = db_in_placeholders($quoteIds);
        $quoteRows = db_query('SELECT * FROM `quotes` WHERE `id` IN (' . $quotePlaceholders . ')', $quoteIds)->fetchAll();
        $filePaths = array_merge(
            $filePaths,
            collect_string_column('SELECT `pdf_path` FROM `quotes` WHERE `id` IN (' . $quotePlaceholders . ') AND `pdf_path` IS NOT NULL', $quoteIds)
        );

        if (db_table_exists('quote_photos')) {
            $filePaths = array_merge(
                $filePaths,
                collect_string_column('SELECT `storage_path` FROM `quote_photos` WHERE `quote_id` IN (' . $quotePlaceholders . ')', $quoteIds)
            );
        }

        foreach ($quoteRows as $quoteRow) {
            $filePaths[] = quote_fee_request_pdf_path($quoteRow);
        }
    }

    $filePaths = array_merge(
        $filePaths,
        collect_string_column('SELECT `storage_path` FROM `connection_request_files` WHERE `connection_request_id` = ?', [$requestId])
    );

    if (db_table_exists('connection_request_work_files')) {
        $filePaths = array_merge(
            $filePaths,
            collect_string_column('SELECT `storage_path` FROM `connection_request_work_files` WHERE `connection_request_id` = ?', [$requestId])
        );
    }

    if (db_table_exists('connection_request_documents')) {
        $filePaths = array_merge(
            $filePaths,
            collect_string_column('SELECT `storage_path` FROM `connection_request_documents` WHERE `connection_request_id` = ?', [$requestId])
        );
    }

    if (db_table_exists('connection_request_mvm_forms')) {
        $filePaths = array_merge(
            $filePaths,
            collect_string_column('SELECT `sketch_storage_path` FROM `connection_request_mvm_forms` WHERE `connection_request_id` = ? AND `sketch_storage_path` IS NOT NULL', [$requestId])
        );
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        if ($quoteIds !== []) {
            $quotePlaceholders = db_in_placeholders($quoteIds);

            if (db_table_exists('email_logs')) {
                db_query('DELETE FROM `email_logs` WHERE `quote_id` IN (' . $quotePlaceholders . ')', $quoteIds);
            }

            db_query('DELETE FROM `quote_lines` WHERE `quote_id` IN (' . $quotePlaceholders . ')', $quoteIds);

            if (db_table_exists('quote_photos')) {
                db_query('DELETE FROM `quote_photos` WHERE `quote_id` IN (' . $quotePlaceholders . ')', $quoteIds);
            }

            db_query('DELETE FROM `quotes` WHERE `id` IN (' . $quotePlaceholders . ')', $quoteIds);
        }

        if ($surveyIds !== []) {
            db_query('DELETE FROM `surveys` WHERE `id` IN (' . db_in_placeholders($surveyIds) . ')', $surveyIds);
        }

        if (db_table_exists('mvm_email_messages')) {
            db_query('DELETE FROM `mvm_email_messages` WHERE `connection_request_id` = ?', [$requestId]);
        }

        if (db_table_exists('mvm_email_threads')) {
            db_query('DELETE FROM `mvm_email_threads` WHERE `connection_request_id` = ?', [$requestId]);
        }

        if (db_table_exists('connection_request_schedule_slots')) {
            db_query('DELETE FROM `connection_request_schedule_slots` WHERE `connection_request_id` = ?', [$requestId]);
        }

        if (db_table_exists('connection_request_activity_logs')) {
            db_query('DELETE FROM `connection_request_activity_logs` WHERE `connection_request_id` = ?', [$requestId]);
        }

        if (db_table_exists('connection_request_mvm_forms')) {
            db_query('DELETE FROM `connection_request_mvm_forms` WHERE `connection_request_id` = ?', [$requestId]);
        }

        if (db_table_exists('connection_request_documents')) {
            db_query('DELETE FROM `connection_request_documents` WHERE `connection_request_id` = ?', [$requestId]);
        }

        if (db_table_exists('connection_request_work_files')) {
            db_query('DELETE FROM `connection_request_work_files` WHERE `connection_request_id` = ?', [$requestId]);
        }

        if (db_table_exists('minicrm_connection_request_links')) {
            db_query('DELETE FROM `minicrm_connection_request_links` WHERE `connection_request_id` = ?', [$requestId]);
        }

        db_query('DELETE FROM `connection_request_files` WHERE `connection_request_id` = ?', [$requestId]);
        db_query('DELETE FROM `connection_requests` WHERE `id` = ?', [$requestId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    return [
        'request_title' => trim((string) ($request['project_name'] ?? '')) ?: ('Adatlap #' . $requestId),
        'request_id' => $requestId,
        'quotes' => count($quoteIds),
        'surveys' => count($surveyIds),
        'files' => delete_storage_files($filePaths),
    ];
}

function work_archive_schema_errors(): array
{
    $errors = [];
    $tables = [
        'minicrm_work_items' => 'MiniCRM munkák',
        'connection_requests' => 'portálos adatlapok',
    ];

    foreach ($tables as $table => $label) {
        if (!db_table_exists($table)) {
            continue;
        }

        $columns = [
            'archived_at' => 'ALTER TABLE `' . $table . '` ADD COLUMN `archived_at` DATETIME DEFAULT NULL AFTER `updated_at`',
            'archived_by_user_id' => 'ALTER TABLE `' . $table . '` ADD COLUMN `archived_by_user_id` INT UNSIGNED NULL AFTER `archived_at`',
        ];

        try {
            foreach ($columns as $column => $sql) {
                if (!db_column_exists($table, $column)) {
                    db_query($sql);
                }
            }
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG
                ? 'Az archiválási mezők létrehozása sikertelen (' . $label . '): ' . $exception->getMessage()
                : 'Az archiválási adatbázis frissítés szükséges: ' . $label . '.';

            continue;
        }

        try {
            $indexName = $table === 'minicrm_work_items'
                ? 'idx_minicrm_work_items_archived'
                : 'idx_connection_requests_archived';
            $indexExists = (bool) db_query(
                'SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [DB_NAME, $table, $indexName]
            )->fetchColumn();

            if (!$indexExists && db_column_exists($table, 'archived_at')) {
                db_query('ALTER TABLE `' . $table . '` ADD KEY `' . $indexName . '` (`archived_at`)');
            }
        } catch (Throwable) {
            // The columns are enough for correct behavior; the index only keeps large lists fast.
        }
    }

    return $errors;
}

function connection_request_archive_columns_ready(): bool
{
    return db_table_exists('connection_requests')
        && db_column_exists('connection_requests', 'archived_at')
        && db_column_exists('connection_requests', 'archived_by_user_id');
}

function set_connection_request_archived(int $requestId, bool $archive): array
{
    if (!connection_request_archive_columns_ready()) {
        return ['ok' => false, 'message' => 'Hiányoznak az adatlap archiválási mezői. Futtasd az adatbázis frissítést.'];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az adatlap nem található.'];
    }

    $user = current_user();
    $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

    if ($archive) {
        db_query(
            'UPDATE `connection_requests` SET `archived_at` = NOW(), `archived_by_user_id` = ? WHERE `id` = ?',
            [$userId > 0 ? $userId : null, $requestId]
        );
    } else {
        db_query(
            'UPDATE `connection_requests` SET `archived_at` = NULL, `archived_by_user_id` = NULL WHERE `id` = ?',
            [$requestId]
        );
    }

    record_connection_request_activity(
        $requestId,
        'workflow',
        $archive ? 'Adatlap archiválva' : 'Adatlap visszaállítva az archívumból',
        $archive
            ? 'Az adatlap lekerült az aktív munkalistáról.'
            : 'Az adatlap újra megjelenik az aktív munkalistában.'
    );

    return [
        'ok' => true,
        'message' => $archive ? 'Az adatlap archiválva lett.' : 'Az adatlap visszaállítva az archívumból.',
    ];
}

function actor_role_display_label(?string $role): string
{
    $role = trim((string) $role);
    $labels = user_role_labels();
    $labels['guest'] = 'Nyilvános link / nincs belépve';
    $labels['specialist'] = 'Adminisztrátor';

    if ($role === '') {
        return 'Ismeretlen szerepkör';
    }

    return $labels[$role] ?? $role;
}

function actor_display_label_from_parts(?string $role, ?string $name = null, ?string $email = null, ?int $userId = null): string
{
    $roleLabel = actor_role_display_label($role);
    $name = trim((string) $name);
    $email = trim((string) $email);
    $details = [];

    if ($name !== '') {
        $details[] = $name;
    }

    if ($email !== '' && strcasecmp($email, $name) !== 0) {
        $details[] = $email;
    }

    if ($details === [] && $userId !== null && $userId > 0) {
        $details[] = '#' . $userId;
    }

    return $details !== [] ? $roleLabel . ': ' . implode(' - ', $details) : $roleLabel;
}

function actor_label_for_user_id(?int $userId, string $fallback = 'Ismeretlen felhasználó'): string
{
    if ($userId === null || $userId <= 0) {
        return $fallback;
    }

    $user = find_user_by_id($userId);

    if ($user === null) {
        return $fallback . ' (#' . $userId . ')';
    }

    $role = (string) ($user['role'] ?? '');
    $name = trim((string) ($user['name'] ?? ''));

    if ($role === 'electrician') {
        $electrician = find_electrician_by_user($userId);
        $name = trim((string) ($electrician['name'] ?? $name));
    } elseif ($role === 'general_contractor') {
        $contractor = find_contractor_by_user($userId);
        $contractorName = trim((string) ($contractor['contractor_name'] ?? ''));
        $contactName = trim((string) ($contractor['contact_name'] ?? $name));
        $name = $contractorName !== '' && $contactName !== '' && $contractorName !== $contactName
            ? $contractorName . ' - ' . $contactName
            : ($contractorName !== '' ? $contractorName : $contactName);
    }

    return actor_display_label_from_parts($role, $name, (string) ($user['email'] ?? ''), $userId);
}

function current_actor_snapshot(?string $sourceLabel = null): array
{
    $user = current_user();
    $role = current_user_role();
    $name = '';
    $email = '';
    $userId = null;

    if (is_array($user)) {
        $userId = (int) ($user['id'] ?? 0);
        $name = trim((string) ($user['name'] ?? ''));
        $email = trim((string) ($user['email'] ?? ''));

        if ($role === 'electrician') {
            $electrician = find_electrician_by_user($userId);
            $name = trim((string) ($electrician['name'] ?? $name));
        } elseif ($role === 'general_contractor') {
            $contractor = find_contractor_by_user($userId);
            $contractorName = trim((string) ($contractor['contractor_name'] ?? ''));
            $contactName = trim((string) ($contractor['contact_name'] ?? $name));
            $name = $contractorName !== '' && $contactName !== '' && $contractorName !== $contactName
                ? $contractorName . ' - ' . $contactName
                : ($contractorName !== '' ? $contractorName : $contactName);
        }
    } elseif ($sourceLabel !== null && trim($sourceLabel) !== '') {
        $name = trim($sourceLabel);
    }

    return [
        'user_id' => $userId,
        'role' => $role,
        'role_label' => actor_role_display_label($role),
        'name' => $name,
        'email' => $email,
        'source_label' => trim((string) $sourceLabel),
    ];
}

function actor_snapshot_label(array $actor): string
{
    return actor_display_label_from_parts(
        (string) ($actor['role'] ?? ''),
        (string) ($actor['name'] ?? ''),
        (string) ($actor['email'] ?? ''),
        isset($actor['user_id']) ? (int) $actor['user_id'] : null
    );
}

function connection_request_activity_schema_errors(): array
{
    try {
        if (!db_table_exists('connection_request_activity_logs')) {
            db_query(
                "CREATE TABLE IF NOT EXISTS `connection_request_activity_logs` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `connection_request_id` INT UNSIGNED NOT NULL,
                    `event_type` VARCHAR(80) NOT NULL,
                    `title` VARCHAR(190) NOT NULL,
                    `body` TEXT DEFAULT NULL,
                    `actor_user_id` INT UNSIGNED NULL,
                    `actor_label` VARCHAR(255) DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_connection_request_activity_request` (`connection_request_id`, `created_at`),
                    KEY `idx_connection_request_activity_type` (`event_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    } catch (Throwable $exception) {
        return [
            APP_DEBUG
                ? 'Az adatlap előzmény tábla létrehozása nem sikerült: ' . $exception->getMessage()
                : 'Az adatlap előzmény tábla létrehozása szükséges.',
        ];
    }

    return [];
}

function connection_request_activity_is_ready(): bool
{
    return connection_request_activity_schema_errors() === [];
}

function record_connection_request_activity(int $requestId, string $eventType, string $title, string $body = '', ?int $actorUserId = null, ?string $actorLabel = null): void
{
    if ($requestId <= 0 || !connection_request_activity_is_ready()) {
        return;
    }

    $actor = current_actor_snapshot();
    $resolvedActorUserId = $actorUserId ?? (isset($actor['user_id']) ? (int) $actor['user_id'] : null);
    $resolvedActorLabel = trim((string) ($actorLabel ?? '')) ?: actor_snapshot_label($actor);

    try {
        db_query(
            'INSERT INTO `connection_request_activity_logs`
                (`connection_request_id`, `event_type`, `title`, `body`, `actor_user_id`, `actor_label`)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $requestId,
                trim($eventType) !== '' ? trim($eventType) : 'note',
                trim($title) !== '' ? trim($title) : 'Adatlap esemény',
                trim($body) !== '' ? trim($body) : null,
                $resolvedActorUserId !== null && $resolvedActorUserId > 0 ? $resolvedActorUserId : null,
                $resolvedActorLabel !== '' ? $resolvedActorLabel : null,
            ]
        );
    } catch (Throwable) {
        // Az üzleti művelet ne bukjon el attól, ha az előzmény napló átmenetileg nem írható.
    }
}

function connection_request_activity_logs(int $requestId): array
{
    if ($requestId <= 0 || !connection_request_activity_is_ready()) {
        return [];
    }

    return db_query(
        'SELECT * FROM `connection_request_activity_logs`
         WHERE `connection_request_id` = ?
         ORDER BY `created_at` DESC, `id` DESC',
        [$requestId]
    )->fetchAll();
}

function development_suggestion_schema_errors(): array
{
    try {
        if (!db_table_exists('development_suggestions')) {
            db_query(
                "CREATE TABLE IF NOT EXISTS `development_suggestions` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED NULL,
                    `user_role` VARCHAR(40) NOT NULL,
                    `user_name` VARCHAR(160) DEFAULT NULL,
                    `user_email` VARCHAR(190) DEFAULT NULL,
                    `title` VARCHAR(190) NOT NULL,
                    `body` TEXT NOT NULL,
                    `status` ENUM('new', 'reviewing', 'accepted', 'rejected', 'done') NOT NULL DEFAULT 'new',
                    `admin_note` TEXT DEFAULT NULL,
                    `reviewed_by_user_id` INT UNSIGNED NULL,
                    `reviewed_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_development_suggestions_user` (`user_id`, `created_at`),
                    KEY `idx_development_suggestions_status` (`status`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    } catch (Throwable $exception) {
        return [
            APP_DEBUG
                ? 'A fejlesztési javaslatok táblájának létrehozása nem sikerült: ' . $exception->getMessage()
                : 'A fejlesztési javaslatok adatbázis-táblájának létrehozása szükséges.',
        ];
    }

    return [];
}

function development_suggestions_are_ready(): bool
{
    return development_suggestion_schema_errors() === [];
}

function development_suggestion_status_labels(): array
{
    return [
        'new' => 'Új',
        'reviewing' => 'Átnézés alatt',
        'accepted' => 'Elfogadva',
        'rejected' => 'Nem kerül megvalósításra',
        'done' => 'Elkészült',
    ];
}

function development_suggestion_status_label(string $status): string
{
    $labels = development_suggestion_status_labels();

    return $labels[$status] ?? $status;
}

function validate_development_suggestion(array $data): array
{
    $errors = [];

    if (trim((string) ($data['title'] ?? '')) === '') {
        $errors[] = 'A rövid cím megadása kötelező.';
    }

    if (trim((string) ($data['body'] ?? '')) === '') {
        $errors[] = 'Írd le röviden a javaslatot vagy hibát.';
    }

    if (function_exists('mb_strlen')) {
        if (mb_strlen(trim((string) ($data['title'] ?? '')), 'UTF-8') > 190) {
            $errors[] = 'A cím legfeljebb 190 karakter lehet.';
        }

        if (mb_strlen(trim((string) ($data['body'] ?? '')), 'UTF-8') > 5000) {
            $errors[] = 'A leírás legfeljebb 5000 karakter lehet.';
        }
    } else {
        if (strlen(trim((string) ($data['title'] ?? ''))) > 190) {
            $errors[] = 'A cím legfeljebb 190 karakter lehet.';
        }

        if (strlen(trim((string) ($data['body'] ?? ''))) > 5000) {
            $errors[] = 'A leírás legfeljebb 5000 karakter lehet.';
        }
    }

    return $errors;
}

function create_development_suggestion(array $data): array
{
    $schemaErrors = development_suggestion_schema_errors();

    if ($schemaErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $schemaErrors)];
    }

    if (!can_submit_development_suggestion()) {
        return ['ok' => false, 'message' => 'Fejlesztési javaslatot csak belső felhasználó küldhet be.'];
    }

    $errors = validate_development_suggestion($data);

    if ($errors !== []) {
        return ['ok' => false, 'message' => implode(' ', $errors)];
    }

    $user = current_user();
    $userId = is_array($user) ? (int) $user['id'] : null;
    $role = current_user_role();
    db_query(
        'INSERT INTO `development_suggestions`
            (`user_id`, `user_role`, `user_name`, `user_email`, `title`, `body`)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $userId !== null && $userId > 0 ? $userId : null,
            $role,
            is_array($user) ? (string) ($user['name'] ?? '') : null,
            is_array($user) ? (string) ($user['email'] ?? '') : null,
            trim((string) ($data['title'] ?? '')),
            trim((string) ($data['body'] ?? '')),
        ]
    );

    return ['ok' => true, 'message' => 'Köszönjük, a fejlesztési javaslat rögzítve lett.'];
}

function development_suggestions_for_user(int $userId, int $limit = 30): array
{
    if ($userId <= 0 || !development_suggestions_are_ready()) {
        return [];
    }

    return db_query(
        'SELECT ds.*, ru.name AS reviewed_by_name, ru.email AS reviewed_by_email, ru.role AS reviewed_by_role, ru.is_admin AS reviewed_by_is_admin
         FROM `development_suggestions` ds
         LEFT JOIN `users` ru ON ru.id = ds.reviewed_by_user_id
         WHERE ds.`user_id` = ?
         ORDER BY ds.`created_at` DESC, ds.`id` DESC
         LIMIT ' . max(1, min(200, $limit)),
        [$userId]
    )->fetchAll();
}

function all_development_suggestions(int $limit = 200): array
{
    if (!development_suggestions_are_ready()) {
        return [];
    }

    return db_query(
        'SELECT ds.*, ru.name AS reviewed_by_name, ru.email AS reviewed_by_email, ru.role AS reviewed_by_role, ru.is_admin AS reviewed_by_is_admin
         FROM `development_suggestions` ds
         LEFT JOIN `users` ru ON ru.id = ds.reviewed_by_user_id
         ORDER BY FIELD(ds.`status`, \'new\', \'reviewing\', \'accepted\', \'done\', \'rejected\'), ds.`created_at` DESC, ds.`id` DESC
         LIMIT ' . max(1, min(500, $limit))
    )->fetchAll();
}

function development_suggestion_counts(): array
{
    $counts = array_fill_keys(array_keys(development_suggestion_status_labels()), 0);

    if (!development_suggestions_are_ready()) {
        return $counts;
    }

    foreach (db_query('SELECT `status`, COUNT(*) AS item_count FROM `development_suggestions` GROUP BY `status`')->fetchAll() as $row) {
        $status = (string) ($row['status'] ?? '');

        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int) $row['item_count'];
        }
    }

    return $counts;
}

function update_development_suggestion_status(int $suggestionId, string $status, string $adminNote = ''): array
{
    $schemaErrors = development_suggestion_schema_errors();

    if ($schemaErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $schemaErrors)];
    }

    if (!can_manage_development_suggestions()) {
        return ['ok' => false, 'message' => 'A javaslatok státuszát csak főadmin módosíthatja.'];
    }

    if ($suggestionId <= 0) {
        return ['ok' => false, 'message' => 'Hiányzó fejlesztési javaslat azonosító.'];
    }

    if (!array_key_exists($status, development_suggestion_status_labels())) {
        return ['ok' => false, 'message' => 'Érvénytelen javaslat státusz.'];
    }

    $user = current_user();
    db_query(
        'UPDATE `development_suggestions`
         SET `status` = ?, `admin_note` = ?, `reviewed_by_user_id` = ?, `reviewed_at` = NOW()
         WHERE `id` = ?',
        [
            $status,
            trim($adminNote) !== '' ? trim($adminNote) : null,
            is_array($user) ? (int) $user['id'] : null,
            $suggestionId,
        ]
    );

    return ['ok' => true, 'message' => 'A fejlesztési javaslat státusza frissítve.'];
}

function development_suggestion_actor_label(array $suggestion): string
{
    return actor_display_label_from_parts(
        (string) ($suggestion['user_role'] ?? ''),
        (string) ($suggestion['user_name'] ?? ''),
        (string) ($suggestion['user_email'] ?? ''),
        isset($suggestion['user_id']) ? (int) $suggestion['user_id'] : null
    );
}

function customer_owner_label(array $customer): string
{
    $role = (string) ($customer['owner_role'] ?? '');

    if ($role === 'electrician') {
        return actor_display_label_from_parts('electrician', (string) ($customer['owner_electrician_name'] ?: $customer['owner_user_name'] ?: ''), (string) ($customer['owner_user_email'] ?? ''));
    }

    if ($role === 'general_contractor') {
        return actor_display_label_from_parts('general_contractor', (string) ($customer['owner_contractor_name'] ?: $customer['owner_user_name'] ?: ''), (string) ($customer['owner_user_email'] ?? ''));
    }

    if ($role === 'admin' || $role === 'specialist' || $role === 'customer') {
        return actor_display_label_from_parts($role, (string) ($customer['owner_user_name'] ?? ''), (string) ($customer['owner_user_email'] ?? ''));
    }

    $accountRole = (string) ($customer['account_role'] ?? '');

    if ($accountRole !== '') {
        return actor_display_label_from_parts($accountRole, (string) ($customer['account_user_name'] ?? ''), (string) ($customer['account_user_email'] ?? ''));
    }

    if (!empty($customer['created_by_user_id'])) {
        return actor_label_for_user_id((int) $customer['created_by_user_id'], 'Ismeretlen létrehozó');
    }

    if (!empty($customer['user_id'])) {
        return actor_label_for_user_id((int) $customer['user_id'], 'Ismeretlen regisztrált fiók');
    }

    if (!empty($customer['assigned_electrician_name'])) {
        return 'Kiadott szerelő: ' . (string) $customer['assigned_electrician_name'];
    }

    return 'Nincs rögzített létrehozó';
}

function create_customer_account(array $customerData, string $password, ?int $claimCustomerId = null): int
{
    $claimCustomer = $claimCustomerId !== null ? find_customer($claimCustomerId) : null;
    $claimEmailMatches = $claimCustomer !== null
        && empty($claimCustomer['user_id'])
        && strcasecmp(trim((string) ($claimCustomer['email'] ?? '')), trim((string) $customerData['email'])) === 0;

    if (!$claimEmailMatches) {
        $claimCustomer = find_claimable_customer_by_email((string) $customerData['email']);
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        if ($claimCustomer !== null) {
            $customerId = (int) $claimCustomer['id'];
            update_customer($customerId, $customerData);
        } else {
            $customerId = create_customer($customerData, null);
        }

        db_query(
            'INSERT INTO `users` (`name`, `email`, `password_hash`, `is_admin`, `role`, `customer_id`)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $customerData['requester_name'],
                $customerData['email'],
                password_hash($password, PASSWORD_DEFAULT),
                0,
                'customer',
                $customerId,
            ]
        );

        $userId = (int) db()->lastInsertId();
        db_query(
            'UPDATE `customers`
             SET `user_id` = ?, `created_by_user_id` = COALESCE(`created_by_user_id`, ?)
             WHERE `id` = ?',
            [$userId, $userId, $customerId]
        );

        $pdo->commit();

        return $userId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function active_price_items(): array
{
    $statement = db_query(
        'SELECT * FROM `quote_price_items`
         WHERE `is_active` = ?
         ORDER BY `sort_order` ASC, `id` ASC',
        [1]
    );

    return array_map('normalize_quote_price_item', $statement->fetchAll());
}

function all_price_items(): array
{
    $statement = db_query(
        'SELECT * FROM `quote_price_items`
         ORDER BY `sort_order` ASC, `category` ASC, `id` ASC'
    );

    return array_map('normalize_quote_price_item', $statement->fetchAll());
}

function save_price_item(array $data, ?int $id = null): void
{
    $params = [
        trim((string) $data['category']),
        trim((string) $data['name']),
        trim((string) ($data['unit'] ?: 'db')),
        (float) $data['unit_price'],
        (float) $data['vat_rate'],
        isset($data['is_active']) ? 1 : 0,
        (int) ($data['sort_order'] ?? 0),
    ];

    if ($id !== null) {
        $params[] = $id;
        db_query(
            'UPDATE `quote_price_items`
             SET `category` = ?, `name` = ?, `unit` = ?, `unit_price` = ?, `vat_rate` = ?, `is_active` = ?, `sort_order` = ?
             WHERE `id` = ?',
            $params
        );
        return;
    }

    db_query(
        'INSERT INTO `quote_price_items` (`category`, `name`, `unit`, `unit_price`, `vat_rate`, `is_active`, `sort_order`)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        $params
    );
}

function find_price_item(int $id): ?array
{
    $statement = db_query('SELECT * FROM `quote_price_items` WHERE `id` = ? LIMIT 1', [$id]);
    $item = $statement->fetch();

    return is_array($item) ? $item : null;
}

function normalize_survey_data(array $source): array
{
    $keys = [
        'site_address', 'hrsz', 'work_type', 'meter_serial', 'meter_location', 'current_phase',
        'current_ampere', 'requested_phase', 'requested_ampere', 'network_notes', 'cabinet_notes',
        'survey_notes',
    ];
    $data = [];

    foreach ($keys as $key) {
        $data[$key] = trim((string) ($source[$key] ?? ''));
    }

    $data['has_controlled_meter'] = array_key_exists('has_controlled_meter', $source) ? (int) $source['has_controlled_meter'] : 0;
    $data['has_solar'] = array_key_exists('has_solar', $source) ? (int) $source['has_solar'] : 0;
    $data['has_h_tariff'] = array_key_exists('has_h_tariff', $source) ? (int) $source['has_h_tariff'] : 0;

    return mvm_recalculate_power_financials($data);
}

function connection_request_quote_survey_seed(array $request): array
{
    return [
        'site_address' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? '')),
        'hrsz' => $request['hrsz'] ?? '',
        'work_type' => connection_request_type_label($request['request_type'] ?? null),
        'meter_serial' => $request['meter_serial'] ?? '',
        'current_ampere' => $request['existing_general_power'] ?? '',
        'requested_ampere' => $request['requested_general_power'] ?? '',
        'survey_notes' => $request['notes'] ?? '',
        'has_h_tariff' => ((string) ($request['request_type'] ?? '') === 'h_tariff') ? 1 : 0,
    ];
}

function collect_quote_lines(array $source): array
{
    $lines = [];
    $priceItems = active_price_items();
    $quantities = $source['price_item_quantity'] ?? [];

    foreach ($priceItems as $item) {
        $quantity = isset($quantities[$item['id']]) ? quote_quantity_value($quantities[$item['id']]) : 0;

        if ($quantity <= 0) {
            continue;
        }

        $lines[] = quote_line_from_values(
            (int) $item['id'],
            quote_effective_category((string) $item['category'], (string) $item['name']),
            (string) $item['name'],
            (string) $item['unit'],
            $quantity,
            (float) $item['unit_price'],
            (float) $item['vat_rate']
        );
    }

    $customNames = $source['custom_name'] ?? [];
    $customCategories = $source['custom_category'] ?? [];
    $customUnits = $source['custom_unit'] ?? [];
    $customQuantities = $source['custom_quantity'] ?? [];
    $customPrices = $source['custom_unit_price'] ?? [];
    $customVatRates = $source['custom_vat_rate'] ?? [];

    foreach ($customNames as $index => $name) {
        $name = trim((string) $name);

        if ($name === '') {
            continue;
        }

        $quantity = quote_quantity_value($customQuantities[$index] ?? 0);
        $unitPrice = (float) str_replace(',', '.', (string) ($customPrices[$index] ?? 0));
        $vatRate = (float) str_replace(',', '.', (string) ($customVatRates[$index] ?? 27));

        if ($quantity <= 0) {
            continue;
        }

        $lines[] = quote_line_from_values(
            null,
            quote_normalize_category(trim((string) ($customCategories[$index] ?? ''))),
            $name,
            trim((string) ($customUnits[$index] ?? 'db')) ?: 'db',
            $quantity,
            $unitPrice,
            $vatRate
        );
    }

    return $lines;
}

function quote_line_from_values(?int $priceItemId, string $category, string $name, string $unit, float $quantity, float $unitPrice, float $vatRate): array
{
    $gross = round($quantity * $unitPrice, 2);
    $net = $vatRate > 0 ? round($gross / (1 + ($vatRate / 100)), 2) : $gross;
    $vat = round($gross - $net, 2);

    return [
        'price_item_id' => $priceItemId,
        'category' => $category,
        'name' => $name,
        'unit' => $unit,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'vat_rate' => $vatRate,
        'line_net' => $net,
        'line_vat' => $vat,
        'line_gross' => $gross,
    ];
}

function quote_totals(array $lines): array
{
    $net = 0.0;
    $vat = 0.0;
    $gross = 0.0;

    foreach ($lines as $line) {
        $net += (float) $line['line_net'];
        $vat += (float) $line['line_vat'];
        $gross += (float) $line['line_gross'];
    }

    return ['net' => $net, 'vat' => $vat, 'gross' => $gross];
}

function next_quote_number(): string
{
    return 'ME-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function save_survey(int $customerId, array $data, ?int $surveyId = null): int
{
    $rawPayload = json_encode($data, JSON_UNESCAPED_UNICODE);
    $user = current_user();
    $specialistId = is_array($user) && (is_staff_user() || is_electrician_user()) ? (int) $user['id'] : null;

    if ($surveyId !== null) {
        db_query(
            'UPDATE `surveys`
             SET `site_address` = ?, `hrsz` = ?, `work_type` = ?, `meter_serial` = ?, `meter_location` = ?,
                 `current_phase` = ?, `current_ampere` = ?, `requested_phase` = ?, `requested_ampere` = ?,
                 `has_controlled_meter` = ?, `has_solar` = ?, `has_h_tariff` = ?, `network_notes` = ?,
                 `cabinet_notes` = ?, `survey_notes` = ?, `raw_payload` = ?
             WHERE `id` = ?',
            [
                $data['site_address'] ?: null,
                $data['hrsz'] ?: null,
                $data['work_type'] ?: null,
                $data['meter_serial'] ?: null,
                $data['meter_location'] ?: null,
                $data['current_phase'] ?: null,
                $data['current_ampere'] ?: null,
                $data['requested_phase'] ?: null,
                $data['requested_ampere'] ?: null,
                $data['has_controlled_meter'],
                $data['has_solar'],
                $data['has_h_tariff'],
                $data['network_notes'] ?: null,
                $data['cabinet_notes'] ?: null,
                $data['survey_notes'] ?: null,
                $rawPayload,
                $surveyId,
            ]
        );

        return $surveyId;
    }

    db_query(
        'INSERT INTO `surveys`
            (`customer_id`, `specialist_user_id`, `site_address`, `hrsz`, `work_type`, `meter_serial`, `meter_location`,
             `current_phase`, `current_ampere`, `requested_phase`, `requested_ampere`, `has_controlled_meter`,
             `has_solar`, `has_h_tariff`, `network_notes`, `cabinet_notes`, `survey_notes`, `raw_payload`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $customerId,
            $specialistId,
            $data['site_address'] ?: null,
            $data['hrsz'] ?: null,
            $data['work_type'] ?: null,
            $data['meter_serial'] ?: null,
            $data['meter_location'] ?: null,
            $data['current_phase'] ?: null,
            $data['current_ampere'] ?: null,
            $data['requested_phase'] ?: null,
            $data['requested_ampere'] ?: null,
            $data['has_controlled_meter'],
            $data['has_solar'],
            $data['has_h_tariff'],
            $data['network_notes'] ?: null,
            $data['cabinet_notes'] ?: null,
            $data['survey_notes'] ?: null,
            $rawPayload,
        ]
    );

    return (int) db()->lastInsertId();
}

function save_quote(int $customerId, array $quoteData, array $surveyData, array $lines, ?int $quoteId = null, ?int $connectionRequestId = null): int
{
    if ($lines === []) {
        throw new RuntimeException('Legalább egy ajánlati tétel megadása kötelező.');
    }

    $totals = quote_totals($lines);
    $user = current_user();
    $specialistId = is_array($user) && (is_staff_user() || is_electrician_user()) ? (int) $user['id'] : null;

    if ($quoteId !== null) {
        $quote = find_quote($quoteId);

        if ($quote === null) {
            throw new RuntimeException('Az ajánlat nem található.');
        }

        $surveyId = save_survey($customerId, $surveyData, isset($quote['survey_id']) ? (int) $quote['survey_id'] : null);

        db_query(
            'UPDATE `quotes`
             SET `connection_request_id` = COALESCE(?, `connection_request_id`), `survey_id` = ?, `subject` = ?, `customer_message` = ?, `total_net` = ?, `total_vat` = ?, `total_gross` = ?
             WHERE `id` = ?',
            [
                $connectionRequestId,
                $surveyId,
                trim((string) $quoteData['subject']),
                trim((string) ($quoteData['customer_message'] ?? '')) ?: null,
                $totals['net'],
                $totals['vat'],
                $totals['gross'],
                $quoteId,
            ]
        );
        db_query('DELETE FROM `quote_lines` WHERE `quote_id` = ?', [$quoteId]);
        insert_quote_lines($quoteId, $lines);

        $requestIdForClear = $connectionRequestId ?: (!empty($quote['connection_request_id']) ? (int) $quote['connection_request_id'] : null);

        if ($requestIdForClear !== null) {
            clear_connection_request_quote_missing_reason((int) $requestIdForClear);
        }

        return $quoteId;
    }

    $surveyId = save_survey($customerId, $surveyData, null);
    db_query(
        'INSERT INTO `quotes`
            (`customer_id`, `connection_request_id`, `survey_id`, `specialist_user_id`, `quote_number`, `status`, `subject`,
             `customer_message`, `total_net`, `total_vat`, `total_gross`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $customerId,
            $connectionRequestId,
            $surveyId,
            $specialistId,
            next_quote_number(),
            'draft',
            trim((string) $quoteData['subject']),
            trim((string) ($quoteData['customer_message'] ?? '')) ?: null,
            $totals['net'],
            $totals['vat'],
            $totals['gross'],
        ]
    );
    $newQuoteId = (int) db()->lastInsertId();
    insert_quote_lines($newQuoteId, $lines);

    if ($connectionRequestId !== null) {
        clear_connection_request_quote_missing_reason($connectionRequestId);
    }

    return $newQuoteId;
}

function insert_quote_lines(int $quoteId, array $lines): void
{
    $sort = 0;

    foreach ($lines as $line) {
        $sort += 10;
        db_query(
            'INSERT INTO `quote_lines`
                (`quote_id`, `price_item_id`, `category`, `name`, `unit`, `quantity`, `unit_price`,
                 `vat_rate`, `line_net`, `line_vat`, `line_gross`, `sort_order`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $quoteId,
                $line['price_item_id'],
                $line['category'],
                $line['name'],
                $line['unit'],
                $line['quantity'],
                $line['unit_price'],
                $line['vat_rate'],
                $line['line_net'],
                $line['line_vat'],
                $line['line_gross'],
                $sort,
            ]
        );
    }
}

function quote_engagement_columns(): array
{
    return [
        'email_opened_at' => '`email_opened_at` DATETIME DEFAULT NULL AFTER `sent_at`',
        'email_last_opened_at' => '`email_last_opened_at` DATETIME DEFAULT NULL AFTER `email_opened_at`',
        'email_open_count' => '`email_open_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `email_last_opened_at`',
        'viewed_at' => '`viewed_at` DATETIME DEFAULT NULL AFTER `email_open_count`',
        'last_viewed_at' => '`last_viewed_at` DATETIME DEFAULT NULL AFTER `viewed_at`',
        'view_count' => '`view_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_viewed_at`',
    ];
}

function quote_engagement_schema_ensure(): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    if (!db_table_exists('quotes')) {
        $ready = false;
        return false;
    }

    $missing = [];

    foreach (quote_engagement_columns() as $column => $definition) {
        if (!db_column_exists('quotes', $column)) {
            $missing[] = 'ADD COLUMN IF NOT EXISTS ' . $definition;
        }
    }

    if ($missing !== []) {
        try {
            db_query('ALTER TABLE `quotes` ' . implode(', ', $missing));
        } catch (Throwable) {
            $ready = false;
            return false;
        }
    }

    foreach (array_keys(quote_engagement_columns()) as $column) {
        if (!db_column_exists('quotes', $column)) {
            $ready = false;
            return false;
        }
    }

    $ready = true;
    return true;
}

function find_quote(int $id): ?array
{
    quote_engagement_schema_ensure();

    $statement = db_query(
        'SELECT q.*, c.requester_name, c.email, c.phone, c.postal_address, c.postal_code, c.city, c.company_name
         FROM `quotes` q
         INNER JOIN `customers` c ON c.id = q.customer_id
         WHERE q.id = ?
         LIMIT 1',
        [$id]
    );
    $quote = $statement->fetch();

    return is_array($quote) ? $quote : null;
}

function quote_lines(int $quoteId): array
{
    return db_query(
        'SELECT * FROM `quote_lines` WHERE `quote_id` = ? ORDER BY `sort_order` ASC, `id` ASC',
        [$quoteId]
    )->fetchAll();
}

function quote_survey(?int $surveyId): ?array
{
    if ($surveyId === null || $surveyId <= 0) {
        return null;
    }

    $statement = db_query('SELECT * FROM `surveys` WHERE `id` = ? LIMIT 1', [$surveyId]);
    $survey = $statement->fetch();

    return is_array($survey) ? $survey : null;
}

function all_quotes(): array
{
    return db_query(
        'SELECT q.*, c.requester_name, c.email
         FROM `quotes` q
         INNER JOIN `customers` c ON c.id = q.customer_id
         ORDER BY q.created_at DESC, q.id DESC'
    )->fetchAll();
}

function quotes_for_customer(int $customerId): array
{
    return db_query(
        'SELECT * FROM `quotes` WHERE `customer_id` = ? ORDER BY `created_at` DESC, `id` DESC',
        [$customerId]
    )->fetchAll();
}

function quotes_for_connection_request(int $requestId): array
{
    if (!db_column_exists('quotes', 'connection_request_id')) {
        return [];
    }

    quote_engagement_schema_ensure();

    return db_query(
        'SELECT q.*, c.requester_name, c.email
         FROM `quotes` q
         INNER JOIN `customers` c ON c.id = q.customer_id
         WHERE q.connection_request_id = ?
         ORDER BY q.created_at DESC, q.id DESC',
        [$requestId]
    )->fetchAll();
}

function latest_quote_for_connection_request(int $requestId): ?array
{
    $quotes = quotes_for_connection_request($requestId);

    return $quotes[0] ?? null;
}

function accepted_quote_for_connection_request(int $requestId): ?array
{
    if (!db_column_exists('quotes', 'connection_request_id')) {
        return null;
    }

    $statement = db_query(
        'SELECT q.*, c.requester_name, c.email
         FROM `quotes` q
         INNER JOIN `customers` c ON c.id = q.customer_id
         WHERE q.connection_request_id = ? AND q.status = ?
         ORDER BY q.accepted_at DESC, q.id DESC
         LIMIT 1',
        [$requestId, 'accepted']
    );
    $quote = $statement->fetch();

    return is_array($quote) ? $quote : null;
}

function accepted_quote_for_registration_duplicate_request(int $requestId): ?array
{
    if (!db_column_exists('quotes', 'connection_request_id')) {
        return null;
    }

    $statement = db_query(
        'SELECT q.*, quote_customer.requester_name, quote_customer.email
         FROM `connection_requests` cr
         INNER JOIN `customers` request_customer ON request_customer.id = cr.customer_id
         INNER JOIN `customers` quote_customer ON LOWER(quote_customer.email) = LOWER(request_customer.email)
         INNER JOIN `quotes` q ON q.customer_id = quote_customer.id
         WHERE cr.id = ?
           AND q.status = ?
           AND (quote_customer.user_id IS NULL OR quote_customer.user_id = 0)
           AND q.connection_request_id <> cr.id
           AND NOT EXISTS (
                SELECT 1
                FROM `quotes` direct_quote
                WHERE direct_quote.connection_request_id = cr.id
           )
         ORDER BY q.accepted_at DESC, q.id DESC
         LIMIT 1',
        [$requestId, 'accepted']
    );
    $quote = $statement->fetch();

    return is_array($quote) ? $quote : null;
}

function customer_can_view_quote(array $quote): bool
{
    if (is_staff_user()) {
        return true;
    }

    $customer = current_customer();

    return $customer !== null && (int) $customer['id'] === (int) $quote['customer_id'];
}

function quote_status_labels(): array
{
    return [
        'draft' => 'Előkészítés alatt',
        'sent' => 'Elküldve',
        'accepted' => 'Elfogadva',
        'consultation_requested' => 'Egyeztetés kérve',
        'rejected' => 'Elutasítva',
    ];
}

function quote_status_label(string $status): string
{
    $labels = quote_status_labels();

    return $labels[$status] ?? $status;
}

function quote_state_summary(?array $latestQuote, ?array $acceptedQuote = null, string $missingReason = ''): array
{
    $missingReason = trim($missingReason);

    if ($acceptedQuote !== null) {
        $acceptedAt = trim((string) ($acceptedQuote['accepted_at'] ?? ''));

        return [
            'class' => 'accepted',
            'title' => 'Elfogadott árajánlat',
            'status' => quote_status_label('accepted'),
            'amount' => quote_display_total($acceptedQuote),
            'description' => 'Az ügyfél elfogadta az árajánlatot' . ($acceptedAt !== '' ? ' ekkor: ' . $acceptedAt : '') . '. Ez az összeg irányadó a kivitelezésnél.',
        ];
    }

    if ($latestQuote === null) {
        return [
            'class' => 'missing',
            'title' => 'Nincs árajánlat',
            'status' => 'Nincs árajánlat',
            'amount' => '-',
            'description' => $missingReason !== ''
                ? 'Az árajánlat hiányának oka: ' . $missingReason
                : 'Ehhez az igényhez még nincs elkészített vagy feltöltött árajánlat.',
        ];
    }

    $status = (string) ($latestQuote['status'] ?? 'draft');
    $statusLabel = quote_status_label($status);
    $description = match ($status) {
        'consultation_requested' => 'Az ügyfél egyeztetést kért az árajánlatról. Szerelőnek kiadás előtt érdemes tisztázni a nyitott kérdéseket.',
        'sent' => 'Az árajánlat ki lett küldve az ügyfélnek, de még nincs elfogadva.',
        'draft' => 'Az árajánlat előkészítés alatt áll, még nincs kiküldve az ügyfélnek.',
        'rejected' => 'Az árajánlat elutasított állapotban van.',
        default => 'Az árajánlat állapota: ' . $statusLabel . '.',
    };

    if (!empty($latestQuote['sent_at']) && $status === 'sent') {
        $description .= ' Kiküldve: ' . (string) $latestQuote['sent_at'] . '.';
    }

    if (!empty($latestQuote['consultation_requested_at']) && $status === 'consultation_requested') {
        $description .= ' Egyeztetés kérve: ' . (string) $latestQuote['consultation_requested_at'] . '.';
    }

    return [
        'class' => $status,
        'title' => $statusLabel,
        'status' => $statusLabel,
        'amount' => quote_display_total($latestQuote),
        'description' => $description,
    ];
}

function ensure_quote_public_token(int $quoteId): ?string
{
    if (!db_column_exists('quotes', 'public_token')) {
        return null;
    }

    $quote = find_quote($quoteId);

    if ($quote === null) {
        return null;
    }

    $token = trim((string) ($quote['public_token'] ?? ''));

    if ($token !== '') {
        return $token;
    }

    $token = bin2hex(random_bytes(32));
    db_query('UPDATE `quotes` SET `public_token` = ? WHERE `id` = ?', [$token, $quoteId]);

    return $token;
}

function quote_customer_action_url(array $quote, string $intent = ''): string
{
    $suffix = $intent !== '' ? '&intent=' . rawurlencode($intent) . '#quote-actions' : '';

    if (!empty($quote['public_token'])) {
        return absolute_url('/quote?id=' . (int) $quote['id'] . '&token=' . rawurlencode((string) $quote['public_token']) . $suffix);
    }

    return absolute_url('/customer/quotes/view?id=' . (int) $quote['id'] . $suffix);
}

function quote_registration_path(array $quote): string
{
    if (!empty($quote['id']) && !empty($quote['public_token'])) {
        return '/register?quote_id=' . (int) $quote['id'] . '&token=' . rawurlencode((string) $quote['public_token']);
    }

    return '/register';
}

function notify_admin_quote_response(int $quoteId, string $response, string $message = ''): array
{
    $quote = find_quote($quoteId);

    if ($quote === null) {
        return ['ok' => false, 'message' => 'Az ajánlat nem található.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        log_admin_notification_email($quoteId, APP_NAME . ' árajánlat visszajelzés', 'failed', 'PHPMailer hiányzik.');
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $responseLabels = [
        'accept' => 'Elfogadta az árajánlatot',
        'consultation' => 'Árajánlat egyeztetést kér',
    ];
    $responseLabel = $responseLabels[$response] ?? 'Árajánlat visszajelzés';
    $subject = APP_NAME . ' - ' . $responseLabel . ' - ' . $quote['quote_number'];
    $emailTitle = $responseLabel;
    $emailLead = 'Az ügyfél visszajelzést adott egy árajánlatra. A részletek az admin felületen is láthatók.';
    $emailSections = [
        [
            'title' => 'Visszajelzés',
            'rows' => [
                ['label' => 'Válasz', 'value' => $responseLabel],
                ['label' => 'Ajánlatszám', 'value' => $quote['quote_number'] ?? '-'],
                ['label' => 'Tárgy', 'value' => $quote['subject'] ?? '-'],
                ['label' => 'Összeg', 'value' => quote_display_total($quote)],
                ['label' => 'Ügyfél', 'value' => ($quote['requester_name'] ?? '-') . "\n" . ($quote['email'] ?? '-') . "\n" . ($quote['phone'] ?? '-')],
            ],
        ],
    ];

    if (trim($message) !== '') {
        $emailSections[] = [
            'title' => 'Ügyfél megjegyzése',
            'lead' => trim($message),
        ];
    }

    $quoteRequestId = (int) ($quote['connection_request_id'] ?? 0);
    $quoteCustomerId = (int) ($quote['customer_id'] ?? 0);
    $quoteContextPath = $quoteRequestId > 0
        ? '/admin/minicrm-import?request=' . $quoteRequestId . '#portal-work-' . $quoteRequestId
        : '/admin/customers' . ($quoteCustomerId > 0 ? '?customer=' . $quoteCustomerId . '#customer-' . $quoteCustomerId : '');
    $emailActions = [
        ['label' => 'Ügyfél adatlap megnyitása', 'url' => absolute_url($quoteContextPath)],
        ['label' => 'Ajánlat szerkesztése', 'url' => absolute_url('/admin/quotes/edit?id=' . (int) $quote['id'])],
    ];

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        add_admin_notification_recipients($mail);
        $mail->addReplyTo((string) $quote['email'], (string) $quote['requester_name']);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions);
        $mail->send();

        log_admin_notification_email($quoteId, $subject, 'sent');

        return ['ok' => true, 'message' => 'Admin értesítés elküldve.'];
    } catch (Throwable $exception) {
        log_admin_notification_email($quoteId, $subject, 'failed', $exception->getMessage());

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az admin email értesítés küldése sikertelen.'];
    }
}

function send_quote_registration_offer(int $quoteId): array
{
    $quote = find_quote($quoteId);

    if ($quote === null) {
        return ['ok' => false, 'message' => 'Az ajánlat nem található.'];
    }

    $customer = find_customer((int) $quote['customer_id']);

    if ($customer !== null && !empty($customer['user_id'])) {
        return ['ok' => true, 'message' => 'Az ügyfélnek már van saját profilja.'];
    }

    $token = ensure_quote_public_token($quoteId);

    if ($token !== null) {
        $quote['public_token'] = $token;
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [$quoteId, $quote['email'], APP_NAME . ' ügyfélprofil regisztráció', 'failed', 'PHPMailer hiányzik.']
        );
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $subject = APP_NAME . ' - saját ügyfélprofil regisztráció';
    $emailSections = [
        [
            'title' => 'Elfogadott ajánlat',
            'rows' => [
                ['label' => 'Ajánlatszám', 'value' => $quote['quote_number'] ?? '-'],
                ['label' => 'Tárgy', 'value' => $quote['subject'] ?? '-'],
                ['label' => 'Összeg', 'value' => quote_display_total($quote)],
                ['label' => 'Ügyfél', 'value' => ($quote['requester_name'] ?? '-') . "\n" . ($quote['email'] ?? '-') . "\n" . ($quote['phone'] ?? '-')],
            ],
        ],
    ];
    $emailActions = [
        ['label' => 'Saját profil regisztrációja', 'url' => absolute_url(quote_registration_path($quote))],
        ['label' => 'Árajánlat megnyitása', 'url' => quote_customer_action_url($quote)],
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress((string) $quote['email'], (string) $quote['requester_name']);
        $mail->Subject = $subject;
        apply_branded_email(
            $mail,
            'Saját ügyfélprofil létrehozása',
            'Köszönjük az árajánlat elfogadását. A folytatáshoz létrehozhat saját ügyfélprofilt, ahol később az igény adatai és dokumentumai is kezelhetők.',
            $emailSections,
            $emailActions,
            (string) ($quote['requester_name'] ?? '')
        );
        $mail->send();

        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [$quoteId, $quote['email'], $subject, 'sent']);

        return ['ok' => true, 'message' => 'A regisztrációs lehetőséget elküldtük az ügyfélnek.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [$quoteId, $quote['email'], $subject, 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A regisztrációs email küldése sikertelen.'];
    }
}

function record_quote_customer_response(int $quoteId, string $response, string $message = ''): array
{
    $quote = find_quote($quoteId);

    if ($quote === null) {
        return ['ok' => false, 'message' => 'Az ajánlat nem található.'];
    }

    $message = trim($message);

    if ($response === 'accept') {
        if ((string) ($quote['status'] ?? '') === 'accepted') {
            return ['ok' => true, 'message' => 'Az árajánlat már elfogadott.'];
        }

        $sets = ['`status` = ?', '`accepted_at` = NOW()'];
        $params = ['accepted'];

        if (db_column_exists('quotes', 'customer_response_message')) {
            $sets[] = '`customer_response_message` = ?';
            $params[] = $message !== '' ? $message : null;
        }

        $params[] = $quoteId;
        db_query('UPDATE `quotes` SET ' . implode(', ', $sets) . ' WHERE `id` = ?', $params);
        $notification = notify_admin_quote_response($quoteId, $response, $message);
        $registrationOffer = send_quote_registration_offer($quoteId);
        $feeRequest = send_quote_fee_request_email($quoteId, '', true);
        $responseMessage = 'Köszönjük, az árajánlat elfogadását rögzítettük.';

        if ($feeRequest['ok'] && empty($feeRequest['skipped'])) {
            $responseMessage .= ' Az ügykezelési díjról a díjbekérőt emailben elküldtük. A munka MVM felé történő indítását a díj beérkezése után tudjuk megkezdeni.';
        } elseif ($feeRequest['ok'] && !empty($feeRequest['skipped'])) {
            $responseMessage .= ' Az ajánlatban nincs fizetendő ügykezelési díj, ezért díjbekérőt nem küldünk.';
        } else {
            $responseMessage .= ' Az ügykezelési díjbekérő automatikus kiküldése közben technikai hiba történt, kollégáink ellenőrzik és szükség esetén külön jelentkeznek.';
        }

        if ($registrationOffer['ok'] && $registrationOffer['message'] !== 'Az ügyfélnek már van saját profilja.') {
            $responseMessage .= ' Külön emailben elküldtük a saját ügyfélprofil létrehozásának lehetőségét is.';
        }

        if (!$notification['ok']) {
            $responseMessage .= ' Az adminisztrátori értesítés küldését a rendszer naplózta, munkatársaink ellenőrizni tudják.';
        }

        return [
            'ok' => true,
            'message' => $responseMessage,
        ];
    }

    if ($response === 'consultation') {
        if (!db_enum_contains('quotes', 'status', 'consultation_requested')) {
            return ['ok' => false, 'message' => 'Az adatbázis még nem támogatja az egyeztetés kérése státuszt. Futtasd a quote_response_actions.sql frissítőt.'];
        }

        $sets = ['`status` = ?'];
        $params = ['consultation_requested'];

        if (db_column_exists('quotes', 'consultation_requested_at')) {
            $sets[] = '`consultation_requested_at` = NOW()';
        }

        if (db_column_exists('quotes', 'customer_response_message')) {
            $sets[] = '`customer_response_message` = ?';
            $params[] = $message !== '' ? $message : null;
        }

        $params[] = $quoteId;
        db_query('UPDATE `quotes` SET ' . implode(', ', $sets) . ' WHERE `id` = ?', $params);
        $notification = notify_admin_quote_response($quoteId, $response, $message);

        return [
            'ok' => true,
            'message' => $notification['ok']
                ? 'Az egyeztetési kérést rögzítettük, és értesítettük az admint.'
                : 'Az egyeztetési kérést rögzítettük, de az admin email értesítés nem ment ki: ' . $notification['message'],
        ];
    }

    return ['ok' => false, 'message' => 'Ismeretlen árajánlat-válasz.'];
}

function accept_quote(int $quoteId): void
{
    record_quote_customer_response($quoteId, 'accept');
}

function quote_upload_schema_errors(): array
{
    $errors = [];

    if (!db_table_exists('quotes')) {
        $errors[] = 'Hianyzik a quotes tabla.';
        return $errors;
    }

    foreach ([
        'connection_request_id' => 'quotes.connection_request_id',
        'public_token' => 'quotes.public_token',
        'uploaded_original_name' => 'quotes.uploaded_original_name',
        'uploaded_by_user_id' => 'quotes.uploaded_by_user_id',
    ] as $column => $label) {
        if (!db_column_exists('quotes', $column)) {
            $errors[] = 'Hianyzik a ' . $label . ' oszlop.';
        }
    }

    return $errors;
}

function normalize_uploaded_quote_data(array $source, ?array $request = null): array
{
    $defaultSubject = APP_NAME . ' árajánlat';

    if ($request !== null && !empty($request['project_name'])) {
        $defaultSubject .= ' - ' . $request['project_name'];
    }

    return [
        'subject' => trim((string) ($source['subject'] ?? $defaultSubject)),
        'customer_message' => trim((string) ($source['customer_message'] ?? '')),
    ];
}

function validate_uploaded_quote_data(array $data, ?array $file): array
{
    $errors = [];

    if ($data['subject'] === '') {
        $errors[] = 'Az ajánlat tárgya kötelező.';
    }

    if (!is_array($file)) {
        $errors[] = 'Az ajánlatfájl feltöltése kötelező.';
        return $errors;
    }

    return array_merge($errors, validate_portal_file_upload($file, 'Ajánlatfájl'));
}

function create_uploaded_quote_for_request(int $requestId, array $data, array $file): int
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        throw new RuntimeException('A munkaigény nem található.');
    }

    $targetDir = CUSTOMER_QUOTE_UPLOAD_PATH . '/' . $requestId;
    ensure_storage_dir($targetDir);

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $extension = $extension === 'jpeg' ? 'jpg' : $extension;
    $storedName = 'quote-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $storedName;

    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Nem sikerült menteni a feltöltött ajánlatot.');
    }

    $user = current_user();

    db_query(
        'INSERT INTO `quotes`
            (`customer_id`, `connection_request_id`, `specialist_user_id`, `quote_number`, `status`, `subject`,
             `customer_message`, `public_token`, `total_net`, `total_vat`, `total_gross`, `pdf_path`, `uploaded_original_name`,
             `uploaded_by_user_id`, `sent_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
        [
            (int) $request['customer_id'],
            $requestId,
            is_array($user) ? (int) $user['id'] : null,
            next_quote_number(),
            'sent',
            $data['subject'],
            $data['customer_message'] !== '' ? $data['customer_message'] : null,
            bin2hex(random_bytes(32)),
            0,
            0,
            0,
            $targetPath,
            (string) $file['name'],
            is_array($user) ? (int) $user['id'] : null,
        ]
    );

    $uploadedQuoteId = (int) db()->lastInsertId();
    clear_connection_request_quote_missing_reason($requestId);

    return $uploadedQuoteId;
}

function quote_file_is_available(array $quote): bool
{
    return !empty($quote['pdf_path']) && is_file((string) $quote['pdf_path']);
}

function quote_public_url(array $quote): string
{
    if (empty($quote['public_token'])) {
        return absolute_url('/customer/quotes/view?id=' . (int) $quote['id']);
    }

    return absolute_url('/quote?id=' . (int) $quote['id'] . '&token=' . rawurlencode((string) $quote['public_token']));
}

function find_quote_by_public_access(int $quoteId, string $token): ?array
{
    if ($quoteId <= 0 || $token === '' || !db_column_exists('quotes', 'public_token')) {
        return null;
    }

    quote_engagement_schema_ensure();

    $statement = db_query(
        'SELECT q.*, c.requester_name, c.email, c.phone, c.postal_address, c.postal_code, c.city, c.company_name
         FROM `quotes` q
         INNER JOIN `customers` c ON c.id = q.customer_id
         WHERE q.id = ? AND q.public_token = ?
         LIMIT 1',
        [$quoteId, $token]
    );
    $quote = $statement->fetch();

    return is_array($quote) ? $quote : null;
}

function quote_tracking_url(array $quote): string
{
    if (empty($quote['id']) || empty($quote['public_token'])) {
        return '';
    }

    return absolute_url('/quote/open?id=' . (int) $quote['id'] . '&token=' . rawurlencode((string) $quote['public_token']));
}

function quote_tracking_pixel_html(array $quote): string
{
    $trackingUrl = quote_tracking_url($quote);

    if ($trackingUrl === '') {
        return '';
    }

    return '<img src="' . h($trackingUrl) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;outline:0;opacity:0;" />';
}

function append_quote_tracking_pixel(object $mail, array $quote): void
{
    $pixelHtml = quote_tracking_pixel_html($quote);

    if ($pixelHtml === '') {
        return;
    }

    $body = (string) ($mail->Body ?? '');
    $bodyClosePosition = stripos($body, '</body>');

    if ($bodyClosePosition === false) {
        $mail->Body = $body . $pixelHtml;
        return;
    }

    $mail->Body = substr($body, 0, $bodyClosePosition) . $pixelHtml . substr($body, $bodyClosePosition);
}

function record_quote_email_open(int $quoteId, string $token): void
{
    if (!quote_engagement_schema_ensure() || find_quote_by_public_access($quoteId, $token) === null) {
        return;
    }

    db_query(
        'UPDATE `quotes`
         SET `email_opened_at` = COALESCE(`email_opened_at`, NOW()),
             `email_last_opened_at` = NOW(),
             `email_open_count` = `email_open_count` + 1
         WHERE `id` = ?',
        [$quoteId]
    );
}

function record_quote_public_view(int $quoteId, string $token): void
{
    if (!quote_engagement_schema_ensure() || find_quote_by_public_access($quoteId, $token) === null) {
        return;
    }

    db_query(
        'UPDATE `quotes`
         SET `viewed_at` = COALESCE(`viewed_at`, NOW()),
             `last_viewed_at` = NOW(),
             `view_count` = `view_count` + 1
         WHERE `id` = ?',
        [$quoteId]
    );
}

function quote_latest_email_log(int $quoteId, string $status = ''): ?array
{
    if (!db_table_exists('email_logs')) {
        return null;
    }

    $params = [$quoteId];
    $where = '`quote_id` = ?';

    if ($status !== '') {
        $where .= ' AND `status` = ?';
        $params[] = $status;
    }

    $statement = db_query(
        'SELECT * FROM `email_logs`
         WHERE ' . $where . '
         ORDER BY `created_at` DESC, `id` DESC
         LIMIT 1',
        $params
    );
    $log = $statement->fetch();

    return is_array($log) ? $log : null;
}

function quote_engagement_count_label(mixed $count): string
{
    $count = max(0, (int) $count);

    return $count > 0 ? ' (' . $count . 'x)' : '';
}

function quote_display_total(array $quote): string
{
    $gross = (float) ($quote['total_gross'] ?? 0);

    return $gross > 0 ? format_money($gross) : 'Feltöltött ajánlat';
}

function connection_request_quote_missing_reason_schema_errors(): array
{
    if (!db_table_exists('connection_requests')) {
        return ['Hiányzik a connection_requests tábla.'];
    }

    if (!db_column_exists('connection_requests', 'quote_missing_reason')) {
        return ['Hiányzik a connection_requests.quote_missing_reason oszlop. Futtasd a database/quote_assignment_guard.sql fájlt phpMyAdminban.'];
    }

    return [];
}

function connection_request_quote_missing_reason(array $request): string
{
    return trim((string) ($request['quote_missing_reason'] ?? ''));
}

function save_connection_request_quote_missing_reason(int $requestId, string $reason): void
{
    if (!db_column_exists('connection_requests', 'quote_missing_reason')) {
        return;
    }

    $reason = trim($reason);

    db_query(
        'UPDATE `connection_requests` SET `quote_missing_reason` = ? WHERE `id` = ?',
        [$reason !== '' ? $reason : null, $requestId]
    );
}

function clear_connection_request_quote_missing_reason(int $requestId): void
{
    save_connection_request_quote_missing_reason($requestId, '');
}

function admin_workflow_stage_definitions(): array
{
    return [
        'case_starting' => [
            'number' => 1,
            'title' => 'Ügyindítás alatt',
            'description' => 'A felmérés megtörtént, vagy az ügyfél/szerelő rögzítette a munkát.',
            'variant' => 'primary',
        ],
        'ready_to_submit' => [
            'number' => 2,
            'title' => 'Ügyindításra kész',
            'description' => 'Minden ügyindításhoz szükséges dokumentum és fotó rendelkezésre áll.',
            'variant' => 'accent',
        ],
        'in_progress' => [
            'number' => 3,
            'title' => 'Folyamatban',
            'description' => 'Az MVM dokumentumok elkészültek, az ügyintézés folyamatban van.',
            'variant' => 'system',
        ],
        'waiting_plan' => [
            'number' => 4,
            'title' => 'Tervkészítésre vár',
            'description' => 'Az MVM jóváhagyta az igényt, indulhat a tervkészítés.',
            'variant' => 'primary',
        ],
        'waiting_intervention_sheet' => [
            'number' => 5,
            'title' => 'Beavatkozólapra vár',
            'description' => 'A kiviteli terv elkészült és be lett küldve az MVM-nek.',
            'variant' => 'accent',
        ],
        'under_construction' => [
            'number' => 6,
            'title' => 'Kivitelezés alatt',
            'description' => 'A beavatkozólap megérkezett, a kivitelezés folyamatban van.',
            'variant' => 'system',
        ],
        'completed' => [
            'number' => 7,
            'title' => 'Befejezve',
            'description' => 'A szerelő elvégezte a kivitelezést, és minden kötelező fotó fent van.',
            'variant' => 'primary',
        ],
        'demand_reporter' => [
            'number' => 8,
            'title' => 'Igénybejelentő',
            'description' => 'Az MVM kapcsolja be az ügyfelet, a munka lezáró ügyintézésben van.',
            'variant' => 'accent',
        ],
        'waiting_customer_response' => [
            'number' => 9,
            'title' => 'Választ várunk a fogyasztótól',
            'description' => 'Az ügyintézés az ügyfél visszajelzésére vár.',
            'variant' => 'system',
        ],
    ];
}

function admin_workflow_stage_label(string $stage): string
{
    $stages = admin_workflow_stage_definitions();

    return $stages[$stage]['title'] ?? $stage;
}

function admin_workflow_stage_number(string $stage): int
{
    $stages = admin_workflow_stage_definitions();

    return (int) ($stages[$stage]['number'] ?? 0);
}

function normalize_admin_workflow_stage(?string $stage): ?string
{
    $stage = trim((string) $stage);
    $legacyStages = [
        'new_request' => 'case_starting',
        'quote_needed' => 'case_starting',
        'quote_waiting_acceptance' => 'case_starting',
        'quote_accepted_document_needed' => 'ready_to_submit',
        'document_sent_to_mvm' => 'in_progress',
        'mvm_accepted_plan_needed' => 'waiting_plan',
        'plan_accepted_work_order_needed' => 'waiting_intervention_sheet',
        'work_order_arrived_assignable' => 'under_construction',
        'assigned_waiting_execution' => 'under_construction',
        'completed_waiting_settlement' => 'completed',
        'waiting_customer_answer' => 'waiting_customer_response',
        'waiting_consumer_response' => 'waiting_customer_response',
    ];

    if (isset($legacyStages[$stage])) {
        $stage = $legacyStages[$stage];
    }

    return array_key_exists($stage, admin_workflow_stage_definitions()) ? $stage : null;
}

function update_connection_request_admin_workflow_stage(int $requestId, ?string $stage): void
{
    if (!db_column_exists('connection_requests', 'admin_workflow_stage')) {
        return;
    }

    db_query(
        'UPDATE `connection_requests` SET `admin_workflow_stage` = ? WHERE `id` = ?',
        [$stage !== null ? normalize_admin_workflow_stage($stage) : null, $requestId]
    );
}

function send_connection_request_status_change_email(int $requestId, string $previousStage, string $nextStage): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az ügyfélértesítés nem ment ki, mert a munka nem található.'];
    }

    $recipientEmail = trim((string) ($request['email'] ?? ''));
    $recipientName = email_recipient_name($request['requester_name'] ?? '');

    if ($recipientEmail === '') {
        return ['ok' => false, 'message' => 'Az ügyfélértesítés nem ment ki, mert hiányzik az ügyfél email címe.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'Az ügyfélértesítés nem ment ki, mert a PHPMailer nincs telepítve.'];
    }

    $previousDefinition = admin_workflow_stage_definitions()[$previousStage] ?? null;
    $nextDefinition = admin_workflow_stage_definitions()[$nextStage] ?? null;
    $previousLabel = $previousDefinition !== null ? (string) $previousDefinition['title'] : admin_workflow_stage_label($previousStage);
    $nextLabel = $nextDefinition !== null ? (string) $nextDefinition['title'] : admin_workflow_stage_label($nextStage);
    $token = customer_email_thread_token($requestId, 'status-' . $nextStage);
    $subject = customer_email_thread_subject(APP_NAME . ' státuszváltozás - ' . (string) ($request['project_name'] ?? ('Munka #' . $requestId)), $token);
    $replyAddress = mvm_mail_reply_address();
    $sections = [
        [
            'title' => 'Munka állapota',
            'rows' => [
                ['label' => 'Munka', 'value' => $request['project_name'] ?? '-'],
                ['label' => 'Korábbi státusz', 'value' => $previousLabel],
                ['label' => 'Új státusz', 'value' => $nextLabel],
                ['label' => 'Mit jelent ez?', 'value' => (string) ($nextDefinition['description'] ?? '')],
                ['label' => 'Kivitelezés címe', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                ['label' => 'Válaszazonosító', 'value' => $token],
            ],
        ],
    ];

    if ($nextStage === 'under_construction') {
        $dueBreakdown = connection_request_electrician_due_breakdown($requestId);
        $scheduleUrl = connection_request_schedule_url($request);

        if ((float) ($dueBreakdown['total'] ?? 0) > 0) {
            $sections[] = [
                'title' => 'Kivitelezés napján fizetendő összeg',
                'rows' => [
                    ['label' => 'Regisztrált villanyszerelői tételek', 'value' => format_money((float) ($dueBreakdown['registered'] ?? 0))],
                    ['label' => 'Villanyszerelői szakmunkás tételek', 'value' => format_money((float) ($dueBreakdown['specialist'] ?? 0))],
                    ['label' => 'Összesen a szerelő részére', 'value' => format_money((float) ($dueBreakdown['total'] ?? 0))],
                    ['label' => 'Fontos', 'value' => 'Ez az összeg nem tartalmazza az MVM-nek fizetendő díjakat és az ügykezelési díjat.'],
                ],
            ];
        }

        $sections[] = [
            'title' => 'Kivitelezési időpont',
            'rows' => [
                ['label' => 'Időpontválasztás', 'value' => 'A kivitelezés egy munkanapot vesz igénybe, és csak hétköznapra foglalható.'],
                ['label' => 'Naptár link', 'value' => $scheduleUrl],
            ],
        ];
    }

    $actions = [
        ['label' => 'Ügyfélportál megnyitása', 'url' => absolute_url('/customer/work-requests')],
    ];

    if ($nextStage === 'under_construction') {
        $actions[] = ['label' => 'Kivitelezési nap kiválasztása', 'url' => connection_request_schedule_url($request)];
    }
    if ($nextStage === 'waiting_customer_response') {
        $sections[] = [
            'title' => 'Teendő',
            'rows' => [
                ['label' => 'Visszajelzés', 'value' => 'Kérjük, válaszoljon erre az emailre, hogy az ügyintézést folytatni tudjuk.'],
                ['label' => 'Fontos', 'value' => 'A válasz a tárgyban szereplő azonosító alapján automatikusan ehhez az adatlaphoz kerül.'],
            ],
        ];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->addReplyTo($replyAddress, MAIL_FROM_NAME);
        $mail->Subject = $subject;
        $emailTitle = 'Státuszváltozás történt';
        $emailLead = $nextStage === 'under_construction'
            ? 'A munka kivitelezési szakaszba lépett. Az alábbi összefoglalóban láthatja, mennyi pénzt érdemes előkészítenie a kivitelezés napjára. Ha kérdése van, kérjük, válaszoljon erre az emailre, és az üzenet automatikusan ehhez a munkához kerül.'
            : 'Frissült a mérőhelyi ügyintézés állapota. Ha kérdése van, kérjük, válaszoljon erre az emailre, és az üzenet automatikusan ehhez a munkához kerül.';
        if ($nextStage === 'waiting_customer_response') {
            $emailTitle = 'Választ várunk';
            $emailLead = 'Az ügyintézés folytatásához az Ön visszajelzésére van szükségünk. Kérjük, válaszoljon erre az emailre röviden a kért információval, hogy a munkát tovább tudjuk vinni.';
        }

        apply_branded_email(
            $mail,
            $emailTitle,
            $emailLead,
            $sections,
            $actions,
            $recipientName
        );
        $mail->send();
        $messageId = method_exists($mail, 'getLastMessageID') ? (string) $mail->getLastMessageID() : '';
        record_customer_email_thread(
            $requestId,
            $token,
            $recipientEmail,
            $subject,
            'Ügyfél státuszértesítés',
            branded_email_text($emailTitle, $emailLead, $sections, $actions, $recipientName),
            branded_email_html($emailTitle, $emailLead, $sections, $actions, $recipientName),
            $messageId !== '' ? $messageId : null
        );

        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, $recipientEmail, $subject, 'sent']);

        return ['ok' => true, 'message' => 'Az ügyfél email értesítést kapott a státuszváltozásról.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, $recipientEmail, $subject, 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az ügyfél státuszértesítő email küldése sikertelen.'];
    }
}

function connection_request_document_type_exists(array $documents, string $documentType): bool
{
    foreach ($documents as $document) {
        if ((string) ($document['document_type'] ?? '') === $documentType) {
            return true;
        }
    }

    return false;
}

function connection_request_document_type_any_exists(array $documents, array $documentTypes): bool
{
    foreach ($documentTypes as $documentType) {
        if (connection_request_document_type_exists($documents, (string) $documentType)) {
            return true;
        }
    }

    return false;
}

function connection_request_admin_workflow_stage(array $request, ?array $latestQuote = null, ?array $acceptedQuote = null, array $documents = []): string
{
    $manualStage = normalize_admin_workflow_stage((string) ($request['admin_workflow_stage'] ?? ''));
    $requestId = (int) ($request['id'] ?? 0);

    if ($manualStage !== null) {
        return $manualStage;
    }

    if ((string) ($request['electrician_status'] ?? '') === 'completed' || !empty($request['after_photos_completed_at'])) {
        $automaticStage = 'completed';
    } elseif (connection_request_document_type_any_exists($documents, ['intervention_sheet', 'completed_intervention_sheet'])) {
        $automaticStage = 'under_construction';
    } elseif (connection_request_document_type_any_exists($documents, ['execution_plan_package'])) {
        $automaticStage = 'waiting_intervention_sheet';
    } elseif (connection_request_document_type_any_exists($documents, ['execution_plan'])) {
        $automaticStage = 'waiting_intervention_sheet';
    } elseif (connection_request_document_type_any_exists($documents, ['accepted_request'])) {
        $automaticStage = 'waiting_plan';
    } elseif (connection_request_document_type_any_exists($documents, ['complete_package'])) {
        $automaticStage = 'in_progress';
    } elseif (connection_request_document_type_any_exists($documents, ['submitted_request'])) {
        $automaticStage = 'in_progress';
    } elseif ($requestId > 0 && connection_request_complete_package_missing_items($requestId) === []) {
        $automaticStage = 'ready_to_submit';
    } else {
        $automaticStage = 'case_starting';
    }

    return $automaticStage;
}

function connection_request_initial_data_is_editable(array $request, ?array $latestQuote = null, ?array $acceptedQuote = null, array $documents = []): bool
{
    $requestId = (int) ($request['id'] ?? 0);

    if ($requestId > 0) {
        $latestQuote ??= latest_quote_for_connection_request($requestId);
        $acceptedQuote ??= accepted_quote_for_connection_request($requestId)
            ?? accepted_quote_for_registration_duplicate_request($requestId);

        if ($documents === []) {
            $documents = connection_request_documents($requestId);
        }
    }

    $stage = connection_request_admin_workflow_stage($request, $latestQuote, $acceptedQuote, $documents);
    $lockedStages = [
        'in_progress',
        'waiting_plan',
        'waiting_intervention_sheet',
        'under_construction',
        'completed',
        'demand_reporter',
    ];

    if (in_array($stage, $lockedStages, true)) {
        return false;
    }

    return !connection_request_document_type_any_exists($documents, [
        'complete_package',
        'submitted_request',
        'accepted_request',
        'execution_plan_package',
        'execution_plan',
        'intervention_sheet',
        'completed_intervention_sheet',
        'technical_handover_package',
        'seal_removal_package',
    ]);
}

function next_admin_workflow_stage(string $stage): ?string
{
    $currentNumber = admin_workflow_stage_number($stage);

    foreach (admin_workflow_stage_definitions() as $key => $definition) {
        if ((int) $definition['number'] === $currentNumber + 1) {
            return (string) $key;
        }
    }

    return null;
}

function send_connection_request_responsible_workflow_email(int $requestId, string $previousStage, string $nextStage): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'A szerelő értesítése nem ment ki, mert a munka nem található.'];
    }

    $nextDefinition = admin_workflow_stage_definitions()[$nextStage] ?? null;
    $message = "A munkafolyamat státusza módosult.\n\n"
        . 'Munka: ' . (string) ($request['project_name'] ?? ('#' . $requestId)) . "\n"
        . 'Korábbi státusz: ' . admin_workflow_stage_label($previousStage) . "\n"
        . 'Új státusz: ' . admin_workflow_stage_label($nextStage);

    if ($nextDefinition !== null && trim((string) ($nextDefinition['description'] ?? '')) !== '') {
        $message .= "\n\n" . (string) $nextDefinition['description'];
    }

    return send_connection_request_manual_message(
        $requestId,
        'responsible',
        APP_NAME . ' munkafolyamat státusz - ' . (string) ($request['project_name'] ?? ('Munka #' . $requestId)),
        $message
    );
}

function set_connection_request_workflow_stage(int $requestId, ?string $targetStage, bool $notifyCustomer = false, bool $notifyResponsible = false): array
{
    if (!db_column_exists('connection_requests', 'admin_workflow_stage')) {
        return ['ok' => false, 'message' => 'Hiányzik a connection_requests.admin_workflow_stage oszlop. Futtasd le az adatbázis frissítést.'];
    }

    $rawTargetStage = $targetStage;
    $targetStage = $targetStage !== null ? normalize_admin_workflow_stage($targetStage) : null;

    if ($rawTargetStage !== null && $targetStage === null) {
        return ['ok' => false, 'message' => 'Érvénytelen munkafolyamat státusz.'];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'A munka nem található.'];
    }

    $documents = connection_request_documents($requestId);
    $latestQuote = latest_quote_for_connection_request($requestId);
    $acceptedQuote = accepted_quote_for_connection_request($requestId);
    $currentStage = connection_request_admin_workflow_stage($request, $latestQuote, $acceptedQuote, $documents);

    update_connection_request_admin_workflow_stage($requestId, $targetStage);

    $updatedRequest = find_connection_request($requestId) ?? $request;
    $updatedDocuments = connection_request_documents($requestId);
    $updatedLatestQuote = latest_quote_for_connection_request($requestId);
    $updatedAcceptedQuote = accepted_quote_for_connection_request($requestId);
    $newStage = connection_request_admin_workflow_stage($updatedRequest, $updatedLatestQuote, $updatedAcceptedQuote, $updatedDocuments);
    $activityBody = admin_workflow_stage_label($currentStage) . ' -> ' . admin_workflow_stage_label($newStage);

    if ($targetStage === null) {
        $activityBody .= "\nAutomatikus állapot visszaállítva.";
    }

    record_connection_request_activity(
        $requestId,
        'workflow',
        'Munkafolyamat státusza módosítva',
        $activityBody
    );

    $notifications = [];
    $message = 'A munkafolyamat státusza mentve. Új státusz: ' . admin_workflow_stage_label($newStage) . '.';

    if ($targetStage === null) {
        $message .= ' A rendszer újra az automatikus állapotot használja.';
    }

    if ($notifyCustomer && $currentStage !== $newStage) {
        $notifications['customer'] = send_connection_request_status_change_email($requestId, $currentStage, $newStage);
    }

    if ($notifyResponsible && $currentStage !== $newStage) {
        $notifications['responsible'] = send_connection_request_responsible_workflow_email($requestId, $currentStage, $newStage);
    }

    if (($notifyCustomer || $notifyResponsible) && $currentStage === $newStage) {
        $message .= ' Nem ment ki státuszértesítés, mert a tényleges státusz nem változott.';
    }

    foreach ($notifications as $notification) {
        if (!empty($notification['message'])) {
            $message .= ' ' . (string) $notification['message'];
        }
    }

    return [
        'ok' => true,
        'message' => $message,
        'stage' => $newStage,
        'notifications' => $notifications,
    ];
}

function close_connection_request_workflow_stage(int $requestId): array
{
    if (!db_column_exists('connection_requests', 'admin_workflow_stage')) {
        return ['ok' => false, 'message' => 'Hiányzik a connection_requests.admin_workflow_stage oszlop. Futtasd le az adatbázis frissítést.'];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'A munka nem található.'];
    }

    $documents = connection_request_documents($requestId);
    $latestQuote = latest_quote_for_connection_request($requestId);
    $acceptedQuote = accepted_quote_for_connection_request($requestId);
    $currentStage = connection_request_admin_workflow_stage($request, $latestQuote, $acceptedQuote, $documents);
    $nextStage = next_admin_workflow_stage($currentStage);

    if ($nextStage === null) {
        return ['ok' => false, 'message' => 'Ez a munkafolyamat már az utolsó státuszban van.'];
    }

    return set_connection_request_workflow_stage($requestId, $nextStage, true, false);
}

function admin_workflow_assignment_due_text(array $request): string
{
    if (empty($request['assigned_electrician_user_id'])) {
        return 'Nincs szerelőnek kiadva';
    }

    $baseDate = (string) ($request['updated_at'] ?? $request['created_at'] ?? '');

    if ($baseDate === '') {
        return 'Határidő: kiadástól számított 60 nap';
    }

    try {
        $dueDate = new DateTimeImmutable($baseDate);
        return 'Határidő: ' . $dueDate->modify('+60 days')->format('Y-m-d');
    } catch (Throwable) {
        return 'Határidő: kiadástól számított 60 nap';
    }
}

function email_display_value(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'Igen' : 'Nem';
    }

    $string = trim((string) ($value ?? ''));

    return $string !== '' ? $string : '-';
}

function email_recipient_name(mixed $value): string
{
    $name = trim((string) ($value ?? ''));

    return $name !== '' ? $name : '';
}

function branded_email_html(string $title, string $lead, array $sections = [], array $actions = [], string $recipientName = ''): string
{
    $recipientName = email_recipient_name($recipientName);

    ob_start();
    ?>
    <!doctype html>
    <html lang="hu">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title); ?></title>
    </head>
    <body style="margin:0;padding:0;background:#f2f6f7;color:#17212f;font-family:Arial,Helvetica,sans-serif;line-height:1.55;">
        <div style="display:none;max-height:0;overflow:hidden;color:transparent;opacity:0;"><?= h($lead); ?></div>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#f2f6f7;margin:0;padding:24px 12px;">
            <tr>
                <td align="center">
                    <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #dfe6ee;border-radius:8px;overflow:hidden;box-shadow:0 10px 28px rgba(23,33,47,0.07);">
                        <tr>
                            <td style="background:#00594f;color:#ffffff;padding:28px 30px;">
                                <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#0bd7c4;"><?= h(APP_NAME); ?></div>
                                <h1 style="margin:8px 0 0;font-size:26px;line-height:1.18;font-weight:800;color:#ffffff;"><?= h($title); ?></h1>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:26px 30px 10px;">
                                <?php if ($recipientName !== ''): ?>
                                    <p style="margin:0 0 12px;color:#17212f;font-size:17px;font-weight:700;">Tisztelt <?= h($recipientName); ?>!</p>
                                <?php endif; ?>
                                <p style="margin:0;color:#405266;font-size:16px;"><?= nl2br(h($lead)); ?></p>
                            </td>
                        </tr>
                        <?php foreach ($sections as $section): ?>
                            <tr>
                                <td style="padding:16px 30px 0;">
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border:1px solid #dfe6ee;border-top:5px solid #0bd7c4;border-radius:8px;background:#ffffff;">
                                        <tr>
                                            <td style="padding:18px 20px;">
                                                <h2 style="margin:0 0 12px;font-size:18px;line-height:1.25;color:#00594f;"><?= h((string) ($section['title'] ?? 'Adatok')); ?></h2>
                                                <?php if (!empty($section['lead'])): ?>
                                                    <p style="margin:0 0 12px;color:#5d6b7c;"><?= nl2br(h((string) $section['lead'])); ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($section['rows']) && is_array($section['rows'])): ?>
                                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">
                                                        <?php foreach ($section['rows'] as $row): ?>
                                                            <tr>
                                                                <td style="width:38%;padding:9px 10px 9px 0;border-top:1px solid #edf1f5;color:#5d6b7c;font-size:13px;font-weight:700;vertical-align:top;"><?= h((string) ($row['label'] ?? '')); ?></td>
                                                                <td style="padding:9px 0;border-top:1px solid #edf1f5;color:#17212f;font-size:14px;vertical-align:top;"><?= nl2br(h(email_display_value($row['value'] ?? null))); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </table>
                                                <?php endif; ?>
                                                <?php if (!empty($section['items']) && is_array($section['items'])): ?>
                                                    <ul style="margin:8px 0 0;padding:0 0 0 18px;color:#17212f;">
                                                        <?php foreach ($section['items'] as $item): ?>
                                                            <li style="margin:0 0 8px;"><?= h(email_display_value($item)); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($actions !== []): ?>
                            <tr>
                                <td style="padding:24px 30px 6px;">
                                    <?php foreach ($actions as $action): ?>
                                        <a href="<?= h((string) ($action['url'] ?? '#')); ?>" style="display:inline-block;margin:0 8px 8px 0;padding:12px 18px;border-radius:8px;background:#008c73;color:#ffffff;text-decoration:none;font-weight:700;"><?= h((string) ($action['label'] ?? 'Megnyitás')); ?></a>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding:22px 30px 28px;color:#5d6b7c;font-size:13px;">
                                <p style="margin:0;">Üdvözlettel,<br><strong style="color:#00594f;"><?= h(APP_NAME); ?></strong></p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    <?php
    return (string) ob_get_clean();
}

function branded_email_text(string $title, string $lead, array $sections = [], array $actions = [], string $recipientName = ''): string
{
    $recipientName = email_recipient_name($recipientName);
    $lines = [
        APP_NAME,
        '',
        $title,
        '',
    ];

    if ($recipientName !== '') {
        $lines[] = 'Tisztelt ' . $recipientName . '!';
        $lines[] = '';
    }

    $lines[] = $lead;
    $lines[] = '';

    foreach ($sections as $section) {
        $lines[] = (string) ($section['title'] ?? 'Adatok');

        if (!empty($section['lead'])) {
            $lines[] = (string) $section['lead'];
        }

        if (!empty($section['rows']) && is_array($section['rows'])) {
            foreach ($section['rows'] as $row) {
                $lines[] = (string) ($row['label'] ?? '') . ': ' . email_display_value($row['value'] ?? null);
            }
        }

        if (!empty($section['items']) && is_array($section['items'])) {
            foreach ($section['items'] as $item) {
                $lines[] = '- ' . email_display_value($item);
            }
        }

        $lines[] = '';
    }

    foreach ($actions as $action) {
        $lines[] = (string) ($action['label'] ?? 'Megnyitás') . ': ' . (string) ($action['url'] ?? '');
    }

    if ($actions !== []) {
        $lines[] = '';
    }

    $lines[] = 'Üdvözlettel,';
    $lines[] = APP_NAME;

    return implode("\n", $lines);
}

function apply_branded_email(object $mail, string $title, string $lead, array $sections = [], array $actions = [], string $recipientName = ''): void
{
    $mail->isHTML(true);
    $mail->Body = branded_email_html($title, $lead, $sections, $actions, $recipientName);
    $mail->AltBody = branded_email_text($title, $lead, $sections, $actions, $recipientName);
}

function admin_notification_copy_emails(): array
{
    return ['kapcsolat@villanyszerelo-bekes.hu'];
}

function admin_notification_recipients(): array
{
    $emails = array_merge([CONNECTION_REQUEST_EMAIL], admin_notification_copy_emails());
    $emails = array_map(static fn (string $email): string => strtolower(trim($email)), $emails);
    $emails = array_filter($emails, static fn (string $email): bool => $email !== '');

    return array_values(array_unique($emails));
}

function add_admin_notification_recipients(object $mail): void
{
    $recipients = admin_notification_recipients();

    if ($recipients === []) {
        return;
    }

    $mail->addAddress($recipients[0]);

    foreach (array_slice($recipients, 1) as $copyEmail) {
        $mail->addCC($copyEmail);
    }
}

function log_admin_notification_email(?int $quoteId, string $subject, string $status, ?string $errorMessage = null): void
{
    foreach (admin_notification_recipients() as $recipientEmail) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [$quoteId, $recipientEmail, $subject, $status, $errorMessage]
        );
    }
}

function admin_notification_should_send_for_current_actor(): bool
{
    return true;
}

function admin_notification_actor_rows(?string $sourceLabel = null): array
{
    $actor = current_actor_snapshot($sourceLabel);
    $source = trim((string) ($actor['source_label'] ?? ''));

    $rows = [
        ['label' => 'Forrás', 'value' => $source !== '' ? $source : (string) $actor['role_label']],
        ['label' => 'Szerepkör', 'value' => (string) $actor['role_label']],
        ['label' => 'Időpont', 'value' => date('Y-m-d H:i:s')],
    ];

    if (trim((string) ($actor['name'] ?? '')) !== '') {
        $rows[] = ['label' => 'Név', 'value' => (string) $actor['name']];
    }

    if (trim((string) ($actor['email'] ?? '')) !== '') {
        $rows[] = ['label' => 'Email', 'value' => (string) $actor['email']];
    }

    if (!empty($actor['user_id'])) {
        $rows[] = ['label' => 'Felhasználó ID', 'value' => '#' . (int) $actor['user_id']];
    }

    return $rows;
}

function send_admin_activity_notification(
    string $title,
    string $lead,
    array $sections = [],
    array $actions = [],
    ?array $replyTo = null,
    ?int $quoteId = null,
    ?string $sourceLabel = null
): array {
    if (!admin_notification_should_send_for_current_actor()) {
        return ['ok' => true, 'message' => 'Admin által indított művelethez nem küldtünk külön értesítést.'];
    }

    $subject = APP_NAME . ' rendszerértesítés - ' . $title;

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        log_admin_notification_email($quoteId, $subject, 'failed', 'PHPMailer hiányzik.');
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $sections[] = [
        'title' => 'Művelet forrása',
        'rows' => admin_notification_actor_rows($sourceLabel),
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        add_admin_notification_recipients($mail);

        if (is_array($replyTo) && !empty($replyTo['email'])) {
            $mail->addReplyTo((string) $replyTo['email'], (string) ($replyTo['name'] ?? ''));
        }

        $mail->Subject = $subject;
        apply_branded_email($mail, $title, $lead, $sections, $actions);
        $mail->send();
        log_admin_notification_email($quoteId, $subject, 'sent');

        return ['ok' => true, 'message' => 'Admin értesítés elküldve.'];
    } catch (Throwable $exception) {
        log_admin_notification_email($quoteId, $subject, 'failed', $exception->getMessage());

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az admin értesítés küldése sikertelen.'];
    }
}

function send_password_reset_email(array $user, string $token): array
{
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $resetUrl = absolute_url('/reset-password?token=' . rawurlencode($token));
    $subject = APP_NAME . ' jelszó-visszaállítás';
    $emailTitle = 'Jelszó-visszaállítás';
    $emailLead = 'Jelszó-visszaállítást kértek a fiókjához. A link 1 óráig érvényes.';
    $emailSections = [
        [
            'title' => 'Fiók',
            'rows' => [
                ['label' => 'Név', 'value' => $user['name'] ?? '-'],
                ['label' => 'Email', 'value' => $user['email'] ?? '-'],
                ['label' => 'Érvényesség', 'value' => '1 óra'],
            ],
        ],
    ];
    $emailActions = [
        ['label' => 'Új jelszó beállítása', 'url' => $resetUrl],
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress((string) $user['email'], (string) ($user['name'] ?? ''));
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions, (string) ($user['name'] ?? ''));
        $mail->send();

        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)',
            [null, (string) $user['email'], $subject, 'sent']
        );

        return ['ok' => true, 'message' => 'A jelszó-visszaállító emailt elküldtük.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, (string) $user['email'], $subject, 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A jelszó-visszaállító email küldése sikertelen.'];
    }
}

function send_uploaded_quote_notification(int $quoteId): array
{
    $quote = find_quote($quoteId);

    if ($quote === null) {
        return ['ok' => false, 'message' => 'Az ajánlat nem található.'];
    }

    $token = ensure_quote_public_token($quoteId);

    if ($token !== null) {
        $quote['public_token'] = $token;
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [$quoteId, $quote['email'], APP_NAME . ' árajánlat', 'failed', 'PHPMailer hiányzik.']
        );
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $subject = APP_NAME . ' elkészítette az árajánlatot';
    $quoteUrl = quote_public_url($quote);
    $emailTitle = 'Árajánlat elkészült';
    $emailLead = 'Elkészítettük az árajánlatot. Az alábbi gombokkal megtekintheti, elfogadhatja, vagy egyeztetést kérhet.';
    $emailSections = [
        [
            'title' => 'Ajánlat adatai',
            'rows' => [
                ['label' => 'Ajánlatszám', 'value' => $quote['quote_number'] ?? '-'],
                ['label' => 'Tárgy', 'value' => $quote['subject'] ?? '-'],
                ['label' => 'Ügyfél', 'value' => $quote['requester_name'] ?? '-'],
                ['label' => 'Összeg', 'value' => quote_display_total($quote)],
            ],
        ],
    ];
    $emailActions = [
        ['label' => 'Árajánlat megtekintése', 'url' => $quoteUrl],
        ['label' => 'Árajánlat elfogadása', 'url' => quote_customer_action_url($quote, 'accept')],
        ['label' => 'Árajánlat egyeztetés', 'url' => quote_customer_action_url($quote, 'consultation')],
    ];

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress((string) $quote['email'], (string) $quote['requester_name']);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions, (string) ($quote['requester_name'] ?? ''));
        append_quote_tracking_pixel($mail, $quote);
        $mail->send();

        db_query('UPDATE `quotes` SET `status` = ?, `sent_at` = COALESCE(`sent_at`, NOW()) WHERE `id` = ?', ['sent', $quoteId]);
        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [$quoteId, $quote['email'], $subject, 'sent']);

        return ['ok' => true, 'message' => 'Az ügyfél értesítése elküldve.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [$quoteId, $quote['email'], $subject, 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az email küldése sikertelen.'];
    }
}

function handle_quote_photo_uploads(int $quoteId, ?int $surveyId, array $files): array
{
    $messages = [];

    if (empty($files['name']) || !is_array($files['name'])) {
        return $messages;
    }

    $targetDir = QUOTE_UPLOAD_PATH . '/' . $quoteId;
    ensure_storage_dir($targetDir);

    foreach ($files['name'] as $index => $originalName) {
        if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if (($files['error'][$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $messages[] = 'Egy fotó feltöltése sikertelen.';
            continue;
        }

        if (($files['size'][$index] ?? 0) > PHOTO_MAX_BYTES) {
            $messages[] = h((string) $originalName) . ': túl nagy fájl.';
            continue;
        }

        $tmpName = (string) $files['tmp_name'][$index];
        $mimeType = mime_content_type($tmpName) ?: '';
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed[$mimeType])) {
            $messages[] = h((string) $originalName) . ': nem engedélyezett fájltípus.';
            continue;
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $allowed[$mimeType];
        $targetPath = $targetDir . '/' . $storedName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            $messages[] = h((string) $originalName) . ': nem sikerült menteni.';
            continue;
        }

        db_query(
            'INSERT INTO `quote_photos`
                (`quote_id`, `survey_id`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $quoteId,
                $surveyId,
                (string) $originalName,
                $storedName,
                $targetPath,
                $mimeType,
                (int) $files['size'][$index],
            ]
        );
    }

    return $messages;
}

function quote_photos(int $quoteId): array
{
    return db_query(
        'SELECT * FROM `quote_photos` WHERE `quote_id` = ? ORDER BY `created_at` DESC, `id` DESC',
        [$quoteId]
    )->fetchAll();
}

function find_quote_photo(int $photoId): ?array
{
    $statement = db_query('SELECT * FROM `quote_photos` WHERE `id` = ? LIMIT 1', [$photoId]);
    $photo = $statement->fetch();

    return is_array($photo) ? $photo : null;
}

function delete_quote_photo(int $photoId, ?int $quoteId = null): array
{
    $photo = find_quote_photo($photoId);

    if ($photo === null || ($quoteId !== null && (int) ($photo['quote_id'] ?? 0) !== $quoteId)) {
        return ['ok' => false, 'message' => 'A törlendő ajánlati fotó nem található.'];
    }

    db_query('DELETE FROM `quote_photos` WHERE `id` = ?', [$photoId]);
    delete_storage_files([(string) ($photo['storage_path'] ?? '')]);

    return ['ok' => true, 'message' => 'Az ajánlati fotó törölve.'];
}

function quote_pdf_html(array $quote, array $lines): string
{
    $sections = quote_price_sections();
    $catalogItems = active_price_items();
    $catalogBySection = array_fill_keys(array_keys($sections), []);
    $activePriceItemIds = [];

    foreach ($catalogItems as $item) {
        $category = quote_effective_category((string) $item['category'], (string) $item['name']);
        $catalogBySection[$category][] = $item;
        $activePriceItemIds[(int) $item['id']] = true;
    }

    $lineByPriceItemId = [];
    $customLinesBySection = array_fill_keys(array_keys($sections), []);

    foreach ($lines as $line) {
        $priceItemId = isset($line['price_item_id']) ? (int) $line['price_item_id'] : 0;

        if ($priceItemId > 0 && isset($activePriceItemIds[$priceItemId])) {
            $lineByPriceItemId[$priceItemId] = $line;
            continue;
        }

        $category = quote_effective_category((string) ($line['category'] ?? ''), (string) ($line['name'] ?? ''));
        $customLinesBySection[$category][] = $line;
    }

    ob_start();
    ?>
    <!doctype html>
    <html lang="hu">
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; color: #17212f; font-size: 11px; }
            h1 { font-size: 22px; margin: 0 0 12px; color: #00594f; }
            h2 { font-size: 14px; margin: 22px 0 8px; color: #00594f; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            th, td { border: 1px solid #333; padding: 5px; text-align: left; }
            th { background: #eef5f3; }
            .right { text-align: right; }
            .total { font-weight: bold; }
            .section-total td { background: #f8fbf3; font-weight: bold; }
            .notice { margin: 8px 0 12px; padding: 8px; border: 1px solid #dfe6ee; background: #f8fbf3; }
            .summary { margin-top: 18px; }
            .brand { text-align: right; color: #00594f; font-weight: bold; font-size: 18px; margin-top: 24px; }
        </style>
    </head>
    <body>
        <h1><?= h(APP_NAME); ?> árajánlat</h1>
        <p><strong>Ajánlat száma:</strong> <?= h($quote['quote_number']); ?></p>
        <p><strong>Ügyfél:</strong> <?= h($quote['requester_name']); ?>, <?= h($quote['postal_code']); ?> <?= h($quote['city']); ?>, <?= h($quote['postal_address']); ?></p>
        <p><strong>Email:</strong> <?= h($quote['email']); ?> | <strong>Telefon:</strong> <?= h($quote['phone']); ?></p>
        <p><strong>Dátum:</strong> <?= h(date('Y.m.d.')); ?></p>

        <h2><?= h($quote['subject']); ?></h2>
        <?php if (!empty($quote['customer_message'])): ?>
            <p><?= nl2br(h($quote['customer_message'])); ?></p>
        <?php endif; ?>

        <?php foreach ($sections as $category => $section): ?>
            <?php
            $sectionRows = [];
            $sectionTotal = 0.0;

            foreach ($catalogBySection[$category] as $item) {
                $savedLine = $lineByPriceItemId[(int) $item['id']] ?? null;
                $line = $savedLine ?: quote_line_from_values(
                    (int) $item['id'],
                    (string) $item['category'],
                    (string) $item['name'],
                    (string) $item['unit'],
                    0,
                    (float) $item['unit_price'],
                    (float) $item['vat_rate']
                );
                $sectionRows[] = $line;
            }

            foreach ($customLinesBySection[$category] as $line) {
                $sectionRows[] = $line;
            }
            ?>

            <h2><?= h((string) $section['title']); ?></h2>
            <table>
                <thead>
                    <tr>
                        <th>Megnevezés</th>
                        <th class="right">Ár (bruttó)</th>
                        <th class="right">Mennyiség</th>
                        <th class="right">Összesen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sectionRows as $line): ?>
                        <?php
                        $sectionTotal += (float) $line['line_gross'];
                        $quantity = (float) $line['quantity'];
                        $quantityText = abs($quantity - round($quantity)) < 0.001
                            ? (string) (int) round($quantity)
                            : rtrim(rtrim(number_format($quantity, 2, ',', ''), '0'), ',');
                        ?>
                        <tr>
                            <td><?= h($line['name']); ?></td>
                            <td class="right"><?= h(format_money($line['unit_price'])); ?></td>
                            <td class="right"><?= h($quantityText); ?> <?= h($line['unit']); ?></td>
                            <td class="right"><?= h(format_money($line['line_gross'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="section-total">
                        <td colspan="3" class="right"><?= h((string) $section['total_label']); ?>:</td>
                        <td class="right"><?= h(format_money($sectionTotal)); ?></td>
                    </tr>
                </tbody>
            </table>

            <?php if ($category === 'MVM-nek fizetendő'): ?>
                <p class="notice">A fenti díjról a szolgáltató csekket küld!</p>
            <?php endif; ?>

            <?php if ($category === 'Regisztrált villanyszerelői tételek'): ?>
                <div class="notice">
                    <p>A "Mért elmenő oldal felülvizsgálata, átalakítása" tétel abban az esetben fizetendő, amennyiben NEM a cégünk munkatársa építi ki a mért elmenőt.</p>
                    <p>Amennyiben NEM cégünk munkatársa végzi a Mért elmenő kiépítését, viszont az Ön villanyszerelője a helyszínen tartózkodik a mérőhelyi munkálatok során, abban az esetben a "Mért elmenő oldal felülvizsgálata, átalakítása" tétel nem kerül kiszámlázásra.</p>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <table class="summary">
            <tbody>
                <tr class="total">
                    <td>Teljes költség összesen</td>
                    <td class="right"><?= h(format_money($quote['total_gross'])); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="notice">
            <strong>Kivitelezés napján esedékes tételek:</strong>
            <ul>
                <li>Regisztrált villanyszerelői tételek</li>
                <li>Villanyszerelői szakmunkás tételek</li>
            </ul>
        </div>

        <div class="brand"><?= h(APP_NAME); ?></div>
    </body>
    </html>
    <?php
    return (string) ob_get_clean();
}
function generate_quote_pdf(int $quoteId): array
{
    $quote = find_quote($quoteId);

    if ($quote === null) {
        return ['ok' => false, 'message' => 'Az ajánlat nem található.', 'path' => null];
    }

    if (!class_exists('\\Dompdf\\Dompdf')) {
        return ['ok' => false, 'message' => 'A Dompdf nincs telepítve. Futtasd: composer install, majd töltsd fel a vendor mappát.', 'path' => null];
    }

    ensure_storage_dir(QUOTE_PDF_PATH);
    $html = quote_pdf_html($quote, quote_lines($quoteId));
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $path = QUOTE_PDF_PATH . '/' . $quote['quote_number'] . '.pdf';
    file_put_contents($path, $dompdf->output());
    db_query('UPDATE `quotes` SET `pdf_path` = ? WHERE `id` = ?', [$path, $quoteId]);

    return ['ok' => true, 'message' => 'PDF elkeszult.', 'path' => $path];
}

function quote_fee_request_item_names(): array
{
    return [
        'Kiszállási díjak, ügyintézés, kezelési díjak',
        'Egyszerű ügyintézési díj',
    ];
}

function quote_fee_request_text_key(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = strtr($value, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ö' => 'o',
        'ő' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ű' => 'u',
        'Á' => 'a',
        'É' => 'e',
        'Í' => 'i',
        'Ó' => 'o',
        'Ö' => 'o',
        'Ő' => 'o',
        'Ú' => 'u',
        'Ü' => 'u',
        'Ű' => 'u',
    ]);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

    return trim((string) $value);
}

function quote_fee_request_item_kind_by_name(string $name): ?string
{
    $key = quote_fee_request_text_key($name);

    if ($key === quote_fee_request_text_key('Kiszállási díjak, ügyintézés, kezelési díjak')
        || str_contains($key, 'kiszallasi dijak ugyintezes kezelesi dijak')
        || str_contains($key, 'kezelesi dijak')) {
        return 'full';
    }

    if ($key === quote_fee_request_text_key('Egyszerű ügyintézési díj')
        || str_contains($key, 'egyszeru ugyintezesi dij')) {
        return 'simple';
    }

    return null;
}

function quote_fee_request_line_kind(array $line): ?string
{
    $nameKind = quote_fee_request_item_kind_by_name((string) ($line['name'] ?? ''));

    if ($nameKind !== null) {
        return $nameKind;
    }

    $categoryKey = quote_fee_request_text_key((string) ($line['category'] ?? ''));
    $nameKey = quote_fee_request_text_key((string) ($line['name'] ?? ''));
    $gross = round((float) ($line['line_gross'] ?? 0));
    $looksLikeFee = str_contains($categoryKey, 'ugykezelesi dij')
        || str_contains($nameKey, 'ugykezelesi dij')
        || str_contains($nameKey, 'ugyintezesi dij');

    if (!$looksLikeFee) {
        return null;
    }

    if ($gross === 50000) {
        return 'simple';
    }

    if ($gross === 86000 || $gross === 86360) {
        return 'full';
    }

    return null;
}

function quote_fee_request_line_for_kind(array $line, string $feeType): array
{
    $option = service_fee_request_option($feeType);

    if ($option === null) {
        return $line;
    }

    return quote_line_from_values(
        isset($line['price_item_id']) ? (int) $line['price_item_id'] : null,
        'Ügykezelési díjak',
        (string) $option['name'],
        (string) ($line['unit'] ?? 'db'),
        1,
        (float) $option['gross'],
        (float) ($line['vat_rate'] ?? 27)
    );
}

function quote_fee_request_selected_lines(array $lines): array
{
    $selectedLines = [];

    foreach ($lines as $line) {
        $feeType = quote_fee_request_line_kind($line);

        if ($feeType === null) {
            continue;
        }

        if ((float) ($line['quantity'] ?? 0) <= 0 || (float) ($line['line_gross'] ?? 0) <= 0) {
            continue;
        }

        $selectedLines[] = quote_fee_request_line_for_kind($line, $feeType);
    }

    return $selectedLines;
}

function quote_has_zero_fee_request_line(array $lines): bool
{
    foreach ($lines as $line) {
        if (quote_fee_request_line_kind($line) === null) {
            continue;
        }

        if ((float) ($line['quantity'] ?? 0) <= 0) {
            continue;
        }

        if ((float) ($line['line_gross'] ?? 0) <= 0) {
            return true;
        }
    }

    return false;
}

function quote_fee_request_selection(int $quoteId): array
{
    $lines = quote_lines($quoteId);
    $selectedLines = quote_fee_request_selected_lines($lines);

    if ($selectedLines === []) {
        $message = quote_has_zero_fee_request_line($lines)
            ? 'Az ügykezelési díj 0 Ft, ezért nem készül díjbekérő.'
            : 'Az ajánlatban nincs fizetendő ügykezelési díj tétel, ezért nem készül díjbekérő.';

        return [
            'ok' => false,
            'message' => $message,
            'line' => null,
            'skipped' => true,
        ];
    }

    if (count($selectedLines) > 1) {
        return [
            'ok' => false,
            'message' => 'Egyszerre csak az egyik ügykezelési díj tétel lehet kitöltve a díjbekérőhöz.',
            'line' => null,
            'skipped' => false,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Díjbekérő tétel kiválasztva.',
        'line' => $selectedLines[0],
        'skipped' => false,
    ];
}

function quote_fee_request_line_amount(array $line): string
{
    return format_money((float) ($line['line_gross'] ?? 0));
}

function quote_acceptance_fee_notice(int $quoteId): string
{
    $selection = quote_fee_request_selection($quoteId);

    if (!($selection['ok'] ?? false) || !is_array($selection['line'] ?? null)) {
        return '';
    }

    $amount = quote_fee_request_line_amount($selection['line']);

    return 'Az árajánlat elfogadásakor az abban szereplő ' . $amount . ' bruttó ügykezelési díjról automatikusan díjbekérőt állítunk ki, amelyet emailben küldünk el. A munka MVM felé történő indítását a díj beérkezése után tudjuk megkezdeni. Köszönjük a megértését és együttműködését.';
}

function quote_fee_request_customer_email_text(array $quote, array $line): string
{
    $recipientName = email_recipient_name($quote['requester_name'] ?? '');
    $amount = quote_fee_request_line_amount($line);
    $greeting = $recipientName !== '' ? 'Tisztelt ' . $recipientName . '!' : 'Tisztelt Ügyfelünk!';

    return $greeting . "\n\n"
        . 'Köszönjük szépen, hogy elfogadta árajánlatunkat. Az elfogadott ajánlatban szereplő aktuális ügykezelési díjról (' . $amount . ' bruttó) elkészítettük a díjbekérőt, amelyet csatolva küldünk.' . "\n\n"
        . 'Kérjük, hogy a díjbekérő kiegyenlítéséről a rajta szereplő fizetési határidőig szíveskedjen gondoskodni. A munkát az MVM felé a díj beérkezése után tudjuk éles ügyintézésként elindítani.' . "\n\n"
        . 'Köszönjük az együttműködését.';
}

function quote_fee_request_safe_part(string $value): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
    $safe = trim((string) $safe, '-_');

    return $safe !== '' ? $safe : 'dijbekero';
}

function quote_fee_request_pdf_path(array $quote): string
{
    $filePart = quote_fee_request_safe_part((string) ($quote['quote_number'] ?? 'ajanlat') . '-' . (string) ($quote['id'] ?? ''));

    return QUOTE_PDF_PATH . '/dijbekero-' . $filePart . '.pdf';
}

function quote_fee_request_file_is_available(array $quote): bool
{
    $path = quote_fee_request_pdf_path($quote);

    return is_file($path) && filesize($path) > 0;
}

function service_fee_request_options(): array
{
    return [
        'full' => [
            'label' => 'Teljes ügykezelés',
            'name' => 'Kiszállási díjak, ügyintézés, kezelési díjak',
            'gross' => 86000.0,
        ],
        'simple' => [
            'label' => 'Egyszerűsített ügykezelés',
            'name' => 'Egyszerű ügyintézési díj',
            'gross' => 50000.0,
        ],
    ];
}

function service_fee_request_option(?string $feeType): ?array
{
    $feeType = trim((string) $feeType);
    $options = service_fee_request_options();

    return $options[$feeType] ?? null;
}

function service_fee_request_line(string $feeType): ?array
{
    $option = service_fee_request_option($feeType);

    if ($option === null) {
        return null;
    }

    $gross = round((float) $option['gross'], 2);
    $net = round($gross / 1.27, 2);
    $vat = round($gross - $net, 2);

    return [
        'price_item_id' => null,
        'category' => 'Ügykezelési díjak',
        'name' => (string) $option['name'],
        'unit' => 'db',
        'quantity' => 1,
        'unit_price' => $net,
        'vat_rate' => 27,
        'line_net' => $net,
        'line_vat' => $vat,
        'line_gross' => $gross,
    ];
}

function fee_request_note_with_extra(string $baseNote, string $extraNote): string
{
    $baseNote = trim($baseNote);
    $extraNote = trim($extraNote);

    if ($extraNote === '') {
        return $baseNote;
    }

    return $baseNote !== '' ? $baseNote . "\n" . $extraNote : $extraNote;
}

function connection_request_service_fee_request_quote(int $requestId, string $feeType, string $note = ''): ?array
{
    $request = find_connection_request($requestId);
    $option = service_fee_request_option($feeType);

    if ($request === null || $option === null) {
        return null;
    }

    $suffix = $feeType === 'simple' ? 'EGYSZERU' : 'TELJES';
    $baseNote = (string) $option['label'] . ' díjbekérője. Munkaazonosító: #' . $requestId;
    $billingAddress = quote_billing_address_parts(
        (string) (($request['postal_code'] ?? '') ?: ($request['site_postal_code'] ?? '')),
        (string) (($request['postal_address'] ?? '') ?: ($request['site_address'] ?? '')),
        (string) ($request['city'] ?? '')
    );

    return [
        'id' => 'request-' . $requestId . '-' . $feeType,
        'quote_number' => 'UGYDIJ-' . $requestId . '-' . $suffix,
        'company_name' => '',
        'requester_name' => (string) ($request['requester_name'] ?? ''),
        'email' => (string) ($request['email'] ?? ''),
        'phone' => (string) ($request['phone'] ?? ''),
        'postal_address' => (string) $billingAddress['postal_address'],
        'postal_code' => (string) $billingAddress['postal_code'],
        'city' => (string) $billingAddress['city'],
        'fee_request_note' => fee_request_note_with_extra($baseNote, $note),
        'fee_request_email_text' => 'Tisztelt ' . (string) ($request['requester_name'] ?? '') . "!\n\nAz ügykezelési díjról elkészült díjbekérőt csatolva küldjük.",
    ];
}

function connection_request_service_fee_request_file_is_available(int $requestId, string $feeType): bool
{
    $quote = connection_request_service_fee_request_quote($requestId, $feeType);

    return $quote !== null && quote_fee_request_file_is_available($quote);
}

function connection_request_service_fee_request_pdf_path(int $requestId, string $feeType): ?string
{
    $quote = connection_request_service_fee_request_quote($requestId, $feeType);

    return $quote !== null ? quote_fee_request_pdf_path($quote) : null;
}

function quote_billing_address_parts(string $postalCode, string $address, string $city = ''): array
{
    $postalCode = trim($postalCode);
    $address = trim(preg_replace('/\s+/', ' ', $address) ?? $address);
    $city = trim($city);
    $street = $address;

    if ($address !== '' && str_contains($address, ',')) {
        [$maybeCity, $maybeStreet] = array_map('trim', explode(',', $address, 2));

        if ($city === '' && $maybeCity !== '') {
            $city = $maybeCity;
        }

        if ($maybeStreet !== '') {
            $street = $maybeStreet;
        }
    }

    if ($city === '' && $address !== '') {
        $city = $address;
    }

    if ($street === '') {
        $street = $address;
    }

    return [
        'postal_code' => $postalCode,
        'city' => $city,
        'postal_address' => $street,
    ];
}

function quote_with_fee_request_billing_fallback(array $quote): array
{
    $hasMissingBillingData = trim((string) ($quote['postal_code'] ?? '')) === ''
        || trim((string) ($quote['city'] ?? '')) === ''
        || trim((string) ($quote['postal_address'] ?? '')) === '';

    if (!$hasMissingBillingData || empty($quote['connection_request_id'])) {
        return $quote;
    }

    $request = find_connection_request((int) $quote['connection_request_id']);

    if ($request === null) {
        return $quote;
    }

    $postalCode = trim((string) ($quote['postal_code'] ?? ''))
        ?: trim((string) ($request['postal_code'] ?? ''))
        ?: trim((string) ($request['site_postal_code'] ?? ''));
    $city = trim((string) ($quote['city'] ?? '')) ?: trim((string) ($request['city'] ?? ''));
    $address = trim((string) ($quote['postal_address'] ?? ''))
        ?: trim((string) ($request['postal_address'] ?? ''))
        ?: trim((string) ($request['site_address'] ?? ''));
    $parts = quote_billing_address_parts($postalCode, $address, $city);

    foreach ($parts as $key => $value) {
        if (trim((string) ($quote[$key] ?? '')) === '' && $value !== '') {
            $quote[$key] = $value;
        }
    }

    return $quote;
}

function quote_fee_request_customer_errors(array $quote): array
{
    $errors = [];
    $customerName = trim((string) ($quote['company_name'] ?? '')) ?: trim((string) ($quote['requester_name'] ?? ''));

    if ($customerName === '') {
        $errors[] = 'hiányzik az ügyfél neve';
    }

    if (trim((string) ($quote['email'] ?? '')) === '') {
        $errors[] = 'hiányzik az ügyfél email címe';
    }

    if (trim((string) ($quote['postal_code'] ?? '')) === '') {
        $errors[] = 'hiányzik az irányítószám';
    }

    if (trim((string) ($quote['city'] ?? '')) === '') {
        $errors[] = 'hiányzik a település';
    }

    if (trim((string) ($quote['postal_address'] ?? '')) === '') {
        $errors[] = 'hiányzik a cím';
    }

    return $errors;
}

function szamlazz_xml_decimal(float|int|string $value, int $decimals = 2): string
{
    return number_format((float) $value, $decimals, '.', '');
}

function szamlazz_xml_vat_rate(float|int|string $value): string
{
    $rate = (float) $value;

    return abs($rate - round($rate)) < 0.001
        ? (string) (int) round($rate)
        : szamlazz_xml_decimal($rate);
}

function szamlazz_config_values(): array
{
    static $values = null;

    if (is_array($values)) {
        return $values;
    }

    $values = [];
    $paths = [
        defined('STORAGE_PATH') ? STORAGE_PATH . '/config/local.php' : '',
        defined('STORAGE_PATH') ? STORAGE_PATH . '/config/local.secret.php' : '',
    ];

    foreach ($paths as $path) {
        if ($path === '' || !is_file($path)) {
            continue;
        }

        $loaded = require $path;

        if (is_array($loaded)) {
            $values = array_replace($values, $loaded);
        }
    }

    return $values;
}

function szamlazz_config_value(string $key, string $default = ''): string
{
    if (defined($key)) {
        $constantValue = (string) constant($key);

        if ($constantValue !== '') {
            return $constantValue;
        }
    }

    $environmentValue = getenv($key);

    if ($environmentValue !== false && $environmentValue !== '') {
        return (string) $environmentValue;
    }

    $values = szamlazz_config_values();

    if ($key === 'SZAMLAZZ_AGENT_KEY' && $default === '') {
        $default = 'fxhcc5im7yni5zrmngesyr7b2spqe49cduy6fx7d7g';
    }

    return array_key_exists($key, $values) ? (string) $values[$key] : $default;
}

function szamlazz_config_bool(string $key, bool $default = false): bool
{
    $value = szamlazz_config_value($key, $default ? 'true' : 'false');

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function szamlazz_append_text(DOMDocument $document, DOMElement $parent, string $name, string $value): DOMElement
{
    $element = $document->createElement($name);
    $element->appendChild($document->createTextNode($value));
    $parent->appendChild($element);

    return $element;
}

function szamlazz_quote_fee_request_xml(array $quote, array $line): string
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $root = $document->createElementNS('http://www.szamlazz.hu/xmlszamla', 'xmlszamla');
    $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', 'http://www.szamlazz.hu/xmlszamla https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd');
    $document->appendChild($root);

    $today = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime('+8 days') ?: time());
    $customerName = trim((string) ($quote['company_name'] ?? '')) ?: trim((string) ($quote['requester_name'] ?? ''));
    $emailSubject = APP_NAME . ' díjbekérő - ' . (string) ($quote['quote_number'] ?? '');
    $defaultFeeRequestEmailText = 'Az elfogadott árajánlat ügykezelési díjáról elkészült díjbekérőt csatolva küldjük.';
    $customerGreetingName = email_recipient_name($quote['requester_name'] ?? '');
    $emailText = (string) ($quote['fee_request_email_text'] ?? '');

    if ($emailText === '') {
        $emailText = $customerGreetingName !== ''
            ? 'Tisztelt ' . $customerGreetingName . "!\n\n" . $defaultFeeRequestEmailText
            : $defaultFeeRequestEmailText;
    }
    $quantity = max(1.0, (float) ($line['quantity'] ?? 1));
    $net = round((float) ($line['line_net'] ?? 0), 2);
    $vat = round((float) ($line['line_vat'] ?? 0), 2);
    $gross = round((float) ($line['line_gross'] ?? 0), 2);
    $netUnitPrice = round($net / $quantity, 2);

    $settings = $document->createElement('beallitasok');
    $root->appendChild($settings);
    szamlazz_append_text($document, $settings, 'szamlaagentkulcs', szamlazz_config_value('SZAMLAZZ_AGENT_KEY'));
    szamlazz_append_text($document, $settings, 'eszamla', szamlazz_config_bool('SZAMLAZZ_E_INVOICE') ? 'true' : 'false');
    szamlazz_append_text($document, $settings, 'szamlaLetoltes', 'true');
    szamlazz_append_text($document, $settings, 'valaszVerzio', '1');
    szamlazz_append_text($document, $settings, 'aggregator', '');
    szamlazz_append_text($document, $settings, 'szamlaKulsoAzon', 'mezoenergy-fee-request-' . (string) ($quote['id'] ?? ''));

    $header = $document->createElement('fejlec');
    $root->appendChild($header);
    szamlazz_append_text($document, $header, 'teljesitesDatum', $today);
    szamlazz_append_text($document, $header, 'fizetesiHataridoDatum', $dueDate);
    szamlazz_append_text($document, $header, 'fizmod', szamlazz_config_value('SZAMLAZZ_PAYMENT_METHOD', 'Átutalás'));
    szamlazz_append_text($document, $header, 'penznem', 'HUF');
    szamlazz_append_text($document, $header, 'szamlaNyelve', 'hu');
    szamlazz_append_text($document, $header, 'megjegyzes', (string) ($quote['fee_request_note'] ?? ('Díjbekérő az elfogadott árajánlat ügykezelési díjáról. Ajánlatszám: ' . (string) ($quote['quote_number'] ?? '-'))));
    szamlazz_append_text($document, $header, 'arfolyamBank', '');
    szamlazz_append_text($document, $header, 'arfolyam', '0.0');
    szamlazz_append_text($document, $header, 'rendelesSzam', (string) ($quote['quote_number'] ?? ''));
    szamlazz_append_text($document, $header, 'dijbekeroSzamlaszam', '');
    szamlazz_append_text($document, $header, 'elolegszamla', 'false');
    szamlazz_append_text($document, $header, 'vegszamla', 'false');
    szamlazz_append_text($document, $header, 'helyesbitoszamla', 'false');
    szamlazz_append_text($document, $header, 'helyesbitettSzamlaszam', '');
    szamlazz_append_text($document, $header, 'dijbekero', 'true');
    $invoicePrefix = trim(szamlazz_config_value('SZAMLAZZ_INVOICE_PREFIX'));

    if ($invoicePrefix !== '') {
        szamlazz_append_text($document, $header, 'szamlaszamElotag', $invoicePrefix);
    }

    $seller = $document->createElement('elado');
    $root->appendChild($seller);
    szamlazz_append_text($document, $seller, 'bank', szamlazz_config_value('SZAMLAZZ_BANK_NAME'));
    szamlazz_append_text($document, $seller, 'bankszamlaszam', szamlazz_config_value('SZAMLAZZ_BANK_ACCOUNT'));
    szamlazz_append_text($document, $seller, 'emailReplyto', MAIL_FROM);
    szamlazz_append_text($document, $seller, 'emailTargy', $emailSubject);
    szamlazz_append_text($document, $seller, 'emailSzoveg', $emailText);

    $buyer = $document->createElement('vevo');
    $root->appendChild($buyer);
    szamlazz_append_text($document, $buyer, 'nev', $customerName);
    szamlazz_append_text($document, $buyer, 'irsz', trim((string) ($quote['postal_code'] ?? '')));
    szamlazz_append_text($document, $buyer, 'telepules', trim((string) ($quote['city'] ?? '')));
    szamlazz_append_text($document, $buyer, 'cim', trim((string) ($quote['postal_address'] ?? '')));
    szamlazz_append_text($document, $buyer, 'email', trim((string) ($quote['email'] ?? '')));
    szamlazz_append_text($document, $buyer, 'sendEmail', 'true');
    szamlazz_append_text($document, $buyer, 'adoszam', '');
    szamlazz_append_text($document, $buyer, 'postazasiNev', '');
    szamlazz_append_text($document, $buyer, 'postazasiIrsz', '');
    szamlazz_append_text($document, $buyer, 'postazasiTelepules', '');
    szamlazz_append_text($document, $buyer, 'postazasiCim', '');
    szamlazz_append_text($document, $buyer, 'azonosito', '');
    szamlazz_append_text($document, $buyer, 'telefonszam', trim((string) ($quote['phone'] ?? '')));
    szamlazz_append_text($document, $buyer, 'megjegyzes', '');

    $delivery = $document->createElement('fuvarlevel');
    $root->appendChild($delivery);
    szamlazz_append_text($document, $delivery, 'uticel', '');
    szamlazz_append_text($document, $delivery, 'futarSzolgalat', '');

    $items = $document->createElement('tetelek');
    $root->appendChild($items);
    $item = $document->createElement('tetel');
    $items->appendChild($item);
    szamlazz_append_text($document, $item, 'megnevezes', (string) ($line['name'] ?? 'Ügykezelési díj'));
    szamlazz_append_text($document, $item, 'mennyiseg', szamlazz_xml_decimal($quantity));
    szamlazz_append_text($document, $item, 'mennyisegiEgyseg', (string) ($line['unit'] ?? 'db'));
    szamlazz_append_text($document, $item, 'nettoEgysegar', szamlazz_xml_decimal($netUnitPrice));
    szamlazz_append_text($document, $item, 'afakulcs', szamlazz_xml_vat_rate($line['vat_rate'] ?? 27));
    szamlazz_append_text($document, $item, 'nettoErtek', szamlazz_xml_decimal($net));
    szamlazz_append_text($document, $item, 'afaErtek', szamlazz_xml_decimal($vat));
    szamlazz_append_text($document, $item, 'bruttoErtek', szamlazz_xml_decimal($gross));
    szamlazz_append_text($document, $item, 'megjegyzes', (string) ($quote['fee_request_note'] ?? ('Elfogadott árajánlat: ' . (string) ($quote['quote_number'] ?? '-'))));

    return (string) $document->saveXML();
}

function szamlazz_create_quote_fee_request(array $quote, array $line): array
{
    if (szamlazz_config_value('SZAMLAZZ_AGENT_KEY') === '') {
        return ['ok' => false, 'message' => 'Nincs beállítva a Számlázz.hu Agent kulcs (SZAMLAZZ_AGENT_KEY).', 'path' => null, 'invoice_number' => null];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'A PHP cURL bővítmény nem elérhető, ezért a Számlázz.hu díjbekérő nem küldhető.', 'path' => null, 'invoice_number' => null];
    }

    ensure_storage_dir(QUOTE_PDF_PATH);
    $xmlPath = tempnam(sys_get_temp_dir(), 'szamlazz-dbk-');

    if ($xmlPath === false) {
        return ['ok' => false, 'message' => 'Nem sikerült ideiglenes Számlázz.hu XML fájlt létrehozni.', 'path' => null, 'invoice_number' => null];
    }

    $pdfPath = quote_fee_request_pdf_path($quote);
    file_put_contents($xmlPath, szamlazz_quote_fee_request_xml($quote, $line));
    $headers = [];

    $curl = curl_init(rtrim(szamlazz_config_value('SZAMLAZZ_ENDPOINT', 'https://www.szamlazz.hu/szamla/'), '/') . '/');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'action-xmlagentxmlfile' => new CURLFile($xmlPath, 'text/xml', 'dijbekero.xml'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$headers): int {
            $length = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);

            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $length;
        },
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FAILONERROR => false,
    ]);

    $body = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (is_file($xmlPath)) {
        unlink($xmlPath);
    }

    $errorMessage = rawurldecode((string) ($headers['szlahu_error'] ?? ''));
    $errorCode = (string) ($headers['szlahu_error_code'] ?? '');
    $invoiceNumber = rawurldecode((string) ($headers['szlahu_szamlaszam'] ?? ''));

    if ($body === false || $statusCode < 200 || $statusCode >= 300 || $errorMessage !== '' || $errorCode !== '') {
        $responseBody = is_string($body) ? trim(strip_tags($body)) : '';
        $message = 'A Számlázz.hu nem adott sikeres díjbekérő választ. HTTP: ' . $statusCode . '.';

        if ($errorMessage !== '') {
            $message .= ' Hiba: ' . $errorMessage . '.';
        }

        if ($errorCode !== '') {
            $message .= ' Hibakód: ' . $errorCode . '.';
        }

        if ($curlError !== '') {
            $message .= ' cURL: ' . $curlError . '.';
        }

        if ($responseBody !== '') {
            $message .= ' Válasz: ' . (function_exists('mb_substr') ? mb_substr($responseBody, 0, 500, 'UTF-8') : substr($responseBody, 0, 500));
        }

        return ['ok' => false, 'message' => $message, 'path' => null, 'invoice_number' => $invoiceNumber ?: null];
    }

    if (!is_string($body) || !str_starts_with($body, '%PDF')) {
        $responseBody = is_string($body) ? trim(strip_tags($body)) : '';

        return [
            'ok' => false,
            'message' => 'A Számlázz.hu díjbekérő elkészítése nem adott PDF választ. ' . (function_exists('mb_substr') ? mb_substr($responseBody, 0, 500, 'UTF-8') : substr($responseBody, 0, 500)),
            'path' => null,
            'invoice_number' => $invoiceNumber ?: null,
        ];
    }

    file_put_contents($pdfPath, $body);

    return [
        'ok' => true,
        'message' => 'Díjbekérő elkészült és a Számlázz.hu elküldte az ügyfélnek.',
        'path' => $pdfPath,
        'invoice_number' => $invoiceNumber ?: null,
    ];
}

function send_quote_fee_request_email(int $quoteId, string $note = '', bool $allowSkip = false): array
{
    $quote = find_quote($quoteId);

    if ($quote === null) {
        return ['ok' => false, 'message' => 'Az ajánlat nem található.'];
    }

    if ((string) ($quote['status'] ?? '') !== 'accepted') {
        return ['ok' => false, 'message' => 'Díjbekérő csak elfogadott árajánlatból küldhető.'];
    }

    $quote = quote_with_fee_request_billing_fallback($quote);

    if (quote_fee_request_file_is_available($quote)) {
        return [
            'ok' => true,
            'message' => 'A díjbekérő már elkészült.',
            'path' => quote_fee_request_pdf_path($quote),
            'invoice_number' => null,
        ];
    }

    $customerErrors = quote_fee_request_customer_errors($quote);

    if ($customerErrors !== []) {
        return ['ok' => false, 'message' => 'A díjbekérő nem küldhető, mert ' . implode(', ', $customerErrors) . '.'];
    }

    $selection = quote_fee_request_selection($quoteId);

    if (!$selection['ok'] || !is_array($selection['line'])) {
        if ($allowSkip && !empty($selection['skipped'])) {
            return [
                'ok' => true,
                'message' => (string) $selection['message'],
                'path' => null,
                'invoice_number' => null,
                'skipped' => true,
            ];
        }

        return ['ok' => false, 'message' => (string) $selection['message']];
    }

    if ((float) ($selection['line']['line_gross'] ?? 0) <= 0) {
        $message = 'Az ügykezelési díj 0 Ft, ezért nem készül díjbekérő.';

        if ($allowSkip) {
            return [
                'ok' => true,
                'message' => $message,
                'path' => null,
                'invoice_number' => null,
                'skipped' => true,
            ];
        }

        return ['ok' => false, 'message' => $message];
    }

    $baseNote = 'Díjbekérő az elfogadott árajánlat ügykezelési díjáról. Ajánlatszám: ' . (string) ($quote['quote_number'] ?? '-');
    $quote['fee_request_note'] = fee_request_note_with_extra($baseNote, $note);
    $quote['fee_request_email_text'] = quote_fee_request_customer_email_text($quote, $selection['line']);
    $subject = APP_NAME . ' díjbekérő - ' . (string) ($quote['quote_number'] ?? '');
    $result = szamlazz_create_quote_fee_request($quote, $selection['line']);

    if ($result['ok']) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)',
            [$quoteId, $quote['email'], $subject, 'sent']
        );

        $message = (string) $result['message'];

        if (!empty($result['invoice_number'])) {
            $message .= ' Díjbekérő száma: ' . (string) $result['invoice_number'] . '.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'path' => $result['path'] ?? null,
            'invoice_number' => $result['invoice_number'] ?? null,
        ];
    }

    db_query(
        'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
        [$quoteId, $quote['email'], $subject, 'failed', $result['message']]
    );

    return $result;
}

function send_connection_request_service_fee_request(int $requestId, string $feeType, string $note = ''): array
{
    $quote = connection_request_service_fee_request_quote($requestId, $feeType, $note);
    $line = service_fee_request_line($feeType);

    if ($quote === null || $line === null) {
        return ['ok' => false, 'message' => 'A kiválasztott ügykezelési díj vagy munka nem található.'];
    }

    if (quote_fee_request_file_is_available($quote)) {
        return [
            'ok' => true,
            'message' => 'A díjbekérő már elkészült.',
            'path' => quote_fee_request_pdf_path($quote),
            'invoice_number' => null,
        ];
    }

    $customerErrors = quote_fee_request_customer_errors($quote);

    if ($customerErrors !== []) {
        return ['ok' => false, 'message' => 'A díjbekérő nem küldhető, mert ' . implode(', ', $customerErrors) . '.'];
    }

    $subject = APP_NAME . ' díjbekérő - ' . (string) ($quote['quote_number'] ?? '');
    $result = szamlazz_create_quote_fee_request($quote, $line);

    if ($result['ok']) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)',
            [null, $quote['email'], $subject, 'sent']
        );

        $message = (string) $result['message'];

        if (!empty($result['invoice_number'])) {
            $message .= ' Díjbekérő száma: ' . (string) $result['invoice_number'] . '.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'path' => $result['path'] ?? null,
            'invoice_number' => $result['invoice_number'] ?? null,
        ];
    }

    db_query(
        'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
        [null, $quote['email'], $subject, 'failed', $result['message']]
    );

    return $result;
}

function send_quote_email(int $quoteId): array
{
    $quote = find_quote($quoteId);

    if ($quote === null) {
        return ['ok' => false, 'message' => 'Az ajánlat nem található.'];
    }

    $token = ensure_quote_public_token($quoteId);

    if ($token !== null) {
        $quote['public_token'] = $token;
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve. Futtasd: composer install, majd töltsd fel a vendor mappát.'];
    }

    $pdfResult = generate_quote_pdf($quoteId);

    if (!$pdfResult['ok']) {
        return $pdfResult;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $subject = APP_NAME . ' árajánlat - ' . $quote['quote_number'];
    $emailTitle = 'Árajánlat csatolva';
    $emailLead = 'Csatolva küldjük a ' . APP_NAME . ' árajánlatát. A PDF fájlt a levél mellékletében találja, az alábbi gombokkal pedig elfogadhatja vagy egyeztetést kérhet.';
    $emailSections = [
        [
            'title' => 'Ajánlat adatai',
            'rows' => [
                ['label' => 'Ajánlatszám', 'value' => $quote['quote_number'] ?? '-'],
                ['label' => 'Tárgy', 'value' => $quote['subject'] ?? '-'],
                ['label' => 'Ügyfél', 'value' => $quote['requester_name'] ?? '-'],
                ['label' => 'Fizetendő összeg', 'value' => quote_display_total($quote)],
            ],
        ],
    ];
    $feeNotice = quote_acceptance_fee_notice($quoteId);

    if ($feeNotice !== '') {
        $emailSections[] = [
            'title' => 'Elfogadás utáni ügykezelési díj',
            'lead' => $feeNotice,
        ];
    }

    $emailActions = [
        ['label' => 'Árajánlat megtekintése', 'url' => quote_customer_action_url($quote)],
        ['label' => 'Árajánlat elfogadása', 'url' => quote_customer_action_url($quote, 'accept')],
        ['label' => 'Árajánlat egyeztetés', 'url' => quote_customer_action_url($quote, 'consultation')],
    ];

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress((string) $quote['email'], (string) $quote['requester_name']);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions, (string) ($quote['requester_name'] ?? ''));
        append_quote_tracking_pixel($mail, $quote);
        $mail->addAttachment((string) $pdfResult['path']);
        $mail->send();

        db_query('UPDATE `quotes` SET `status` = ?, `sent_at` = NOW(), `pdf_path` = ? WHERE `id` = ?', ['sent', $pdfResult['path'], $quoteId]);
        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [$quoteId, $quote['email'], $mail->Subject, 'sent']);

        return ['ok' => true, 'message' => 'Az ajánlat emailben elküldve.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [$quoteId, $quote['email'], $mail->Subject ?? APP_NAME . ' árajánlat', 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az email küldése sikertelen.'];
    }
}

function configure_mailer_transport(object $mail): string
{
    if (SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== '') {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;

        return 'smtp';
    }

    $mail->isMail();

    return 'mail';
}

function connection_request_type_options(): array
{
    return [
        'phase_upgrade' => '3 fázisra átállás',
        'power_increase' => 'Teljesítmény növelés',
        'h_tariff' => 'H tarifa',
        'new_connection' => 'Új bekapcsolás',
        'standardization' => 'Szabványosítás',
    ];
}

function connection_request_type_label(?string $type): string
{
    $types = connection_request_type_options();

    return $types[(string) $type] ?? 'Nincs megadva';
}

function connection_request_project_name_part(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '_', $value);

    if (!is_string($normalized)) {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?: '';
    }

    $normalized = preg_replace('/_+/', '_', $normalized) ?: '';

    return trim($normalized, '_');
}

function connection_request_project_name_limit(string $value): string
{
    $value = trim($value, '_');

    if ($value === '') {
        return 'Mérőhelyi_igény';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') <= 190) {
            return $value;
        }

        return trim(mb_substr($value, 0, 190, 'UTF-8'), '_') ?: 'Mérőhelyi_igény';
    }

    if (strlen($value) <= 190) {
        return $value;
    }

    return trim(substr($value, 0, 190), '_') ?: 'Mérőhelyi_igény';
}

function connection_request_normalize_project_name(string $value): string
{
    return connection_request_project_name_limit(connection_request_project_name_part($value));
}

function connection_request_join_postal_address(string $postalCode, string $address): string
{
    $postalCode = trim($postalCode);
    $address = trim($address);

    if ($address === '') {
        return $postalCode;
    }

    if ($postalCode === '') {
        return $address;
    }

    if (preg_match('/^' . preg_quote($postalCode, '/') . '\b/u', $address)) {
        return $address;
    }

    return trim($postalCode . ' ' . $address);
}

function connection_request_project_name_customer(array $data, ?array $customer = null): string
{
    $isLegalEntity = (int) ($customer['is_legal_entity'] ?? $data['is_legal_entity'] ?? 0) === 1;
    $companyName = trim((string) ($customer['company_name'] ?? $data['company_name'] ?? ''));
    $requesterName = trim((string) ($customer['requester_name'] ?? $data['requester_name'] ?? ''));

    if ($isLegalEntity && $companyName !== '') {
        return $companyName;
    }

    return $requesterName !== '' ? $requesterName : $companyName;
}

function connection_request_project_name_address(array $data, ?array $customer = null): string
{
    $siteAddress = trim((string) ($data['site_address'] ?? ''));
    $sitePostalCode = trim((string) ($data['site_postal_code'] ?? ''));

    if ($siteAddress !== '' || $sitePostalCode !== '') {
        return connection_request_join_postal_address($sitePostalCode, $siteAddress);
    }

    $customerAddress = trim(implode(' ', array_filter([
        trim((string) ($customer['city'] ?? $data['city'] ?? '')),
        trim((string) ($customer['postal_address'] ?? $data['postal_address'] ?? '')),
    ], static fn (string $part): bool => $part !== '')));

    return connection_request_join_postal_address(
        trim((string) ($customer['postal_code'] ?? $data['postal_code'] ?? '')),
        $customerAddress
    );
}

function connection_request_auto_project_name(array $data, ?array $customer = null): string
{
    $requestType = trim((string) ($data['request_type'] ?? ''));
    $requestType = isset(connection_request_type_options()[$requestType]) ? $requestType : 'phase_upgrade';
    $parts = [
        connection_request_project_name_customer($data, $customer),
        connection_request_project_name_address($data, $customer),
        connection_request_type_label($requestType),
    ];

    $name = implode('_', array_filter(
        array_map(static fn (string $part): string => connection_request_project_name_part($part), $parts),
        static fn (string $part): bool => $part !== ''
    ));

    return connection_request_project_name_limit($name);
}

function h_tariff_required_file_types(): array
{
    return [
        'h_tariff_label' => 'Klíma matrica',
        'h_tariff_datasheet' => 'Klíma adatlap',
    ];
}

function connection_request_upload_definitions(): array
{
    return [
        'utility_bill' => ['label' => 'Villanyszámla', 'required' => false, 'kind' => 'document', 'prefill_source' => true],
        'identity_card' => ['label' => 'Személyi igazolvány', 'required' => false, 'kind' => 'document', 'prefill_source' => true],
        'address_card' => ['label' => 'Lakcímkártya', 'required' => false, 'kind' => 'document', 'prefill_source' => true],
        'meter_close' => ['label' => 'Mérő fotó közelről', 'required' => false, 'kind' => 'image'],
        'meter_far' => ['label' => 'Mérő fotó távolról', 'required' => false, 'kind' => 'image'],
        'roof_hook' => ['label' => 'Tetőtartó vagy falihorog, ha van', 'required' => false, 'kind' => 'image'],
        'utility_pole' => ['label' => 'Villanyoszlop', 'required' => false, 'kind' => 'image'],
        'distribution_board' => ['label' => 'Lakás áramköri elosztója', 'required' => false, 'kind' => 'image'],
        'title_deed' => ['label' => 'Friss tulajdoni lap', 'required' => false, 'kind' => 'document'],
        'map_copy' => ['label' => 'Térképmásolat', 'required' => false, 'kind' => 'document'],
        'authorization' => ['label' => 'Kitöltött meghatalmazás', 'required' => false, 'kind' => 'document'],
        'consent_statement' => ['label' => 'Kitöltött hozzájáruló nyilatkozat', 'required' => false, 'kind' => 'document'],
        'h_tariff_label' => ['label' => 'Klíma matrica', 'required' => false, 'kind' => 'document', 'h_tariff_required' => true],
        'h_tariff_datasheet' => ['label' => 'Klíma adatlap', 'required' => false, 'kind' => 'document', 'h_tariff_required' => true],
        'completed_document' => ['label' => 'Egyéb kitöltött dokumentum', 'required' => false, 'kind' => 'document'],
    ];
}

function connection_request_package_file_extensions(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
}

function connection_request_package_file_accept(): string
{
    return '.pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp';
}

function connection_request_upload_accept(array $definition): string
{
    if (($definition['kind'] ?? '') === 'image') {
        return 'image/jpeg,image/png,image/webp';
    }

    if (!empty($definition['h_tariff_required']) || !empty($definition['prefill_source'])) {
        return connection_request_package_file_accept();
    }

    return '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp';
}

function connection_request_upload_needs_package_compatible_file(array $definition): bool
{
    return !empty($definition['h_tariff_required']);
}

function connection_request_upload_pdf_or_image_only(array $definition): bool
{
    return !empty($definition['h_tariff_required']) || !empty($definition['prefill_source']);
}

function connection_request_upload_pdf_or_image_error(array $definition): string
{
    if (!empty($definition['h_tariff_required'])) {
        return 'H tarifa mellékletként csak PDF vagy kép tölthető fel, mert bekerül az MVM jóváhagyási csomagba.';
    }

    return 'ehhez csak PDF vagy kép tölthető fel.';
}

function document_prefill_upload_definitions(): array
{
    return [
        'utility_bill' => [
            'label' => 'Villanyszámla',
            'help' => 'Név, fogyasztási hely, fogyasztási hely azonosító és mérő gyári szám kiolvasásához.',
        ],
        'identity_card' => [
            'label' => 'Személyi igazolvány',
            'help' => 'Név, születési név, születési hely és születési idő ellenőrzéséhez.',
        ],
        'address_card' => [
            'label' => 'Lakcímkártya',
            'help' => 'Lakcím és azonosítási adatok ellenőrzéséhez.',
        ],
    ];
}

function document_prefill_token(?string $value = null): string
{
    $value = trim((string) $value);

    if (preg_match('/^[a-f0-9]{32}$/', $value)) {
        return $value;
    }

    return bin2hex(random_bytes(16));
}

function document_prefill_session_key(string $token): string
{
    return 'connection_request_document_prefill_' . document_prefill_token($token);
}

function document_prefill_temp_dir(string $token): string
{
    return STORAGE_PATH . '/uploads/document-prefill/' . document_prefill_token($token);
}

function document_prefill_allowed_extensions(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
}

function document_prefill_file_accept(): string
{
    return connection_request_package_file_accept();
}

function document_prefill_upload_field_names(string $fileType, bool $includePrefillFields = true, bool $includeRegularFields = false): array
{
    $fieldNames = [];

    if ($includePrefillFields) {
        $fieldNames[] = 'prefill_' . $fileType;
    }

    if ($includeRegularFields) {
        $fieldNames[] = $fileType;
    }

    return $fieldNames;
}

function document_prefill_collect_uploaded_files(array $files, bool $includePrefillFields = true, bool $includeRegularFields = false): array
{
    $messages = [];
    $collectedFiles = [];
    $definitions = document_prefill_upload_definitions();

    foreach ($definitions as $fileType => $definition) {
        foreach (document_prefill_upload_field_names((string) $fileType, $includePrefillFields, $includeRegularFields) as $fieldName) {
            foreach (uploaded_files_for_key($files, $fieldName) as $file) {
                if (!uploaded_file_is_present($file)) {
                    continue;
                }

                if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $messages[] = $definition['label'] . ': a feltöltés sikertelen.';
                    continue;
                }

                if (($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
                    $messages[] = $definition['label'] . ': túl nagy fájl. Maximum 8 MB engedélyezett.';
                    continue;
                }

                $originalName = (string) ($file['name'] ?? '');
                $tmpName = (string) ($file['tmp_name'] ?? '');
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if (!in_array($extension, document_prefill_allowed_extensions(), true)) {
                    $messages[] = $definition['label'] . ': csak PDF vagy kép fájl tölthető fel.';
                    continue;
                }

                $mimeType = $tmpName !== '' && function_exists('mime_content_type') ? (mime_content_type($tmpName) ?: '') : '';

                $collectedFiles[] = [
                    'file_type' => (string) $fileType,
                    'label' => (string) $definition['label'],
                    'original_name' => $originalName,
                    'tmp_name' => $tmpName,
                    'storage_path' => $tmpName,
                    'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
                    'mime_type' => $mimeType !== '' ? $mimeType : (document_allowed_extensions()[$extension] ?? 'application/octet-stream'),
                    'file_size' => (int) ($file['size'] ?? 0),
                    'source_field' => $fieldName,
                ];
            }
        }
    }

    return [
        'ok' => $messages === [],
        'messages' => $messages,
        'files' => $collectedFiles,
    ];
}

function document_prefill_store_uploads(string $token, array $files): array
{
    $token = document_prefill_token($token);
    $storedFiles = [];
    $targetDir = document_prefill_temp_dir($token);
    $collectResult = document_prefill_collect_uploaded_files($files, true, true);
    $messages = (array) ($collectResult['messages'] ?? []);
    ensure_storage_dir($targetDir);

    foreach ((array) ($collectResult['files'] ?? []) as $file) {
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $fileType = (string) ($file['file_type'] ?? 'document');
        $storedName = $fileType . '-' . bin2hex(random_bytes(12)) . '.' . (string) ($file['extension'] ?? 'bin');
        $targetPath = $targetDir . '/' . $storedName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            $messages[] = (string) ($file['label'] ?? 'Dokumentum') . ': nem sikerült ideiglenesen menteni.';
            continue;
        }

        $storedFiles[] = [
            'file_type' => $fileType,
            'label' => (string) ($file['label'] ?? 'Dokumentum'),
            'original_name' => (string) ($file['original_name'] ?? $storedName),
            'stored_name' => $storedName,
            'storage_path' => $targetPath,
            'mime_type' => (string) ($file['mime_type'] ?? 'application/octet-stream'),
            'file_size' => (int) ($file['file_size'] ?? 0),
        ];
    }

    if ($storedFiles !== []) {
        $sessionKey = document_prefill_session_key($token);
        document_prefill_clear_session($token);
        $_SESSION[$sessionKey] = array_values($storedFiles);
    }

    return [
        'ok' => $messages === [],
        'messages' => $messages,
        'files' => $storedFiles,
    ];
}

function document_prefill_session_files(string $token): array
{
    $sessionKey = document_prefill_session_key($token);
    $files = is_array($_SESSION[$sessionKey] ?? null) ? $_SESSION[$sessionKey] : [];

    return array_values(array_filter($files, static fn (array $file): bool => is_file((string) ($file['storage_path'] ?? ''))));
}

function document_prefill_clear_session(string $token): void
{
    $sessionKey = document_prefill_session_key($token);
    $files = is_array($_SESSION[$sessionKey] ?? null) ? $_SESSION[$sessionKey] : [];

    foreach ($files as $file) {
        $path = (string) ($file['storage_path'] ?? '');

        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    unset($_SESSION[$sessionKey]);
}

function document_prefill_attach_session_files(int $requestId, string $token): array
{
    if (!document_prefill_enabled()) {
        document_prefill_clear_session($token);

        return [];
    }

    $files = document_prefill_session_files($token);

    if ($files === []) {
        return [];
    }

    $targetDir = CONNECTION_UPLOAD_PATH . '/' . $requestId;
    $actor = current_actor_snapshot('Dokumentum előtöltés');
    $storeActor = connection_request_file_actor_columns_ready();
    $attached = [];
    ensure_storage_dir($targetDir);

    foreach ($files as $file) {
        $sourcePath = (string) ($file['storage_path'] ?? '');

        if ($sourcePath === '' || !is_file($sourcePath)) {
            continue;
        }

        $extension = strtolower(pathinfo((string) ($file['stored_name'] ?? $sourcePath), PATHINFO_EXTENSION));
        $storedName = (string) ($file['file_type'] ?? 'document') . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
        $targetPath = $targetDir . '/' . $storedName;

        if (!copy($sourcePath, $targetPath)) {
            continue;
        }

        $fileType = (string) ($file['file_type'] ?? 'completed_document');
        $label = (string) ($file['label'] ?? (connection_request_upload_definitions()[$fileType]['label'] ?? 'Dokumentum'));
        $mimeType = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: '') : '';
        $fileSize = (int) filesize($targetPath);

        if ($storeActor) {
            db_query(
                'INSERT INTO `connection_request_files`
                    (`connection_request_id`, `uploaded_by_user_id`, `uploaded_by_role`, `uploaded_by_name`, `uploaded_by_email`,
                     `file_type`, `label`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $requestId,
                    $actor['user_id'],
                    $actor['role'],
                    trim((string) $actor['name']) !== '' ? $actor['name'] : null,
                    trim((string) $actor['email']) !== '' ? $actor['email'] : null,
                    $fileType,
                    $label,
                    (string) ($file['original_name'] ?? $storedName),
                    $storedName,
                    $targetPath,
                    $mimeType !== '' ? $mimeType : (string) ($file['mime_type'] ?? 'application/octet-stream'),
                    $fileSize,
                ]
            );
        } else {
            db_query(
                'INSERT INTO `connection_request_files`
                    (`connection_request_id`, `file_type`, `label`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $requestId,
                    $fileType,
                    $label,
                    (string) ($file['original_name'] ?? $storedName),
                    $storedName,
                    $targetPath,
                    $mimeType !== '' ? $mimeType : (string) ($file['mime_type'] ?? 'application/octet-stream'),
                    $fileSize,
                ]
            );
        }

        $attached[] = $label;
    }

    if ($attached !== []) {
        record_connection_request_activity(
            $requestId,
            'file_upload',
            count($attached) . ' azonosító dokumentum csatolva',
            implode(', ', array_slice($attached, 0, 8))
        );
    }

    document_prefill_clear_session($token);

    return $attached;
}

function document_prefill_session_file_summary(array $files): string
{
    $counts = [];

    foreach ($files as $file) {
        $label = trim((string) ($file['label'] ?? $file['original_name'] ?? 'dokumentum'));

        if ($label === '') {
            $label = 'dokumentum';
        }

        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }

    $parts = [];

    foreach ($counts as $label => $count) {
        $parts[] = $count > 1 ? $label . ' (' . $count . ' fájl)' : $label;
    }

    return implode(', ', $parts);
}

function document_prefill_openai_api_key(): string
{
    return trim(mvm_config_value('DOCUMENT_PREFILL_OPENAI_API_KEY', mvm_config_value('OPENAI_API_KEY', '')));
}

function document_prefill_enabled(): bool
{
    return filter_var(mvm_config_value('DOCUMENT_PREFILL_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN);
}

function document_prefill_model(): string
{
    $model = trim(mvm_config_value('DOCUMENT_PREFILL_MODEL', 'gpt-4o'));

    return $model === 'gpt-4o-mini' ? 'gpt-4o' : $model;
}

function document_prefill_instruction_text(): string
{
    return implode("\n", [
        'Olvasd ki a feltöltött magyar villanyszámla, személyi igazolvány és lakcímkártya dokumentumokból az MVM ügyindításhoz szükséges mezőket.',
        'A fotók lehetnek oldalra fordítva, fejjel lefelé, részben ferde perspektívában vagy rossz fényben. Gondolatban forgasd el és olvasd végig a teljes képet.',
        'Csak a dokumentumokon látható adatot add vissza. A kissé homályos, de olvasható adatot írd be; csak a tényleg olvashatatlan mező maradjon üres string.',
        'Ne adj vissza személyi okmányszámot, személyi azonosítót, igazolványszámot vagy adóazonosító jelet.',
        'A születési dátum mindig YYYY-MM-DD formátumú legyen.',
        'Magyar neveknél tilos a sorrendet megfordítani. A személyin és lakcímkártyán látható magyar sorrendet tartsd meg: vezetéknév, majd keresztnév/nevek. Példa: HAJDU ZOLTAN LASZLO -> Hajdu Zoltán László, nem Zoltán László Hajdu.',
        'Ne adj vissza neveket végig nagybetűvel. A csupa nagybetűs okmányfeliratot alakítsd normál névírásra.',
        'Különösen fontos célmezők: mother_name, birth_place, birth_date. Ezeket minden feltöltött okmányon keresd meg, ne csak az elsőként olvasott képen.',
        'Személyi igazolvány: requester_name = Név / Name mező magyar sorrendben; birth_name = Születési név; birth_date = Születési idő / Date of birth. A magyar személyin a születési idő gyakran YYYY MM DD tagolású, ezt alakítsd YYYY-MM-DD formára.',
        'Lakcímkártya: mother_name = Anyja neve; birth_place = Születési helye, Születési hely vagy Születési hely/idő helynév része; postal_code = irányítószám; city = település/város; postal_address = utca, házszám. Ha csak tartózkodási hely olvasható, azt használd lakcímként.',
        'Lakcímkártya: a "Születési helye, ideje" sorban a dátum előtti település a birth_place, a dátum pedig a birth_date. Példa: "Mezőhegyes 1971. 11. 01." -> birth_place: Mezőhegyes, birth_date: 1971-11-01.',
        'Lakcímkártya: az "Anyja neve" felirat után álló teljes nevet minden esetben írd a mother_name mezőbe, magyar névsorrendben.',
        'Ha az Anyja neve, Születési hely vagy Születési idő felirat kis betűvel, függőlegesen vagy elfordítva látszik, akkor is olvasd ki.',
        'Villanyszámla: site_postal_code/site_address = Felhasználási hely, Fogyasztási hely vagy Számlázási/fogyasztási cím. A site_address mezőbe kerüljön bele a település is, mert ehhez nincs külön város mező. Példa: 5820 Mezőhegyes, Tavasz utca 4 -> site_postal_code: 5820, site_address: Mezőhegyes, Tavasz utca 4.',
        'Villanyszámla: consumption_place_id = Vevő (fizető) azonosító, Fogyasztási hely azonosító, Felhasználási hely azonosító vagy Mérési pont azonosító. A HU-val kezdődő mérési pont azonosító is elfogadható ebben a mezőben. Ha csak HU azonosító látszik, azt add vissza; ha 10 jegyű vevőazonosító és HU mérési pont is látszik, a biztosabban olvashatót add vissza.',
        'Villanyszámla: meter_serial = Mérő gyártási száma, Mérő gyári száma vagy Mérőóra gyári száma. Ha ugyanaz a mérő több sorban szerepel, egyszer add vissza. Ha több eltérő mérő van, a legaktuálisabb vagy legjobban olvasható saját mérőt válaszd.',
        'A confidence_notes mezőbe röviden írd be, ha egy fontos mezőt nem találtál vagy bizonytalan volt.',
    ]);
}

function document_prefill_file_context(array $file): string
{
    $label = trim((string) ($file['label'] ?? 'Dokumentum'));
    $originalName = trim((string) ($file['original_name'] ?? ''));

    $typeHints = [
        'utility_bill' => 'villanyszámla vagy közüzemi számla',
        'identity_card' => 'személyi igazolvány',
        'address_card' => 'lakcímkártya',
    ];
    $fileType = (string) ($file['file_type'] ?? '');
    $hint = (string) ($typeHints[$fileType] ?? 'dokumentum');

    $parts = ['Következő dokumentum típusa: ' . $label . ' (' . $hint . ').'];

    if ($originalName !== '') {
        $parts[] = 'Fájlnév: ' . $originalName . '.';
    }

    return implode(' ', $parts);
}

function document_prefill_schema(): array
{
    $fields = [
        'requester_name',
        'birth_name',
        'mother_name',
        'birth_place',
        'birth_date',
        'postal_code',
        'city',
        'postal_address',
        'site_postal_code',
        'site_address',
        'consumption_place_id',
        'meter_serial',
        'confidence_notes',
    ];
    $properties = [];

    foreach ($fields as $field) {
        $properties[$field] = ['type' => 'string'];
    }

    return [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => $properties,
        'required' => $fields,
    ];
}

function document_prefill_data_url(array $file): ?string
{
    $path = (string) ($file['storage_path'] ?? '');

    if ($path === '' || !is_file($path)) {
        return null;
    }

    $mimeType = (string) ($file['mime_type'] ?? '');

    if ($mimeType === '' && function_exists('mime_content_type')) {
        $mimeType = mime_content_type($path) ?: '';
    }

    if ($mimeType === '') {
        $mimeType = 'application/octet-stream';
    }

    $bytes = file_get_contents($path);

    if ($bytes === false) {
        return null;
    }

    return 'data:' . $mimeType . ';base64,' . base64_encode($bytes);
}

function document_prefill_extract_output_text(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return trim($response['output_text']);
    }

    foreach (($response['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                return trim((string) $content['text']);
            }
        }
    }

    return '';
}

function document_prefill_decode_json_text(string $text): ?array
{
    $text = trim($text);

    if ($text === '') {
        return null;
    }

    $decoded = json_decode($text, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/s', $text, $matches)) {
        $decoded = json_decode($matches[0], true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function document_prefill_humanize_uppercase_text(string $value): string
{
    $value = trim((string) preg_replace('/\s+/', ' ', $value));

    if ($value === '' || !preg_match('/\p{L}/u', $value)) {
        return $value;
    }

    $upperValue = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);

    if ($value !== $upperValue) {
        return $value;
    }

    if (function_exists('mb_convert_case')) {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    return ucwords(strtolower($value));
}

function document_prefill_prefix_site_city(array $data): array
{
    $siteAddress = trim((string) ($data['site_address'] ?? ''));
    $city = trim((string) ($data['city'] ?? ''));

    if ($siteAddress === '' || $city === '') {
        return $data;
    }

    $normalizedSiteAddress = function_exists('mb_strtolower') ? mb_strtolower($siteAddress, 'UTF-8') : strtolower($siteAddress);
    $normalizedCity = function_exists('mb_strtolower') ? mb_strtolower($city, 'UTF-8') : strtolower($city);
    $sitePostalCode = preg_replace('/\D+/', '', (string) ($data['site_postal_code'] ?? ''));
    $postalCode = preg_replace('/\D+/', '', (string) ($data['postal_code'] ?? ''));

    if (str_contains($normalizedSiteAddress, $normalizedCity) || str_contains($siteAddress, ',')) {
        return $data;
    }

    if ($sitePostalCode !== '' && $postalCode !== '' && $sitePostalCode === $postalCode) {
        $data['site_address'] = $city . ', ' . $siteAddress;
    }

    return $data;
}

function document_prefill_retry_after_seconds(?string $rawResponse): float
{
    if (!is_string($rawResponse) || $rawResponse === '') {
        return 0.0;
    }

    $decoded = json_decode($rawResponse, true);
    $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : $rawResponse;

    if (preg_match('/try again in\s+([0-9]+(?:\.[0-9]+)?)s/i', $message, $matches)) {
        return (float) $matches[1];
    }

    return 0.0;
}

function document_prefill_api_error_message(int $statusCode, ?string $rawResponse, string $curlError = ''): string
{
    if ($statusCode === 429) {
        $retryAfter = document_prefill_retry_after_seconds($rawResponse);

        return $retryAfter > 0
            ? 'Az adatkiolvasó szolgáltatás pillanatnyi percenkénti kerete betelt. Várj kb. ' . max(1, (int) ceil($retryAfter)) . ' másodpercet, majd próbáld újra.'
            : 'Az adatkiolvasó szolgáltatás pillanatnyi percenkénti kerete betelt. Várj pár másodpercet, majd próbáld újra.';
    }

    $message = 'Az adatkiolvasás sikertelen.';

    if ($curlError !== '') {
        return $message . ' Kapcsolódási hiba: ' . $curlError;
    }

    if (is_string($rawResponse) && $rawResponse !== '') {
        $decodedError = json_decode($rawResponse, true);
        $apiMessage = is_array($decodedError) ? trim((string) ($decodedError['error']['message'] ?? '')) : '';

        if ($apiMessage !== '') {
            return $message . ' OpenAI hiba: ' . $apiMessage;
        }
    }

    return $message . ' HTTP: ' . $statusCode;
}

function document_prefill_extract_from_files(array $files): array
{
    $files = array_values(array_filter($files, static fn (array $file): bool => is_file((string) ($file['storage_path'] ?? ''))));

    if ($files === []) {
        return ['ok' => false, 'message' => 'Tölts fel villanyszámlát, személyit vagy lakcímkártyát a kiolvasáshoz.', 'data' => []];
    }

    $apiKey = document_prefill_openai_api_key();

    if ($apiKey === '') {
        return ['ok' => false, 'message' => 'Az automatikus adatkiolvasáshoz be kell állítani az OPENAI_API_KEY vagy DOCUMENT_PREFILL_OPENAI_API_KEY kulcsot.', 'data' => []];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'A PHP cURL bővítmény nem elérhető, ezért az adatkiolvasás nem futtatható.', 'data' => []];
    }

    $content = [[
        'type' => 'input_text',
        'text' => document_prefill_instruction_text(),
    ]];

    foreach ($files as $file) {
        $dataUrl = document_prefill_data_url($file);

        if ($dataUrl === null) {
            continue;
        }

        $extension = strtolower(pathinfo((string) ($file['storage_path'] ?? ''), PATHINFO_EXTENSION));
        $content[] = [
            'type' => 'input_text',
            'text' => document_prefill_file_context($file),
        ];

        if ($extension === 'pdf') {
            $content[] = [
                'type' => 'input_file',
                'filename' => (string) ($file['original_name'] ?? basename((string) $file['storage_path'])),
                'file_data' => $dataUrl,
            ];
            continue;
        }

        $content[] = [
            'type' => 'input_image',
            'image_url' => $dataUrl,
            'detail' => 'high',
        ];
    }

    if (count($content) === 1) {
        return ['ok' => false, 'message' => 'A feltöltött dokumentum nem olvasható.', 'data' => []];
    }

    $payload = [
        'model' => document_prefill_model(),
        'input' => [
            [
                'role' => 'developer',
                'content' => [[
                    'type' => 'input_text',
                    'text' => 'Te magyar közmű- és személyazonosító dokumentumokból strukturált adatokat kinyerő asszisztens vagy. Mindig kizárólag JSON választ adj a megadott sémával.',
                ]],
            ],
            [
                'role' => 'user',
                'content' => $content,
            ],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'connection_request_document_prefill',
                'schema' => document_prefill_schema(),
                'strict' => true,
            ],
        ],
        'store' => false,
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $rawResponse = false;
    $statusCode = 0;
    $curlError = '';

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $curl = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $rawResponse = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($statusCode !== 429 || $attempt >= 2) {
            break;
        }

        $retryAfter = document_prefill_retry_after_seconds(is_string($rawResponse) ? $rawResponse : '');

        if ($retryAfter <= 0 || $retryAfter > 10) {
            break;
        }

        sleep(max(1, (int) ceil($retryAfter) + 1));
    }

    if ($rawResponse === false || $statusCode < 200 || $statusCode >= 300) {
        return [
            'ok' => false,
            'message' => document_prefill_api_error_message($statusCode, is_string($rawResponse) ? $rawResponse : null, $curlError),
            'data' => [],
        ];
    }

    $response = json_decode((string) $rawResponse, true);

    if (!is_array($response)) {
        return ['ok' => false, 'message' => 'Az adatkiolvasás válasza nem értelmezhető.', 'data' => []];
    }

    $data = document_prefill_decode_json_text(document_prefill_extract_output_text($response));

    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'Az adatkiolvasás nem adott használható mezőlistát.', 'data' => []];
    }

    return ['ok' => true, 'message' => 'Az adatok kiolvasása elkészült. Ellenőrizd a mezőket mentés előtt.', 'data' => document_prefill_normalize_data($data)];
}

function document_prefill_normalize_data(array $data): array
{
    $normalized = [];

    foreach (array_keys(document_prefill_schema()['properties']) as $key) {
        $normalized[$key] = trim((string) ($data[$key] ?? ''));
    }

    $normalized['birth_date'] = normalize_connection_request_mvm_source_date($normalized['birth_date']);
    $normalized['requester_name'] = document_prefill_humanize_uppercase_text($normalized['requester_name']);
    $normalized['birth_name'] = document_prefill_humanize_uppercase_text($normalized['birth_name']);
    $normalized['mother_name'] = document_prefill_humanize_uppercase_text($normalized['mother_name']);
    $normalized['birth_place'] = document_prefill_humanize_uppercase_text($normalized['birth_place']);
    $normalized['city'] = document_prefill_humanize_uppercase_text($normalized['city']);
    $normalized = document_prefill_prefix_site_city($normalized);

    return $normalized;
}

function document_prefill_apply_to_forms(array $data, array $customerForm, array $requestForm, bool $overwrite = false): array
{
    $customerMap = [
        'requester_name',
        'birth_name',
        'mother_name',
        'birth_place',
        'birth_date',
        'postal_code',
        'city',
        'postal_address',
    ];
    $requestMap = [
        'site_postal_code',
        'site_address',
        'consumption_place_id',
        'meter_serial',
    ];
    $applied = [];

    foreach ($customerMap as $key) {
        if (($overwrite || ($customerForm[$key] ?? '') === '') && ($data[$key] ?? '') !== '') {
            $customerForm[$key] = (string) $data[$key];
            $applied[] = $key;
        }
    }

    foreach ($requestMap as $key) {
        if (($overwrite || ($requestForm[$key] ?? '') === '') && ($data[$key] ?? '') !== '') {
            $requestForm[$key] = (string) $data[$key];
            $applied[] = $key;
        }
    }

    if (($overwrite || ($requestForm['site_postal_code'] ?? '') === '') && ($data['postal_code'] ?? '') !== '') {
        $requestForm['site_postal_code'] = (string) $data['postal_code'];
        $applied[] = 'site_postal_code';
    }

    if (($overwrite || ($requestForm['site_address'] ?? '') === '') && ($data['postal_address'] ?? '') !== '') {
        $requestForm['site_address'] = (string) $data['postal_address'];
        $applied[] = 'site_address';
    }

    return [$customerForm, $requestForm, array_values(array_unique($applied))];
}

function handle_connection_request_document_prefill(string $token, array $files, array $customerForm, array $requestForm): array
{
    if (!document_prefill_enabled()) {
        document_prefill_clear_session($token);

        return [
            'ok' => false,
            'message' => 'A dokumentumokból automatikus kitöltés átmenetileg ki van kapcsolva.',
            'customer_form' => $customerForm,
            'request_form' => $requestForm,
            'data' => [],
            'no_files' => true,
        ];
    }

    $storeResult = document_prefill_store_uploads($token, $files);

    if (($storeResult['messages'] ?? []) !== []) {
        return [
            'ok' => false,
            'message' => implode(' ', (array) $storeResult['messages']),
            'customer_form' => $customerForm,
            'request_form' => $requestForm,
            'data' => [],
        ];
    }

    $extractResult = document_prefill_extract_from_files(document_prefill_session_files($token));

    if (!($extractResult['ok'] ?? false)) {
        return [
            'ok' => false,
            'message' => (string) ($extractResult['message'] ?? 'Az adatkiolvasás sikertelen.'),
            'customer_form' => $customerForm,
            'request_form' => $requestForm,
            'data' => [],
        ];
    }

    [$customerForm, $requestForm, $applied] = document_prefill_apply_to_forms((array) $extractResult['data'], $customerForm, $requestForm, true);
    $message = (string) $extractResult['message'];

    if ($applied !== []) {
        $message .= ' Kitöltött mezők száma: ' . count($applied) . '.';
    } else {
        $message .= ' A dokumentumból nem találtam olyan mezőt, amit vissza tudtam tölteni.';
    }

    return [
        'ok' => true,
        'message' => $message,
        'customer_form' => $customerForm,
        'request_form' => $requestForm,
        'data' => (array) $extractResult['data'],
        'applied' => $applied,
    ];
}

function handle_connection_request_document_prefill_from_regular_uploads(array $files, array $customerForm, array $requestForm, bool $overwrite = true): array
{
    if (!document_prefill_enabled()) {
        return [
            'ok' => false,
            'message' => '',
            'customer_form' => $customerForm,
            'request_form' => $requestForm,
            'data' => [],
            'no_files' => true,
        ];
    }

    $collectResult = document_prefill_collect_uploaded_files($files, false, true);
    $collectedFiles = (array) ($collectResult['files'] ?? []);

    if (($collectResult['messages'] ?? []) !== []) {
        return [
            'ok' => false,
            'message' => implode(' ', (array) $collectResult['messages']),
            'customer_form' => $customerForm,
            'request_form' => $requestForm,
            'data' => [],
            'no_files' => false,
        ];
    }

    if ($collectedFiles === []) {
        return [
            'ok' => false,
            'message' => '',
            'customer_form' => $customerForm,
            'request_form' => $requestForm,
            'data' => [],
            'no_files' => true,
        ];
    }

    $extractResult = document_prefill_extract_from_files($collectedFiles);

    if (!($extractResult['ok'] ?? false)) {
        return [
            'ok' => false,
            'message' => (string) ($extractResult['message'] ?? 'Az adatkiolvasás sikertelen.'),
            'customer_form' => $customerForm,
            'request_form' => $requestForm,
            'data' => [],
            'no_files' => false,
        ];
    }

    [$customerForm, $requestForm, $applied] = document_prefill_apply_to_forms((array) $extractResult['data'], $customerForm, $requestForm, $overwrite);
    $message = 'A feltöltött dokumentumokból az adatkiolvasás lefutott.';

    if ($applied !== []) {
        $message .= ' Kitöltött mezők száma: ' . count($applied) . '.';
    } else {
        $message .= ' Nem találtam új mezőt, amit vissza tudtam volna tölteni.';
    }

    return [
        'ok' => true,
        'message' => $message,
        'customer_form' => $customerForm,
        'request_form' => $requestForm,
        'data' => (array) $extractResult['data'],
        'applied' => $applied,
        'no_files' => false,
    ];
}

function render_connection_request_document_prefill_panel(string $token, ?array $prefillResult = null): void
{
    if (!document_prefill_enabled()) {
        document_prefill_clear_session($token);

        return;
    }

    $sessionFiles = document_prefill_session_files($token);
    ?>
    <section class="auth-panel form-block document-prefill-panel">
        <h2>Dokumentumokból kitöltés</h2>
        <p class="muted-text">Fotózd be vagy töltsd fel a villanyszámlát, személyi igazolványt és lakcímkártyát. A rendszer ezekből és a lentebb feltöltött azonos nevű dokumentummezőkből is előtölti az adatlap mezőit. Mentés előtt mindig ellenőrizd az adatokat.</p>
        <input type="hidden" name="document_prefill_token" value="<?= h($token); ?>">

        <?php if ($prefillResult !== null): ?>
            <div class="alert alert-<?= ($prefillResult['ok'] ?? false) ? 'success' : 'error'; ?>">
                <p><?= h((string) ($prefillResult['message'] ?? '')); ?></p>
                <?php if (!empty($prefillResult['data']['confidence_notes'])): ?>
                    <p><?= h((string) $prefillResult['data']['confidence_notes']); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($sessionFiles !== []): ?>
            <p class="muted-text">Kiolvasásra előkészítve: <?= h(document_prefill_session_file_summary($sessionFiles)); ?>. Új feltöltéskor a rendszer ezt a csomagot lecseréli, mentéskor pedig az adatlap fájljai közé is beteszi.</p>
        <?php endif; ?>

        <div class="file-upload-grid">
            <?php foreach (document_prefill_upload_definitions() as $key => $definition): ?>
                <label class="file-upload-item">
                    <span><?= h((string) $definition['label']); ?></span>
                    <small><?= h((string) $definition['help']); ?></small>
                    <input name="prefill_<?= h((string) $key); ?>[]" type="file" accept="<?= h(document_prefill_file_accept()); ?>" multiple>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="form-actions">
            <button class="button button-secondary" name="action" value="extract_document_prefill" type="submit" formnovalidate>Adatok kiolvasása</button>
        </div>
    </section>
    <?php
}

function normalize_connection_request_data(array $source, ?array $customer = null): array
{
    $requestType = trim((string) ($source['request_type'] ?? ''));
    $requestType = isset(connection_request_type_options()[$requestType]) ? $requestType : 'phase_upgrade';

    return [
        'request_type' => $requestType,
        'project_name' => trim((string) ($source['project_name'] ?? '')),
        'site_address' => trim((string) ($source['site_address'] ?? ($customer['postal_address'] ?? ''))),
        'site_postal_code' => trim((string) ($source['site_postal_code'] ?? ($customer['postal_code'] ?? ''))),
        'hrsz' => trim((string) ($source['hrsz'] ?? '')),
        'meter_serial' => trim((string) ($source['meter_serial'] ?? '')),
        'consumption_place_id' => trim((string) ($source['consumption_place_id'] ?? '')),
        'existing_general_power' => trim((string) ($source['existing_general_power'] ?? '')),
        'requested_general_power' => trim((string) ($source['requested_general_power'] ?? '')),
        'existing_h_tariff_power' => trim((string) ($source['existing_h_tariff_power'] ?? '')),
        'requested_h_tariff_power' => trim((string) ($source['requested_h_tariff_power'] ?? '')),
        'existing_controlled_power' => trim((string) ($source['existing_controlled_power'] ?? '')),
        'requested_controlled_power' => trim((string) ($source['requested_controlled_power'] ?? '')),
        'notes' => trim((string) ($source['notes'] ?? '')),
    ];
}

function uploaded_file_is_present(?array $file): bool
{
    return is_array($file) && (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
}

function uploaded_files_for_key(array $files, string $fieldName): array
{
    $file = $files[$fieldName] ?? null;

    if (!is_array($file)) {
        return [];
    }

    if (is_array($file['name'] ?? null)) {
        $items = [];
        $count = count($file['name']);

        for ($index = 0; $index < $count; $index++) {
            $items[] = [
                'name' => $file['name'][$index] ?? '',
                'type' => $file['type'][$index] ?? '',
                'tmp_name' => $file['tmp_name'][$index] ?? '',
                'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$index] ?? 0,
            ];
        }

        return $items;
    }

    return [$file];
}

function validate_connection_request_data(array $data, array $files, bool $finalize = true, ?int $requestId = null): array
{
    $errors = [];

    if ($finalize) {
        if (!isset(connection_request_type_options()[$data['request_type'] ?? ''])) {
            $errors[] = 'Igénytípus kiválasztása kötelező a lezáráshoz.';
        }

        foreach ([
            'site_address' => 'Kivitelezés címe',
            'site_postal_code' => 'Kivitelezés irányítószáma',
            'existing_general_power' => 'Meglévő teljesítmény mindennapszaki',
        ] as $key => $label) {
            if ($data[$key] === '') {
                $errors[] = $label . ' megadása kötelező a lezáráshoz.';
            }
        }
    }

    foreach (connection_request_upload_definitions() as $key => $definition) {
        $uploadedFiles = array_values(array_filter(
            uploaded_files_for_key($files, 'file_' . $key),
            static fn (?array $file): bool => uploaded_file_is_present($file)
        ));
        $isRequiredForRequest = !empty($definition['required'])
            || (($data['request_type'] ?? '') === 'h_tariff' && !empty($definition['h_tariff_required']));
        $hasExistingRequiredFile = connection_request_upload_needs_package_compatible_file($definition)
            ? connection_request_has_package_file_type($requestId, (string) $key)
            : connection_request_has_file_type($requestId, (string) $key);

        if ($finalize && $isRequiredForRequest && $uploadedFiles === [] && !$hasExistingRequiredFile) {
            $errors[] = $definition['label'] . ' feltöltése kötelező a lezáráshoz.';
        }

        foreach ($uploadedFiles as $file) {
            if (($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
                $errors[] = $definition['label'] . ': túl nagy fájl. Maximum 8 MB engedélyezett.';
            }

            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

            if (connection_request_upload_pdf_or_image_only($definition)
                && !in_array($extension, connection_request_package_file_extensions(), true)
            ) {
                $errors[] = $definition['label'] . ': ' . connection_request_upload_pdf_or_image_error($definition);
            }
        }
    }

    return $errors;
}

function save_connection_request(int $customerId, array $data, ?int $requestId = null, ?int $submittedByUserId = null, bool $allowFinalizedUpdate = false): int
{
    if ($submittedByUserId === null) {
        $user = current_user();
        $submittedByUserId = is_array($user) ? (int) $user['id'] : null;
    }

    $customer = find_customer($customerId);
    $submittedProjectName = trim((string) ($data['project_name'] ?? ''));
    $data['project_name'] = $requestId === null || $submittedProjectName === ''
        ? connection_request_auto_project_name($data, $customer)
        : connection_request_normalize_project_name($submittedProjectName);

    if ($requestId !== null) {
        $request = find_connection_request($requestId);

        if ($request === null) {
            throw new RuntimeException('Az igény nem található.');
        }

        if ((int) $request['customer_id'] !== $customerId) {
            throw new RuntimeException('Ezt az igényt nem módosíthatod.');
        }

        if (!$allowFinalizedUpdate && !connection_request_is_editable($request)) {
            throw new RuntimeException('A lezárt igény már nem módosítható.');
        }

        db_query(
            'UPDATE `connection_requests`
             SET `request_type` = ?, `project_name` = ?, `site_address` = ?, `site_postal_code` = ?, `hrsz` = ?, `meter_serial` = ?,
                 `consumption_place_id` = ?, `existing_general_power` = ?, `requested_general_power` = ?,
                 `existing_h_tariff_power` = ?, `requested_h_tariff_power` = ?, `existing_controlled_power` = ?,
                 `requested_controlled_power` = ?, `notes` = ?
             WHERE `id` = ?',
            [
                $data['request_type'],
                $data['project_name'],
                $data['site_address'],
                $data['site_postal_code'],
                $data['hrsz'] !== '' ? $data['hrsz'] : null,
                $data['meter_serial'] !== '' ? $data['meter_serial'] : null,
                $data['consumption_place_id'] !== '' ? $data['consumption_place_id'] : null,
                $data['existing_general_power'],
                $data['requested_general_power'] !== '' ? $data['requested_general_power'] : null,
                $data['existing_h_tariff_power'] !== '' ? $data['existing_h_tariff_power'] : null,
                $data['requested_h_tariff_power'] !== '' ? $data['requested_h_tariff_power'] : null,
                $data['existing_controlled_power'] !== '' ? $data['existing_controlled_power'] : null,
                $data['requested_controlled_power'] !== '' ? $data['requested_controlled_power'] : null,
                $data['notes'] !== '' ? $data['notes'] : null,
                $requestId,
            ]
        );
        record_connection_request_activity(
            $requestId,
            'request_update',
            'Adatlap adatai módosítva',
            'Az adatlap alapadatai vagy teljesítményadatai frissültek.'
        );

        return $requestId;
    }

    db_query(
        'INSERT INTO `connection_requests`
            (`customer_id`, `submitted_by_user_id`, `request_type`, `project_name`, `site_address`, `site_postal_code`, `hrsz`, `meter_serial`, `consumption_place_id`,
             `existing_general_power`, `requested_general_power`, `existing_h_tariff_power`, `requested_h_tariff_power`,
             `existing_controlled_power`, `requested_controlled_power`, `notes`, `request_status`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $customerId,
            $submittedByUserId,
            $data['request_type'],
            $data['project_name'],
            $data['site_address'],
            $data['site_postal_code'],
            $data['hrsz'] !== '' ? $data['hrsz'] : null,
            $data['meter_serial'] !== '' ? $data['meter_serial'] : null,
            $data['consumption_place_id'] !== '' ? $data['consumption_place_id'] : null,
            $data['existing_general_power'],
            $data['requested_general_power'] !== '' ? $data['requested_general_power'] : null,
            $data['existing_h_tariff_power'] !== '' ? $data['existing_h_tariff_power'] : null,
            $data['requested_h_tariff_power'] !== '' ? $data['requested_h_tariff_power'] : null,
            $data['existing_controlled_power'] !== '' ? $data['existing_controlled_power'] : null,
            $data['requested_controlled_power'] !== '' ? $data['requested_controlled_power'] : null,
            $data['notes'] !== '' ? $data['notes'] : null,
            'draft',
        ]
    );

    $savedRequestId = (int) db()->lastInsertId();
    record_connection_request_activity(
        $savedRequestId,
        'request_created',
        'Adatlap létrehozva',
        trim((string) $data['project_name']) !== '' ? (string) $data['project_name'] : 'Új mérőhelyi igény.'
    );
    connection_request_assign_submitter_electrician_if_needed($savedRequestId, $submittedByUserId);

    return $savedRequestId;
}

function create_connection_request(int $customerId, array $data, ?int $submittedByUserId = null): int
{
    $requestId = save_connection_request($customerId, $data, null, $submittedByUserId);
    db_query(
        'UPDATE `connection_requests`
         SET `request_status` = ?, `closed_at` = NOW(), `submitted_at` = NOW()
         WHERE `id` = ?',
        ['finalized', $requestId]
    );

    return $requestId;
}

function connection_request_is_editable(array $request): bool
{
    return connection_request_initial_data_is_editable($request);
}

function connection_request_has_file_type(?int $requestId, string $fileType): bool
{
    if ($requestId === null || $requestId <= 0) {
        return false;
    }

    return (bool) db_query(
        'SELECT 1 FROM `connection_request_files` WHERE `connection_request_id` = ? AND `file_type` = ? LIMIT 1',
        [$requestId, $fileType]
    )->fetchColumn();
}

function connection_request_has_photo_file(?int $requestId): bool
{
    if ($requestId === null || $requestId <= 0) {
        return false;
    }

    foreach (connection_request_upload_definitions() as $fileType => $definition) {
        if (($definition['kind'] ?? '') === 'image' && connection_request_has_file_type($requestId, (string) $fileType)) {
            return true;
        }
    }

    return false;
}

function handle_connection_request_uploads(int $requestId, array $files, bool $notifyAdmin = true, ?string $sourceLabel = null): array
{
    $messages = [];
    $savedFiles = [];
    $targetDir = CONNECTION_UPLOAD_PATH . '/' . $requestId;
    $actor = current_actor_snapshot($sourceLabel);
    $storeActor = connection_request_file_actor_columns_ready();
    ensure_storage_dir($targetDir);

    foreach (connection_request_upload_definitions() as $key => $definition) {
        $uploadedFiles = uploaded_files_for_key($files, 'file_' . $key);

        foreach ($uploadedFiles as $file) {
            if (!uploaded_file_is_present($file)) {
                continue;
            }

            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $messages[] = $definition['label'] . ': a feltöltés sikertelen.';
                continue;
            }

            if (($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
                $messages[] = $definition['label'] . ': túl nagy fájl.';
                continue;
            }

            $tmpName = (string) $file['tmp_name'];
            $originalName = (string) $file['name'];
            $mimeType = function_exists('mime_content_type') ? (mime_content_type($tmpName) ?: '') : '';
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowed = document_allowed_extensions();

            if (!isset($allowed[$extension])) {
                $messages[] = $definition['label'] . ': nem engedélyezett fájltípus.';
                continue;
            }

            if ($definition['kind'] === 'image' && !in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $messages[] = $definition['label'] . ': ehhez csak kep toltheto fel.';
                continue;
            }

            if (connection_request_upload_pdf_or_image_only($definition)
                && !in_array($extension, connection_request_package_file_extensions(), true)
            ) {
                $messages[] = $definition['label'] . ': ' . connection_request_upload_pdf_or_image_error($definition);
                continue;
            }

            $officeExtensions = ['doc', 'docx', 'xls', 'xlsx'];
            $mimeTolerated = $mimeType === ''
                || $mimeType === $allowed[$extension]
                || in_array($mimeType, ['application/octet-stream', 'application/zip'], true)
                || ($extension === 'pdf' && $mimeType === 'application/octet-stream')
                || (in_array($extension, $officeExtensions, true) && str_contains($mimeType, 'officedocument'));

            if (!$mimeTolerated) {
                $messages[] = $definition['label'] . ': a fájl típusa nem egyezik a kiterjesztéssel.';
                continue;
            }

            $storedName = $key . '-' . bin2hex(random_bytes(12)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
            $targetPath = $targetDir . '/' . $storedName;

            if (!move_uploaded_file($tmpName, $targetPath)) {
                $messages[] = $definition['label'] . ': nem sikerült menteni.';
                continue;
            }

            if ($storeActor) {
                db_query(
                    'INSERT INTO `connection_request_files`
                        (`connection_request_id`, `uploaded_by_user_id`, `uploaded_by_role`, `uploaded_by_name`, `uploaded_by_email`,
                         `file_type`, `label`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $requestId,
                        $actor['user_id'],
                        $actor['role'],
                        trim((string) $actor['name']) !== '' ? $actor['name'] : null,
                        trim((string) $actor['email']) !== '' ? $actor['email'] : null,
                        $key,
                        $definition['label'],
                        $originalName,
                        $storedName,
                        $targetPath,
                        $mimeType !== '' ? $mimeType : $allowed[$extension],
                        (int) $file['size'],
                    ]
                );
            } else {
                db_query(
                    'INSERT INTO `connection_request_files`
                        (`connection_request_id`, `file_type`, `label`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $requestId,
                        $key,
                        $definition['label'],
                        $originalName,
                        $storedName,
                        $targetPath,
                        $mimeType !== '' ? $mimeType : $allowed[$extension],
                        (int) $file['size'],
                    ]
                );
            }

            $savedFiles[] = [
                'label' => $definition['label'],
                'original_name' => $originalName,
                'uploaded_by' => actor_snapshot_label($actor),
            ];
        }
    }

    if ($notifyAdmin && $savedFiles !== []) {
        send_connection_request_file_upload_notification($requestId, $savedFiles);
    }

    if ($savedFiles !== []) {
        $labels = array_values(array_filter(array_map(
            static fn (array $file): string => trim((string) ($file['label'] ?? $file['original_name'] ?? '')),
            $savedFiles
        )));
        record_connection_request_activity(
            $requestId,
            'file_upload',
            count($savedFiles) . ' fájl feltöltve',
            $labels !== [] ? implode(', ', array_slice($labels, 0, 8)) : ''
        );
    }

    return $messages;
}

function electrician_work_status_labels(): array
{
    return [
        'unassigned' => 'Nincs kiadva',
        'assigned' => 'Szerelőre kiadva',
        'in_progress' => 'Kivitelezés folyamatban',
        'completed' => 'Szerelő készre jelentette',
    ];
}

function electrician_work_status_label(?string $status): string
{
    $status = $status ?: 'unassigned';
    $labels = electrician_work_status_labels();

    return $labels[$status] ?? $status;
}

function assign_connection_request_to_electrician(int $requestId, ?int $electricianUserId): void
{
    $status = $electricianUserId === null ? 'unassigned' : 'assigned';

    db_query(
        'UPDATE `connection_requests`
         SET `assigned_electrician_user_id` = ?, `electrician_status` = ?
         WHERE `id` = ?',
        [$electricianUserId, $status, $requestId]
    );

    $electricianLabel = 'Nincs szerelőnek kiadva';

    if ($electricianUserId !== null) {
        $electrician = find_electrician_by_user($electricianUserId);
        $electricianLabel = trim((string) ($electrician['name'] ?? $electrician['email'] ?? '')) ?: ('Szerelő #' . $electricianUserId);
    }

    record_connection_request_activity(
        $requestId,
        'assignment',
        'Adatlap felelőse módosítva',
        $electricianLabel
    );
}

function connection_request_electrician_assignment_schema_ready(): bool
{
    return db_table_exists('electricians')
        && db_column_exists('connection_requests', 'assigned_electrician_user_id')
        && db_column_exists('connection_requests', 'electrician_status');
}

function connection_request_assign_submitter_electrician_if_needed(int $requestId, ?int $submittedByUserId): bool
{
    if ($requestId <= 0 || $submittedByUserId === null || $submittedByUserId <= 0) {
        return false;
    }

    if (!connection_request_electrician_assignment_schema_ready()) {
        return false;
    }

    $electrician = find_electrician_by_user((int) $submittedByUserId);

    if ($electrician === null) {
        return false;
    }

    $statement = db_query(
        'UPDATE `connection_requests`
         SET `assigned_electrician_user_id` = ?,
             `electrician_status` = CASE
                WHEN `electrician_status` IN (?, ?) THEN `electrician_status`
                ELSE ?
             END
         WHERE `id` = ? AND `assigned_electrician_user_id` IS NULL',
        [(int) $submittedByUserId, 'in_progress', 'completed', 'assigned', $requestId]
    );

    if ($statement->rowCount() <= 0) {
        return false;
    }

    record_connection_request_activity(
        $requestId,
        'assignment',
        'Szerelő automatikusan hozzárendelve',
        trim((string) ($electrician['name'] ?? $electrician['email'] ?? '')) ?: ('Szerelő #' . (int) $submittedByUserId)
    );

    return true;
}

function connection_request_auto_assign_submitted_electrician_items(?int $submittedByUserId = null): int
{
    if (!connection_request_electrician_assignment_schema_ready()) {
        return 0;
    }

    $params = [];
    $filter = '';

    if ($submittedByUserId !== null && $submittedByUserId > 0) {
        $filter = ' AND cr.`submitted_by_user_id` = ?';
        $params[] = $submittedByUserId;
    }

    $rows = db_query(
        'SELECT cr.`id`, cr.`submitted_by_user_id`
         FROM `connection_requests` cr
         INNER JOIN `electricians` e ON e.`user_id` = cr.`submitted_by_user_id`
         WHERE cr.`assigned_electrician_user_id` IS NULL
           AND cr.`submitted_by_user_id` IS NOT NULL' . $filter . '
         ORDER BY cr.`id` DESC
         LIMIT 100',
        $params
    )->fetchAll();
    $updated = 0;

    foreach ($rows as $row) {
        if (connection_request_assign_submitter_electrician_if_needed((int) $row['id'], (int) $row['submitted_by_user_id'])) {
            $updated++;
        }
    }

    return $updated;
}

function send_electrician_assignment_email(int $requestId, int $electricianUserId): array
{
    $request = find_connection_request($requestId);
    $electrician = find_electrician_by_user($electricianUserId);

    if ($request === null || $electrician === null) {
        return ['ok' => false, 'message' => 'A munka vagy a szerelő nem található.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $subject = APP_NAME . ' új szerelői munka - ' . $request['project_name'];
    $dueBreakdown = connection_request_electrician_due_breakdown($requestId);
    $sections = [
        [
            'title' => 'Kiadott munka',
            'rows' => [
                ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                ['label' => 'Igénytípus', 'value' => connection_request_type_label($request['request_type'] ?? null)],
                ['label' => 'Kivitelezés címe', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                ['label' => 'Mérő', 'value' => $request['meter_serial'] ?? '-'],
            ],
        ],
    ];

    if ((float) ($dueBreakdown['total'] ?? 0) > 0) {
        $sections[] = [
            'title' => 'Kivitelezéskor beszedendő összeg',
            'rows' => [
                ['label' => 'Regisztrált villanyszerelői tételek', 'value' => format_money((float) ($dueBreakdown['registered'] ?? 0))],
                ['label' => 'Villanyszerelői szakmunkás tételek', 'value' => format_money((float) ($dueBreakdown['specialist'] ?? 0))],
                ['label' => 'Összesen', 'value' => format_money((float) ($dueBreakdown['total'] ?? 0))],
            ],
        ];
    }

    $actions = [
        ['label' => 'Munka megnyitása', 'url' => absolute_url('/electrician/work-request?id=' . $requestId)],
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress((string) $electrician['email'], (string) $electrician['name']);
        $mail->Subject = $subject;
        apply_branded_email($mail, 'Új kivitelezési munka érkezett', 'Az admin új munkát adott ki neked. A munka megkezdése előtt töltsd fel a kötelező induló fotókat.', $sections, $actions, (string) ($electrician['name'] ?? ''));
        $mail->send();

        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, (string) $electrician['email'], $subject, 'sent']);

        return ['ok' => true, 'message' => 'A szerelő email értesítést kapott.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, (string) $electrician['email'], $subject, 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A szerelői email értesítés küldése sikertelen.'];
    }
}

function find_connection_request(int $id): ?array
{
    $statement = db_query(
        'SELECT cr.*, c.requester_name, c.birth_name, c.company_name, c.tax_number, c.is_legal_entity,
                c.phone, c.email, c.postal_address, c.postal_code, c.city,
                c.mother_name, c.birth_place, c.birth_date,
                ct.contractor_name, ct.company_name AS contractor_company_name, ct.contact_name AS contractor_contact_name,
                ct.phone AS contractor_phone, ct.email AS contractor_email, ct.postal_code AS contractor_postal_code,
                ct.city AS contractor_city, ct.postal_address AS contractor_postal_address
         FROM `connection_requests` cr
         INNER JOIN `customers` c ON c.id = cr.customer_id
         LEFT JOIN `contractors` ct ON ct.user_id = cr.submitted_by_user_id
         WHERE cr.id = ?
         LIMIT 1',
        [$id]
    );
    $request = $statement->fetch();

    return is_array($request) ? $request : null;
}

function update_connection_request_customer_email(int $requestId, string $email): array
{
    $request = find_connection_request($requestId);
    $email = trim($email);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az adatlap nem található.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Érvényes ügyfél email címet adj meg.'];
    }

    $customerId = (int) ($request['customer_id'] ?? 0);

    if ($customerId <= 0) {
        return ['ok' => false, 'message' => 'Az adatlaphoz nem található ügyfél.'];
    }

    $previousEmail = trim((string) ($request['email'] ?? ''));

    if (strcasecmp($previousEmail, $email) === 0) {
        return ['ok' => true, 'message' => 'Az ügyfél alap email címe már ez volt.'];
    }

    db_query('UPDATE `customers` SET `email` = ? WHERE `id` = ?', [$email, $customerId]);
    record_connection_request_activity(
        $requestId,
        'customer-email',
        'Ügyfél email címe módosítva',
        'Régi email: ' . ($previousEmail !== '' ? $previousEmail : '-') . "\nÚj email: " . $email
    );

    return ['ok' => true, 'message' => 'Az ügyfél alap email címe frissült.'];
}

function update_connection_request_portal_details(int $requestId, array $source): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az adatlap nem található.'];
    }

    $customerId = (int) ($request['customer_id'] ?? 0);

    if ($customerId <= 0) {
        return ['ok' => false, 'message' => 'Az adatlaphoz nem található ügyfél.'];
    }

    $requestType = trim((string) ($source['request_type'] ?? $request['request_type'] ?? ''));

    if (!isset(connection_request_type_options()[$requestType])) {
        return ['ok' => false, 'message' => 'Érvényes munka típust válassz.'];
    }

    $details = [
        'requester_name' => trim((string) ($source['requester_name'] ?? $request['requester_name'] ?? '')),
        'email' => trim((string) ($source['customer_email'] ?? $request['email'] ?? '')),
        'phone' => trim((string) ($source['phone'] ?? $request['phone'] ?? '')),
        'project_name' => trim((string) ($source['project_name'] ?? $request['project_name'] ?? '')),
        'site_postal_code' => trim((string) ($source['site_postal_code'] ?? $request['site_postal_code'] ?? '')),
        'site_address' => trim((string) ($source['site_address'] ?? $request['site_address'] ?? '')),
        'hrsz' => trim((string) ($source['hrsz'] ?? $request['hrsz'] ?? '')),
        'meter_serial' => trim((string) ($source['meter_serial'] ?? $request['meter_serial'] ?? '')),
        'request_type' => $requestType,
    ];

    if ($details['requester_name'] === '') {
        return ['ok' => false, 'message' => 'Az ügyfél neve kötelező.'];
    }

    if (!filter_var($details['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Érvényes ügyfél email címet adj meg.'];
    }

    $details['project_name'] = $details['project_name'] !== ''
        ? connection_request_normalize_project_name($details['project_name'])
        : connection_request_auto_project_name($details, $details);

    $labels = [
        'project_name' => 'Adatlap neve',
        'requester_name' => 'Ügyfél neve',
        'email' => 'Ügyfél email',
        'phone' => 'Ügyfél telefon',
        'site_postal_code' => 'Kivitelezési irányítószám',
        'site_address' => 'Kivitelezési cím',
        'hrsz' => 'HRSZ',
        'request_type' => 'Munka típusa',
        'meter_serial' => 'Mérő gyári száma',
    ];
    $changes = [];

    foreach ($labels as $key => $label) {
        $old = trim((string) ($request[$key] ?? ''));
        $new = trim((string) ($details[$key] ?? ''));

        if ($old !== $new) {
            $changes[] = $label . ': ' . ($old !== '' ? $old : '-') . ' -> ' . ($new !== '' ? $new : '-');
        }
    }

    if ($changes === []) {
        return ['ok' => true, 'message' => 'Nem változott adat.'];
    }

    db_query(
        'UPDATE `customers`
         SET `requester_name` = ?, `email` = ?, `phone` = ?
         WHERE `id` = ?',
        [
            $details['requester_name'],
            $details['email'],
            $details['phone'],
            $customerId,
        ]
    );

    db_query(
        'UPDATE `connection_requests`
         SET `project_name` = ?, `request_type` = ?, `site_postal_code` = ?, `site_address` = ?,
             `hrsz` = ?, `meter_serial` = ?
         WHERE `id` = ?',
        [
            $details['project_name'],
            $details['request_type'],
            $details['site_postal_code'],
            $details['site_address'],
            $details['hrsz'] !== '' ? $details['hrsz'] : null,
            $details['meter_serial'] !== '' ? $details['meter_serial'] : null,
            $requestId,
        ]
    );

    record_connection_request_activity(
        $requestId,
        'request_update',
        'Adatlap és ügyfél adatok módosítva',
        implode("\n", array_slice($changes, 0, 12))
    );

    return ['ok' => true, 'message' => 'Az adatlap és az ügyfél adatai frissültek.'];
}

function all_connection_requests(): array
{
    connection_request_auto_assign_submitted_electrician_items();

    if (db_table_exists('electricians') && db_column_exists('connection_requests', 'assigned_electrician_user_id')) {
        return db_query(
            'SELECT cr.*, c.requester_name, c.email, c.phone,
                    ct.contractor_name, ct.contact_name AS contractor_contact_name,
                    e.name AS electrician_name, e.phone AS electrician_phone, e.email AS electrician_email
             FROM `connection_requests` cr
             INNER JOIN `customers` c ON c.id = cr.customer_id
             LEFT JOIN `contractors` ct ON ct.user_id = cr.submitted_by_user_id
             LEFT JOIN `electricians` e ON e.user_id = cr.assigned_electrician_user_id
             ORDER BY cr.created_at DESC, cr.id DESC'
        )->fetchAll();
    }

    return db_query(
        'SELECT cr.*, c.requester_name, c.email, c.phone,
                ct.contractor_name, ct.contact_name AS contractor_contact_name
         FROM `connection_requests` cr
         INNER JOIN `customers` c ON c.id = cr.customer_id
         LEFT JOIN `contractors` ct ON ct.user_id = cr.submitted_by_user_id
         ORDER BY cr.created_at DESC, cr.id DESC'
    )->fetchAll();
}

function admin_standalone_connection_request_where(bool $archivedOnly, bool $hasMinicrmLinks): string
{
    $conditions = [];

    if ($hasMinicrmLinks) {
        $conditions[] = 'l.id IS NULL';
    }

    if (connection_request_archive_columns_ready()) {
        $conditions[] = 'cr.`archived_at` IS ' . ($archivedOnly ? 'NOT NULL' : 'NULL');
    } elseif ($archivedOnly) {
        $conditions[] = '1 = 0';
    }

    return $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
}

function admin_standalone_connection_request_count(bool $archivedOnly = false): int
{
    if (!db_table_exists('connection_requests') || !db_table_exists('customers')) {
        return 0;
    }

    $hasMinicrmLinks = minicrm_connection_request_link_schema_errors() === [];
    $minicrmJoin = $hasMinicrmLinks
        ? ' LEFT JOIN `minicrm_connection_request_links` l ON l.connection_request_id = cr.id'
        : '';

    return (int) db_query(
        'SELECT COUNT(*)
         FROM `connection_requests` cr
         INNER JOIN `customers` c ON c.id = cr.customer_id'
         . $minicrmJoin
         . admin_standalone_connection_request_where($archivedOnly, $hasMinicrmLinks)
    )->fetchColumn();
}

function admin_standalone_connection_request_items(int $limit = 500, bool $archivedOnly = false): array
{
    if (!db_table_exists('connection_requests') || !db_table_exists('customers')) {
        return [];
    }

    connection_request_auto_assign_submitted_electrician_items();

    $limit = max(1, min(1000, $limit));
    $hasElectricianTable = db_table_exists('electricians');
    $hasElectricians = $hasElectricianTable && db_column_exists('connection_requests', 'assigned_electrician_user_id');
    $hasMinicrmLinks = minicrm_connection_request_link_schema_errors() === [];
    $electricianSelect = $hasElectricians
        ? ', e.name AS electrician_name, e.phone AS electrician_phone, e.email AS electrician_email'
        : ", NULL AS electrician_name, NULL AS electrician_phone, NULL AS electrician_email";
    $electricianJoin = $hasElectricians
        ? ' LEFT JOIN `electricians` e ON e.user_id = cr.assigned_electrician_user_id'
        : '';
    $submittedByElectricianSelect = $hasElectricianTable ? ', se.name AS submitted_by_electrician_name' : ', NULL AS submitted_by_electrician_name';
    $submittedByElectricianJoin = $hasElectricianTable ? ' LEFT JOIN `electricians` se ON se.user_id = cr.submitted_by_user_id' : '';
    $minicrmSelect = $hasMinicrmLinks ? ', l.work_item_id AS minicrm_work_item_id' : ', NULL AS minicrm_work_item_id';
    $minicrmJoin = $hasMinicrmLinks
        ? ' LEFT JOIN `minicrm_connection_request_links` l ON l.connection_request_id = cr.id'
        : '';
    $where = admin_standalone_connection_request_where($archivedOnly, $hasMinicrmLinks);

    return db_query(
        'SELECT cr.*, c.requester_name, c.birth_name, c.company_name, c.tax_number, c.is_legal_entity,
                c.phone, c.email, c.postal_address, c.postal_code, c.city,
                c.mother_name, c.birth_place, c.birth_date,
                su.role AS submitted_by_user_role, su.name AS submitted_by_user_name, su.email AS submitted_by_user_email'
                . $submittedByElectricianSelect . ',
                sct.contractor_name AS submitted_by_contractor_name, sct.contact_name AS submitted_by_contractor_contact_name,
                ct.contractor_name, ct.contact_name AS contractor_contact_name,
                ct.phone AS contractor_phone, ct.email AS contractor_email,
                ct.postal_code AS contractor_postal_code, ct.city AS contractor_city, ct.postal_address AS contractor_postal_address'
                . $electricianSelect . $minicrmSelect . '
         FROM `connection_requests` cr
         INNER JOIN `customers` c ON c.id = cr.customer_id
         LEFT JOIN `users` su ON su.id = cr.submitted_by_user_id
         ' . $submittedByElectricianJoin . '
         LEFT JOIN `contractors` sct ON sct.user_id = cr.submitted_by_user_id
         LEFT JOIN `contractors` ct ON ct.user_id = cr.submitted_by_user_id'
         . $electricianJoin . $minicrmJoin . $where . '
         ORDER BY cr.created_at DESC, cr.id DESC
         LIMIT ' . $limit
    )->fetchAll();
}

function connection_requests_for_customer(int $customerId): array
{
    $select = 'SELECT cr.*, su.role AS submitted_by_user_role, su.name AS submitted_by_user_name, su.email AS submitted_by_user_email';
    $joins = ' FROM `connection_requests` cr
         LEFT JOIN `users` su ON su.id = cr.submitted_by_user_id';

    if (db_table_exists('electricians')) {
        $select .= ', se.name AS submitted_by_electrician_name';
        $joins .= ' LEFT JOIN `electricians` se ON se.user_id = cr.submitted_by_user_id';
    } else {
        $select .= ', NULL AS submitted_by_electrician_name';
    }

    if (db_table_exists('contractors')) {
        $select .= ', sct.contractor_name AS submitted_by_contractor_name, sct.contact_name AS submitted_by_contractor_contact_name';
        $joins .= ' LEFT JOIN `contractors` sct ON sct.user_id = cr.submitted_by_user_id';
    } else {
        $select .= ', NULL AS submitted_by_contractor_name, NULL AS submitted_by_contractor_contact_name';
    }

    return db_query(
        $select . $joins . '
         WHERE cr.`customer_id` = ?
         ORDER BY cr.`created_at` DESC, cr.`id` DESC',
        [$customerId]
    )->fetchAll();
}

function connection_request_summaries_for_customers(array $customerIds): array
{
    $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds))));

    if ($customerIds === []) {
        return [];
    }

    $rows = db_query(
        'SELECT `id`, `customer_id`, `project_name`, `request_type`, `request_status`, `site_address`, `site_postal_code`, `created_at`
         FROM `connection_requests`
         WHERE `customer_id` IN (' . db_in_placeholders($customerIds) . ')
         ORDER BY `customer_id` ASC, `created_at` DESC, `id` DESC',
        $customerIds
    )->fetchAll();
    $grouped = [];

    foreach ($rows as $row) {
        $grouped[(int) $row['customer_id']][] = $row;
    }

    return $grouped;
}

function connection_requests_for_submitter(int $submittedByUserId): array
{
    return db_query(
        'SELECT cr.*, c.requester_name, c.email, c.phone
         FROM `connection_requests` cr
         INNER JOIN `customers` c ON c.id = cr.customer_id
         WHERE cr.submitted_by_user_id = ?
         ORDER BY cr.created_at DESC, cr.id DESC',
        [$submittedByUserId]
    )->fetchAll();
}

function connection_requests_for_electrician(int $electricianUserId): array
{
    if (!db_column_exists('connection_requests', 'assigned_electrician_user_id')) {
        return [];
    }

    connection_request_auto_assign_submitted_electrician_items($electricianUserId);

    return db_query(
        'SELECT cr.*, c.requester_name, c.email, c.phone
         FROM `connection_requests` cr
         INNER JOIN `customers` c ON c.id = cr.customer_id
         WHERE cr.assigned_electrician_user_id = ? OR cr.submitted_by_user_id = ?
         ORDER BY cr.created_at DESC, cr.id DESC',
        [$electricianUserId, $electricianUserId]
    )->fetchAll();
}

function connection_request_files(int $requestId): array
{
    return db_query(
        'SELECT * FROM `connection_request_files` WHERE `connection_request_id` = ? ORDER BY `id` ASC',
        [$requestId]
    )->fetchAll();
}

function connection_request_file_actor_columns_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        if (!db_table_exists('connection_request_files')) {
            $ready = false;
            return $ready;
        }

        $columns = [
            'uploaded_by_user_id' => 'ALTER TABLE `connection_request_files` ADD COLUMN `uploaded_by_user_id` INT UNSIGNED NULL AFTER `connection_request_id`',
            'uploaded_by_role' => 'ALTER TABLE `connection_request_files` ADD COLUMN `uploaded_by_role` VARCHAR(40) NOT NULL DEFAULT \'guest\' AFTER `uploaded_by_user_id`',
            'uploaded_by_name' => 'ALTER TABLE `connection_request_files` ADD COLUMN `uploaded_by_name` VARCHAR(160) DEFAULT NULL AFTER `uploaded_by_role`',
            'uploaded_by_email' => 'ALTER TABLE `connection_request_files` ADD COLUMN `uploaded_by_email` VARCHAR(190) DEFAULT NULL AFTER `uploaded_by_name`',
        ];

        foreach ($columns as $column => $sql) {
            if (!db_column_exists('connection_request_files', $column)) {
                try {
                    db_query($sql);
                } catch (Throwable) {
                    $ready = false;
                    return $ready;
                }
            }
        }

        try {
            $indexExists = (bool) db_query(
                'SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [DB_NAME, 'connection_request_files', 'idx_connection_request_files_uploader']
            )->fetchColumn();

            if (!$indexExists) {
                db_query('ALTER TABLE `connection_request_files` ADD KEY `idx_connection_request_files_uploader` (`uploaded_by_user_id`, `created_at`)');
            }
        } catch (Throwable) {
            // The actor columns are the important part; the index can be added by the SQL upgrade later.
        }

        $ready = db_column_exists('connection_request_files', 'uploaded_by_user_id')
            && db_column_exists('connection_request_files', 'uploaded_by_role')
            && db_column_exists('connection_request_files', 'uploaded_by_name')
            && db_column_exists('connection_request_files', 'uploaded_by_email');
    }

    return $ready;
}

function connection_request_submitter_label(array $request): string
{
    $submittedByUserId = (int) ($request['submitted_by_user_id'] ?? 0);

    if ($submittedByUserId > 0) {
        if (!empty($request['submitted_by_user_role']) || !empty($request['submitted_by_user_name']) || !empty($request['submitted_by_user_email'])) {
            $role = (string) ($request['submitted_by_user_role'] ?? '');
            $name = (string) ($request['submitted_by_user_name'] ?? '');

            if ($role === 'electrician' && !empty($request['submitted_by_electrician_name'])) {
                $name = (string) $request['submitted_by_electrician_name'];
            } elseif ($role === 'general_contractor' && !empty($request['submitted_by_contractor_name'])) {
                $contractorName = (string) $request['submitted_by_contractor_name'];
                $contactName = trim((string) ($request['submitted_by_contractor_contact_name'] ?? ''));
                $name = $contactName !== '' && $contactName !== $contractorName
                    ? $contractorName . ' - ' . $contactName
                    : $contractorName;
            }

            return actor_display_label_from_parts($role, $name, (string) ($request['submitted_by_user_email'] ?? ''), $submittedByUserId);
        }

        return actor_label_for_user_id($submittedByUserId, 'Ismeretlen beküldő');
    }

    $customerName = trim((string) ($request['requester_name'] ?? ''));
    $customerEmail = trim((string) ($request['email'] ?? ''));

    if ($customerName !== '' || $customerEmail !== '') {
        return actor_display_label_from_parts('customer', $customerName, $customerEmail);
    }

    return 'Nincs rögzített beküldő';
}

function authorization_signature_token(array $request): string
{
    return authorization_signature_token_for_parts(
        (int) ($request['id'] ?? 0),
        (int) ($request['customer_id'] ?? 0)
    );
}

function authorization_signature_token_for_parts(int $requestId, int $customerId, string $email = ''): string
{
    return hash_hmac(
        'sha256',
        (string) $requestId . '|' . (string) $customerId . ($email !== '' ? '|' . $email : ''),
        DB_PASS
    );
}

function authorization_signature_legacy_token_for_parts(int $requestId, int $customerId, string $email = ''): string
{
    return hash_hmac(
        'sha256',
        (string) $requestId . '|' . (string) $customerId . '|' . $email,
        DB_PASS
    );
}

function authorization_signature_url(array $request): string
{
    return absolute_url(
        '/authorization-sign?id=' . (int) $request['id'] .
        '&token=' . rawurlencode(authorization_signature_token($request))
    );
}

function authorization_upload_url(array $request): string
{
    return absolute_url(
        '/authorization-upload?id=' . (int) $request['id'] .
        '&token=' . rawurlencode(authorization_signature_token($request))
    );
}

function authorization_signature_token_is_valid(array $request, string $token): bool
{
    $token = trim($token);
    $requestId = (int) ($request['id'] ?? 0);
    $customerId = (int) ($request['customer_id'] ?? 0);
    $email = (string) ($request['email'] ?? '');
    $validTokens = [
        authorization_signature_token_for_parts($requestId, $customerId),
        authorization_signature_token_for_parts($requestId, $customerId, $email),
        authorization_signature_legacy_token_for_parts($requestId, $customerId, $email),
        authorization_signature_legacy_token_for_parts($requestId, $customerId, ''),
    ];

    foreach (array_unique($validTokens) as $validToken) {
        if (hash_equals($validToken, $token)) {
            return true;
        }
    }

    return false;
}

function decode_signature_data_uri(string $value, string $label): array
{
    if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', trim($value), $matches)) {
        throw new RuntimeException($label . ' hiányzik vagy érvénytelen.');
    }

    $binary = base64_decode($matches[1], true);

    if (!is_string($binary) || $binary === '') {
        throw new RuntimeException($label . ' nem olvasható.');
    }

    if (strlen($binary) > 2 * 1024 * 1024) {
        throw new RuntimeException($label . ' túl nagy.');
    }

    return [
        'data_uri' => 'data:image/png;base64,' . base64_encode($binary),
        'bytes' => $binary,
    ];
}

function authorization_pdf_template_path(): ?string
{
    $candidates = [
        APP_ROOT . '/templates/mvm/a-szab-155-ny03-meghatalmazas-elektronikus.pdf',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function authorization_pdf_page_height_pt(): float
{
    return 841.89;
}

function authorization_pdf_pt_to_mm(float $point): float
{
    return $point * 25.4 / 72;
}

function authorization_pdf_rect_to_mm(array $rect): array
{
    $pageHeight = authorization_pdf_page_height_pt();

    return [
        'x' => authorization_pdf_pt_to_mm((float) $rect[0]),
        'y' => authorization_pdf_pt_to_mm($pageHeight - (float) $rect[3]),
        'w' => authorization_pdf_pt_to_mm((float) $rect[2] - (float) $rect[0]),
        'h' => authorization_pdf_pt_to_mm((float) $rect[3] - (float) $rect[1]),
    ];
}

function authorization_pdf_add_text(
    \setasign\Fpdi\Fpdi $pdf,
    string $text,
    array $rect,
    array &$temporaryFiles,
    int $fontSize = 7,
    float $verticalOffsetMm = 4.6
): void {
    $box = authorization_pdf_rect_to_mm($rect);
    $height = max(4.0, min($box['h'], 4.6));
    $y = $box['y'] + $verticalOffsetMm;

    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($box['x'], max(0, $y - 0.3), $box['w'], $height + 0.6, 'F');

    if (trim($text) === '') {
        return;
    }

    $imagePath = mvm_pdf_text_image($text, $box['w'], $height, $fontSize);
    $temporaryFiles[] = $imagePath;
    $pdf->Image($imagePath, $box['x'], $y, $box['w'], $height, 'PNG');
}

function authorization_pdf_add_checkbox(\setasign\Fpdi\Fpdi $pdf, array $rect): void
{
    $box = authorization_pdf_rect_to_mm($rect);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text($box['x'] + 1.4, $box['y'] + 2.4, 'X');
}

function authorization_pdf_add_consumption_place_id(\setasign\Fpdi\Fpdi $pdf, string $value): void
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';

    if ($digits === '') {
        return;
    }

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);

    foreach (str_split(substr($digits, 0, 10)) as $index => $digit) {
        $pdf->Text(87.8 + ($index * 4.96), 52.7, $digit);
    }
}

function authorization_pdf_signature_file(array $signature, float $widthMm, float $heightMm, array &$temporaryFiles): string
{
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('Az aláírás PDF-be illesztéséhez a PHP GD támogatás szükséges.');
    }

    $source = imagecreatefromstring((string) $signature['bytes']);

    if ($source === false) {
        throw new RuntimeException('Az aláírás képe nem olvasható.');
    }

    $path = mvm_pdf_text_image(' ', $widthMm, $heightMm, 7);
    $temporaryFiles[] = $path;
    $target = imagecreatefrompng($path);

    if ($target === false) {
        imagedestroy($source);
        throw new RuntimeException('Az aláírás előkészítése sikertelen.');
    }

    imagealphablending($target, true);

    $sourceWidth = max(1, imagesx($source));
    $sourceHeight = max(1, imagesy($source));
    $targetWidth = max(1, imagesx($target));
    $targetHeight = max(1, imagesy($target));
    $scale = min(($targetWidth - 10) / $sourceWidth, ($targetHeight - 6) / $sourceHeight);
    $scale = max(0.1, $scale);
    $drawWidth = max(1, (int) floor($sourceWidth * $scale));
    $drawHeight = max(1, (int) floor($sourceHeight * $scale));
    $dstX = max(0, (int) floor(($targetWidth - $drawWidth) / 2));
    $dstY = max(0, (int) floor(($targetHeight - $drawHeight) / 2));

    imagecopyresampled($target, $source, $dstX, $dstY, 0, 0, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);
    imagepng($target, $path, 6);
    imagedestroy($source);
    imagedestroy($target);

    return $path;
}

function authorization_pdf_add_signature(
    \setasign\Fpdi\Fpdi $pdf,
    string $signaturePath,
    float $xPt,
    float $lineYPt,
    float $widthPt,
    float $heightPt
): void {
    $x = authorization_pdf_pt_to_mm($xPt);
    $w = authorization_pdf_pt_to_mm($widthPt);
    $h = authorization_pdf_pt_to_mm($heightPt);
    $lineY = authorization_pdf_pt_to_mm(authorization_pdf_page_height_pt() - $lineYPt);
    $y = $lineY - $h + 0.8;

    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($x, max(0, $y - 0.7), $w, $h + 1.4, 'F');
    $pdf->Image($signaturePath, $x, $y, $w, $h, 'PNG');
}

function authorization_pdf_customer_address(array $request): string
{
    return trim((string) ($request['postal_code'] ?? '') . ' ' . (string) ($request['city'] ?? '') . ' ' . (string) ($request['postal_address'] ?? ''));
}

function authorization_pdf_site_address(array $request): string
{
    return trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));
}

function authorization_pdf_birth_place_and_date(array $request): string
{
    return trim((string) ($request['birth_place'] ?? '') . ', ' . format_mvm_docx_date((string) ($request['birth_date'] ?? '')), " \t\n\r\0\x0B,");
}

function generate_signed_authorization_pdf_from_template(int $requestId, array $request, array $data, array $signatures, string $targetPath, bool $includeSignatures = true): void
{
    if (!class_exists('\\setasign\\Fpdi\\Fpdi')) {
        throw new RuntimeException('Az MVM meghatalmazás PDF kitöltéséhez hiányzik az FPDI csomag.');
    }

    $templatePath = authorization_pdf_template_path();

    if ($templatePath === null) {
        throw new RuntimeException('Hiányzik az MVM meghatalmazás sablon: templates/mvm/a-szab-155-ny03-meghatalmazas-elektronikus.pdf');
    }

    $temporaryFiles = [];
    $pdf = new \setasign\Fpdi\Fpdi();
    $pageCount = $pdf->setSourceFile($templatePath);

    try {
        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $templateId = $pdf->importPage($pageNumber);
            $size = $pdf->getTemplateSize($templateId);
            $orientation = ($size['width'] ?? 0) > ($size['height'] ?? 0) ? 'L' : 'P';
            $pdf->AddPage($orientation, [(float) $size['width'], (float) $size['height']]);
            $pdf->useTemplate($templateId, 0, 0, (float) $size['width'], (float) $size['height']);

            if ($pageNumber === 1) {
                $isLegalEntity = (int) ($request['is_legal_entity'] ?? 0) === 1;
                $customerName = trim((string) ($request['requester_name'] ?? ''));
                $birthName = trim((string) ($request['birth_name'] ?? ''));
                $companyName = trim((string) ($request['company_name'] ?? ''));

                if ($birthName === '') {
                    $birthName = $customerName;
                }

                if ($companyName === '') {
                    $companyName = $customerName;
                }

                if ($isLegalEntity) {
                    authorization_pdf_add_text($pdf, $companyName, [131.04, 462.84, 536.76, 486.00], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, authorization_pdf_customer_address($request), [152.88, 437.52, 536.76, 460.68], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, (string) ($request['tax_number'] ?? ''), [134.16, 412.32, 296.40, 435.36], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, '', [415.08, 412.32, 536.76, 435.48], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, (string) ($request['email'] ?? ''), [318.36, 387.00, 536.76, 410.16], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, $customerName, [340.20, 361.68, 536.76, 384.84], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, (string) ($request['phone'] ?? ''), [312.12, 336.36, 436.92, 359.52], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, (string) ($request['email'] ?? ''), [299.64, 311.04, 436.92, 334.20], $temporaryFiles, 7);
                } else {
                    authorization_pdf_add_text($pdf, $customerName, [118.56, 649.80, 296.40, 672.96], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, $birthName, [396.36, 649.80, 536.76, 672.96], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, (string) ($request['mother_name'] ?? ''), [137.28, 624.48, 296.40, 647.64], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, authorization_pdf_birth_place_and_date($request), [427.56, 624.48, 536.76, 647.64], $temporaryFiles, 5);
                    authorization_pdf_add_text($pdf, authorization_pdf_customer_address($request), [171.60, 599.16, 536.76, 622.32], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, (string) ($request['phone'] ?? ''), [296.52, 573.84, 436.92, 597.00], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, (string) ($request['email'] ?? ''), [287.16, 548.64, 436.92, 571.68], $temporaryFiles, 7);
                    authorization_pdf_add_text($pdf, (string) ($request['email'] ?? ''), [243.36, 523.32, 436.92, 546.48], $temporaryFiles, 7);
                }

                authorization_pdf_add_text($pdf, 'Hajdu Zoltán', [109.20, 254.88, 296.40, 278.04], $temporaryFiles, 7);
                authorization_pdf_add_text($pdf, 'Oravecz Zsuzsanna', [137.28, 229.56, 296.40, 252.72], $temporaryFiles, 7);
                authorization_pdf_add_text($pdf, 'Medgyesegyháza, 1971.01.11.', [427.56, 229.56, 536.76, 252.72], $temporaryFiles, 5);
                authorization_pdf_add_text($pdf, '5820 Mezőhegyes, Tavasz u. 4.', [159.12, 204.24, 536.76, 227.40], $temporaryFiles, 7);
                authorization_pdf_add_text($pdf, '06301654941', [296.52, 178.92, 436.92, 202.08], $temporaryFiles, 7);
                authorization_pdf_add_text($pdf, 'kapcsolat@villanyszerelo-bekes.hu', [287.16, 153.60, 436.92, 176.76], $temporaryFiles, 5);
            }

            if ($pageNumber === 2) {
                authorization_pdf_add_checkbox($pdf, [70.92, 708.60, 81.96, 715.92]);
                authorization_pdf_add_consumption_place_id($pdf, (string) ($request['consumption_place_id'] ?? ''));
                authorization_pdf_add_text($pdf, authorization_pdf_site_address($request), [196.56, 669.84, 536.76, 690.00], $temporaryFiles, 7);
                authorization_pdf_add_checkbox($pdf, [70.92, 569.16, 80.40, 575.40]);
                authorization_pdf_add_checkbox($pdf, [70.92, 514.92, 81.96, 522.24]);
                authorization_pdf_add_checkbox($pdf, [70.92, 430.08, 81.96, 437.40]);
                authorization_pdf_add_checkbox($pdf, [70.92, 389.64, 81.96, 396.96]);
                authorization_pdf_add_text($pdf, date('Y.m.d.'), [96.44, 320.12, 215.34, 340.28], $temporaryFiles, 8);

                if (!$includeSignatures) {
                    continue;
                }

                $customerSignature = authorization_pdf_signature_file($signatures['customer_signature'], authorization_pdf_pt_to_mm(145.00), authorization_pdf_pt_to_mm(36.00), $temporaryFiles);
                $witness1Signature = authorization_pdf_signature_file($signatures['witness_1_signature'], authorization_pdf_pt_to_mm(209.10), authorization_pdf_pt_to_mm(34.00), $temporaryFiles);
                $witness2Signature = authorization_pdf_signature_file($signatures['witness_2_signature'], authorization_pdf_pt_to_mm(209.10), authorization_pdf_pt_to_mm(34.00), $temporaryFiles);

                authorization_pdf_add_signature($pdf, $customerSignature, 234.10, 320.20, 145.00, 36.00);
                authorization_pdf_add_signature($pdf, $witness1Signature, 71.80, 226.20, 209.10, 34.00);
                authorization_pdf_add_signature($pdf, $witness2Signature, 327.70, 226.20, 209.10, 34.00);
                authorization_pdf_add_text($pdf, (string) $data['witness_1_name'], [71.80, 188.20, 280.90, 208.30], $temporaryFiles, 7);
                authorization_pdf_add_text($pdf, (string) $data['witness_2_name'], [327.70, 188.20, 536.80, 208.30], $temporaryFiles, 7);
                authorization_pdf_add_text($pdf, (string) $data['witness_1_address'], [71.80, 150.20, 280.90, 170.40], $temporaryFiles, 7);
                authorization_pdf_add_text($pdf, (string) $data['witness_2_address'], [327.70, 150.20, 536.80, 170.40], $temporaryFiles, 7);
            }
        }

        $pdf->Output('F', $targetPath);
    } finally {
        foreach ($temporaryFiles as $temporaryFile) {
            if (is_string($temporaryFile) && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }
}

function save_signed_authorization_document(int $requestId, array $source): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        throw new RuntimeException('Az igény nem található.');
    }

    $data = [
        'witness_1_name' => trim((string) ($source['witness_1_name'] ?? '')),
        'witness_1_address' => trim((string) ($source['witness_1_address'] ?? '')),
        'witness_2_name' => trim((string) ($source['witness_2_name'] ?? '')),
        'witness_2_address' => trim((string) ($source['witness_2_address'] ?? '')),
    ];

    foreach ([
        'witness_1_name' => 'Az 1. tanú neve',
        'witness_1_address' => 'Az 1. tanú címe',
        'witness_2_name' => 'A 2. tanú neve',
        'witness_2_address' => 'A 2. tanú címe',
    ] as $key => $label) {
        if ($data[$key] === '') {
            throw new RuntimeException($label . ' kötelező.');
        }
    }

    $signatures = [
        'customer_signature' => decode_signature_data_uri((string) ($source['customer_signature'] ?? ''), 'Az ügyfél aláírása'),
        'witness_1_signature' => decode_signature_data_uri((string) ($source['witness_1_signature'] ?? ''), 'Az 1. tanú aláírása'),
        'witness_2_signature' => decode_signature_data_uri((string) ($source['witness_2_signature'] ?? ''), 'A 2. tanú aláírása'),
    ];

    $targetDir = CONNECTION_UPLOAD_PATH . '/' . $requestId;
    ensure_storage_dir($targetDir);
    $storedName = 'authorization-signed-' . date('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.pdf';
    $targetPath = $targetDir . '/' . $storedName;
    generate_signed_authorization_pdf_from_template($requestId, $request, $data, $signatures, $targetPath);

    db_query(
        'INSERT INTO `connection_request_files`
            (`connection_request_id`, `file_type`, `label`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            'authorization',
            'Elektronikusan aláírt MVM meghatalmazás',
            'a-szab-155-ny03-meghatalmazas-alairva.pdf',
            $storedName,
            $targetPath,
            'application/pdf',
            (int) filesize($targetPath),
        ]
    );

    return [
        'ok' => true,
        'message' => 'Az elektronikusan aláírt meghatalmazás elmentve.',
        'file_id' => (int) db()->lastInsertId(),
    ];
}

function generate_prefilled_authorization_form_pdf(int $requestId): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.'];
    }

    try {
        $targetDir = CONNECTION_UPLOAD_PATH . '/' . $requestId;
        ensure_storage_dir($targetDir);
        $storedName = 'authorization-prefilled-' . $requestId . '.pdf';
        $targetPath = $targetDir . '/' . $storedName;

        generate_signed_authorization_pdf_from_template(
            $requestId,
            $request,
            [],
            [],
            $targetPath,
            false
        );

        return [
            'ok' => true,
            'message' => 'A kitöltött meghatalmazás PDF elkészült.',
            'path' => $targetPath,
            'filename' => 'a-szab-155-ny03-meghatalmazas-kitoltve.pdf',
            'file_size' => is_file($targetPath) ? (int) filesize($targetPath) : 0,
        ];
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'message' => APP_DEBUG ? $exception->getMessage() : 'A meghatalmazás PDF elkészítése sikertelen.',
        ];
    }
}

function send_prefilled_authorization_form_email(int $requestId): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $recipientEmail = trim((string) ($request['email'] ?? ''));
    $recipientName = email_recipient_name($request['requester_name'] ?? '');

    if ($recipientEmail === '') {
        return ['ok' => false, 'message' => 'A meghatalmazás email nem küldhető, mert hiányzik az ügyfél email címe.'];
    }

    $pdfResult = generate_prefilled_authorization_form_pdf($requestId);

    if (!($pdfResult['ok'] ?? false)) {
        return $pdfResult;
    }

    $uploadUrl = authorization_upload_url($request);
    $token = customer_email_thread_token($requestId, 'authorization-upload');
    $subject = customer_email_thread_subject(APP_NAME . ' meghatalmazás nyomtatvány - ' . ($request['project_name'] ?? 'mérőhelyi igény'), $token);
    $replyAddress = mvm_mail_reply_address();
    $emailTitle = 'Meghatalmazás nyomtatvány kitöltve';
    $emailLead = 'Csatoltuk az adataival előre kitöltött meghatalmazás nyomtatványt. Kérjük, nyomtassa ki, írja alá a tanúkkal együtt, majd a gombbal töltse fel befotózva vagy beszkennelve.';
    $sections = [
        [
            'title' => 'Igény adatai',
            'rows' => [
                ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                ['label' => 'Ügyfél', 'value' => $request['requester_name'] ?? '-'],
                ['label' => 'Felhasználási hely', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                ['label' => 'Válaszazonosító', 'value' => $token],
            ],
        ],
        [
            'title' => 'Teendő',
            'items' => [
                'Nyomtassa ki a csatolt meghatalmazást.',
                'Írja alá, és kérjen két tanú aláírást is.',
                'A gombra kattintva töltse fel fotóként vagy PDF-ként.',
            ],
        ],
    ];
    $actions = [
        ['label' => 'Aláírt meghatalmazás feltöltése', 'url' => $uploadUrl],
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->addReplyTo($replyAddress, MAIL_FROM_NAME);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $sections, $actions, $recipientName);
        $mail->addAttachment((string) $pdfResult['path'], (string) $pdfResult['filename']);
        $mail->send();

        $messageId = method_exists($mail, 'getLastMessageID') ? (string) $mail->getLastMessageID() : '';
        record_customer_email_thread(
            $requestId,
            $token,
            $recipientEmail,
            $subject,
            'Meghatalmazás nyomtatvány',
            branded_email_text($emailTitle, $emailLead, $sections, $actions, $recipientName),
            branded_email_html($emailTitle, $emailLead, $sections, $actions, $recipientName),
            $messageId !== '' ? $messageId : null
        );

        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, $recipientEmail, $subject, 'sent']);

        return ['ok' => true, 'message' => 'A kitöltött meghatalmazást elküldtük az ügyfélnek.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, $recipientEmail, $subject, 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A meghatalmazás email küldése sikertelen.'];
    }
}

function electrician_work_stage_labels(): array
{
    return [
        'before' => 'Kivitelezés előtti fotók',
        'after' => 'Kivitelezés utáni fotók',
    ];
}

function electrician_work_file_definitions(string $stage): array
{
    $definitions = [
        'meter_close' => ['label' => 'Mérő közelről', 'multiple' => false],
        'meter_far' => ['label' => 'Mérő távolról', 'multiple' => false],
        'roof_hook' => ['label' => 'Tetőtartó', 'multiple' => false],
        'utility_pole' => ['label' => 'Villanyoszlop', 'multiple' => false],
        'seals' => ['label' => 'Plombák', 'multiple' => true],
    ];

    if ($stage === 'after') {
        $definitions['completed_intervention_sheet'] = ['label' => 'Elkészült beavatkozási lap', 'multiple' => true];
    }

    return $definitions;
}

function connection_request_work_files(int $requestId, ?string $stage = null): array
{
    if (!db_table_exists('connection_request_work_files')) {
        return [];
    }

    $select = 'SELECT wf.*, u.role AS uploaded_by_user_role, u.name AS uploaded_by_user_name, u.email AS uploaded_by_user_email';
    $joins = ' FROM `connection_request_work_files` wf
         LEFT JOIN `users` u ON u.id = wf.uploaded_by_user_id';

    if (db_table_exists('electricians')) {
        $select .= ', e.name AS uploaded_by_electrician_name';
        $joins .= ' LEFT JOIN `electricians` e ON e.user_id = wf.uploaded_by_user_id';
    } else {
        $select .= ', NULL AS uploaded_by_electrician_name';
    }

    if (db_table_exists('contractors')) {
        $select .= ', ct.contractor_name AS uploaded_by_contractor_name, ct.contact_name AS uploaded_by_contractor_contact_name';
        $joins .= ' LEFT JOIN `contractors` ct ON ct.user_id = wf.uploaded_by_user_id';
    } else {
        $select .= ', NULL AS uploaded_by_contractor_name, NULL AS uploaded_by_contractor_contact_name';
    }

    if ($stage !== null) {
        return db_query(
            $select . $joins . '
             WHERE wf.`connection_request_id` = ? AND wf.`stage` = ?
             ORDER BY wf.`created_at` DESC, wf.`id` DESC',
            [$requestId, $stage]
        )->fetchAll();
    }

    return db_query(
        $select . $joins . '
         WHERE wf.`connection_request_id` = ?
         ORDER BY wf.`stage` ASC, wf.`created_at` DESC, wf.`id` DESC',
        [$requestId]
    )->fetchAll();
}

function connection_request_has_work_file_type(int $requestId, string $stage, string $fileType): bool
{
    if (!db_table_exists('connection_request_work_files')) {
        return false;
    }

    return (bool) db_query(
        'SELECT 1 FROM `connection_request_work_files`
         WHERE `connection_request_id` = ? AND `stage` = ? AND `file_type` = ?
         LIMIT 1',
        [$requestId, $stage, $fileType]
    )->fetchColumn();
}

function validate_electrician_work_uploads(int $requestId, string $stage, array $files): array
{
    $errors = [];

    foreach (electrician_work_file_definitions($stage) as $key => $definition) {
        $uploadedFiles = array_values(array_filter(
            uploaded_files_for_key($files, 'work_file_' . $stage . '_' . $key),
            static fn (?array $file): bool => uploaded_file_is_present($file)
        ));

        if ($uploadedFiles === [] && !connection_request_has_work_file_type($requestId, $stage, $key)) {
            $errors[] = $definition['label'] . ' feltöltése kötelező.';
            continue;
        }

        foreach ($uploadedFiles as $file) {
            $errors = array_merge($errors, validate_portal_file_upload($file, $definition['label'], true));
        }
    }

    return $errors;
}

function validate_connection_request_after_work_photo_uploads(int $requestId, array $files): array
{
    if (!db_table_exists('connection_request_work_files')) {
        return ['A szerelői munkafotók adatbázistáblája nem elérhető.'];
    }

    $errors = [];

    foreach (connection_request_required_after_photo_labels() as $key => $label) {
        $uploadedFiles = array_values(array_filter(
            uploaded_files_for_key($files, 'work_file_after_' . $key),
            static fn (?array $file): bool => uploaded_file_is_present($file)
        ));

        if ($uploadedFiles === [] && !connection_request_has_work_file_type($requestId, 'after', $key)) {
            $errors[] = $label . ' feltöltése kötelező.';
            continue;
        }

        foreach ($uploadedFiles as $file) {
            $errors = array_merge($errors, validate_portal_file_upload($file, $label, true));
        }
    }

    return $errors;
}

function store_connection_request_after_work_photo_uploads(int $requestId, array $files): array
{
    $messages = [];
    $saved = 0;
    $user = current_user();

    if (!is_array($user)) {
        return ['saved' => 0, 'messages' => ['A feltöltéshez be kell jelentkezni.']];
    }

    $targetDir = ELECTRICIAN_WORK_UPLOAD_PATH . '/' . $requestId . '/after';
    ensure_storage_dir($targetDir);

    foreach (connection_request_required_after_photo_labels() as $key => $label) {
        foreach (uploaded_files_for_key($files, 'work_file_after_' . $key) as $file) {
            if (!uploaded_file_is_present($file)) {
                continue;
            }

            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $messages[] = $label . ': a feltöltés sikertelen.';
                continue;
            }

            $validationErrors = validate_portal_file_upload($file, $label, true);

            if ($validationErrors !== []) {
                $messages = array_merge($messages, $validationErrors);
                continue;
            }

            $tmpName = (string) $file['tmp_name'];
            $originalName = (string) $file['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $extension = $extension === 'jpeg' ? 'jpg' : $extension;
            $storedName = $key . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
            $targetPath = $targetDir . '/' . $storedName;

            if (!move_uploaded_file($tmpName, $targetPath)) {
                $messages[] = $label . ': nem sikerült menteni.';
                continue;
            }

            $mimeType = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: '') : '';

            db_query(
                'INSERT INTO `connection_request_work_files`
                    (`connection_request_id`, `uploaded_by_user_id`, `stage`, `file_type`, `label`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $requestId,
                    (int) $user['id'],
                    'after',
                    $key,
                    $label,
                    $originalName,
                    $storedName,
                    $targetPath,
                    $mimeType !== '' ? $mimeType : (document_allowed_extensions()[$extension] ?? 'image/jpeg'),
                    (int) $file['size'],
                ]
            );
            $saved++;
        }
    }

    if ($saved > 0) {
        record_connection_request_activity($requestId, 'file_upload', 'Szerelői befejező fotók feltöltve', $saved . ' fájl');
    }

    return ['saved' => $saved, 'messages' => $messages];
}

function handle_electrician_work_uploads(int $requestId, string $stage, array $files): array
{
    $messages = [];
    $user = current_user();

    if (!is_array($user)) {
        return ['A feltöltéshez be kell jelentkezni.'];
    }

    $targetDir = ELECTRICIAN_WORK_UPLOAD_PATH . '/' . $requestId . '/' . $stage;
    ensure_storage_dir($targetDir);

    foreach (electrician_work_file_definitions($stage) as $key => $definition) {
        $uploadedFiles = uploaded_files_for_key($files, 'work_file_' . $stage . '_' . $key);

        foreach ($uploadedFiles as $file) {
            if (!uploaded_file_is_present($file)) {
                continue;
            }

            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $messages[] = $definition['label'] . ': a feltöltés sikertelen.';
                continue;
            }

            $validationErrors = validate_portal_file_upload($file, $definition['label'], true);

            if ($validationErrors !== []) {
                $messages = array_merge($messages, $validationErrors);
                continue;
            }

            $tmpName = (string) $file['tmp_name'];
            $originalName = (string) $file['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $extension = $extension === 'jpeg' ? 'jpg' : $extension;
            $storedName = $key . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
            $targetPath = $targetDir . '/' . $storedName;

            if (!move_uploaded_file($tmpName, $targetPath)) {
                $messages[] = $definition['label'] . ': nem sikerült menteni.';
                continue;
            }

            $mimeType = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: '') : '';

            db_query(
                'INSERT INTO `connection_request_work_files`
                    (`connection_request_id`, `uploaded_by_user_id`, `stage`, `file_type`, `label`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $requestId,
                    (int) $user['id'],
                    $stage,
                    $key,
                    $definition['label'],
                    $originalName,
                    $storedName,
                    $targetPath,
                    $mimeType !== '' ? $mimeType : (document_allowed_extensions()[$extension] ?? 'image/jpeg'),
                    (int) $file['size'],
                ]
            );
        }
    }

    return $messages;
}

function complete_electrician_work_stage(int $requestId, string $stage): void
{
    if ($stage === 'before') {
        db_query(
            'UPDATE `connection_requests`
             SET `before_photos_completed_at` = NOW(), `electrician_status` = ?
             WHERE `id` = ?',
            ['in_progress', $requestId]
        );
        return;
    }

    db_query(
        'UPDATE `connection_requests`
         SET `after_photos_completed_at` = NOW(), `electrician_status` = ?
         WHERE `id` = ?',
        ['completed', $requestId]
    );
}

function find_connection_request_work_file(int $fileId): ?array
{
    if (!db_table_exists('connection_request_work_files')) {
        return null;
    }

    $statement = db_query('SELECT * FROM `connection_request_work_files` WHERE `id` = ? LIMIT 1', [$fileId]);
    $file = $statement->fetch();

    return is_array($file) ? $file : null;
}

function delete_connection_request_work_file(int $fileId, int $requestId): array
{
    $file = find_connection_request_work_file($fileId);

    if ($file === null || (int) ($file['connection_request_id'] ?? 0) !== $requestId) {
        return ['ok' => false, 'message' => 'A törlendő munka fájl nem található.'];
    }

    db_query('DELETE FROM `connection_request_work_files` WHERE `id` = ?', [$fileId]);
    delete_storage_files([(string) ($file['storage_path'] ?? '')]);
    record_connection_request_activity(
        $requestId,
        'file_delete',
        'Szerelői munkafájl törölve',
        (string) ($file['original_name'] ?? $file['label'] ?? '')
    );

    return ['ok' => true, 'message' => 'A munka fájl törölve.'];
}

function portal_file_preview_kind(array $file): string
{
    $mimeType = strtolower((string) ($file['mime_type'] ?? ''));
    $extension = strtolower(pathinfo((string) ($file['original_name'] ?? $file['storage_path'] ?? ''), PATHINFO_EXTENSION));

    if (str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return 'image';
    }

    if (str_contains($mimeType, 'pdf') || $extension === 'pdf') {
        return 'pdf';
    }

    return 'document';
}

function portal_file_preview_extension(array $file): string
{
    $extension = strtoupper(pathinfo((string) ($file['original_name'] ?? $file['storage_path'] ?? ''), PATHINFO_EXTENSION));

    return $extension !== '' ? $extension : 'FÁJL';
}

function portal_file_uploader_label(array $file, string $fallback = 'Feltöltő ismeretlen'): string
{
    if (!empty($file['uploaded_by_role']) || !empty($file['uploaded_by_name']) || !empty($file['uploaded_by_email'])) {
        if (
            (string) ($file['uploaded_by_role'] ?? '') === 'guest'
            && trim((string) ($file['uploaded_by_name'] ?? '')) === ''
            && trim((string) ($file['uploaded_by_email'] ?? '')) === ''
            && empty($file['uploaded_by_user_id'])
        ) {
            return $fallback;
        }

        return actor_display_label_from_parts(
            (string) ($file['uploaded_by_role'] ?? ''),
            (string) ($file['uploaded_by_name'] ?? ''),
            (string) ($file['uploaded_by_email'] ?? ''),
            isset($file['uploaded_by_user_id']) ? (int) $file['uploaded_by_user_id'] : null
        );
    }

    if (!empty($file['uploaded_by_user_role']) || !empty($file['uploaded_by_user_name']) || !empty($file['uploaded_by_user_email'])) {
        $role = (string) ($file['uploaded_by_user_role'] ?? '');
        $name = (string) ($file['uploaded_by_user_name'] ?? '');

        if ($role === 'electrician' && !empty($file['uploaded_by_electrician_name'])) {
            $name = (string) $file['uploaded_by_electrician_name'];
        } elseif ($role === 'general_contractor' && !empty($file['uploaded_by_contractor_name'])) {
            $contractorName = (string) $file['uploaded_by_contractor_name'];
            $contactName = trim((string) ($file['uploaded_by_contractor_contact_name'] ?? ''));
            $name = $contactName !== '' && $contactName !== $contractorName
                ? $contractorName . ' - ' . $contactName
                : $contractorName;
        }

        return actor_display_label_from_parts(
            $role,
            $name,
            (string) ($file['uploaded_by_user_email'] ?? ''),
            isset($file['uploaded_by_user_id']) ? (int) $file['uploaded_by_user_id'] : null
        );
    }

    if (!empty($file['created_by_user_role']) || !empty($file['created_by_user_name']) || !empty($file['created_by_user_email'])) {
        return actor_display_label_from_parts(
            (string) ($file['created_by_user_role'] ?? ''),
            (string) ($file['created_by_user_name'] ?? ''),
            (string) ($file['created_by_user_email'] ?? ''),
            isset($file['created_by_user_id']) ? (int) $file['created_by_user_id'] : null
        );
    }

    if (!empty($file['uploaded_by_user_id'])) {
        return actor_label_for_user_id((int) $file['uploaded_by_user_id'], $fallback);
    }

    if (!empty($file['created_by_user_id'])) {
        return actor_label_for_user_id((int) $file['created_by_user_id'], $fallback);
    }

    return $fallback;
}

function find_connection_request_file(int $fileId): ?array
{
    $statement = db_query('SELECT * FROM `connection_request_files` WHERE `id` = ? LIMIT 1', [$fileId]);
    $file = $statement->fetch();

    return is_array($file) ? $file : null;
}

function delete_connection_request_file(int $fileId, int $requestId): array
{
    $file = find_connection_request_file($fileId);

    if ($file === null || (int) ($file['connection_request_id'] ?? 0) !== $requestId) {
        return ['ok' => false, 'message' => 'A törlendő fájl nem található.'];
    }

    db_query('DELETE FROM `connection_request_files` WHERE `id` = ?', [$fileId]);
    delete_storage_files([(string) ($file['storage_path'] ?? '')]);
    record_connection_request_activity(
        $requestId,
        'file_delete',
        'Ügyfél/adatlap fájl törölve',
        (string) ($file['original_name'] ?? $file['label'] ?? '')
    );

    return ['ok' => true, 'message' => 'A fájl törölve.'];
}

function finalize_connection_request(int $requestId): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.'];
    }

    if ((string) ($request['request_status'] ?? '') === 'finalized') {
        return ['ok' => true, 'message' => 'Az igény már korábban be lett küldve. A módosítások mentése után nem küldünk új lezárási értesítést.'];
    }

    if (!connection_request_is_editable($request)) {
        return ['ok' => false, 'message' => 'Ez az igény már le van zárva.'];
    }

    db_query(
        'UPDATE `connection_requests`
         SET `request_status` = ?, `closed_at` = NOW(), `submitted_at` = COALESCE(`submitted_at`, NOW()), `email_status` = ?
         WHERE `id` = ?',
        ['finalized', 'pending', $requestId]
    );

    return send_connection_request_email($requestId, true);
}

function send_connection_request_file_upload_notification(int $requestId, array $savedFiles): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        log_admin_notification_email(null, APP_NAME . ' dokumentumfeltöltés', 'failed', 'PHPMailer hiányzik.');
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $subject = APP_NAME . ' dokumentumfeltöltés - ' . $request['project_name'] . ' - ' . $request['requester_name'];
    $emailTitle = 'Új ügyféldokumentum érkezett';
    $emailLead = 'Új dokumentum vagy fotó került feltöltésre egy mérőhelyi igényhez. Az admin felületen letölthető.';
    $uploaderLabel = trim((string) ($savedFiles[0]['uploaded_by'] ?? ''));

    if ($uploaderLabel === '') {
        $uploaderLabel = actor_snapshot_label(current_actor_snapshot());
    }

    $emailSections = [
        [
            'title' => 'Igény adatai',
            'rows' => [
                ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                ['label' => 'Képviselő', 'value' => !empty($request['contractor_name']) ? (($request['contractor_name'] ?? '-') . "\n" . ($request['contractor_email'] ?? '-') . "\n" . ($request['contractor_phone'] ?? '-')) : 'Saját ügyfélfeltöltés'],
                ['label' => 'Státusz', 'value' => connection_request_status_label((string) ($request['request_status'] ?? 'draft'))],
            ],
        ],
        [
            'title' => 'Feltöltő',
            'rows' => [
                ['label' => 'Feltöltő', 'value' => $uploaderLabel],
                ['label' => 'Időpont', 'value' => date('Y-m-d H:i:s')],
            ],
        ],
        [
            'title' => 'Feltöltött fájlok',
            'items' => array_map(
                static fn (array $file): string => (string) ($file['label'] ?? 'Dokumentum') . ': ' . (string) ($file['original_name'] ?? '-') . ' - ' . (string) ($file['uploaded_by'] ?? ''),
                $savedFiles
            ),
        ],
    ];
    $emailActions = [
        ['label' => 'Munkák megnyitása', 'url' => absolute_url('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId)],
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $replyToEmail = !empty($request['contractor_email']) ? (string) $request['contractor_email'] : (string) $request['email'];
    $replyToName = !empty($request['contractor_contact_name'])
        ? (string) $request['contractor_contact_name']
        : (!empty($request['contractor_name']) ? (string) $request['contractor_name'] : (string) $request['requester_name']);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        add_admin_notification_recipients($mail);
        $mail->addReplyTo($replyToEmail, $replyToName);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions);
        $mail->send();

        log_admin_notification_email(null, $subject, 'sent');

        return ['ok' => true, 'message' => 'Admin értesítés elküldve.'];
    } catch (Throwable $exception) {
        log_admin_notification_email(null, $subject, 'failed', $exception->getMessage());

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az admin email értesítés küldése sikertelen.'];
    }
}

function send_electrician_work_stage_notification(int $requestId, string $stage): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $stageLabels = electrician_work_stage_labels();
    $stageLabel = $stageLabels[$stage] ?? $stage;
    $subject = APP_NAME . ' szerelői munkafotók - ' . $request['project_name'];
    $sections = [
        [
            'title' => 'Munka adatai',
            'rows' => [
                ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                ['label' => 'Cím', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                ['label' => 'Állapot', 'value' => electrician_work_status_label((string) ($request['electrician_status'] ?? 'unassigned'))],
                ['label' => 'Csomag', 'value' => $stageLabel],
            ],
        ],
    ];
    $actions = [
        ['label' => 'Munkák megnyitása', 'url' => absolute_url('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId)],
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        add_admin_notification_recipients($mail);
        $mail->Subject = $subject;
        apply_branded_email($mail, $stageLabel . ' feltöltve', 'A szerelő feltöltötte a kötelező munkafotók csomagját. Az admin felületen ellenőrizhető.', $sections, $actions);
        $mail->send();

        log_admin_notification_email(null, $subject, 'sent');

        return ['ok' => true, 'message' => 'Admin értesítés elküldve.'];
    } catch (Throwable $exception) {
        log_admin_notification_email(null, $subject, 'failed', $exception->getMessage());

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az admin email értesítés küldése sikertelen.'];
    }
}

function connection_request_status_labels(): array
{
    return [
        'draft' => 'Szerkesztés alatt',
        'finalized' => 'Lezárt, végleges igény',
    ];
}

function connection_request_status_label(string $status): string
{
    $labels = connection_request_status_labels();

    return $labels[$status] ?? $status;
}

function mvm_document_types(bool $includeSystemTypes = true): array
{
    $types = [
        'submitted_request' => 'Beküldött igény',
        'accepted_request' => 'Elfogadott igény',
        'authorization' => 'Meghatalmazás',
        'execution_plan' => 'Kiviteli terv dokumentáció',
        'intervention_sheet' => 'Új beavatkozási lap',
        'completed_intervention_sheet' => 'Kész beavatkozási lap',
        'construction_log' => 'Építési napló',
        'technical_handover' => 'Műszaki átadás-átvételi jegyzőkönyv',
        'technical_declaration' => 'Nyilatkozatok adatlap',
        'h_tariff_declaration' => 'H tarifa nyilatkozat',
        'seal_removal' => 'Plombabontási engedély',
    ];

    if ($includeSystemTypes) {
        $types['complete_package'] = 'MVM jóváhagyási csomag';
        $types['execution_plan_package'] = 'Kiviteli terv csomag';
        $types['technical_handover_package'] = 'Műszaki átadás csomag';
        $types['seal_removal_package'] = 'Plombabontás csomag';
    }

    return $types;
}

function mvm_document_type_keys(): array
{
    return [
        'submitted_request',
        'accepted_request',
        'authorization',
        'execution_plan',
        'intervention_sheet',
        'completed_intervention_sheet',
        'construction_log',
        'technical_handover',
        'technical_declaration',
        'h_tariff_declaration',
        'seal_removal',
        'complete_package',
        'execution_plan_package',
        'technical_handover_package',
        'seal_removal_package',
    ];
}

function mvm_document_is_mvm_sendable_package(string $documentType): bool
{
    return in_array($documentType, ['complete_package', 'execution_plan_package', 'technical_handover_package', 'seal_removal_package'], true);
}

function mvm_document_type_enum_sql(): string
{
    return "ENUM('" . implode("', '", mvm_document_type_keys()) . "')";
}

function mvm_submission_guard_schema_errors(): array
{
    if (!db_table_exists('connection_requests')) {
        return ['Hiányzik a connection_requests tábla.'];
    }

    $columns = [
        'mvm_fee_payment_confirmed_at' => 'ALTER TABLE `connection_requests` ADD COLUMN `mvm_fee_payment_confirmed_at` DATETIME DEFAULT NULL AFTER `admin_workflow_stage`',
        'mvm_fee_payment_confirmed_by_user_id' => 'ALTER TABLE `connection_requests` ADD COLUMN `mvm_fee_payment_confirmed_by_user_id` INT UNSIGNED NULL AFTER `mvm_fee_payment_confirmed_at`',
        'mvm_fee_payment_note' => 'ALTER TABLE `connection_requests` ADD COLUMN `mvm_fee_payment_note` TEXT DEFAULT NULL AFTER `mvm_fee_payment_confirmed_by_user_id`',
    ];

    try {
        foreach ($columns as $column => $sql) {
            if (!db_column_exists('connection_requests', $column)) {
                db_query($sql);
            }
        }
    } catch (Throwable $exception) {
        return [
            APP_DEBUG
                ? 'Az MVM ügyindítási jóváhagyás mezőinek automatikus létrehozása nem sikerült: ' . $exception->getMessage()
                : 'Az MVM ügyindítási jóváhagyás adatbázis frissítése szükséges.',
        ];
    }

    return [];
}

function connection_request_mvm_fee_payment_is_confirmed(array $request): bool
{
    return trim((string) ($request['mvm_fee_payment_confirmed_at'] ?? '')) !== '';
}

function connection_request_mvm_fee_payment_approver_label(array $request): string
{
    $userId = (int) ($request['mvm_fee_payment_confirmed_by_user_id'] ?? 0);

    if ($userId <= 0 || !users_table_exists()) {
        return 'Admin';
    }

    $user = db_query('SELECT `name`, `email`, `role`, `is_admin` FROM `users` WHERE `id` = ? LIMIT 1', [$userId])->fetch();

    if (!is_array($user)) {
        return 'Admin';
    }

    $role = !empty($user['is_admin']) ? 'admin' : (string) ($user['role'] ?? 'admin');

    return actor_display_label_from_parts($role, (string) ($user['name'] ?? ''), (string) ($user['email'] ?? ''));
}

function connection_request_mvm_submission_guard_message(?array $request = null): string
{
    return 'Az MVM dokumentumok generálása és MVM felé küldése zárolva van, amíg egy admin nem rögzíti, hogy az ügykezelési díj beérkezett.';
}

function connection_request_mvm_submission_is_allowed(array $request): bool
{
    return connection_request_mvm_fee_payment_is_confirmed($request);
}

function connection_request_mvm_submission_guard_result(int $requestId): ?array
{
    $schemaErrors = mvm_submission_guard_schema_errors();

    if ($schemaErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $schemaErrors), 'document_id' => null];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    if (!connection_request_mvm_submission_is_allowed($request)) {
        return ['ok' => false, 'message' => connection_request_mvm_submission_guard_message($request), 'document_id' => null];
    }

    return null;
}

function confirm_connection_request_mvm_fee_payment(int $requestId, string $note = ''): array
{
    $schemaErrors = mvm_submission_guard_schema_errors();

    if ($schemaErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $schemaErrors)];
    }

    if (!is_staff_user()) {
        return ['ok' => false, 'message' => 'Az ügykezelési díj beérkezését csak admin rögzítheti.'];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'A munka nem található.'];
    }

    $user = current_user();
    $userId = is_array($user) ? (int) $user['id'] : null;
    db_query(
        'UPDATE `connection_requests`
         SET `mvm_fee_payment_confirmed_at` = NOW(),
             `mvm_fee_payment_confirmed_by_user_id` = ?,
             `mvm_fee_payment_note` = ?
         WHERE `id` = ?',
        [
            $userId !== null && $userId > 0 ? $userId : null,
            trim($note) !== '' ? trim($note) : null,
            $requestId,
        ]
    );

    record_connection_request_activity(
        $requestId,
        'payment_confirmed',
        'Ügykezelési díj beérkezése jóváhagyva',
        trim($note)
    );

    return ['ok' => true, 'message' => 'Az ügykezelési díj beérkezése rögzítve. Az MVM dokumentumgenerálás és beküldés engedélyezve van.'];
}

function mvm_document_schema_errors(): array
{
    if (!db_table_exists('connection_request_documents')) {
        return ['Hianyzik a connection_request_documents tabla.'];
    }

    $guardSchemaErrors = mvm_submission_guard_schema_errors();

    if ($guardSchemaErrors !== []) {
        return $guardSchemaErrors;
    }

    try {
        $columnType = (string) (db_query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [DB_NAME, 'connection_request_documents', 'document_type']
        )->fetchColumn() ?: '');
        $missingTypes = [];

        foreach (mvm_document_type_keys() as $requiredType) {
            if (!str_contains($columnType, "'" . $requiredType . "'")) {
                $missingTypes[] = $requiredType;
            }
        }

        if ($missingTypes === []) {
            return [];
        }

        db_query('ALTER TABLE `connection_request_documents` MODIFY COLUMN `document_type` ' . mvm_document_type_enum_sql() . ' NOT NULL');
        $updatedColumnType = (string) (db_query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [DB_NAME, 'connection_request_documents', 'document_type']
        )->fetchColumn() ?: '');

        foreach (mvm_document_type_keys() as $requiredType) {
            if (!str_contains($updatedColumnType, "'" . $requiredType . "'")) {
                return ['A connection_request_documents.document_type mező frissítése szükséges. Futtasd újra a database/upgrade_connection_requests.sql fájlt.'];
            }
        }
    } catch (Throwable $exception) {
        return [
            APP_DEBUG
                ? 'A connection_request_documents.document_type automatikus frissítése nem sikerült: ' . $exception->getMessage()
                : 'A connection_request_documents.document_type mező frissítése szükséges. Futtasd újra a database/upgrade_connection_requests.sql fájlt.',
        ];
    }

    return [];
}

function mvm_form_schema_errors(): array
{
    if (!db_table_exists('connection_request_mvm_forms')) {
        return ['Hiányzik a connection_request_mvm_forms tábla.'];
    }

    return [];
}

function mvm_config_value(string $key, string $default = ''): string
{
    $environmentValue = getenv($key);

    if ($environmentValue !== false && $environmentValue !== '') {
        return (string) $environmentValue;
    }

    static $localConfig = null;

    if ($localConfig === null) {
        $localConfig = [];
        $localConfigPaths = defined('STORAGE_PATH')
            ? [
                STORAGE_PATH . '/config/local.php',
                STORAGE_PATH . '/config/local.secret.php',
            ]
            : [];

        foreach ($localConfigPaths as $localConfigPath) {
            if ($localConfigPath === '' || !is_file($localConfigPath)) {
                continue;
            }

            try {
                $loadedLocalConfig = require $localConfigPath;
            } catch (Throwable) {
                continue;
            }

            if (is_array($loadedLocalConfig)) {
                $localConfig = array_replace($localConfig, $loadedLocalConfig);
            }
        }
    }

    return array_key_exists($key, $localConfig) ? (string) $localConfig[$key] : $default;
}

function mvm_mail_reply_address(): string
{
    $configured = trim(mvm_config_value('MVM_REPLY_EMAIL', mvm_config_value('MVM_MAILBOX_USER', 'csatlakozo@mvm-mezoenergy.hu')));

    return $configured !== '' ? $configured : MAIL_FROM;
}

function mvm_mail_schema_errors(): array
{
    try {
        if (!db_table_exists('mvm_email_threads')) {
            db_query(
                "CREATE TABLE IF NOT EXISTS `mvm_email_threads` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `connection_request_id` INT UNSIGNED NOT NULL,
                    `connection_request_document_id` INT UNSIGNED NULL,
                    `token` VARCHAR(90) NOT NULL,
                    `mvm_recipient` VARCHAR(190) NOT NULL,
                    `subject` VARCHAR(255) NOT NULL,
                    `document_label` VARCHAR(190) NOT NULL,
                    `status` ENUM('sent', 'replied', 'failed') NOT NULL DEFAULT 'sent',
                    `sent_message_id` VARCHAR(255) DEFAULT NULL,
                    `last_message_at` DATETIME DEFAULT NULL,
                    `created_by_user_id` INT UNSIGNED NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `ux_mvm_email_threads_token` (`token`),
                    KEY `idx_mvm_email_threads_request` (`connection_request_id`, `created_at`),
                    KEY `idx_mvm_email_threads_document` (`connection_request_document_id`),
                    KEY `idx_mvm_email_threads_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!db_table_exists('mvm_email_messages')) {
            db_query(
                "CREATE TABLE IF NOT EXISTS `mvm_email_messages` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `thread_id` INT UNSIGNED NOT NULL,
                    `connection_request_id` INT UNSIGNED NOT NULL,
                    `direction` ENUM('outbound', 'inbound') NOT NULL,
                    `mailbox_uid` VARCHAR(190) DEFAULT NULL,
                    `message_id` VARCHAR(255) DEFAULT NULL,
                    `in_reply_to` TEXT DEFAULT NULL,
                    `sender_email` VARCHAR(190) DEFAULT NULL,
                    `sender_name` VARCHAR(190) DEFAULT NULL,
                    `recipient_email` VARCHAR(190) DEFAULT NULL,
                    `subject` VARCHAR(255) NOT NULL,
                    `body_text` MEDIUMTEXT DEFAULT NULL,
                    `body_html` MEDIUMTEXT DEFAULT NULL,
                    `raw_headers` MEDIUMTEXT DEFAULT NULL,
                    `received_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `ux_mvm_email_messages_uid` (`mailbox_uid`),
                    KEY `idx_mvm_email_messages_thread` (`thread_id`, `created_at`),
                    KEY `idx_mvm_email_messages_request` (`connection_request_id`, `created_at`),
                    KEY `idx_mvm_email_messages_message_id` (`message_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    } catch (Throwable $exception) {
        return [
            APP_DEBUG
                ? 'Az MVM levelezési táblák automatikus létrehozása nem sikerült: ' . $exception->getMessage()
                : 'Az MVM levelezési táblák létrehozása szükséges.',
        ];
    }

    return [];
}

function mvm_email_schema_is_ready(): bool
{
    return mvm_mail_schema_errors() === [];
}

function mvm_email_human_identifier(array $request): string
{
    $parts = [
        $request['requester_name'] ?? '',
        trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? '')),
        connection_request_type_label($request['request_type'] ?? null),
    ];
    $label = trim(implode('_', array_filter(array_map('trim', $parts))));
    $label = (string) preg_replace('/[^\p{L}\p{N}]+/u', '_', $label);
    $label = trim($label, '_');

    if ($label === '') {
        $label = 'igeny_' . (int) ($request['id'] ?? 0);
    }

    if (function_exists('mb_substr')) {
        return mb_substr($label, 0, 120, 'UTF-8');
    }

    return substr($label, 0, 120);
}

function mvm_email_token(int $requestId, int $documentId): string
{
    return 'MEZO-IGENY-' . $requestId . '-DOK-' . $documentId . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function customer_email_thread_token(int $requestId, string $purpose): string
{
    $purpose = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', $purpose));
    $purpose = trim($purpose, '-');
    $purpose = $purpose !== '' ? $purpose : 'UZENET';
    $hash = strtoupper(substr(hash('sha256', $requestId . '|' . $purpose . '|' . mvm_mail_reply_address()), 0, 8));

    return 'MEZO-UGYFEL-' . $requestId . '-' . $hash;
}

function customer_email_thread_subject(string $subject, string $token): string
{
    return str_contains($subject, '[' . $token . ']') ? $subject : $subject . ' [' . $token . ']';
}

function record_customer_email_thread(
    int $requestId,
    string $token,
    string $recipientEmail,
    string $subject,
    string $label,
    string $bodyText,
    string $bodyHtml,
    ?string $messageId = null
): void {
    if (!mvm_email_schema_is_ready()) {
        return;
    }

    try {
        $user = current_user();
        db_query(
            'INSERT INTO `mvm_email_threads`
                (`connection_request_id`, `connection_request_document_id`, `token`, `mvm_recipient`, `subject`, `document_label`, `status`, `sent_message_id`, `last_message_at`, `created_by_user_id`)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                `mvm_recipient` = VALUES(`mvm_recipient`),
                `subject` = VALUES(`subject`),
                `document_label` = VALUES(`document_label`),
                `status` = IF(`status` = \'replied\', `status`, VALUES(`status`)),
                `sent_message_id` = COALESCE(VALUES(`sent_message_id`), `sent_message_id`),
                `last_message_at` = COALESCE(`last_message_at`, VALUES(`last_message_at`))',
            [
                $requestId,
                $token,
                $recipientEmail,
                $subject,
                $label,
                'sent',
                $messageId,
                is_array($user) ? (int) $user['id'] : null,
            ]
        );

        $thread = db_query('SELECT * FROM `mvm_email_threads` WHERE `token` = ? LIMIT 1', [$token])->fetch();

        if (!is_array($thread)) {
            return;
        }

        db_query(
            'INSERT INTO `mvm_email_messages`
                (`thread_id`, `connection_request_id`, `direction`, `message_id`, `sender_email`, `sender_name`, `recipient_email`, `subject`, `body_text`, `body_html`, `received_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $thread['id'],
                $requestId,
                'outbound',
                $messageId !== '' ? $messageId : null,
                mvm_mail_reply_address(),
                MAIL_FROM_NAME,
                $recipientEmail,
                $subject,
                $bodyText,
                $bodyHtml,
            ]
        );
    } catch (Throwable) {
        // Az email kiküldés ne bukjon el attól, ha a naplózás átmenetileg nem írható.
    }
}

function mvm_recipient_config_key_for_document_type(string $documentType): string
{
    $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $documentType));

    return 'MVM_RECIPIENT_' . trim($normalized, '_');
}

function default_mvm_document_recipient(string $documentType): string
{
    $specific = trim(mvm_config_value(mvm_recipient_config_key_for_document_type($documentType), ''));

    if ($specific !== '') {
        return $specific;
    }

    return trim(mvm_config_value('MVM_DEFAULT_RECIPIENT', ''));
}

function mvm_email_thread_status_labels(): array
{
    return [
        'sent' => 'Elküldve, válaszra vár',
        'replied' => 'Válasz érkezett',
        'failed' => 'Küldési hiba',
    ];
}

function mvm_email_threads_for_request(int $requestId): array
{
    if (!mvm_email_schema_is_ready()) {
        return [];
    }

    return db_query(
        'SELECT * FROM `mvm_email_threads`
         WHERE `connection_request_id` = ?
         ORDER BY COALESCE(`last_message_at`, `created_at`) DESC, `id` DESC',
        [$requestId]
    )->fetchAll();
}

function mvm_email_messages_for_thread(int $threadId): array
{
    if (!mvm_email_schema_is_ready()) {
        return [];
    }

    return db_query(
        'SELECT * FROM `mvm_email_messages`
         WHERE `thread_id` = ?
         ORDER BY COALESCE(`received_at`, `created_at`) ASC, `id` ASC',
        [$threadId]
    )->fetchAll();
}

function mvm_email_threads_with_messages(int $requestId): array
{
    $threads = mvm_email_threads_for_request($requestId);

    foreach ($threads as &$thread) {
        $thread['messages'] = mvm_email_messages_for_thread((int) $thread['id']);
    }
    unset($thread);

    return $threads;
}

function latest_mvm_email_message_preview(array $thread): string
{
    $messages = is_array($thread['messages'] ?? null) ? $thread['messages'] : [];
    $last = $messages !== [] ? $messages[array_key_last($messages)] : null;
    $body = is_array($last) ? trim(strip_tags((string) (($last['body_text'] ?? '') ?: ($last['body_html'] ?? '')))) : '';

    if ($body === '') {
        return 'Még nincs beérkezett válasz ehhez a küldéshez.';
    }

    $body = preg_replace('/\s+/', ' ', $body) ?: $body;

    if (function_exists('mb_substr')) {
        return mb_substr($body, 0, 240, 'UTF-8');
    }

    return substr($body, 0, 240);
}

function connection_request_message_recipient(array $request, string $recipient, string $customerEmail = '', string $customerName = ''): ?array
{
    $recipient = trim($recipient);

    if ($recipient === 'customer') {
        $email = trim($customerEmail) !== '' ? trim($customerEmail) : trim((string) ($request['email'] ?? ''));
        $name = trim($customerName) !== '' ? trim($customerName) : (string) ($request['requester_name'] ?? '');

        return $email !== '' ? [
            'email' => $email,
            'name' => email_recipient_name($name),
            'label' => 'ügyfél',
            'purpose' => 'customer-message',
            'thread_label' => 'Ügyfél kommunikáció',
        ] : null;
    }

    if ($recipient === 'responsible') {
        $electricianUserId = (int) ($request['assigned_electrician_user_id'] ?? 0);
        $electrician = $electricianUserId > 0 ? find_electrician_by_user($electricianUserId) : null;
        $user = $electricianUserId > 0 ? find_user_by_id($electricianUserId) : null;
        $email = trim((string) ($electrician['email'] ?? $user['email'] ?? ''));
        $name = trim((string) ($electrician['name'] ?? $user['name'] ?? ''));

        return $email !== '' ? [
            'email' => $email,
            'name' => $name,
            'label' => 'adatlap felelős',
            'purpose' => 'responsible-message',
            'thread_label' => 'Adatlap felelős kommunikáció',
        ] : null;
    }

    return null;
}

function send_connection_request_manual_message(int $requestId, string $recipient, string $subject, string $message, string $customerEmail = '', string $customerName = ''): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az adatlap nem található.'];
    }

    $recipient = trim($recipient);
    $message = trim($message);

    if ($message === '') {
        return ['ok' => false, 'message' => 'Az üzenet szövege kötelező.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $recipientData = connection_request_message_recipient($request, $recipient, $customerEmail, $customerName);

    if ($recipientData === null) {
        return ['ok' => false, 'message' => $recipient === 'responsible'
            ? 'Az adatlap felelősének nincs elérhető email címe, vagy nincs szerelőhöz rendelve.'
            : 'Az ügyfél email címe hiányzik.'];
    }

    if (!filter_var((string) $recipientData['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'A címzett email címe nem érvényes.'];
    }

    $token = customer_email_thread_token($requestId, (string) $recipientData['purpose']);
    $subject = trim($subject) !== ''
        ? trim($subject)
        : APP_NAME . ' üzenet - ' . (string) ($request['project_name'] ?? ('Munka #' . $requestId));
    $subject = customer_email_thread_subject($subject, $token);
    $replyAddress = mvm_mail_reply_address();
    $recipientName = email_recipient_name($recipientData['name'] ?? '');
    $actor = current_actor_snapshot();
    $actorLabel = actor_snapshot_label($actor);
    $sections = [
        [
            'title' => 'Adatlap',
            'rows' => [
                ['label' => 'Munka', 'value' => $request['project_name'] ?? '-'],
                ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                ['label' => 'Címzett email', 'value' => (string) $recipientData['email']],
                ['label' => 'Cím', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                ['label' => 'Küldő', 'value' => $actorLabel],
                ['label' => 'Válaszazonosító', 'value' => $token],
            ],
        ],
        [
            'title' => 'Üzenet',
            'lead' => $message,
        ],
    ];
    $actions = $recipient === 'customer'
        ? [['label' => 'Ügyfélportál megnyitása', 'url' => absolute_url('/customer/work-requests')]]
        : [['label' => 'Adatlap megnyitása', 'url' => absolute_url('/electrician/work-request?id=' . $requestId)]];
    $emailTitle = 'Új üzenet érkezett';
    $emailLead = 'Az adatlapról küldött üzenetet alább találod. Ha erre az emailre válasz érkezik, az automatikusan ehhez az adatlaphoz kerül a kommunikációs előzmények közé.';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress((string) $recipientData['email'], $recipientName);
        $mail->addReplyTo($replyAddress, MAIL_FROM_NAME);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $sections, $actions, $recipientName);
        $mail->send();
        $messageId = method_exists($mail, 'getLastMessageID') ? (string) $mail->getLastMessageID() : '';

        record_customer_email_thread(
            $requestId,
            $token,
            (string) $recipientData['email'],
            $subject,
            (string) $recipientData['thread_label'],
            branded_email_text($emailTitle, $emailLead, $sections, $actions, $recipientName),
            branded_email_html($emailTitle, $emailLead, $sections, $actions, $recipientName),
            $messageId !== '' ? $messageId : null
        );
        record_connection_request_activity(
            $requestId,
            'message',
            'Üzenet küldve: ' . (string) $recipientData['label'],
            'Címzett: ' . (string) $recipientData['email'] . "\n\n" . $message
        );
        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, (string) $recipientData['email'], $subject, 'sent']);

        return ['ok' => true, 'message' => 'Az üzenetet elküldtük a címzettnek, és bekerült az adatlap előzményei közé.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, (string) $recipientData['email'], $subject, 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az üzenet küldése sikertelen.'];
    }
}

function connection_request_timeline_events(array $request): array
{
    $requestId = (int) ($request['id'] ?? 0);
    $events = [];
    $createdAt = trim((string) ($request['created_at'] ?? ''));

    if ($createdAt !== '') {
        $events[] = [
            'kind' => 'system',
            'date' => $createdAt,
            'title' => 'Adatlap létrejött',
            'actor' => connection_request_submitter_label($request),
            'body' => (string) ($request['project_name'] ?? 'Mérőhelyi igény'),
            'sort' => strtotime($createdAt) ?: 0,
        ];
    }

    foreach (connection_request_activity_logs($requestId) as $log) {
        $date = (string) ($log['created_at'] ?? '');
        $events[] = [
            'kind' => (string) ($log['event_type'] ?? 'activity'),
            'date' => $date,
            'title' => (string) ($log['title'] ?? 'Adatlap esemény'),
            'actor' => (string) ($log['actor_label'] ?? 'Rendszer'),
            'body' => (string) ($log['body'] ?? ''),
            'sort' => strtotime($date) ?: 0,
        ];
    }

    foreach (quotes_for_connection_request($requestId) as $quote) {
        $quoteId = (int) ($quote['id'] ?? 0);
        $quoteNumber = (string) ($quote['quote_number'] ?? ('#' . $quoteId));
        $quoteSubject = (string) ($quote['subject'] ?? 'Árajánlat');
        $quoteTotal = quote_display_total($quote);
        $createdAt = trim((string) ($quote['created_at'] ?? ''));
        $latestSentLog = $quoteId > 0 ? quote_latest_email_log($quoteId, 'sent') : null;
        $sentAt = trim((string) ($latestSentLog['created_at'] ?? '')) ?: trim((string) ($quote['sent_at'] ?? ''));
        $emailOpenedAt = trim((string) ($quote['email_opened_at'] ?? ''));
        $viewedAt = trim((string) ($quote['viewed_at'] ?? ''));

        if ($createdAt !== '') {
            $events[] = [
                'kind' => 'quote',
                'date' => $createdAt,
                'title' => 'Árajánlat készült',
                'actor' => (string) ($quote['requester_name'] ?? 'Ajánlat'),
                'body' => $quoteNumber . ' - ' . $quoteSubject . "\n" . $quoteTotal,
                'sort' => strtotime($createdAt) ?: 0,
            ];
        }

        if ($sentAt !== '') {
            $events[] = [
                'kind' => 'quote-sent',
                'date' => $sentAt,
                'title' => 'Árajánlat email elküldve',
                'actor' => 'Címzett: ' . (string) ($quote['email'] ?? '-'),
                'body' => $quoteNumber . ' - ' . $quoteSubject,
                'sort' => strtotime($sentAt) ?: 0,
            ];
        }

        if ($emailOpenedAt !== '') {
            $events[] = [
                'kind' => 'quote-opened',
                'date' => $emailOpenedAt,
                'title' => 'Árajánlat email megnyitva',
                'actor' => (string) ($quote['requester_name'] ?? 'Ügyfél'),
                'body' => $quoteNumber . quote_engagement_count_label($quote['email_open_count'] ?? 0),
                'sort' => strtotime($emailOpenedAt) ?: 0,
            ];
        }

        if ($viewedAt !== '') {
            $events[] = [
                'kind' => 'quote-viewed',
                'date' => $viewedAt,
                'title' => 'Árajánlat oldal megnyitva',
                'actor' => (string) ($quote['requester_name'] ?? 'Ügyfél'),
                'body' => $quoteNumber . quote_engagement_count_label($quote['view_count'] ?? 0),
                'sort' => strtotime($viewedAt) ?: 0,
            ];
        }
    }

    foreach (mvm_email_threads_with_messages($requestId) as $thread) {
        foreach (($thread['messages'] ?? []) as $message) {
            $date = (string) (($message['received_at'] ?? '') ?: ($message['created_at'] ?? ''));
            $direction = (string) ($message['direction'] ?? '');
            $sender = trim((string) (($message['sender_name'] ?? '') ?: ($message['sender_email'] ?? '')));
            $recipient = trim((string) ($message['recipient_email'] ?? ''));
            $body = latest_mvm_email_message_preview(['messages' => [$message]]);
            $events[] = [
                'kind' => $direction === 'inbound' ? 'message-inbound' : 'message-outbound',
                'date' => $date,
                'title' => $direction === 'inbound' ? 'Bejövő email' : (string) ($thread['document_label'] ?? 'Kimenő email'),
                'actor' => $direction === 'inbound'
                    ? ($sender !== '' ? $sender : 'Bejövő levél')
                    : ($recipient !== '' ? 'Címzett: ' . $recipient : 'Kimenő levél'),
                'body' => $body,
                'sort' => strtotime($date) ?: 0,
            ];
        }
    }

    usort($events, static fn (array $a, array $b): int => ((int) ($b['sort'] ?? 0)) <=> ((int) ($a['sort'] ?? 0)));

    return $events;
}

function send_connection_request_document_to_mvm(int $documentId, string $recipientEmail, string $note = ''): array
{
    $schemaErrors = mvm_mail_schema_errors();

    if ($schemaErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $schemaErrors)];
    }

    $document = find_connection_request_document($documentId);

    if ($document === null) {
        return ['ok' => false, 'message' => 'A küldendő dokumentum nem található.'];
    }

    $request = find_connection_request((int) $document['connection_request_id']);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.'];
    }

    if (!connection_request_mvm_submission_is_allowed($request)) {
        return ['ok' => false, 'message' => connection_request_mvm_submission_guard_message($request)];
    }

    $recipientEmail = trim($recipientEmail);

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Adj meg érvényes MVM email címet.'];
    }

    if (!is_file((string) $document['storage_path'])) {
        return ['ok' => false, 'message' => 'A dokumentum fájlja nem található a tárhelyen.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $types = mvm_document_types();
    $documentLabel = $types[$document['document_type']] ?? (string) $document['title'];
    $token = mvm_email_token((int) $request['id'], $documentId);
    $subject = 'MVM dokumentum - ' . mvm_email_human_identifier($request) . ' - [' . $token . ']';
    $replyAddress = mvm_mail_reply_address();
    $emailLead = 'Csatolva küldjük az adott mérőhelyi igényhez tartozó dokumentumot. Kérjük, válaszában hagyja változatlanul a tárgyban szereplő azonosítót, hogy a válasz automatikusan az ügyfél adatlapjára kerüljön.';
    $sections = [
        [
            'title' => 'Igény azonosítása',
            'rows' => [
                ['label' => 'CRM azonosító', 'value' => '#' . (int) $request['id']],
                ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                ['label' => 'Feladat', 'value' => connection_request_type_label($request['request_type'] ?? null)],
                ['label' => 'Cím', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                ['label' => 'Dokumentum', 'value' => $documentLabel . "\n" . ($document['original_name'] ?? '-')],
                ['label' => 'Válaszazonosító', 'value' => $token],
            ],
        ],
    ];

    if (trim($note) !== '') {
        $sections[] = [
            'title' => 'Megjegyzés',
            'rows' => [
                ['label' => 'Üzenet', 'value' => trim($note)],
            ],
        ];
    }

    $user = current_user();
    db_query(
        'INSERT INTO `mvm_email_threads`
            (`connection_request_id`, `connection_request_document_id`, `token`, `mvm_recipient`, `subject`, `document_label`, `status`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            (int) $request['id'],
            $documentId,
            $token,
            $recipientEmail,
            $subject,
            $documentLabel,
            'failed',
            is_array($user) ? (int) $user['id'] : null,
        ]
    );
    $threadId = (int) db()->lastInsertId();

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($replyAddress, MAIL_FROM_NAME);
        $mail->addAddress($recipientEmail);
        $mail->addReplyTo($replyAddress, MAIL_FROM_NAME);
        $mail->Subject = $subject;
        apply_branded_email($mail, 'MVM dokumentum küldése', $emailLead, $sections);
        $mail->addAttachment((string) $document['storage_path'], (string) $document['original_name']);
        $mail->send();

        $messageId = method_exists($mail, 'getLastMessageID') ? (string) $mail->getLastMessageID() : '';
        db_query(
            'UPDATE `mvm_email_threads`
             SET `status` = ?, `sent_message_id` = ?, `last_message_at` = NOW()
             WHERE `id` = ?',
            ['sent', $messageId !== '' ? $messageId : null, $threadId]
        );
        db_query(
            'INSERT INTO `mvm_email_messages`
                (`thread_id`, `connection_request_id`, `direction`, `message_id`, `sender_email`, `sender_name`, `recipient_email`, `subject`, `body_text`, `body_html`, `received_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $threadId,
                (int) $request['id'],
                'outbound',
                $messageId !== '' ? $messageId : null,
                $replyAddress,
                MAIL_FROM_NAME,
                $recipientEmail,
                $subject,
                branded_email_text('MVM dokumentum küldése', $emailLead, $sections),
                branded_email_html('MVM dokumentum küldése', $emailLead, $sections),
            ]
        );
        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, $recipientEmail, $subject, 'sent']);
        record_connection_request_activity(
            (int) $request['id'],
            'mvm_submission',
            'MVM dokumentum elküldve',
            $documentLabel . ' -> ' . $recipientEmail
        );

        return ['ok' => true, 'message' => 'A dokumentum elküldve az MVM részére.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, $recipientEmail, $subject, 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az MVM email küldése sikertelen.'];
    }
}

function mvm_imap_mailbox_string(): string
{
    $host = trim(mvm_config_value('MVM_IMAP_HOST', 'mail.nethely.hu'));
    $port = (int) mvm_config_value('MVM_IMAP_PORT', '993');
    $folder = trim(mvm_config_value('MVM_IMAP_FOLDER', 'INBOX'));
    $encryption = strtolower(trim(mvm_config_value('MVM_IMAP_ENCRYPTION', 'ssl')));
    $noValidate = filter_var(mvm_config_value('MVM_IMAP_NOVALIDATE_CERT', '0'), FILTER_VALIDATE_BOOLEAN);
    $flags = '/imap';

    if ($encryption === 'ssl' || $encryption === 'tls') {
        $flags .= '/' . $encryption;
    } elseif ($encryption === 'none') {
        $flags .= '/notls';
    }

    if ($noValidate) {
        $flags .= '/novalidate-cert';
    }

    return '{' . $host . ':' . $port . $flags . '}' . $folder;
}

function mvm_decode_mime_header(string $value): string
{
    if (!function_exists('imap_mime_header_decode')) {
        return trim($value);
    }

    $parts = @imap_mime_header_decode($value);

    if (!is_array($parts)) {
        return trim($value);
    }

    $decoded = '';

    foreach ($parts as $part) {
        $text = (string) ($part->text ?? '');
        $charset = strtoupper((string) ($part->charset ?? ''));

        if ($charset !== '' && $charset !== 'DEFAULT' && $charset !== 'UTF-8' && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            $text = $converted !== false ? $converted : $text;
        }

        $decoded .= $text;
    }

    return trim($decoded);
}

function mvm_decode_imap_body(string $body, int $encoding): string
{
    return match ($encoding) {
        3 => (string) base64_decode($body, true),
        4 => quoted_printable_decode($body),
        default => $body,
    };
}

function mvm_imap_collect_body_parts(object $structure, string $prefix = ''): array
{
    $parts = [];

    if (empty($structure->parts) || !is_array($structure->parts)) {
        $subtype = strtolower((string) ($structure->subtype ?? 'plain'));
        $parts[] = [
            'part' => $prefix !== '' ? $prefix : '1',
            'type' => strtolower((string) ($structure->type ?? 0)) === '1' ? 'multipart' : 'text/' . $subtype,
            'encoding' => (int) ($structure->encoding ?? 0),
        ];

        return $parts;
    }

    foreach ($structure->parts as $index => $part) {
        $partNumber = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);
        $type = (int) ($part->type ?? 0);
        $subtype = strtolower((string) ($part->subtype ?? 'plain'));

        if ($type === 0 && in_array($subtype, ['plain', 'html'], true)) {
            $parts[] = [
                'part' => $partNumber,
                'type' => 'text/' . $subtype,
                'encoding' => (int) ($part->encoding ?? 0),
            ];
            continue;
        }

        if (!empty($part->parts)) {
            $parts = array_merge($parts, mvm_imap_collect_body_parts($part, $partNumber));
        }
    }

    return $parts;
}

function mvm_fetch_imap_message_bodies($imap, int $uid): array
{
    $plain = '';
    $html = '';
    $structure = @imap_fetchstructure($imap, $uid, FT_UID);

    if (is_object($structure)) {
        foreach (mvm_imap_collect_body_parts($structure) as $part) {
            $body = @imap_fetchbody($imap, $uid, (string) $part['part'], FT_UID | FT_PEEK);

            if (!is_string($body) || $body === '') {
                continue;
            }

            $decoded = mvm_decode_imap_body($body, (int) $part['encoding']);

            if ($part['type'] === 'text/plain' && $plain === '') {
                $plain = $decoded;
            } elseif ($part['type'] === 'text/html' && $html === '') {
                $html = $decoded;
            }
        }
    }

    if ($plain === '' && $html === '') {
        $body = @imap_body($imap, $uid, FT_UID | FT_PEEK);
        $plain = is_string($body) ? $body : '';
    }

    if ($plain === '' && $html !== '') {
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    return ['plain' => trim($plain), 'html' => trim($html)];
}

function mvm_extract_email_address(string $value): string
{
    if (preg_match('/<([^>]+)>/', $value, $matches)) {
        return trim($matches[1]);
    }

    return trim($value);
}

function mvm_find_email_thread_for_message(string $subject, string $body, string $headers): ?array
{
    if (!mvm_email_schema_is_ready()) {
        return null;
    }

    $haystack = $subject . "\n" . $body . "\n" . $headers;

    if (preg_match('/MEZO-(?:IGENY-\d+-DOK-\d+-[A-F0-9]{6}|UGYFEL-\d+-[A-Z0-9]{8})/i', $haystack, $matches)) {
        $statement = db_query('SELECT * FROM `mvm_email_threads` WHERE `token` = ? LIMIT 1', [strtoupper($matches[0])]);
        $thread = $statement->fetch();

        if (is_array($thread)) {
            return $thread;
        }
    }

    if (preg_match_all('/<[^>]+>/', $headers, $matches)) {
        foreach (array_unique($matches[0]) as $messageId) {
            $statement = db_query('SELECT * FROM `mvm_email_threads` WHERE `sent_message_id` = ? LIMIT 1', [$messageId]);
            $thread = $statement->fetch();

            if (is_array($thread)) {
                return $thread;
            }
        }
    }

    return null;
}

function mvm_mailbox_sync_setup_message(): string
{
    $schemaErrors = mvm_mail_schema_errors();

    if ($schemaErrors !== []) {
        return implode(' ', $schemaErrors);
    }

    if (!function_exists('imap_open')) {
        return 'A központi postafiók beolvasásához a PHP IMAP bővítményt be kell kapcsolni a tárhelyen.';
    }

    $user = trim(mvm_config_value('MVM_IMAP_USER', mvm_mail_reply_address()));
    $password = (string) mvm_config_value('MVM_IMAP_PASS', '');

    if ($user === '' || $password === '') {
        return 'A válaszok automatikus beolvasásához az MVM_IMAP_USER és MVM_IMAP_PASS beállítás szükséges.';
    }

    return '';
}

function mvm_mailbox_sync_can_run(): bool
{
    return mvm_mailbox_sync_setup_message() === '';
}

function maybe_sync_mvm_mailbox_replies(int $limit = 40, int $throttleSeconds = 60): array
{
    $setupMessage = mvm_mailbox_sync_setup_message();

    if ($setupMessage !== '') {
        return [
            'ok' => false,
            'message' => $setupMessage,
            'matched' => 0,
            'ignored' => 0,
            'skipped' => true,
        ];
    }

    $now = time();
    $sessionKey = '_mvm_mailbox_auto_sync_at';

    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[$sessionKey])) {
        $lastRun = (int) $_SESSION[$sessionKey];

        if ($lastRun > 0 && $now - $lastRun < max(5, $throttleSeconds)) {
            return [
                'ok' => true,
                'message' => 'A központi postafiók nemrég frissült.',
                'matched' => 0,
                'ignored' => 0,
                'skipped' => true,
            ];
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$sessionKey] = $now;
    }

    $result = sync_mvm_mailbox_replies($limit);
    $result['skipped'] = false;

    return $result;
}

function sync_mvm_mailbox_replies(int $limit = 80): array
{
    $schemaErrors = mvm_mail_schema_errors();

    if ($schemaErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $schemaErrors), 'matched' => 0, 'ignored' => 0];
    }

    if (!function_exists('imap_open')) {
        return ['ok' => false, 'message' => 'A PHP IMAP bővítmény nincs bekapcsolva a tárhelyen.', 'matched' => 0, 'ignored' => 0];
    }

    $user = trim(mvm_config_value('MVM_IMAP_USER', mvm_mail_reply_address()));
    $password = (string) mvm_config_value('MVM_IMAP_PASS', '');

    if ($user === '' || $password === '') {
        return ['ok' => false, 'message' => 'Nincs beállítva az MVM_IMAP_USER és MVM_IMAP_PASS a storage/config/local.php vagy storage/config/local.secret.php fájlban.', 'matched' => 0, 'ignored' => 0];
    }

    $imap = @imap_open(mvm_imap_mailbox_string(), $user, $password);

    if ($imap === false) {
        return ['ok' => false, 'message' => 'Nem sikerült csatlakozni az MVM válasz postafiókhoz: ' . implode(' ', imap_errors() ?: []), 'matched' => 0, 'ignored' => 0];
    }

    $since = date('d-M-Y', strtotime('-90 days'));
    $uids = @imap_search($imap, 'SINCE "' . $since . '"', SE_UID);
    $matched = 0;
    $ignored = 0;

    if (is_array($uids)) {
        rsort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, 0, max(1, $limit));

        foreach ($uids as $uid) {
            $mailboxUid = sha1($user . '|' . (string) $uid);
            $exists = db_query('SELECT 1 FROM `mvm_email_messages` WHERE `mailbox_uid` = ? LIMIT 1', [$mailboxUid])->fetchColumn();

            if ($exists) {
                continue;
            }

            $uid = (int) $uid;
            $overview = @imap_fetch_overview($imap, (string) $uid, FT_UID);
            $overviewItem = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
            $subject = is_object($overviewItem) ? mvm_decode_mime_header((string) ($overviewItem->subject ?? '')) : '';
            $from = is_object($overviewItem) ? mvm_decode_mime_header((string) ($overviewItem->from ?? '')) : '';
            $to = is_object($overviewItem) ? mvm_decode_mime_header((string) ($overviewItem->to ?? '')) : '';
            $messageId = is_object($overviewItem) ? trim((string) ($overviewItem->message_id ?? '')) : '';
            $date = is_object($overviewItem) ? trim((string) ($overviewItem->date ?? '')) : '';
            $headers = @imap_fetchheader($imap, $uid, FT_UID);
            $headers = is_string($headers) ? $headers : '';
            $bodies = mvm_fetch_imap_message_bodies($imap, (int) $uid);
            $thread = mvm_find_email_thread_for_message($subject, $bodies['plain'] . "\n" . $bodies['html'], $headers);

            if ($thread === null) {
                $ignored++;
                continue;
            }

            $receivedAt = null;

            if ($date !== '') {
                try {
                    $receivedAt = (new DateTimeImmutable($date))->format('Y-m-d H:i:s');
                } catch (Throwable) {
                    $receivedAt = null;
                }
            }

            db_query(
                'INSERT INTO `mvm_email_messages`
                    (`thread_id`, `connection_request_id`, `direction`, `mailbox_uid`, `message_id`, `in_reply_to`, `sender_email`, `sender_name`, `recipient_email`, `subject`, `body_text`, `body_html`, `raw_headers`, `received_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $thread['id'],
                    (int) $thread['connection_request_id'],
                    'inbound',
                    $mailboxUid,
                    $messageId !== '' ? $messageId : null,
                    $headers,
                    mvm_extract_email_address($from),
                    $from,
                    mvm_extract_email_address($to),
                    $subject,
                    $bodies['plain'],
                    $bodies['html'],
                    $headers,
                    $receivedAt,
                ]
            );
            db_query(
                'UPDATE `mvm_email_threads`
                 SET `status` = ?, `last_message_at` = COALESCE(?, NOW())
                 WHERE `id` = ?',
                ['replied', $receivedAt, (int) $thread['id']]
            );
            $matched++;
        }
    }

    @imap_close($imap);

    return [
        'ok' => true,
        'message' => 'MVM válaszok szinkronizálva. Találat: ' . $matched . ', nem kapcsolható levél: ' . $ignored . '.',
        'matched' => $matched,
        'ignored' => $ignored,
    ];
}

function mvm_contractor_templates(): array
{
    return [
        'primavill' => [
            'label' => 'Primavill Kft.',
            'short_label' => 'Primavill',
            'supplier_number' => '38063',
            'template' => 'primavill_igenybejelento_2026_lakossagi.docx',
            'plan_template' => 'primavill_terv_sablon.docx',
            'handover_template' => 'primavill_muszaki_atadas.docx',
            'seal_removal_template' => 'primavill_plombabontasi_engedely.docx',
            'plan_header_line_1' => 'Primavill Kft. MVM Démász Áramhálózati Kft. partnerkivitelező',
            'plan_header_line_2' => '5600 Békéscsaba, Víztározó u. 19. Tel.: 06 30 23 08 472',
        ],
        'kasosvill' => [
            'label' => 'Kasosvill Kft.',
            'short_label' => 'Kasosvill',
            'supplier_number' => '33716',
            'template' => 'kasosvill_igenybejelento_2026_lakossagi.docx',
            'plan_template' => 'kasosvill_terv_sablon.docx',
            'handover_template' => 'kasosvill_muszaki_atadas.docx',
            'seal_removal_template' => 'kasosvill_plombabontasi_engedely.docx',
            'plan_header_line_1' => 'Kasosvill Kft. MVM Démász Áramhálózati Kft. partnerkivitelező',
            'plan_header_line_2' => 'Szállító szám: 33716',
        ],
        'szabowatt' => [
            'label' => 'Szabó Watt és Társai Kft.',
            'short_label' => 'Szabó Watt',
            'supplier_number' => '31945',
            'template' => 'szabowatt_igenybejelento_2026_lakossagi.docx',
            'plan_template' => 'szabowatt_terv_sablon.docx',
            'handover_template' => 'szabowatt_muszaki_atadas.docx',
            'seal_removal_template' => 'szabowatt_plombabontasi_engedely.docx',
            'plan_header_line_1' => 'Szabó Watt és Társai Kft. MVM Démász Áramhálózati Kft. partnerkivitelező',
            'plan_header_line_2' => 'Szállító szám: 31945',
        ],
        't-tech-2000' => [
            'label' => 'T-tech 2000 Kft.',
            'short_label' => 'T-tech 2000',
            'supplier_number' => '33665',
            'template' => 't-tech-2000_igenybejelento_2026_lakossagi.docx',
            'plan_template' => 't-tech-2000_terv_sablon.docx',
            'handover_template' => 't-tech-2000_muszaki_atadas.docx',
            'seal_removal_template' => 't-tech-2000_plombabontasi_engedely.docx',
            'plan_header_line_1' => 'T-tech 2000 Kft. MVM Démász Áramhálózati Kft. partnerkivitelező',
            'plan_header_line_2' => 'Szállító szám: 33665',
        ],
    ];
}

function normalize_mvm_contractor_key(?string $key): string
{
    $key = trim((string) $key);

    return array_key_exists($key, mvm_contractor_templates()) ? $key : 'primavill';
}

function mvm_contractor_definition(?string $key): array
{
    $templates = mvm_contractor_templates();

    return $templates[normalize_mvm_contractor_key($key)];
}

function mvm_contractor_select_options(): array
{
    $options = [];

    foreach (mvm_contractor_templates() as $key => $contractor) {
        $options[$key] = $contractor['label'] . ' - szállító szám: ' . $contractor['supplier_number'];
    }

    return $options;
}

function mvm_form_template_errors(?string $contractorKey = null): array
{
    $contractor = mvm_contractor_definition($contractorKey);

    return mvm_docx_template_path($contractorKey) === null
        ? ['Hiányzik a(z) ' . $contractor['label'] . ' DOCX sablon a templates/mvm/contractor-templates mappából.']
        : [];
}

function mvm_template_candidates(string $relativePath): array
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $roots = [
        APP_ROOT . '/templates/mvm',
        defined('PUBLIC_ROOT') ? PUBLIC_ROOT . '/templates/mvm' : '',
        APP_ROOT . '/public_html/templates/mvm',
    ];
    $parentRoot = dirname(APP_ROOT);

    if ($parentRoot !== APP_ROOT) {
        $roots[] = $parentRoot . '/templates/mvm';
    }

    $candidates = [];

    foreach ($roots as $root) {
        if ($root === '') {
            continue;
        }

        $candidate = rtrim($root, '/\\') . '/' . $relativePath;

        if (!in_array($candidate, $candidates, true)) {
            $candidates[] = $candidate;
        }
    }

    return $candidates;
}

function mvm_plan_template_path(?string $contractorKey = null): ?string
{
    $contractor = mvm_contractor_definition($contractorKey);
    $candidates = mvm_template_candidates('plan-templates/' . (string) ($contractor['plan_template'] ?? ''));

    if (normalize_mvm_contractor_key($contractorKey) === 'primavill') {
        $candidates = array_merge($candidates, mvm_template_candidates('plan-templates/terv-sablon-delvill-2.docx'));
        $candidates[] = APP_ROOT . '/Dokumentumok/Fővállalkozói dokumentumok/Tervsablonok/terv-sablon-delvill-2.docx';
        $candidates[] = APP_ROOT . '/Dokumentumok/Fővállalkozói dokumentumok/terv-sablon-delvill-2.docx';
    }

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function mvm_plan_template_errors(?string $contractorKey = null): array
{
    $contractor = mvm_contractor_definition($contractorKey);

    return mvm_plan_template_path($contractorKey) === null
        ? ['Hiányzik a(z) ' . $contractor['label'] . ' terv DOCX sablon a templates/mvm/plan-templates mappából.']
        : [];
}

function mvm_technical_handover_template_path(?string $contractorKey = null): ?string
{
    $contractor = mvm_contractor_definition($contractorKey);

    foreach (mvm_template_candidates('handover-templates/' . (string) ($contractor['handover_template'] ?? '')) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function mvm_technical_handover_template_errors(?string $contractorKey = null): array
{
    $contractor = mvm_contractor_definition($contractorKey);

    return mvm_technical_handover_template_path($contractorKey) === null
        ? ['Hiányzik a(z) ' . $contractor['label'] . ' műszaki átadás DOCX sablon a templates/mvm/handover-templates mappából.']
        : [];
}

function mvm_seal_removal_template_path(?string $contractorKey = null): ?string
{
    $contractor = mvm_contractor_definition($contractorKey);

    foreach (mvm_template_candidates('seal-removal-templates/' . (string) ($contractor['seal_removal_template'] ?? '')) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function mvm_seal_removal_template_errors(?string $contractorKey = null): array
{
    $contractor = mvm_contractor_definition($contractorKey);

    return mvm_seal_removal_template_path($contractorKey) === null
        ? ['Hiányzik a(z) ' . $contractor['label'] . ' plombabontási DOCX sablon a templates/mvm/seal-removal-templates mappából.']
        : [];
}

function mvm_h_tariff_template_path(): ?string
{
    $candidates = mvm_template_candidates('h-tariff-templates/h_tarifa_nyilatkozat.docx');

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function mvm_h_tariff_template_errors(): array
{
    return mvm_h_tariff_template_path() === null
        ? ['Hiányzik a H tarifa nyilatkozat DOCX sablon a templates/mvm/h-tariff-templates mappából.']
        : [];
}

function mvm_h_tariff_operating_system_options(): array
{
    return [
        '' => 'Nincs kiválasztva',
        'levego_levego' => 'levegő - levegő',
        'levego_viz' => 'levegő - víz',
        'talaj_levego' => 'talaj - levegő',
        'talaj_viz' => 'talaj - víz',
        'viz_levego' => 'víz - levegő',
        'viz_viz' => 'víz - víz',
    ];
}

function mvm_h_tariff_operating_system_label(?string $value): string
{
    if ((string) $value === '') {
        return '';
    }

    $options = mvm_h_tariff_operating_system_options();

    return (string) ($options[(string) $value] ?? '');
}

function mvm_form_field_sections(): array
{
    return [
        'basic_mvm' => [
            'title' => 'MVM alapadatok',
            'description' => 'A sablon felső részén és a munka tárgya blokknál megjelenő admin adatok.',
            'fields' => [
                'mvm_contractor' => ['label' => 'Fővállalkozó', 'type' => 'select', 'options' => mvm_contractor_select_options()],
                'mt' => ['label' => 'Munka tárgya', 'type' => 'text'],
                'felhasznalasi_cim' => ['label' => 'Felhasználási / kivitelezési cím', 'type' => 'text'],
                'iranyitoszam2' => ['label' => 'Felhasználási hely irányítószáma', 'type' => 'text'],
                'varos2' => ['label' => 'Felhasználási hely települése', 'type' => 'text'],
                'adoszam2' => ['label' => 'Adószám', 'type' => 'text'],
                'cegjegyzekszam' => ['label' => 'Cégjegyzékszám', 'type' => 'text'],
                'mertekado_eves_fogyasztas' => ['label' => 'Mértékadó éves fogyasztás (kWh/év)', 'type' => 'text'],
                'leadas_datuma' => ['label' => 'Leadás dátuma', 'type' => 'text'],
            ],
        ],
        'address' => [
            'title' => 'Ügyfél címadatai a sablonhoz',
            'description' => 'Ezek alapból az ügyfél adataiból töltődnek, de itt pontosíthatók, mert a Word-sablon külön utcát és házszámot vár.',
            'fields' => [
                'iranyito_szam' => ['label' => 'Irányítószám', 'type' => 'text'],
                'varos' => ['label' => 'Település', 'type' => 'text'],
                'utca' => ['label' => 'Utca, közterület', 'type' => 'text'],
                'hazszam' => ['label' => 'Házszám', 'type' => 'text'],
                'emelet_ajto' => ['label' => 'Emelet / ajtó', 'type' => 'text'],
            ],
        ],
        'request_subject' => [
            'title' => 'Munka tárgya jelölések',
            'description' => 'A Word dokumentum munka tárgya táblázatában ezek a jelölések jelennek meg. Pipáld, amelyik az adott igényre igaz.',
            'fields' => [
                'uj_fogyaszto' => ['label' => 'Új fogyasztói csatlakozás', 'type' => 'checkbox'],
                'n13' => ['label' => '1-3 fázisra áttérés', 'type' => 'checkbox'],
                'tn' => ['label' => 'Teljesítmény változtatás', 'type' => 'checkbox'],
                'sc' => ['label' => 'Felújítás, átépítés', 'type' => 'checkbox'],
                'egyedi_merohely_felulvizsgalat' => ['label' => 'Egyedi mérőhely felülvizsgálat', 'type' => 'checkbox'],
                'hmke_bekapcsolas' => ['label' => 'HMKE bekapcsolás', 'type' => 'checkbox'],
                'h_tarifa_vagy_melleszereles' => ['label' => 'H tarifa vagy mellészerelés', 'type' => 'checkbox'],
                'csak_kismegszakitocsere' => ['label' => 'Csak kismegszakítócsere', 'type' => 'checkbox'],
                'kozvilagitas_merohely_letesites' => ['label' => 'Közvilágítás mérőhely létesítés', 'type' => 'checkbox'],
                'csatlakozo_berendezes_helyreallitasa' => ['label' => 'Csatlakozó berendezés helyreállítása', 'type' => 'checkbox'],
                'lakossagi_fogyaszto' => ['label' => 'Lakossági fogyasztó', 'type' => 'checkbox'],
                'nem_lakossagi_fogyaszto' => ['label' => 'Nem lakossági fogyasztó', 'type' => 'checkbox'],
            ],
        ],
        'mvm_next_declarations' => [
            'title' => 'MVM Next nyilatkozatok',
            'description' => 'A plusz MVM Next oldalak 1.4, 1.5 és 2.1 jelölései.',
            'fields' => [
                'rendeltetes_lakas_haz' => ['label' => 'Lakás / ház', 'type' => 'checkbox'],
                'rendeltetes_iroda_uzlet_rendelo' => ['label' => 'Iroda / üzlet / rendelő', 'type' => 'checkbox'],
                'rendeltetes_ipari_uzemi_terulet' => ['label' => 'Ipari / üzemi terület', 'type' => 'checkbox'],
                'rendeltetes_zartkert_pince_tanya' => ['label' => 'Zártkert / pince / tanya', 'type' => 'checkbox'],
                'rendeltetes_udulo_nyaralo' => ['label' => 'Üdülő / nyaraló', 'type' => 'checkbox'],
                'rendeltetes_garazs' => ['label' => 'Garázs', 'type' => 'checkbox'],
                'rendeltetes_tarsashazi_kozosseg' => ['label' => 'Társasházi közösség', 'type' => 'checkbox'],
                'rendeltetes_egyeb' => ['label' => 'Egyéb rendeltetés', 'type' => 'checkbox'],
                'rendeltetes_egyeb_szoveg' => ['label' => 'Egyéb rendeltetés megnevezése', 'type' => 'text'],
                'rendeltetes_csoportos_db' => ['label' => 'Csoportos mérőhely felhasználási helyek száma', 'type' => 'text'],
                'jogcim_tulajdonos' => ['label' => 'Tulajdonos', 'type' => 'checkbox'],
                'jogcim_berlo' => ['label' => 'Bérlő', 'type' => 'checkbox'],
                'jogcim_haszonelvezo' => ['label' => 'Haszonélvező', 'type' => 'checkbox'],
                'jogcim_kezelo' => ['label' => 'Kezelő', 'type' => 'checkbox'],
                'jogcim_egyeb' => ['label' => 'Egyéb jogcím', 'type' => 'checkbox'],
                'jogcim_egyeb_szoveg' => ['label' => 'Egyéb jogcím megnevezése', 'type' => 'text'],
                'teljesitmeny_csokkentese' => ['label' => 'Teljesítmény csökkentése', 'type' => 'checkbox'],
                'csatlakozovezetek_athelyezese' => ['label' => 'Csatlakozóvezeték áthelyezése', 'type' => 'checkbox'],
                'csatlakozovezetek_csereje' => ['label' => 'Csatlakozóvezeték cseréje', 'type' => 'checkbox'],
                'csatlakozasi_mod_valtasa' => ['label' => 'Csatlakozási mód váltása', 'type' => 'checkbox'],
                'mero_athelyezese' => ['label' => 'Mérő áthelyezése', 'type' => 'checkbox'],
                'vezerelt_mero_szerelese' => ['label' => 'Vezérelt mérő szerelése', 'type' => 'checkbox'],
                'okosmero_szerelese' => ['label' => 'Okosmérő szerelése', 'type' => 'checkbox'],
                'elore_fizetos_mero' => ['label' => 'Előre fizetős mérő', 'type' => 'checkbox'],
                'a2_tarifas_mero' => ['label' => 'A2 tarifás mérő', 'type' => 'checkbox'],
                'ideiglenes_bekapcsolas' => ['label' => 'Ideiglenes bekapcsolás', 'type' => 'checkbox'],
            ],
        ],
        'connection' => [
            'title' => 'Csatlakozás és mérőszekrény',
            'description' => 'Ezek azok az MVM/partneri adatok, amelyek nem az ügyfél regisztrációs adataiból jönnek.',
            'fields' => [
                'foldkabeles' => ['label' => 'Földkábeles csatlakozás jelölése', 'type' => 'checkbox'],
                'legvezetekes' => ['label' => 'Légvezetékes csatlakozás jelölése', 'type' => 'checkbox'],
                'n216' => ['label' => 'NFA 2x16', 'type' => 'checkbox'],
                'n416' => ['label' => 'NFA 4x16', 'type' => 'checkbox'],
                'n225' => ['label' => 'NFA 2x25', 'type' => 'checkbox'],
                'n425' => ['label' => 'NFA 4x25', 'type' => 'checkbox'],
                'n425f' => ['label' => 'NAYY-O 4x25', 'type' => 'checkbox'],
                'n435f' => ['label' => 'NAYY-O 4x35', 'type' => 'checkbox'],
                'n450f' => ['label' => 'NAYY-O 4x50', 'type' => 'checkbox'],
                'n470f' => ['label' => 'NAYY-O 4x70', 'type' => 'checkbox'],
                'n495f' => ['label' => 'NAYY-O 4x95', 'type' => 'checkbox'],
                'szekreny_tipusa' => ['label' => 'Szekrény típusa', 'type' => 'text'],
                'szekreny_brutto_egysegar' => ['label' => 'Szekrény bruttó egységára', 'type' => 'text'],
                'jelenlegi_meroszekreny' => ['label' => 'Jelenlegi mérőszekrény', 'type' => 'text'],
                'szekreny_felulvizsgalati_dij' => ['label' => 'Szekrény felülvizsgálati díj bruttó ára', 'type' => 'text'],
                'fha' => ['label' => 'Fogyasztási hely azonosító', 'type' => 'text'],
            ],
        ],
        'performance' => [
            'title' => 'Teljesítményadatok',
            'description' => 'A fázisonkénti jelenlegi és igényelt értékek. Amit lehet, a rendszer az igényből előtölt.',
            'fields' => [
                'jml1' => ['label' => 'Jelenlegi mindennapszaki L1', 'type' => 'text'],
                'jml2' => ['label' => 'Jelenlegi mindennapszaki L2', 'type' => 'text'],
                'jml3' => ['label' => 'Jelenlegi mindennapszaki L3', 'type' => 'text'],
                'iml1' => ['label' => 'Igényelt mindennapszaki L1', 'type' => 'text'],
                'iml2' => ['label' => 'Igényelt mindennapszaki L2', 'type' => 'text'],
                'iml3' => ['label' => 'Igényelt mindennapszaki L3', 'type' => 'text'],
                'jelenlegi_hl1' => ['label' => 'Jelenlegi H tarifa L1', 'type' => 'text'],
                'jelenlegi_hl2' => ['label' => 'Jelenlegi H tarifa L2', 'type' => 'text'],
                'jelenlegi_hl3' => ['label' => 'Jelenlegi H tarifa L3', 'type' => 'text'],
                'ihl1' => ['label' => 'Igényelt H tarifa L1', 'type' => 'text'],
                'ihl2' => ['label' => 'Igényelt H tarifa L2', 'type' => 'text'],
                'ihl3' => ['label' => 'Igényelt H tarifa L3', 'type' => 'text'],
                'jvl1' => ['label' => 'Jelenlegi vezérelt L1', 'type' => 'text'],
                'jvl2' => ['label' => 'Jelenlegi vezérelt L2', 'type' => 'text'],
                'jvl3' => ['label' => 'Jelenlegi vezérelt L3', 'type' => 'text'],
                'ivl1' => ['label' => 'Igényelt vezérelt L1', 'type' => 'text'],
                'ivl2' => ['label' => 'Igényelt vezérelt L2', 'type' => 'text'],
                'ivl3' => ['label' => 'Igényelt vezérelt L3', 'type' => 'text'],
                'igenyelt_osszes_teljesitmeny' => ['label' => 'Igényelt összes teljesítmény (A)', 'type' => 'text', 'readonly' => true],
                'osszes_igenyelt_h_teljesitmeny' => ['label' => 'Összes igényelt H teljesítmény (A)', 'type' => 'text', 'readonly' => true],
            ],
        ],
        'mvm_financial' => [
            'title' => 'Fizetendő teljesítmény és MVM költségek',
            'description' => 'A rendszer az igényelt fázisértékek összegéből levonja a meglévő teljesítményt, de legalább 32 A-t, majd kiszámolja a 4 953 Ft/A díjat.',
            'fields' => [
                'ingyenes_teljesitmeny_ampere' => ['label' => 'Levonandó meglévő / díjmentes teljesítmény (A)', 'type' => 'text', 'readonly' => true],
                'fizetendo_teljesitmeny_ampere' => ['label' => 'Fizetendő teljesítmény (A)', 'type' => 'text', 'readonly' => true],
                'fizetendo_teljesitmeny_osszeg' => ['label' => 'Fizetendő teljesítmény díja (Ft)', 'type' => 'text', 'readonly' => true],
                'oszloptelepites_koltseg' => ['label' => 'Oszloptelepítés költsége (Ft)', 'type' => 'text'],
                'legvezetekes_csatlakozo_koltseg' => ['label' => 'Légvezetékes csatlakozóvezeték költsége (Ft)', 'type' => 'text'],
                'szfd' => ['label' => 'Egyedi mérőszekrény felülvizsgálati díja (Ft)', 'type' => 'text'],
                'csatlakozo_berendezes_helyreallitas_koltseg' => ['label' => 'Csatlakozó berendezés helyreállítása (Ft)', 'type' => 'text'],
                'foldkabel_tobletkoltseg' => ['label' => 'Ügyféligényű műszaki eltérés többletköltsége (Ft)', 'type' => 'text'],
                'ofo' => ['label' => 'Összesen fizetendő költség (Ft)', 'type' => 'text', 'readonly' => true],
                'ofosz' => ['label' => 'Összesen szöveggel', 'type' => 'text', 'readonly' => true],
            ],
        ],
        'technical' => [
            'title' => 'Műszaki részletek',
            'description' => 'Vezeték, oszlop, tetőtartó és többletköltség adatok a sablon további táblázataihoz.',
            'fields' => [
                'oszlop_tipusa' => ['label' => 'Oszlop típusa', 'type' => 'text'],
                'tetotarto_hossz' => ['label' => 'Tetőtartó hossz', 'type' => 'text'],
                'ossz_kabelhossz' => ['label' => 'Össz kábelhossz', 'type' => 'text'],
                'vizszintes_kabelhossz_m' => ['label' => 'Vízszintes kábelhossz (m)', 'type' => 'text'],
                'legvezetekes_tobbletkoltseg' => ['label' => 'Légvezetékes többletköltség', 'type' => 'text'],
                'muszaki_tobbletkoltseg' => ['label' => 'Műszaki többletköltség', 'type' => 'text'],
                'meroora_helye_jelenleg' => ['label' => 'Mérőóra helye jelenleg', 'type' => 'text'],
            ],
        ],
        'h_tariff_declaration' => [
            'title' => 'H tarifa nyilatkozat',
            'description' => 'A H tarifa igényhez szükséges berendezés- és hőszivattyú adatok. Ha itt adatot adsz meg, a rendszer a nyilatkozatot a jóváhagyási csomagba is beemeli.',
            'fields' => [
                'h_tarifa_gyarto' => ['label' => 'Berendezés gyártója', 'type' => 'text'],
                'h_tarifa_tipus' => ['label' => 'Berendezés típusjelzése', 'type' => 'text'],
                'h_tarifa_nevleges_villamos_kw' => ['label' => 'Névleges villamos teljesítmény (kW)', 'type' => 'text'],
                'h_tarifa_futesi_teljesitmeny_kw' => ['label' => 'Fűtési teljesítmény (kW)', 'type' => 'text'],
                'h_tarifa_scop' => ['label' => 'Jósági tényező / SCOP érték', 'type' => 'text'],
                'h_tarifa_mukodesi_rendszer' => ['label' => 'Hőszivattyú működési rendszere', 'type' => 'select', 'options' => mvm_h_tariff_operating_system_options()],
                'h_tarifa_teljes_egyideju_kw' => ['label' => 'Teljes egyidejű villamos teljesítmény (kW)', 'type' => 'text'],
                'h_tarifa_futesi_fogyasztas_kwh' => ['label' => 'Várható fogyasztás fűtési időszakban (kWh)', 'type' => 'text'],
                'h_tarifa_nyari_fogyasztas_kwh' => ['label' => 'Várható fogyasztás nyári időszakban (kWh)', 'type' => 'text'],
            ],
        ],
        'mvm_points' => [
            'title' => 'MVM pontok és kockás rajz feliratai',
            'description' => 'A kockázott részhez tartozó szövegek és az MVM 7-es pont adatai.',
            'fields' => [
                'n7es_pont_nev' => ['label' => '7-es pont neve', 'type' => 'text'],
                'n7es_pont_cim' => ['label' => '7-es pont címe', 'type' => 'text'],
                'kockas_papir_vezetek' => ['label' => 'Kockás papír vezeték felirata', 'type' => 'text'],
                'kockas_papir_szekreny' => ['label' => 'Kockás papír szekrény felirata', 'type' => 'text'],
                'datum' => ['label' => 'Dátum', 'type' => 'text'],
            ],
        ],
        'execution_plan' => [
            'title' => 'Tervdokumentáció adatai',
            'description' => 'Ezek csak a tervsablonba kerülnek. A műszaki leírás, a csatlakozás típusa és módja szabadon írható.',
            'fields' => [
                'plan_csatlakozas_tipusa' => ['label' => 'Csatlakozás típusa', 'type' => 'textarea'],
                'plan_csatlakozas_modja' => ['label' => 'Csatlakozás módja', 'type' => 'textarea'],
                'plan_muszaki_leiras' => ['label' => 'Műszaki leírás szabad szöveg', 'type' => 'textarea'],
            ],
        ],
    ];
}

function mvm_form_field_definitions(): array
{
    $fields = [];

    foreach (mvm_form_field_sections() as $section) {
        foreach ($section['fields'] as $key => $field) {
            $fields[$key] = $field;
        }
    }

    return $fields;
}

function mvm_plan_header_known_default(string $value, string $key): bool
{
    foreach (mvm_contractor_templates() as $contractor) {
        if (trim((string) ($contractor[$key] ?? '')) === $value) {
            return true;
        }
    }

    return false;
}

function mvm_apply_plan_field_defaults(array $data): array
{
    $contractor = mvm_contractor_definition($data['mvm_contractor'] ?? null);

    foreach (['plan_header_line_1', 'plan_header_line_2'] as $key) {
        $value = trim((string) ($data[$key] ?? ''));

        if ($value === '' || mvm_plan_header_known_default($value, $key)) {
            $data[$key] = (string) ($contractor[$key] ?? '');
        }
    }

    return $data;
}

function split_street_and_house_number(string $address): array
{
    $address = trim($address);

    if ($address === '') {
        return ['', ''];
    }

    if (preg_match('/^(.+?)\s+(\d+[A-Za-zÁÉÍÓÖŐÚÜŰáéíóöőúüű0-9\/\-\.\s]*)$/u', $address, $matches)) {
        return [trim($matches[1]), trim($matches[2])];
    }

    return [$address, ''];
}

function mvm_power_phase_values(?string $value): array
{
    $value = strtolower(trim((string) $value));

    if ($value === '' || $value === '-' || $value === '- / -') {
        return ['', '', ''];
    }

    if (preg_match('/^([123])\s*x\s*([0-9]+(?:[,.][0-9]+)?)/', $value, $matches)) {
        $phaseCount = (int) $matches[1];
        $ampere = str_replace(',', '.', $matches[2]);

        return match ($phaseCount) {
            1 => [$ampere, '', ''],
            2 => [$ampere, $ampere, ''],
            default => [$ampere, $ampere, $ampere],
        };
    }

    return [$value, '', ''];
}

function mvm_power_total_ampere(?string $value): int
{
    $value = strtolower(trim((string) $value));

    if ($value === '' || $value === '-' || $value === '- / -') {
        return 0;
    }

    if (preg_match('/^([123])\s*x\s*([0-9]+(?:[,.][0-9]+)?)/', $value, $matches)) {
        return (int) round((int) $matches[1] * (float) str_replace(',', '.', $matches[2]));
    }

    if (preg_match('/([0-9]+(?:[,.][0-9]+)?)/', $value, $matches)) {
        return (int) round((float) str_replace(',', '.', $matches[1]));
    }

    return 0;
}

function format_mvm_forint_value(float|int|string $amount): string
{
    return number_format((float) $amount, 0, ',', ' ');
}

function mvm_forint_amount_value(mixed $value): int
{
    $value = trim((string) $value);

    if ($value === '') {
        return 0;
    }

    $normalized = preg_replace('/[^\d\-]/', '', $value);

    if ($normalized === null || $normalized === '' || $normalized === '-') {
        return 0;
    }

    return (int) $normalized;
}

function mvm_phase_ampere_value(mixed $value): int
{
    $value = strtolower(trim((string) $value));

    if ($value === '' || $value === '-' || $value === '- / -') {
        return 0;
    }

    if (preg_match('/^([123])\s*x\s*([0-9]+(?:[,.][0-9]+)?)/', $value, $matches)) {
        return (int) round((int) $matches[1] * (float) str_replace(',', '.', $matches[2]));
    }

    if (preg_match('/([0-9]+(?:[,.][0-9]+)?)/', $value, $matches)) {
        return (int) round((float) str_replace(',', '.', $matches[1]));
    }

    return 0;
}

function mvm_phase_ampere_total(array $data, array $keys): int
{
    $total = 0;

    foreach ($keys as $key) {
        $total += mvm_phase_ampere_value($data[$key] ?? '');
    }

    return $total;
}

function format_mvm_kva_value(int $ampere): string
{
    if ($ampere <= 0) {
        return '';
    }

    $value = $ampere * 230 / 1000;
    $formatted = number_format($value, 2, ',', ' ');
    $formatted = (string) preg_replace('/,00$/', '', $formatted);

    return (string) preg_replace('/,([0-9])0$/', ',$1', $formatted);
}

function mvm_number_under_thousand_to_words(int $number): string
{
    $number = max(0, min(999, $number));
    $ones = ['', 'egy', 'kettő', 'három', 'négy', 'öt', 'hat', 'hét', 'nyolc', 'kilenc'];
    $tens = [3 => 'harminc', 4 => 'negyven', 5 => 'ötven', 6 => 'hatvan', 7 => 'hetven', 8 => 'nyolcvan', 9 => 'kilencven'];
    $words = '';

    $hundreds = intdiv($number, 100);
    $remainder = $number % 100;

    if ($hundreds > 0) {
        $words .= $hundreds === 1 ? 'száz' : ($hundreds === 2 ? 'kétszáz' : $ones[$hundreds] . 'száz');
    }

    if ($remainder === 0) {
        return $words;
    }

    if ($remainder < 10) {
        return $words . $ones[$remainder];
    }

    if ($remainder === 10) {
        return $words . 'tíz';
    }

    if ($remainder < 20) {
        return $words . 'tizen' . $ones[$remainder - 10];
    }

    if ($remainder === 20) {
        return $words . 'húsz';
    }

    if ($remainder < 30) {
        return $words . 'huszon' . $ones[$remainder - 20];
    }

    $ten = intdiv($remainder, 10);
    $one = $remainder % 10;

    return $words . $tens[$ten] . ($one > 0 ? $ones[$one] : '');
}

function mvm_number_to_hungarian_words(int $number): string
{
    if ($number === 0) {
        return 'nulla';
    }

    $number = abs($number);
    $millions = intdiv($number, 1000000);
    $number %= 1000000;
    $thousands = intdiv($number, 1000);
    $remainder = $number % 1000;
    $parts = [];

    if ($millions > 0) {
        $parts[] = mvm_number_under_thousand_to_words($millions) . 'millió';
    }

    if ($thousands > 0) {
        $parts[] = match ($thousands) {
            1 => 'ezer',
            2 => 'kétezer',
            default => mvm_number_under_thousand_to_words($thousands) . 'ezer',
        };
    }

    if ($remainder > 0) {
        $parts[] = mvm_number_under_thousand_to_words($remainder);
    }

    return count($parts) > 1 && ($millions > 0 || $thousands > 2)
        ? implode('-', $parts)
        : implode('', $parts);
}

function mvm_recalculate_power_financials(array $data): array
{
    $requestedGeneralTotal = mvm_phase_ampere_total($data, ['iml1', 'iml2', 'iml3']);
    $requestedHTotal = mvm_phase_ampere_total($data, ['ihl1', 'ihl2', 'ihl3']);
    $requestedControlledTotal = mvm_phase_ampere_total($data, ['ivl1', 'ivl2', 'ivl3']);
    $requestedTotal = $requestedGeneralTotal + $requestedHTotal + $requestedControlledTotal;
    $existingGeneralTotal = mvm_phase_ampere_total($data, ['jml1', 'jml2', 'jml3']);
    $existingHTotal = mvm_phase_ampere_total($data, ['jelenlegi_hl1', 'jelenlegi_hl2', 'jelenlegi_hl3']);
    $existingControlledTotal = mvm_phase_ampere_total($data, ['jvl1', 'jvl2', 'jvl3']);
    $existingTotal = mvm_phase_ampere_total($data, [
        'jml1',
        'jml2',
        'jml3',
        'jelenlegi_hl1',
        'jelenlegi_hl2',
        'jelenlegi_hl3',
        'jvl1',
        'jvl2',
        'jvl3',
    ]);
    $deductibleAmpere = $requestedTotal > 0 ? max(32, $existingTotal) : 0;
    $payableAmpere = $requestedTotal > 0 ? max(0, $requestedTotal - $deductibleAmpere) : 0;
    $payableAmount = $payableAmpere * 4953;
    $additionalCostKeys = [
        'szekreny_brutto_egysegar',
        'szekreny_felulvizsgalati_dij',
        'oszloptelepites_koltseg',
        'legvezetekes_csatlakozo_koltseg',
        'szfd',
        'csatlakozo_berendezes_helyreallitas_koltseg',
        'foldkabel_tobletkoltseg',
    ];
    $additionalCosts = 0;

    foreach ($additionalCostKeys as $key) {
        $additionalCosts += mvm_forint_amount_value($data[$key] ?? '');
    }

    $totalAmount = $payableAmount + $additionalCosts;
    $hasTotal = $requestedTotal > 0 || $additionalCosts > 0;

    $data['igenyelt_osszes_teljesitmeny'] = $requestedTotal > 0 ? (string) $requestedTotal : '';
    $data['igenyelt_osszes_mindennapszaki_teljesitmeny'] = $requestedGeneralTotal > 0 ? (string) $requestedGeneralTotal : '';
    $data['osszes_igenyelt_vezerelt_teljesitmeny'] = $requestedControlledTotal > 0 ? (string) $requestedControlledTotal : '';
    $data['osszes_igenyelt_h_teljesitmeny'] = $requestedHTotal > 0 ? (string) $requestedHTotal : '';
    $data['meglevo_osszes_teljesitmeny'] = $existingTotal > 0 ? (string) $existingTotal : '';
    $data['meglevo_osszes_mindennapszaki_teljesitmeny'] = $existingGeneralTotal > 0 ? (string) $existingGeneralTotal : '';
    $data['osszes_meglevo_vezerelt_teljesitmeny'] = $existingControlledTotal > 0 ? (string) $existingControlledTotal : '';
    $data['osszes_meglevo_h_teljesitmeny'] = $existingHTotal > 0 ? (string) $existingHTotal : '';
    $data['igenyelt_mindennapszaki_kva'] = format_mvm_kva_value($requestedGeneralTotal);
    $data['igenyelt_vezerelt_kva'] = format_mvm_kva_value($requestedControlledTotal);
    $data['igenyelt_h_kva'] = format_mvm_kva_value($requestedHTotal);
    $data['meglevo_mindennapszaki_kva'] = format_mvm_kva_value($existingGeneralTotal);
    $data['meglevo_vezerelt_kva'] = format_mvm_kva_value($existingControlledTotal);
    $data['meglevo_h_kva'] = format_mvm_kva_value($existingHTotal);
    $data['igenyelt_osszes_kva'] = format_mvm_kva_value($requestedTotal);
    $data['meglevo_osszes_kva'] = format_mvm_kva_value($existingTotal);
    $data['ingyenes_teljesitmeny_ampere'] = $deductibleAmpere > 0 ? (string) $deductibleAmpere : '';
    $data['fizetendo_teljesitmeny_ampere'] = $requestedTotal > 0 ? (string) $payableAmpere : '';
    $data['fizetendo_teljesitmeny_osszeg'] = $requestedTotal > 0 ? format_mvm_forint_value($payableAmount) : '';
    $data['ofo'] = $hasTotal ? format_mvm_forint_value($totalAmount) : '';
    $data['ofosz'] = $hasTotal ? mvm_number_to_hungarian_words($totalAmount) : '';

    return $data;
}

function mvm_form_default_values(array $request): array
{
    $addressSource = trim((string) ($request['postal_address'] ?? ''));

    if ($addressSource === '') {
        $addressSource = trim((string) ($request['site_address'] ?? ''));
    }

    $postalCode = trim((string) ($request['postal_code'] ?? ''));

    if ($postalCode === '') {
        $postalCode = trim((string) ($request['site_postal_code'] ?? ''));
    }

    [$street, $houseNumber] = split_street_and_house_number($addressSource);
    [$jml1, $jml2, $jml3] = mvm_power_phase_values($request['existing_general_power'] ?? '');
    [$iml1, $iml2, $iml3] = mvm_power_phase_values($request['requested_general_power'] ?? '');
    [$jhl1, $jhl2, $jhl3] = mvm_power_phase_values($request['existing_h_tariff_power'] ?? '');
    [$ihl1, $ihl2, $ihl3] = mvm_power_phase_values($request['requested_h_tariff_power'] ?? '');
    [$jvl1, $jvl2, $jvl3] = mvm_power_phase_values($request['existing_controlled_power'] ?? '');
    [$ivl1, $ivl2, $ivl3] = mvm_power_phase_values($request['requested_controlled_power'] ?? '');

    $defaults = [
        'mvm_contractor' => 'primavill',
        'mt' => connection_request_type_label($request['request_type'] ?? null),
        'felhasznalasi_cim' => trim($postalCode . ' ' . (string) ($request['site_address'] ?? '')),
        'iranyitoszam2' => (string) ($request['site_postal_code'] ?? ''),
        'varos2' => '',
        'adoszam2' => (string) ($request['tax_number'] ?? ''),
        'cegjegyzekszam' => '',
        'mertekado_eves_fogyasztas' => '',
        'leadas_datuma' => '',
        'iranyito_szam' => $postalCode,
        'varos' => (string) ($request['city'] ?? ''),
        'utca' => $street,
        'hazszam' => $houseNumber,
        'emelet_ajto' => '',
        'uj_fogyaszto' => ($request['request_type'] ?? '') === 'new_connection' ? 'X' : '',
        'n13' => ($request['request_type'] ?? '') === 'phase_upgrade' ? 'X' : '',
        'tn' => in_array(($request['request_type'] ?? ''), ['phase_upgrade', 'power_increase'], true) ? 'X' : '',
        'sc' => ($request['request_type'] ?? '') === 'standardization' ? 'X' : '',
        'egyedi_merohely_felulvizsgalat' => '',
        'hmke_bekapcsolas' => '',
        'h_tarifa_vagy_melleszereles' => ($request['request_type'] ?? '') === 'h_tariff' ? 'X' : '',
        'csak_kismegszakitocsere' => '',
        'kozvilagitas_merohely_letesites' => '',
        'csatlakozo_berendezes_helyreallitasa' => '',
        'lakossagi_fogyaszto' => empty($request['is_legal_entity']) ? 'X' : '',
        'nem_lakossagi_fogyaszto' => !empty($request['is_legal_entity']) ? 'X' : '',
        'rendeltetes_lakas_haz' => 'X',
        'rendeltetes_iroda_uzlet_rendelo' => '',
        'rendeltetes_ipari_uzemi_terulet' => '',
        'rendeltetes_zartkert_pince_tanya' => '',
        'rendeltetes_udulo_nyaralo' => '',
        'rendeltetes_garazs' => '',
        'rendeltetes_tarsashazi_kozosseg' => '',
        'rendeltetes_egyeb' => '',
        'rendeltetes_egyeb_szoveg' => '',
        'rendeltetes_csoportos_db' => '',
        'jogcim_tulajdonos' => 'X',
        'jogcim_berlo' => '',
        'jogcim_haszonelvezo' => '',
        'jogcim_kezelo' => '',
        'jogcim_egyeb' => '',
        'jogcim_egyeb_szoveg' => '',
        'teljesitmeny_csokkentese' => '',
        'csatlakozovezetek_athelyezese' => '',
        'csatlakozovezetek_csereje' => '',
        'csatlakozasi_mod_valtasa' => '',
        'mero_athelyezese' => '',
        'vezerelt_mero_szerelese' => '',
        'okosmero_szerelese' => '',
        'elore_fizetos_mero' => '',
        'a2_tarifas_mero' => '',
        'ideiglenes_bekapcsolas' => '',
        'foldkabeles' => '',
        'legvezetekes' => '',
        'n216' => '',
        'n416' => '',
        'n225' => '',
        'n425' => '',
        'n425f' => '',
        'n435f' => '',
        'n450f' => '',
        'n470f' => '',
        'n495f' => '',
        'szekreny_tipusa' => '',
        'szekreny_brutto_egysegar' => '',
        'jelenlegi_meroszekreny' => '',
        'szekreny_felulvizsgalati_dij' => '',
        'fha' => (string) ($request['consumption_place_id'] ?? ''),
        'jml1' => $jml1,
        'jml2' => $jml2,
        'jml3' => $jml3,
        'iml1' => $iml1,
        'iml2' => $iml2,
        'iml3' => $iml3,
        'jelenlegi_hl1' => $jhl1,
        'jelenlegi_hl2' => $jhl2,
        'jelenlegi_hl3' => $jhl3,
        'ihl1' => $ihl1,
        'ihl2' => $ihl2,
        'ihl3' => $ihl3,
        'jvl1' => $jvl1,
        'jvl2' => $jvl2,
        'jvl3' => $jvl3,
        'ivl1' => $ivl1,
        'ivl2' => $ivl2,
        'ivl3' => $ivl3,
        'igenyelt_osszes_teljesitmeny' => '',
        'igenyelt_osszes_mindennapszaki_teljesitmeny' => '',
        'osszes_igenyelt_vezerelt_teljesitmeny' => '',
        'osszes_igenyelt_h_teljesitmeny' => '',
        'meglevo_osszes_teljesitmeny' => '',
        'meglevo_osszes_mindennapszaki_teljesitmeny' => '',
        'osszes_meglevo_vezerelt_teljesitmeny' => '',
        'osszes_meglevo_h_teljesitmeny' => '',
        'igenyelt_osszes_kva' => '',
        'meglevo_osszes_kva' => '',
        'ingyenes_teljesitmeny_ampere' => '',
        'fizetendo_teljesitmeny_ampere' => '',
        'fizetendo_teljesitmeny_osszeg' => '',
        'oszloptelepites_koltseg' => '',
        'legvezetekes_csatlakozo_koltseg' => '',
        'szfd' => '',
        'csatlakozo_berendezes_helyreallitas_koltseg' => '',
        'foldkabel_tobletkoltseg' => '',
        'ofo' => '',
        'ofosz' => '',
        'ot' => '',
        'ohfk' => '',
        'otvez' => '',
        'oszlop_tipusa' => '',
        'tetotarto_hossz' => '',
        'ossz_kabelhossz' => '',
        'vizszintes_kabelhossz_m' => '',
        'legvezetekes_tobbletkoltseg' => '',
        'muszaki_tobbletkoltseg' => '',
        'meroora_helye_jelenleg' => '',
        'laed' => '',
        'mgyszv' => '',
        'mogysz' => '',
        'n7es_pont_nev' => '',
        'n7es_pont_cim' => '',
        'kockas_papir_vezetek' => '',
        'kockas_papir_szekreny' => '',
        'datum' => date('Y.m.d.'),
        'plan_header_line_1' => '',
        'plan_header_line_2' => '',
        'plan_csatlakozas_tipusa' => '',
        'plan_csatlakozas_modja' => '',
        'plan_muszaki_leiras' => '',
    ];

    foreach (mvm_form_field_definitions() as $key => $definition) {
        if (!array_key_exists($key, $defaults)) {
            $defaults[$key] = '';
        }
    }

    return mvm_recalculate_power_financials(mvm_apply_plan_field_defaults($defaults));
}

function normalize_mvm_form_data(array $source): array
{
    $data = [];

    foreach (mvm_form_field_definitions() as $key => $field) {
        if (($field['type'] ?? 'text') === 'checkbox') {
            $data[$key] = isset($source[$key]) && (string) $source[$key] !== '' ? 'X' : '';
            continue;
        }

        $data[$key] = trim((string) ($source[$key] ?? ''));
    }

    $data['mvm_contractor'] = normalize_mvm_contractor_key($data['mvm_contractor'] ?? null);

    return mvm_apply_plan_field_defaults($data);
}

function connection_request_mvm_source_form_values(array $request, ?array $source = null): array
{
    $values = [
        'requester_name' => (string) ($request['requester_name'] ?? ''),
        'birth_name' => (string) ($request['birth_name'] ?? ''),
        'mother_name' => (string) ($request['mother_name'] ?? ''),
        'birth_place' => (string) ($request['birth_place'] ?? ''),
        'birth_date' => (string) ($request['birth_date'] ?? ''),
        'tax_number' => (string) ($request['tax_number'] ?? ''),
        'project_name' => (string) ($request['project_name'] ?? ''),
        'request_type' => (string) ($request['request_type'] ?? 'phase_upgrade'),
        'site_postal_code' => (string) ($request['site_postal_code'] ?? ''),
        'site_address' => (string) ($request['site_address'] ?? ''),
        'hrsz' => (string) ($request['hrsz'] ?? ''),
        'meter_serial' => (string) ($request['meter_serial'] ?? ''),
        'consumption_place_id' => (string) ($request['consumption_place_id'] ?? ''),
    ];

    if ($source !== null) {
        foreach (array_keys($values) as $key) {
            $sourceKey = 'source_' . $key;

            if (array_key_exists($sourceKey, $source)) {
                $values[$key] = trim((string) $source[$sourceKey]);
            }
        }

        if (connection_request_mvm_source_birth_date_parts_submitted($source)) {
            $values['birth_date'] = normalize_connection_request_mvm_source_birth_date($source);
        }
    }

    if (!isset(connection_request_type_options()[$values['request_type']])) {
        $values['request_type'] = 'phase_upgrade';
    }

    return $values;
}

function normalize_connection_request_mvm_source_date(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{4})[.\-\/ ]+(\d{1,2})[.\-\/ ]+(\d{1,2})$/', $value, $matches)) {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
    } elseif (preg_match('/^(\d{1,2})[.\-\/ ]+(\d{1,2})[.\-\/ ]+(\d{4})$/', $value, $matches)) {
        $year = (int) $matches[3];
        $month = (int) $matches[2];
        $day = (int) $matches[1];
    } else {
        return '';
    }

    if (!checkdate($month, $day, $year)) {
        return '';
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function connection_request_mvm_source_birth_date_parts(string $value): array
{
    $normalized = normalize_connection_request_mvm_source_date($value);

    if ($normalized === '') {
        return [
            'year' => '',
            'month' => '',
            'day' => '',
        ];
    }

    [$year, $month, $day] = explode('-', $normalized);

    return [
        'year' => $year,
        'month' => $month,
        'day' => $day,
    ];
}

function connection_request_mvm_source_birth_date_parts_submitted(array $source): bool
{
    return array_key_exists('source_birth_date_year', $source)
        || array_key_exists('source_birth_date_month', $source)
        || array_key_exists('source_birth_date_day', $source);
}

function connection_request_mvm_source_birth_date_has_value(array $source): bool
{
    if (connection_request_mvm_source_birth_date_parts_submitted($source)) {
        return trim((string) ($source['source_birth_date_year'] ?? '')) !== ''
            || trim((string) ($source['source_birth_date_month'] ?? '')) !== ''
            || trim((string) ($source['source_birth_date_day'] ?? '')) !== '';
    }

    return trim((string) ($source['source_birth_date'] ?? '')) !== '';
}

function normalize_connection_request_mvm_source_birth_date(array $source): string
{
    if (!connection_request_mvm_source_birth_date_parts_submitted($source)) {
        return normalize_connection_request_mvm_source_date((string) ($source['source_birth_date'] ?? ''));
    }

    $year = preg_replace('/\D+/', '', trim((string) ($source['source_birth_date_year'] ?? '')));
    $month = preg_replace('/\D+/', '', trim((string) ($source['source_birth_date_month'] ?? '')));
    $day = preg_replace('/\D+/', '', trim((string) ($source['source_birth_date_day'] ?? '')));

    if ($year === '' && $month === '' && $day === '') {
        return '';
    }

    if ($year === '' || $month === '' || $day === '' || strlen($year) !== 4) {
        return '';
    }

    $yearNumber = (int) $year;
    $monthNumber = (int) $month;
    $dayNumber = (int) $day;

    if (!checkdate($monthNumber, $dayNumber, $yearNumber)) {
        return '';
    }

    return sprintf('%04d-%02d-%02d', $yearNumber, $monthNumber, $dayNumber);
}

function save_connection_request_mvm_source_data(int $requestId, array $source, bool $allowPartial = false): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        throw new RuntimeException('Az adatlap nem található.');
    }

    $values = connection_request_mvm_source_form_values($request, $source);
    $birthDateHasValue = connection_request_mvm_source_birth_date_has_value($source);
    $values['birth_date'] = normalize_connection_request_mvm_source_birth_date($source);

    if ($birthDateHasValue && $values['birth_date'] === '') {
        if (!$allowPartial) {
            throw new RuntimeException('A születési időnél az év, hónap és nap mezőt is helyes dátummal kell kitölteni.');
        }

        $values['birth_date'] = (string) ($request['birth_date'] ?? '');
    }

    if (trim($values['requester_name']) === '') {
        if (!$allowPartial) {
            throw new RuntimeException('Az ügyfél neve kötelező.');
        }

        $values['requester_name'] = (string) ($request['requester_name'] ?? '');
    }

    if (trim($values['site_postal_code']) === '' || trim($values['site_address']) === '') {
        if (!$allowPartial) {
            throw new RuntimeException('A kivitelezési irányítószám és cím kötelező.');
        }

        if (trim($values['site_postal_code']) === '') {
            $values['site_postal_code'] = (string) ($request['site_postal_code'] ?? '');
        }

        if (trim($values['site_address']) === '') {
            $values['site_address'] = (string) ($request['site_address'] ?? '');
        }
    }

    $submittedProjectName = trim((string) ($values['project_name'] ?? ''));
    $values['project_name'] = $submittedProjectName !== ''
        ? connection_request_normalize_project_name($submittedProjectName)
        : connection_request_auto_project_name($values, $values);

    $labels = [
        'requester_name' => 'Ügyfél neve',
        'birth_name' => 'Születési név',
        'mother_name' => 'Anyja neve',
        'birth_place' => 'Születési hely',
        'birth_date' => 'Születési idő',
        'tax_number' => 'Adószám',
        'project_name' => 'Munka megnevezése',
        'request_type' => 'Munka típusa',
        'site_postal_code' => 'Kivitelezési irányítószám',
        'site_address' => 'Kivitelezési cím',
        'hrsz' => 'HRSZ',
        'meter_serial' => 'Mérő gyári szám',
        'consumption_place_id' => 'Fogyasztási hely azonosító',
    ];
    $changes = [];

    foreach ($labels as $key => $label) {
        $old = trim((string) ($request[$key] ?? ''));
        $new = trim((string) ($values[$key] ?? ''));

        if ($old !== $new) {
            $changes[] = $label . ': ' . ($old !== '' ? $old : '-') . ' -> ' . ($new !== '' ? $new : '-');
        }
    }

    db_query(
        'UPDATE `customers`
         SET `requester_name` = ?, `birth_name` = ?, `mother_name` = ?, `birth_place` = ?, `birth_date` = ?, `tax_number` = ?
         WHERE `id` = ?',
        [
            $values['requester_name'],
            $values['birth_name'] !== '' ? $values['birth_name'] : null,
            $values['mother_name'] !== '' ? $values['mother_name'] : null,
            $values['birth_place'] !== '' ? $values['birth_place'] : null,
            $values['birth_date'] !== '' ? $values['birth_date'] : null,
            $values['tax_number'] !== '' ? $values['tax_number'] : null,
            (int) $request['customer_id'],
        ]
    );

    db_query(
        'UPDATE `connection_requests`
         SET `request_type` = ?, `project_name` = ?, `site_address` = ?, `site_postal_code` = ?,
             `hrsz` = ?, `meter_serial` = ?, `consumption_place_id` = ?
         WHERE `id` = ?',
        [
            $values['request_type'],
            $values['project_name'],
            $values['site_address'],
            $values['site_postal_code'],
            $values['hrsz'] !== '' ? $values['hrsz'] : null,
            $values['meter_serial'] !== '' ? $values['meter_serial'] : null,
            $values['consumption_place_id'] !== '' ? $values['consumption_place_id'] : null,
            $requestId,
        ]
    );

    if ($changes !== []) {
        record_connection_request_activity(
            $requestId,
            'request_update',
            'MVM dokumentum adatlapadatai módosítva',
            implode("\n", array_slice($changes, 0, 12))
        );
    }

    return $values;
}

function connection_request_mvm_form(int $requestId): ?array
{
    if (!db_table_exists('connection_request_mvm_forms')) {
        return null;
    }

    $statement = db_query(
        'SELECT * FROM `connection_request_mvm_forms` WHERE `connection_request_id` = ? LIMIT 1',
        [$requestId]
    );
    $row = $statement->fetch();

    if (!is_array($row)) {
        return null;
    }

    $decoded = json_decode((string) ($row['form_data'] ?? ''), true);
    $row['form_values'] = is_array($decoded) ? $decoded : [];

    return $row;
}

function connection_request_mvm_form_values(array $request): array
{
    $values = mvm_form_default_values($request);
    $row = connection_request_mvm_form((int) $request['id']);

    if ($row === null) {
        return $values;
    }

    foreach (($row['form_values'] ?? []) as $key => $value) {
        if (array_key_exists($key, $values)) {
            $values[$key] = (string) $value;
        }
    }

    $values['mvm_contractor'] = normalize_mvm_contractor_key($values['mvm_contractor'] ?? null);

    return mvm_recalculate_power_financials(mvm_apply_plan_field_defaults($values));
}

function mvm_h_tariff_form_field_keys(): array
{
    return [
        'h_tarifa_gyarto',
        'h_tarifa_tipus',
        'h_tarifa_nevleges_villamos_kw',
        'h_tarifa_futesi_teljesitmeny_kw',
        'h_tarifa_scop',
        'h_tarifa_mukodesi_rendszer',
        'h_tarifa_teljes_egyideju_kw',
        'h_tarifa_futesi_fogyasztas_kwh',
        'h_tarifa_nyari_fogyasztas_kwh',
    ];
}

function mvm_h_tariff_form_values_are_filled(array $values): bool
{
    foreach (mvm_h_tariff_form_field_keys() as $key) {
        if (trim((string) ($values[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function connection_request_h_tariff_section_is_filled(int $requestId): bool
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return false;
    }

    return mvm_h_tariff_form_values_are_filled(connection_request_mvm_form_values($request));
}

function save_connection_request_mvm_form(int $requestId, array $source, ?array $sketchFile = null): array
{
    if (!db_table_exists('connection_request_mvm_forms')) {
        throw new RuntimeException('Az MVM űrlap mentéséhez futtasd le a database/mvm_docx_form.sql fájlt phpMyAdminban.');
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        throw new RuntimeException('Az igény nem található.');
    }

    $data = normalize_mvm_form_data($source);
    $existing = connection_request_mvm_form($requestId);
    $sketchOriginalName = $existing['sketch_original_name'] ?? null;
    $sketchStoredName = $existing['sketch_stored_name'] ?? null;
    $sketchStoragePath = $existing['sketch_storage_path'] ?? null;
    $sketchMimeType = $existing['sketch_mime_type'] ?? null;
    $sketchFileSize = $existing['sketch_file_size'] ?? null;

    if (uploaded_file_is_present($sketchFile)) {
        if (($sketchFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('A skicc kép feltöltése sikertelen.');
        }

        if ((int) ($sketchFile['size'] ?? 0) > PHOTO_MAX_BYTES) {
            throw new RuntimeException('A skicc kép túl nagy. Maximum 8 MB engedélyezett.');
        }

        $extension = strtolower(pathinfo((string) $sketchFile['name'], PATHINFO_EXTENSION));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (!in_array($extension, ['jpg', 'png', 'webp'], true)) {
            throw new RuntimeException('A skicc helyére JPG, PNG vagy WEBP kép tölthető fel.');
        }

        $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/mvm-form-sketch';
        ensure_storage_dir($targetDir);
        $storedName = 'skicc-' . bin2hex(random_bytes(12)) . '.' . $extension;
        $targetPath = $targetDir . '/' . $storedName;

        if (!move_uploaded_file((string) $sketchFile['tmp_name'], $targetPath)) {
            throw new RuntimeException('A skicc képet nem sikerült menteni.');
        }

        $mimeType = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: '') : '';
        $sketchOriginalName = (string) $sketchFile['name'];
        $sketchStoredName = $storedName;
        $sketchStoragePath = $targetPath;
        $sketchMimeType = $mimeType !== '' ? $mimeType : 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension);
        $sketchFileSize = (int) $sketchFile['size'];
    }

    $user = current_user();
    db_query(
        'INSERT INTO `connection_request_mvm_forms`
            (`connection_request_id`, `form_data`, `sketch_original_name`, `sketch_stored_name`, `sketch_storage_path`,
             `sketch_mime_type`, `sketch_file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            `form_data` = VALUES(`form_data`),
            `sketch_original_name` = VALUES(`sketch_original_name`),
            `sketch_stored_name` = VALUES(`sketch_stored_name`),
            `sketch_storage_path` = VALUES(`sketch_storage_path`),
            `sketch_mime_type` = VALUES(`sketch_mime_type`),
            `sketch_file_size` = VALUES(`sketch_file_size`),
            `updated_at` = CURRENT_TIMESTAMP',
        [
            $requestId,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $sketchOriginalName,
            $sketchStoredName,
            $sketchStoragePath,
            $sketchMimeType,
            $sketchFileSize,
            is_array($user) ? (int) $user['id'] : null,
        ]
    );

    return $data;
}

function delete_connection_request_mvm_form_sketch(int $requestId): array
{
    if (!db_table_exists('connection_request_mvm_forms')) {
        return ['ok' => false, 'message' => 'Az MVM űrlap adatbázistáblája nem elérhető.'];
    }

    $row = connection_request_mvm_form($requestId);

    if ($row === null || empty($row['sketch_storage_path'])) {
        return ['ok' => false, 'message' => 'Nincs törölhető skicc kép ehhez az adatlaphoz.'];
    }

    db_query(
        'UPDATE `connection_request_mvm_forms`
         SET `sketch_original_name` = NULL,
             `sketch_stored_name` = NULL,
             `sketch_storage_path` = NULL,
             `sketch_mime_type` = NULL,
             `sketch_file_size` = NULL,
             `updated_at` = CURRENT_TIMESTAMP
         WHERE `connection_request_id` = ?',
        [$requestId]
    );
    delete_storage_files([(string) $row['sketch_storage_path']]);
    record_connection_request_activity($requestId, 'file_delete', 'MVM skicc kép törölve', (string) ($row['sketch_original_name'] ?? ''));

    return ['ok' => true, 'message' => 'A skicc kép törölve.'];
}

function mvm_docx_template_path(?string $contractorKey = null): ?string
{
    $contractorKey = normalize_mvm_contractor_key($contractorKey);
    $contractor = mvm_contractor_definition($contractorKey);
    $candidates = mvm_template_candidates('contractor-templates/' . $contractor['template']);

    if ($contractorKey === 'primavill') {
        $candidates[] = defined('MVM_DOCX_TEMPLATE_PATH') ? MVM_DOCX_TEMPLATE_PATH : '';
        $candidates = array_merge($candidates, mvm_template_candidates('primavill_igenybejelento_2026_lakossagi.docx'));
        $candidates[] = APP_ROOT . '/Dokumentumok/Fővállalkozói dokumentumok/Primavill/Primavill_igénybejelentő_2026_lakossági.docx';
    }

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function mvm_pdf_template_path(): ?string
{
    $candidates = [
        defined('MVM_BLANK_PDF_TEMPLATE_PATH') ? MVM_BLANK_PDF_TEMPLATE_PATH : '',
        defined('MVM_PDF_TEMPLATE_PATH') ? MVM_PDF_TEMPLATE_PATH : '',
        APP_ROOT . '/Dokumentumok/Fővállalkozói dokumentumok/Primavill/primavill_igenybejelento_2026_lakossagi.pdf',
    ];

    $candidates = array_merge(
        $candidates,
        mvm_template_candidates('primavill_igenybejelento_2026_lakossagi_blank.pdf'),
        mvm_template_candidates('primavill_igenybejelento_2026_lakossagi.pdf')
    );

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function mvm_pdf_template_requires_cover(string $templatePath): bool
{
    return !str_contains(strtolower(basename($templatePath)), '_blank');
}

function format_mvm_docx_date(?string $date): string
{
    $date = trim((string) $date);

    if ($date === '') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('Y.m.d.', $timestamp) : $date;
}

function mvm_docx_xml_value(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function mvm_docx_placeholder_map(array $request, array $values): array
{
    $field = static fn (string $key): string => (string) ($values[$key] ?? '');
    $customerAddress = trim(
        (string) ($request['postal_code'] ?? '') . ' ' .
        (string) ($request['city'] ?? '') . ' ' .
        (string) ($request['postal_address'] ?? '')
    );
    $birthName = trim((string) ($request['birth_name'] ?? ''));

    if ($birthName === '') {
        $birthName = (string) ($request['requester_name'] ?? '');
    }

    $motherName = (string) ($request['mother_name'] ?? '');
    $companyNumber = $field('cegjegyzekszam');
    $motherOrCompanyNumber = $companyNumber !== '' ? $companyNumber : $motherName;
    $birthDate = format_mvm_docx_date((string) ($request['birth_date'] ?? ''));
    $birthDateParts = connection_request_mvm_source_birth_date_parts((string) ($request['birth_date'] ?? ''));
    $map = [
        '{d.Person.Name}' => (string) ($request['requester_name'] ?? ''),
        '{d.Person.Phone}' => (string) ($request['phone'] ?? ''),
        '{d.Person.Email}' => (string) ($request['email'] ?? ''),
    ];

    $addProject = static function (string $name, string $value) use (&$map): void {
        $map['{d.Project.' . $name . '}'] = $value;
        $map['{d.Project. ' . $name . '}'] = $value;
    };

    $addProject('Nev', (string) ($request['requester_name'] ?? ''));
    $addProject('Rnev', $birthName);
    $addProject('SzuletesiNev', $birthName);
    $addProject('Adoszam2', $field('adoszam2'));
    $addProject('Adoszam', $field('adoszam2'));
    $addProject('Cegjegyzekszam', $companyNumber);
    $addProject('AnyjaNeve', $motherName);
    $addProject('AnyjaNevecegjegyzekszam', $motherOrCompanyNumber);
    $addProject('SzuletesiHely', (string) ($request['birth_place'] ?? ''));
    $addProject('SzuletesiHelye', (string) ($request['birth_place'] ?? ''));
    $addProject('SzuletesiIdo', $birthDate);
    $addProject('SzuletesiIdeje', $birthDate);
    $addProject('SzuletesiDatum', $birthDate);
    $addProject('SzuletesiEv', $birthDateParts['year']);
    $addProject('SzuletesiHonap', $birthDateParts['month']);
    $addProject('SzuletesiHo', $birthDateParts['month']);
    $addProject('SzuletesiNap', $birthDateParts['day']);
    $addProject('UgyfelLakcime', $customerAddress);
    $addProject('IranyitoSzam', $field('iranyito_szam'));
    $addProject('Iranyitoszam2', $field('iranyitoszam2'));
    $addProject('Varos', $field('varos'));
    $addProject('Varos2', $field('varos2'));
    $addProject('Utca', $field('utca'));
    $addProject('Hazszam', $field('hazszam'));
    $addProject('EmeletAjto', $field('emelet_ajto'));
    $addProject('FelhasznalasiCim', $field('felhasznalasi_cim'));
    $addProject('HelyrajziSzam', (string) ($request['hrsz'] ?? ''));
    $addProject('Fha', $field('fha'));
    $addProject('MertekadoEvesFogyasztas', $field('mertekado_eves_fogyasztas'));
    $addProject('Mt', $field('mt'));
    $addProject('UjFogyaszto', $field('uj_fogyaszto'));
    $addProject('FizetendoTeljesitmeny2', $field('fizetendo_teljesitmeny_ampere'));
    $addProject('Fo', $field('fizetendo_teljesitmeny_osszeg'));
    $addProject('EgyediMerohelyFelulvizsgalat', $field('egyedi_merohely_felulvizsgalat'));
    $addProject('HmkeBekakapcsolas', $field('hmke_bekapcsolas'));
    $addProject('HTarifaVagyMelleszereles', $field('h_tarifa_vagy_melleszereles'));
    $addProject('CsakKismegszakitocsere', $field('csak_kismegszakitocsere'));
    $addProject('KozvilagitasMerohelyLetesites', $field('kozvilagitas_merohely_letesites'));
    $addProject('CsatlakozoBerendezesHelyreallitasa', $field('csatlakozo_berendezes_helyreallitasa'));
    $addProject('LakossagiFogyaszto', $field('lakossagi_fogyaszto'));
    $addProject('NemLakossagiFogyaszto', $field('nem_lakossagi_fogyaszto'));
    $addProject('RendeltetesLakasHaz', $field('rendeltetes_lakas_haz'));
    $addProject('RendeltetesIrodaUzletRendelo', $field('rendeltetes_iroda_uzlet_rendelo'));
    $addProject('RendeltetesIpariUzemiTerulet', $field('rendeltetes_ipari_uzemi_terulet'));
    $addProject('RendeltetesZartkertPinceTanya', $field('rendeltetes_zartkert_pince_tanya'));
    $addProject('RendeltetesUduloNyaralo', $field('rendeltetes_udulo_nyaralo'));
    $addProject('RendeltetesGarazs', $field('rendeltetes_garazs'));
    $addProject('RendeltetesTarsashaziKozosseg', $field('rendeltetes_tarsashazi_kozosseg'));
    $addProject('RendeltetesEgyeb', $field('rendeltetes_egyeb'));
    $addProject('RendeltetesEgyebSzoveg', $field('rendeltetes_egyeb_szoveg'));
    $addProject('RendeltetesCsoportosDb', $field('rendeltetes_csoportos_db'));
    $addProject('JogcimTulajdonos', $field('jogcim_tulajdonos'));
    $addProject('JogcimBerlo', $field('jogcim_berlo'));
    $addProject('JogcimHaszonelvezo', $field('jogcim_haszonelvezo'));
    $addProject('JogcimKezelo', $field('jogcim_kezelo'));
    $addProject('JogcimEgyeb', $field('jogcim_egyeb'));
    $addProject('JogcimEgyebSzoveg', $field('jogcim_egyeb_szoveg'));
    $addProject('TeljesitmenyCsokkentese', $field('teljesitmeny_csokkentese'));
    $addProject('CsatlakozovezetekAthelyezese', $field('csatlakozovezetek_athelyezese'));
    $addProject('CsatlakozovezetekCsereje', $field('csatlakozovezetek_csereje'));
    $addProject('CsatlakozasiModValtasa', $field('csatlakozasi_mod_valtasa'));
    $addProject('MeroAthelyezese', $field('mero_athelyezese'));
    $addProject('VezereltMeroSzerelese', $field('vezerelt_mero_szerelese'));
    $addProject('OkosmeroSzerelese', $field('okosmero_szerelese'));
    $addProject('EloreFizetosMero', $field('elore_fizetos_mero'));
    $addProject('A2TarifasMero', $field('a2_tarifas_mero'));
    $addProject('IdeiglenesBekapcsolas', $field('ideiglenes_bekapcsolas'));
    $addProject('Foldkabeles', $field('foldkabeles'));
    $addProject('Legvezetekes', $field('legvezetekes'));
    $addProject('N13', $field('n13'));
    $addProject('N216', $field('n216'));
    $addProject('N416', $field('n416'));
    $addProject('N225', $field('n225'));
    $addProject('N425', $field('n425'));
    $addProject('N425f', $field('n425f'));
    $addProject('N435f', $field('n435f'));
    $map['{d.Project. N435f }'] = $field('n435f');
    $map['{d.Project.N435f }'] = $field('n435f');
    $addProject('N450f', $field('n450f'));
    $map['{d.Project. N450f }'] = $field('n450f');
    $map['{d.Project.N450f }'] = $field('n450f');
    $addProject('N470f', $field('n470f'));
    $addProject('N495f', $field('n495f'));
    $addProject('SzekrenyTipusa', $field('szekreny_tipusa'));
    $addProject('SzekrenyBruttoEgysegara', $field('szekreny_brutto_egysegar'));
    $addProject('JelenlegiMeroszekreny', $field('jelenlegi_meroszekreny'));
    $addProject('SzekrenyFelulvizsgalatiDijBruttoAra', $field('szekreny_felulvizsgalati_dij'));
    $addProject('Jml1', $field('jml1'));
    $addProject('Jml2', $field('jml2'));
    $addProject('Jml3', $field('jml3'));
    $addProject('Iml1', $field('iml1'));
    $addProject('Iml2', $field('iml2'));
    $addProject('Iml3', $field('iml3'));
    $addProject('JelenlegiHL1', $field('jelenlegi_hl1'));
    $addProject('JelenlegiHL2', $field('jelenlegi_hl2'));
    $addProject('JelenlegiHL3', $field('jelenlegi_hl3'));
    $addProject('Ihl1', $field('ihl1'));
    $addProject('Ihl2', $field('ihl2'));
    $addProject('Ihl3', $field('ihl3'));
    $addProject('Jvl1', $field('jvl1'));
    $addProject('Jvl2', $field('jvl2'));
    $addProject('Jvl3', $field('jvl3'));
    $addProject('Ivl1', $field('ivl1'));
    $addProject('Ivl2', $field('ivl2'));
    $addProject('Ivl3', $field('ivl3'));
    $addProject('IgenyeltOsszesTeljesitmeny', $field('igenyelt_osszes_teljesitmeny'));
    $addProject('IgenyeltOsszesMindennapszakiTeljesitmeny', $field('igenyelt_osszes_mindennapszaki_teljesitmeny'));
    $addProject('OsszesIgenyeltVezereltTeljesitmeny', $field('osszes_igenyelt_vezerelt_teljesitmeny'));
    $addProject('OsszesIgenyeltHTeljesitmeny', $field('osszes_igenyelt_h_teljesitmeny'));
    $addProject('MeglevoOsszesTeljesitmeny', $field('meglevo_osszes_teljesitmeny'));
    $addProject('MeglevoOsszesMindennapszakiTeljesitmeny', $field('meglevo_osszes_mindennapszaki_teljesitmeny'));
    $addProject('OsszesMeglevoVezereltTeljesitmeny', $field('osszes_meglevo_vezerelt_teljesitmeny'));
    $addProject('OsszesMeglevoHTeljesitmeny', $field('osszes_meglevo_h_teljesitmeny'));
    $addProject('IgenyeltMindennapszakiKva', $field('igenyelt_mindennapszaki_kva'));
    $addProject('IgenyeltVezereltKva', $field('igenyelt_vezerelt_kva'));
    $addProject('IgenyeltHKva', $field('igenyelt_h_kva'));
    $addProject('MeglevoMindennapszakiKva', $field('meglevo_mindennapszaki_kva'));
    $addProject('MeglevoVezereltKva', $field('meglevo_vezerelt_kva'));
    $addProject('MeglevoHKva', $field('meglevo_h_kva'));
    $addProject('IgenyeltOsszesKva', $field('igenyelt_osszes_kva'));
    $addProject('MeglevoOsszesKva', $field('meglevo_osszes_kva'));
    $addProject('MindennapszakiHafhoz32VagyTobb', $field('ingyenes_teljesitmeny_ampere'));
    $addProject('Sc', $field('sc'));
    $addProject('Tn', $field('tn'));
    $addProject('Ot', $field('igenyelt_osszes_mindennapszaki_teljesitmeny'));
    $addProject('Ofo', $field('ofo'));
    $addProject('Ohfk', $field('ohfk'));
    $addProject('Ofosz', $field('ofosz'));
    $addProject('Otvez', $field('osszes_igenyelt_vezerelt_teljesitmeny'));
    $addProject('OszlopTipusa', $field('oszlop_tipusa'));
    $addProject('TetotartoHossz', $field('tetotarto_hossz'));
    $addProject('OsszKabelhossz', $field('ossz_kabelhossz'));
    $addProject('VizszintesKabelhosszM', $field('vizszintes_kabelhossz_m'));
    $addProject('OszloptelepitesKoltseg', $field('oszloptelepites_koltseg'));
    $addProject('LegvezetekesCsatlakozoKoltseg', $field('legvezetekes_csatlakozo_koltseg'));
    $addProject('CsatlakozoBerendezesHelyreallitasKoltseg', $field('csatlakozo_berendezes_helyreallitas_koltseg'));
    $addProject('FoldkabelTobletkoltseg', $field('foldkabel_tobletkoltseg'));
    $addProject('LegvezetekesTobbletkoltseg', $field('legvezetekes_tobbletkoltseg'));
    $addProject('MuszakiTobbletkoltseg', $field('muszaki_tobbletkoltseg'));
    $addProject('MerooraHelyeJelenleg', $field('meroora_helye_jelenleg'));
    $addProject('Gyarto', $field('h_tarifa_gyarto'));
    $addProject('Tipus', $field('h_tarifa_tipus'));
    $addProject('NevlegesVillamosTeljesitmeny', $field('h_tarifa_nevleges_villamos_kw'));
    $addProject('FutesiTeljesitmeny', $field('h_tarifa_futesi_teljesitmeny_kw'));
    $addProject('ScopErtek', $field('h_tarifa_scop'));
    $addProject('TeljesEgyidejuVillamosTeljesitmeny', $field('h_tarifa_teljes_egyideju_kw'));
    $addProject('VarhatoFogyasztasFutesiIdoszak', $field('h_tarifa_futesi_fogyasztas_kwh'));
    $addProject('VarhatoFogyasztasNyariIdoszak', $field('h_tarifa_nyari_fogyasztas_kwh'));
    $leadDate = $field('leadas_datuma') !== '' ? $field('leadas_datuma') : $field('datum');
    $addProject('Laed', format_mvm_docx_date($leadDate));
    $addProject('Kva', $field('igenyelt_osszes_kva'));
    $addProject('VezetekTipusa', $field('plan_csatlakozas_tipusa'));
    $addProject('PlanHeaderLine1', $field('plan_header_line_1'));
    $addProject('PlanHeaderLine2', $field('plan_header_line_2'));
    $addProject('PlanCsatlakozasTipusa', $field('plan_csatlakozas_tipusa'));
    $addProject('PlanCsatlakozasModja', $field('plan_csatlakozas_modja'));
    $addProject('PlanMuszakiLeiras', $field('plan_muszaki_leiras'));
    $addProject('Mgyszv', $field('mgyszv'));
    $addProject('Mogysz', '');
    $addProject('Szfd', $field('szfd') !== '' ? $field('szfd') : $field('szekreny_felulvizsgalati_dij'));
    $addProject('N7esPontNev', $field('n7es_pont_nev'));
    $addProject('N7esPontCim', $field('n7es_pont_cim'));
    $addProject('KockasPapirVezetek', $field('kockas_papir_vezetek'));
    $addProject('KockasPapirSzekreny', $field('kockas_papir_szekreny'));
    $addProject('Datum', $field('datum'));
    $addProject('KeszrejelentesDatum', date('Y.m.d.'));
    $addProject('SkiccFeltoltese', '');
    $addProject('MeghatalmazasFeltoltese', '');
    $addProject('MeghatalmazasFeltoltese2', '');
    $map['{d.Projekt.OszlopTipusa}'] = $field('oszlop_tipusa');

    return $map;
}

function replace_docx_placeholders_in_xml(string $xml, array $placeholderMap): string
{
    preg_match_all(
        '/(<((?:[A-Za-z_][A-Za-z0-9_.-]*:)?p)(?:\s[^>]*)?>[\s\S]*?<\/\2>)/',
        $xml,
        $paragraphMatches,
        PREG_OFFSET_CAPTURE
    );

    if (empty($paragraphMatches[0])) {
        return replace_docx_placeholders_in_xml_fragment($xml, $placeholderMap);
    }

    $result = '';
    $cursor = 0;

    foreach ($paragraphMatches[0] as [$paragraphXml, $xmlOffset]) {
        $xmlOffset = (int) $xmlOffset;
        $result .= substr($xml, $cursor, $xmlOffset - $cursor);
        $result .= replace_docx_placeholders_in_xml_fragment($paragraphXml, $placeholderMap);
        $cursor = $xmlOffset + strlen($paragraphXml);
    }

    return $result . substr($xml, $cursor);
}

function replace_docx_placeholders_in_xml_fragment(string $xml, array $placeholderMap): string
{
    preg_match_all(
        '/(<((?:[A-Za-z_][A-Za-z0-9_.-]*:)?t)(?:\s[^>]*)?(?<!\/)>)([\s\S]*?)(<\/\2>)/',
        $xml,
        $textNodeMatches,
        PREG_OFFSET_CAPTURE
    );

    if (empty($textNodeMatches[0])) {
        return strtr($xml, array_map('mvm_docx_xml_value', $placeholderMap));
    }

    $nodeValues = [];
    $nodeXmlRanges = [];
    $fullText = '';

    foreach ($textNodeMatches[0] as $index => [$fullMatch, $xmlOffset]) {
        $value = html_entity_decode($textNodeMatches[3][$index][0], ENT_QUOTES | ENT_XML1, 'UTF-8');
        $nodeValues[] = $value;
        $fullText .= $value;
        $nodeXmlRanges[] = [
            'start' => (int) $xmlOffset,
            'length' => strlen($fullMatch),
            'open' => $textNodeMatches[1][$index][0],
            'close' => $textNodeMatches[4][$index][0],
        ];
    }

    preg_match_all('/\{\{?\s*d\s*\.\s*[^}]{1,220}\}/i', $fullText, $matches, PREG_OFFSET_CAPTURE);
    $replacements = [];

    foreach ($matches[0] as [$token, $position]) {
        $mapToken = normalize_mvm_docx_placeholder_token($token);

        if (!array_key_exists($mapToken, $placeholderMap)) {
            continue;
        }

        $replacements[] = [
            'start' => (int) $position,
            'end' => (int) $position + strlen($token),
            'value' => (string) $placeholderMap[$mapToken],
        ];
    }

    if ($replacements === []) {
        return $xml;
    }

    $replacementIndex = 0;
    $offset = 0;
    $replacementCount = count($replacements);
    $newNodeValues = [];

    foreach ($nodeValues as $nodeIndex => $original) {
        $original = $nodeValues[$nodeIndex];
        $nodeStart = $offset;
        $nodeLength = strlen($original);
        $nodeEnd = $nodeStart + $nodeLength;
        $cursor = $nodeStart;
        $newValue = '';

        while ($replacementIndex < $replacementCount && $replacements[$replacementIndex]['end'] <= $nodeStart) {
            $replacementIndex++;
        }

        $scanIndex = $replacementIndex;

        while ($scanIndex < $replacementCount) {
            $replacement = $replacements[$scanIndex];

            if ($replacement['start'] >= $nodeEnd) {
                break;
            }

            if ($replacement['start'] < $nodeStart && $replacement['end'] > $nodeStart) {
                $cursor = max($cursor, min($nodeEnd, $replacement['end']));
                $scanIndex++;
                continue;
            }

            if ($replacement['start'] >= $nodeStart && $replacement['start'] < $nodeEnd) {
                $newValue .= substr($fullText, $cursor, $replacement['start'] - $cursor);
                $newValue .= $replacement['value'];
                $cursor = max($cursor, min($nodeEnd, $replacement['end']));
            }

            $scanIndex++;
        }

        if ($cursor < $nodeEnd) {
            $newValue .= substr($fullText, $cursor, $nodeEnd - $cursor);
        }

        $newNodeValues[$nodeIndex] = $newValue;
        $offset = $nodeEnd;
    }

    $result = '';
    $xmlCursor = 0;

    foreach ($nodeXmlRanges as $index => $range) {
        $result .= substr($xml, $xmlCursor, $range['start'] - $xmlCursor);
        $result .= $range['open']
            . mvm_docx_xml_value($newNodeValues[$index] ?? $nodeValues[$index])
            . $range['close'];
        $xmlCursor = $range['start'] + $range['length'];
    }

    return $result . substr($xml, $xmlCursor);
}

function replace_docx_literal_text_in_xml(string $xml, array $literalMap): string
{
    preg_match_all(
        '/(<((?:[A-Za-z_][A-Za-z0-9_.-]*:)?p)(?:\s[^>]*)?>[\s\S]*?<\/\2>)/',
        $xml,
        $paragraphMatches,
        PREG_OFFSET_CAPTURE
    );

    if (empty($paragraphMatches[0])) {
        return replace_docx_literal_text_in_xml_fragment($xml, $literalMap);
    }

    $result = '';
    $cursor = 0;

    foreach ($paragraphMatches[0] as [$paragraphXml, $xmlOffset]) {
        $xmlOffset = (int) $xmlOffset;
        $result .= substr($xml, $cursor, $xmlOffset - $cursor);
        $result .= replace_docx_literal_text_in_xml_fragment($paragraphXml, $literalMap);
        $cursor = $xmlOffset + strlen($paragraphXml);
    }

    return $result . substr($xml, $cursor);
}

function replace_docx_literal_text_in_xml_fragment(string $xml, array $literalMap): string
{
    preg_match_all(
        '/(<((?:[A-Za-z_][A-Za-z0-9_.-]*:)?t)(?:\s[^>]*)?(?<!\/)>)([\s\S]*?)(<\/\2>)/',
        $xml,
        $textNodeMatches,
        PREG_OFFSET_CAPTURE
    );

    if (empty($textNodeMatches[0])) {
        return strtr($xml, array_map('mvm_docx_xml_value', $literalMap));
    }

    $nodeValues = [];
    $nodeXmlRanges = [];
    $fullText = '';

    foreach ($textNodeMatches[0] as $index => [$fullMatch, $xmlOffset]) {
        $value = html_entity_decode($textNodeMatches[3][$index][0], ENT_QUOTES | ENT_XML1, 'UTF-8');
        $nodeValues[] = $value;
        $fullText .= $value;
        $nodeXmlRanges[] = [
            'start' => (int) $xmlOffset,
            'length' => strlen($fullMatch),
            'open' => $textNodeMatches[1][$index][0],
            'close' => $textNodeMatches[4][$index][0],
        ];
    }

    $replacements = [];

    foreach ($literalMap as $literal => $replacementValue) {
        $literal = (string) $literal;

        if ($literal === '') {
            continue;
        }

        $offset = 0;

        while (($position = strpos($fullText, $literal, $offset)) !== false) {
            $replacements[] = [
                'start' => (int) $position,
                'end' => (int) $position + strlen($literal),
                'value' => (string) $replacementValue,
            ];
            $offset = (int) $position + max(1, strlen($literal));
        }
    }

    if ($replacements === []) {
        return $xml;
    }

    usort($replacements, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

    $filtered = [];
    $lastEnd = -1;

    foreach ($replacements as $replacement) {
        if ($replacement['start'] < $lastEnd) {
            continue;
        }

        $filtered[] = $replacement;
        $lastEnd = $replacement['end'];
    }

    $replacementIndex = 0;
    $offset = 0;
    $replacementCount = count($filtered);
    $newNodeValues = [];

    foreach ($nodeValues as $nodeIndex => $original) {
        $nodeStart = $offset;
        $nodeLength = strlen($original);
        $nodeEnd = $nodeStart + $nodeLength;
        $cursor = $nodeStart;
        $newValue = '';

        while ($replacementIndex < $replacementCount && $filtered[$replacementIndex]['end'] <= $nodeStart) {
            $replacementIndex++;
        }

        $scanIndex = $replacementIndex;

        while ($scanIndex < $replacementCount) {
            $replacement = $filtered[$scanIndex];

            if ($replacement['start'] >= $nodeEnd) {
                break;
            }

            if ($replacement['start'] < $nodeStart && $replacement['end'] > $nodeStart) {
                $cursor = max($cursor, min($nodeEnd, $replacement['end']));
                $scanIndex++;
                continue;
            }

            if ($replacement['start'] >= $nodeStart && $replacement['start'] < $nodeEnd) {
                $newValue .= substr($fullText, $cursor, $replacement['start'] - $cursor);
                $newValue .= $replacement['value'];
                $cursor = max($cursor, min($nodeEnd, $replacement['end']));
            }

            $scanIndex++;
        }

        if ($cursor < $nodeEnd) {
            $newValue .= substr($fullText, $cursor, $nodeEnd - $cursor);
        }

        $newNodeValues[$nodeIndex] = $newValue;
        $offset = $nodeEnd;
    }

    $result = '';
    $xmlCursor = 0;

    foreach ($nodeXmlRanges as $index => $range) {
        $result .= substr($xml, $xmlCursor, $range['start'] - $xmlCursor);
        $result .= $range['open']
            . mvm_docx_xml_value($newNodeValues[$index] ?? $nodeValues[$index])
            . $range['close'];
        $xmlCursor = $range['start'] + $range['length'];
    }

    return $result . substr($xml, $xmlCursor);
}

function normalize_mvm_docx_placeholder_token(string $token): string
{
    $token = trim($token);
    $token = (string) preg_replace('/\s+/', '', $token);
    $token = (string) preg_replace('/^\{\{?d\./i', '{d.', $token);

    return $token;
}

function replace_docx_placeholders_in_xml_dom(string $xml, array $placeholderMap): string
{
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
        return replace_docx_placeholders_in_xml($xml, $placeholderMap);
    }

    $dom = new DOMDocument();
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);

    if (!$loaded) {
        return strtr($xml, array_map('mvm_docx_xml_value', $placeholderMap));
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $paragraphs = $xpath->query('//w:p');

    if ($paragraphs === false) {
        return (string) $dom->saveXML();
    }

    foreach ($paragraphs as $paragraph) {
        $textNodes = $xpath->query('.//w:t', $paragraph);

        if ($textNodes === false || $textNodes->length === 0) {
            continue;
        }

        $textNodeList = [];
        $nodeValues = [];
        $fullText = '';

        foreach ($textNodes as $textNode) {
            $textNodeList[] = $textNode;
            $value = (string) $textNode->nodeValue;
            $nodeValues[] = $value;
            $fullText .= $value;
        }

        preg_match_all('/\{\{?\s*d\s*\.\s*[^}]{1,220}\}/i', $fullText, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            continue;
        }

        $replacements = [];

        foreach ($matches[0] as [$token, $position]) {
            $mapToken = normalize_mvm_docx_placeholder_token((string) $token);

            if (!array_key_exists($mapToken, $placeholderMap)) {
                continue;
            }

            $replacements[] = [
                'start' => (int) $position,
                'end' => (int) $position + strlen((string) $token),
                'value' => (string) $placeholderMap[$mapToken],
            ];
        }

        if ($replacements === []) {
            continue;
        }

        $replacementIndex = 0;
        $offset = 0;
        $replacementCount = count($replacements);
        $newNodeValues = [];

        foreach ($nodeValues as $nodeIndex => $original) {
            $nodeStart = $offset;
            $nodeLength = strlen($original);
            $nodeEnd = $nodeStart + $nodeLength;
            $cursor = $nodeStart;
            $newValue = '';

            while ($replacementIndex < $replacementCount && $replacements[$replacementIndex]['end'] <= $nodeStart) {
                $replacementIndex++;
            }

            $scanIndex = $replacementIndex;

            while ($scanIndex < $replacementCount) {
                $replacement = $replacements[$scanIndex];

                if ($replacement['start'] >= $nodeEnd) {
                    break;
                }

                if ($replacement['start'] < $nodeStart && $replacement['end'] > $nodeStart) {
                    $cursor = max($cursor, min($nodeEnd, $replacement['end']));
                    $scanIndex++;
                    continue;
                }

                if ($replacement['start'] >= $nodeStart && $replacement['start'] < $nodeEnd) {
                    $newValue .= substr($fullText, $cursor, $replacement['start'] - $cursor);
                    $newValue .= $replacement['value'];
                    $cursor = max($cursor, min($nodeEnd, $replacement['end']));
                }

                $scanIndex++;
            }

            if ($cursor < $nodeEnd) {
                $newValue .= substr($fullText, $cursor, $nodeEnd - $cursor);
            }

            $newNodeValues[$nodeIndex] = $newValue;
            $offset = $nodeEnd;
        }

        foreach ($textNodeList as $nodeIndex => $textNode) {
            $textNode->nodeValue = $newNodeValues[$nodeIndex] ?? $nodeValues[$nodeIndex] ?? '';
        }
    }

    return (string) $dom->saveXML();
}

function mvm_docx_add_underline_to_run(string $runXml): string
{
    if (str_contains($runXml, '<w:u')) {
        return $runXml;
    }

    if (preg_match('/<w:rPr\b[^>]*>/', $runXml, $matches, PREG_OFFSET_CAPTURE)) {
        $insertAt = (int) $matches[0][1] + strlen($matches[0][0]);

        return substr($runXml, 0, $insertAt) . '<w:u w:val="single"/>' . substr($runXml, $insertAt);
    }

    return (string) preg_replace(
        '/(<w:r\b[^>]*>)/',
        '$1<w:rPr><w:u w:val="single"/></w:rPr>',
        $runXml,
        1
    );
}

function mvm_docx_underline_text_in_xml(string $xml, string $text): string
{
    $text = trim($text);

    if ($text === '') {
        return $xml;
    }

    $encodedText = preg_quote(mvm_docx_xml_value($text), '/');
    $plainText = preg_quote($text, '/');
    $pattern = '/<w:r\b[^>]*>(?:(?!<\/w:r>).)*(?:' . $encodedText . '|' . $plainText . ')(?:(?!<\/w:r>).)*<\/w:r>/su';

    return (string) preg_replace_callback(
        $pattern,
        static fn (array $matches): string => mvm_docx_add_underline_to_run((string) $matches[0]),
        $xml
    );
}

function prepare_mvm_docx_sketch_png(string $sourcePath): string
{
    $imageInfo = getimagesize($sourcePath);

    if ($imageInfo === false) {
        throw new RuntimeException('A skicc kép nem olvasható.');
    }

    [$sourceWidth, $sourceHeight, $imageType] = $imageInfo;
    $sourceImage = match ($imageType) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
        IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : false,
        default => false,
    };

    if (!$sourceImage) {
        throw new RuntimeException('A skicc kép formátuma nem támogatott.');
    }

    $targetWidth = 1400;
    $targetHeight = 1252;
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    imagefill($targetImage, 0, 0, imagecolorallocate($targetImage, 255, 255, 255));

    $ratio = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
    $renderWidth = max(1, (int) floor($sourceWidth * $ratio));
    $renderHeight = max(1, (int) floor($sourceHeight * $ratio));
    $left = (int) floor(($targetWidth - $renderWidth) / 2);
    $top = (int) floor(($targetHeight - $renderHeight) / 2);
    imagecopyresampled($targetImage, $sourceImage, $left, $top, 0, 0, $renderWidth, $renderHeight, $sourceWidth, $sourceHeight);

    $targetPath = tempnam(sys_get_temp_dir(), 'mezo_mvm_sketch_') . '.png';
    imagepng($targetImage, $targetPath, 6);
    imagedestroy($sourceImage);
    imagedestroy($targetImage);

    return $targetPath;
}

function mvm_plan_photo_placeholder_map(): array
{
    return [
        '{d.Project.HelysziniFotok}' => 'meter_close',
        '{d.Project.FotoEgyebMerorolKozelrol}' => 'meter_far',
        '{d.Project.FotoATetotertorol}' => 'roof_hook',
        '{d.Project.FotoAVillanyoszloprol}' => 'utility_pole',
    ];
}

function first_connection_request_image_file(int $requestId, string $fileType): ?array
{
    foreach (connection_request_files($requestId) as $file) {
        if ((string) ($file['file_type'] ?? '') !== $fileType) {
            continue;
        }

        $path = (string) ($file['storage_path'] ?? '');
        $mimeType = (string) ($file['mime_type'] ?? '');

        if ($path !== '' && is_file($path) && (str_starts_with($mimeType, 'image/') || @getimagesize($path) !== false)) {
            return $file;
        }
    }

    return null;
}

function prepare_mvm_docx_plan_photo_image(string $sourcePath, int $canvasWidth, int $canvasHeight, string $extension = 'jpg'): string
{
    $imageInfo = getimagesize($sourcePath);

    if ($imageInfo === false) {
        throw new RuntimeException('A tervdokumentációhoz tartozó fotó nem olvasható.');
    }

    [$sourceWidth, $sourceHeight, $imageType] = $imageInfo;
    $sourceImage = match ($imageType) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
        IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : false,
        default => false,
    };

    if (!$sourceImage) {
        throw new RuntimeException('A tervdokumentációhoz tartozó fotó formátuma nem támogatott.');
    }

    if ($imageType === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;

        if ($orientation === 3) {
            $sourceImage = imagerotate($sourceImage, 180, 0);
        } elseif ($orientation === 6) {
            $sourceImage = imagerotate($sourceImage, -90, 0);
        } elseif ($orientation === 8) {
            $sourceImage = imagerotate($sourceImage, 90, 0);
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
    }

    $canvasWidth = max(500, $canvasWidth);
    $canvasHeight = max(500, $canvasHeight);
    $targetImage = imagecreatetruecolor($canvasWidth, $canvasHeight);
    imagefill($targetImage, 0, 0, imagecolorallocate($targetImage, 255, 255, 255));

    $ratio = min($canvasWidth / $sourceWidth, $canvasHeight / $sourceHeight);
    $renderWidth = max(1, (int) floor($sourceWidth * $ratio));
    $renderHeight = max(1, (int) floor($sourceHeight * $ratio));
    $left = (int) floor(($canvasWidth - $renderWidth) / 2);
    $top = (int) floor(($canvasHeight - $renderHeight) / 2);
    imagecopyresampled($targetImage, $sourceImage, $left, $top, 0, 0, $renderWidth, $renderHeight, $sourceWidth, $sourceHeight);

    $extension = strtolower($extension) === 'png' ? 'png' : 'jpg';
    $targetPath = tempnam(sys_get_temp_dir(), 'mezo_plan_photo_') . '.' . $extension;

    if ($extension === 'png') {
        imagepng($targetImage, $targetPath, 6);
    } else {
        imagejpeg($targetImage, $targetPath, 84);
    }

    imagedestroy($sourceImage);
    imagedestroy($targetImage);

    return $targetPath;
}

function prepare_mvm_docx_plan_photo_jpeg(string $sourcePath, int $canvasWidth, int $canvasHeight): string
{
    return prepare_mvm_docx_plan_photo_image($sourcePath, $canvasWidth, $canvasHeight, 'jpg');
}

function mvm_docx_relationship_targets(string $relsXml): array
{
    preg_match_all('/<Relationship\s+[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/i', $relsXml, $matches, PREG_SET_ORDER);
    $targets = [];

    foreach ($matches as $match) {
        $targets[$match[1]] = $match[2];
    }

    return $targets;
}

function replace_mvm_docx_relationship_target(string $relsXml, string $relationshipId, string $target): string
{
    $pattern = '/<Relationship\s+[^>]*Id="' . preg_quote($relationshipId, '/') . '"[^>]*>/i';

    return (string) preg_replace_callback(
        $pattern,
        static fn (array $match): string => (string) preg_replace('/Target="[^"]*"/', 'Target="' . mvm_docx_xml_value($target) . '"', $match[0], 1),
        $relsXml,
        1
    );
}

function ensure_mvm_docx_jpeg_content_type(string $contentTypesXml): string
{
    if (str_contains($contentTypesXml, 'Extension="jpg"')) {
        return $contentTypesXml;
    }

    $default = '<Default Extension="jpg" ContentType="image/jpeg"/>';

    return (string) preg_replace('/(<Types\b[^>]*>)/', '$1' . $default, $contentTypesXml, 1);
}

function mvm_docx_photo_canvas_size_from_extent(int $extentCx, int $extentCy): array
{
    if ($extentCx <= 0 || $extentCy <= 0) {
        return [1300, 1800];
    }

    $ratio = $extentCx / $extentCy;
    $longSide = 1800;

    if ($ratio >= 1) {
        return [$longSide, max(500, (int) round($longSide / $ratio))];
    }

    return [max(500, (int) round($longSide * $ratio)), $longSide];
}

function mvm_execution_plan_photo_slots(string $documentXml): array
{
    $slots = [];

    foreach (mvm_plan_photo_placeholder_map() as $placeholder => $fileType) {
        $position = strpos($documentXml, 'descr="' . $placeholder . '"');

        if ($position === false) {
            continue;
        }

        $inlineStart = strrpos(substr($documentXml, 0, $position), '<wp:inline');
        $inlineEnd = strpos($documentXml, '</wp:inline>', $position);

        if ($inlineStart === false || $inlineEnd === false) {
            continue;
        }

        $inlineXml = substr($documentXml, $inlineStart, $inlineEnd - $inlineStart + strlen('</wp:inline>'));

        if (!preg_match('/r:embed="([^"]+)"/', $inlineXml, $relationshipMatch)) {
            continue;
        }

        $extentCx = 0;
        $extentCy = 0;

        if (preg_match('/<wp:extent\s+cx="([0-9]+)"\s+cy="([0-9]+)"/', $inlineXml, $extentMatch)) {
            $extentCx = (int) $extentMatch[1];
            $extentCy = (int) $extentMatch[2];
        }

        $slots[] = [
            'placeholder' => $placeholder,
            'file_type' => $fileType,
            'relationship_id' => $relationshipMatch[1],
            'extent_cx' => $extentCx,
            'extent_cy' => $extentCy,
        ];
    }

    return $slots;
}

function replace_mvm_execution_plan_photos(ZipArchive $zip, int $requestId): array
{
    $documentXml = $zip->getFromName('word/document.xml');
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');

    if ($documentXml === false || $relsXml === false) {
        return ['temporary_paths' => [], 'inserted' => 0, 'missing' => array_values(mvm_plan_photo_placeholder_map())];
    }

    $relationshipTargets = mvm_docx_relationship_targets($relsXml);
    $temporaryPaths = [];
    $missing = [];
    $insertedTypes = [];
    $inserted = 0;

    foreach (mvm_execution_plan_photo_slots($documentXml) as $slot) {
        $fileType = (string) $slot['file_type'];
        $file = first_connection_request_image_file($requestId, $fileType);

        if ($file === null) {
            $missing[] = $fileType;
            continue;
        }

        [$canvasWidth, $canvasHeight] = mvm_docx_photo_canvas_size_from_extent((int) $slot['extent_cx'], (int) $slot['extent_cy']);
        $relationshipTarget = (string) ($relationshipTargets[(string) $slot['relationship_id']] ?? '');

        if ($relationshipTarget === '' || str_contains($relationshipTarget, '..')) {
            $missing[] = $fileType;
            continue;
        }

        $targetExtension = strtolower(pathinfo($relationshipTarget, PATHINFO_EXTENSION));
        $targetExtension = in_array($targetExtension, ['png', 'jpg', 'jpeg'], true) ? $targetExtension : 'jpg';
        $photoPath = prepare_mvm_docx_plan_photo_image((string) $file['storage_path'], $canvasWidth, $canvasHeight, $targetExtension === 'png' ? 'png' : 'jpg');
        $temporaryPaths[] = $photoPath;

        $targetZipPath = 'word/' . ltrim($relationshipTarget, '/');

        if ($zip->locateName($targetZipPath) !== false) {
            $zip->deleteName($targetZipPath);
        }

        if (!$zip->addFile($photoPath, $targetZipPath)) {
            throw new RuntimeException('A tervdokumentáció fotóját nem sikerült beilleszteni: ' . (string) ($file['original_name'] ?? $fileType));
        }

        $inserted++;
        $insertedTypes[] = $fileType;
    }

    return [
        'temporary_paths' => $temporaryPaths,
        'inserted' => $inserted,
        'inserted_types' => $insertedTypes,
        'missing' => $missing,
        'relationship_targets' => $relationshipTargets,
    ];
}

function generate_primavill_mvm_docx(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'A Word dokumentum generálásához hiányzik a PHP ZIP bővítmény.', 'document_id' => null];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $form = connection_request_mvm_form($requestId);

    if ($form === null) {
        return ['ok' => false, 'message' => 'Előbb mentsd az MVM űrlap adatait.', 'document_id' => null];
    }

    if (empty($form['sketch_storage_path']) || !is_file((string) $form['sketch_storage_path'])) {
        return ['ok' => false, 'message' => 'A 3. oldali kockázott részhez tölts fel skicc képet.', 'document_id' => null];
    }

    $values = connection_request_mvm_form_values($request);
    $contractorKey = normalize_mvm_contractor_key($values['mvm_contractor'] ?? null);
    $contractor = mvm_contractor_definition($contractorKey);
    $templatePath = mvm_docx_template_path($contractorKey);

    if ($templatePath === null) {
        return ['ok' => false, 'message' => 'Hiányzik a(z) ' . $contractor['label'] . ' DOCX sablon a templates/mvm/contractor-templates mappából.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/generated-docx';
    ensure_storage_dir($targetDir);

    $storedName = $contractorKey . '-igenybejelento-' . $requestId . '-' . date('Ymd-His') . '.docx';
    $targetPath = $targetDir . '/' . $storedName;

    if (!copy($templatePath, $targetPath)) {
        return ['ok' => false, 'message' => 'A Word sablont nem sikerült előkészíteni.', 'document_id' => null];
    }

    $zip = new ZipArchive();

    if ($zip->open($targetPath) !== true) {
        return ['ok' => false, 'message' => 'A Word dokumentumot nem sikerült megnyitni szerkesztésre.', 'document_id' => null];
    }

    $temporaryImage = null;

    try {
        $placeholderMap = mvm_docx_placeholder_map($request, $values);
        $zipFileCount = $zip->numFiles;

        for ($index = 0; $index < $zipFileCount; $index++) {
            $name = $zip->getNameIndex($index);

            if (!is_string($name) || !preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }

            $xml = $zip->getFromName($name);

            if ($xml === false) {
                continue;
            }

            $xml = replace_docx_placeholders_in_xml($xml, $placeholderMap);
            $zip->addFromString($name, strtr($xml, array_map('mvm_docx_xml_value', $placeholderMap)));
        }

        $temporaryImage = prepare_mvm_docx_sketch_png((string) $form['sketch_storage_path']);
        $sketchImageReplaced = false;

        foreach (['word/media/image2.png', 'word/media/image20.png'] as $imagePath) {
            if ($zip->locateName($imagePath) !== false) {
                $zip->deleteName($imagePath);

                if (!$zip->addFile($temporaryImage, $imagePath)) {
                    throw new RuntimeException('A skicc képet nem sikerült beilleszteni a Word dokumentumba.');
                }

                $sketchImageReplaced = true;
            }
        }

        if (!$sketchImageReplaced) {
            throw new RuntimeException('A sablonban nem található a skicc kép helye.');
        }

        $zip->close();
    } catch (Throwable $exception) {
        $zip->close();

        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        if ($temporaryImage !== null && is_file($temporaryImage)) {
            unlink($temporaryImage);
        }

        return ['ok' => false, 'message' => 'A Word dokumentum generálása sikertelen: ' . $exception->getMessage(), 'document_id' => null];
    }

    if ($temporaryImage !== null && is_file($temporaryImage)) {
        unlink($temporaryImage);
    }

    clearstatcache(true, $targetPath);
    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'submitted_request',
            $contractor['short_label'] . ' MVM igénybejelentő - kitöltött Word dokumentum',
            $storedName,
            $storedName,
            $targetPath,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            is_file($targetPath) ? (int) filesize($targetPath) : 0,
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött ' . $contractor['short_label'] . ' Word dokumentum elkészült.',
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function validate_mvm_execution_plan_values(array $values): array
{
    $errors = [];
    $requiredFields = [
        'plan_csatlakozas_tipusa' => 'Csatlakozás típusa',
        'plan_csatlakozas_modja' => 'Csatlakozás módja',
        'plan_muszaki_leiras' => 'Műszaki leírás',
    ];

    foreach ($requiredFields as $key => $label) {
        if (trim((string) ($values[$key] ?? '')) === '') {
            $errors[] = 'A tervdokumentációhoz kötelező mező: ' . $label . '.';
        }
    }

    return $errors;
}

function mvm_execution_plan_literal_map(array $placeholderMap): array
{
    return [
        'A fent nevezett ingatlan fogyasztója keresett meg {d.Project.Mt} miatt. A csatlakozóvezeték nem volt megfelelő, cseréje miatt készítettük el a címbeli munka kivitelezési és engedélyezési tervdokumentációját, mely jelen dokumentáció tárgya.' => $placeholderMap['{d.Project.PlanMuszakiLeiras}'] ?? '',
        'A légvezetékes csatlakozás' => $placeholderMap['{d.Project.PlanCsatlakozasModja}'] ?? '',
        'Légkábeles csatlakozóvezeték csere' => $placeholderMap['{d.Project.PlanCsatlakozasModja}'] ?? '',
    ];
}

function generate_mvm_execution_plan_docx(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'A Word dokumentum generálásához hiányzik a PHP ZIP bővítmény.', 'document_id' => null];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $form = connection_request_mvm_form($requestId);

    if ($form === null) {
        return ['ok' => false, 'message' => 'Előbb mentsd az MVM űrlap adatait.', 'document_id' => null];
    }

    if (empty($form['sketch_storage_path']) || !is_file((string) $form['sketch_storage_path'])) {
        return ['ok' => false, 'message' => 'A tervdokumentációhoz tölts fel skicc képet az MVM űrlapon.', 'document_id' => null];
    }

    $values = connection_request_mvm_form_values($request);
    $validationErrors = validate_mvm_execution_plan_values($values);

    if ($validationErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $validationErrors), 'document_id' => null];
    }

    $contractorKey = normalize_mvm_contractor_key($values['mvm_contractor'] ?? null);
    $contractor = mvm_contractor_definition($contractorKey);
    $templatePath = mvm_plan_template_path($contractorKey);

    if ($templatePath === null) {
        return ['ok' => false, 'message' => 'Hiányzik a(z) ' . $contractor['label'] . ' terv DOCX sablon a templates/mvm/plan-templates mappából.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/generated-plan-docx';
    ensure_storage_dir($targetDir);

    $storedName = $contractorKey . '-tervdokumentacio-' . $requestId . '-' . date('Ymd-His') . '.docx';
    $targetPath = $targetDir . '/' . $storedName;

    if (!copy($templatePath, $targetPath)) {
        return ['ok' => false, 'message' => 'A terv Word sablont nem sikerült előkészíteni.', 'document_id' => null];
    }

    $zip = new ZipArchive();

    if ($zip->open($targetPath) !== true) {
        return ['ok' => false, 'message' => 'A terv Word dokumentumot nem sikerült megnyitni szerkesztésre.', 'document_id' => null];
    }

    $temporaryImages = [];
    $planPhotoResult = ['inserted' => 0, 'missing' => []];

    try {
        $placeholderMap = mvm_docx_placeholder_map($request, $values);
        $executionPlanLiteralMap = mvm_execution_plan_literal_map($placeholderMap);
        $planPhotoResult = replace_mvm_execution_plan_photos($zip, $requestId);
        $temporaryImages = array_merge($temporaryImages, $planPhotoResult['temporary_paths']);
        $zipFileCount = $zip->numFiles;

        for ($index = 0; $index < $zipFileCount; $index++) {
            $name = $zip->getNameIndex($index);

            if (!is_string($name) || !preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }

            $xml = $zip->getFromName($name);

            if ($xml === false) {
                continue;
            }

            $xml = replace_docx_literal_text_in_xml($xml, $executionPlanLiteralMap);
            $xml = replace_docx_placeholders_in_xml($xml, $placeholderMap);
            $zip->addFromString($name, strtr($xml, array_map('mvm_docx_xml_value', $placeholderMap)));
        }

        $temporaryImage = prepare_mvm_docx_sketch_png((string) $form['sketch_storage_path']);
        $temporaryImages[] = $temporaryImage;
        $sketchImagePath = 'word/media/image2.png';

        if ($zip->locateName($sketchImagePath) === false) {
            throw new RuntimeException('A tervsablonban nem található a skicc kép helye.');
        }

        $zip->deleteName($sketchImagePath);

        if (!$zip->addFile($temporaryImage, $sketchImagePath)) {
            throw new RuntimeException('A skicc képet nem sikerült beilleszteni a tervdokumentációba.');
        }

        $zip->close();
    } catch (Throwable $exception) {
        $zip->close();

        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        foreach ($temporaryImages as $temporaryImage) {
            if (is_file((string) $temporaryImage)) {
                unlink((string) $temporaryImage);
            }
        }

        return ['ok' => false, 'message' => 'A terv Word dokumentum generálása sikertelen: ' . $exception->getMessage(), 'document_id' => null];
    }

    foreach ($temporaryImages as $temporaryImage) {
        if (is_file((string) $temporaryImage)) {
            unlink((string) $temporaryImage);
        }
    }

    clearstatcache(true, $targetPath);
    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'execution_plan',
            $contractor['short_label'] . ' kiviteli tervdokumentáció - kitöltött Word dokumentum',
            $storedName,
            $storedName,
            $targetPath,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            is_file($targetPath) ? (int) filesize($targetPath) : 0,
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    $message = 'A kitöltött ' . $contractor['short_label'] . ' terv Word dokumentum elkészült.';

    $definitions = connection_request_upload_definitions();

    if (!empty($planPhotoResult['inserted_types'])) {
        $insertedLabels = [];

        foreach ((array) $planPhotoResult['inserted_types'] as $insertedType) {
            $insertedLabels[] = (string) ($definitions[(string) $insertedType]['label'] ?? $insertedType);
        }

        $message .= ' Beillesztett fotók: ' . implode(', ', array_unique($insertedLabels)) . '.';
    }

    if (!empty($planPhotoResult['missing'])) {
        $missingLabels = [];

        foreach ((array) $planPhotoResult['missing'] as $missingType) {
            $missingLabels[] = (string) ($definitions[(string) $missingType]['label'] ?? $missingType);
        }

        $message .= ' Hiányzó fotók: ' . implode(', ', array_unique($missingLabels)) . '.';
    }

    return [
        'ok' => true,
        'message' => $message,
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function convert_mvm_docx_document_to_pdf(array $document): array
{
    $sourcePath = (string) ($document['storage_path'] ?? '');

    if ($sourcePath === '' || !is_file($sourcePath)) {
        return ['ok' => false, 'message' => 'A kitöltött Word dokumentum fájlja nem található.', 'path' => null];
    }

    $outputDir = dirname($sourcePath);
    $expectedPath = $outputDir . '/' . pathinfo($sourcePath, PATHINFO_FILENAME) . '.pdf';
    $provider = defined('MVM_DOCX_CONVERTER') ? MVM_DOCX_CONVERTER : 'auto';
    $messages = [];

    if ($provider === '' || $provider === 'auto' || $provider === 'soffice' || $provider === 'libreoffice') {
        $sofficeResult = convert_mvm_docx_to_pdf_with_soffice($sourcePath, $expectedPath);

        if ($sofficeResult['ok']) {
            return $sofficeResult;
        }

        $messages[] = (string) $sofficeResult['message'];

        if ($provider === 'soffice' || $provider === 'libreoffice') {
            return [
                'ok' => false,
                'message' => 'A Word dokumentum elkészült, de a LibreOffice/soffice konvertálás nem sikerült. ' . implode(' ', $messages),
                'path' => null,
            ];
        }
    }

    if ($provider === 'auto' || $provider === 'convertapi') {
        $convertApiResult = convert_mvm_docx_to_pdf_with_convertapi($sourcePath, $expectedPath);

        if ($convertApiResult['ok']) {
            return $convertApiResult;
        }

        $messages[] = (string) $convertApiResult['message'];

        if ($provider === 'convertapi') {
            return [
                'ok' => false,
                'message' => 'A Word dokumentum elkészült, de a ConvertAPI konvertálás nem sikerült. ' . implode(' ', $messages),
                'path' => null,
            ];
        }
    }

    return [
        'ok' => false,
        'message' => 'A Word dokumentum elkészült, de PDF-be konvertálni nem sikerült. Beállítható megoldások: LibreOffice/soffice a tárhelyen, vagy MVM_DOCX_CONVERTER=convertapi és CONVERTAPI_SECRET a felhős konvertáláshoz. Részlet: ' . implode(' ', array_filter($messages)),
        'path' => null,
    ];
}

function convert_mvm_docx_to_pdf_with_soffice(string $sourcePath, string $expectedPath): array
{
    if (!function_exists('exec')) {
        return ['ok' => false, 'message' => 'Az exec futtatás nem engedélyezett, ezért a LibreOffice/soffice nem indítható.', 'path' => null];
    }

    $outputDir = dirname($sourcePath);
    $configuredBinary = getenv('LIBREOFFICE_BIN') ?: '';
    $candidates = array_values(array_filter([$configuredBinary, 'soffice', 'libreoffice']));
    $lastOutput = [];

    foreach ($candidates as $binary) {
        $command = escapeshellcmd($binary)
            . ' --headless --convert-to pdf --outdir '
            . escapeshellarg($outputDir)
            . ' '
            . escapeshellarg($sourcePath)
            . ' 2>&1';
        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);
        $lastOutput = $output;

        clearstatcache(true, $expectedPath);

        if ($exitCode === 0 && is_file($expectedPath)) {
            return ['ok' => true, 'message' => 'A PDF dokumentum elkészült LibreOffice/soffice konvertálással.', 'path' => $expectedPath];
        }
    }

    return [
        'ok' => false,
        'message' => 'LibreOffice/soffice nem érhető el vagy hibával futott. Részlet: ' . trim(implode(' ', $lastOutput)),
        'path' => null,
    ];
}

function convert_mvm_docx_to_pdf_with_convertapi(string $sourcePath, string $targetPath): array
{
    $secret = defined('CONVERTAPI_SECRET') ? CONVERTAPI_SECRET : '';

    if ($secret === '') {
        return ['ok' => false, 'message' => 'Nincs beállítva CONVERTAPI_SECRET, ezért a ConvertAPI konvertálás nem használható.', 'path' => null];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'A PHP cURL bővítmény nem elérhető, ezért a ConvertAPI konvertálás nem indítható.', 'path' => null];
    }

    $endpoint = defined('CONVERTAPI_ENDPOINT') ? CONVERTAPI_ENDPOINT : 'https://v2.convertapi.com';
    $url = $endpoint . '/convert/docx/to/pdf';
    $targetFile = fopen($targetPath, 'wb');

    if ($targetFile === false) {
        return ['ok' => false, 'message' => 'Nem sikerült létrehozni a PDF célfájlt.', 'path' => null];
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'File' => new CURLFile($sourcePath, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', basename($sourcePath)),
            'StoreFile' => 'false',
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secret,
        ],
        CURLOPT_FILE => $targetFile,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FAILONERROR => false,
    ]);

    $success = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    fclose($targetFile);
    clearstatcache(true, $targetPath);

    if (!$success || $statusCode < 200 || $statusCode >= 300 || !is_file($targetPath) || filesize($targetPath) === 0) {
        $responseBody = is_file($targetPath) ? (string) file_get_contents($targetPath) : '';

        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        return [
            'ok' => false,
            'message' => 'A ConvertAPI nem adott sikeres PDF választ. HTTP: ' . $statusCode . '. ' . ($curlError !== '' ? 'cURL: ' . $curlError . '. ' : '') . trim($responseBody),
            'path' => null,
        ];
    }

    $handle = fopen($targetPath, 'rb');
    $signature = $handle ? fread($handle, 4) : '';

    if (is_resource($handle)) {
        fclose($handle);
    }

    if ($signature !== '%PDF') {
        $responseBody = is_file($targetPath) ? (string) file_get_contents($targetPath) : '';
        $decoded = json_decode($responseBody, true);

        if (is_array($decoded) && isset($decoded['Files'][0]['FileData'])) {
            $pdfBytes = base64_decode((string) $decoded['Files'][0]['FileData'], true);

            if (is_string($pdfBytes) && str_starts_with($pdfBytes, '%PDF')) {
                file_put_contents($targetPath, $pdfBytes);

                return ['ok' => true, 'message' => 'A PDF dokumentum elkészült ConvertAPI konvertálással.', 'path' => $targetPath];
            }
        }

        if (is_array($decoded) && isset($decoded['Files'][0]['Url'])) {
            $fileUrl = (string) $decoded['Files'][0]['Url'];
            $downloadFile = fopen($targetPath, 'wb');

            if ($downloadFile !== false) {
                $download = curl_init($fileUrl);
                curl_setopt_array($download, [
                    CURLOPT_FILE => $downloadFile,
                    CURLOPT_TIMEOUT => 120,
                    CURLOPT_CONNECTTIMEOUT => 20,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_FAILONERROR => false,
                ]);

                $downloadSuccess = curl_exec($download);
                $downloadStatus = (int) curl_getinfo($download, CURLINFO_HTTP_CODE);
                curl_close($download);
                fclose($downloadFile);

                clearstatcache(true, $targetPath);
                $downloadHandle = fopen($targetPath, 'rb');
                $downloadSignature = $downloadHandle ? fread($downloadHandle, 4) : '';

                if (is_resource($downloadHandle)) {
                    fclose($downloadHandle);
                }

                if ($downloadSuccess && $downloadStatus >= 200 && $downloadStatus < 300 && $downloadSignature === '%PDF') {
                    return ['ok' => true, 'message' => 'A PDF dokumentum elkészült ConvertAPI konvertálással.', 'path' => $targetPath];
                }
            }
        }

        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        $responsePreview = substr($responseBody, 0, 300);

        return [
            'ok' => false,
            'message' => 'A ConvertAPI válasza nem PDF fájl. ' . trim($responsePreview),
            'path' => null,
        ];
    }

    return ['ok' => true, 'message' => 'A PDF dokumentum elkészült ConvertAPI konvertálással.', 'path' => $targetPath];
}

function mvm_pdf_font_path(): ?string
{
    $candidates = [
        APP_ROOT . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf',
        APP_ROOT . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSerif.ttf',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function mvm_pdf_text_image(string $text, float $widthMm, float $heightMm, int $fontSize = 8, bool $transparent = false): string
{
    if (!function_exists('imagettftext')) {
        throw new RuntimeException('A PDF sablon kitöltéséhez a PHP GD/FreeType támogatás szükséges.');
    }

    $fontPath = mvm_pdf_font_path();

    if ($fontPath === null) {
        throw new RuntimeException('Hiányzik a DejaVu betűkészlet a vendor/dompdf mappából.');
    }

    $dpi = $transparent ? 120 : 220;
    $width = max(20, (int) ceil($widthMm / 25.4 * $dpi));
    $height = max(14, (int) ceil($heightMm / 25.4 * $dpi));
    $image = imagecreatetruecolor($width, $height);
    $black = imagecolorallocate($image, 8, 18, 32);

    if ($transparent) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagealphablending($image, true);
    } else {
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    }

    $fontPixelSize = max(7, (int) round($fontSize * $dpi / 72));
    $lines = preg_split('/\R/u', trim($text)) ?: [''];
    $renderLines = [];

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ($line === '') {
            $renderLines[] = '';
            continue;
        }

        $words = preg_split('/\s+/u', $line) ?: [$line];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $box = imagettfbbox($fontPixelSize, 0, $fontPath, $candidate);
            $candidateWidth = abs((int) $box[2] - (int) $box[0]);

            if ($candidateWidth <= $width - 8 || $current === '') {
                $current = $candidate;
                continue;
            }

            $renderLines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $renderLines[] = $current;
        }
    }

    $lineHeight = (int) round($fontPixelSize * 1.22);
    $baseline = max($fontPixelSize + 2, (int) round($fontPixelSize * 1.05));

    foreach ($renderLines as $line) {
        if ($baseline > $height - 2) {
            break;
        }

        imagettftext($image, $fontPixelSize, 0, 4, $baseline, $black, $fontPath, $line);
        $baseline += $lineHeight;
    }

    $targetPath = tempnam(sys_get_temp_dir(), 'mezo_mvm_pdf_text_') . '.png';
    imagepng($image, $targetPath, 6);
    imagedestroy($image);

    return $targetPath;
}

function add_mvm_pdf_text(
    \setasign\Fpdi\Fpdi $pdf,
    string $text,
    float $x,
    float $y,
    float $w,
    float $h,
    int $fontSize,
    array &$temporaryFiles,
    bool $coverBackground = true
): void
{
    if ($coverBackground) {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, max(0, $y - 0.9), $w, $h + 1.8, 'F');
    }

    if (trim($text) === '') {
        return;
    }

    $imagePath = mvm_pdf_text_image($text, $w, $h, $fontSize);
    $temporaryFiles[] = $imagePath;
    $pdf->Image($imagePath, $x, $y, $w, $h, 'PNG');
}

function mvm_pdf_overlay_dimensions(string $key): array
{
    $checkboxes = [
        'uj_fogyaszto',
        'phase_upgrade',
        'egyedi_merohely_felulvizsgalat',
        'hmke_bekapcsolas',
        'h_tarifa_vagy_melleszereles',
        'csak_kismegszakitocsere',
        'kozvilagitas_merohely_letesites',
        'csatlakozo_berendezes_helyreallitasa',
        'foldkabeles',
        'legvezetekes',
        'lakossagi_fogyaszto',
        'nem_lakossagi_fogyaszto',
        'n13',
        'n216',
        'n416',
        'n225',
        'n425',
        'n425f',
        'n435f',
        'n450f',
        'n470f',
        'n495f',
        'sc',
        'tn',
        'ot',
        'ohfk',
        'otvez',
        'laed',
        'mgyszv',
        'mogysz',
    ];

    if (in_array($key, $checkboxes, true)) {
        return ['w' => 8.0, 'h' => 4.2, 'size' => 8];
    }

    $wide = [
        'felhasznalasi_cim' => 84.0,
        'ugyfel_lakcime' => 92.0,
        'jelenlegi_meroszekreny' => 58.0,
        'meroora_helye_jelenleg' => 42.0,
        'kockas_papir_vezetek' => 48.0,
        'kockas_papir_szekreny' => 48.0,
        'n7es_pont_nev' => 50.0,
        'n7es_pont_cim' => 52.0,
        'anyja_neve_cegjegyzekszam' => 56.0,
    ];

    $medium = [
        'nev' => 52.0,
        'rnev' => 52.0,
        'anyja_neve' => 48.0,
        'szuletesi_hely' => 34.0,
        'szuletesi_helye' => 34.0,
        'szuletesi_ido' => 30.0,
        'szuletesi_ideje' => 30.0,
        'adoszam2' => 32.0,
        'cegjegyzekszam' => 36.0,
        'telefon' => 34.0,
        'email' => 48.0,
        'varos' => 34.0,
        'varos2' => 34.0,
        'utca' => 42.0,
        'hazszam' => 24.0,
        'helyrajzi_szam' => 32.0,
        'mt' => 44.0,
        'szekreny_tipusa' => 42.0,
        'szekreny_brutto_egysegar' => 36.0,
        'szekreny_felulvizsgalati_dij' => 42.0,
        'foldkabel_tobletkoltseg' => 38.0,
        'fizetendo_teljesitmeny_ampere' => 24.0,
        'fizetendo_teljesitmeny_osszeg' => 36.0,
        'ingyenes_teljesitmeny_ampere' => 24.0,
        'legvezetekes_csatlakozo_koltseg' => 38.0,
        'legvezetekes_tobbletkoltseg' => 38.0,
        'muszaki_tobbletkoltseg' => 38.0,
        'ofo' => 38.0,
        'ofosz' => 60.0,
        'oszloptelepites_koltseg' => 38.0,
        'csatlakozo_berendezes_helyreallitas_koltseg' => 38.0,
        'oszlop_tipusa' => 36.0,
        'szfd' => 38.0,
        'tetotarto_hossz' => 24.0,
        'ossz_kabelhossz' => 24.0,
        'vizszintes_kabelhossz_m' => 24.0,
        'igenyelt_osszes_teljesitmeny' => 24.0,
        'osszes_igenyelt_h_teljesitmeny' => 24.0,
        'datum' => 28.0,
        'fha' => 32.0,
    ];

    if (array_key_exists($key, $wide)) {
        return ['w' => $wide[$key], 'h' => 4.6, 'size' => 7];
    }

    if (array_key_exists($key, $medium)) {
        return ['w' => $medium[$key], 'h' => 4.4, 'size' => 7];
    }

    return ['w' => 18.0, 'h' => 4.2, 'size' => 7];
}

function mvm_pdf_additional_overlay_positions(): array
{
    return [
        ['page' => 2, 'key' => 'nev', 'x' => 29.64, 'y' => 22.65],
        ['page' => 2, 'key' => 'datum', 'x' => 27.86, 'y' => 39.63],
        ['page' => 2, 'key' => 'ossz_kabelhossz', 'x' => 155.30, 'y' => 60.46],
        ['page' => 2, 'key' => 'vizszintes_kabelhossz_m', 'x' => 166.62, 'y' => 60.46],
        ['page' => 2, 'key' => 'jml1', 'x' => 64.23, 'y' => 80.27],
        ['page' => 2, 'key' => 'jml2', 'x' => 73.64, 'y' => 80.27],
        ['page' => 2, 'key' => 'jml3', 'x' => 82.14, 'y' => 80.27],
        ['page' => 2, 'key' => 'jvl1', 'x' => 134.39, 'y' => 80.27],
        ['page' => 2, 'key' => 'jvl2', 'x' => 143.75, 'y' => 80.27],
        ['page' => 2, 'key' => 'jvl3', 'x' => 152.93, 'y' => 80.27],
        ['page' => 2, 'key' => 'jelenlegi_hl1', 'x' => 52.50, 'y' => 88.41],
        ['page' => 2, 'key' => 'jelenlegi_hl2', 'x' => 63.08, 'y' => 88.41],
        ['page' => 2, 'key' => 'jelenlegi_hl3', 'x' => 73.64, 'y' => 88.41],
        ['page' => 2, 'key' => 'iml1', 'x' => 59.02, 'y' => 96.54],
        ['page' => 2, 'key' => 'iml2', 'x' => 79.01, 'y' => 96.54],
        ['page' => 2, 'key' => 'iml3', 'x' => 99.04, 'y' => 96.54],
        ['page' => 2, 'key' => 'ivl1', 'x' => 141.55, 'y' => 96.54],
        ['page' => 2, 'key' => 'ivl2', 'x' => 161.58, 'y' => 96.54],
        ['page' => 2, 'key' => 'ivl3', 'x' => 183.42, 'y' => 96.54],
        ['page' => 2, 'key' => 'ihl1', 'x' => 59.06, 'y' => 104.83],
        ['page' => 2, 'key' => 'ihl2', 'x' => 79.05, 'y' => 104.83],
        ['page' => 2, 'key' => 'ihl3', 'x' => 99.08, 'y' => 104.83],
        ['page' => 2, 'key' => 'igenyelt_osszes_teljesitmeny', 'x' => 46.91, 'y' => 118.59],
        ['page' => 2, 'key' => 'ingyenes_teljesitmeny_ampere', 'x' => 63.21, 'y' => 118.59],
        ['page' => 2, 'key' => 'fizetendo_teljesitmeny_ampere', 'x' => 81.51, 'y' => 118.59],
        ['page' => 2, 'key' => 'fizetendo_teljesitmeny_osszeg', 'x' => 116.15, 'y' => 118.59],
        ['page' => 2, 'key' => 'szekreny_brutto_egysegar', 'x' => 179.95, 'y' => 149.67],
        ['page' => 2, 'key' => 'szfd', 'x' => 38.15, 'y' => 160.64],
        ['page' => 2, 'key' => 'foldkabel_tobletkoltseg', 'x' => 139.13, 'y' => 164.32],
        ['page' => 2, 'key' => 'fizetendo_teljesitmeny_osszeg', 'x' => 12.70, 'y' => 178.88],
        ['page' => 2, 'key' => 'ofo', 'x' => 22.70, 'y' => 191.16],
        ['page' => 2, 'key' => 'ofosz', 'x' => 38.23, 'y' => 191.16],
        ['page' => 3, 'key' => 'oszlop_tipusa', 'x' => 73.64, 'y' => 53.56],
        ['page' => 3, 'key' => 'vizszintes_kabelhossz_m', 'x' => 159.04, 'y' => 88.20],
        ['page' => 3, 'key' => 'tetotarto_hossz', 'x' => 159.01, 'y' => 92.26],
        ['page' => 3, 'key' => 'ossz_kabelhossz', 'x' => 159.15, 'y' => 96.45],
        ['page' => 3, 'key' => 'kockas_papir_vezetek', 'x' => 121.94, 'y' => 108.73],
        ['page' => 3, 'key' => 'kockas_papir_szekreny', 'x' => 121.94, 'y' => 134.68],
        ['page' => 4, 'key' => 'datum', 'x' => 40.99, 'y' => 129.13],
        ['page' => 4, 'key' => 'laed', 'x' => 102.97, 'y' => 129.13],
        ['page' => 4, 'key' => 'nev', 'x' => 14.35, 'y' => 143.70],
        ['page' => 5, 'key' => 'nev', 'x' => 117.46, 'y' => 104.50],
        ['page' => 5, 'key' => 'iranyito_szam', 'x' => 90.10, 'y' => 110.42],
        ['page' => 5, 'key' => 'varos', 'x' => 142.60, 'y' => 110.42],
        ['page' => 5, 'key' => 'utca', 'x' => 78.38, 'y' => 116.56],
        ['page' => 5, 'key' => 'hazszam', 'x' => 108.00, 'y' => 116.56],
        ['page' => 5, 'key' => 'anyja_neve', 'x' => 78.38, 'y' => 122.74],
        ['page' => 5, 'key' => 'szuletesi_ido', 'x' => 142.60, 'y' => 122.74],
        ['page' => 5, 'key' => 'szuletesi_hely', 'x' => 78.38, 'y' => 128.16],
        ['page' => 5, 'key' => 'adoszam2', 'x' => 92.00, 'y' => 128.16],
        ['page' => 5, 'key' => 'nev', 'x' => 142.60, 'y' => 128.20],
        ['page' => 5, 'key' => 'telefon', 'x' => 78.38, 'y' => 134.34],
        ['page' => 5, 'key' => 'email', 'x' => 142.60, 'y' => 134.34],
        ['page' => 5, 'key' => 'ugyfel_lakcime', 'x' => 78.38, 'y' => 154.33],
        ['page' => 5, 'key' => 'rnev', 'x' => 125.88, 'y' => 172.07],
        ['page' => 5, 'key' => 'iranyitoszam2', 'x' => 90.10, 'y' => 178.25],
        ['page' => 5, 'key' => 'varos2', 'x' => 142.60, 'y' => 178.25],
        ['page' => 5, 'key' => 'ugyfel_lakcime', 'x' => 78.38, 'y' => 185.15],
        ['page' => 5, 'key' => 'anyja_neve_cegjegyzekszam', 'x' => 78.38, 'y' => 190.61],
        ['page' => 5, 'key' => 'szuletesi_ideje', 'x' => 142.60, 'y' => 190.61],
        ['page' => 5, 'key' => 'szuletesi_helye', 'x' => 78.38, 'y' => 196.04],
        ['page' => 5, 'key' => 'adoszam2', 'x' => 92.00, 'y' => 196.04],
        ['page' => 5, 'key' => 'rnev', 'x' => 142.60, 'y' => 196.08],
        ['page' => 6, 'key' => 'mt', 'x' => 113.35, 'y' => 103.40],
        ['page' => 6, 'key' => 'fha', 'x' => 110.17, 'y' => 124.01],
        ['page' => 6, 'key' => 'nev', 'x' => 113.39, 'y' => 130.74],
        ['page' => 6, 'key' => 'felhasznalasi_cim', 'x' => 113.39, 'y' => 138.29],
        ['page' => 6, 'key' => 'lakossagi_fogyaszto', 'x' => 113.39, 'y' => 152.51],
        ['page' => 6, 'key' => 'nem_lakossagi_fogyaszto', 'x' => 147.22, 'y' => 152.51],
        ['page' => 6, 'key' => 'mogysz', 'x' => 113.39, 'y' => 175.58],
        ['page' => 6, 'key' => 'mgyszv', 'x' => 113.39, 'y' => 183.67],
        ['page' => 7, 'key' => 'iml1', 'x' => 109.70, 'y' => 68.42],
        ['page' => 7, 'key' => 'iml2', 'x' => 128.17, 'y' => 68.42],
        ['page' => 7, 'key' => 'iml3', 'x' => 146.67, 'y' => 68.42],
        ['page' => 7, 'key' => 'ot', 'x' => 171.87, 'y' => 68.42],
        ['page' => 7, 'key' => 'ivl1', 'x' => 109.70, 'y' => 77.35],
        ['page' => 7, 'key' => 'ivl2', 'x' => 128.08, 'y' => 77.35],
        ['page' => 7, 'key' => 'ivl3', 'x' => 145.40, 'y' => 77.35],
        ['page' => 7, 'key' => 'otvez', 'x' => 168.61, 'y' => 77.35],
        ['page' => 7, 'key' => 'ihl1', 'x' => 109.70, 'y' => 86.29],
        ['page' => 7, 'key' => 'ihl2', 'x' => 128.08, 'y' => 86.29],
        ['page' => 7, 'key' => 'ihl3', 'x' => 145.40, 'y' => 86.29],
        ['page' => 7, 'key' => 'osszes_igenyelt_h_teljesitmeny', 'x' => 168.61, 'y' => 86.29],
        ['page' => 8, 'key' => 'varos', 'x' => 41.75, 'y' => 71.00],
        ['page' => 8, 'key' => 'datum', 'x' => 88.66, 'y' => 71.72],
        ['page' => 8, 'key' => 'nev', 'x' => 49.88, 'y' => 93.70],
        ['page' => 8, 'key' => 'n7es_pont_nev', 'x' => 136.51, 'y' => 138.58],
        ['page' => 8, 'key' => 'n7es_pont_cim', 'x' => 136.42, 'y' => 145.36],
        ['page' => 9, 'key' => 'nev', 'x' => 72.58, 'y' => 53.39],
        ['page' => 9, 'key' => 'felhasznalasi_cim', 'x' => 72.58, 'y' => 59.65],
        ['page' => 9, 'key' => 'nev', 'x' => 28.84, 'y' => 73.54],
        ['page' => 9, 'key' => 'anyja_neve', 'x' => 95.65, 'y' => 73.54],
        ['page' => 9, 'key' => 'szuletesi_hely', 'x' => 33.49, 'y' => 77.57],
        ['page' => 9, 'key' => 'szuletesi_ido', 'x' => 113.31, 'y' => 77.57],
        ['page' => 9, 'key' => 'iranyito_szam', 'x' => 36.67, 'y' => 99.67],
        ['page' => 9, 'key' => 'varos', 'x' => 91.33, 'y' => 99.67],
        ['page' => 9, 'key' => 'utca', 'x' => 10.54, 'y' => 105.55],
        ['page' => 9, 'key' => 'hazszam', 'x' => 58.09, 'y' => 105.55],
        ['page' => 9, 'key' => 'helyrajzi_szam', 'x' => 103.49, 'y' => 105.55],
        ['page' => 9, 'key' => 'varos', 'x' => 18.92, 'y' => 113.09],
        ['page' => 9, 'key' => 'datum', 'x' => 52.92, 'y' => 113.43],
        ['page' => 10, 'key' => 'felhasznalasi_cim', 'x' => 50.05, 'y' => 20.66],
        ['page' => 10, 'key' => 'fha', 'x' => 160.10, 'y' => 20.40],
        ['page' => 10, 'key' => 'jelenlegi_meroszekreny', 'x' => 52.00, 'y' => 50.72],
        ['page' => 10, 'key' => 'meroora_helye_jelenleg', 'x' => 92.35, 'y' => 51.06],
        ['page' => 10, 'key' => 'iml1', 'x' => 86.00, 'y' => 64.06],
        ['page' => 10, 'key' => 'ihl1', 'x' => 102.00, 'y' => 64.06],
        ['page' => 10, 'key' => 'ivl1', 'x' => 115.00, 'y' => 64.06],
        ['page' => 10, 'key' => 'varos', 'x' => 11.26, 'y' => 85.49],
        ['page' => 10, 'key' => 'datum', 'x' => 42.30, 'y' => 85.49],
        ['page' => 10, 'key' => 'iml1', 'x' => 86.00, 'y' => 141.84],
        ['page' => 10, 'key' => 'ihl1', 'x' => 102.00, 'y' => 141.84],
        ['page' => 10, 'key' => 'ivl1', 'x' => 115.00, 'y' => 141.84],
        ['page' => 10, 'key' => 'varos', 'x' => 11.26, 'y' => 163.26],
        ['page' => 10, 'key' => 'datum', 'x' => 42.30, 'y' => 163.26],
        ['page' => 10, 'key' => 'iml1', 'x' => 86.00, 'y' => 199.38],
        ['page' => 10, 'key' => 'ihl1', 'x' => 102.00, 'y' => 199.38],
        ['page' => 10, 'key' => 'ivl1', 'x' => 115.00, 'y' => 199.38],
        ['page' => 10, 'key' => 'varos', 'x' => 12.70, 'y' => 225.12],
        ['page' => 10, 'key' => 'datum', 'x' => 42.30, 'y' => 225.12],
        ['page' => 11, 'key' => 'varos', 'x' => 11.26, 'y' => 60.50],
        ['page' => 11, 'key' => 'datum', 'x' => 40.52, 'y' => 60.50],
    ];
}

function mvm_pdf_overlay_fields(array $values): array
{
    $field = static fn (string $key): string => (string) ($values[$key] ?? '');

    $fields = [
        ['page' => 1, 'x' => 42.4, 'y' => 30.9, 'w' => 84, 'h' => 4.3, 'size' => 7, 'text' => $field('felhasznalasi_cim')],
        ['page' => 1, 'x' => 174.6, 'y' => 30.9, 'w' => 21, 'h' => 4.3, 'size' => 7, 'text' => $field('iranyitoszam2')],
        ['page' => 1, 'x' => 34.7, 'y' => 35.7, 'w' => 90, 'h' => 4.3, 'size' => 7, 'text' => $field('nev')],
        ['page' => 1, 'x' => 161.6, 'y' => 35.7, 'w' => 32, 'h' => 4.3, 'size' => 7, 'text' => $field('fha')],
        ['page' => 1, 'x' => 51.5, 'y' => 39.7, 'w' => 24, 'h' => 4.3, 'size' => 7, 'text' => $field('szuletesi_ido')],
        ['page' => 1, 'x' => 72, 'y' => 39.7, 'w' => 55, 'h' => 4.3, 'size' => 7, 'text' => $field('adoszam2')],
        ['page' => 1, 'x' => 149.8, 'y' => 40.4, 'w' => 44, 'h' => 4.3, 'size' => 7, 'text' => $field('szuletesi_hely')],
        ['page' => 1, 'x' => 58.5, 'y' => 44.4, 'w' => 76, 'h' => 4.3, 'size' => 7, 'text' => $field('anyja_neve')],
        ['page' => 1, 'x' => 102, 'y' => 44.4, 'w' => 37, 'h' => 4.3, 'size' => 7, 'text' => $field('cegjegyzekszam')],
        ['page' => 1, 'x' => 39.5, 'y' => 49.8, 'w' => 115, 'h' => 4.3, 'size' => 7, 'text' => $field('ugyfel_lakcime')],
        ['page' => 1, 'x' => 174.6, 'y' => 49.8, 'w' => 21, 'h' => 4.3, 'size' => 7, 'text' => $field('iranyito_szam')],
        ['page' => 1, 'x' => 41.5, 'y' => 54.5, 'w' => 52, 'h' => 4.3, 'size' => 7, 'text' => $field('telefon')],
        ['page' => 1, 'x' => 112.8, 'y' => 69.4, 'w' => 45, 'h' => 4.2, 'size' => 7, 'text' => $field('mt')],
        ['page' => 1, 'x' => 77.4, 'y' => 76.0, 'w' => 13, 'h' => 4.2, 'size' => 8, 'text' => $field('uj_fogyaszto')],
        ['page' => 1, 'x' => 136.2, 'y' => 73.5, 'w' => 20, 'h' => 4.2, 'size' => 8, 'text' => $field('tn')],
        ['page' => 1, 'x' => 178.8, 'y' => 73.3, 'w' => 17, 'h' => 4.2, 'size' => 8, 'text' => $field('sc')],
        ['page' => 1, 'x' => 77.4, 'y' => 81.8, 'w' => 13, 'h' => 4.2, 'size' => 8, 'text' => $field('phase_upgrade')],
        ['page' => 1, 'x' => 178.8, 'y' => 81.6, 'w' => 17, 'h' => 4.2, 'size' => 8, 'text' => $field('egyedi_merohely_felulvizsgalat')],
        ['page' => 1, 'x' => 77.4, 'y' => 86.0, 'w' => 13, 'h' => 4.2, 'size' => 8, 'text' => $field('hmke_bekapcsolas')],
        ['page' => 1, 'x' => 178.8, 'y' => 85.8, 'w' => 17, 'h' => 4.2, 'size' => 8, 'text' => $field('h_tarifa_vagy_melleszereles')],
        ['page' => 1, 'x' => 77.4, 'y' => 90.2, 'w' => 13, 'h' => 4.2, 'size' => 8, 'text' => $field('csak_kismegszakitocsere')],
        ['page' => 1, 'x' => 178.8, 'y' => 90.1, 'w' => 17, 'h' => 4.2, 'size' => 8, 'text' => $field('kozvilagitas_merohely_letesites')],
        ['page' => 1, 'x' => 178.8, 'y' => 94.3, 'w' => 17, 'h' => 4.2, 'size' => 8, 'text' => $field('csatlakozo_berendezes_helyreallitasa')],
        ['page' => 1, 'x' => 42, 'y' => 123.3, 'w' => 42, 'h' => 4.2, 'size' => 8, 'text' => $field('foldkabeles')],
        ['page' => 1, 'x' => 133.2, 'y' => 123.3, 'w' => 45, 'h' => 4.2, 'size' => 8, 'text' => $field('legvezetekes')],
        ['page' => 1, 'x' => 37.5, 'y' => 166.4, 'w' => 43, 'h' => 4.2, 'size' => 7, 'text' => $field('szekreny_tipusa')],
        ['page' => 1, 'x' => 122.5, 'y' => 166.4, 'w' => 56, 'h' => 4.2, 'size' => 7, 'text' => $field('szekreny_brutto_egysegar')],
        ['page' => 1, 'x' => 32.5, 'y' => 186.4, 'w' => 52, 'h' => 4.2, 'size' => 7, 'text' => $field('jelenlegi_meroszekreny')],
        ['page' => 1, 'x' => 114.5, 'y' => 186.4, 'w' => 72, 'h' => 4.2, 'size' => 7, 'text' => $field('szekreny_felulvizsgalati_dij')],
        ['page' => 1, 'x' => 20.2, 'y' => 199.2, 'w' => 59, 'h' => 4.2, 'size' => 7, 'text' => $field('legvezetekes_tobbletkoltseg')],
        ['page' => 1, 'x' => 116, 'y' => 199.2, 'w' => 52, 'h' => 4.2, 'size' => 7, 'text' => $field('muszaki_tobbletkoltseg')],
        ['page' => 1, 'x' => 30.4, 'y' => 234.1, 'w' => 14, 'h' => 4.2, 'size' => 8, 'text' => $field('n425f')],
        ['page' => 1, 'x' => 51.9, 'y' => 236.1, 'w' => 14, 'h' => 4.2, 'size' => 8, 'text' => $field('n435f')],
        ['page' => 1, 'x' => 74.5, 'y' => 234.1, 'w' => 14, 'h' => 4.2, 'size' => 8, 'text' => $field('n450f')],
        ['page' => 1, 'x' => 120.1, 'y' => 234.1, 'w' => 14, 'h' => 4.2, 'size' => 8, 'text' => $field('n216')],
        ['page' => 1, 'x' => 140.9, 'y' => 234.1, 'w' => 14, 'h' => 4.2, 'size' => 8, 'text' => $field('n416')],
        ['page' => 1, 'x' => 165.3, 'y' => 234.1, 'w' => 14, 'h' => 4.2, 'size' => 8, 'text' => $field('n225')],
        ['page' => 1, 'x' => 187.9, 'y' => 236.1, 'w' => 14, 'h' => 4.2, 'size' => 8, 'text' => $field('n425')],
    ];

    foreach (mvm_pdf_additional_overlay_positions() as $position) {
        $key = (string) $position['key'];
        $dimensions = mvm_pdf_overlay_dimensions($key);

        $fields[] = [
            'page' => (int) $position['page'],
            'x' => (float) $position['x'],
            'y' => (float) $position['y'],
            'w' => (float) ($position['w'] ?? $dimensions['w']),
            'h' => (float) ($position['h'] ?? $dimensions['h']),
            'size' => (int) ($position['size'] ?? $dimensions['size']),
            'text' => $field($key),
        ];
    }

    return $fields;
}

function mvm_pdf_value_map(array $request, array $values): array
{
    $birthName = trim((string) ($request['birth_name'] ?? ''));

    if ($birthName === '') {
        $birthName = (string) ($request['requester_name'] ?? '');
    }

    $motherName = (string) ($request['mother_name'] ?? '');
    $companyNumber = (string) ($values['cegjegyzekszam'] ?? '');
    $birthDate = format_mvm_docx_date((string) ($request['birth_date'] ?? ''));
    $birthDateParts = connection_request_mvm_source_birth_date_parts((string) ($request['birth_date'] ?? ''));
    $birthPlace = (string) ($request['birth_place'] ?? '');

    return array_merge($values, [
        'nev' => (string) ($request['requester_name'] ?? ''),
        'rnev' => $birthName,
        'email' => (string) ($request['email'] ?? ''),
        'telefon' => (string) ($request['phone'] ?? ''),
        'ugyfel_lakcime' => trim((string) ($request['postal_code'] ?? '') . ' ' . (string) ($request['city'] ?? '') . ' ' . (string) ($request['postal_address'] ?? '')),
        'anyja_neve' => $motherName,
        'anyja_neve_cegjegyzekszam' => $companyNumber !== '' ? $companyNumber : $motherName,
        'szuletesi_hely' => $birthPlace,
        'szuletesi_helye' => $birthPlace,
        'szuletesi_ido' => $birthDate,
        'szuletesi_ideje' => $birthDate,
        'szuletesi_datum' => $birthDate,
        'szuletesi_ev' => $birthDateParts['year'],
        'szuletesi_honap' => $birthDateParts['month'],
        'szuletesi_ho' => $birthDateParts['month'],
        'szuletesi_nap' => $birthDateParts['day'],
        'helyrajzi_szam' => (string) ($request['hrsz'] ?? ''),
        'phase_upgrade' => ($request['request_type'] ?? '') === 'phase_upgrade' ? 'X' : '',
    ]);
}

function add_mvm_pdf_sketch(\setasign\Fpdi\Fpdi $pdf, array $form, int $page, array &$temporaryFiles): void
{
    if ($page !== 3 || empty($form['sketch_storage_path']) || !is_file((string) $form['sketch_storage_path'])) {
        return;
    }

    $preparedImage = prepare_mvm_docx_sketch_png((string) $form['sketch_storage_path']);
    $temporaryFiles[] = $preparedImage;
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(60, 88, 88, 78, 'F');
    $pdf->Image($preparedImage, 60, 88, 88, 78, 'PNG');
}

function generate_primavill_mvm_pdf_from_template(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    if (!class_exists('\\setasign\\Fpdi\\Fpdi')) {
        return ['ok' => false, 'message' => 'A PDF sablon kitöltéséhez hiányzik az FPDI csomag.', 'document_id' => null];
    }

    $templatePath = mvm_pdf_template_path();

    if ($templatePath === null) {
        return ['ok' => false, 'message' => 'Hiányzik a Primavill PDF sablon a templates/mvm mappából.', 'document_id' => null];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $form = connection_request_mvm_form($requestId);

    if ($form === null) {
        return ['ok' => false, 'message' => 'Előbb mentsd az MVM űrlap adatait.', 'document_id' => null];
    }

    $values = mvm_pdf_value_map($request, connection_request_mvm_form_values($request));
    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/generated-pdf';
    ensure_storage_dir($targetDir);
    $storedName = 'primavill-igenybejelento-' . $requestId . '-' . date('Ymd-His') . '.pdf';
    $targetPath = $targetDir . '/' . $storedName;
    $temporaryFiles = [];
    $coverPdfTemplateFields = mvm_pdf_template_requires_cover($templatePath);

    try {
        $pdf = new \setasign\Fpdi\Fpdi();
        $pageCount = $pdf->setSourceFile($templatePath);
        $fields = mvm_pdf_overlay_fields($values);

        for ($page = 1; $page <= $pageCount; $page++) {
            $templateId = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($templateId);
            $orientation = ((float) $size['width'] > (float) $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, [(float) $size['width'], (float) $size['height']]);
            $pdf->useTemplate($templateId);

            foreach ($fields as $field) {
                if ((int) $field['page'] !== $page) {
                    continue;
                }

                add_mvm_pdf_text(
                    $pdf,
                    (string) $field['text'],
                    (float) $field['x'],
                    (float) $field['y'],
                    (float) $field['w'],
                    (float) $field['h'],
                    (int) $field['size'],
                    $temporaryFiles,
                    $coverPdfTemplateFields || !empty($field['cover'])
                );
            }

            add_mvm_pdf_sketch($pdf, $form, $page, $temporaryFiles);
        }

        $pdf->Output('F', $targetPath);
    } catch (Throwable $exception) {
        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        foreach ($temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        return ['ok' => false, 'message' => 'A PDF sablon kitöltése sikertelen: ' . $exception->getMessage(), 'document_id' => null];
    }

    foreach ($temporaryFiles as $temporaryFile) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }

    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'submitted_request',
            'Primavill MVM igénybejelentő - kitöltött PDF',
            $storedName,
            $storedName,
            $targetPath,
            'application/pdf',
            is_file($targetPath) ? (int) filesize($targetPath) : 0,
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött Primavill PDF dokumentum elkészült.',
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function generate_primavill_mvm_pdf(int $requestId): array
{
    $docxResult = generate_primavill_mvm_docx($requestId);

    if (!$docxResult['ok']) {
        return $docxResult;
    }

    $document = find_connection_request_document((int) $docxResult['document_id']);

    if ($document === null) {
        return ['ok' => false, 'message' => 'A Word dokumentum elkészült, de a mentett dokumentumrekord nem található.', 'document_id' => null];
    }

    $pdfResult = convert_mvm_docx_document_to_pdf($document);

    if (!$pdfResult['ok'] || empty($pdfResult['path']) || !is_file((string) $pdfResult['path'])) {
        return ['ok' => false, 'message' => $pdfResult['message'], 'document_id' => (int) $document['id']];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $values = connection_request_mvm_form_values($request);
    $contractor = mvm_contractor_definition($values['mvm_contractor'] ?? null);
    $pdfPath = (string) $pdfResult['path'];
    $storedName = basename($pdfPath);
    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'submitted_request',
            $contractor['short_label'] . ' MVM igénybejelentő - kitöltött PDF',
            $storedName,
            $storedName,
            $pdfPath,
            'application/pdf',
            (int) filesize($pdfPath),
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött ' . $contractor['short_label'] . ' PDF dokumentum elkészült.',
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function generate_mvm_execution_plan_pdf(int $requestId): array
{
    $docxResult = generate_mvm_execution_plan_docx($requestId);

    if (!$docxResult['ok']) {
        return $docxResult;
    }

    $document = find_connection_request_document((int) $docxResult['document_id']);

    if ($document === null) {
        return ['ok' => false, 'message' => 'A terv Word dokumentum elkészült, de a mentett dokumentumrekord nem található.', 'document_id' => null];
    }

    $pdfResult = convert_mvm_docx_document_to_pdf($document);

    if (!$pdfResult['ok'] || empty($pdfResult['path']) || !is_file((string) $pdfResult['path'])) {
        return ['ok' => false, 'message' => $pdfResult['message'], 'document_id' => (int) $document['id']];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $values = connection_request_mvm_form_values($request);
    $contractor = mvm_contractor_definition($values['mvm_contractor'] ?? null);
    $pdfPath = (string) $pdfResult['path'];
    $storedName = basename($pdfPath);

    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'execution_plan',
            $contractor['short_label'] . ' kiviteli tervdokumentáció - kitöltött PDF',
            $storedName,
            $storedName,
            $pdfPath,
            'application/pdf',
            (int) filesize($pdfPath),
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött ' . $contractor['short_label'] . ' terv PDF dokumentum elkészült. ' . (string) ($docxResult['message'] ?? ''),
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function generate_mvm_technical_handover_docx(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'A Word dokumentum generálásához hiányzik a PHP ZIP bővítmény.', 'document_id' => null];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $form = connection_request_mvm_form($requestId);

    if ($form === null) {
        return ['ok' => false, 'message' => 'Előbb mentsd az MVM űrlap adatait.', 'document_id' => null];
    }

    $values = connection_request_mvm_form_values($request);
    $contractorKey = normalize_mvm_contractor_key($values['mvm_contractor'] ?? null);
    $contractor = mvm_contractor_definition($contractorKey);
    $templatePath = mvm_technical_handover_template_path($contractorKey);

    if ($templatePath === null) {
        return ['ok' => false, 'message' => 'Hiányzik a(z) ' . $contractor['label'] . ' műszaki átadás DOCX sablon a templates/mvm/handover-templates mappából.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/generated-technical-handover-docx';
    ensure_storage_dir($targetDir);

    $storedName = $contractorKey . '-muszaki-atadas-' . $requestId . '-' . date('Ymd-His') . '.docx';
    $targetPath = $targetDir . '/' . $storedName;

    if (!copy($templatePath, $targetPath)) {
        return ['ok' => false, 'message' => 'A műszaki átadás Word sablont nem sikerült előkészíteni.', 'document_id' => null];
    }

    $zip = new ZipArchive();

    if ($zip->open($targetPath) !== true) {
        return ['ok' => false, 'message' => 'A műszaki átadás Word dokumentumot nem sikerült megnyitni szerkesztésre.', 'document_id' => null];
    }

    try {
        $placeholderMap = mvm_docx_placeholder_map($request, $values);
        $zipFileCount = $zip->numFiles;

        for ($index = 0; $index < $zipFileCount; $index++) {
            $name = $zip->getNameIndex($index);

            if (!is_string($name) || !preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }

            $xml = $zip->getFromName($name);

            if ($xml === false) {
                continue;
            }

            $xml = replace_docx_placeholders_in_xml($xml, $placeholderMap);
            $zip->addFromString($name, strtr($xml, array_map('mvm_docx_xml_value', $placeholderMap)));
        }

        $zip->close();
    } catch (Throwable $exception) {
        $zip->close();

        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        return ['ok' => false, 'message' => 'A műszaki átadás Word dokumentum generálása sikertelen: ' . $exception->getMessage(), 'document_id' => null];
    }

    clearstatcache(true, $targetPath);
    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'technical_handover',
            $contractor['short_label'] . ' műszaki átadás-átvételi jegyzőkönyv - kitöltött Word dokumentum',
            $storedName,
            $storedName,
            $targetPath,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            is_file($targetPath) ? (int) filesize($targetPath) : 0,
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött ' . $contractor['short_label'] . ' műszaki átadás Word dokumentum elkészült.',
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function generate_mvm_technical_handover_pdf(int $requestId): array
{
    $docxResult = generate_mvm_technical_handover_docx($requestId);

    if (!$docxResult['ok']) {
        return $docxResult;
    }

    $document = find_connection_request_document((int) $docxResult['document_id']);

    if ($document === null) {
        return ['ok' => false, 'message' => 'A műszaki átadás Word dokumentum elkészült, de a mentett dokumentumrekord nem található.', 'document_id' => null];
    }

    $pdfResult = convert_mvm_docx_document_to_pdf($document);

    if (!$pdfResult['ok'] || empty($pdfResult['path']) || !is_file((string) $pdfResult['path'])) {
        return ['ok' => false, 'message' => $pdfResult['message'], 'document_id' => (int) $document['id']];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $values = connection_request_mvm_form_values($request);
    $contractor = mvm_contractor_definition($values['mvm_contractor'] ?? null);
    $pdfPath = (string) $pdfResult['path'];
    $storedName = basename($pdfPath);

    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'technical_handover',
            $contractor['short_label'] . ' műszaki átadás-átvételi jegyzőkönyv - kitöltött PDF',
            $storedName,
            $storedName,
            $pdfPath,
            'application/pdf',
            (int) filesize($pdfPath),
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött ' . $contractor['short_label'] . ' műszaki átadás PDF dokumentum elkészült.',
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function generate_mvm_seal_removal_docx(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'A Word dokumentum generálásához hiányzik a PHP ZIP bővítmény.', 'document_id' => null];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $form = connection_request_mvm_form($requestId);

    if ($form === null) {
        return ['ok' => false, 'message' => 'Előbb mentsd az MVM űrlap adatait.', 'document_id' => null];
    }

    $values = connection_request_mvm_form_values($request);
    $contractorKey = normalize_mvm_contractor_key($values['mvm_contractor'] ?? null);
    $contractor = mvm_contractor_definition($contractorKey);
    $templatePath = mvm_seal_removal_template_path($contractorKey);

    if ($templatePath === null) {
        return ['ok' => false, 'message' => 'Hiányzik a(z) ' . $contractor['label'] . ' plombabontási DOCX sablon a templates/mvm/seal-removal-templates mappából.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/generated-seal-removal-docx';
    ensure_storage_dir($targetDir);

    $storedName = $contractorKey . '-plombabontasi-engedely-' . $requestId . '-' . date('Ymd-His') . '.docx';
    $targetPath = $targetDir . '/' . $storedName;

    if (!copy($templatePath, $targetPath)) {
        return ['ok' => false, 'message' => 'A plombabontási Word sablont nem sikerült előkészíteni.', 'document_id' => null];
    }

    $zip = new ZipArchive();

    if ($zip->open($targetPath) !== true) {
        return ['ok' => false, 'message' => 'A plombabontási Word dokumentumot nem sikerült megnyitni szerkesztésre.', 'document_id' => null];
    }

    try {
        $placeholderMap = mvm_docx_placeholder_map($request, $values);
        $zipFileCount = $zip->numFiles;

        for ($index = 0; $index < $zipFileCount; $index++) {
            $name = $zip->getNameIndex($index);

            if (!is_string($name) || !preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }

            $xml = $zip->getFromName($name);

            if ($xml === false) {
                continue;
            }

            $xml = replace_docx_placeholders_in_xml($xml, $placeholderMap);
            $zip->addFromString($name, strtr($xml, array_map('mvm_docx_xml_value', $placeholderMap)));
        }

        $zip->close();
    } catch (Throwable $exception) {
        $zip->close();

        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        return ['ok' => false, 'message' => 'A plombabontási Word dokumentum generálása sikertelen: ' . $exception->getMessage(), 'document_id' => null];
    }

    clearstatcache(true, $targetPath);
    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'seal_removal',
            $contractor['short_label'] . ' plombabontási engedély - kitöltött Word dokumentum',
            $storedName,
            $storedName,
            $targetPath,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            is_file($targetPath) ? (int) filesize($targetPath) : 0,
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött ' . $contractor['short_label'] . ' plombabontási Word dokumentum elkészült.',
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function generate_mvm_seal_removal_pdf(int $requestId): array
{
    $docxResult = generate_mvm_seal_removal_docx($requestId);

    if (!$docxResult['ok']) {
        return $docxResult;
    }

    $document = find_connection_request_document((int) $docxResult['document_id']);

    if ($document === null) {
        return ['ok' => false, 'message' => 'A plombabontási Word dokumentum elkészült, de a mentett dokumentumrekord nem található.', 'document_id' => null];
    }

    $pdfResult = convert_mvm_docx_document_to_pdf($document);

    if (!$pdfResult['ok'] || empty($pdfResult['path']) || !is_file((string) $pdfResult['path'])) {
        return ['ok' => false, 'message' => $pdfResult['message'], 'document_id' => (int) $document['id']];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $values = connection_request_mvm_form_values($request);
    $contractor = mvm_contractor_definition($values['mvm_contractor'] ?? null);
    $pdfPath = (string) $pdfResult['path'];
    $storedName = basename($pdfPath);

    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'seal_removal',
            $contractor['short_label'] . ' plombabontási engedély - kitöltött PDF',
            $storedName,
            $storedName,
            $pdfPath,
            'application/pdf',
            (int) filesize($pdfPath),
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött ' . $contractor['short_label'] . ' plombabontási PDF dokumentum elkészült.',
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function generate_mvm_h_tariff_docx(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'A Word dokumentum generálásához hiányzik a PHP ZIP bővítmény.', 'document_id' => null];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $form = connection_request_mvm_form($requestId);

    if ($form === null) {
        return ['ok' => false, 'message' => 'Előbb mentsd az MVM űrlap adatait.', 'document_id' => null];
    }

    $values = connection_request_mvm_form_values($request);

    if (!mvm_h_tariff_form_values_are_filled($values)) {
        return ['ok' => false, 'message' => 'A H tarifa nyilatkozathoz tölts ki legalább egy H tarifa mezőt.', 'document_id' => null];
    }

    $templatePath = mvm_h_tariff_template_path();

    if ($templatePath === null) {
        return ['ok' => false, 'message' => 'Hiányzik a H tarifa nyilatkozat DOCX sablon a templates/mvm/h-tariff-templates mappából.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/generated-h-tariff-docx';
    ensure_storage_dir($targetDir);

    $storedName = 'h-tarifa-nyilatkozat-' . $requestId . '-' . date('Ymd-His') . '.docx';
    $targetPath = $targetDir . '/' . $storedName;

    if (!copy($templatePath, $targetPath)) {
        return ['ok' => false, 'message' => 'A H tarifa Word sablont nem sikerült előkészíteni.', 'document_id' => null];
    }

    $zip = new ZipArchive();

    if ($zip->open($targetPath) !== true) {
        return ['ok' => false, 'message' => 'A H tarifa Word dokumentumot nem sikerült megnyitni szerkesztésre.', 'document_id' => null];
    }

    try {
        $placeholderMap = mvm_docx_placeholder_map($request, $values);
        $selectedSystemLabel = mvm_h_tariff_operating_system_label($values['h_tarifa_mukodesi_rendszer'] ?? '');
        $zipFileCount = $zip->numFiles;

        for ($index = 0; $index < $zipFileCount; $index++) {
            $name = $zip->getNameIndex($index);

            if (!is_string($name) || !preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }

            $xml = $zip->getFromName($name);

            if ($xml === false) {
                continue;
            }

            $xml = replace_docx_placeholders_in_xml_dom($xml, $placeholderMap);
            $xml = strtr($xml, array_map('mvm_docx_xml_value', $placeholderMap));
            $xml = mvm_docx_underline_text_in_xml($xml, $selectedSystemLabel);
            $zip->addFromString($name, $xml);
        }

        $zip->close();
    } catch (Throwable $exception) {
        $zip->close();

        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        return ['ok' => false, 'message' => 'A H tarifa Word dokumentum generálása sikertelen: ' . $exception->getMessage(), 'document_id' => null];
    }

    clearstatcache(true, $targetPath);
    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'h_tariff_declaration',
            'H tarifa nyilatkozat - kitöltött Word dokumentum',
            $storedName,
            $storedName,
            $targetPath,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            is_file($targetPath) ? (int) filesize($targetPath) : 0,
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött H tarifa Word dokumentum elkészült.',
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function generate_mvm_h_tariff_pdf(int $requestId): array
{
    $docxResult = generate_mvm_h_tariff_docx($requestId);

    if (!$docxResult['ok']) {
        return $docxResult;
    }

    $document = find_connection_request_document((int) $docxResult['document_id']);

    if ($document === null) {
        return ['ok' => false, 'message' => 'A H tarifa Word dokumentum elkészült, de a mentett dokumentumrekord nem található.', 'document_id' => null];
    }

    $pdfResult = convert_mvm_docx_document_to_pdf($document);

    if (!$pdfResult['ok'] || empty($pdfResult['path']) || !is_file((string) $pdfResult['path'])) {
        return ['ok' => false, 'message' => $pdfResult['message'], 'document_id' => (int) $document['id']];
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $pdfPath = (string) $pdfResult['path'];
    $storedName = basename($pdfPath);

    db_query(
        'INSERT INTO `connection_request_documents`
            (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
             `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $requestId,
            (int) $request['customer_id'],
            'h_tariff_declaration',
            'H tarifa nyilatkozat - kitöltött PDF',
            $storedName,
            $storedName,
            $pdfPath,
            'application/pdf',
            (int) filesize($pdfPath),
            is_array(current_user()) ? (int) current_user()['id'] : null,
        ]
    );

    return [
        'ok' => true,
        'message' => 'A kitöltött H tarifa PDF dokumentum elkészült.',
        'document_id' => (int) db()->lastInsertId(),
    ];
}

function validate_connection_request_document_upload(string $documentType, array $files): array
{
    $errors = [];

    if (!isset(mvm_document_types()[$documentType])) {
        $errors[] = 'Érvénytelen MVM dokumentumtípus.';
    }

    $uploadedFiles = array_values(array_filter(
        $files,
        static fn (?array $file): bool => is_array($file) && uploaded_file_is_present($file)
    ));

    if ($uploadedFiles === []) {
        $errors[] = 'Legalább egy dokumentum feltöltése kötelező.';
        return $errors;
    }

    foreach ($uploadedFiles as $file) {
        $errors = array_merge($errors, validate_portal_file_upload($file, 'MVM dokumentum'));

        if ($documentType === 'complete_package') {
            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

            if ($extension !== 'pdf') {
                $errors[] = 'A komplett összefűzött dokumentum csak PDF lehet.';
            }

            if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
                $errors[] = 'A komplett összefűzött dokumentum legfeljebb 5 MB lehet.';
            }
        }

        if (in_array($documentType, ['authorization', 'completed_intervention_sheet', 'construction_log', 'technical_declaration', 'h_tariff_declaration', 'technical_handover_package', 'seal_removal_package'], true)) {
            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

            if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                $errors[] = 'Az összefűzött MVM csomagba kerülő feltöltések csak PDF vagy kép fájlok lehetnek.';
            }
        }
    }

    return $errors;
}

function store_connection_request_documents(int $requestId, string $documentType, string $title, array $files): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        throw new RuntimeException('A munkaigény nem található.');
    }

    $types = mvm_document_types();
    $documentTitle = trim($title) !== '' ? trim($title) : ($types[$documentType] ?? 'MVM dokumentum');
    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/' . $documentType;
    ensure_storage_dir($targetDir);

    $messages = [];
    $savedCount = 0;
    $user = current_user();

    foreach ($files as $file) {
        if (!is_array($file) || !uploaded_file_is_present($file)) {
            continue;
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $storedName = $documentType . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
        $targetPath = $targetDir . '/' . $storedName;

        if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
            $messages[] = (string) $file['name'] . ': nem sikerült menteni.';
            continue;
        }

        $mimeType = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: '') : '';

        db_query(
            'INSERT INTO `connection_request_documents`
                (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
                 `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $requestId,
                (int) $request['customer_id'],
                $documentType,
                $documentTitle,
                (string) $file['name'],
                $storedName,
                $targetPath,
                $mimeType !== '' ? $mimeType : (document_allowed_extensions()[$extension] ?? 'application/octet-stream'),
                (int) $file['size'],
                is_array($user) ? (int) $user['id'] : null,
            ]
        );

        $savedCount++;
    }

    if ($savedCount === 0) {
        throw new RuntimeException('Nem sikerült menteni a feltöltött MVM dokumentumot.');
    }

    return $messages;
}

function connection_request_documents(int $requestId): array
{
    if (!db_table_exists('connection_request_documents')) {
        return [];
    }

    return db_query(
        'SELECT d.*, u.role AS created_by_user_role, u.name AS created_by_user_name, u.email AS created_by_user_email
         FROM `connection_request_documents` d
         LEFT JOIN `users` u ON u.id = d.created_by_user_id
         WHERE d.`connection_request_id` = ?
         ORDER BY d.`created_at` DESC, d.`id` DESC',
        [$requestId]
    )->fetchAll();
}

function connection_request_complete_packages(int $requestId): array
{
    if (!db_table_exists('connection_request_documents')) {
        return [];
    }

    return db_query(
        'SELECT * FROM `connection_request_documents`
         WHERE `connection_request_id` = ? AND `document_type` = ?
         ORDER BY `created_at` DESC, `id` DESC',
        [$requestId, 'complete_package']
    )->fetchAll();
}

function connection_request_execution_plan_packages(int $requestId): array
{
    if (!db_table_exists('connection_request_documents')) {
        return [];
    }

    return db_query(
        'SELECT * FROM `connection_request_documents`
         WHERE `connection_request_id` = ? AND `document_type` = ?
         ORDER BY `created_at` DESC, `id` DESC',
        [$requestId, 'execution_plan_package']
    )->fetchAll();
}

function connection_request_technical_handover_packages(int $requestId): array
{
    if (!db_table_exists('connection_request_documents')) {
        return [];
    }

    return db_query(
        'SELECT * FROM `connection_request_documents`
         WHERE `connection_request_id` = ? AND `document_type` = ?
         ORDER BY `created_at` DESC, `id` DESC',
        [$requestId, 'technical_handover_package']
    )->fetchAll();
}

function connection_request_seal_removal_packages(int $requestId): array
{
    if (!db_table_exists('connection_request_documents')) {
        return [];
    }

    return db_query(
        'SELECT * FROM `connection_request_documents`
         WHERE `connection_request_id` = ? AND `document_type` = ?
         ORDER BY `created_at` DESC, `id` DESC',
        [$requestId, 'seal_removal_package']
    )->fetchAll();
}

function latest_connection_request_document_by_types(int $requestId, array $documentTypes, bool $packageCompatibleOnly = true): ?array
{
    $documentTypes = array_values(array_filter(array_unique(array_map('strval', $documentTypes))));

    if (!db_table_exists('connection_request_documents') || $documentTypes === []) {
        return null;
    }

    $placeholders = implode(', ', array_fill(0, count($documentTypes), '?'));
    $documents = db_query(
        'SELECT * FROM `connection_request_documents`
         WHERE `connection_request_id` = ? AND `document_type` IN (' . $placeholders . ')
         ORDER BY `created_at` DESC, `id` DESC',
        array_merge([$requestId], $documentTypes)
    )->fetchAll();

    foreach ($documents as $document) {
        if (!$packageCompatibleOnly || pdf_package_file_is_pdf($document) || pdf_package_file_is_image($document)) {
            return $document;
        }
    }

    return null;
}

function latest_connection_request_mvm_source_document(int $requestId, bool $packageCompatibleOnly = true): ?array
{
    return latest_connection_request_document_by_types(
        $requestId,
        ['accepted_request', 'submitted_request', 'intervention_sheet'],
        $packageCompatibleOnly
    );
}

function latest_connection_request_mvm_request_pdf_document(int $requestId): ?array
{
    if (!db_table_exists('connection_request_documents')) {
        return null;
    }

    foreach (['submitted_request', 'accepted_request'] as $documentType) {
        $documents = db_query(
            'SELECT * FROM `connection_request_documents`
             WHERE `connection_request_id` = ? AND `document_type` = ?
             ORDER BY `created_at` DESC, `id` DESC',
            [$requestId, $documentType]
        )->fetchAll();

        foreach ($documents as $document) {
            if (pdf_package_file_is_pdf($document)) {
                return $document;
            }
        }
    }

    return null;
}

function latest_connection_request_technical_declaration_source_document(int $requestId): ?array
{
    $mvmRequest = latest_connection_request_mvm_request_pdf_document($requestId);

    if ($mvmRequest !== null) {
        return $mvmRequest;
    }

    return latest_connection_request_document_by_types($requestId, ['complete_package'], true);
}

function latest_connection_request_execution_plan_document(int $requestId, bool $packageCompatibleOnly = true): ?array
{
    return latest_connection_request_document_by_types($requestId, ['execution_plan'], $packageCompatibleOnly);
}

function latest_connection_request_technical_handover_document(int $requestId, bool $packageCompatibleOnly = true): ?array
{
    return latest_connection_request_document_by_types($requestId, ['technical_handover'], $packageCompatibleOnly);
}

function latest_connection_request_seal_removal_document(int $requestId, bool $packageCompatibleOnly = true): ?array
{
    return latest_connection_request_document_by_types($requestId, ['seal_removal'], $packageCompatibleOnly);
}

function latest_connection_request_h_tariff_declaration_document(int $requestId, bool $packageCompatibleOnly = true): ?array
{
    return latest_connection_request_document_by_types($requestId, ['h_tariff_declaration'], $packageCompatibleOnly);
}

function latest_connection_request_technical_document(int $requestId, string $documentType, bool $packageCompatibleOnly = true): ?array
{
    return latest_connection_request_document_by_types($requestId, [$documentType], $packageCompatibleOnly);
}

function connection_request_document_package_part(array $document, string $group, string $fallbackLabel): array
{
    return [
        'group' => $group,
        'label' => (string) ($document['title'] ?: $fallbackLabel),
        'original_name' => (string) $document['original_name'],
        'path' => (string) $document['storage_path'],
        'mime_type' => (string) $document['mime_type'],
        'source' => 'mvm',
    ];
}

function connection_request_work_file_package_part(array $file, string $group): array
{
    return [
        'group' => $group,
        'label' => (string) ($file['label'] ?? $group),
        'original_name' => (string) $file['original_name'],
        'path' => (string) $file['storage_path'],
        'mime_type' => (string) $file['mime_type'],
        'source' => 'work_file',
        'file_type' => (string) ($file['file_type'] ?? ''),
    ];
}

function connection_request_package_file_is_compatible(array $file): bool
{
    return pdf_package_file_is_pdf($file) || pdf_package_file_is_image($file);
}

function connection_request_has_package_file_type(?int $requestId, string $fileType): bool
{
    if ($requestId === null || $requestId <= 0) {
        return false;
    }

    foreach (connection_request_files($requestId) as $file) {
        if ((string) ($file['file_type'] ?? '') !== $fileType) {
            continue;
        }

        if (connection_request_package_file_is_compatible($file)) {
            return true;
        }
    }

    return false;
}

function connection_request_has_h_tariff_requirement(int $requestId): bool
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return false;
    }

    if ((string) ($request['request_type'] ?? '') === 'h_tariff') {
        return true;
    }

    $values = connection_request_mvm_form_values($request);

    return trim((string) ($values['h_tarifa_vagy_melleszereles'] ?? '')) !== ''
        || mvm_h_tariff_form_values_are_filled($values);
}

function connection_request_named_file_package_part(array $file, string $group, string $label, string $fileType): array
{
    return [
        'group' => $group,
        'label' => $label,
        'original_name' => (string) $file['original_name'],
        'path' => (string) $file['storage_path'],
        'mime_type' => (string) $file['mime_type'],
        'source' => 'request_file',
        'file_type' => $fileType,
    ];
}

function connection_request_complete_package_parts(int $requestId): array
{
    $parts = [];
    $mvmDocument = latest_connection_request_mvm_source_document($requestId);

    if ($mvmDocument !== null) {
        $parts[] = connection_request_document_package_part($mvmDocument, 'MVM dokumentum', 'MVM dokumentum');
    }

    $hTariffDeclaration = latest_connection_request_h_tariff_declaration_document($requestId);

    if ($hTariffDeclaration !== null) {
        $parts[] = connection_request_document_package_part($hTariffDeclaration, 'H tarifa nyilatkozat', 'H tarifa nyilatkozat');
    }

    $definitions = connection_request_upload_definitions();
    $filesByType = [];

    foreach (connection_request_files($requestId) as $file) {
        $filesByType[(string) $file['file_type']][] = $file;
    }

    if (connection_request_has_h_tariff_requirement($requestId)) {
        foreach (h_tariff_required_file_types() as $fileType => $label) {
            foreach ($filesByType[$fileType] ?? [] as $file) {
                if (!connection_request_package_file_is_compatible($file)) {
                    continue;
                }

                $parts[] = connection_request_named_file_package_part($file, 'H tarifa melléklet', $label, (string) $fileType);
            }
        }
    }

    foreach ([
        'authorization' => 'Meghatalmazás',
        'title_deed' => 'Tulajdoni lap',
        'map_copy' => 'Térképmásolat',
        'consent_statement' => 'Hozzájáruló nyilatkozat',
    ] as $fileType => $group) {
        foreach ($filesByType[$fileType] ?? [] as $file) {
            if (!connection_request_package_file_is_compatible($file)) {
                continue;
            }

            $parts[] = connection_request_named_file_package_part(
                $file,
                $group,
                (string) ($definitions[$fileType]['label'] ?? $group),
                (string) $fileType
            );
        }
    }

    foreach ($definitions as $fileType => $definition) {
        if (($definition['kind'] ?? '') !== 'image') {
            continue;
        }

        foreach ($filesByType[$fileType] ?? [] as $file) {
            if (!connection_request_package_file_is_compatible($file)) {
                continue;
            }

            $parts[] = connection_request_named_file_package_part($file, 'Fotók', (string) $definition['label'], (string) $fileType);
        }
    }

    return $parts;
}

function connection_request_execution_plan_package_parts(int $requestId): array
{
    $parts = [];
    $executionPlan = latest_connection_request_execution_plan_document($requestId);

    if ($executionPlan !== null) {
        $parts[] = connection_request_document_package_part($executionPlan, 'Kiviteli terv', 'Kiviteli terv dokumentáció');
    }

    $definitions = connection_request_upload_definitions();
    $filesByType = [];

    foreach (connection_request_files($requestId) as $file) {
        $filesByType[(string) $file['file_type']][] = $file;
    }

    foreach ($definitions as $fileType => $definition) {
        if (($definition['kind'] ?? '') !== 'image') {
            continue;
        }

        foreach ($filesByType[$fileType] ?? [] as $file) {
            $parts[] = [
                'group' => 'Fotók',
                'label' => (string) $definition['label'],
                'original_name' => (string) $file['original_name'],
                'path' => (string) $file['storage_path'],
                'mime_type' => (string) $file['mime_type'],
                'source' => 'request_file',
                'file_type' => $fileType,
            ];
        }
    }

    return $parts;
}

function connection_request_seal_removal_package_parts(int $requestId): array
{
    $parts = [];
    $sealRemoval = latest_connection_request_seal_removal_document($requestId);

    if ($sealRemoval !== null) {
        $parts[] = connection_request_document_package_part($sealRemoval, 'Plombabontási engedély', 'Plombabontási engedély');
    }

    $authorization = latest_connection_request_authorization_package_part($requestId);

    if ($authorization !== null) {
        $parts[] = $authorization;
    }

    return $parts;
}

function connection_request_complete_package_missing_items(int $requestId): array
{
    $missing = [];

    if (latest_connection_request_mvm_source_document($requestId) === null) {
        $missing[] = 'MVM dokumentum PDF vagy kép formátumban';
    }

    if (connection_request_h_tariff_section_is_filled($requestId)
        && latest_connection_request_h_tariff_declaration_document($requestId) === null
        && mvm_h_tariff_template_errors() !== []
    ) {
        $missing[] = 'H tarifa nyilatkozat sablon';
    }

    if (connection_request_has_h_tariff_requirement($requestId)) {
        if (!connection_request_h_tariff_section_is_filled($requestId)) {
            $missing[] = 'H tarifa nyilatkozat adatai';
        }

        foreach (h_tariff_required_file_types() as $fileType => $label) {
            if (!connection_request_has_package_file_type($requestId, (string) $fileType)) {
                $missing[] = $label . ' PDF vagy kép formátumban';
            }
        }
    }

    if (!connection_request_has_package_file_type($requestId, 'authorization')) {
        $missing[] = 'Meghatalmazás PDF vagy kép formátumban';
    }

    if (!connection_request_has_package_file_type($requestId, 'title_deed')) {
        $missing[] = 'Tulajdoni lap PDF vagy kép formátumban';
    }

    if (!connection_request_has_package_file_type($requestId, 'map_copy')) {
        $missing[] = 'Térképmásolat PDF vagy kép formátumban';
    }

    if (!connection_request_has_photo_file($requestId)) {
        $missing[] = 'Fotók';
    }

    return $missing;
}

function connection_request_execution_plan_package_missing_items(int $requestId): array
{
    $missing = [];

    if (latest_connection_request_execution_plan_document($requestId) === null) {
        $missing[] = 'Kiviteli terv PDF vagy kép formátumban';
    }

    if (!connection_request_has_photo_file($requestId)) {
        $missing[] = 'Fotók';
    }

    return $missing;
}

function connection_request_seal_removal_package_missing_items(int $requestId): array
{
    $missing = [];

    if (latest_connection_request_seal_removal_document($requestId) === null) {
        $missing[] = 'Plombabontási engedély PDF';
    }

    if (latest_connection_request_authorization_package_part($requestId) === null) {
        $missing[] = 'Meghatalmazás PDF vagy kép';
    }

    return $missing;
}

function latest_completed_intervention_sheet_part(int $requestId): ?array
{
    $document = latest_connection_request_technical_document($requestId, 'completed_intervention_sheet');

    if ($document !== null) {
        return connection_request_document_package_part($document, 'Kész beavatkozási lap', 'Kész beavatkozási lap');
    }

    return null;
}

function connection_request_after_work_photo_parts(int $requestId): array
{
    if (!db_table_exists('connection_request_work_files')) {
        return [];
    }

    $files = db_query(
        'SELECT * FROM `connection_request_work_files`
         WHERE `connection_request_id` = ? AND `stage` = ?
         ORDER BY `created_at` ASC, `id` ASC',
        [$requestId, 'after']
    )->fetchAll();
    $byType = [];

    foreach ($files as $file) {
        $fileType = (string) ($file['file_type'] ?? '');

        if ($fileType === 'completed_intervention_sheet') {
            continue;
        }

        if (pdf_package_file_is_pdf($file) || pdf_package_file_is_image($file)) {
            $byType[$fileType][] = $file;
        }
    }

    $orderedTypes = ['meter_far', 'meter_close', 'utility_pole', 'roof_hook', 'seals'];
    $parts = [];

    foreach ($orderedTypes as $fileType) {
        foreach ($byType[$fileType] ?? [] as $file) {
            $parts[] = connection_request_work_file_package_part($file, 'Kivitelezési fotók');
        }
    }

    foreach ($byType as $fileType => $typedFiles) {
        if (in_array($fileType, $orderedTypes, true)) {
            continue;
        }

        foreach ($typedFiles as $file) {
            $parts[] = connection_request_work_file_package_part($file, 'Kivitelezési fotók');
        }
    }

    return $parts;
}

function connection_request_technical_handover_package_parts(int $requestId): array
{
    $parts = [];
    $technicalHandover = latest_connection_request_technical_handover_document($requestId);

    if ($technicalHandover !== null) {
        $parts[] = connection_request_document_package_part($technicalHandover, 'Műszaki átadási dokumentum', 'Műszaki átadás-átvételi jegyzőkönyv');
    }

    $completedInterventionSheet = latest_completed_intervention_sheet_part($requestId);

    if ($completedInterventionSheet !== null) {
        $parts[] = $completedInterventionSheet;
    }

    $constructionLog = latest_connection_request_technical_document($requestId, 'construction_log');

    if ($constructionLog !== null) {
        $parts[] = connection_request_document_package_part($constructionLog, 'Építési napló', 'Építési napló');
    }

    $technicalDeclaration = latest_connection_request_technical_document($requestId, 'technical_declaration');

    if ($technicalDeclaration !== null) {
        $parts[] = connection_request_document_package_part($technicalDeclaration, 'Nyilatkozat adatlap', 'Nyilatkozat adatlap');
    }

    return array_merge($parts, connection_request_after_work_photo_parts($requestId));
}

function connection_request_required_after_photo_labels(): array
{
    return [
        'meter_far' => 'Mérő távolról',
        'meter_close' => 'Mérő közelről',
        'utility_pole' => 'Villanyoszlop',
        'roof_hook' => 'Tetőtartó',
        'seals' => 'Plombák',
    ];
}

function connection_request_file_package_part(array $file, string $group): array
{
    return [
        'group' => $group,
        'label' => (string) ($file['label'] ?? $group),
        'original_name' => (string) $file['original_name'],
        'path' => (string) $file['storage_path'],
        'mime_type' => (string) $file['mime_type'],
        'source' => 'request_file',
        'file_type' => (string) ($file['file_type'] ?? ''),
    ];
}

function latest_connection_request_authorization_package_part(int $requestId): ?array
{
    $candidates = [];

    if (db_table_exists('connection_request_files')) {
        $files = db_query(
            'SELECT * FROM `connection_request_files`
             WHERE `connection_request_id` = ? AND `file_type` = ?
             ORDER BY `created_at` DESC, `id` DESC',
            [$requestId, 'authorization']
        )->fetchAll();

        foreach ($files as $file) {
            if (pdf_package_file_is_pdf($file) || pdf_package_file_is_image($file)) {
                $candidates[] = [
                    'created_at' => (string) ($file['created_at'] ?? ''),
                    'id' => (int) ($file['id'] ?? 0),
                    'part' => connection_request_file_package_part($file, 'Meghatalmazás'),
                ];
            }
        }
    }

    $document = latest_connection_request_document_by_types($requestId, ['authorization'], true);

    if ($document !== null) {
        $candidates[] = [
            'created_at' => (string) ($document['created_at'] ?? ''),
            'id' => (int) ($document['id'] ?? 0),
            'part' => connection_request_document_package_part($document, 'Meghatalmazás', 'Meghatalmazás'),
        ];
    }

    if (db_table_exists('connection_request_work_files')) {
        $workFiles = db_query(
            'SELECT * FROM `connection_request_work_files`
             WHERE `connection_request_id` = ? AND `file_type` = ?
             ORDER BY `created_at` DESC, `id` DESC',
            [$requestId, 'authorization']
        )->fetchAll();

        foreach ($workFiles as $file) {
            if (pdf_package_file_is_pdf($file) || pdf_package_file_is_image($file)) {
                $candidates[] = [
                    'created_at' => (string) ($file['created_at'] ?? ''),
                    'id' => (int) ($file['id'] ?? 0),
                    'part' => connection_request_work_file_package_part($file, 'Meghatalmazás'),
                ];
            }
        }
    }

    if ($candidates === []) {
        return null;
    }

    usort(
        $candidates,
        static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));

            return $dateCompare !== 0 ? $dateCompare : ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
        }
    );

    return $candidates[0]['part'];
}

function connection_request_required_after_photo_missing_items(int $requestId): array
{
    $missing = [];

    foreach (connection_request_required_after_photo_labels() as $fileType => $label) {
        if (!connection_request_has_work_file_type($requestId, 'after', $fileType)) {
            $missing[] = $label;
        }
    }

    return $missing;
}

function connection_request_technical_handover_package_missing_items(int $requestId): array
{
    $missing = [];

    if (latest_connection_request_technical_handover_document($requestId) === null) {
        $missing[] = 'Műszaki átadási dokumentum PDF';
    }

    if (latest_completed_intervention_sheet_part($requestId) === null) {
        $missing[] = 'Kész beavatkozási lap';
    }

    if (latest_connection_request_technical_document($requestId, 'construction_log') === null) {
        $missing[] = 'Építési napló';
    }

    if (
        latest_connection_request_technical_document($requestId, 'technical_declaration') === null
        && latest_connection_request_technical_declaration_source_document($requestId) === null
    ) {
        $missing[] = 'Nyilatkozat adatlaphoz MVM jóváhagyási dokumentum';
    }

    foreach (connection_request_required_after_photo_missing_items($requestId) as $label) {
        $missing[] = 'Kivitelezési fotó: ' . $label;
    }

    return $missing;
}

function pdf_package_file_is_pdf(array $part): bool
{
    $path = (string) ($part['path'] ?? $part['storage_path'] ?? '');

    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf'
        || str_contains(strtolower((string) ($part['mime_type'] ?? '')), 'pdf');
}

function pdf_package_file_is_image(array $part): bool
{
    $path = (string) ($part['path'] ?? $part['storage_path'] ?? '');
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)
        || str_starts_with(strtolower((string) ($part['mime_type'] ?? '')), 'image/');
}

function prepare_pdf_package_image(string $sourcePath, int $quality): string
{
    $imageInfo = getimagesize($sourcePath);

    if ($imageInfo === false) {
        throw new RuntimeException('A fotó nem olvasható: ' . basename($sourcePath));
    }

    [$sourceWidth, $sourceHeight, $imageType] = $imageInfo;

    $sourceImage = match ($imageType) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
        IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : false,
        default => false,
    };

    if (!$sourceImage) {
        throw new RuntimeException('Nem támogatott fotóformátum: ' . basename($sourcePath));
    }

    $maxDimension = 1600;
    $scale = min(1, $maxDimension / max($sourceWidth, $sourceHeight));
    $targetWidth = max(1, (int) floor($sourceWidth * $scale));
    $targetHeight = max(1, (int) floor($sourceHeight * $scale));
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

    imagefill($targetImage, 0, 0, imagecolorallocate($targetImage, 255, 255, 255));
    imagecopyresampled($targetImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

    $targetPath = tempnam(sys_get_temp_dir(), 'mezo_package_img_') . '.jpg';
    imagejpeg($targetImage, $targetPath, $quality);
    imagedestroy($sourceImage);
    imagedestroy($targetImage);

    return $targetPath;
}

function pdf_package_file_starts_with_pdf_signature(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $handle = fopen($path, 'rb');
    $signature = $handle ? fread($handle, 4) : '';

    if (is_resource($handle)) {
        fclose($handle);
    }

    return $signature === '%PDF';
}

function normalize_pdf_package_pdf_with_command(string $sourcePath, string $targetPath, array $candidates, callable $commandBuilder): array
{
    if (!function_exists('exec')) {
        return ['ok' => false, 'message' => 'Az exec futtatás nem engedélyezett.', 'path' => null];
    }

    $lastOutput = [];

    foreach (array_values(array_filter($candidates)) as $binary) {
        $command = $commandBuilder((string) $binary, $sourcePath, $targetPath);
        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);
        $lastOutput = $output;
        clearstatcache(true, $targetPath);

        if ($exitCode === 0 && pdf_package_file_starts_with_pdf_signature($targetPath)) {
            return ['ok' => true, 'message' => basename((string) $binary) . ' PDF normalizálás sikeres.', 'path' => $targetPath];
        }

        if (is_file($targetPath)) {
            unlink($targetPath);
        }
    }

    return [
        'ok' => false,
        'message' => trim(implode(' ', $lastOutput)) ?: 'Nem érhető el megfelelő helyi PDF normalizáló eszköz.',
        'path' => null,
    ];
}

function normalize_pdf_package_pdf_with_qpdf(string $sourcePath, string $targetPath): array
{
    $configuredBinary = trim(mvm_config_value('QPDF_BIN', ''));
    $candidates = array_values(array_filter([$configuredBinary, 'qpdf']));

    return normalize_pdf_package_pdf_with_command(
        $sourcePath,
        $targetPath,
        $candidates,
        static fn (string $binary, string $source, string $target): string => escapeshellcmd($binary)
            . ' --object-streams=disable --stream-data=uncompress --force-version=1.4 '
            . escapeshellarg($source) . ' ' . escapeshellarg($target) . ' 2>&1'
    );
}

function normalize_pdf_package_pdf_with_ghostscript(string $sourcePath, string $targetPath): array
{
    $configuredBinary = trim(mvm_config_value('GHOSTSCRIPT_BIN', ''));
    $candidates = array_values(array_filter([$configuredBinary, 'gs', 'gswin64c', 'gswin32c']));

    return normalize_pdf_package_pdf_with_command(
        $sourcePath,
        $targetPath,
        $candidates,
        static fn (string $binary, string $source, string $target): string => escapeshellcmd($binary)
            . ' -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/prepress -dNOPAUSE -dBATCH -dSAFER '
            . '-sOutputFile=' . escapeshellarg($target) . ' '
            . escapeshellarg($source) . ' 2>&1'
    );
}

function normalize_pdf_package_pdf_with_convertapi(string $sourcePath, string $targetPath): array
{
    $secret = defined('CONVERTAPI_SECRET') ? CONVERTAPI_SECRET : '';

    if ($secret === '') {
        return ['ok' => false, 'message' => 'Nincs beállítva CONVERTAPI_SECRET.', 'path' => null];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'A PHP cURL bővítmény nem elérhető.', 'path' => null];
    }

    $targetFile = fopen($targetPath, 'wb');

    if ($targetFile === false) {
        return ['ok' => false, 'message' => 'Nem sikerült létrehozni az ideiglenes PDF fájlt.', 'path' => null];
    }

    $endpoint = defined('CONVERTAPI_ENDPOINT') ? CONVERTAPI_ENDPOINT : 'https://v2.convertapi.com';
    $curl = curl_init(rtrim($endpoint, '/') . '/convert/pdf/to/pdf');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'File' => new CURLFile($sourcePath, 'application/pdf', basename($sourcePath)),
            'PdfVersion' => '1.4',
            'StoreFile' => 'false',
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secret,
        ],
        CURLOPT_FILE => $targetFile,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FAILONERROR => false,
    ]);

    $success = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    fclose($targetFile);
    clearstatcache(true, $targetPath);

    if (!$success || $statusCode < 200 || $statusCode >= 300 || !is_file($targetPath) || filesize($targetPath) === 0) {
        $responseBody = is_file($targetPath) ? (string) file_get_contents($targetPath) : '';

        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        return [
            'ok' => false,
            'message' => 'ConvertAPI PDF normalizálás sikertelen. HTTP: ' . $statusCode . '. ' . ($curlError !== '' ? 'cURL: ' . $curlError . '. ' : '') . trim($responseBody),
            'path' => null,
        ];
    }

    if (pdf_package_file_starts_with_pdf_signature($targetPath)) {
        return ['ok' => true, 'message' => 'ConvertAPI PDF normalizálás sikeres.', 'path' => $targetPath];
    }

    $responseBody = (string) file_get_contents($targetPath);
    $decoded = json_decode($responseBody, true);

    if (is_array($decoded) && isset($decoded['Files'][0]['FileData'])) {
        $pdfBytes = base64_decode((string) $decoded['Files'][0]['FileData'], true);

        if (is_string($pdfBytes) && str_starts_with($pdfBytes, '%PDF')) {
            file_put_contents($targetPath, $pdfBytes);

            return ['ok' => true, 'message' => 'ConvertAPI PDF normalizálás sikeres.', 'path' => $targetPath];
        }
    }

    if (is_file($targetPath)) {
        unlink($targetPath);
    }

    return [
        'ok' => false,
        'message' => 'A ConvertAPI PDF normalizálás válasza nem PDF fájl. ' . substr($responseBody, 0, 300),
        'path' => null,
    ];
}

function normalize_pdf_package_pdf_for_fpdi(string $sourcePath, string $label): array
{
    $targetPath = tempnam(sys_get_temp_dir(), 'mezo_package_pdf_') . '.pdf';
    $messages = [];
    $normalizers = [
        'qpdf' => 'normalize_pdf_package_pdf_with_qpdf',
        'ghostscript' => 'normalize_pdf_package_pdf_with_ghostscript',
        'convertapi' => 'normalize_pdf_package_pdf_with_convertapi',
    ];

    foreach ($normalizers as $normalizerName => $normalizer) {
        $result = $normalizer($sourcePath, $targetPath);

        if (($result['ok'] ?? false) && !empty($result['path']) && is_file((string) $result['path'])) {
            return [
                'ok' => true,
                'message' => (string) ($result['message'] ?? ($normalizerName . ' normalizálás sikeres.')),
                'path' => (string) $result['path'],
            ];
        }

        $messages[] = $normalizerName . ': ' . (string) ($result['message'] ?? 'sikertelen');
    }

    if (is_file($targetPath)) {
        unlink($targetPath);
    }

    return [
        'ok' => false,
        'message' => 'A(z) "' . $label . '" PDF automatikus kompatibilissé mentése nem sikerült. ' . implode(' ', array_filter($messages)),
        'path' => null,
    ];
}

function render_connection_request_complete_package_pdf(array $parts, string $targetPath, int $imageQuality): array
{
    if (!class_exists('\\setasign\\Fpdi\\Fpdi')) {
        throw new RuntimeException('Az FPDI nincs telepítve. Futtasd: composer install, majd töltsd fel a vendor mappát.');
    }

    $pdf = new \setasign\Fpdi\Fpdi();
    $temporaryFiles = [];

    try {
        foreach ($parts as $part) {
            $path = (string) $part['path'];
            $label = (string) ($part['label'] ?? $part['original_name'] ?? basename($path));

            if (!is_file($path)) {
                throw new RuntimeException('Hiányzó fájl: ' . $label);
            }

            if (pdf_package_file_is_pdf($part)) {
                try {
                    $pageCount = $pdf->setSourceFile($path);
                } catch (Throwable $exception) {
                    $normalizeResult = normalize_pdf_package_pdf_for_fpdi($path, $label);

                    if (($normalizeResult['ok'] ?? false) && !empty($normalizeResult['path'])) {
                        $path = (string) $normalizeResult['path'];
                        $temporaryFiles[] = $path;
                        $pageCount = $pdf->setSourceFile($path);
                    } else {
                        throw new RuntimeException(
                        'A(z) "' . $label . '" PDF nem fűzhető be. Nyisd meg, mentsd vagy nyomtasd új PDF-be, majd töltsd fel újra. Részletek: ' . $exception->getMessage(),
                        0,
                        $exception
                        );
                    }
                }

                for ($page = 1; $page <= $pageCount; $page++) {
                    try {
                        $templateId = $pdf->importPage($page);
                        $size = $pdf->getTemplateSize($templateId);
                        $orientation = ((float) $size['width'] > (float) $size['height']) ? 'L' : 'P';
                        $pdf->AddPage($orientation, [(float) $size['width'], (float) $size['height']]);
                        $pdf->useTemplate($templateId);
                    } catch (Throwable $exception) {
                        throw new RuntimeException(
                            'A(z) "' . $label . '" PDF ' . $page . '. oldala nem fűzhető be. Nyisd meg, mentsd vagy nyomtasd új PDF-be, majd töltsd fel újra. Részletek: ' . $exception->getMessage(),
                            0,
                            $exception
                        );
                    }
                }

                continue;
            }

            if (pdf_package_file_is_image($part)) {
                $imagePath = prepare_pdf_package_image($path, $imageQuality);
                $temporaryFiles[] = $imagePath;
                $imageInfo = getimagesize($imagePath);

                if ($imageInfo === false) {
                    throw new RuntimeException('A fotó nem olvasható: ' . $label);
                }

                [$imageWidth, $imageHeight] = $imageInfo;
                $orientation = $imageWidth > $imageHeight ? 'L' : 'P';
                $pdf->AddPage($orientation, 'A4');
                $pageWidth = $pdf->GetPageWidth();
                $pageHeight = $pdf->GetPageHeight();
                $margin = 8;
                $usableWidth = $pageWidth - ($margin * 2);
                $usableHeight = $pageHeight - ($margin * 2);
                $ratio = min($usableWidth / $imageWidth, $usableHeight / $imageHeight);
                $renderWidth = $imageWidth * $ratio;
                $renderHeight = $imageHeight * $ratio;
                $left = ($pageWidth - $renderWidth) / 2;
                $top = ($pageHeight - $renderHeight) / 2;
                $pdf->Image($imagePath, $left, $top, $renderWidth, $renderHeight, 'JPG');
                continue;
            }

            throw new RuntimeException('A komplett PDF-be csak PDF és kép fájl fűzhető be: ' . $label);
        }

        $pdf->Output('F', $targetPath);
    } finally {
        foreach ($temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    clearstatcache(true, $targetPath);

    return [
        'path' => $targetPath,
        'size' => is_file($targetPath) ? (int) filesize($targetPath) : 0,
    ];
}

function extract_connection_request_pdf_pages(array $sourceDocument, string $targetPath, int $startPage, int $endPage): void
{
    if (!class_exists('\\setasign\\Fpdi\\Fpdi')) {
        throw new RuntimeException('Az FPDI nincs telepítve. Futtasd: composer install, majd töltsd fel a vendor mappát.');
    }

    $sourcePath = (string) ($sourceDocument['storage_path'] ?? '');

    if ($sourcePath === '' || !is_file($sourcePath) || !pdf_package_file_is_pdf($sourceDocument)) {
        throw new RuntimeException('A forrás MVM jóváhagyási PDF nem található.');
    }

    $pdf = new \setasign\Fpdi\Fpdi();
    $pageCount = $pdf->setSourceFile($sourcePath);

    if ($pageCount < $endPage) {
        throw new RuntimeException('A forrás MVM jóváhagyási PDF csak ' . $pageCount . ' oldalas, ezért a 9-11. oldal nem nyerhető ki.');
    }

    for ($page = $startPage; $page <= $endPage; $page++) {
        $templateId = $pdf->importPage($page);
        $size = $pdf->getTemplateSize($templateId);
        $orientation = ((float) $size['width'] > (float) $size['height']) ? 'L' : 'P';
        $pdf->AddPage($orientation, [(float) $size['width'], (float) $size['height']]);
        $pdf->useTemplate($templateId);
    }

    $pdf->Output('F', $targetPath);
}

function generate_connection_request_technical_declaration(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $sourceDocument = latest_connection_request_technical_declaration_source_document($requestId);

    if ($sourceDocument === null) {
        return ['ok' => false, 'message' => 'A nyilatkozat adatlap kinyeréséhez előbb legyen MVM jóváhagyási dokumentum PDF az igényhez.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/technical-declaration';
    ensure_storage_dir($targetDir);

    $storedName = 'nyilatkozat-adatlap-' . $requestId . '-' . date('Ymd-His') . '.pdf';
    $targetPath = $targetDir . '/' . $storedName;

    try {
        extract_connection_request_pdf_pages($sourceDocument, $targetPath, 9, 11);

        if (!is_file($targetPath)) {
            return ['ok' => false, 'message' => 'A nyilatkozatok adatlap PDF nem készült el.', 'document_id' => null];
        }

        db_query(
            'INSERT INTO `connection_request_documents`
                (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
                 `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $requestId,
                (int) $request['customer_id'],
                'technical_declaration',
                'Nyilatkozat adatlap - jóváhagyási dokumentumból kinyerve',
                $storedName,
                $storedName,
                $targetPath,
                'application/pdf',
                (int) filesize($targetPath),
                is_array(current_user()) ? (int) current_user()['id'] : null,
            ]
        );

        return [
            'ok' => true,
            'message' => 'A nyilatkozat adatlap elkészült az MVM jóváhagyási dokumentum 9-11. oldalából.',
            'document_id' => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $exception) {
        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        return [
            'ok' => false,
            'message' => 'A nyilatkozat adatlap kinyerése sikertelen: ' . $exception->getMessage(),
            'document_id' => null,
        ];
    }
}

function generate_connection_request_complete_package(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    if (connection_request_h_tariff_section_is_filled($requestId)) {
        $hTariffResult = generate_mvm_h_tariff_pdf($requestId);

        if (!$hTariffResult['ok']) {
            return [
                'ok' => false,
                'message' => 'A H tarifa nyilatkozatot nem sikerült elkészíteni a jóváhagyási csomaghoz. ' . (string) $hTariffResult['message'],
                'document_id' => null,
            ];
        }
    }

    $missingItems = connection_request_complete_package_missing_items($requestId);

    if ($missingItems !== []) {
        return ['ok' => false, 'message' => 'Az MVM jóváhagyási csomaghoz még hiányzik: ' . implode(', ', $missingItems) . '.', 'document_id' => null];
    }

    $parts = connection_request_complete_package_parts($requestId);

    if ($parts === []) {
        return ['ok' => false, 'message' => 'Nincs összefűzhető dokumentum.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/complete-package';
    ensure_storage_dir($targetDir);

    $storedName = 'mvm-jovahagyasi-csomag-' . $requestId . '-' . date('Ymd-His') . '.pdf';
    $finalPath = $targetDir . '/' . $storedName;
    $maxBytes = 5 * 1024 * 1024;
    $lastSize = 0;

    try {
        foreach ([72, 60, 50, 42, 35, 28] as $quality) {
            $attemptPath = $targetDir . '/attempt-' . bin2hex(random_bytes(6)) . '.pdf';
            $result = render_connection_request_complete_package_pdf($parts, $attemptPath, $quality);
            $lastSize = (int) $result['size'];

            if ($lastSize <= $maxBytes) {
                rename($attemptPath, $finalPath);
                clearstatcache(true, $finalPath);
                break;
            }

            if (is_file($attemptPath)) {
                unlink($attemptPath);
            }
        }

        if (!is_file($finalPath)) {
            return [
                'ok' => false,
                'message' => 'Az MVM jóváhagyási csomag ' . format_bytes($lastSize) . ', ezért nem mentettük. A kész dokumentumnak 5 MB alatt kell lennie; tölts fel tömörített MVM/dokumentum PDF-et vagy kisebb fotókat.',
                'document_id' => null,
            ];
        }

        db_query(
            'INSERT INTO `connection_request_documents`
                (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
                 `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $requestId,
                (int) $request['customer_id'],
                'complete_package',
                'MVM jóváhagyási csomag',
                $storedName,
                $storedName,
                $finalPath,
                'application/pdf',
                (int) filesize($finalPath),
                is_array(current_user()) ? (int) current_user()['id'] : null,
            ]
        );

        return [
            'ok' => true,
            'message' => 'Az MVM jóváhagyási csomag elkészült: ' . format_bytes((int) filesize($finalPath)) . '.',
            'document_id' => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $exception) {
        if (is_file($finalPath)) {
            unlink($finalPath);
        }

        return [
            'ok' => false,
            'message' => 'Az MVM jóváhagyási csomag generálása sikertelen: ' . $exception->getMessage(),
            'document_id' => null,
        ];
    }
}

function generate_connection_request_execution_plan_package(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $missingItems = connection_request_execution_plan_package_missing_items($requestId);

    if ($missingItems !== []) {
        return ['ok' => false, 'message' => 'A kiviteli terv csomaghoz még hiányzik: ' . implode(', ', $missingItems) . '.', 'document_id' => null];
    }

    $parts = connection_request_execution_plan_package_parts($requestId);

    if ($parts === []) {
        return ['ok' => false, 'message' => 'Nincs összefűzhető dokumentum.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/execution-plan-package';
    ensure_storage_dir($targetDir);

    $storedName = 'kiviteli-terv-csomag-' . $requestId . '-' . date('Ymd-His') . '.pdf';
    $finalPath = $targetDir . '/' . $storedName;

    try {
        $result = render_connection_request_complete_package_pdf($parts, $finalPath, 72);

        if (!is_file($finalPath) || (int) $result['size'] <= 0) {
            return ['ok' => false, 'message' => 'A kiviteli terv csomag PDF nem készült el.', 'document_id' => null];
        }

        db_query(
            'INSERT INTO `connection_request_documents`
                (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
                 `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $requestId,
                (int) $request['customer_id'],
                'execution_plan_package',
                'Kiviteli terv csomag',
                $storedName,
                $storedName,
                $finalPath,
                'application/pdf',
                (int) filesize($finalPath),
                is_array(current_user()) ? (int) current_user()['id'] : null,
            ]
        );

        return [
            'ok' => true,
            'message' => 'A kiviteli terv csomag elkészült: ' . format_bytes((int) filesize($finalPath)) . '.',
            'document_id' => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $exception) {
        if (is_file($finalPath)) {
            unlink($finalPath);
        }

        return [
            'ok' => false,
            'message' => 'A kiviteli terv csomag generálása sikertelen: ' . $exception->getMessage(),
            'document_id' => null,
        ];
    }
}

function generate_connection_request_technical_handover_package(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $missingItems = connection_request_technical_handover_package_missing_items($requestId);

    if ($missingItems !== []) {
        return ['ok' => false, 'message' => 'A műszaki átadás csomaghoz még hiányzik: ' . implode(', ', $missingItems) . '.', 'document_id' => null];
    }

    if (latest_connection_request_technical_document($requestId, 'technical_declaration') === null) {
        $declarationResult = generate_connection_request_technical_declaration($requestId);

        if (!$declarationResult['ok']) {
            return [
                'ok' => false,
                'message' => 'A műszaki átadás csomaghoz nem sikerült előállítani a nyilatkozatok adatlapot. ' . (string) $declarationResult['message'],
                'document_id' => null,
            ];
        }
    }

    $parts = connection_request_technical_handover_package_parts($requestId);

    if ($parts === []) {
        return ['ok' => false, 'message' => 'Nincs összefűzhető műszaki átadás dokumentum.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/technical-handover-package';
    ensure_storage_dir($targetDir);

    $storedName = 'muszaki-atadas-csomag-' . $requestId . '-' . date('Ymd-His') . '.pdf';
    $finalPath = $targetDir . '/' . $storedName;

    try {
        $result = render_connection_request_complete_package_pdf($parts, $finalPath, 72);

        if (!is_file((string) $result['path'])) {
            return ['ok' => false, 'message' => 'A műszaki átadás PDF nem készült el.', 'document_id' => null];
        }

        db_query(
            'INSERT INTO `connection_request_documents`
                (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
                 `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $requestId,
                (int) $request['customer_id'],
                'technical_handover_package',
                'Műszaki átadás csomag',
                $storedName,
                $storedName,
                $finalPath,
                'application/pdf',
                (int) filesize($finalPath),
                is_array(current_user()) ? (int) current_user()['id'] : null,
            ]
        );

        return [
            'ok' => true,
            'message' => 'A műszaki átadás csomag elkészült: ' . format_bytes((int) filesize($finalPath)) . '.',
            'document_id' => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $exception) {
        if (is_file($finalPath)) {
            unlink($finalPath);
        }

        return [
            'ok' => false,
            'message' => 'A műszaki átadás csomag generálása sikertelen: ' . $exception->getMessage(),
            'document_id' => null,
        ];
    }
}

function generate_connection_request_seal_removal_package(int $requestId): array
{
    $guard = connection_request_mvm_submission_guard_result($requestId);

    if ($guard !== null) {
        return $guard;
    }

    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $missingItems = connection_request_seal_removal_package_missing_items($requestId);

    if ($missingItems !== []) {
        return ['ok' => false, 'message' => 'A plombabontás csomaghoz még hiányzik: ' . implode(', ', $missingItems) . '.', 'document_id' => null];
    }

    $parts = connection_request_seal_removal_package_parts($requestId);

    if ($parts === []) {
        return ['ok' => false, 'message' => 'Nincs összefűzhető plombabontási dokumentum.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/seal-removal-package';
    ensure_storage_dir($targetDir);

    $storedName = 'plombabontas-csomag-' . $requestId . '-' . date('Ymd-His') . '.pdf';
    $finalPath = $targetDir . '/' . $storedName;

    try {
        $result = render_connection_request_complete_package_pdf($parts, $finalPath, 72);

        if (!is_file((string) $result['path'])) {
            return ['ok' => false, 'message' => 'A plombabontás PDF csomag nem készült el.', 'document_id' => null];
        }

        db_query(
            'INSERT INTO `connection_request_documents`
                (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
                 `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $requestId,
                (int) $request['customer_id'],
                'seal_removal_package',
                'Plombabontás csomag',
                $storedName,
                $storedName,
                $finalPath,
                'application/pdf',
                (int) filesize($finalPath),
                is_array(current_user()) ? (int) current_user()['id'] : null,
            ]
        );

        return [
            'ok' => true,
            'message' => 'A plombabontás csomag elkészült: ' . format_bytes((int) filesize($finalPath)) . '.',
            'document_id' => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $exception) {
        if (is_file($finalPath)) {
            unlink($finalPath);
        }

        return [
            'ok' => false,
            'message' => 'A plombabontás csomag generálása sikertelen: ' . $exception->getMessage(),
            'document_id' => null,
        ];
    }
}

function send_connection_request_complete_package_to_customer(int $documentId): array
{
    $document = find_connection_request_document($documentId);

    if ($document === null || (string) ($document['document_type'] ?? '') !== 'complete_package') {
        return ['ok' => false, 'message' => 'A komplett dokumentum nem található.'];
    }

    if (!is_file((string) $document['storage_path'])) {
        return ['ok' => false, 'message' => 'A komplett dokumentum fájlja nem található.'];
    }

    $request = find_connection_request((int) $document['connection_request_id']);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $recipientEmail = trim((string) ($request['email'] ?? ''));
    $recipientName = email_recipient_name($request['requester_name'] ?? '');

    if ($recipientEmail === '') {
        return ['ok' => false, 'message' => 'A komplett dokumentum email nem küldhető, mert hiányzik az ügyfél email címe.'];
    }

    $token = customer_email_thread_token((int) $request['id'], 'complete-package');
    $subject = customer_email_thread_subject(APP_NAME . ' komplett dokumentumcsomag - ' . $request['project_name'], $token);
    $replyAddress = mvm_mail_reply_address();
    $sections = [
        [
            'title' => 'Dokumentum adatai',
            'rows' => [
                ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                ['label' => 'Cím', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                ['label' => 'Fájlméret', 'value' => format_bytes((int) $document['file_size'])],
                ['label' => 'Válaszazonosító', 'value' => $token],
            ],
        ],
    ];

    $actions = [
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->addReplyTo($replyAddress, MAIL_FROM_NAME);
        $mail->Subject = $subject;
        $emailTitle = 'Elkészült a komplett dokumentumcsomag';
        $emailLead = 'A mérőhelyi ügyintézéshez összeállított komplett dokumentumcsomagot csatoltuk. Ha kérdése van, kérjük, válaszoljon erre az emailre, és az üzenet automatikusan ehhez a munkához kerül.';
        apply_branded_email(
            $mail,
            $emailTitle,
            $emailLead,
            $sections,
            $actions,
            $recipientName
        );
        $mail->addAttachment((string) $document['storage_path'], (string) $document['original_name']);
        $mail->send();
        $messageId = method_exists($mail, 'getLastMessageID') ? (string) $mail->getLastMessageID() : '';
        record_customer_email_thread(
            (int) $request['id'],
            $token,
            $recipientEmail,
            $subject,
            'Ügyfél dokumentumcsomag',
            branded_email_text($emailTitle, $emailLead, $sections, $actions, $recipientName),
            branded_email_html($emailTitle, $emailLead, $sections, $actions, $recipientName),
            $messageId !== '' ? $messageId : null
        );

        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, $recipientEmail, $subject, 'sent']);

        return ['ok' => true, 'message' => 'A komplett dokumentumot elküldtük az ügyfélnek.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, $recipientEmail, $subject, 'failed', $exception->getMessage()]
        );

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A komplett dokumentum email küldése sikertelen.'];
    }
}

function connection_request_documents_for_customer(int $customerId): array
{
    if (!db_table_exists('connection_request_documents')) {
        return [];
    }

    return db_query(
        'SELECT d.*, cr.project_name, cr.site_address, cr.site_postal_code, cr.hrsz
         FROM `connection_request_documents` d
         INNER JOIN `connection_requests` cr ON cr.id = d.connection_request_id
         WHERE d.customer_id = ?
         ORDER BY d.created_at DESC, d.id DESC',
        [$customerId]
    )->fetchAll();
}

function find_connection_request_document(int $documentId): ?array
{
    if (!db_table_exists('connection_request_documents')) {
        return null;
    }

    $statement = db_query('SELECT * FROM `connection_request_documents` WHERE `id` = ? LIMIT 1', [$documentId]);
    $document = $statement->fetch();

    return is_array($document) ? $document : null;
}

function delete_connection_request_document(int $documentId, int $requestId): array
{
    $document = find_connection_request_document($documentId);

    if ($document === null || (int) ($document['connection_request_id'] ?? 0) !== $requestId) {
        return ['ok' => false, 'message' => 'A törlendő dokumentum nem található.'];
    }

    db_query('DELETE FROM `connection_request_documents` WHERE `id` = ?', [$documentId]);
    delete_storage_files([(string) ($document['storage_path'] ?? '')]);
    record_connection_request_activity(
        $requestId,
        'file_delete',
        'MVM dokumentum törölve',
        (string) ($document['original_name'] ?? $document['title'] ?? '')
    );

    return ['ok' => true, 'message' => 'A dokumentum törölve.'];
}

function customer_can_view_connection_request_document(array $document): bool
{
    $customer = current_customer();

    if ($customer === null) {
        return false;
    }

    return (int) $document['customer_id'] === (int) $customer['id'];
}

function customer_can_view_connection_request_file(array $file): bool
{
    $customer = current_customer();

    if ($customer === null) {
        return false;
    }

    $request = find_connection_request((int) $file['connection_request_id']);

    return $request !== null && (int) $request['customer_id'] === (int) $customer['id'];
}

function customer_can_edit_connection_request(array $request): bool
{
    $customer = current_customer();

    return $customer !== null
        && (int) $request['customer_id'] === (int) $customer['id']
        && connection_request_is_editable($request);
}

function contractor_can_manage_connection_request(array $request): bool
{
    $user = current_user();

    return is_array($user)
        && current_user_role() === 'general_contractor'
        && (int) ($request['submitted_by_user_id'] ?? 0) === (int) $user['id'];
}

function electrician_can_manage_connection_request(array $request): bool
{
    $user = current_user();

    return is_array($user)
        && current_user_role() === 'electrician'
        && (
            (int) ($request['assigned_electrician_user_id'] ?? 0) === (int) $user['id']
            || (int) ($request['submitted_by_user_id'] ?? 0) === (int) $user['id']
        );
}

function contractor_can_view_connection_request_file(array $file): bool
{
    $user = current_user();

    if (!is_array($user) || current_user_role() !== 'general_contractor') {
        return false;
    }

    $request = find_connection_request((int) $file['connection_request_id']);

    return $request !== null && (int) ($request['submitted_by_user_id'] ?? 0) === (int) $user['id'];
}

function electrician_can_view_connection_request_work_file(array $file): bool
{
    $request = find_connection_request((int) $file['connection_request_id']);

    return $request !== null && electrician_can_manage_connection_request($request);
}

function electrician_can_view_connection_request_file(array $file): bool
{
    $request = find_connection_request((int) $file['connection_request_id']);

    return $request !== null && electrician_can_manage_connection_request($request);
}

function electrician_can_view_connection_request_document(array $document): bool
{
    $request = find_connection_request((int) $document['connection_request_id']);

    return $request !== null && electrician_can_manage_connection_request($request);
}

function connection_request_email_sections(array $request, array $files): array
{
    $sections = [
        [
            'title' => 'Igény összefoglaló',
            'rows' => [
                ['label' => 'Igénytípus', 'value' => connection_request_type_label($request['request_type'] ?? null)],
                ['label' => 'Munka megnevezése', 'value' => $request['project_name'] ?? '-'],
                ['label' => 'Állapot', 'value' => connection_request_status_label((string) ($request['request_status'] ?? 'draft'))],
                ['label' => 'Véglegesítés ideje', 'value' => $request['closed_at'] ?? $request['submitted_at'] ?? '-'],
                ['label' => 'Kivitelezés címe', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                ['label' => 'Helyrajzi szám', 'value' => $request['hrsz'] ?? '-'],
            ],
        ],
    ];

    if (!empty($request['contractor_name'])) {
        $contractorZipCity = trim((string) ($request['contractor_postal_code'] ?? '') . ' ' . (string) ($request['contractor_city'] ?? ''));
        $contractorStreet = trim((string) ($request['contractor_postal_address'] ?? ''));
        $contractorAddress = trim($contractorZipCity . ($contractorZipCity !== '' && $contractorStreet !== '' ? ', ' : '') . $contractorStreet);

        $sections[] = [
            'title' => 'Generálkivitelező adatok',
            'rows' => [
                ['label' => 'Név / cég', 'value' => $request['contractor_name'] ?? '-'],
                ['label' => 'Cégnév', 'value' => $request['contractor_company_name'] ?? '-'],
                ['label' => 'Kapcsolattartó', 'value' => $request['contractor_contact_name'] ?? '-'],
                ['label' => 'Telefon', 'value' => $request['contractor_phone'] ?? '-'],
                ['label' => 'Email', 'value' => $request['contractor_email'] ?? '-'],
                ['label' => 'Cím', 'value' => $contractorAddress],
            ],
        ];
    }

    $customerAddress = trim(
        (string) ($request['postal_code'] ?? '') . ' ' .
        (string) ($request['city'] ?? '') .
        (((string) ($request['postal_address'] ?? '')) !== '' ? ', ' . (string) $request['postal_address'] : '')
    );

    $sections[] = [
        'title' => !empty($request['contractor_name']) ? 'Végügyfél adatok' : 'Ügyfél adatok',
        'rows' => [
            ['label' => 'Név', 'value' => $request['requester_name'] ?? '-'],
            ['label' => 'Születési név', 'value' => $request['birth_name'] ?? '-'],
            ['label' => 'Telefon', 'value' => $request['phone'] ?? '-'],
            ['label' => 'Email', 'value' => $request['email'] ?? '-'],
            ['label' => 'Postacím', 'value' => $customerAddress],
            ['label' => 'Anyja neve', 'value' => $request['mother_name'] ?? '-'],
            ['label' => 'Születési hely', 'value' => $request['birth_place'] ?? '-'],
            ['label' => 'Születési idő', 'value' => $request['birth_date'] ?? '-'],
        ],
    ];

    $sections[] = [
        'title' => 'Műszaki adatok',
        'rows' => [
            ['label' => 'Saját mérő gyári száma', 'value' => $request['meter_serial'] ?? '-'],
            ['label' => 'Fogyasztási hely azonosító', 'value' => $request['consumption_place_id'] ?? '-'],
            ['label' => 'Meglévő teljesítmény mindennapszaki', 'value' => $request['existing_general_power'] ?? '-'],
            ['label' => 'Igényelt teljesítmény mindennapszaki', 'value' => $request['requested_general_power'] ?? '-'],
            ['label' => 'Meglévő teljesítmény H tarifa', 'value' => $request['existing_h_tariff_power'] ?? '-'],
            ['label' => 'Igényelt teljesítmény H tarifa', 'value' => $request['requested_h_tariff_power'] ?? '-'],
            ['label' => 'Meglévő teljesítmény vezérelt', 'value' => $request['existing_controlled_power'] ?? '-'],
            ['label' => 'Igényelt teljesítmény vezérelt', 'value' => $request['requested_controlled_power'] ?? '-'],
            ['label' => 'Megjegyzés', 'value' => $request['notes'] ?? '-'],
        ],
    ];

    $fileItems = [];

    foreach ($files as $file) {
        $fileItems[] = (string) ($file['label'] ?? 'Csatolmány') . ': ' . (string) ($file['original_name'] ?? '-');
    }

    $sections[] = [
        'title' => 'Csatolt fájlok',
        'items' => $fileItems !== [] ? $fileItems : ['Nincs csatolt fájl.'],
    ];

    return $sections;
}

function connection_request_email_body(array $request, array $files): string
{
    return branded_email_text(
        'Új mérőhelyi munkaigény érkezett',
        'Új igénybejelentést rögzítettek a weboldalon. Az adatok és a csatolt fájlok az alábbi összefoglalóban találhatók.',
        connection_request_email_sections($request, $files),
        [['label' => 'Munkák megnyitása', 'url' => absolute_url('/admin/minicrm-import?request=' . (int) $request['id'] . '#portal-work-' . (int) $request['id'])]]
    );
}

function send_connection_request_email(int $requestId, bool $finalized = false): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.'];
    }

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        db_query('UPDATE `connection_requests` SET `email_status` = ?, `email_error` = ? WHERE `id` = ?', ['failed', 'PHPMailer hiányzik.', $requestId]);
        log_admin_notification_email(null, APP_NAME . ' mérőhelyi munkaigény', 'failed', 'PHPMailer hiányzik.');
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $files = connection_request_files($requestId);
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $subjectPrefix = $finalized ? 'végleges mérőhelyi igénybejelentés' : 'mérőhelyi munkaigény';
    $subject = APP_NAME . ' ' . $subjectPrefix . ' - ' . $request['project_name'] . ' - ' . $request['requester_name'];
    $emailTitle = $finalized ? 'Végleges igénybejelentés érkezett' : 'Új mérőhelyi munkaigény érkezett';
    $emailLead = $finalized
        ? 'Az ügyfél lezárta az igényét, ezért ez végleges igénybejelentésként kezelendő. Az adatok és a csatolt fájlok az alábbi összefoglalóban találhatók.'
        : 'Új igénybejelentést rögzítettek a weboldalon. Az adatok és a csatolt fájlok az alábbi összefoglalóban találhatók.';
    $emailSections = connection_request_email_sections($request, $files);
    $emailActions = [
        ['label' => 'Munkák megnyitása', 'url' => absolute_url('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId)],
    ];

    if (!empty($request['contractor_name'])) {
        $subject .= ' - ' . $request['contractor_name'];
    }

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        add_admin_notification_recipients($mail);
        $mail->addReplyTo((string) $request['email'], (string) $request['requester_name']);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions);

        foreach ($files as $file) {
            if (is_file((string) $file['storage_path'])) {
                $mail->addAttachment((string) $file['storage_path'], (string) $file['original_name']);
            }
        }

        $mail->send();

        db_query('UPDATE `connection_requests` SET `email_status` = ?, `email_error` = NULL WHERE `id` = ?', ['sent', $requestId]);
        log_admin_notification_email(null, $subject, 'sent');

        return ['ok' => true, 'message' => $finalized ? 'Az igényt lezártuk, és végleges igénybejelentésként elküldtük az adminnak.' : 'Az igény rögzítve és elküldve.'];
    } catch (Throwable $exception) {
        db_query('UPDATE `connection_requests` SET `email_status` = ?, `email_error` = ? WHERE `id` = ?', ['failed', $exception->getMessage(), $requestId]);
        log_admin_notification_email(null, $subject, 'failed', $exception->getMessage());

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Az igény rögzítve, de az email küldése sikertelen.'];
    }
}

function minicrm_headers(): array
{
    return [
        'Adatlap: Hol talált ránk? ',
        'Adatlap: Státusz',
        'Cég: Név',
        'Cég: Email',
        'Cég: Telefon',
        'Cég: Összefoglaló',
        'Cég: Weboldal',
        'Cég: Számlaszám',
        'Cég: SWIFT kód',
        'Cég: Cégjegyzék',
        'Cég: Adószám',
        'Cég: Iparág',
        'Cég: Régió',
        'Cég: Alkalmazottak száma',
        'Személy1: Vezetéknév',
        'Személy1: Keresztnév',
        'Személy1: Email',
        'Személy1: Telefon',
        'Személy1: Beosztás',
        'Személy2: Vezetéknév',
        'Személy2: Keresztnév',
        'Személy2: Email',
        'Személy2: Telefon',
        'Személy2: Beosztás',
        'Cím1: Típus',
        'Cím1: Ország',
        'Cím1: Irányítószám',
        'Cím1: Település',
        'Cím1: Cím',
        'Cím2: Típus',
        'Cím2: Ország',
        'Cím2: Irányítószám',
        'Cím2: Település',
        'Cím2: Cím',
    ];
}

function minicrm_row(array $customer): array
{
    [$lastName, $firstName] = split_full_name((string) $customer['requester_name']);
    $companyName = !empty($customer['company_name']) ? (string) $customer['company_name'] : (string) $customer['requester_name'];
    $mailingAddress = !empty($customer['mailing_address']) ? (string) $customer['mailing_address'] : (string) $customer['postal_address'];

    return [
        $customer['source'] ?: 'Weboldal',
        $customer['status'] ?: 'Ajánlatkészítés',
        $companyName,
        $customer['email'],
        $customer['phone'],
        $customer['notes'] ?: APP_NAME . ' ügyféladatlapból exportálva.',
        '',
        '',
        '',
        '',
        $customer['tax_number'] ?: '',
        '',
        '',
        '',
        $lastName,
        $firstName,
        $customer['email'],
        $customer['phone'],
        'Kapcsolattarto',
        '',
        '',
        '',
        '',
        '',
        'Postai',
        'Magyarorszag',
        $customer['postal_code'],
        $customer['city'],
        $customer['postal_address'],
        'Levelezesi',
        'Magyarorszag',
        $customer['postal_code'],
        $customer['city'],
        $mailingAddress,
    ];
}

function generate_minicrm_export(): array
{
    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        return generate_minicrm_csv_export();
    }

    ensure_storage_dir(MINICRM_EXPORT_PATH);

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Import Minta');
    $sheet->fromArray(minicrm_headers(), null, 'A1');

    $customers = all_customers();
    $row = 2;

    foreach ($customers as $customer) {
        $sheet->fromArray(minicrm_row($customer), null, 'A' . $row);
        $row++;
    }

    $path = MINICRM_EXPORT_PATH . '/minicrm-export-' . date('Ymd-His') . '.xlsx';
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save($path);

    $user = current_user();
    db_query(
        'INSERT INTO `minicrm_exports` (`file_path`, `row_count`, `created_by`) VALUES (?, ?, ?)',
        [$path, count($customers), is_array($user) ? (int) $user['id'] : null]
    );

    return ['ok' => true, 'message' => 'MiniCRM export elkeszult.', 'path' => $path, 'rows' => count($customers)];
}

function generate_minicrm_csv_export(): array
{
    ensure_storage_dir(MINICRM_EXPORT_PATH);

    $customers = all_customers();
    $path = MINICRM_EXPORT_PATH . '/minicrm-export-' . date('Ymd-His') . '.csv';
    $handle = fopen($path, 'wb');

    if ($handle === false) {
        return ['ok' => false, 'message' => 'Nem sikerült létrehozni az export fájlt.', 'path' => null];
    }

    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, minicrm_headers(), ';');

    foreach ($customers as $customer) {
        fputcsv($handle, minicrm_row($customer), ';');
    }

    fclose($handle);

    $user = current_user();
    db_query(
        'INSERT INTO `minicrm_exports` (`file_path`, `row_count`, `created_by`) VALUES (?, ?, ?)',
        [$path, count($customers), is_array($user) ? (int) $user['id'] : null]
    );

    return [
        'ok' => true,
        'message' => 'MiniCRM CSV export elkeszult. XLSX exporthoz toltsd fel a Composer vendor mappat.',
        'path' => $path,
        'rows' => count($customers),
    ];
}

function minicrm_import_schema_errors(): array
{
    $errors = [];

    if (!db_table_exists('minicrm_import_batches')) {
        $errors[] = 'Hiányzik a minicrm_import_batches tábla.';
    }

    if (!db_table_exists('minicrm_work_items')) {
        $errors[] = 'Hiányzik a minicrm_work_items tábla.';
    }

    if (!db_table_exists('minicrm_work_item_files')) {
        $errors[] = 'Hiányzik a minicrm_work_item_files tábla.';
    }

    if (!db_table_exists('minicrm_customer_profiles')) {
        $errors[] = 'Hianyzik a minicrm_customer_profiles tabla.';
    }

    foreach (['person_name', 'person_email', 'person_phone', 'person_consent'] as $column) {
        if (db_table_exists('minicrm_customer_profiles') && !db_column_exists('minicrm_customer_profiles', $column)) {
            $errors[] = 'Hianyzik a minicrm_customer_profiles.' . $column . ' oszlop.';
        }
    }

    return array_merge($errors, work_archive_schema_errors());
}

function minicrm_import_document_column_map(): array
{
    return [
        5 => 'Meghatalmazás feltöltése',
        6 => 'Tulajdoni lap',
        7 => 'Földhivatali térképmásolat',
        8 => 'Tulajdonosi hozzájáruló nyilatkozat',
        9 => 'Mérőállás kivitelezés előtt',
        10 => 'Mérő kismegszakítóval',
        11 => 'Mérőállás egyéb mérőről',
        12 => 'Egyéb mérő kismegszakítóval',
        13 => 'Plomba előtte 1',
        14 => 'Plomba előtte 2',
        15 => 'Plomba előtte 3',
        16 => 'Plomba előtte 4',
        17 => 'Mérő közelről',
        18 => 'Mérőről távolról',
        19 => 'Fotó a tetőtartóról',
        20 => 'Fotó a villanyoszlopról',
        21 => 'Plomba 1',
        22 => 'Plomba 2',
        23 => 'Plomba 3',
        24 => 'Plomba 4',
        25 => 'Plomba 5',
        26 => 'Fotó a mérőről - közelről',
        27 => 'Fotó a mérőről - távolról',
        28 => 'Fotó egyéb mérőről - közelről',
        29 => 'Fotó a tetőtartóról',
        30 => 'Fotó a tetőtartótól 2',
        31 => 'Fotó a csatlakozó vezetékről a tetőtartónál',
        32 => 'Fotó a villanyoszlopról',
        59 => 'Skicc feltöltése',
    ];
}

function minicrm_import_clean(mixed $value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    $value = trim((string) $value);
    $value = preg_replace('/\s+/u', ' ', $value);

    return is_string($value) ? $value : '';
}

function minicrm_import_nullable(string $value): ?string
{
    $value = trim($value);

    return $value !== '' ? $value : null;
}

function minicrm_import_value(array $values, int $column): string
{
    return minicrm_import_clean($values[$column - 1] ?? '');
}

function minicrm_source_id_key(string $sourceId): string
{
    return strtolower(trim($sourceId));
}

function minicrm_import_key(string $value): string
{
    $value = minicrm_import_lower(trim($value));
    $value = strtr((string) $value, [
        "\u{00E1}" => 'a',
        "\u{00E9}" => 'e',
        "\u{00ED}" => 'i',
        "\u{00F3}" => 'o',
        "\u{00F6}" => 'o',
        "\u{0151}" => 'o',
        "\u{00FA}" => 'u',
        "\u{00FC}" => 'u',
        "\u{0171}" => 'u',
    ]);
    $value = strtr((string) $value, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ö' => 'o',
        'ő' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ű' => 'u',
    ]);
    $value = preg_replace('/^adatlap:\s*/u', '', $value);
    $value = strtr((string) $value, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ö' => 'o',
        'ő' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ű' => 'u',
    ]);
    $value = strtr((string) $value, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ö' => 'o',
        'ő' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ű' => 'u',
    ]);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', (string) $value);

    return trim((string) preg_replace('/\s+/', ' ', (string) $value));
}

function minicrm_import_header_label(string $header): string
{
    $header = trim(preg_replace('/^Adatlap:\s*/u', '', $header) ?? $header);

    return $header !== '' ? $header : 'Oszlop';
}

function minicrm_import_header_map(array $headers): array
{
    $map = [];

    foreach ($headers as $index => $header) {
        $key = minicrm_import_key((string) $header);

        if ($key === '') {
            continue;
        }

        $map[$key][] = (int) $index;
    }

    return $map;
}

function minicrm_import_value_by_labels(array $headers, array $values, array $labels, bool $last = false): string
{
    $map = minicrm_import_header_map($headers);

    foreach ($labels as $label) {
        $indexes = $map[minicrm_import_key((string) $label)] ?? [];

        if ($last) {
            $indexes = array_reverse($indexes);
        }

        foreach ($indexes as $index) {
            $value = minicrm_import_clean($values[$index] ?? '');

            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function minicrm_import_values_for_labels(array $headers, array $values, array $labels): array
{
    $map = minicrm_import_header_map($headers);
    $found = [];

    foreach ($labels as $label) {
        foreach ($map[minicrm_import_key((string) $label)] ?? [] as $index) {
            $value = minicrm_import_clean($values[$index] ?? '');

            if ($value !== '') {
                $found[] = $value;
            }
        }
    }

    return $found;
}

function minicrm_import_selected_labels(array $headers, array $values, array $labels): array
{
    $selected = [];

    foreach ($labels as $label) {
        $value = minicrm_import_value_by_labels($headers, $values, [$label]);

        if ($value === '') {
            continue;
        }

        if (in_array(minicrm_import_key($value), ['nem', 'no', '0'], true)) {
            continue;
        }

        $selected[] = (string) $label;
    }

    return $selected;
}

function minicrm_import_lower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function minicrm_import_detect_request_type(string $workType, string $workKind, string $cardName): string
{
    $text = minicrm_import_lower($workType . ' ' . $workKind . ' ' . $cardName);

    if (str_contains($text, 'h tarifa') || str_contains($text, '"h" tarifa')) {
        return 'h_tariff';
    }

    if (str_contains($text, '1-3') || str_contains($text, '3 fáz') || str_contains($text, '3 faz')) {
        return 'phase_upgrade';
    }

    if (str_contains($text, 'teljesítmény') || str_contains($text, 'teljesitmeny')) {
        return 'power_increase';
    }

    if (str_contains($text, 'új bekapcsol') || str_contains($text, 'uj bekapcsol') || str_contains($text, 'sötét cím') || str_contains($text, 'sotet cim')) {
        return 'new_connection';
    }

    if (str_contains($text, 'szabvĂˇny') || str_contains($text, 'szabvany')) {
        return 'standardization';
    }

    return '';
}

function minicrm_import_build_site_address(array $headers, array $values): string
{
    $city = minicrm_import_value_by_labels($headers, $values, ['VĂˇros']);
    $street = minicrm_import_value_by_labels($headers, $values, ['Utca']);
    $houseNumber = minicrm_import_value_by_labels($headers, $values, ['HĂˇzszĂˇm']);
    $floorDoor = minicrm_import_value_by_labels($headers, $values, ['Emelet, AjtĂł']);
    $usageAddress = minicrm_import_value_by_labels($headers, $values, ['FelhasznĂˇlĂˇsi cĂ­m (ir. nĂ©lkĂĽl)', 'FelhasznĂˇlĂˇsi cĂ­m']);
    $city = $city !== '' ? $city : minicrm_import_value_by_labels($headers, $values, ['Varos']);
    $houseNumber = $houseNumber !== '' ? $houseNumber : minicrm_import_value_by_labels($headers, $values, ['Hazszam']);
    $floorDoor = $floorDoor !== '' ? $floorDoor : minicrm_import_value_by_labels($headers, $values, ['Emelet Ajto']);
    $usageAddress = $usageAddress !== '' ? $usageAddress : minicrm_import_value_by_labels($headers, $values, ['Felhasznalasi cim ir nelkul', 'Felhasznalasi cim']);
    $streetLine = trim(implode(' ', array_filter([$street, $houseNumber, $floorDoor], static fn (string $part): bool => $part !== '')));

    if ($streetLine !== '') {
        return $city !== '' && !str_contains(minicrm_import_lower($streetLine), minicrm_import_lower($city))
            ? $city . ', ' . $streetLine
            : $streetLine;
    }

    return $usageAddress;
}

function minicrm_import_payload(array $headers, array $values): array
{
    $columns = [];
    $count = max(count($headers), count($values));

    for ($index = 0; $index < $count; $index++) {
        $header = minicrm_import_clean($headers[$index] ?? ('Oszlop ' . ($index + 1)));
        $value = minicrm_import_clean($values[$index] ?? '');

        if ($value === '') {
            continue;
        }

        $columns[] = [
            'index' => $index + 1,
            'header' => $header,
            'value' => $value,
        ];
    }

    return ['columns' => $columns];
}

function minicrm_import_document_links(array $headers, array $values): array
{
    $links = [];

    foreach (minicrm_import_document_column_map() as $column => $label) {
        $value = minicrm_import_value($values, $column);

        if ($value === '') {
            continue;
        }

        $links[] = [
            'label' => $label,
            'value' => $value,
            'is_url' => str_starts_with($value, 'http://') || str_starts_with($value, 'https://'),
        ];
    }

    foreach ($headers as $index => $header) {
        $label = minicrm_import_header_label((string) $header);
        $value = minicrm_import_clean($values[$index] ?? '');

        if ($value === '') {
            continue;
        }

        $isUrl = str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
        $labelKey = minicrm_import_key($label);
        $looksLikeDocument = $isUrl || preg_match('/(feltoltes|foto|kep|lap|terv|nyilatkozat|meghatalmazas|terkep|skicc|hibalap|ugyinditas|beavatkozasi|muszaki|fedlap|kivitelezoi|dokumentum|pdf|villanyszamla|szamla|klima|matrica|papir|alairt|engedely|hozzajarulo|tulajdoni|foldhivatali)/', $labelKey);

        if (!$looksLikeDocument) {
            continue;
        }

        $links[] = [
            'label' => $label,
            'value' => $value,
            'is_url' => $isUrl,
        ];
    }

    return minicrm_import_unique_items($links, ['label', 'value']);
}

function minicrm_import_json(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
}

function minicrm_import_row_data(array $headers, array $values): array
{
    $cardName = minicrm_import_value_by_labels($headers, $values, ['NĂ©v']);
    $customerName = minicrm_import_value_by_labels($headers, $values, ['NĂ©v'], true);
    $workType = minicrm_import_value_by_labels($headers, $values, [
        'Munka tĂ­pusa',
        'MVM DĂ©mĂˇsz munka tĂ­pusa',
        'Munka rĂ¶vid leĂ­rĂˇsa/ IgĂ©nyelt amper',
    ]);
    $requestTypeSignals = minicrm_import_selected_labels($headers, $values, [
        'Ăšj fogyasztĂł',
        '1-3 fĂˇzisra ĂˇtĂˇllĂˇs',
        'TeljesĂ­tmĂ©ny nĂ¶velĂ©s',
        '"H" tarifa vagy mellĂ©szerelĂ©s',
        'CsatlakozĂł berendezĂ©s helyreĂˇllĂ­tĂˇsa',
    ]);

    if ($workType === '' && $requestTypeSignals !== []) {
        $workType = implode(', ', $requestTypeSignals);
    }

    $workKind = minicrm_import_value_by_labels($headers, $values, ['Munka jellege', 'MĂ©rĹ‘helyi munkĂˇlatok']);
    $siteAddress = minicrm_import_build_site_address($headers, $values);
    $meterSerials = minicrm_import_values_for_labels($headers, $values, ['MĂ©rĹ‘Ăłra gyĂˇri szĂˇma MN', 'Ăšj mĂ©rĹ‘ gyĂˇriszĂˇma']);
    $meterSerial = implode(', ', array_values(array_unique($meterSerials)));

    return [
        'source_id' => substr(minicrm_import_value_by_labels($headers, $values, ['AzonosĂ­tĂł']), 0, 80),
        'card_name' => $cardName !== '' ? $cardName : ($customerName !== '' ? 'MiniCRM munka - ' . $customerName : 'MiniCRM munka'),
        'customer_name' => $customerName !== '' ? $customerName : $cardName,
        'responsible' => minicrm_import_value_by_labels($headers, $values, ['FelelĹ‘s', 'MVM felelĹ‘s']),
        'minicrm_status' => minicrm_import_value_by_labels($headers, $values, ['StĂˇtusz', 'Folyamat stĂˇtusz']),
        'work_type' => $workType,
        'work_kind' => $workKind,
        'request_type' => minicrm_import_detect_request_type($workType, $workKind, $cardName),
        'date_value' => minicrm_import_value_by_labels($headers, $values, ['DĂˇtum', 'Egyeztetett idĹ‘pont']),
        'submitted_date' => minicrm_import_value_by_labels($headers, $values, ['LeadĂˇs dĂˇtuma', 'KĂ©szrejelentĂ©s dĂˇtum', 'MVM DĂ©mĂˇsz bekĂ¶tĂ©si dĂˇtum']),
        'birth_name' => minicrm_import_value_by_labels($headers, $values, ['SzĂĽletĂ©si nĂ©v']),
        'birth_place' => minicrm_import_value_by_labels($headers, $values, ['SzĂĽletĂ©si hely']),
        'birth_date' => minicrm_import_value_by_labels($headers, $values, ['SzĂĽletĂ©si idĹ‘']),
        'mother_name' => minicrm_import_value_by_labels($headers, $values, ['Anyja neve']),
        'mailing_address' => minicrm_import_value_by_labels($headers, $values, ['ĂśgyfĂ©l levelezĂ©si cĂ­me']),
        'postal_code' => minicrm_import_value_by_labels($headers, $values, ['IrĂˇnyĂ­tĂł szĂˇm', 'IrĂˇnyĂ­tĂłszĂˇm']),
        'city' => minicrm_import_value_by_labels($headers, $values, ['VĂˇros']),
        'site_address' => $siteAddress,
        'street' => minicrm_import_value_by_labels($headers, $values, ['Utca']),
        'house_number' => minicrm_import_value_by_labels($headers, $values, ['HĂˇzszĂˇm']),
        'floor_door' => minicrm_import_value_by_labels($headers, $values, ['Emelet, AjtĂł']),
        'hrsz' => minicrm_import_value_by_labels($headers, $values, ['Helyrajzi szĂˇm']),
        'consumption_place_id' => minicrm_import_value_by_labels($headers, $values, ['FelhasznĂˇlĂˇsi hely azonosĂ­tĂł']),
        'meter_serial' => $meterSerial,
        'controlled_meter_serial' => minicrm_import_value_by_labels($headers, $values, ['MĂ©rĹ‘Ăłra gyĂˇri szĂˇma vezĂ©relt']),
        'wire_type' => minicrm_import_value_by_labels($headers, $values, ['VezetĂ©k tĂ­pusa', 'KĂˇbel tĂ­pusa']),
        'meter_cabinet' => minicrm_import_value_by_labels($headers, $values, ['MĂ©rĹ‘szekrĂ©ny', 'SzekrĂ©ny tĂ­pusa']),
        'meter_location' => minicrm_import_value_by_labels($headers, $values, ['MĂ©rĹ‘Ăłra helye jelenleg']),
        'pole_type' => minicrm_import_value_by_labels($headers, $values, ['Oszlop tĂ­pusa']),
        'wire_note' => minicrm_import_value_by_labels($headers, $values, ['KockĂˇs papĂ­r vezetĂ©k', 'SzĂ¶veg']),
        'cabinet_note' => minicrm_import_value_by_labels($headers, $values, ['KockĂˇs papĂ­r szekrĂ©ny']),
        'document_links_json' => minicrm_import_json(minicrm_import_document_links($headers, $values)),
        'raw_payload' => minicrm_import_json(minicrm_import_payload($headers, $values)),
    ];
}

function minicrm_import_unique_items(array $items, array $keys): array
{
    $seen = [];
    $unique = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $signatureParts = [];

        foreach ($keys as $key) {
            $signatureParts[] = minicrm_import_key((string) ($item[$key] ?? ''));
        }

        $signature = implode('|', $signatureParts);

        if ($signature === '|' || isset($seen[$signature])) {
            continue;
        }

        $seen[$signature] = true;
        $unique[] = $item;
    }

    return $unique;
}

function minicrm_import_document_links_from_headers(array $headers, array $values): array
{
    $links = [];

    foreach ($headers as $index => $header) {
        $label = minicrm_import_header_label((string) $header);
        $value = minicrm_import_clean($values[$index] ?? '');

        if ($value === '') {
            continue;
        }

        $isUrl = str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
        $labelKey = minicrm_import_key($label);
        $looksLikeDocument = $isUrl || preg_match('/(feltoltes|foto|kep|lap|terv|nyilatkozat|meghatalmazas|terkep|skicc|hibalap|ugyinditas|beavatkozasi|muszaki|fedlap|kivitelezoi|dokumentum|pdf|villanyszamla|szamla|klima|matrica|papir|alairt|engedely|hozzajarulo|tulajdoni|foldhivatali)/', $labelKey);

        if (!$looksLikeDocument) {
            continue;
        }

        $links[] = [
            'label' => $label,
            'value' => $value,
            'is_url' => $isUrl,
        ];
    }

    return minicrm_import_unique_items($links, ['label', 'value']);
}

function minicrm_import_row_data_from_headers(array $headers, array $values): array
{
    $cardName = minicrm_import_value_by_labels($headers, $values, ['Nev']);
    $customerName = minicrm_import_value_by_labels($headers, $values, ['Nev'], true);
    $workType = minicrm_import_value_by_labels($headers, $values, [
        'Munka tipusa',
        'MVM Demasz munka tipusa',
        'Munka rovid leirasa Igenyelt amper',
    ]);
    $requestTypeSignals = minicrm_import_selected_labels($headers, $values, [
        'Uj fogyaszto',
        '1 3 fazisra atallas',
        'Teljesitmeny noveles',
        'H tarifa vagy melleszereles',
        'Csatlakozo berendezes helyreallitasa',
    ]);

    if ($workType === '' && $requestTypeSignals !== []) {
        $workType = implode(', ', $requestTypeSignals);
    }

    $workKind = minicrm_import_value_by_labels($headers, $values, ['Munka jellege', 'Merohelyi munkalatok']);
    $siteAddress = minicrm_import_build_site_address($headers, $values);
    $meterSerials = minicrm_import_values_for_labels($headers, $values, ['Meroora gyari szama MN', 'Uj mero gyariszama']);
    $meterSerial = implode(', ', array_values(array_unique($meterSerials)));

    return [
        'source_id' => substr(minicrm_import_value_by_labels($headers, $values, ['Azonosito']), 0, 80),
        'card_name' => $cardName !== '' ? $cardName : ($customerName !== '' ? 'MiniCRM munka - ' . $customerName : 'MiniCRM munka'),
        'customer_name' => $customerName !== '' ? $customerName : $cardName,
        'responsible' => minicrm_import_value_by_labels($headers, $values, ['Felelos', 'MVM felelos']),
        'minicrm_status' => minicrm_import_value_by_labels($headers, $values, ['Statusz', 'Folyamat statusz']),
        'work_type' => $workType,
        'work_kind' => $workKind,
        'request_type' => minicrm_import_detect_request_type($workType, $workKind, $cardName),
        'date_value' => minicrm_import_value_by_labels($headers, $values, ['Datum', 'Egyeztetett idopont']),
        'submitted_date' => minicrm_import_value_by_labels($headers, $values, ['Leadas datuma', 'Keszrejelentes datum', 'MVM Demasz bekotesi datum']),
        'birth_name' => minicrm_import_value_by_labels($headers, $values, ['Szuletesi nev']),
        'birth_place' => minicrm_import_value_by_labels($headers, $values, ['Szuletesi hely']),
        'birth_date' => minicrm_import_value_by_labels($headers, $values, ['Szuletesi ido']),
        'mother_name' => minicrm_import_value_by_labels($headers, $values, ['Anyja neve']),
        'mailing_address' => minicrm_import_value_by_labels($headers, $values, ['Ugyfel levelezesi cime']),
        'postal_code' => minicrm_import_value_by_labels($headers, $values, ['Iranyito szam', 'Iranyitoszam']),
        'city' => minicrm_import_value_by_labels($headers, $values, ['Varos']),
        'site_address' => $siteAddress,
        'street' => minicrm_import_value_by_labels($headers, $values, ['Utca']),
        'house_number' => minicrm_import_value_by_labels($headers, $values, ['Hazszam']),
        'floor_door' => minicrm_import_value_by_labels($headers, $values, ['Emelet Ajto']),
        'hrsz' => minicrm_import_value_by_labels($headers, $values, ['Helyrajzi szam']),
        'consumption_place_id' => minicrm_import_value_by_labels($headers, $values, ['Felhasznalasi hely azonosito']),
        'meter_serial' => $meterSerial,
        'controlled_meter_serial' => minicrm_import_value_by_labels($headers, $values, ['Meroora gyari szama vezerelt']),
        'wire_type' => minicrm_import_value_by_labels($headers, $values, ['Vezetek tipusa', 'Kabel tipusa']),
        'meter_cabinet' => minicrm_import_value_by_labels($headers, $values, ['Meroszekreny', 'Szekreny tipusa']),
        'meter_location' => minicrm_import_value_by_labels($headers, $values, ['Meroora helye jelenleg']),
        'pole_type' => minicrm_import_value_by_labels($headers, $values, ['Oszlop tipusa']),
        'wire_note' => minicrm_import_value_by_labels($headers, $values, ['Kockas papir vezetek', 'Szoveg']),
        'cabinet_note' => minicrm_import_value_by_labels($headers, $values, ['Kockas papir szekreny']),
        'document_links_json' => minicrm_import_json(minicrm_import_document_links_from_headers($headers, $values)),
        'raw_payload' => minicrm_import_json(minicrm_import_payload($headers, $values)),
    ];
}

function minicrm_import_json_list(string|null $json): array
{
    $decoded = json_decode((string) $json, true);

    return is_array($decoded) ? $decoded : [];
}

function minicrm_import_merge_payload_json(?string $existingJson, string $newJson): string
{
    $existing = minicrm_import_json_list($existingJson)['columns'] ?? [];
    $new = minicrm_import_json_list($newJson)['columns'] ?? [];
    $merged = [];
    $seen = [];

    foreach (array_merge(is_array($existing) ? $existing : [], is_array($new) ? $new : []) as $column) {
        if (!is_array($column)) {
            continue;
        }

        $header = minicrm_import_header_label((string) ($column['header'] ?? ''));
        $value = minicrm_import_clean($column['value'] ?? '');

        if ($value === '') {
            continue;
        }

        $signature = minicrm_import_key($header) . '|' . minicrm_import_key($value);

        if (isset($seen[$signature])) {
            continue;
        }

        $seen[$signature] = true;
        $merged[] = [
            'index' => count($merged) + 1,
            'header' => $header,
            'value' => $value,
        ];
    }

    return minicrm_import_json(['columns' => $merged]);
}

function minicrm_import_merge_document_json(?string $existingJson, string $newJson): string
{
    $existing = minicrm_import_json_list($existingJson);
    $new = minicrm_import_json_list($newJson);

    return minicrm_import_json(minicrm_import_unique_items(array_merge($existing, $new), ['label', 'value']));
}

function minicrm_import_merge_row_data(array $data, ?array $existing): array
{
    if ($existing === null) {
        return $data;
    }

    foreach ($data as $key => $value) {
        if (in_array($key, ['source_id', 'batch_id'], true)) {
            continue;
        }

        if (in_array($key, ['document_links_json', 'raw_payload'], true)) {
            continue;
        }

        if (minicrm_import_clean($value) === '' && !empty($existing[$key])) {
            $data[$key] = (string) $existing[$key];
        }
    }

    if (($data['request_type'] ?? '') === '' && !empty($existing['request_type'])) {
        $data['request_type'] = (string) $existing['request_type'];
    }

    $data['document_links_json'] = minicrm_import_merge_document_json($existing['document_links_json'] ?? null, (string) $data['document_links_json']);
    $data['raw_payload'] = minicrm_import_merge_payload_json($existing['raw_payload'] ?? null, (string) $data['raw_payload']);

    return $data;
}

function minicrm_import_upload(array $file): array
{
    if (minicrm_import_schema_errors() !== []) {
        return ['ok' => false, 'message' => 'Előbb futtasd le a database/minicrm_import.sql fájlt phpMyAdminban.'];
    }

    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        return ['ok' => false, 'message' => 'A PhpSpreadsheet nincs telepítve, ezért az Excel import nem futtatható.'];
    }

    if (!uploaded_file_is_present($file)) {
        return ['ok' => false, 'message' => 'Válassz ki egy MiniCRM Excel fájlt.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'A fájl feltöltése sikertelen.'];
    }

    if (($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
        return ['ok' => false, 'message' => 'Túl nagy fájl. Maximum 8 MB engedélyezett.'];
    }

    $originalName = (string) ($file['name'] ?? 'minicrm-import.xlsx');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['xls', 'xlsx'], true)) {
        return ['ok' => false, 'message' => 'Csak XLS vagy XLSX fájl importálható.'];
    }

    return import_minicrm_workbook((string) $file['tmp_name'], $originalName);
}

function minicrm_import_uploads(array $files): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $uploadedFiles = array_values(array_filter(
        uploaded_files_for_key($files, 'minicrm_files'),
        static fn (?array $file): bool => uploaded_file_is_present($file)
    ));

    if ($uploadedFiles === []) {
        $uploadedFiles = array_values(array_filter(
            uploaded_files_for_key($files, 'minicrm_file'),
            static fn (?array $file): bool => uploaded_file_is_present($file)
        ));
    }

    if ($uploadedFiles === []) {
        return ['ok' => false, 'message' => 'Válassz ki legalább egy MiniCRM Excel fájlt.'];
    }

    $successCount = 0;
    $rows = 0;
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $errors = 0;
    $failed = [];

    foreach ($uploadedFiles as $file) {
        $result = minicrm_import_upload($file);
        $originalName = (string) ($file['name'] ?? 'MiniCRM Excel');

        if (!($result['ok'] ?? false)) {
            $failed[] = $originalName . ': ' . (string) ($result['message'] ?? 'sikertelen import');
            continue;
        }

        $successCount++;
        $rows += (int) ($result['rows'] ?? 0);
        $imported += (int) ($result['imported'] ?? 0);
        $updated += (int) ($result['updated'] ?? 0);
        $skipped += (int) ($result['skipped'] ?? 0);
        $errors += (int) ($result['errors'] ?? 0);
    }

    if ($successCount === 0) {
        return ['ok' => false, 'message' => implode(' ', $failed)];
    }

    $message = 'MiniCRM import kész: ' . $successCount . ' fájl, ' . $rows . ' feldolgozott sor, '
        . $imported . ' új, ' . $updated . ' frissített, ' . $skipped . ' kihagyott, ' . $errors . ' hibás sor.';

    if ($failed !== []) {
        $message .= ' Nem importált fájlok: ' . implode(' ', $failed);
    }

    if (electrician_schema_errors() === []) {
        $assignmentResult = minicrm_assign_imported_work_items_to_electricians();

        if ($assignmentResult['ok'] ?? false) {
            $message .= ' ' . (string) ($assignmentResult['message'] ?? '');
        }
    }

    return [
        'ok' => true,
        'message' => $message,
        'rows' => $rows,
        'imported' => $imported,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors,
        'files' => $successCount,
        'failed' => $failed,
    ];
}

function minicrm_customer_profile_date_value(mixed $value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if (is_numeric($value) && (float) $value > 20000 && class_exists('\\PhpOffice\\PhpSpreadsheet\\Shared\\Date')) {
        try {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        } catch (Throwable) {
            return minicrm_import_clean($value);
        }
    }

    return minicrm_import_clean($value);
}

function minicrm_customer_profile_project_id(string $url): string
{
    preg_match('~/Project-\d+/(\d+)(?:/|$)~i', $url, $matches);

    return (string) ($matches[1] ?? '');
}

function minicrm_customer_profile_row_data(array $headers, array $values): array
{
    $cardUrl = minicrm_import_value_by_labels($headers, $values, ['Adatlap Url']);

    return [
        'source_id' => substr(minicrm_import_value_by_labels($headers, $values, ['Azonosito']), 0, 80),
        'project_id' => minicrm_customer_profile_project_id($cardUrl),
        'card_name' => minicrm_import_value_by_labels($headers, $values, ['Nev']),
        'responsible' => minicrm_import_value_by_labels($headers, $values, ['Felelos']),
        'minicrm_status' => minicrm_import_value_by_labels($headers, $values, ['Statusz']),
        'status_group' => minicrm_import_value_by_labels($headers, $values, ['Statuszcsoport']),
        'status_updated_at' => minicrm_customer_profile_date_value(minicrm_import_value_by_labels($headers, $values, ['Statusz utoljara modositva'])),
        'visibility' => minicrm_import_value_by_labels($headers, $values, ['Lathatosag']),
        'created_by_name' => minicrm_import_value_by_labels($headers, $values, ['Rogzito neve']),
        'created_date' => minicrm_customer_profile_date_value(minicrm_import_value_by_labels($headers, $values, ['Rogzites datuma'])),
        'modified_by_name' => minicrm_import_value_by_labels($headers, $values, ['Modosito neve']),
        'modified_date' => minicrm_customer_profile_date_value(minicrm_import_value_by_labels($headers, $values, ['Modositas datuma'])),
        'card_url' => $cardUrl,
        'minicrm_imported_at' => minicrm_customer_profile_date_value(minicrm_import_value_by_labels($headers, $values, ['Importalas datuma'])),
        'person_type' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Tipus']),
        'person_name' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Nev']),
        'person_first_name' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Keresztnev']),
        'person_last_name' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Vezeteknev']),
        'person_email' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Email']),
        'person_phone' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Telefon']),
        'person_summary' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Osszefoglalo']),
        'person_created_by_name' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Rogzito neve']),
        'person_created_date' => minicrm_customer_profile_date_value(minicrm_import_value_by_labels($headers, $values, ['Szemely1 Rogzites datuma'])),
        'person_modified_by_name' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Modosito neve']),
        'person_modified_date' => minicrm_customer_profile_date_value(minicrm_import_value_by_labels($headers, $values, ['Szemely1 Utolso modositas datuma'])),
        'person_position' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Beosztas']),
        'person_website' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Weboldal']),
        'person_consent' => minicrm_import_value_by_labels($headers, $values, ['Szemely1 Adatkezelesi hozzajarulas']),
        'raw_payload' => minicrm_import_json(minicrm_import_payload($headers, $values)),
    ];
}

function minicrm_customer_profile_phone_key(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?: '';
}

function minicrm_customer_profile_customer_id(array $data): ?int
{
    $sourceId = trim((string) ($data['source_id'] ?? ''));

    if ($sourceId !== '' && db_table_exists('minicrm_connection_request_links')) {
        $customerId = db_query(
            'SELECT `customer_id` FROM `minicrm_connection_request_links` WHERE LOWER(`source_id`) = LOWER(?) LIMIT 1',
            [$sourceId]
        )->fetchColumn();

        if ($customerId !== false && (int) $customerId > 0) {
            return (int) $customerId;
        }

        $linkedCustomerId = db_query(
            'SELECT l.`customer_id`
             FROM `minicrm_work_items` w
             INNER JOIN `minicrm_connection_request_links` l ON l.`work_item_id` = w.`id`
             WHERE LOWER(w.`source_id`) = LOWER(?)
             LIMIT 1',
            [$sourceId]
        )->fetchColumn();

        if ($linkedCustomerId !== false && (int) $linkedCustomerId > 0) {
            return (int) $linkedCustomerId;
        }
    }

    $projectId = trim((string) ($data['project_id'] ?? ''));

    if ($projectId !== '' && db_table_exists('minicrm_connection_request_links')) {
        $projectMap = minicrm_project_work_map();
        $work = $projectMap[$projectId] ?? null;

        if (is_array($work)) {
            $customerId = db_query(
                'SELECT `customer_id` FROM `minicrm_connection_request_links` WHERE `work_item_id` = ? LIMIT 1',
                [(int) $work['id']]
            )->fetchColumn();

            if ($customerId !== false && (int) $customerId > 0) {
                return (int) $customerId;
            }
        }
    }

    $personEmail = trim((string) ($data['person_email'] ?? ''));

    if ($personEmail !== '') {
        $customerId = db_query(
            'SELECT `id` FROM `customers` WHERE LOWER(`email`) = LOWER(?) LIMIT 1',
            [$personEmail]
        )->fetchColumn();

        if ($customerId !== false && (int) $customerId > 0) {
            return (int) $customerId;
        }
    }

    $personPhoneKey = minicrm_customer_profile_phone_key((string) ($data['person_phone'] ?? ''));

    if (strlen($personPhoneKey) >= 8) {
        $customersByPhone = all_customers();

        foreach ($customersByPhone as $customer) {
            if ($personPhoneKey === minicrm_customer_profile_phone_key((string) ($customer['phone'] ?? ''))) {
                return (int) $customer['id'];
            }
        }
    }

    $profileNameKey = minicrm_import_key((string) ($data['card_name'] ?? ''));
    $personNameKey = minicrm_import_key((string) ($data['person_name'] ?? ''));

    if ($profileNameKey === '' && $personNameKey === '') {
        return null;
    }

    static $customers = null;

    if ($customers === null) {
        $customers = all_customers();
    }

    foreach ($customers as $customer) {
        $customerKey = minicrm_import_key((string) ($customer['requester_name'] ?? ''));

        if ($customerKey === '') {
            continue;
        }

        if (
            $profileNameKey === $customerKey
            || $personNameKey === $customerKey
            || (strlen($customerKey) >= 8 && ($profileNameKey !== '' && str_starts_with($profileNameKey, $customerKey)))
            || (strlen($customerKey) >= 8 && ($personNameKey !== '' && str_starts_with($personNameKey, $customerKey)))
        ) {
            return (int) $customer['id'];
        }
    }

    return null;
}

function minicrm_customer_profile_upload(array $file): array
{
    if (minicrm_import_schema_errors() !== []) {
        return ['ok' => false, 'message' => 'Elobb hozd letre/frissitsd a MiniCRM import tablakat.'];
    }

    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        return ['ok' => false, 'message' => 'A PhpSpreadsheet nincs telepitve, ezert az Excel import nem futtathato.'];
    }

    if (!uploaded_file_is_present($file)) {
        return ['ok' => false, 'message' => 'Valassz ki egy MiniCRM ugyfeladat Excel fajlt.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'A fajl feltoltese sikertelen.'];
    }

    $originalName = (string) ($file['name'] ?? 'minicrm-customer-profiles.xlsx');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['xls', 'xlsx'], true)) {
        return ['ok' => false, 'message' => 'Csak XLS vagy XLSX fajl importalhato.'];
    }

    return import_minicrm_customer_profile_workbook((string) $file['tmp_name'], $originalName);
}

function minicrm_customer_profile_uploads(array $files): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $uploadedFiles = array_values(array_filter(
        uploaded_files_for_key($files, 'minicrm_customer_profile_files'),
        static fn (?array $file): bool => uploaded_file_is_present($file)
    ));

    if ($uploadedFiles === []) {
        return ['ok' => false, 'message' => 'Valassz ki legalabb egy MiniCRM ugyfeladat Excel fajlt.'];
    }

    $totals = ['files' => 0, 'rows' => 0, 'imported' => 0, 'updated' => 0, 'matched' => 0, 'unmatched' => 0, 'skipped' => 0, 'errors' => 0];
    $failed = [];

    foreach ($uploadedFiles as $file) {
        $result = minicrm_customer_profile_upload($file);
        $originalName = (string) ($file['name'] ?? 'MiniCRM ugyfeladat Excel');

        if (!($result['ok'] ?? false)) {
            $failed[] = $originalName . ': ' . (string) ($result['message'] ?? 'sikertelen import');
            continue;
        }

        foreach (array_keys($totals) as $key) {
            $totals[$key] += (int) ($result[$key] ?? 0);
        }

        $totals['files']++;
    }

    if ($totals['files'] === 0) {
        return ['ok' => false, 'message' => implode(' ', $failed)];
    }

    $message = 'MiniCRM ugyfeladat import kesz: ' . $totals['files'] . ' fajl, ' . $totals['rows'] . ' sor, '
        . $totals['imported'] . ' uj, ' . $totals['updated'] . ' frissitett, '
        . $totals['matched'] . ' ugyfelhez rendelve, ' . $totals['unmatched'] . ' parositatlan.';

    if ($failed !== []) {
        $message .= ' Nem importalt fajlok: ' . implode(' ', $failed);
    }

    return array_merge(['ok' => true, 'message' => $message, 'failed' => $failed], $totals);
}

function sync_minicrm_customer_profile_to_customer(int $customerId, array $data): void
{
    if ($customerId <= 0) {
        return;
    }

    $customer = find_customer($customerId);

    if ($customer === null) {
        return;
    }

    $updates = [];
    $params = [];
    $personName = minicrm_customer_profile_display_value($data, 'person_name', ['Szemely1 Nev', 'Személy1: Név', 'Nev', 'Név']);
    $cardName = trim((string) ($data['card_name'] ?? ''));
    $personEmail = minicrm_customer_profile_display_value($data, 'person_email', ['Szemely1 Email', 'Személy1: Email', 'Ceg Email', 'Cég: Email', 'Email']);
    $personPhone = minicrm_customer_profile_display_value($data, 'person_phone', ['Szemely1 Telefon', 'Személy1: Telefon', 'Ceg Telefon', 'Cég: Telefon', 'Telefon']);

    if (trim((string) ($customer['requester_name'] ?? '')) === '' && ($personName !== '' || $cardName !== '')) {
        $updates[] = '`requester_name` = ?';
        $params[] = $personName !== '' ? $personName : $cardName;
    }

    if (trim((string) ($customer['email'] ?? '')) === '' && $personEmail !== '') {
        $updates[] = '`email` = ?';
        $params[] = $personEmail;
    }

    if (trim((string) ($customer['phone'] ?? '')) === '' && $personPhone !== '') {
        $updates[] = '`phone` = ?';
        $params[] = $personPhone;
    }

    if ($updates === []) {
        return;
    }

    $params[] = $customerId;
    db_query('UPDATE `customers` SET ' . implode(', ', $updates) . ' WHERE `id` = ?', $params);
}

function minicrm_import_schema_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS `minicrm_import_batches` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_name` VARCHAR(190) NOT NULL,
    `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `imported_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_minicrm_import_batches_created_at` (`created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `minicrm_work_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` INT UNSIGNED NULL,
    `source_id` VARCHAR(80) NOT NULL,
    `card_name` VARCHAR(255) NOT NULL,
    `customer_name` VARCHAR(190) DEFAULT NULL,
    `responsible` VARCHAR(160) DEFAULT NULL,
    `minicrm_status` VARCHAR(120) DEFAULT NULL,
    `work_type` VARCHAR(160) DEFAULT NULL,
    `work_kind` VARCHAR(160) DEFAULT NULL,
    `request_type` VARCHAR(60) DEFAULT NULL,
    `date_value` VARCHAR(60) DEFAULT NULL,
    `submitted_date` VARCHAR(60) DEFAULT NULL,
    `birth_name` VARCHAR(160) DEFAULT NULL,
    `birth_place` VARCHAR(120) DEFAULT NULL,
    `birth_date` VARCHAR(60) DEFAULT NULL,
    `mother_name` VARCHAR(160) DEFAULT NULL,
    `mailing_address` VARCHAR(255) DEFAULT NULL,
    `postal_code` VARCHAR(20) DEFAULT NULL,
    `city` VARCHAR(120) DEFAULT NULL,
    `site_address` VARCHAR(255) DEFAULT NULL,
    `street` VARCHAR(160) DEFAULT NULL,
    `house_number` VARCHAR(80) DEFAULT NULL,
    `floor_door` VARCHAR(80) DEFAULT NULL,
    `hrsz` VARCHAR(80) DEFAULT NULL,
    `consumption_place_id` VARCHAR(120) DEFAULT NULL,
    `meter_serial` VARCHAR(120) DEFAULT NULL,
    `controlled_meter_serial` VARCHAR(120) DEFAULT NULL,
    `wire_type` VARCHAR(120) DEFAULT NULL,
    `meter_cabinet` VARCHAR(160) DEFAULT NULL,
    `meter_location` VARCHAR(255) DEFAULT NULL,
    `pole_type` VARCHAR(120) DEFAULT NULL,
    `wire_note` TEXT DEFAULT NULL,
    `cabinet_note` TEXT DEFAULT NULL,
    `document_links_json` LONGTEXT DEFAULT NULL,
    `raw_payload` LONGTEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `archived_at` DATETIME DEFAULT NULL,
    `archived_by_user_id` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_minicrm_work_items_source_id` (`source_id`),
    KEY `idx_minicrm_work_items_status` (`minicrm_status`),
    KEY `idx_minicrm_work_items_responsible` (`responsible`),
    KEY `idx_minicrm_work_items_batch_id` (`batch_id`),
    KEY `idx_minicrm_work_items_submitted_date` (`submitted_date`),
    KEY `idx_minicrm_work_items_archived` (`archived_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `minicrm_work_item_files` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_item_id` INT UNSIGNED NOT NULL,
    `source_id` VARCHAR(80) NOT NULL,
    `project_id` VARCHAR(40) NOT NULL,
    `label` VARCHAR(190) DEFAULT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `zip_entry` TEXT DEFAULT NULL,
    `zip_entry_hash` CHAR(64) NOT NULL,
    `storage_path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(120) DEFAULT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_minicrm_work_item_files_zip_hash` (`zip_entry_hash`),
    KEY `idx_minicrm_work_item_files_work_item` (`work_item_id`),
    KEY `idx_minicrm_work_item_files_source_id` (`source_id`),
    KEY `idx_minicrm_work_item_files_project_id` (`project_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `minicrm_connection_request_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_item_id` INT UNSIGNED NOT NULL,
    `source_id` VARCHAR(80) NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `connection_request_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_minicrm_connection_links_work_item` (`work_item_id`),
    KEY `idx_minicrm_connection_links_source_id` (`source_id`),
    KEY `idx_minicrm_connection_links_customer` (`customer_id`),
    KEY `idx_minicrm_connection_links_request` (`connection_request_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `minicrm_customer_profiles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_id` VARCHAR(80) NOT NULL,
    `customer_id` INT UNSIGNED NULL,
    `project_id` VARCHAR(40) DEFAULT NULL,
    `card_name` VARCHAR(255) DEFAULT NULL,
    `responsible` VARCHAR(160) DEFAULT NULL,
    `minicrm_status` VARCHAR(120) DEFAULT NULL,
    `status_group` VARCHAR(160) DEFAULT NULL,
    `status_updated_at` VARCHAR(60) DEFAULT NULL,
    `visibility` VARCHAR(60) DEFAULT NULL,
    `created_by_name` VARCHAR(160) DEFAULT NULL,
    `created_date` VARCHAR(60) DEFAULT NULL,
    `modified_by_name` VARCHAR(160) DEFAULT NULL,
    `modified_date` VARCHAR(60) DEFAULT NULL,
    `card_url` VARCHAR(500) DEFAULT NULL,
    `minicrm_imported_at` VARCHAR(60) DEFAULT NULL,
    `person_type` VARCHAR(80) DEFAULT NULL,
    `person_name` VARCHAR(190) DEFAULT NULL,
    `person_first_name` VARCHAR(120) DEFAULT NULL,
    `person_last_name` VARCHAR(120) DEFAULT NULL,
    `person_email` VARCHAR(190) DEFAULT NULL,
    `person_phone` VARCHAR(80) DEFAULT NULL,
    `person_summary` TEXT DEFAULT NULL,
    `person_created_by_name` VARCHAR(160) DEFAULT NULL,
    `person_created_date` VARCHAR(60) DEFAULT NULL,
    `person_modified_by_name` VARCHAR(160) DEFAULT NULL,
    `person_modified_date` VARCHAR(60) DEFAULT NULL,
    `person_position` VARCHAR(160) DEFAULT NULL,
    `person_website` VARCHAR(255) DEFAULT NULL,
    `person_consent` VARCHAR(120) DEFAULT NULL,
    `raw_payload` LONGTEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_minicrm_customer_profiles_source_id` (`source_id`),
    KEY `idx_minicrm_customer_profiles_customer` (`customer_id`),
    KEY `idx_minicrm_customer_profiles_project` (`project_id`),
    KEY `idx_minicrm_customer_profiles_status` (`minicrm_status`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `minicrm_customer_profiles`
    ADD COLUMN IF NOT EXISTS `person_type` VARCHAR(80) DEFAULT NULL AFTER `minicrm_imported_at`,
    ADD COLUMN IF NOT EXISTS `person_name` VARCHAR(190) DEFAULT NULL AFTER `person_type`,
    ADD COLUMN IF NOT EXISTS `person_first_name` VARCHAR(120) DEFAULT NULL AFTER `person_name`,
    ADD COLUMN IF NOT EXISTS `person_last_name` VARCHAR(120) DEFAULT NULL AFTER `person_first_name`,
    ADD COLUMN IF NOT EXISTS `person_email` VARCHAR(190) DEFAULT NULL AFTER `person_last_name`,
    ADD COLUMN IF NOT EXISTS `person_phone` VARCHAR(80) DEFAULT NULL AFTER `person_email`,
    ADD COLUMN IF NOT EXISTS `person_summary` TEXT DEFAULT NULL AFTER `person_phone`,
    ADD COLUMN IF NOT EXISTS `person_created_by_name` VARCHAR(160) DEFAULT NULL AFTER `person_summary`,
    ADD COLUMN IF NOT EXISTS `person_created_date` VARCHAR(60) DEFAULT NULL AFTER `person_created_by_name`,
    ADD COLUMN IF NOT EXISTS `person_modified_by_name` VARCHAR(160) DEFAULT NULL AFTER `person_created_date`,
    ADD COLUMN IF NOT EXISTS `person_modified_date` VARCHAR(60) DEFAULT NULL AFTER `person_modified_by_name`,
    ADD COLUMN IF NOT EXISTS `person_position` VARCHAR(160) DEFAULT NULL AFTER `person_modified_date`,
    ADD COLUMN IF NOT EXISTS `person_website` VARCHAR(255) DEFAULT NULL AFTER `person_position`,
    ADD COLUMN IF NOT EXISTS `person_consent` VARCHAR(120) DEFAULT NULL AFTER `person_website`;
SQL;
}

function minicrm_import_install_schema(): array
{
    if (!is_admin_user()) {
        return ['ok' => false, 'message' => 'A MiniCRM import tábláit csak admin jogosultsággal lehet létrehozni.'];
    }

    $path = APP_ROOT . '/database/minicrm_import.sql';
    $sql = is_file($path) ? file_get_contents($path) : false;

    if ($sql === false) {
        $sql = minicrm_import_schema_sql();
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    $executed = 0;

    try {
        foreach ($statements as $statement) {
            $statement = trim($statement);

            if ($statement === '') {
                continue;
            }

            db()->exec($statement);
            $executed++;
        }
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A MiniCRM import táblák létrehozása sikertelen.'];
    }

    return ['ok' => true, 'message' => 'A MiniCRM import táblák létrejöttek. Futtatott SQL parancsok: ' . $executed . '.'];
}

function import_minicrm_workbook(string $path, string $originalName): array
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $readerType = $extension === 'xls' ? 'Xls' : 'Xlsx';
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerType);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestDataRow();
    $highestColumn = $sheet->getHighestDataColumn();

    if ($highestRow < 2) {
        $spreadsheet->disconnectWorksheets();

        return ['ok' => false, 'message' => 'Az Excel nem tartalmaz importálható sort.'];
    }

    $headers = $sheet->rangeToArray('A1:' . $highestColumn . '1', null, true, true, false)[0];
    $user = current_user();
    $pdo = db();
    $pdo->beginTransaction();

    try {
        db_query(
            'INSERT INTO `minicrm_import_batches` (`original_name`, `created_by`) VALUES (?, ?)',
            [$originalName, is_array($user) ? (int) $user['id'] : null]
        );
        $batchId = (int) $pdo->lastInsertId();

        $columns = [
            'batch_id',
            'source_id',
            'card_name',
            'customer_name',
            'responsible',
            'minicrm_status',
            'work_type',
            'work_kind',
            'request_type',
            'date_value',
            'submitted_date',
            'birth_name',
            'birth_place',
            'birth_date',
            'mother_name',
            'mailing_address',
            'postal_code',
            'city',
            'site_address',
            'street',
            'house_number',
            'floor_door',
            'hrsz',
            'consumption_place_id',
            'meter_serial',
            'controlled_meter_serial',
            'wire_type',
            'meter_cabinet',
            'meter_location',
            'pole_type',
            'wire_note',
            'cabinet_note',
            'document_links_json',
            'raw_payload',
        ];
        $updates = array_values(array_filter($columns, static fn (string $column): bool => $column !== 'source_id'));
        $sql = 'INSERT INTO `minicrm_work_items` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            . ' ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(static fn (string $column): string => '`' . $column . '` = VALUES(`' . $column . '`)', $updates))
            . ', `updated_at` = CURRENT_TIMESTAMP';

        $rowCount = 0;
        $importedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $values = $sheet->rangeToArray('A' . $rowNumber . ':' . $highestColumn . $rowNumber, null, true, true, false)[0];

            if (implode('', array_map(static fn (mixed $value): string => trim((string) $value), $values)) === '') {
                continue;
            }

            $rowCount++;

            try {
                $data = minicrm_import_row_data_from_headers($headers, $values);

                if ($data['source_id'] === '') {
                    $skippedCount++;
                    continue;
                }

                $existingItem = db_query('SELECT * FROM `minicrm_work_items` WHERE `source_id` = ? LIMIT 1', [$data['source_id']])->fetch();
                $existingItem = is_array($existingItem) ? $existingItem : null;
                $data = minicrm_import_merge_row_data($data, $existingItem);
                $params = [$batchId];

                foreach (array_slice($columns, 1) as $column) {
                    if (in_array($column, ['card_name', 'source_id', 'document_links_json', 'raw_payload'], true)) {
                        $params[] = $data[$column];
                    } else {
                        $params[] = minicrm_import_nullable((string) ($data[$column] ?? ''));
                    }
                }

                db_query($sql, $params);

                if ($existingItem !== null) {
                    $updatedCount++;
                } else {
                    $importedCount++;
                }
            } catch (Throwable) {
                $errorCount++;
            }
        }

        db_query(
            'UPDATE `minicrm_import_batches`
             SET `row_count` = ?, `imported_count` = ?, `updated_count` = ?, `skipped_count` = ?, `error_count` = ?
             WHERE `id` = ?',
            [$rowCount, $importedCount, $updatedCount, $skippedCount, $errorCount, $batchId]
        );

        $pdo->commit();
        $spreadsheet->disconnectWorksheets();

        return [
            'ok' => true,
            'message' => 'MiniCRM import kész: ' . $importedCount . ' új, ' . $updatedCount . ' frissített, ' . $skippedCount . ' kihagyott, ' . $errorCount . ' hibás sor.',
            'rows' => $rowCount,
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
            'errors' => $errorCount,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $spreadsheet->disconnectWorksheets();

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A MiniCRM import sikertelen.'];
    }
}

function import_minicrm_customer_profile_workbook(string $path, string $originalName): array
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $readerType = $extension === 'xls' ? 'Xls' : 'Xlsx';
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerType);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestDataRow();
    $highestColumn = $sheet->getHighestDataColumn();

    if ($highestRow < 2) {
        $spreadsheet->disconnectWorksheets();

        return ['ok' => false, 'message' => 'Az Excel nem tartalmaz importalhato sort.'];
    }

    $headers = $sheet->rangeToArray('A1:' . $highestColumn . '1', null, true, true, false)[0];
    $headerMap = minicrm_import_header_map($headers);
    $hasContactColumns = isset($headerMap[minicrm_import_key('Szemely1 Email')])
        || isset($headerMap[minicrm_import_key('Személy1: Email')])
        || isset($headerMap[minicrm_import_key('Szemely1 Telefon')])
        || isset($headerMap[minicrm_import_key('Személy1: Telefon')]);
    $columns = [
        'source_id',
        'customer_id',
        'project_id',
        'card_name',
        'responsible',
        'minicrm_status',
        'status_group',
        'status_updated_at',
        'visibility',
        'created_by_name',
        'created_date',
        'modified_by_name',
        'modified_date',
        'card_url',
        'minicrm_imported_at',
        'person_type',
        'person_name',
        'person_first_name',
        'person_last_name',
        'person_email',
        'person_phone',
        'person_summary',
        'person_created_by_name',
        'person_created_date',
        'person_modified_by_name',
        'person_modified_date',
        'person_position',
        'person_website',
        'person_consent',
        'raw_payload',
    ];
    $updates = array_values(array_filter($columns, static fn (string $column): bool => $column !== 'source_id'));
    $sql = 'INSERT INTO `minicrm_customer_profiles` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        . ' ON DUPLICATE KEY UPDATE '
        . implode(', ', array_map(static fn (string $column): string => '`' . $column . '` = VALUES(`' . $column . '`)', $updates))
        . ', `updated_at` = CURRENT_TIMESTAMP';

    $pdo = db();
    $pdo->beginTransaction();
    $rowCount = 0;
    $importedCount = 0;
    $updatedCount = 0;
    $matchedCount = 0;
    $unmatchedCount = 0;
    $skippedCount = 0;
    $errorCount = 0;

    try {
        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $values = $sheet->rangeToArray('A' . $rowNumber . ':' . $highestColumn . $rowNumber, null, true, true, false)[0];

            if (implode('', array_map(static fn (mixed $value): string => trim((string) $value), $values)) === '') {
                continue;
            }

            $rowCount++;

            try {
                $data = minicrm_customer_profile_row_data($headers, $values);

                if ($data['source_id'] === '') {
                    $skippedCount++;
                    continue;
                }

                $existingProfile = db_query(
                    'SELECT * FROM `minicrm_customer_profiles` WHERE LOWER(`source_id`) = LOWER(?) LIMIT 1',
                    [$data['source_id']]
                )->fetch();
                $existingId = is_array($existingProfile) ? (int) $existingProfile['id'] : false;
                $customerId = minicrm_customer_profile_customer_id($data);
                $preserveColumns = [
                    'person_type',
                    'person_name',
                    'person_first_name',
                    'person_last_name',
                    'person_email',
                    'person_phone',
                    'person_summary',
                    'person_created_by_name',
                    'person_created_date',
                    'person_modified_by_name',
                    'person_modified_date',
                    'person_position',
                    'person_website',
                    'person_consent',
                ];

                if (is_array($existingProfile)) {
                    if ($customerId === null && !empty($existingProfile['customer_id'])) {
                        $customerId = (int) $existingProfile['customer_id'];
                    }

                    foreach ($preserveColumns as $preserveColumn) {
                        if (trim((string) ($data[$preserveColumn] ?? '')) === '' && trim((string) ($existingProfile[$preserveColumn] ?? '')) !== '') {
                            $data[$preserveColumn] = (string) $existingProfile[$preserveColumn];
                        }
                    }

                    if (!$hasContactColumns && trim((string) ($existingProfile['raw_payload'] ?? '')) !== '') {
                        $data['raw_payload'] = (string) $existingProfile['raw_payload'];
                    }
                }

                $data['customer_id'] = $customerId;

                if ($customerId !== null) {
                    $matchedCount++;
                    sync_minicrm_customer_profile_to_customer($customerId, $data);
                } else {
                    $unmatchedCount++;
                }

                $params = [];

                foreach ($columns as $column) {
                    if ($column === 'customer_id') {
                        $params[] = $data[$column] !== null ? (int) $data[$column] : null;
                    } elseif (in_array($column, ['source_id', 'raw_payload'], true)) {
                        $params[] = $data[$column];
                    } else {
                        $params[] = minicrm_import_nullable((string) ($data[$column] ?? ''));
                    }
                }

                db_query($sql, $params);

                if ($existingId !== false) {
                    $updatedCount++;
                } else {
                    $importedCount++;
                }
            } catch (Throwable) {
                $errorCount++;
            }
        }

        $pdo->commit();
        $spreadsheet->disconnectWorksheets();

        $message = 'MiniCRM ugyfeladat import kesz: ' . $importedCount . ' uj, ' . $updatedCount . ' frissitett, '
            . $matchedCount . ' ugyfelhez rendelve, ' . $unmatchedCount . ' parositatlan.';

        if (!$hasContactColumns) {
            $message .= ' Figyelem: ez az Excel nem tartalmaz Szemely1 Email/Telefon oszlopot, ezert csak a korabban mar importalt kontaktadatokat oriztuk meg.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'rows' => $rowCount,
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'matched' => $matchedCount,
            'unmatched' => $unmatchedCount,
            'skipped' => $skippedCount,
            'errors' => $errorCount,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $spreadsheet->disconnectWorksheets();

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A MiniCRM ugyfeladat import sikertelen.'];
    }
}

function minicrm_document_zip_candidates(): array
{
    $importPath = minicrm_document_import_path();
    ensure_storage_dir($importPath);

    $candidates = [
        $importPath . '/minicrm-documents.zip',
        $importPath . '/elo-munkak-export-2026-04-28-dokumentumok.zip',
    ];

    foreach (glob($importPath . '/*.zip') ?: [] as $path) {
        $candidates[] = $path;
    }

    $unique = [];

    foreach ($candidates as $path) {
        $realPath = realpath($path);

        if ($realPath === false || !is_file($realPath)) {
            continue;
        }

        $unique[$realPath] = $realPath;
    }

    return array_values($unique);
}

function minicrm_document_import_path(): string
{
    return defined('MINICRM_DOCUMENT_IMPORT_PATH')
        ? MINICRM_DOCUMENT_IMPORT_PATH
        : STORAGE_PATH . '/imports';
}

function minicrm_document_upload_path(): string
{
    return defined('MINICRM_DOCUMENT_UPLOAD_PATH')
        ? MINICRM_DOCUMENT_UPLOAD_PATH
        : STORAGE_PATH . '/uploads/minicrm-documents';
}

function minicrm_document_allowed_extensions(): array
{
    return [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'heif' => 'image/heif',
        'heic' => 'image/heic',
    ];
}

function minicrm_safe_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $baseName = pathinfo($name, PATHINFO_FILENAME);
    $baseName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName) ?: 'file';
    $baseName = trim($baseName, '-._');

    if ($baseName === '') {
        $baseName = 'file';
    }

    return $extension !== '' ? $baseName . '.' . $extension : $baseName;
}

function minicrm_document_label_from_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/^\d+-/u', '', $name) ?: $name;
    $name = pathinfo($name, PATHINFO_FILENAME);
    $name = str_replace(['_', '-'], ' ', $name);
    $name = trim(preg_replace('/\s+/', ' ', $name) ?: $name);

    return $name !== '' ? $name : 'MiniCRM dokumentum';
}

function minicrm_document_mime_type(string $path, string $originalName): string
{
    $mimeType = function_exists('mime_content_type') ? (mime_content_type($path) ?: '') : '';

    if ($mimeType !== '') {
        return $mimeType;
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    return minicrm_document_allowed_extensions()[$extension] ?? 'application/octet-stream';
}

function validate_minicrm_work_item_file_uploads(array $files): array
{
    $errors = [];
    $uploadedFiles = array_values(array_filter(
        $files,
        static fn (?array $file): bool => is_array($file) && uploaded_file_is_present($file)
    ));

    if ($uploadedFiles === []) {
        return ['Legalább egy fotó vagy dokumentum feltöltése kötelező.'];
    }

    $allowed = minicrm_document_allowed_extensions();

    foreach ($uploadedFiles as $file) {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = (string) ($file['name'] ?? 'Fájl') . ': a feltöltés sikertelen.';
            continue;
        }

        if ((int) ($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
            $errors[] = (string) ($file['name'] ?? 'Fájl') . ': túl nagy fájl. Maximum 8 MB engedélyezett.';
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (!isset($allowed[$extension])) {
            $errors[] = (string) ($file['name'] ?? 'Fájl') . ': nem engedélyezett fájltípus. Használható: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, WEBP, HEIC.';
            continue;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $mimeType = $tmpName !== '' && function_exists('mime_content_type') ? (mime_content_type($tmpName) ?: '') : '';
        $officeExtensions = ['doc', 'docx', 'xls', 'xlsx'];
        $mimeTolerated = $mimeType === ''
            || $mimeType === $allowed[$extension]
            || in_array($mimeType, ['application/octet-stream', 'application/zip'], true)
            || (in_array($extension, $officeExtensions, true) && str_contains($mimeType, 'officedocument'));

        if (!$mimeTolerated) {
            $errors[] = (string) ($file['name'] ?? 'Fájl') . ': a fájl típusa nem egyezik a kiterjesztéssel.';
        }
    }

    return $errors;
}

function minicrm_project_ids_from_text(string $text): array
{
    if ($text === '') {
        return [];
    }

    preg_match_all('~/Project-\d+/(\d+)(?:/|$)~i', $text, $matches);

    return array_values(array_unique($matches[1] ?? []));
}

function minicrm_work_item_project_ids(array $item): array
{
    $ids = [];

    foreach ([
        (string) ($item['card_url'] ?? ''),
        (string) ($item['document_links_json'] ?? ''),
        (string) ($item['raw_payload'] ?? ''),
    ] as $text) {
        foreach (minicrm_project_ids_from_text($text) as $projectId) {
            $ids[$projectId] = $projectId;
        }
    }

    foreach (minicrm_work_item_document_links($item) as $link) {
        foreach (minicrm_project_ids_from_text((string) ($link['value'] ?? '')) as $projectId) {
            $ids[$projectId] = $projectId;
        }
    }

    foreach (minicrm_work_item_raw_fields($item) as $field) {
        foreach (minicrm_project_ids_from_text((string) ($field['value'] ?? '')) as $projectId) {
            $ids[$projectId] = $projectId;
        }
    }

    $workItemId = (int) ($item['id'] ?? 0);

    if ($workItemId > 0 && db_table_exists('minicrm_work_item_files')) {
        $rows = db_query(
            'SELECT DISTINCT `project_id`
             FROM `minicrm_work_item_files`
             WHERE `work_item_id` = ? AND `project_id` <> \'\'',
            [$workItemId]
        )->fetchAll();

        foreach ($rows as $row) {
            $projectId = trim((string) ($row['project_id'] ?? ''));

            if ($projectId !== '') {
                $ids[$projectId] = $projectId;
            }
        }
    }

    return array_values($ids);
}

function minicrm_project_work_map(): array
{
    if (!db_table_exists('minicrm_work_items')) {
        return [];
    }

    $rows = db_query(
        'SELECT `id`, `source_id`, `card_name`, `document_links_json`, `raw_payload`
         FROM `minicrm_work_items`
         ORDER BY `id` ASC'
    )->fetchAll();
    $map = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        foreach (minicrm_work_item_project_ids($row) as $projectId) {
            if (isset($map[$projectId])) {
                continue;
            }

            $map[$projectId] = [
                'id' => (int) $row['id'],
                'source_id' => (string) $row['source_id'],
                'card_name' => (string) $row['card_name'],
            ];
        }
    }

    return $map;
}

function minicrm_store_uploaded_document_zip(?array $file): ?string
{
    if (!is_array($file) || !uploaded_file_is_present($file)) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('A ZIP feltöltése sikertelen.');
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($extension !== 'zip') {
        throw new RuntimeException('Csak ZIP fájl dolgozható fel.');
    }

    $importPath = minicrm_document_import_path();
    ensure_storage_dir($importPath);

    $storedName = 'minicrm-documents-' . date('Ymd-His') . '.zip';
    $targetPath = $importPath . '/' . $storedName;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $targetPath)) {
        throw new RuntimeException('A ZIP fájl mentése sikertelen.');
    }

    return $targetPath;
}

function minicrm_uploaded_document_zip_files(array $files): array
{
    $uploadedFiles = array_values(array_filter(
        uploaded_files_for_key($files, 'minicrm_document_zips'),
        static fn (?array $file): bool => uploaded_file_is_present($file)
    ));

    if ($uploadedFiles === []) {
        $uploadedFiles = array_values(array_filter(
            uploaded_files_for_key($files, 'minicrm_document_zip'),
            static fn (?array $file): bool => uploaded_file_is_present($file)
        ));
    }

    return $uploadedFiles;
}

function minicrm_import_document_zips(array $files): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'A szerveren nincs bekapcsolva a ZipArchive PHP kiegészítő.'];
    }

    if (minicrm_import_schema_errors() !== []) {
        return ['ok' => false, 'message' => 'Előbb hozd létre/frissítsd a MiniCRM import táblákat.'];
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $zipPaths = [];

    foreach (minicrm_uploaded_document_zip_files($files) as $file) {
        try {
            $zipPath = minicrm_store_uploaded_document_zip($file);
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A ZIP feltöltése sikertelen.'];
        }

        if ($zipPath !== null) {
            $zipPaths[] = $zipPath;
        }
    }

    if ($zipPaths === []) {
        $zipPaths = minicrm_document_zip_candidates();
    }

    if ($zipPaths === []) {
        return ['ok' => false, 'message' => 'Nem található feldolgozható ZIP. Töltsd fel a ZIP-eket a storage/imports mappába, vagy válaszd ki az űrlapon.'];
    }

    $projectMap = minicrm_project_work_map();

    if ($projectMap === []) {
        return ['ok' => false, 'message' => 'Előbb importáld a MiniCRM Excel fájlokat, hogy legyen mihez kötni a dokumentumokat.'];
    }

    $totals = [
        'processed' => 0,
        'imported' => 0,
        'updated' => 0,
        'existing' => 0,
        'unmatched' => 0,
        'unsupported' => 0,
        'errors' => 0,
    ];
    $matchedWorks = [];
    $failed = [];
    $zipCount = 0;

    foreach ($zipPaths as $zipPath) {
        $result = minicrm_import_document_zip_path($zipPath, $projectMap);

        if (!($result['ok'] ?? false)) {
            $failed[] = basename($zipPath) . ': ' . (string) ($result['message'] ?? 'sikertelen feldolgozás');
            continue;
        }

        $zipCount++;

        foreach (array_keys($totals) as $key) {
            $totals[$key] += (int) ($result[$key] ?? 0);
        }

        foreach (($result['matched_source_ids'] ?? []) as $sourceId) {
            $matchedWorks[(string) $sourceId] = true;
        }
    }

    if ($zipCount === 0) {
        return ['ok' => false, 'message' => implode(' ', $failed)];
    }

    $message = 'MiniCRM dokumentum ZIP feldolgozva: ' . $zipCount . ' ZIP, ' . $totals['processed'] . ' fájl, '
        . $totals['imported'] . ' új, ' . $totals['updated'] . ' újramentett, ' . $totals['existing'] . ' már meglévő, '
        . $totals['unmatched'] . ' nem párosítható, ' . $totals['unsupported'] . ' nem támogatott, '
        . $totals['errors'] . ' hibás. Érintett munkák: ' . count($matchedWorks) . '.';

    if ($failed !== []) {
        $message .= ' Sikertelen ZIP-ek: ' . implode(' ', $failed);
    }

    return array_merge([
        'ok' => true,
        'message' => $message,
        'zip_count' => $zipCount,
        'failed' => $failed,
        'matched_works' => count($matchedWorks),
    ], $totals);
}

function minicrm_import_document_zip(?array $uploadFile = null): array
{
    return minicrm_import_document_zips(['minicrm_document_zip' => $uploadFile ?? ['error' => UPLOAD_ERR_NO_FILE]]);
}

function minicrm_import_document_zip_path(string $zipPath, ?array $projectMap = null): array
{
    $givenZipPath = $zipPath;
    $uploadFile = null;

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'A szerveren nincs bekapcsolva a ZipArchive PHP kiegészítő.'];
    }

    if (minicrm_import_schema_errors() !== []) {
        return ['ok' => false, 'message' => 'Előbb hozd létre/frissítsd a MiniCRM import táblákat.'];
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    try {
        $zipPath = minicrm_store_uploaded_document_zip($uploadFile);
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A ZIP feltöltése sikertelen.'];
    }

    if ($zipPath === null) {
        $candidates = minicrm_document_zip_candidates();
        $zipPath = $candidates[0] ?? null;
    }

    $zipPath = $givenZipPath;

    if ($zipPath === null || !is_file($zipPath)) {
        return ['ok' => false, 'message' => 'Nem található feldolgozható ZIP. Töltsd fel a storage/imports/minicrm-documents.zip fájlt, vagy válaszd ki az űrlapon.'];
    }

    if ($projectMap === null) {
        $projectMap = minicrm_project_work_map();
    }

    if ($projectMap === []) {
        return ['ok' => false, 'message' => 'Előbb importáld a MiniCRM Excel fájlokat, hogy legyen mihez kötni a dokumentumokat.'];
    }

    $documentUploadPath = minicrm_document_upload_path();
    ensure_storage_dir($documentUploadPath);

    $zip = new ZipArchive();

    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'message' => 'A ZIP fájl nem nyitható meg.'];
    }

    $allowedExtensions = minicrm_document_allowed_extensions();
    $processed = 0;
    $imported = 0;
    $updated = 0;
    $existing = 0;
    $unmatched = 0;
    $unsupported = 0;
    $errors = 0;
    $matchedWorks = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entryName = (string) $zip->getNameIndex($index);

        if ($entryName === '' || str_ends_with($entryName, '/')) {
            continue;
        }

        $processed++;
        $baseName = basename(str_replace('\\', '/', $entryName));

        if (!preg_match('/^(\d+)-(.+)$/u', $baseName, $matches)) {
            $unmatched++;
            continue;
        }

        $projectId = $matches[1];
        $work = $projectMap[$projectId] ?? null;

        if ($work === null) {
            $unmatched++;
            continue;
        }

        $extension = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));

        if (!isset($allowedExtensions[$extension])) {
            $unsupported++;
            continue;
        }

        $hash = hash('sha256', $entryName);
        $existingFile = db_query(
            'SELECT `id`, `storage_path` FROM `minicrm_work_item_files` WHERE `zip_entry_hash` = ? LIMIT 1',
            [$hash]
        )->fetch();

        if (is_array($existingFile) && is_file((string) $existingFile['storage_path'])) {
            $existing++;
            $matchedWorks[(string) $work['source_id']] = true;
            continue;
        }

        $targetDir = $documentUploadPath . '/' . minicrm_safe_filename((string) $work['source_id']);
        ensure_storage_dir($targetDir);

        $safeName = minicrm_safe_filename($matches[2]);
        $storedName = $projectId . '-' . substr($hash, 0, 12) . '-' . $safeName;
        $targetPath = $targetDir . '/' . $storedName;
        $input = $zip->getStream($entryName);

        if ($input === false) {
            $errors++;
            continue;
        }

        $output = fopen($targetPath, 'wb');

        if ($output === false) {
            fclose($input);
            $errors++;
            continue;
        }

        $copied = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);

        if ($copied === false || !is_file($targetPath)) {
            @unlink($targetPath);
            $errors++;
            continue;
        }

        $fileSize = (int) filesize($targetPath);
        $mimeType = minicrm_document_mime_type($targetPath, $baseName);
        $label = minicrm_document_label_from_filename($baseName);
        $params = [
            (int) $work['id'],
            (string) $work['source_id'],
            $projectId,
            $label,
            $baseName,
            $storedName,
            $entryName,
            $hash,
            $targetPath,
            $mimeType,
            $fileSize,
        ];

        if (is_array($existingFile)) {
            db_query(
                'UPDATE `minicrm_work_item_files`
                 SET `work_item_id` = ?, `source_id` = ?, `project_id` = ?, `label` = ?, `original_name` = ?,
                     `stored_name` = ?, `zip_entry` = ?, `zip_entry_hash` = ?, `storage_path` = ?, `mime_type` = ?, `file_size` = ?
                 WHERE `id` = ?',
                array_merge($params, [(int) $existingFile['id']])
            );
            $updated++;
        } else {
            db_query(
                'INSERT INTO `minicrm_work_item_files`
                    (`work_item_id`, `source_id`, `project_id`, `label`, `original_name`, `stored_name`, `zip_entry`,
                     `zip_entry_hash`, `storage_path`, `mime_type`, `file_size`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $params
            );
            $imported++;
        }

        $matchedWorks[(string) $work['source_id']] = true;
    }

    $zip->close();

    return [
        'ok' => true,
        'message' => 'MiniCRM dokumentum ZIP feldolgozva: ' . $processed . ' fájl, ' . $imported . ' új, '
            . $updated . ' újramentett, ' . $existing . ' már meglévő, ' . $unmatched . ' nem párosítható, '
            . $unsupported . ' nem támogatott, ' . $errors . ' hibás. Érintett munkák: ' . count($matchedWorks) . '.',
        'processed' => $processed,
        'imported' => $imported,
        'updated' => $updated,
        'existing' => $existing,
        'unmatched' => $unmatched,
        'unsupported' => $unsupported,
        'errors' => $errors,
        'matched_works' => count($matchedWorks),
        'matched_source_ids' => array_keys($matchedWorks),
    ];
}

function minicrm_import_batches(int $limit = 10): array
{
    if (!db_table_exists('minicrm_import_batches')) {
        return [];
    }

    return db_query(
        'SELECT b.*, u.name AS created_by_name
         FROM `minicrm_import_batches` b
         LEFT JOIN `users` u ON u.id = b.created_by
         ORDER BY b.created_at DESC, b.id DESC
         LIMIT ' . max(1, min(100, $limit))
    )->fetchAll();
}

function minicrm_work_item_archive_columns_ready(): bool
{
    return db_table_exists('minicrm_work_items')
        && db_column_exists('minicrm_work_items', 'archived_at')
        && db_column_exists('minicrm_work_items', 'archived_by_user_id');
}

function minicrm_work_item_archive_where(bool $archivedOnly): string
{
    if (!minicrm_work_item_archive_columns_ready()) {
        return $archivedOnly ? ' WHERE 1 = 0' : '';
    }

    return $archivedOnly ? ' WHERE `archived_at` IS NOT NULL' : ' WHERE `archived_at` IS NULL';
}

function minicrm_work_items(int $limit = 500, bool $archivedOnly = false): array
{
    if (!db_table_exists('minicrm_work_items')) {
        return [];
    }

    return db_query(
        'SELECT *
         FROM `minicrm_work_items`
         ' . minicrm_work_item_archive_where($archivedOnly) . '
         ORDER BY COALESCE(`updated_at`, `created_at`) DESC, `id` DESC
         LIMIT ' . max(1, min(1000, $limit))
    )->fetchAll();
}

function find_minicrm_work_item(int $id): ?array
{
    if (!db_table_exists('minicrm_work_items')) {
        return null;
    }

    $item = db_query('SELECT * FROM `minicrm_work_items` WHERE `id` = ? LIMIT 1', [$id])->fetch();

    return is_array($item) ? $item : null;
}

function minicrm_connection_request_link_schema_errors(): array
{
    try {
        if (!db_table_exists('minicrm_connection_request_links')) {
            db_query(
                "CREATE TABLE IF NOT EXISTS `minicrm_connection_request_links` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `work_item_id` INT UNSIGNED NOT NULL,
                    `source_id` VARCHAR(80) NOT NULL,
                    `customer_id` INT UNSIGNED NOT NULL,
                    `connection_request_id` INT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `ux_minicrm_connection_links_work_item` (`work_item_id`),
                    KEY `idx_minicrm_connection_links_source_id` (`source_id`),
                    KEY `idx_minicrm_connection_links_customer` (`customer_id`),
                    KEY `idx_minicrm_connection_links_request` (`connection_request_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    } catch (Throwable $exception) {
        return [
            APP_DEBUG
                ? 'A MiniCRM-MVM kapcsolat tabla letrehozasa nem sikerult: ' . $exception->getMessage()
                : 'A MiniCRM-MVM kapcsolat tabla letrehozasa szukseges.',
        ];
    }

    return [];
}

function minicrm_work_item_connection_link(int $workItemId): ?array
{
    if ($workItemId <= 0 || minicrm_connection_request_link_schema_errors() !== []) {
        return null;
    }

    $link = db_query(
        'SELECT * FROM `minicrm_connection_request_links` WHERE `work_item_id` = ? LIMIT 1',
        [$workItemId]
    )->fetch();

    return is_array($link) ? $link : null;
}

function minicrm_work_item_connection_request_id(int $workItemId): ?int
{
    $link = minicrm_work_item_connection_link($workItemId);

    if ($link === null) {
        return null;
    }

    $request = find_connection_request((int) $link['connection_request_id']);

    return $request !== null ? (int) $request['id'] : null;
}

function set_minicrm_work_item_archived(int $workItemId, bool $archive): array
{
    if (!minicrm_work_item_archive_columns_ready()) {
        return ['ok' => false, 'message' => 'Hiányoznak a MiniCRM archiválási mezői. Futtasd az adatbázis frissítést.'];
    }

    $item = find_minicrm_work_item($workItemId);

    if ($item === null) {
        return ['ok' => false, 'message' => 'A MiniCRM adatlap nem található.'];
    }

    $user = current_user();
    $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

    if ($archive) {
        db_query(
            'UPDATE `minicrm_work_items` SET `archived_at` = NOW(), `archived_by_user_id` = ? WHERE `id` = ?',
            [$userId > 0 ? $userId : null, $workItemId]
        );
    } else {
        db_query(
            'UPDATE `minicrm_work_items` SET `archived_at` = NULL, `archived_by_user_id` = NULL WHERE `id` = ?',
            [$workItemId]
        );
    }

    $linkedRequestId = minicrm_work_item_connection_request_id($workItemId);

    if ($linkedRequestId !== null && connection_request_archive_columns_ready()) {
        set_connection_request_archived($linkedRequestId, $archive);
    }

    return [
        'ok' => true,
        'message' => $archive ? 'A MiniCRM adatlap archiválva lett.' : 'A MiniCRM adatlap visszaállítva az archívumból.',
    ];
}

function delete_minicrm_work_item_with_related_data(int $workItemId, bool $deleteLinkedRequest = true): array
{
    $item = find_minicrm_work_item($workItemId);

    if ($item === null) {
        throw new RuntimeException('A MiniCRM adatlap nem található.');
    }

    $filePaths = db_table_exists('minicrm_work_item_files')
        ? collect_string_column('SELECT `storage_path` FROM `minicrm_work_item_files` WHERE `work_item_id` = ?', [$workItemId])
        : [];
    $linkedRequestId = $deleteLinkedRequest ? minicrm_work_item_connection_request_id($workItemId) : null;
    $linkedDeleteSummary = null;

    if ($linkedRequestId !== null) {
        $linkedDeleteSummary = delete_connection_request_with_related_data($linkedRequestId);
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        if (db_table_exists('minicrm_work_item_files')) {
            db_query('DELETE FROM `minicrm_work_item_files` WHERE `work_item_id` = ?', [$workItemId]);
        }

        if (db_table_exists('minicrm_connection_request_links')) {
            db_query('DELETE FROM `minicrm_connection_request_links` WHERE `work_item_id` = ?', [$workItemId]);
        }

        db_query('DELETE FROM `minicrm_work_items` WHERE `id` = ?', [$workItemId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    return [
        'work_item_id' => $workItemId,
        'card_name' => (string) ($item['card_name'] ?? ('MiniCRM #' . $workItemId)),
        'files' => delete_storage_files($filePaths) + (int) ($linkedDeleteSummary['files'] ?? 0),
        'linked_request_id' => $linkedRequestId,
        'linked_request_deleted' => $linkedDeleteSummary !== null,
    ];
}

function minicrm_work_item_raw_value_by_patterns(array $item, array $patterns): string
{
    foreach (minicrm_work_item_raw_fields($item) as $field) {
        $labelKey = minicrm_import_key((string) ($field['label'] ?? ''));
        $value = trim((string) ($field['value'] ?? ''));

        if ($value === '' || $value === '-') {
            continue;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $labelKey)) {
                return $value;
            }
        }
    }

    return '';
}

function minicrm_work_item_phase_power_value(array $item, array $phasePatterns, array $fallbackPatterns): string
{
    $phaseValues = [1 => '', 2 => '', 3 => ''];

    foreach (minicrm_work_item_raw_fields($item) as $field) {
        $labelKey = minicrm_import_key((string) ($field['label'] ?? ''));
        $value = trim((string) ($field['value'] ?? ''));

        if ($value === '' || $value === '-') {
            continue;
        }

        foreach ($phasePatterns as $phase => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $labelKey)) {
                    $phaseValues[(int) $phase] = $value;
                    continue 3;
                }
            }
        }
    }

    if (array_filter($phaseValues, static fn (string $value): bool => trim($value) !== '') === []) {
        return minicrm_work_item_raw_value_by_patterns($item, $fallbackPatterns);
    }

    $amps = [
        1 => mvm_phase_ampere_value($phaseValues[1]),
        2 => mvm_phase_ampere_value($phaseValues[2]),
        3 => mvm_phase_ampere_value($phaseValues[3]),
    ];
    $active = array_values(array_filter($amps, static fn (int $ampere): bool => $ampere > 0));

    if ($active === []) {
        return minicrm_work_item_raw_value_by_patterns($item, $fallbackPatterns);
    }

    if (count($active) === 3 && $amps[1] === $amps[2] && $amps[2] === $amps[3]) {
        return '3x' . $amps[1];
    }

    if (count($active) === 2 && $amps[1] > 0 && $amps[1] === $amps[2] && $amps[3] === 0) {
        return '2x' . $amps[1];
    }

    if (count($active) === 1 && $amps[1] > 0 && $amps[2] === 0 && $amps[3] === 0) {
        return (string) $amps[1];
    }

    return trim(implode(' / ', array_filter([
        $amps[1] > 0 ? 'L1 ' . $amps[1] : '',
        $amps[2] > 0 ? 'L2 ' . $amps[2] : '',
        $amps[3] > 0 ? 'L3 ' . $amps[3] : '',
    ])));
}

function minicrm_work_item_requested_general_power(array $item): string
{
    return minicrm_work_item_phase_power_value(
        $item,
        [
            1 => ['/igenyelt.*(mn|mindennap).*l1/', '/^iml1$/'],
            2 => ['/igenyelt.*(mn|mindennap).*l2/', '/^iml2$/'],
            3 => ['/igenyelt.*(mn|mindennap).*l3/', '/^iml3$/'],
        ],
        ['/igenyelt.*mindennap/', '/igenyelt.*mn.*l1/', '/iml/']
    );
}

function minicrm_work_item_requested_h_tariff_power(array $item): string
{
    return minicrm_work_item_phase_power_value(
        $item,
        [
            1 => ['/igenyelt.*h.*l1/', '/^ihl1$/'],
            2 => ['/igenyelt.*h.*l2/', '/^ihl2$/'],
            3 => ['/igenyelt.*h.*l3/', '/^ihl3$/'],
        ],
        ['/igenyelt.*h tarifa/', '/ihl/']
    );
}

function minicrm_work_item_requested_controlled_power(array $item): string
{
    return minicrm_work_item_phase_power_value(
        $item,
        [
            1 => ['/igenyelt.*vez.*l1/', '/^ivl1$/'],
            2 => ['/igenyelt.*vez.*l2/', '/^ivl2$/'],
            3 => ['/igenyelt.*vez.*l3/', '/^ivl3$/'],
        ],
        ['/igenyelt.*vezerelt/', '/ivl/']
    );
}

function minicrm_work_item_electrician_assignment_name(array $item): string
{
    return minicrm_work_item_electrician_assignment_candidates($item)[0] ?? '';
}

function minicrm_work_item_electrician_assignment_candidates(array $item): array
{
    $patterns = [
        '/^a kivitelezest elvegezte$/',
        '/kivitelezest elvegezte/',
        '/kivitelezest vegzo/',
        '/kivitelezo szerelo/',
        '/villanyszerelo/',
        '/szerelo/',
        '/^felelos$/',
        '/^mvm felelos$/',
    ];
    $candidates = [];

    foreach (minicrm_work_item_raw_fields($item) as $field) {
        $labelKey = minicrm_import_key((string) ($field['label'] ?? ''));
        $value = trim((string) ($field['value'] ?? ''));

        if ($value === '' || $value === '-') {
            continue;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $labelKey)) {
                $candidates[] = $value;
                break;
            }
        }
    }

    if (!empty($item['responsible'])) {
        $candidates[] = (string) $item['responsible'];
    }

    $unique = [];

    foreach ($candidates as $candidate) {
        foreach (preg_split('/[,;\r\n]+/', $candidate) ?: [] as $part) {
            $part = trim((string) $part);
            $key = minicrm_electrician_assignment_key($part);

            if ($key === '' || isset($unique[$key])) {
                continue;
            }

            $unique[$key] = $part;
        }
    }

    return array_values($unique);
}

function minicrm_electrician_assignment_key(string $name): string
{
    return minicrm_import_key($name);
}

function minicrm_electrician_assignment_map(): array
{
    $map = [];

    foreach (electrician_users(true) as $electrician) {
        $userId = (int) ($electrician['user_id'] ?? 0);

        if ($userId <= 0) {
            continue;
        }

        foreach ([
            (string) ($electrician['name'] ?? ''),
            (string) ($electrician['user_name'] ?? ''),
        ] as $name) {
            $key = minicrm_electrician_assignment_key($name);

            if ($key === '') {
                continue;
            }

            $map[$key][] = [
                'user_id' => $userId,
                'name' => (string) ($electrician['name'] ?? $name),
            ];
        }
    }

    return $map;
}

function minicrm_find_electrician_assignment(string $name, ?array $electricianMap = null): ?array
{
    $key = minicrm_electrician_assignment_key($name);

    if ($key === '') {
        return null;
    }

    $electricianMap ??= minicrm_electrician_assignment_map();
    $matches = $electricianMap[$key] ?? [];
    $unique = [];

    foreach ($matches as $match) {
        $unique[(int) $match['user_id']] = $match;
    }

    return count($unique) === 1 ? reset($unique) : null;
}

function minicrm_find_electrician_assignment_for_item(array $item, ?array $electricianMap = null): ?array
{
    $electricianMap ??= minicrm_electrician_assignment_map();

    foreach (minicrm_work_item_electrician_assignment_candidates($item) as $candidate) {
        $electrician = minicrm_find_electrician_assignment($candidate, $electricianMap);

        if ($electrician !== null) {
            $electrician['matched_name'] = $candidate;

            return $electrician;
        }
    }

    return null;
}

function minicrm_set_request_electrician_assignment(int $requestId, int $electricianUserId): void
{
    $request = find_connection_request($requestId);
    $currentStatus = is_array($request) ? (string) ($request['electrician_status'] ?? 'unassigned') : 'unassigned';
    $status = in_array($currentStatus, ['in_progress', 'completed'], true) ? $currentStatus : 'assigned';

    db_query(
        'UPDATE `connection_requests`
         SET `assigned_electrician_user_id` = ?, `electrician_status` = ?
         WHERE `id` = ?',
        [$electricianUserId, $status, $requestId]
    );
    $electrician = find_electrician_by_user($electricianUserId);
    record_connection_request_activity(
        $requestId,
        'assignment',
        'Adatlap felelőse módosítva',
        trim((string) ($electrician['name'] ?? $electrician['email'] ?? '')) ?: ('Szerelő #' . $electricianUserId)
    );
}

function minicrm_assign_request_to_imported_electrician(array $item, int $requestId, ?array $electricianMap = null): array
{
    if ($requestId <= 0 || !db_column_exists('connection_requests', 'assigned_electrician_user_id')) {
        return ['assigned' => false, 'reason' => 'missing_schema'];
    }

    $candidates = minicrm_work_item_electrician_assignment_candidates($item);

    if ($candidates === []) {
        return ['assigned' => false, 'reason' => 'missing_name'];
    }

    $electrician = minicrm_find_electrician_assignment_for_item($item, $electricianMap);

    if ($electrician === null) {
        return ['assigned' => false, 'reason' => 'unmatched', 'name' => implode(', ', $candidates)];
    }

    minicrm_set_request_electrician_assignment($requestId, (int) $electrician['user_id']);

    return [
        'assigned' => true,
        'name' => (string) ($electrician['matched_name'] ?? $electrician['name']),
        'electrician_user_id' => (int) $electrician['user_id'],
    ];
}

function minicrm_assign_work_item_to_electrician(int $workItemId, ?array $electricianMap = null): array
{
    $item = find_minicrm_work_item($workItemId);

    if ($item === null) {
        return ['assigned' => false, 'reason' => 'missing_item'];
    }

    $candidates = minicrm_work_item_electrician_assignment_candidates($item);

    if ($candidates === []) {
        return ['assigned' => false, 'reason' => 'missing_name'];
    }

    $electrician = minicrm_find_electrician_assignment_for_item($item, $electricianMap);

    if ($electrician === null) {
        return ['assigned' => false, 'reason' => 'unmatched', 'name' => implode(', ', $candidates)];
    }

    $linkResult = ensure_minicrm_work_item_connection_request($workItemId);

    if (!($linkResult['ok'] ?? false)) {
        return ['assigned' => false, 'reason' => 'link_failed', 'message' => (string) ($linkResult['message'] ?? '')];
    }

    $requestId = (int) ($linkResult['request_id'] ?? 0);

    if ($requestId <= 0) {
        return ['assigned' => false, 'reason' => 'link_failed'];
    }

    minicrm_set_request_electrician_assignment($requestId, (int) $electrician['user_id']);

    return [
        'assigned' => true,
        'name' => (string) ($electrician['matched_name'] ?? $electrician['name']),
        'electrician_user_id' => (int) $electrician['user_id'],
    ];
}

function minicrm_assign_imported_work_items_to_electricians(): array
{
    if (minicrm_import_schema_errors() !== []) {
        return ['ok' => false, 'message' => 'Elobb hozd letre/frissitsd a MiniCRM import tablakat.'];
    }

    if (electrician_schema_errors() !== []) {
        return ['ok' => false, 'message' => 'Elobb futtasd le a database/electrician_workflow.sql fajlt, es hozz letre szereloi fiokokat.'];
    }

    $items = db_query(
        'SELECT *
         FROM `minicrm_work_items`
         ORDER BY `id` ASC'
    )->fetchAll();

    if ($items === []) {
        return ['ok' => false, 'message' => 'Nincs szetoszthato MiniCRM munka.'];
    }

    $electricianMap = minicrm_electrician_assignment_map();
    $assigned = 0;
    $missingName = 0;
    $unmatched = [];
    $failed = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $result = minicrm_assign_work_item_to_electrician((int) $item['id'], $electricianMap);

        if ($result['assigned'] ?? false) {
            $assigned++;
            continue;
        }

        $reason = (string) ($result['reason'] ?? '');

        if ($reason === 'missing_name') {
            $missingName++;
        } elseif ($reason === 'unmatched') {
            $name = trim((string) ($result['name'] ?? ''));

            if ($name !== '') {
                $unmatched[$name] = ($unmatched[$name] ?? 0) + 1;
            }
        } else {
            $failed++;
        }
    }

    $message = 'MiniCRM munkak szetosztasa kesz: ' . $assigned . ' munka szerelore kiadva, '
        . $missingName . ' szerelo nev nelkuli, ' . array_sum($unmatched) . ' nem parosithato nev, '
        . $failed . ' hiba.';

    if ($unmatched !== []) {
        arsort($unmatched);
        $message .= ' Nem talalt szerelok: ' . implode(', ', array_slice(array_keys($unmatched), 0, 12)) . '.';
    }

    return [
        'ok' => true,
        'message' => $message,
        'assigned' => $assigned,
        'missing_name' => $missingName,
        'unmatched' => $unmatched,
        'failed' => $failed,
    ];
}

function minicrm_work_item_raw_email(array $item): string
{
    $email = minicrm_work_item_raw_value_by_patterns($item, ['/e-?mail/', '/email/', '/levelcim/']);

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    foreach (minicrm_work_item_raw_fields($item) as $field) {
        $value = (string) ($field['value'] ?? '');

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches)) {
            return $matches[0];
        }
    }

    return '';
}

function minicrm_work_item_normalized_date(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{4})[.\-\/ ]+(\d{1,2})[.\-\/ ]+(\d{1,2})/', $value, $matches)) {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
    } elseif (preg_match('/^(\d{1,2})[.\-\/ ]+(\d{1,2})[.\-\/ ]+(\d{4})/', $value, $matches)) {
        $year = (int) $matches[3];
        $month = (int) $matches[2];
        $day = (int) $matches[1];
    } else {
        return '';
    }

    if (!checkdate($month, $day, $year)) {
        return '';
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function minicrm_work_item_legal_entity(array $item): int
{
    $text = minicrm_import_lower(
        (string) ($item['card_name'] ?? '') . ' ' .
        (string) ($item['customer_name'] ?? '') . ' ' .
        minicrm_work_item_raw_value_by_patterns($item, ['/nem lakossagi/', '/ceg/', '/adoszam/'])
    );

    if (preg_match('/\b(kft|bt|zrt|nyrt|ev|egyeni vallalkozo|onkormanyzat)\b/u', $text)) {
        return 1;
    }

    return 0;
}

function minicrm_work_item_customer_data(array $item): array
{
    $customerProfile = minicrm_customer_profile_for_work_item($item);
    $siteAddress = trim((string) ($item['site_address'] ?? ''));
    $mailingAddress = trim((string) ($item['mailing_address'] ?? ''));
    $postalAddress = $mailingAddress !== '' ? $mailingAddress : $siteAddress;
    $postalCode = trim((string) ($item['postal_code'] ?? ''));
    $city = trim((string) ($item['city'] ?? ''));

    if ($city === '' && $siteAddress !== '' && str_contains($siteAddress, ',')) {
        $city = trim((string) strtok($siteAddress, ','));
    }

    $profileName = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_name', ['Szemely1 Nev', 'Személy1: Név', 'Nev', 'Név']) : '';
    $profileEmail = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_email', ['Szemely1 Email', 'Személy1: Email', 'Ceg Email', 'Cég: Email', 'Email']) : '';
    $profilePhone = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_phone', ['Szemely1 Telefon', 'Személy1: Telefon', 'Ceg Telefon', 'Cég: Telefon', 'Telefon']) : '';

    return [
        'is_legal_entity' => minicrm_work_item_legal_entity($item),
        'requester_name' => trim((string) ($profileName ?: $item['customer_name'] ?: $item['card_name'] ?: 'MiniCRM ugyfel')),
        'birth_name' => trim((string) ($item['birth_name'] ?? '')),
        'company_name' => '',
        'tax_number' => minicrm_work_item_raw_value_by_patterns($item, ['/adoszam/']),
        'phone' => $profilePhone !== '' ? $profilePhone : minicrm_work_item_raw_value_by_patterns($item, ['/telefon/', '/mobilszam/', '/phone/']),
        'email' => $profileEmail !== '' ? $profileEmail : minicrm_work_item_raw_email($item),
        'postal_address' => $postalAddress !== '' ? $postalAddress : (string) ($item['card_name'] ?? 'MiniCRM import'),
        'postal_code' => $postalCode,
        'city' => $city,
        'mailing_address' => $mailingAddress,
        'mother_name' => trim((string) ($item['mother_name'] ?? '')),
        'birth_place' => trim((string) ($item['birth_place'] ?? '')),
        'birth_date' => minicrm_work_item_normalized_date((string) ($item['birth_date'] ?? '')),
        'contact_data_accepted' => 0,
        'source' => 'MiniCRM import',
        'status' => trim((string) ($item['minicrm_status'] ?? 'MiniCRM import')),
        'notes' => 'MiniCRM azonosito: ' . (string) ($item['source_id'] ?? ''),
    ];
}

function minicrm_work_item_request_notes(array $item): string
{
    $notes = [];

    foreach ([
        'Munka tipusa' => $item['work_type'] ?? '',
        'Munka jellege' => $item['work_kind'] ?? '',
        'Vezetek' => $item['wire_type'] ?? '',
        'Meroszekreny' => $item['meter_cabinet'] ?? '',
        'Vezetek megjegyzes' => $item['wire_note'] ?? '',
        'Szekreny megjegyzes' => $item['cabinet_note'] ?? '',
    ] as $label => $value) {
        $value = trim((string) $value);

        if ($value !== '') {
            $notes[] = $label . ': ' . $value;
        }
    }

    $rawNote = minicrm_work_item_raw_value_by_patterns($item, ['/megjegyzes/', '/uzenet/', '/szoveg/', '/leiras/']);

    if ($rawNote !== '') {
        $notes[] = $rawNote;
    }

    return implode("\n", array_unique($notes));
}

function minicrm_work_item_request_data(array $item): array
{
    $siteAddress = trim((string) ($item['site_address'] ?? ''));
    $meterSerial = trim((string) ($item['meter_serial'] ?? ''));

    if ($siteAddress === '') {
        $siteAddress = trim((string) ($item['mailing_address'] ?? ''));
    }

    if ($siteAddress === '') {
        $siteAddress = trim((string) ($item['card_name'] ?? 'MiniCRM import'));
    }

    $requestType = trim((string) ($item['request_type'] ?? ''));
    $requestType = isset(connection_request_type_options()[$requestType]) ? $requestType : 'phase_upgrade';
    $meterSerial = $meterSerial !== '' ? $meterSerial : trim((string) ($item['controlled_meter_serial'] ?? ''));

    return [
        'request_type' => $requestType,
        'project_name' => trim((string) ($item['card_name'] ?? 'MiniCRM munka')),
        'site_address' => $siteAddress,
        'site_postal_code' => trim((string) ($item['postal_code'] ?? '')),
        'hrsz' => trim((string) ($item['hrsz'] ?? '')),
        'meter_serial' => $meterSerial,
        'consumption_place_id' => trim((string) ($item['consumption_place_id'] ?? '')),
        'existing_general_power' => minicrm_work_item_raw_value_by_patterns($item, ['/meglevo.*mindennap/', '/jelenlegi.*mindennap/', '/jml/']),
        'requested_general_power' => minicrm_work_item_requested_general_power($item),
        'existing_h_tariff_power' => minicrm_work_item_raw_value_by_patterns($item, ['/meglevo.*h tarifa/', '/jelenlegi.*h tarifa/', '/jelenlegi_h/']),
        'requested_h_tariff_power' => minicrm_work_item_requested_h_tariff_power($item),
        'existing_controlled_power' => minicrm_work_item_raw_value_by_patterns($item, ['/meglevo.*vezerelt/', '/jelenlegi.*vezerelt/', '/jvl/']),
        'requested_controlled_power' => minicrm_work_item_requested_controlled_power($item),
        'notes' => minicrm_work_item_request_notes($item),
    ];
}

function minicrm_db_text(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }

    return substr($value, 0, $length);
}

function minicrm_connection_request_file_type(array $file): string
{
    $text = minicrm_import_key((string) ($file['label'] ?? '') . ' ' . (string) ($file['original_name'] ?? ''));

    if (preg_match('/meghatalmazas|alairt meghatalmazas/', $text)) {
        return 'authorization';
    }

    if (preg_match('/tulajdoni|foldhivatali/', $text)) {
        return 'title_deed';
    }

    if (preg_match('/terkep|map/', $text)) {
        return 'map_copy';
    }

    if (preg_match('/hozzajarulo|hozzajarulas/', $text)) {
        return 'consent_statement';
    }

    if (preg_match('/klima.*adatlap|adatlap.*klima|muszaki adatlap/', $text)) {
        return 'h_tariff_datasheet';
    }

    if (preg_match('/klima|matrica/', $text)) {
        return 'h_tariff_label';
    }

    if (preg_match('/oszlop/', $text)) {
        return 'utility_pole';
    }

    if (preg_match('/tetotarto|falihorog|kampo/', $text)) {
        return 'roof_hook';
    }

    if (preg_match('/eloszto|elosztotabla|lakás aramkori/', $text)) {
        return 'distribution_board';
    }

    if (preg_match('/mero|meroor|szekreny/', $text)) {
        return 'meter_close';
    }

    return 'completed_document';
}

function minicrm_connection_request_document_type(array $file): ?string
{
    $text = minicrm_import_key((string) ($file['label'] ?? '') . ' ' . (string) ($file['original_name'] ?? ''));

    if (preg_match('/ugyinditas|igenybejelento|bekuldott igeny/', $text)) {
        return preg_match('/alairt|elfogadott|mvm alairt/', $text) ? 'accepted_request' : 'submitted_request';
    }

    if (preg_match('/meghatalmazas|alairt meghatalmazas/', $text)) {
        return 'authorization';
    }

    if (preg_match('/terv|kiviteli/', $text)) {
        return 'execution_plan';
    }

    if (preg_match('/plombabontas|plomba bontas|plombabontasi/', $text)) {
        return 'seal_removal';
    }

    if (preg_match('/kesz.*beavatkozasi|beavatkozasi.*kesz/', $text)) {
        return 'completed_intervention_sheet';
    }

    if (preg_match('/beavatkozasi/', $text)) {
        return 'intervention_sheet';
    }

    if (preg_match('/epitesi naplo/', $text)) {
        return 'construction_log';
    }

    if (preg_match('/nyilatkozatok adatlap/', $text)) {
        return 'technical_declaration';
    }

    if (preg_match('/muszaki atadas|atadas atveteli/', $text)) {
        return 'technical_handover';
    }

    return null;
}

function sync_minicrm_work_item_files_to_connection_request(int $workItemId, int $requestId): array
{
    $request = find_connection_request($requestId);

    if ($request === null || !db_table_exists('connection_request_files')) {
        return [];
    }

    $definitions = connection_request_upload_definitions();
    $warnings = [];

    foreach (minicrm_work_item_files($workItemId) as $file) {
        $storagePath = (string) ($file['storage_path'] ?? '');

        if ($storagePath === '' || !is_file($storagePath)) {
            continue;
        }

        $exists = db_query(
            'SELECT 1 FROM `connection_request_files` WHERE `connection_request_id` = ? AND `storage_path` = ? LIMIT 1',
            [$requestId, $storagePath]
        )->fetchColumn();

        if (!$exists) {
            $fileType = minicrm_connection_request_file_type($file);
            $label = (string) ($definitions[$fileType]['label'] ?? ($file['label'] ?? 'MiniCRM dokumentum'));

            db_query(
                'INSERT INTO `connection_request_files`
                    (`connection_request_id`, `file_type`, `label`, `original_name`, `stored_name`, `storage_path`, `mime_type`, `file_size`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $requestId,
                    $fileType,
                    minicrm_db_text($label, 160),
                    minicrm_db_text((string) ($file['original_name'] ?? basename($storagePath)), 190),
                    minicrm_db_text((string) ($file['stored_name'] ?? basename($storagePath)), 190),
                    minicrm_db_text($storagePath, 255),
                    minicrm_db_text((string) ($file['mime_type'] ?? 'application/octet-stream'), 80),
                    min((int) ($file['file_size'] ?? filesize($storagePath)), 2147483647),
                ]
            );
        }

        $documentType = minicrm_connection_request_document_type($file);

        if ($documentType === null || !db_table_exists('connection_request_documents')) {
            continue;
        }

        $documentExists = db_query(
            'SELECT 1 FROM `connection_request_documents` WHERE `connection_request_id` = ? AND `storage_path` = ? LIMIT 1',
            [$requestId, $storagePath]
        )->fetchColumn();

        if ($documentExists) {
            continue;
        }

        try {
            $types = mvm_document_types();
            db_query(
                'INSERT INTO `connection_request_documents`
                    (`connection_request_id`, `customer_id`, `document_type`, `title`, `original_name`, `stored_name`,
                     `storage_path`, `mime_type`, `file_size`, `created_by_user_id`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $requestId,
                    (int) $request['customer_id'],
                    $documentType,
                    $types[$documentType] ?? 'MiniCRM MVM dokumentum',
                    minicrm_db_text((string) ($file['original_name'] ?? basename($storagePath)), 190),
                    minicrm_db_text((string) ($file['stored_name'] ?? basename($storagePath)), 190),
                    minicrm_db_text($storagePath, 255),
                    minicrm_db_text((string) ($file['mime_type'] ?? 'application/octet-stream'), 120),
                    min((int) ($file['file_size'] ?? filesize($storagePath)), 2147483647),
                    is_array(current_user()) ? (int) current_user()['id'] : null,
                ]
            );
        } catch (Throwable $exception) {
            $warnings[] = APP_DEBUG
                ? 'MiniCRM MVM dokumentum szinkron hiba: ' . $exception->getMessage()
                : 'Egy MiniCRM dokumentumot nem sikerult MVM dokumentumkent atvenni.';
        }
    }

    return $warnings;
}

function ensure_minicrm_work_item_connection_request(int $workItemId): array
{
    $schemaErrors = minicrm_connection_request_link_schema_errors();

    if ($schemaErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $schemaErrors)];
    }

    $item = find_minicrm_work_item($workItemId);

    if ($item === null) {
        return ['ok' => false, 'message' => 'A MiniCRM munka nem talalhato.'];
    }

    $link = minicrm_work_item_connection_link($workItemId);
    $customerData = minicrm_work_item_customer_data($item);
    $requestData = minicrm_work_item_request_data($item);
    $user = current_user();
    $createdBy = is_array($user) ? (int) $user['id'] : null;
    $customerId = $link !== null ? (int) $link['customer_id'] : 0;
    $requestId = $link !== null ? (int) $link['connection_request_id'] : 0;
    $existingRequest = $requestId > 0 ? find_connection_request($requestId) : null;

    if ($existingRequest !== null) {
        $customerId = (int) $existingRequest['customer_id'];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $existingCustomer = $customerId > 0 ? find_customer($customerId) : null;

        if ($existingCustomer !== null) {
            foreach (['phone', 'email', 'postal_address', 'postal_code', 'city', 'mailing_address'] as $customerField) {
                if (trim((string) ($customerData[$customerField] ?? '')) === '' && trim((string) ($existingCustomer[$customerField] ?? '')) !== '') {
                    $customerData[$customerField] = (string) $existingCustomer[$customerField];
                }
            }

            update_customer($customerId, $customerData);
        } else {
            $customerId = create_customer($customerData, null, $createdBy);

            if ($existingRequest !== null) {
                db_query('UPDATE `connection_requests` SET `customer_id` = ? WHERE `id` = ?', [$customerId, $requestId]);
            }
        }

        if ($requestId > 0 && $existingRequest !== null) {
            save_connection_request($customerId, $requestData, $requestId, $createdBy, true);
        } else {
            $requestId = save_connection_request($customerId, $requestData, null, $createdBy, true);
            db_query(
                'UPDATE `connection_requests`
                 SET `request_status` = ?, `submitted_at` = COALESCE(`submitted_at`, NOW()), `closed_at` = COALESCE(`closed_at`, NOW())
                 WHERE `id` = ?',
                ['finalized', $requestId]
            );
        }

        db_query(
            'INSERT INTO `minicrm_connection_request_links`
                (`work_item_id`, `source_id`, `customer_id`, `connection_request_id`)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `source_id` = VALUES(`source_id`),
                `customer_id` = VALUES(`customer_id`),
                `connection_request_id` = VALUES(`connection_request_id`),
                `updated_at` = CURRENT_TIMESTAMP',
            [
                $workItemId,
                (string) ($item['source_id'] ?? ''),
                $customerId,
                $requestId,
            ]
        );

        $customerProfile = minicrm_customer_profile_for_work_item($item);

        if (is_array($customerProfile)) {
            db_query(
                'UPDATE `minicrm_customer_profiles` SET `customer_id` = ? WHERE `id` = ?',
                [$customerId, (int) $customerProfile['id']]
            );
            sync_minicrm_customer_profile_to_customer($customerId, $customerProfile);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'message' => APP_DEBUG ? $exception->getMessage() : 'A MiniCRM munka MVM dokumentumhoz kapcsolasa sikertelen.',
        ];
    }

    $warnings = [];

    try {
        $assignment = minicrm_assign_request_to_imported_electrician($item, $requestId);

        if (($assignment['reason'] ?? '') === 'unmatched' && !empty($assignment['name'])) {
            $warnings[] = 'Nem talalhato aktiv szerelo ezzel a MiniCRM nevvel: ' . (string) $assignment['name'];
        }
    } catch (Throwable $exception) {
        $warnings[] = APP_DEBUG
            ? 'MiniCRM szereloi kiosztas sikertelen: ' . $exception->getMessage()
            : 'A MiniCRM szereloi kiosztas automatikus beallitasa nem sikerult.';
    }

    try {
        $warnings = array_merge($warnings, sync_minicrm_work_item_files_to_connection_request($workItemId, $requestId));
    } catch (Throwable $exception) {
        $warnings[] = APP_DEBUG
            ? 'MiniCRM fajlok szinkronizalasa sikertelen: ' . $exception->getMessage()
            : 'A MiniCRM fajlok automatikus atvetele nem sikerult.';
    }

    return [
        'ok' => true,
        'message' => 'A MiniCRM munka MVM dokumentumgeneralohoz kapcsolva.',
        'request_id' => $requestId,
        'customer_id' => $customerId,
        'warnings' => $warnings,
    ];
}

function minicrm_work_item_count(bool $archivedOnly = false): int
{
    if (!db_table_exists('minicrm_work_items')) {
        return 0;
    }

    return (int) db_query('SELECT COUNT(*) FROM `minicrm_work_items`' . minicrm_work_item_archive_where($archivedOnly))->fetchColumn();
}

function minicrm_customer_profiles_for_customer(int $customerId): array
{
    if ($customerId <= 0 || !db_table_exists('minicrm_customer_profiles')) {
        return [];
    }

    return db_query(
        'SELECT *
         FROM `minicrm_customer_profiles`
         WHERE `customer_id` = ?
         ORDER BY COALESCE(`modified_date`, `status_updated_at`, `created_date`) DESC, `id` DESC',
        [$customerId]
    )->fetchAll();
}

function minicrm_customer_profiles_by_source_ids(array $sourceIds): array
{
    if (!db_table_exists('minicrm_customer_profiles')) {
        return [];
    }

    $sourceIds = array_values(array_unique(array_filter(
        array_map(static fn (mixed $sourceId): string => minicrm_source_id_key((string) $sourceId), $sourceIds),
        static fn (string $sourceId): bool => $sourceId !== ''
    )));

    if ($sourceIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($sourceIds), '?'));
    $rows = db_query(
        'SELECT *
         FROM `minicrm_customer_profiles`
         WHERE LOWER(`source_id`) IN (' . $placeholders . ')
         ORDER BY COALESCE(`modified_date`, `person_modified_date`, `status_updated_at`, `created_date`) DESC, `id` DESC',
        $sourceIds
    )->fetchAll();
    $profiles = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $sourceId = minicrm_source_id_key((string) ($row['source_id'] ?? ''));

        if ($sourceId !== '' && !isset($profiles[$sourceId])) {
            $profiles[$sourceId] = $row;
        }
    }

    return $profiles;
}

function minicrm_customer_profile_for_work_item(array $item): ?array
{
    if (!db_table_exists('minicrm_customer_profiles')) {
        return null;
    }

    $sourceId = trim((string) ($item['source_id'] ?? ''));

    if ($sourceId !== '') {
        $profile = db_query(
            'SELECT *
             FROM `minicrm_customer_profiles`
             WHERE LOWER(`source_id`) = LOWER(?)
             ORDER BY COALESCE(`modified_date`, `person_modified_date`, `status_updated_at`, `created_date`) DESC, `id` DESC
             LIMIT 1',
            [$sourceId]
        )->fetch();

        if (is_array($profile)) {
            return $profile;
        }
    }

    if (db_table_exists('minicrm_connection_request_links')) {
        $workItemId = (int) ($item['id'] ?? 0);

        if ($workItemId > 0) {
            $profile = db_query(
                'SELECT p.*
                 FROM `minicrm_connection_request_links` l
                 INNER JOIN `minicrm_customer_profiles` p ON p.`customer_id` = l.`customer_id`
                 WHERE l.`work_item_id` = ?
                 ORDER BY COALESCE(p.`modified_date`, p.`person_modified_date`, p.`status_updated_at`, p.`created_date`) DESC, p.`id` DESC
                 LIMIT 1',
                [$workItemId]
            )->fetch();

            if (is_array($profile)) {
                return $profile;
            }
        }
    }

    foreach (minicrm_work_item_project_ids($item) as $projectId) {
        $profile = db_query(
            'SELECT *
             FROM `minicrm_customer_profiles`
             WHERE `project_id` = ?
             ORDER BY COALESCE(`modified_date`, `person_modified_date`, `status_updated_at`, `created_date`) DESC, `id` DESC
             LIMIT 1',
            [$projectId]
        )->fetch();

        if (is_array($profile)) {
            return $profile;
        }
    }

    $wantedKeys = array_values(array_unique(array_filter([
        minicrm_import_key((string) ($item['customer_name'] ?? '')),
        minicrm_import_key((string) ($item['card_name'] ?? '')),
    ])));

    if ($wantedKeys === []) {
        return null;
    }

    $rows = db_query(
        'SELECT *
         FROM `minicrm_customer_profiles`
         ORDER BY COALESCE(`modified_date`, `person_modified_date`, `status_updated_at`, `created_date`) DESC, `id` DESC
         LIMIT 2000'
    )->fetchAll();

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $profileKeys = array_values(array_unique(array_filter([
            minicrm_import_key((string) ($row['card_name'] ?? '')),
            minicrm_import_key(minicrm_customer_profile_display_value($row, 'person_name', ['Szemely1 Nev', 'Személy1: Név', 'Nev', 'Név'])),
        ])));

        foreach ($wantedKeys as $wantedKey) {
            foreach ($profileKeys as $profileKey) {
                if (
                    $wantedKey === $profileKey
                    || (strlen($wantedKey) >= 8 && str_starts_with($profileKey, $wantedKey))
                    || (strlen($profileKey) >= 8 && str_starts_with($wantedKey, $profileKey))
                ) {
                    return $row;
                }
            }
        }
    }

    return null;
}

function minicrm_customer_profiles_by_connection_request_ids(array $requestIds): array
{
    if (
        !db_table_exists('minicrm_customer_profiles')
        || !db_table_exists('minicrm_connection_request_links')
        || !db_table_exists('connection_requests')
    ) {
        return [];
    }

    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));

    if ($requestIds === []) {
        return [];
    }

    $profiles = [];
    $rows = db_query(
        'SELECT l.`connection_request_id` AS linked_request_id, p.*
         FROM `minicrm_connection_request_links` l
         INNER JOIN `minicrm_customer_profiles` p ON LOWER(p.`source_id`) = LOWER(l.`source_id`)
         WHERE l.`connection_request_id` IN (' . db_in_placeholders($requestIds) . ')
         ORDER BY COALESCE(p.`modified_date`, p.`person_modified_date`, p.`status_updated_at`, p.`created_date`) DESC, p.`id` DESC',
        $requestIds
    )->fetchAll();

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $requestId = (int) ($row['linked_request_id'] ?? 0);

        if ($requestId > 0 && !isset($profiles[$requestId])) {
            $profiles[$requestId] = $row;
        }
    }

    $missingRequestIds = array_values(array_diff($requestIds, array_keys($profiles)));

    if ($missingRequestIds === []) {
        return $profiles;
    }

    $fallbackRows = db_query(
        'SELECT cr.`id` AS linked_request_id, p.*
         FROM `connection_requests` cr
         INNER JOIN `minicrm_customer_profiles` p ON p.`customer_id` = cr.`customer_id`
         WHERE cr.`id` IN (' . db_in_placeholders($missingRequestIds) . ')
         ORDER BY COALESCE(p.`modified_date`, p.`person_modified_date`, p.`status_updated_at`, p.`created_date`) DESC, p.`id` DESC',
        $missingRequestIds
    )->fetchAll();

    foreach ($fallbackRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $requestId = (int) ($row['linked_request_id'] ?? 0);

        if ($requestId > 0 && !isset($profiles[$requestId])) {
            $profiles[$requestId] = $row;
        }
    }

    return $profiles;
}

function minicrm_customer_profile_for_connection_request(array $request): ?array
{
    $requestId = (int) ($request['id'] ?? 0);

    if ($requestId <= 0) {
        return null;
    }

    $profiles = minicrm_customer_profiles_by_connection_request_ids([$requestId]);

    return $profiles[$requestId] ?? null;
}

function minicrm_customer_profile_raw_fields(array $profile): array
{
    $decoded = json_decode((string) ($profile['raw_payload'] ?? '{}'), true);
    $columns = is_array($decoded) && is_array($decoded['columns'] ?? null) ? $decoded['columns'] : [];
    $fields = [];

    foreach ($columns as $position => $column) {
        if (!is_array($column)) {
            continue;
        }

        $label = minicrm_import_header_label((string) ($column['header'] ?? ('Oszlop ' . ($position + 1))));
        $value = minicrm_import_clean_field_value($column['value'] ?? '');

        if ($label === '' || $value === '') {
            continue;
        }

        $fields[] = [
            'label' => $label,
            'value' => $value,
        ];
    }

    return $fields;
}

function minicrm_customer_profile_raw_value_by_labels(array $profile, array $labels): string
{
    $wanted = [];

    foreach ($labels as $label) {
        $wanted[minicrm_import_key((string) $label)] = true;
    }

    foreach (minicrm_customer_profile_raw_fields($profile) as $field) {
        $key = minicrm_import_key((string) ($field['label'] ?? ''));

        if (isset($wanted[$key])) {
            return trim((string) ($field['value'] ?? ''));
        }
    }

    return '';
}

function minicrm_customer_profile_display_value(array $profile, string $column, array $labels): string
{
    $value = trim((string) ($profile[$column] ?? ''));

    if ($value !== '') {
        return $value;
    }

    return minicrm_customer_profile_raw_value_by_labels($profile, $labels);
}

function minicrm_work_item_file_count(bool $archivedOnly = false): int
{
    if (!db_table_exists('minicrm_work_item_files')) {
        return 0;
    }

    if (minicrm_work_item_archive_columns_ready()) {
        return (int) db_query(
            'SELECT COUNT(*)
             FROM `minicrm_work_item_files` f
             INNER JOIN `minicrm_work_items` w ON w.id = f.work_item_id
             WHERE w.`archived_at` IS ' . ($archivedOnly ? 'NOT NULL' : 'NULL')
        )->fetchColumn();
    }

    if ($archivedOnly) {
        return 0;
    }

    return (int) db_query('SELECT COUNT(*) FROM `minicrm_work_item_files`')->fetchColumn();
}

function minicrm_work_item_file_size_total(bool $archivedOnly = false): int
{
    if (!db_table_exists('minicrm_work_item_files')) {
        return 0;
    }

    if (minicrm_work_item_archive_columns_ready()) {
        return (int) db_query(
            'SELECT COALESCE(SUM(f.`file_size`), 0)
             FROM `minicrm_work_item_files` f
             INNER JOIN `minicrm_work_items` w ON w.id = f.work_item_id
             WHERE w.`archived_at` IS ' . ($archivedOnly ? 'NOT NULL' : 'NULL')
        )->fetchColumn();
    }

    if ($archivedOnly) {
        return 0;
    }

    return (int) db_query('SELECT COALESCE(SUM(`file_size`), 0) FROM `minicrm_work_item_files`')->fetchColumn();
}

function minicrm_work_item_status_counts(bool $archivedOnly = false): array
{
    if (!db_table_exists('minicrm_work_items')) {
        return [];
    }

    $rows = db_query(
        'SELECT COALESCE(NULLIF(`minicrm_status`, \'\'), \'Nincs státusz\') AS status_name, COUNT(*) AS item_count
         FROM `minicrm_work_items`
         ' . minicrm_work_item_archive_where($archivedOnly) . '
         GROUP BY COALESCE(NULLIF(`minicrm_status`, \'\'), \'Nincs státusz\')
         ORDER BY item_count DESC, status_name ASC'
    )->fetchAll();
    $counts = [];

    foreach ($rows as $row) {
        $counts[(string) $row['status_name']] = (int) $row['item_count'];
    }

    return $counts;
}

function minicrm_work_item_document_links(array $item): array
{
    $decoded = json_decode((string) ($item['document_links_json'] ?? '[]'), true);

    return is_array($decoded) ? $decoded : [];
}

function minicrm_work_item_files(int $workItemId): array
{
    if (!db_table_exists('minicrm_work_item_files')) {
        return [];
    }

    return db_query(
        'SELECT *
         FROM `minicrm_work_item_files`
         WHERE `work_item_id` = ?
         ORDER BY `created_at` DESC, `project_id` ASC, `original_name` ASC, `id` ASC',
        [$workItemId]
    )->fetchAll();
}

function find_minicrm_work_item_file(int $fileId): ?array
{
    if (!db_table_exists('minicrm_work_item_files')) {
        return null;
    }

    $file = db_query('SELECT * FROM `minicrm_work_item_files` WHERE `id` = ? LIMIT 1', [$fileId])->fetch();

    return is_array($file) ? $file : null;
}

function store_minicrm_work_item_files(int $workItemId, array $files, string $label = 'Kézi feltöltés'): array
{
    if (!db_table_exists('minicrm_work_item_files')) {
        return ['ok' => false, 'message' => 'A MiniCRM fájltábla nem érhető el.'];
    }

    $item = find_minicrm_work_item($workItemId);

    if ($item === null) {
        return ['ok' => false, 'message' => 'A MiniCRM munka nem található.'];
    }

    $errors = validate_minicrm_work_item_file_uploads($files);

    if ($errors !== []) {
        return ['ok' => false, 'message' => implode(' ', $errors)];
    }

    $uploadedFiles = array_values(array_filter(
        $files,
        static fn (?array $file): bool => is_array($file) && uploaded_file_is_present($file)
    ));
    $targetDir = minicrm_document_upload_path() . '/' . minicrm_safe_filename((string) $item['source_id']);
    ensure_storage_dir($targetDir);
    $saved = 0;
    $messages = [];

    foreach ($uploadedFiles as $file) {
        $originalName = (string) ($file['name'] ?? 'feltoltes');
        $safeName = minicrm_safe_filename($originalName);
        $hash = hash('sha256', 'manual|' . $workItemId . '|' . $originalName . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
        $storedName = 'manual-' . substr($hash, 0, 12) . '-' . $safeName;
        $targetPath = $targetDir . '/' . $storedName;

        if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
            $messages[] = $originalName . ': nem sikerült menteni.';
            continue;
        }

        db_query(
            'INSERT INTO `minicrm_work_item_files`
                (`work_item_id`, `source_id`, `project_id`, `label`, `original_name`, `stored_name`, `zip_entry`,
                 `zip_entry_hash`, `storage_path`, `mime_type`, `file_size`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $workItemId,
                (string) $item['source_id'],
                'manual',
                trim($label) !== '' ? trim($label) : minicrm_document_label_from_filename($originalName),
                $originalName,
                $storedName,
                null,
                $hash,
                $targetPath,
                minicrm_document_mime_type($targetPath, $originalName),
                (int) ($file['size'] ?? filesize($targetPath)),
            ]
        );

        $saved++;
    }

    if ($saved === 0) {
        return ['ok' => false, 'message' => $messages !== [] ? implode(' ', $messages) : 'Nem sikerült fájlt menteni.'];
    }

    $message = $saved . ' fájl feltöltve a MiniCRM munkához.';

    if ($messages !== []) {
        $message .= ' Figyelmeztetés: ' . implode(' ', $messages);
    }

    return ['ok' => true, 'message' => $message, 'saved' => $saved];
}

function delete_minicrm_work_item_file(int $fileId, ?int $workItemId = null): array
{
    $file = find_minicrm_work_item_file($fileId);

    if ($file === null || ($workItemId !== null && (int) ($file['work_item_id'] ?? 0) !== $workItemId)) {
        return ['ok' => false, 'message' => 'A törlendő MiniCRM fájl nem található.'];
    }

    db_query('DELETE FROM `minicrm_work_item_files` WHERE `id` = ?', [$fileId]);
    delete_storage_files([(string) ($file['storage_path'] ?? '')]);

    return ['ok' => true, 'message' => 'A MiniCRM fájl törölve.'];
}

function minicrm_import_clean_field_value(mixed $value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    $value = trim((string) $value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value);
    $value = preg_replace("/\n{3,}/", "\n\n", (string) $value);

    return is_string($value) ? $value : '';
}

function minicrm_import_payload_from_columns(array $columns): array
{
    $payloadColumns = [];

    foreach ($columns as $position => $column) {
        if (!is_array($column)) {
            continue;
        }

        $index = (int) ($column['index'] ?? ($position + 1));
        $header = minicrm_import_clean($column['header'] ?? ($column['label'] ?? ('Oszlop ' . $index)));
        $value = minicrm_import_clean_field_value($column['value'] ?? '');

        if ($header === '') {
            continue;
        }

        $payloadColumns[] = [
            'index' => $index > 0 ? $index : count($payloadColumns) + 1,
            'header' => $header,
            'value' => $value,
        ];
    }

    return ['columns' => $payloadColumns];
}

function minicrm_work_item_raw_columns(array $item): array
{
    $decoded = json_decode((string) ($item['raw_payload'] ?? '{}'), true);
    $columns = is_array($decoded) && is_array($decoded['columns'] ?? null) ? $decoded['columns'] : [];
    $fields = [];

    foreach ($columns as $position => $column) {
        if (!is_array($column)) {
            continue;
        }

        $index = (int) ($column['index'] ?? ($position + 1));
        $header = minicrm_import_clean($column['header'] ?? ('Oszlop ' . $index));
        $label = minicrm_import_header_label($header);

        if ($label === '') {
            continue;
        }

        $fields[] = [
            'index' => $index > 0 ? $index : $position + 1,
            'header' => $header,
            'label' => $label,
            'value' => minicrm_import_clean_field_value($column['value'] ?? ''),
        ];
    }

    return $fields;
}

function minicrm_work_item_raw_fields(array $item): array
{
    $fields = [];

    foreach (minicrm_work_item_raw_columns($item) as $column) {
        $label = (string) ($column['label'] ?? '');
        $value = minicrm_import_clean_field_value($column['value'] ?? '');

        if ($label === '' || $value === '') {
            continue;
        }

        $fields[] = [
            'index' => (int) ($column['index'] ?? 0),
            'label' => $label,
            'value' => $value,
        ];
    }

    return $fields;
}

function minicrm_work_item_field_groups(array $item, bool $includeEmpty = false): array
{
    $groups = [
        'base' => ['title' => 'Alapadatok', 'fields' => []],
        'customer' => ['title' => 'Ugyfel es cim', 'fields' => []],
        'work' => ['title' => 'Munka es igeny', 'fields' => []],
        'technical' => ['title' => 'Muszaki es kivitelezesi adatok', 'fields' => []],
        'finance' => ['title' => 'Penzugy es arajanlat', 'fields' => []],
        'documents' => ['title' => 'Dokumentumok es linkek', 'fields' => []],
        'notes' => ['title' => 'Megjegyzesek es uzenetek', 'fields' => []],
        'other' => ['title' => 'Egyeb MiniCRM mezok', 'fields' => []],
    ];

    $fields = $includeEmpty ? minicrm_work_item_raw_columns($item) : minicrm_work_item_raw_fields($item);

    foreach ($fields as $field) {
        $key = minicrm_import_key((string) ($field['label'] ?? ''));
        $value = (string) ($field['value'] ?? '');
        $isUrl = str_starts_with($value, 'http://') || str_starts_with($value, 'https://');

        if ($isUrl) {
            $groupKey = 'documents';
        } elseif (preg_match('/^(azonosito|nev|felelos|statusz|folyamat statusz|terulet|regio|sorszam|uk szam|mvm felelos)$/', $key)) {
            $groupKey = 'base';
        } elseif (preg_match('/(szuletesi|anyja|ugyfel|levelezesi|felhasznalasi cim|iranyito|varos|utca|hazszam|emelet|helyrajzi|fogyasztasi hely|tulajdonos|berlo|haszonelvezo|lakossagi|nem lakossagi)/', $key)) {
            $groupKey = 'customer';
        } elseif (preg_match('/(megjegyzes|uzenet|szoveggel|szoveg)$/', $key)) {
            $groupKey = 'notes';
        } elseif (preg_match('/(arajan|fizetendo|dij|osszeg|brutto|egysegar|fizetes|dijbekero|elszamolhato|kifizetheto|szerelok|adminisztracios|komplett arajanlat|tobbletkoltseg|koltseg)/', $key)) {
            $groupKey = 'finance';
        } elseif (preg_match('/(feltoltes|foto|kep|lap|terv|nyilatkozat|meghatalmazas|terkep|skicc|hibalap|ugyinditas|beavatkozasi|muszaki atadas|fedlap|kivitelezoi|dokumentum|pdf|mgt|adatbekero|hcssz|villanyszamla|szamla|klima|matrica|papir|alairt|engedely|hozzajarulo|tulajdoni|foldhivatali)/', $key)) {
            $groupKey = 'documents';
        } elseif (preg_match('/(munka|igeny|igenyelt|meglevo|fazis|teljesitmeny|kva|h tarifa|vezerelt|uj fogyaszto|csatlakozo berendezes|foldkabeles|legvezetekes|felujitas|atepites|bekotesi datum|idopont|keszrejelentes|haf|mindennapszaki|plombabontas|visszahivast|elfogadta)/', $key)) {
            $groupKey = 'work';
        } elseif (preg_match('/(vezetek|kabel|szekreny|mero|plomba|oszlop|tetotarto|foldeles|fi rele|feszultseg|hibafelmero|kivitelezes|gyarto|tipus|scop|futesi|villamos|fogyasztas|n31|s300|s20|nfa|nayy)/', $key)) {
            $groupKey = 'technical';
        } else {
            $groupKey = 'other';
        }

        $groups[$groupKey]['fields'][] = $field;
    }

    return array_filter($groups, static fn (array $group): bool => $group['fields'] !== []);
}

function update_minicrm_work_item_fields(int $id, array $fieldValues): array
{
    if (!db_table_exists('minicrm_work_items')) {
        return ['ok' => false, 'message' => 'A MiniCRM import tábla nem érhető el.'];
    }

    $item = find_minicrm_work_item($id);

    if ($item === null) {
        return ['ok' => false, 'message' => 'A MiniCRM munka nem található.'];
    }

    $columns = minicrm_work_item_raw_columns($item);

    if ($columns === []) {
        return ['ok' => false, 'message' => 'Ehhez a munkához nincs menthető MiniCRM mező.'];
    }

    $postedValues = [];

    foreach ($fieldValues as $index => $value) {
        if (is_array($value)) {
            continue;
        }

        $postedValues[(int) $index] = minicrm_import_clean_field_value($value);
    }

    $hasSourceColumn = false;

    foreach ($columns as $position => $column) {
        $index = (int) ($column['index'] ?? ($position + 1));

        if (minicrm_import_key((string) ($column['label'] ?? '')) === 'azonosito') {
            $hasSourceColumn = true;
        }

        if (array_key_exists($index, $postedValues)) {
            $columns[$position]['value'] = $postedValues[$index];
        }
    }

    $headers = array_map(static fn (array $column): string => (string) ($column['header'] ?? $column['label'] ?? ''), $columns);
    $values = array_map(static fn (array $column): string => (string) ($column['value'] ?? ''), $columns);
    $data = minicrm_import_row_data_from_headers($headers, $values);
    $oldSourceId = (string) ($item['source_id'] ?? '');

    if (($data['source_id'] ?? '') === '') {
        if ($hasSourceColumn) {
            return ['ok' => false, 'message' => 'A MiniCRM azonosító nem lehet üres, mert ehhez kapcsolódnak a dokumentumok.'];
        }

        $data['source_id'] = $oldSourceId;
    }

    $newSourceId = (string) $data['source_id'];

    if ($newSourceId !== $oldSourceId) {
        $existing = db_query(
            'SELECT `id` FROM `minicrm_work_items` WHERE `source_id` = ? AND `id` <> ? LIMIT 1',
            [$newSourceId, $id]
        )->fetch();

        if (is_array($existing)) {
            return ['ok' => false, 'message' => 'Ez a MiniCRM azonosító már egy másik munkához tartozik.'];
        }
    }

    $data['raw_payload'] = minicrm_import_json(minicrm_import_payload_from_columns($columns));
    $updateColumns = [
        'source_id',
        'card_name',
        'customer_name',
        'responsible',
        'minicrm_status',
        'work_type',
        'work_kind',
        'request_type',
        'date_value',
        'submitted_date',
        'birth_name',
        'birth_place',
        'birth_date',
        'mother_name',
        'mailing_address',
        'postal_code',
        'city',
        'site_address',
        'street',
        'house_number',
        'floor_door',
        'hrsz',
        'consumption_place_id',
        'meter_serial',
        'controlled_meter_serial',
        'wire_type',
        'meter_cabinet',
        'meter_location',
        'pole_type',
        'wire_note',
        'cabinet_note',
        'document_links_json',
        'raw_payload',
    ];
    $params = [];

    foreach ($updateColumns as $column) {
        if (in_array($column, ['source_id', 'card_name', 'document_links_json', 'raw_payload'], true)) {
            $params[] = $data[$column] ?? '';
        } else {
            $params[] = minicrm_import_nullable((string) ($data[$column] ?? ''));
        }
    }

    $params[] = $id;
    $pdo = db();
    $pdo->beginTransaction();

    try {
        db_query(
            'UPDATE `minicrm_work_items`
             SET `' . implode('` = ?, `', $updateColumns) . '` = ?, `updated_at` = CURRENT_TIMESTAMP
             WHERE `id` = ?',
            $params
        );

        if ($newSourceId !== $oldSourceId && db_table_exists('minicrm_work_item_files')) {
            db_query('UPDATE `minicrm_work_item_files` SET `source_id` = ? WHERE `work_item_id` = ?', [$newSourceId, $id]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'A MiniCRM mezők mentése sikertelen.'];
    }

    return ['ok' => true, 'message' => 'A MiniCRM mezők mentve.'];
}

function minicrm_szabo_dezso_5_apartment_power_source_ids(): array
{
    return [
        '0w7q9r5d421638so1sxo23hfom90wv',
        '0v2lrgvj7f0nz8ln8z4w17e83vlfcu',
        '0qx5qet51e0vzzb9rry61pspu0lg36',
        '2b9nq4tkmq015ohcqc7v1s36bb5bvb',
        '230zhrh4sk11lkcgs54j08bxxatodd',
        '28og45k0xp0zqw2thvev2oy94qwpgr',
        '1fxaprgz7x1gimmqtyyk1tsnynseaf',
        '1o6cs9dib00araao8c8a2ga0juotse',
        '0fug9gxtjb24pjfhjhvo000tw57zgf',
        '14huloerdx049m5arv7k00b6bytwne',
        '1m356k82fl0bcxb8qxeb09vjg62et6',
        '261tik54lm08tww0g6qz1e2tkrgbfr',
        '1r3vk2rxkw0kcodtviw20luj6iojjv',
        '12w7u4x4my1m9tmy2vpa1mjy2mmzjm',
        '1t5mhxf6p81do5v7ttjd17z7737org',
        '1v8kba091519qyzmclni1asg5zb4x0',
        '1gomahghab1enuuozxqp1s6wum9x16',
        '1u8qrez3mq1esuvq0xem0uat8f47iv',
        '1axryjibod0o3bpxz2z927bzpsr2wx',
        '2od6hlpv9w1q0cdgrega0pz0vhkw2d',
    ];
}

function minicrm_is_szabo_dezso_5_apartment_power_item(array $item): bool
{
    $sourceIds = array_fill_keys(minicrm_szabo_dezso_5_apartment_power_source_ids(), true);
    $sourceId = minicrm_source_id_key((string) ($item['source_id'] ?? ''));
    $statusKey = minicrm_import_key((string) ($item['minicrm_status'] ?? ''));

    return isset($sourceIds[$sourceId]) && $statusKey === 'szabo dezso 5';
}

function minicrm_szabo_dezso_5_apartment_power_preview(array $items): array
{
    $found = 0;
    $pending = 0;
    $alreadyFixed = 0;

    foreach ($items as $item) {
        if (!minicrm_is_szabo_dezso_5_apartment_power_item($item)) {
            continue;
        }

        $found++;
        $power = strtolower(str_replace(' ', '', minicrm_work_item_requested_general_power($item)));

        if ($power === '3x16') {
            $alreadyFixed++;
        } else {
            $pending++;
        }
    }

    return [
        'target' => count(minicrm_szabo_dezso_5_apartment_power_source_ids()),
        'found' => $found,
        'pending' => $pending,
        'already_fixed' => $alreadyFixed,
    ];
}

function minicrm_szabo_dezso_5_power_field_updates(array $item): array
{
    $targetValues = [
        'igenyelt mn l1 legnagyobb' => '16',
        'igenyelt mn l2' => '16',
        'igenyelt mn l3' => '16',
        'osszes igenyelt mn teljesitmeny' => '48',
        'igenyelt osszes teljesitmeny' => '48',
        'fizetendo teljesitmeny' => '16',
        'fizetendo osszeg haf' => '79 248',
        'kva' => '11,04',
    ];
    $updates = [];

    foreach (minicrm_work_item_raw_columns($item) as $position => $column) {
        $index = (int) ($column['index'] ?? ($position + 1));
        $key = minicrm_import_key((string) ($column['label'] ?? $column['header'] ?? ''));

        if ($index > 0 && array_key_exists($key, $targetValues)) {
            $updates[$index] = $targetValues[$key];
        }
    }

    return $updates;
}

function minicrm_update_szabo_dezso_5_linked_request_power(int $requestId): array
{
    $updatedRequest = false;
    $updatedMvmForm = false;
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['request' => false, 'mvm_form' => false];
    }

    if (trim((string) ($request['requested_general_power'] ?? '')) !== '3x16') {
        db_query(
            'UPDATE `connection_requests`
             SET `requested_general_power` = ?, `updated_at` = CURRENT_TIMESTAMP
             WHERE `id` = ?',
            ['3x16', $requestId]
        );
        $updatedRequest = true;
        $request['requested_general_power'] = '3x16';
    }

    if (db_table_exists('connection_request_mvm_forms') && connection_request_mvm_form($requestId) !== null) {
        $values = connection_request_mvm_form_values($request);
        $needsFormUpdate = ($values['iml1'] ?? '') !== '16'
            || ($values['iml2'] ?? '') !== '16'
            || ($values['iml3'] ?? '') !== '16';

        $values['iml1'] = '16';
        $values['iml2'] = '16';
        $values['iml3'] = '16';
        $values = mvm_recalculate_power_financials(mvm_apply_plan_field_defaults($values));

        if ($needsFormUpdate) {
            db_query(
                'UPDATE `connection_request_mvm_forms`
                 SET `form_data` = ?, `updated_at` = CURRENT_TIMESTAMP
                 WHERE `connection_request_id` = ?',
                [json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $requestId]
            );
            $updatedMvmForm = true;
        }
    }

    if ($updatedRequest || $updatedMvmForm) {
        record_connection_request_activity(
            $requestId,
            'data_update',
            'Igényelt teljesítmény módosítva',
            'Szabó Dezső 5 lakás: 1x32 A helyett 3x16 A.'
        );
    }

    return ['request' => $updatedRequest, 'mvm_form' => $updatedMvmForm];
}

function apply_minicrm_szabo_dezso_5_apartment_power_fix(): array
{
    if (!db_table_exists('minicrm_work_items')) {
        return ['ok' => false, 'message' => 'Hiányzik a MiniCRM import tábla.'];
    }

    $sourceIds = minicrm_szabo_dezso_5_apartment_power_source_ids();
    $placeholders = implode(', ', array_fill(0, count($sourceIds), '?'));
    $items = db_query(
        'SELECT *
         FROM `minicrm_work_items`
         WHERE LOWER(`source_id`) IN (' . $placeholders . ')
         ORDER BY `id` ASC',
        $sourceIds
    )->fetchAll();
    $itemsBySource = [];

    foreach ($items as $item) {
        $itemsBySource[minicrm_source_id_key((string) ($item['source_id'] ?? ''))] = $item;
    }

    $summary = [
        'target' => count($sourceIds),
        'found' => 0,
        'updated' => 0,
        'linked_requests' => 0,
        'mvm_forms' => 0,
        'missing' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];
    $errors = [];

    foreach ($sourceIds as $sourceId) {
        $item = $itemsBySource[minicrm_source_id_key($sourceId)] ?? null;

        if ($item === null) {
            $summary['missing']++;
            continue;
        }

        if (!minicrm_is_szabo_dezso_5_apartment_power_item($item)) {
            $summary['skipped']++;
            continue;
        }

        $summary['found']++;
        $fieldUpdates = minicrm_szabo_dezso_5_power_field_updates($item);

        if ($fieldUpdates === []) {
            $summary['failed']++;
            $errors[] = (string) ($item['card_name'] ?? $sourceId) . ': hiányzó teljesítmény mezők.';
            continue;
        }

        $result = update_minicrm_work_item_fields((int) $item['id'], $fieldUpdates);

        if (!($result['ok'] ?? false)) {
            $summary['failed']++;
            $errors[] = (string) ($item['card_name'] ?? $sourceId) . ': ' . (string) ($result['message'] ?? 'sikertelen mentés');
            continue;
        }

        $summary['updated']++;
        $requestId = minicrm_work_item_connection_request_id((int) $item['id']);

        if ($requestId !== null) {
            $linkedResult = minicrm_update_szabo_dezso_5_linked_request_power($requestId);

            if ($linkedResult['request'] ?? false) {
                $summary['linked_requests']++;
            }

            if ($linkedResult['mvm_form'] ?? false) {
                $summary['mvm_forms']++;
            }
        }
    }

    $ok = $summary['found'] === $summary['target'] && $summary['failed'] === 0;
    $message = 'Szabó Dezső 5 teljesítményjavítás: ' . $summary['updated'] . ' lakás frissítve 3x16 A-re. '
        . 'Kapcsolt adatlap: ' . $summary['linked_requests'] . ', MVM űrlap: ' . $summary['mvm_forms'] . '.';

    if ($summary['missing'] > 0 || $summary['skipped'] > 0 || $summary['failed'] > 0) {
        $message .= ' Kimaradt: ' . $summary['missing'] . ' hiányzó, ' . $summary['skipped'] . ' eltérő státuszú, ' . $summary['failed'] . ' hibás.';
    }

    if ($errors !== []) {
        $message .= ' ' . implode(' ', array_slice($errors, 0, 3));
    }

    return ['ok' => $ok, 'message' => $message, 'summary' => $summary, 'errors' => $errors];
}

function minicrm_status_class(?string $status): string
{
    $status = minicrm_import_lower((string) $status);

    if (str_contains($status, 'kivitelez')) {
        return 'in_progress';
    }

    if (str_contains($status, 'vár') || str_contains($status, 'var')) {
        return 'draft';
    }

    if (str_contains($status, 'kész') || str_contains($status, 'kesz') || str_contains($status, 'lezár') || str_contains($status, 'lezar')) {
        return 'completed';
    }

    return 'pending';
}
