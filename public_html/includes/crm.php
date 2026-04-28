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
        'MVM Partneri tevékenységek' => [
            'title' => 'MVM Partneri tevékenységek után fizetendő tételek',
            'total_label' => 'MVM Partneri tevékenységek után fizetendő összes költség (bruttó)',
        ],
        'Villanyszerelői munkák' => [
            'title' => 'Villanyszerelői munkák után fizetendő tételek',
            'total_label' => 'Villanyszerelői munkák után fizetendő összes költség (bruttó)',
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
        'MVM/Demasz partneri munkadijak' => 'MVM Partneri tevékenységek',
        'MVM/Démász partneri munkadíjak' => 'MVM Partneri tevékenységek',
        'Mert elmeno kiepites' => 'Villanyszerelői munkák',
        'Mért elmenő kiépítés' => 'Villanyszerelői munkák',
    ];

    return $legacyMap[$category] ?? array_key_first($sections);
}

function dependency_status(): array
{
    return [
        'dompdf' => class_exists('\\Dompdf\\Dompdf'),
        'phpmailer' => class_exists('\\PHPMailer\\PHPMailer\\PHPMailer'),
        'phpspreadsheet' => class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet'),
    ];
}

function ensure_storage_dir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
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
            'SELECT c.*, u.role AS owner_role, u.name AS owner_user_name,
                    e.name AS owner_electrician_name, ct.contractor_name AS owner_contractor_name,
                    ae.name AS assigned_electrician_name
             FROM `customers` c
             LEFT JOIN `users` u ON u.id = c.created_by_user_id
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

function customer_owner_label(array $customer): string
{
    $role = (string) ($customer['owner_role'] ?? '');

    if (!empty($customer['assigned_electrician_name'])) {
        return 'Kiadott szerelő: ' . (string) $customer['assigned_electrician_name'];
    }

    if ($role === 'electrician') {
        return 'Szerelő: ' . (string) ($customer['owner_electrician_name'] ?: $customer['owner_user_name'] ?: '-');
    }

    if ($role === 'general_contractor') {
        return 'Generálkivitelező: ' . (string) ($customer['owner_contractor_name'] ?: $customer['owner_user_name'] ?: '-');
    }

    if ($role === 'admin' || $role === 'specialist') {
        return 'Admin: ' . (string) ($customer['owner_user_name'] ?: '-');
    }

    return 'Saját ügyfél / nincs felelős';
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
        db_query('UPDATE `customers` SET `user_id` = ? WHERE `id` = ?', [$userId, $customerId]);

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

    return $statement->fetchAll();
}

function all_price_items(): array
{
    $statement = db_query(
        'SELECT * FROM `quote_price_items`
         ORDER BY `sort_order` ASC, `category` ASC, `id` ASC'
    );

    return $statement->fetchAll();
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
            (string) $item['category'],
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
    $specialistId = is_staff_user() && is_array($user) ? (int) $user['id'] : null;

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
    $specialistId = is_staff_user() && is_array($user) ? (int) $user['id'] : null;

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

function find_quote(int $id): ?array
{
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
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [$quoteId, CONNECTION_REQUEST_EMAIL, APP_NAME . ' árajánlat visszajelzés', 'failed', 'PHPMailer hiányzik.']
        );
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

    $emailActions = [
        ['label' => 'Ajánlatok megnyitása', 'url' => absolute_url('/admin/quotes')],
        ['label' => 'Ajánlat szerkesztése', 'url' => absolute_url('/admin/quotes/edit?id=' . (int) $quote['id'])],
    ];

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress(CONNECTION_REQUEST_EMAIL);
        $mail->addReplyTo((string) $quote['email'], (string) $quote['requester_name']);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions);
        $mail->send();

        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [$quoteId, CONNECTION_REQUEST_EMAIL, $subject, 'sent']);

        return ['ok' => true, 'message' => 'Admin értesítés elküldve.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [$quoteId, CONNECTION_REQUEST_EMAIL, $subject, 'failed', $exception->getMessage()]
        );

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
            $emailActions
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
        $responseMessage = 'Az árajánlat elfogadását rögzítettük';

        if ($notification['ok']) {
            $responseMessage .= ', és értesítettük az admint';
        } else {
            $responseMessage .= ', de az admin email értesítés nem ment ki: ' . $notification['message'];
        }

        if ($registrationOffer['ok'] && $registrationOffer['message'] === 'Az ügyfélnek már van saját profilja.') {
            $responseMessage .= '. Az ügyfélnek már van saját profilja.';
        } elseif ($registrationOffer['ok']) {
            $responseMessage .= '. A saját profil regisztrációs lehetőségét elküldtük emailben.';
        } else {
            $responseMessage .= '. A saját profil regisztrációs email küldése nem sikerült.';
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
        'new_request' => [
            'number' => 1,
            'title' => 'Új igény érkezett',
            'description' => 'Friss vagy még piszkozatban lévő igény, amely első admin ellenőrzésre vár.',
            'variant' => 'primary',
        ],
        'quote_needed' => [
            'number' => 2,
            'title' => 'Árajánlat készítésre vár',
            'description' => 'Véglegesített igény, amelyhez még nincs kapcsolt árajánlat.',
            'variant' => 'accent',
        ],
        'quote_waiting_acceptance' => [
            'number' => 3,
            'title' => 'Árajánlat elfogadásra vár',
            'description' => 'Az ajánlat elkészült vagy ki lett küldve, de az ügyfél még nem fogadta el.',
            'variant' => 'system',
        ],
        'quote_accepted_document_needed' => [
            'number' => 4,
            'title' => 'Árajánlat elfogadva - dokumentum generálásra vár',
            'description' => 'Az ügyfél elfogadta az ajánlatot, indulhat az MVM dokumentumcsomag.',
            'variant' => 'primary',
        ],
        'document_sent_to_mvm' => [
            'number' => 5,
            'title' => 'Dokumentum legenerálva - MVM-nek beküldve',
            'description' => 'A komplett dokumentum elkészült és MVM ügyintézés alatt van.',
            'variant' => 'accent',
        ],
        'mvm_accepted_plan_needed' => [
            'number' => 6,
            'title' => 'MVM elfogadta - tervkészítésre vár',
            'description' => 'Az MVM elfogadta az igényt, a következő lépés a tervkészítés.',
            'variant' => 'system',
        ],
        'plan_accepted_work_order_needed' => [
            'number' => 7,
            'title' => 'Terv elfogadva - munkarendelésre vár',
            'description' => 'A terv elfogadva, a munkarendelés beérkezésére vár.',
            'variant' => 'primary',
        ],
        'work_order_arrived_assignable' => [
            'number' => 8,
            'title' => 'Munkarendelés megérkezett - szerelőnek kiadható',
            'description' => 'A munka kiadható szerelőnek, ha még nincs szerelő nevén.',
            'variant' => 'accent',
        ],
        'assigned_waiting_execution' => [
            'number' => 9,
            'title' => 'Szerelőnek kiadva - kivitelezésre vár',
            'description' => 'A szerelőnek kiadott munka, amelyet legfeljebb 60 napon belül el kell végezni.',
            'variant' => 'system',
        ],
        'completed_waiting_settlement' => [
            'number' => 10,
            'title' => 'Kivitelezve - elszámolásra vár',
            'description' => 'A szerelő készre jelentette, a munka elszámolásra vár.',
            'variant' => 'primary',
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

function connection_request_document_type_exists(array $documents, string $documentType): bool
{
    foreach ($documents as $document) {
        if ((string) ($document['document_type'] ?? '') === $documentType) {
            return true;
        }
    }

    return false;
}

function connection_request_admin_workflow_stage(array $request, ?array $latestQuote = null, ?array $acceptedQuote = null, array $documents = []): string
{
    $electricianStatus = (string) ($request['electrician_status'] ?? 'unassigned');

    if ($electricianStatus === 'completed' || !empty($request['after_photos_completed_at'])) {
        return 'completed_waiting_settlement';
    }

    if (!empty($request['assigned_electrician_user_id']) || in_array($electricianStatus, ['assigned', 'in_progress'], true)) {
        return 'assigned_waiting_execution';
    }

    $manualStage = normalize_admin_workflow_stage((string) ($request['admin_workflow_stage'] ?? ''));

    if ($acceptedQuote !== null) {
        if (connection_request_document_type_exists($documents, 'complete_package')) {
            $automaticStage = 'document_sent_to_mvm';
        } else {
            $automaticStage = 'quote_accepted_document_needed';
        }
    } elseif ($latestQuote !== null) {
        $automaticStage = 'quote_waiting_acceptance';
    } elseif ((string) ($request['request_status'] ?? 'draft') === 'finalized') {
        $automaticStage = 'quote_needed';
    } else {
        $automaticStage = 'new_request';
    }

    if ($manualStage !== null && admin_workflow_stage_number($manualStage) >= admin_workflow_stage_number($automaticStage)) {
        return $manualStage;
    }

    return $automaticStage;
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

function branded_email_html(string $title, string $lead, array $sections = [], array $actions = []): string
{
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

function branded_email_text(string $title, string $lead, array $sections = [], array $actions = []): string
{
    $lines = [
        APP_NAME,
        '',
        $title,
        '',
        $lead,
        '',
    ];

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

function apply_branded_email(object $mail, string $title, string $lead, array $sections = [], array $actions = []): void
{
    $mail->isHTML(true);
    $mail->Body = branded_email_html($title, $lead, $sections, $actions);
    $mail->AltBody = branded_email_text($title, $lead, $sections, $actions);
}

function send_password_reset_email(array $user, string $token): array
{
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $resetUrl = absolute_url('/reset-password?token=' . rawurlencode($token));
    $subject = APP_NAME . ' jelszó-visszaállítás';
    $emailTitle = 'Jelszó-visszaállítás';
    $emailLead = 'Jelszó-visszaállítást kértek a fiókodhoz. A link 1 óráig érvényes.';
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
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions);
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
        ['label' => 'Elfogadom az árajánlatot', 'url' => quote_customer_action_url($quote, 'accept')],
        ['label' => 'Árajánlat egyeztetés', 'url' => quote_customer_action_url($quote, 'consultation')],
    ];

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress((string) $quote['email'], (string) $quote['requester_name']);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions);
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

function quote_pdf_html(array $quote, array $lines): string
{
    $sections = quote_price_sections();
    $catalogItems = active_price_items();
    $catalogBySection = array_fill_keys(array_keys($sections), []);
    $activePriceItemIds = [];

    foreach ($catalogItems as $item) {
        $category = quote_normalize_category((string) $item['category']);
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

        $category = quote_normalize_category((string) ($line['category'] ?? ''));
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

            <?php if ($category === 'MVM Partneri tevékenységek'): ?>
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
                <li>MVM Partneri tevékenységek után fizetendő tételek</li>
                <li>Villanyszerelői munkák után fizetendő tételek</li>
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
    $emailActions = [
        ['label' => 'Árajánlat megtekintése', 'url' => quote_customer_action_url($quote)],
        ['label' => 'Elfogadom az árajánlatot', 'url' => quote_customer_action_url($quote, 'accept')],
        ['label' => 'Árajánlat egyeztetés', 'url' => quote_customer_action_url($quote, 'consultation')],
    ];

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress((string) $quote['email'], (string) $quote['requester_name']);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions);
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

function h_tariff_required_file_types(): array
{
    return [
        'h_tariff_label' => 'Klímamatrica fotó vagy dokumentum',
        'h_tariff_datasheet' => 'Műszaki adatlap fotó vagy dokumentum',
    ];
}

function connection_request_upload_definitions(): array
{
    return [
        'meter_close' => ['label' => 'Mérő fotó közelről', 'required' => false, 'kind' => 'image'],
        'meter_far' => ['label' => 'Mérő fotó távolról', 'required' => false, 'kind' => 'image'],
        'roof_hook' => ['label' => 'Tetőtartó vagy falihorog, ha van', 'required' => false, 'kind' => 'image'],
        'utility_pole' => ['label' => 'Villanyoszlop', 'required' => false, 'kind' => 'image'],
        'distribution_board' => ['label' => 'Lakás áramköri elosztója', 'required' => false, 'kind' => 'image'],
        'title_deed' => ['label' => 'Friss tulajdoni lap', 'required' => false, 'kind' => 'document'],
        'map_copy' => ['label' => 'Térképmásolat', 'required' => false, 'kind' => 'document'],
        'authorization' => ['label' => 'Kitöltött meghatalmazás', 'required' => false, 'kind' => 'document'],
        'consent_statement' => ['label' => 'Kitöltött hozzájáruló nyilatkozat', 'required' => false, 'kind' => 'document'],
        'h_tariff_label' => ['label' => 'Klímamatrica fotó vagy dokumentum', 'required' => false, 'kind' => 'document', 'h_tariff_required' => true],
        'h_tariff_datasheet' => ['label' => 'Műszaki adatlap fotó vagy dokumentum', 'required' => false, 'kind' => 'document', 'h_tariff_required' => true],
        'completed_document' => ['label' => 'Egyéb kitöltött dokumentum', 'required' => false, 'kind' => 'document'],
    ];
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
            'project_name' => 'Igény megnevezése',
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

        if ($finalize && $isRequiredForRequest && $uploadedFiles === [] && !connection_request_has_file_type($requestId, $key)) {
            $errors[] = $definition['label'] . ' feltöltése kötelező a lezáráshoz.';
        }

        foreach ($uploadedFiles as $file) {
            if (($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
                $errors[] = $definition['label'] . ': túl nagy fájl. Maximum 8 MB engedélyezett.';
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

    if (trim((string) $data['project_name']) === '') {
        $data['project_name'] = 'Mérőhelyi igény';
    }

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

    return (int) db()->lastInsertId();
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
    return (string) ($request['request_status'] ?? 'finalized') !== 'finalized';
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

function handle_connection_request_uploads(int $requestId, array $files, bool $notifyAdmin = true): array
{
    $messages = [];
    $savedFiles = [];
    $targetDir = CONNECTION_UPLOAD_PATH . '/' . $requestId;
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

            $savedFiles[] = [
                'label' => $definition['label'],
                'original_name' => $originalName,
            ];
        }
    }

    if ($notifyAdmin && $savedFiles !== []) {
        send_connection_request_file_upload_notification($requestId, $savedFiles);
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
        apply_branded_email($mail, 'Új kivitelezési munka érkezett', 'Az admin új munkát adott ki neked. A munka megkezdése előtt töltsd fel a kötelező induló fotókat.', $sections, $actions);
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

function all_connection_requests(): array
{
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

function connection_requests_for_customer(int $customerId): array
{
    return db_query(
        'SELECT * FROM `connection_requests`
         WHERE `customer_id` = ?
         ORDER BY `created_at` DESC, `id` DESC',
        [$customerId]
    )->fetchAll();
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

function generate_signed_authorization_pdf_from_template(int $requestId, array $request, array $data, array $signatures, string $targetPath): void
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

    if ($stage !== null) {
        return db_query(
            'SELECT * FROM `connection_request_work_files`
             WHERE `connection_request_id` = ? AND `stage` = ?
             ORDER BY `created_at` DESC, `id` DESC',
            [$requestId, $stage]
        )->fetchAll();
    }

    return db_query(
        'SELECT * FROM `connection_request_work_files`
         WHERE `connection_request_id` = ?
         ORDER BY `stage` ASC, `created_at` DESC, `id` DESC',
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

function find_connection_request_file(int $fileId): ?array
{
    $statement = db_query('SELECT * FROM `connection_request_files` WHERE `id` = ? LIMIT 1', [$fileId]);
    $file = $statement->fetch();

    return is_array($file) ? $file : null;
}

function finalize_connection_request(int $requestId): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.'];
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
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, CONNECTION_REQUEST_EMAIL, APP_NAME . ' dokumentumfeltöltés', 'failed', 'PHPMailer hiányzik.']
        );
        return ['ok' => false, 'message' => 'A PHPMailer nincs telepítve.'];
    }

    $subject = APP_NAME . ' dokumentumfeltöltés - ' . $request['project_name'] . ' - ' . $request['requester_name'];
    $emailTitle = 'Új ügyféldokumentum érkezett';
    $emailLead = 'Új dokumentum vagy fotó került feltöltésre egy mérőhelyi igényhez. Az admin felületen letölthető.';
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
            'title' => 'Feltöltött fájlok',
            'items' => array_map(
                static fn (array $file): string => (string) ($file['label'] ?? 'Dokumentum') . ': ' . (string) ($file['original_name'] ?? '-'),
                $savedFiles
            ),
        ],
    ];
    $emailActions = [
        ['label' => 'Igények megnyitása', 'url' => absolute_url('/admin/connection-requests')],
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
        $mail->addAddress(CONNECTION_REQUEST_EMAIL);
        $mail->addReplyTo($replyToEmail, $replyToName);
        $mail->Subject = $subject;
        apply_branded_email($mail, $emailTitle, $emailLead, $emailSections, $emailActions);
        $mail->send();

        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, CONNECTION_REQUEST_EMAIL, $subject, 'sent']);

        return ['ok' => true, 'message' => 'Admin értesítés elküldve.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, CONNECTION_REQUEST_EMAIL, $subject, 'failed', $exception->getMessage()]
        );

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
        ['label' => 'Igények megnyitása', 'url' => absolute_url('/admin/connection-requests')],
    ];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress(CONNECTION_REQUEST_EMAIL);
        $mail->Subject = $subject;
        apply_branded_email($mail, $stageLabel . ' feltöltve', 'A szerelő feltöltötte a kötelező munkafotók csomagját. Az admin felületen ellenőrizhető.', $sections, $actions);
        $mail->send();

        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, CONNECTION_REQUEST_EMAIL, $subject, 'sent']);

        return ['ok' => true, 'message' => 'Admin értesítés elküldve.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, CONNECTION_REQUEST_EMAIL, $subject, 'failed', $exception->getMessage()]
        );

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
        'execution_plan' => 'Kiviteli terv dokumentáció',
        'intervention_sheet' => 'Új beavatkozási lap',
        'completed_intervention_sheet' => 'Kész beavatkozási lap',
        'construction_log' => 'Építési napló',
        'technical_handover' => 'Műszaki átadás-átvételi jegyzőkönyv',
        'technical_declaration' => 'Nyilatkozatok adatlap',
    ];

    if ($includeSystemTypes) {
        $types['complete_package'] = 'Komplett összefűzött dokumentum';
        $types['technical_handover_package'] = 'Műszaki átadás csomag';
    }

    return $types;
}

function mvm_document_type_keys(): array
{
    return [
        'submitted_request',
        'accepted_request',
        'execution_plan',
        'intervention_sheet',
        'completed_intervention_sheet',
        'construction_log',
        'technical_handover',
        'technical_declaration',
        'complete_package',
        'technical_handover_package',
    ];
}

function mvm_document_type_enum_sql(): string
{
    return "ENUM('" . implode("', '", mvm_document_type_keys()) . "')";
}

function mvm_document_schema_errors(): array
{
    if (!db_table_exists('connection_request_documents')) {
        return ['Hianyzik a connection_request_documents tabla.'];
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
        $localConfigPath = defined('STORAGE_PATH') ? STORAGE_PATH . '/config/local.php' : '';

        if ($localConfigPath !== '' && is_file($localConfigPath)) {
            $loadedLocalConfig = require $localConfigPath;

            if (is_array($loadedLocalConfig)) {
                $localConfig = $loadedLocalConfig;
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
        'replied' => 'MVM válasz érkezett',
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
    $structure = @imap_fetchstructure($imap, (string) $uid, FT_UID);

    if (is_object($structure)) {
        foreach (mvm_imap_collect_body_parts($structure) as $part) {
            $body = @imap_fetchbody($imap, (string) $uid, (string) $part['part'], FT_UID | FT_PEEK);

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
        $body = @imap_body($imap, (string) $uid, FT_UID | FT_PEEK);
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

    if (preg_match('/MEZO-IGENY-\d+-DOK-\d+-[A-F0-9]{6}/i', $haystack, $matches)) {
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
        return ['ok' => false, 'message' => 'Nincs beállítva az MVM_IMAP_USER és MVM_IMAP_PASS a storage/config/local.php fájlban.', 'matched' => 0, 'ignored' => 0];
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

            $overview = @imap_fetch_overview($imap, (string) $uid, FT_UID);
            $overviewItem = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
            $subject = is_object($overviewItem) ? mvm_decode_mime_header((string) ($overviewItem->subject ?? '')) : '';
            $from = is_object($overviewItem) ? mvm_decode_mime_header((string) ($overviewItem->from ?? '')) : '';
            $to = is_object($overviewItem) ? mvm_decode_mime_header((string) ($overviewItem->to ?? '')) : '';
            $messageId = is_object($overviewItem) ? trim((string) ($overviewItem->message_id ?? '')) : '';
            $date = is_object($overviewItem) ? trim((string) ($overviewItem->date ?? '')) : '';
            $headers = @imap_fetchheader($imap, (string) $uid, FT_UID | FT_PEEK);
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

function mvm_plan_template_path(?string $contractorKey = null): ?string
{
    $contractor = mvm_contractor_definition($contractorKey);
    $candidates = [
        APP_ROOT . '/templates/mvm/plan-templates/' . (string) ($contractor['plan_template'] ?? ''),
    ];

    if (normalize_mvm_contractor_key($contractorKey) === 'primavill') {
        $candidates[] = APP_ROOT . '/templates/mvm/plan-templates/terv-sablon-delvill-2.docx';
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
    $candidate = APP_ROOT . '/templates/mvm/handover-templates/' . (string) ($contractor['handover_template'] ?? '');

    return is_file($candidate) ? $candidate : null;
}

function mvm_technical_handover_template_errors(?string $contractorKey = null): array
{
    $contractor = mvm_contractor_definition($contractorKey);

    return mvm_technical_handover_template_path($contractorKey) === null
        ? ['Hiányzik a(z) ' . $contractor['label'] . ' műszaki átadás DOCX sablon a templates/mvm/handover-templates mappából.']
        : [];
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

function mvm_docx_template_path(?string $contractorKey = null): ?string
{
    $contractorKey = normalize_mvm_contractor_key($contractorKey);
    $contractor = mvm_contractor_definition($contractorKey);
    $candidates = [
        APP_ROOT . '/templates/mvm/contractor-templates/' . $contractor['template'],
    ];

    if ($contractorKey === 'primavill') {
        $candidates[] = defined('MVM_DOCX_TEMPLATE_PATH') ? MVM_DOCX_TEMPLATE_PATH : '';
        $candidates[] = APP_ROOT . '/templates/mvm/primavill_igenybejelento_2026_lakossagi.docx';
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
        APP_ROOT . '/templates/mvm/primavill_igenybejelento_2026_lakossagi_blank.pdf',
        defined('MVM_PDF_TEMPLATE_PATH') ? MVM_PDF_TEMPLATE_PATH : '',
        APP_ROOT . '/Dokumentumok/Fővállalkozói dokumentumok/Primavill/primavill_igenybejelento_2026_lakossagi.pdf',
    ];

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
    $addProject('SzuletesiIdo', format_mvm_docx_date((string) ($request['birth_date'] ?? '')));
    $addProject('SzuletesiIdeje', format_mvm_docx_date((string) ($request['birth_date'] ?? '')));
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

        if (in_array($documentType, ['completed_intervention_sheet', 'construction_log', 'technical_declaration', 'technical_handover_package'], true)) {
            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

            if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                $errors[] = 'A műszaki átadás csomagba kerülő feltöltések csak PDF vagy kép fájlok lehetnek.';
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
        'SELECT * FROM `connection_request_documents`
         WHERE `connection_request_id` = ?
         ORDER BY `created_at` DESC, `id` DESC',
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

function latest_connection_request_execution_plan_document(int $requestId, bool $packageCompatibleOnly = true): ?array
{
    return latest_connection_request_document_by_types($requestId, ['execution_plan'], $packageCompatibleOnly);
}

function latest_connection_request_technical_handover_document(int $requestId, bool $packageCompatibleOnly = true): ?array
{
    return latest_connection_request_document_by_types($requestId, ['technical_handover'], $packageCompatibleOnly);
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

function connection_request_complete_package_parts(int $requestId): array
{
    $parts = [];
    $mvmDocument = latest_connection_request_mvm_source_document($requestId);

    if ($mvmDocument !== null) {
        $parts[] = connection_request_document_package_part($mvmDocument, 'MVM dokumentum', 'MVM dokumentum');
    }

    $executionPlan = latest_connection_request_execution_plan_document($requestId);

    if ($executionPlan !== null) {
        $parts[] = connection_request_document_package_part($executionPlan, 'Kiviteli terv', 'Kiviteli terv dokumentáció');
    }

    $definitions = connection_request_upload_definitions();
    $filesByType = [];

    foreach (connection_request_files($requestId) as $file) {
        $filesByType[(string) $file['file_type']][] = $file;
    }

    foreach ([
        'authorization' => 'Meghatalmazás',
        'title_deed' => 'Tulajdoni lap',
        'map_copy' => 'Térképmásolat',
        'consent_statement' => 'Hozzájáruló nyilatkozat',
    ] as $fileType => $group) {
        foreach ($filesByType[$fileType] ?? [] as $file) {
            $parts[] = [
                'group' => $group,
                'label' => (string) ($definitions[$fileType]['label'] ?? $group),
                'original_name' => (string) $file['original_name'],
                'path' => (string) $file['storage_path'],
                'mime_type' => (string) $file['mime_type'],
                'source' => 'request_file',
                'file_type' => $fileType,
            ];
        }
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

function connection_request_complete_package_missing_items(int $requestId): array
{
    $missing = [];

    if (latest_connection_request_mvm_source_document($requestId) === null) {
        $missing[] = 'MVM dokumentum PDF vagy kép formátumban';
    }

    if (!connection_request_has_file_type($requestId, 'authorization')) {
        $missing[] = 'Meghatalmazás';
    }

    return $missing;
}

function latest_completed_intervention_sheet_part(int $requestId): ?array
{
    $document = latest_connection_request_technical_document($requestId, 'completed_intervention_sheet');

    if ($document !== null) {
        return connection_request_document_package_part($document, 'Kész beavatkozási lap', 'Kész beavatkozási lap');
    }

    if (!db_table_exists('connection_request_work_files')) {
        return null;
    }

    $file = db_query(
        'SELECT * FROM `connection_request_work_files`
         WHERE `connection_request_id` = ? AND `stage` = ? AND `file_type` = ?
         ORDER BY `created_at` DESC, `id` DESC
         LIMIT 1',
        [$requestId, 'after', 'completed_intervention_sheet']
    )->fetch();

    if (!is_array($file) || (!pdf_package_file_is_pdf($file) && !pdf_package_file_is_image($file))) {
        return null;
    }

    return connection_request_work_file_package_part($file, 'Kész beavatkozási lap');
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
            $parts[] = connection_request_work_file_package_part($file, 'Fotók');
        }
    }

    foreach ($byType as $fileType => $typedFiles) {
        if (in_array($fileType, $orderedTypes, true)) {
            continue;
        }

        foreach ($typedFiles as $file) {
            $parts[] = connection_request_work_file_package_part($file, 'Fotók');
        }
    }

    return $parts;
}

function connection_request_technical_handover_package_parts(int $requestId): array
{
    $parts = [];
    $completedInterventionSheet = latest_completed_intervention_sheet_part($requestId);

    if ($completedInterventionSheet !== null) {
        $parts[] = $completedInterventionSheet;
    }

    $constructionLog = latest_connection_request_technical_document($requestId, 'construction_log');

    if ($constructionLog !== null) {
        $parts[] = connection_request_document_package_part($constructionLog, 'Építési napló', 'Építési napló');
    }

    $technicalHandover = latest_connection_request_technical_handover_document($requestId);

    if ($technicalHandover !== null) {
        $parts[] = connection_request_document_package_part($technicalHandover, 'Műszaki átadás', 'Műszaki átadás-átvételi jegyzőkönyv');
    }

    $technicalDeclaration = latest_connection_request_technical_document($requestId, 'technical_declaration');

    if ($technicalDeclaration !== null) {
        $parts[] = connection_request_document_package_part($technicalDeclaration, 'Nyilatkozatok adatlap', 'Nyilatkozatok adatlap');
    }

    return array_merge($parts, connection_request_after_work_photo_parts($requestId));
}

function connection_request_technical_handover_package_missing_items(int $requestId): array
{
    $missing = [];

    if (latest_completed_intervention_sheet_part($requestId) === null) {
        $missing[] = 'Kész beavatkozási lap';
    }

    if (latest_connection_request_technical_document($requestId, 'construction_log') === null) {
        $missing[] = 'Építési napló';
    }

    if (latest_connection_request_technical_handover_document($requestId) === null) {
        $missing[] = 'Műszaki átadás PDF';
    }

    if (
        latest_connection_request_technical_document($requestId, 'technical_declaration') === null
        && latest_connection_request_mvm_request_pdf_document($requestId) === null
    ) {
        $missing[] = 'Nyilatkozatok adatlaphoz MVM igénybejelentő PDF (9-11. oldal)';
    }

    $requiredAfterPhotos = [
        'meter_far' => 'Mérő távolról',
        'meter_close' => 'Mérő közelről',
        'utility_pole' => 'Villanyoszlop',
        'roof_hook' => 'Tetőtartó',
        'seals' => 'Plombák',
    ];

    foreach ($requiredAfterPhotos as $fileType => $label) {
        if (!connection_request_has_work_file_type($requestId, 'after', $fileType)) {
            $missing[] = 'Kivitelezés utáni fotó: ' . $label;
        }
    }

    return $missing;
}

function pdf_package_file_is_pdf(array $part): bool
{
    return strtolower(pathinfo((string) $part['path'], PATHINFO_EXTENSION)) === 'pdf'
        || str_contains(strtolower((string) ($part['mime_type'] ?? '')), 'pdf');
}

function pdf_package_file_is_image(array $part): bool
{
    $extension = strtolower(pathinfo((string) $part['path'], PATHINFO_EXTENSION));

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
                    throw new RuntimeException(
                        'A(z) "' . $label . '" PDF nem fűzhető be. Nyisd meg, mentsd vagy nyomtasd új PDF-be, majd töltsd fel újra. Részletek: ' . $exception->getMessage(),
                        0,
                        $exception
                    );
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
        throw new RuntimeException('A forrás MVM igénybejelentő PDF nem található.');
    }

    $pdf = new \setasign\Fpdi\Fpdi();
    $pageCount = $pdf->setSourceFile($sourcePath);

    if ($pageCount < $endPage) {
        throw new RuntimeException('Az MVM igénybejelentő PDF csak ' . $pageCount . ' oldalas, ezért a 9-11. oldal nem nyerhető ki.');
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
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $sourceDocument = latest_connection_request_mvm_request_pdf_document($requestId);

    if ($sourceDocument === null) {
        return ['ok' => false, 'message' => 'A nyilatkozatok adatlap kinyeréséhez előbb legyen MVM igénybejelentő PDF az igényhez.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/technical-declaration';
    ensure_storage_dir($targetDir);

    $storedName = 'nyilatkozatok-adatlap-' . $requestId . '-' . date('Ymd-His') . '.pdf';
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
                'Nyilatkozatok adatlap - igénybejelentőből kinyerve',
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
            'message' => 'A nyilatkozatok adatlap elkészült az MVM igénybejelentő 9-11. oldalából.',
            'document_id' => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $exception) {
        if (is_file($targetPath)) {
            unlink($targetPath);
        }

        return [
            'ok' => false,
            'message' => 'A nyilatkozatok adatlap kinyerése sikertelen: ' . $exception->getMessage(),
            'document_id' => null,
        ];
    }
}

function generate_connection_request_complete_package(int $requestId): array
{
    $request = find_connection_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'Az igény nem található.', 'document_id' => null];
    }

    $missingItems = connection_request_complete_package_missing_items($requestId);

    if ($missingItems !== []) {
        return ['ok' => false, 'message' => 'A komplett dokumentumhoz még hiányzik: ' . implode(', ', $missingItems) . '.', 'document_id' => null];
    }

    $parts = connection_request_complete_package_parts($requestId);

    if ($parts === []) {
        return ['ok' => false, 'message' => 'Nincs összefűzhető dokumentum.', 'document_id' => null];
    }

    $targetDir = MVM_DOCUMENT_UPLOAD_PATH . '/' . $requestId . '/complete-package';
    ensure_storage_dir($targetDir);

    $storedName = 'komplett-dokumentumcsomag-' . $requestId . '-' . date('Ymd-His') . '.pdf';
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
                'message' => 'Az összefűzött dokumentum ' . format_bytes($lastSize) . ', ezért nem mentettük. A kész dokumentumnak 5 MB alatt kell lennie; tölts fel tömörített MVM/dokumentum PDF-et vagy kisebb fotókat.',
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
                'Komplett összefűzött dokumentum',
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
            'message' => 'A komplett dokumentum elkészült: ' . format_bytes((int) filesize($finalPath)) . '.',
            'document_id' => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $exception) {
        if (is_file($finalPath)) {
            unlink($finalPath);
        }

        return [
            'ok' => false,
            'message' => 'A komplett dokumentum generálása sikertelen: ' . $exception->getMessage(),
            'document_id' => null,
        ];
    }
}

function generate_connection_request_technical_handover_package(int $requestId): array
{
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

    $subject = APP_NAME . ' komplett dokumentumcsomag - ' . $request['project_name'];
    $sections = [
        [
            'title' => 'Dokumentum adatai',
            'rows' => [
                ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                ['label' => 'Cím', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                ['label' => 'Fájlméret', 'value' => format_bytes((int) $document['file_size'])],
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
        $mail->addAddress((string) $request['email'], (string) $request['requester_name']);
        $mail->Subject = $subject;
        apply_branded_email(
            $mail,
            'Elkészült a komplett dokumentumcsomag',
            'A mérőhelyi ügyintézéshez összeállított komplett dokumentumcsomagot csatoltuk.',
            $sections,
            $actions
        );
        $mail->addAttachment((string) $document['storage_path'], (string) $document['original_name']);
        $mail->send();

        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, (string) $request['email'], $subject, 'sent']);

        return ['ok' => true, 'message' => 'A komplett dokumentumot elküldtük az ügyfélnek.'];
    } catch (Throwable $exception) {
        db_query(
            'INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)',
            [null, (string) $request['email'], $subject, 'failed', $exception->getMessage()]
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
        [['label' => 'Igények megnyitása', 'url' => absolute_url('/index.php?route=admin/connection-requests')]]
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
        ['label' => 'Igények megnyitása', 'url' => absolute_url('/index.php?route=admin/connection-requests')],
    ];

    if (!empty($request['contractor_name'])) {
        $subject .= ' - ' . $request['contractor_name'];
    }

    try {
        configure_mailer_transport($mail);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress(CONNECTION_REQUEST_EMAIL);
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
        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`) VALUES (?, ?, ?, ?)', [null, CONNECTION_REQUEST_EMAIL, $subject, 'sent']);

        return ['ok' => true, 'message' => $finalized ? 'Az igényt lezártuk, és végleges igénybejelentésként elküldtük az adminnak.' : 'Az igény rögzítve és elküldve.'];
    } catch (Throwable $exception) {
        db_query('UPDATE `connection_requests` SET `email_status` = ?, `email_error` = ? WHERE `id` = ?', ['failed', $exception->getMessage(), $requestId]);
        db_query('INSERT INTO `email_logs` (`quote_id`, `recipient_email`, `subject`, `status`, `error_message`) VALUES (?, ?, ?, ?, ?)', [null, CONNECTION_REQUEST_EMAIL, $subject, 'failed', $exception->getMessage()]);

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
