<?php
declare(strict_types=1);

const LEAD_IMPORT_SOURCE_FACEBOOK = 'facebook_instant_form';
const LEAD_IMPORT_MIN_TOKEN_LENGTH = 32;

function lead_import_handle_facebook_lead_request(): never
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Allow: POST');
        lead_import_json_response(405, [
            'status' => 'HIBA',
            'error' => 'Method not allowed',
        ]);
    }

    if (!lead_import_token_is_configured()) {
        lead_import_json_response(503, [
            'status' => 'HIBA',
            'error' => 'Import API is not configured',
        ]);
    }

    if (!lead_import_request_is_authorized()) {
        lead_import_json_response(401, [
            'status' => 'HIBA',
            'error' => 'Unauthorized',
        ]);
    }

    if (!lead_import_request_is_json()) {
        lead_import_json_response(415, [
            'status' => 'HIBA',
            'error' => 'Only application/json payload is accepted',
        ]);
    }

    $payload = lead_import_read_json_payload();
    if ($payload === null) {
        lead_import_json_response(422, [
            'status' => 'HIBA',
            'error' => 'Invalid JSON payload',
        ]);
    }

    $payload = lead_import_normalize_payload($payload);
    $errors = lead_import_validate_payload($payload);
    if ($errors !== []) {
        lead_import_json_response(422, [
            'status' => 'HIBA',
            'error' => implode(' ', $errors),
        ]);
    }

    $schemaErrors = lead_import_schema_errors();
    if ($schemaErrors !== []) {
        lead_import_json_response(500, [
            'status' => 'HIBA',
            'error' => implode(' ', $schemaErrors),
        ]);
    }

    try {
        $result = lead_import_process_payload($payload);
    } catch (Throwable $exception) {
        lead_import_json_response(500, [
            'status' => 'HIBA',
            'error' => lead_import_public_exception_message($exception),
        ]);
    }

    if (!empty($result['duplicate'])) {
        lead_import_json_response(200, [
            'status' => 'DUPLIKÁLT',
            'duplicate' => true,
            'customer_id' => (string) ($result['customer_id'] ?? ''),
            'work_request_id' => (string) ($result['work_request_id'] ?? ''),
            'message' => 'Lead already imported',
        ]);
    }

    lead_import_json_response(201, [
        'status' => 'SIKERES',
        'duplicate' => false,
        'customer_id' => (string) ($result['customer_id'] ?? ''),
        'work_request_id' => (string) ($result['work_request_id'] ?? ''),
        'message' => 'Lead imported successfully',
    ]);
}

function lead_import_json_response(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function lead_import_request_is_authorized(): bool
{
    $expectedToken = lead_import_expected_token();
    $providedToken = lead_import_bearer_token();

    return $expectedToken !== ''
        && $providedToken !== ''
        && hash_equals($expectedToken, $providedToken);
}

function lead_import_token_is_configured(): bool
{
    return strlen(lead_import_expected_token()) >= LEAD_IMPORT_MIN_TOKEN_LENGTH;
}

function lead_import_expected_token(): string
{
    $environmentValue = getenv('LEAD_IMPORT_TOKEN');

    if ($environmentValue !== false && trim((string) $environmentValue) !== '') {
        return trim((string) $environmentValue);
    }

    return lead_import_local_config_value('LEAD_IMPORT_TOKEN');
}

function lead_import_local_config_value(string $key): string
{
    static $localConfig = null;

    if ($localConfig === null) {
        $localConfig = [];
        $storagePath = defined('STORAGE_PATH') ? (string) STORAGE_PATH : dirname(__DIR__, 2) . '/storage';
        $localConfigPaths = [
            $storagePath . '/config/local.secret.php',
        ];

        foreach ($localConfigPaths as $localConfigPath) {
            if (!is_file($localConfigPath) || !is_readable($localConfigPath)) {
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

    return array_key_exists($key, $localConfig) ? trim((string) $localConfig[$key]) : '';
}

function lead_import_bearer_token(): string
{
    $header = lead_import_authorization_header();

    if (!preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $header, $matches)) {
        return '';
    }

    return trim((string) $matches[1]);
}

function lead_import_authorization_header(): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $key) {
        $value = $_SERVER[$key] ?? '';
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, 'Authorization') === 0 && is_string($value)) {
                    return trim($value);
                }
            }
        }
    }

    return '';
}

function lead_import_request_is_json(): bool
{
    $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '')));

    return preg_match('/^application\/(?:[\w.+-]+\+)?json(?:\s*;|$)/', $contentType) === 1;
}

function lead_import_read_json_payload(): ?array
{
    $rawBody = file_get_contents('php://input');

    if (!is_string($rawBody) || trim($rawBody) === '') {
        return null;
    }

    $payload = json_decode($rawBody, true);

    return is_array($payload) && json_last_error() === JSON_ERROR_NONE ? $payload : null;
}

function lead_import_normalize_payload(array $payload): array
{
    return [
        'source' => lead_import_payload_string($payload, 'source', 80),
        'external_lead_id' => lead_import_payload_string($payload, 'external_lead_id', 500),
        'created_time' => lead_import_payload_string($payload, 'created_time', 80),
        'campaign_name' => lead_import_payload_string($payload, 'campaign_name', 255),
        'form_name' => lead_import_payload_string($payload, 'form_name', 255),
        'property_location' => lead_import_payload_string($payload, 'property_location', 500),
        'work_type' => lead_import_payload_string($payload, 'work_type', 255),
        'work_request_title' => lead_import_payload_first_string($payload, [
            'work_request_title',
            'request_title',
            'adatlap_neve',
            'adatlap neve',
            'munka_neve',
            'munka neve',
            'igeny_neve',
            'igény_neve',
            'igény neve',
        ], 180),
        'has_existing_utility_request' => lead_import_payload_string($payload, 'has_existing_utility_request', 255),
        'city' => lead_import_payload_string($payload, 'city', 160),
        'email' => strtolower(lead_import_payload_string($payload, 'email', 190)),
        'full_name' => lead_import_payload_string($payload, 'full_name', 190),
        'phone' => lead_import_payload_string($payload, 'phone', 80),
        'lead_status' => lead_import_payload_string($payload, 'lead_status', 120),
        'sheet_row' => lead_import_payload_int_or_null($payload, 'sheet_row'),
    ];
}

function lead_import_payload_string(array $payload, string $key, int $limit): string
{
    $value = $payload[$key] ?? '';

    if (is_array($value) || is_object($value)) {
        return '';
    }

    $value = trim((string) $value);
    $value = preg_replace('/[ \t\r\n]+/u', ' ', $value);
    $value = is_string($value) ? trim($value) : '';

    return lead_import_limit($value, $limit);
}

function lead_import_payload_first_string(array $payload, array $keys, int $limit): string
{
    foreach ($keys as $key) {
        $value = lead_import_payload_string($payload, (string) $key, $limit);

        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function lead_import_payload_int_or_null(array $payload, string $key): ?int
{
    if (!array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
        return null;
    }

    return is_numeric($payload[$key]) ? (int) $payload[$key] : null;
}

function lead_import_validate_payload(array $payload): array
{
    $errors = [];

    if ((string) $payload['source'] !== LEAD_IMPORT_SOURCE_FACEBOOK) {
        $errors[] = 'source must be facebook_instant_form.';
    }

    if ((string) $payload['email'] !== '' && !filter_var((string) $payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email.';
    }

    if ((string) $payload['email'] === '' && (string) $payload['phone'] === '') {
        $errors[] = 'email or phone is required.';
    }

    if ($payload['sheet_row'] !== null && (int) $payload['sheet_row'] < 1) {
        $errors[] = 'sheet_row must be a positive number.';
    }

    return $errors;
}

function lead_import_schema_errors(): array
{
    $errors = [];

    foreach (['users', 'customers', 'connection_requests'] as $table) {
        if (!db_table_exists($table)) {
            $errors[] = 'Missing database table: ' . $table . '.';
        }
    }

    if ($errors !== []) {
        return $errors;
    }

    try {
        db_query(
            "CREATE TABLE IF NOT EXISTS `lead_imports` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `source` VARCHAR(80) NOT NULL,
                `external_lead_id` VARCHAR(190) NOT NULL,
                `payload_hash` CHAR(64) NOT NULL,
                `customer_id` INT UNSIGNED NULL,
                `work_request_id` INT UNSIGNED NULL,
                `status` VARCHAR(40) NOT NULL DEFAULT 'processing',
                `duplicate_of_id` INT UNSIGNED NULL,
                `error_message` TEXT DEFAULT NULL,
                `imported_at` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_lead_imports_source_external` (`source`, `external_lead_id`),
                KEY `idx_lead_imports_customer` (`customer_id`),
                KEY `idx_lead_imports_work_request` (`work_request_id`),
                KEY `idx_lead_imports_status` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $columns = [
            'source' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `source` VARCHAR(80) NOT NULL AFTER `id`",
            'external_lead_id' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `external_lead_id` VARCHAR(190) NOT NULL AFTER `source`",
            'payload_hash' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `payload_hash` CHAR(64) NOT NULL AFTER `external_lead_id`",
            'customer_id' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `customer_id` INT UNSIGNED NULL AFTER `payload_hash`",
            'work_request_id' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `work_request_id` INT UNSIGNED NULL AFTER `customer_id`",
            'status' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `status` VARCHAR(40) NOT NULL DEFAULT 'processing' AFTER `work_request_id`",
            'duplicate_of_id' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `duplicate_of_id` INT UNSIGNED NULL AFTER `status`",
            'error_message' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `error_message` TEXT DEFAULT NULL AFTER `duplicate_of_id`",
            'imported_at' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `imported_at` DATETIME DEFAULT NULL AFTER `error_message`",
            'created_at' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `imported_at`",
            'updated_at' => "ALTER TABLE `lead_imports` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
        ];

        foreach ($columns as $column => $sql) {
            if (!db_column_exists('lead_imports', $column)) {
                db_query($sql);
            }
        }

        if (!lead_import_index_exists('ux_lead_imports_source_external')) {
            db_query('ALTER TABLE `lead_imports` ADD UNIQUE KEY `ux_lead_imports_source_external` (`source`, `external_lead_id`)');
        }
    } catch (Throwable $exception) {
        $errors[] = APP_DEBUG
            ? 'lead_imports schema error: ' . $exception->getMessage()
            : 'lead_imports database schema is not ready.';
    }

    return $errors;
}

function lead_import_index_exists(string $indexName): bool
{
    $statement = db_query(
        'SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1',
        [DB_NAME, 'lead_imports', $indexName]
    );

    return (bool) $statement->fetchColumn();
}

function lead_import_process_payload(array $payload): array
{
    $source = (string) $payload['source'];
    $externalLeadId = lead_import_resolved_external_id($payload);
    $payloadHash = lead_import_payload_hash($payload);
    $pdo = db();
    $startedTransaction = !$pdo->inTransaction();

    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $existingImport = $startedTransaction
            ? lead_import_find_by_external_id_for_update($source, $externalLeadId)
            : lead_import_find_by_external_id($source, $externalLeadId);

        if (lead_import_row_is_imported($existingImport)) {
            if ($startedTransaction) {
                $pdo->commit();
            }

            return [
                'duplicate' => true,
                'customer_id' => (int) ($existingImport['customer_id'] ?? 0),
                'work_request_id' => (int) ($existingImport['work_request_id'] ?? 0),
            ];
        }

        $importId = lead_import_start_log($source, $externalLeadId, $payloadHash, $existingImport);
        $currentImport = $startedTransaction
            ? lead_import_find_by_id_for_update($importId)
            : lead_import_find_by_id($importId);

        if (lead_import_row_is_imported($currentImport)) {
            if ($startedTransaction) {
                $pdo->commit();
            }

            return [
                'duplicate' => true,
                'customer_id' => (int) ($currentImport['customer_id'] ?? 0),
                'work_request_id' => (int) ($currentImport['work_request_id'] ?? 0),
            ];
        }

        $customerResult = lead_import_find_or_create_customer($payload);
        $customerId = (int) $customerResult['customer_id'];
        $workRequestId = lead_import_create_work_request($customerId, $payload, $externalLeadId);

        lead_import_mark_success($importId, $customerId, $workRequestId);

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        lead_import_record_error_log($source, $externalLeadId, $payloadHash, lead_import_public_exception_message($exception));
        throw $exception;
    }

    $activationResult = lead_import_send_activation_email_if_needed(
        (int) ($customerResult['user_id'] ?? 0),
        !empty($customerResult['user_created']),
        $workRequestId
    );

    if (!($activationResult['ok'] ?? true)) {
        lead_import_mark_activation_warning($importId, (string) ($activationResult['message'] ?? 'Activation email was not sent.'));
    }

    return [
        'duplicate' => false,
        'customer_id' => $customerId,
        'work_request_id' => $workRequestId,
    ];
}

function lead_import_row_is_imported(?array $row): bool
{
    return is_array($row)
        && (int) ($row['customer_id'] ?? 0) > 0
        && (int) ($row['work_request_id'] ?? 0) > 0;
}

function lead_import_find_by_external_id(string $source, string $externalLeadId): ?array
{
    $row = db_query(
        'SELECT *
         FROM `lead_imports`
         WHERE `source` = ? AND `external_lead_id` = ?
         LIMIT 1',
        [$source, $externalLeadId]
    )->fetch();

    return is_array($row) ? $row : null;
}

function lead_import_find_by_external_id_for_update(string $source, string $externalLeadId): ?array
{
    $row = db_query(
        'SELECT *
         FROM `lead_imports`
         WHERE `source` = ? AND `external_lead_id` = ?
         LIMIT 1
         FOR UPDATE',
        [$source, $externalLeadId]
    )->fetch();

    return is_array($row) ? $row : null;
}

function lead_import_find_by_id(int $id): ?array
{
    $row = db_query(
        'SELECT *
         FROM `lead_imports`
         WHERE `id` = ?
         LIMIT 1',
        [$id]
    )->fetch();

    return is_array($row) ? $row : null;
}

function lead_import_find_by_id_for_update(int $id): ?array
{
    $row = db_query(
        'SELECT *
         FROM `lead_imports`
         WHERE `id` = ?
         LIMIT 1
         FOR UPDATE',
        [$id]
    )->fetch();

    return is_array($row) ? $row : null;
}

function lead_import_start_log(string $source, string $externalLeadId, string $payloadHash, ?array $existingImport): int
{
    if (is_array($existingImport)) {
        if ((int) ($existingImport['customer_id'] ?? 0) > 0 && (int) ($existingImport['work_request_id'] ?? 0) > 0) {
            return (int) $existingImport['id'];
        }

        db_query(
            'UPDATE `lead_imports`
             SET `payload_hash` = ?,
                 `status` = ?,
                 `duplicate_of_id` = NULL,
                 `error_message` = NULL,
                 `updated_at` = CURRENT_TIMESTAMP
             WHERE `id` = ?',
            [$payloadHash, 'processing', (int) $existingImport['id']]
        );

        return (int) $existingImport['id'];
    }

    try {
        db_query(
            'INSERT INTO `lead_imports` (`source`, `external_lead_id`, `payload_hash`, `status`)
             VALUES (?, ?, ?, ?)',
            [$source, $externalLeadId, $payloadHash, 'processing']
        );

        return (int) db()->lastInsertId();
    } catch (Throwable $exception) {
        $existingImport = lead_import_find_by_external_id($source, $externalLeadId);
        if (is_array($existingImport)) {
            return (int) $existingImport['id'];
        }

        throw $exception;
    }
}

function lead_import_mark_success(int $importId, int $customerId, int $workRequestId): void
{
    db_query(
        'UPDATE `lead_imports`
         SET `customer_id` = ?,
             `work_request_id` = ?,
             `status` = ?,
             `duplicate_of_id` = NULL,
             `error_message` = NULL,
             `imported_at` = NOW(),
             `updated_at` = CURRENT_TIMESTAMP
         WHERE `id` = ?',
        [$customerId, $workRequestId, 'imported', $importId]
    );
}

function lead_import_mark_error(int $importId, string $message): void
{
    db_query(
        'UPDATE `lead_imports`
         SET `status` = ?,
             `error_message` = ?,
             `updated_at` = CURRENT_TIMESTAMP
         WHERE `id` = ?',
        ['error', lead_import_limit($message, 1000), $importId]
    );
}

function lead_import_record_error_log(string $source, string $externalLeadId, string $payloadHash, string $message): void
{
    db_query(
        'INSERT INTO `lead_imports` (`source`, `external_lead_id`, `payload_hash`, `status`, `error_message`)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             `payload_hash` = VALUES(`payload_hash`),
             `status` = VALUES(`status`),
             `error_message` = VALUES(`error_message`),
             `updated_at` = CURRENT_TIMESTAMP',
        [$source, $externalLeadId, $payloadHash, 'error', lead_import_limit($message, 1000)]
    );
}

function lead_import_mark_activation_warning(int $importId, string $message): void
{
    db_query(
        'UPDATE `lead_imports`
         SET `error_message` = ?,
             `updated_at` = CURRENT_TIMESTAMP
         WHERE `id` = ?',
        ['Activation email warning: ' . lead_import_limit($message, 900), $importId]
    );
}

function lead_import_find_or_create_customer(array $payload): array
{
    $email = (string) $payload['email'];
    $phone = (string) $payload['phone'];
    $customer = null;

    if (lead_import_email_is_valid($email)) {
        $customer = lead_import_find_customer_by_email($email);
    }

    if ($customer === null && lead_import_email_is_valid($email)) {
        $user = find_user_by_email($email);
        if (is_array($user) && (int) ($user['customer_id'] ?? 0) > 0) {
            $customer = find_customer((int) $user['customer_id']);
        }
    }

    if ($customer === null && $phone !== '') {
        $customer = lead_import_find_customer_by_phone($phone);
    }

    if (is_array($customer)) {
        return lead_import_ensure_customer_account($customer, $payload);
    }

    return lead_import_create_customer_with_optional_account($payload);
}

function lead_import_find_customer_by_email(string $email): ?array
{
    if (!lead_import_email_is_valid($email)) {
        return null;
    }

    $row = db_query(
        'SELECT *
         FROM `customers`
         WHERE LOWER(`email`) = LOWER(?)
         ORDER BY CASE WHEN `user_id` IS NULL OR `user_id` = 0 THEN 0 ELSE 1 END DESC,
                  `created_at` DESC,
                  `id` DESC
         LIMIT 1',
        [$email]
    )->fetch();

    return is_array($row) ? $row : null;
}

function lead_import_find_customer_by_phone(string $phone): ?array
{
    $phone = trim($phone);
    $digits = lead_import_phone_digits($phone);

    if ($phone === '' || $digits === '') {
        return null;
    }

    $row = db_query(
        "SELECT *
         FROM `customers`
         WHERE `phone` = ?
            OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone`, ' ', ''), '-', ''), '/', ''), '(', ''), ')', ''), '.', ''), '+', '') = ?
         ORDER BY `created_at` DESC, `id` DESC
         LIMIT 1",
        [$phone, $digits]
    )->fetch();

    return is_array($row) ? $row : null;
}

function lead_import_ensure_customer_account(array $customer, array $payload): array
{
    $customerId = (int) $customer['id'];
    $customerUserId = (int) ($customer['user_id'] ?? 0);
    $email = (string) $payload['email'];

    if ($customerUserId > 0 || !lead_import_email_is_valid($email)) {
        return [
            'customer_id' => $customerId,
            'user_id' => $customerUserId,
            'user_created' => false,
        ];
    }

    $user = find_user_by_email($email);
    if (is_array($user)) {
        $userId = (int) $user['id'];
        $linkedCustomerId = (int) ($user['customer_id'] ?? 0);

        if ($linkedCustomerId <= 0 || $linkedCustomerId === $customerId) {
            db_query('UPDATE `users` SET `customer_id` = ? WHERE `id` = ?', [$customerId, $userId]);
            db_query(
                'UPDATE `customers`
                 SET `user_id` = ?, `created_by_user_id` = COALESCE(`created_by_user_id`, ?)
                 WHERE `id` = ?',
                [$userId, $userId, $customerId]
            );
        }

        return [
            'customer_id' => $customerId,
            'user_id' => $userId,
            'user_created' => false,
        ];
    }

    $userId = create_user_account_record(
        lead_import_customer_display_name($payload),
        $email,
        bin2hex(random_bytes(32)),
        'customer',
        $customerId,
        false,
        false
    );

    db_query(
        'UPDATE `customers`
         SET `user_id` = ?, `created_by_user_id` = COALESCE(`created_by_user_id`, ?)
         WHERE `id` = ?',
        [$userId, $userId, $customerId]
    );

    return [
        'customer_id' => $customerId,
        'user_id' => $userId,
        'user_created' => true,
    ];
}

function lead_import_create_customer_with_optional_account(array $payload): array
{
    $email = (string) $payload['email'];
    $userId = null;
    $userCreated = false;

    if (lead_import_email_is_valid($email)) {
        $user = find_user_by_email($email);
        if (is_array($user)) {
            $userId = (int) $user['id'];
        } else {
            $userId = create_user_account_record(
                lead_import_customer_display_name($payload),
                $email,
                bin2hex(random_bytes(32)),
                'customer',
                null,
                false,
                false
            );
            $userCreated = true;
        }
    }

    $customerId = create_customer(lead_import_customer_data($payload), $userId);

    if ($userId !== null && $userId > 0) {
        db_query(
            'UPDATE `users`
             SET `customer_id` = ?
             WHERE `id` = ?
               AND (`customer_id` IS NULL OR `customer_id` = 0)',
            [$customerId, $userId]
        );
        db_query(
            'UPDATE `customers`
             SET `user_id` = ?, `created_by_user_id` = COALESCE(`created_by_user_id`, ?)
             WHERE `id` = ?',
            [$userId, $userId, $customerId]
        );
    }

    return [
        'customer_id' => $customerId,
        'user_id' => $userId ?? 0,
        'user_created' => $userCreated,
    ];
}

function lead_import_customer_data(array $payload): array
{
    $location = (string) $payload['property_location'];
    $city = (string) $payload['city'];

    return [
        'is_legal_entity' => 0,
        'requester_name' => lead_import_customer_display_name($payload),
        'birth_name' => '',
        'company_name' => '',
        'tax_number' => '',
        'phone' => (string) $payload['phone'],
        'email' => lead_import_email_is_valid((string) $payload['email']) ? (string) $payload['email'] : '',
        'postal_address' => $location !== '' ? $location : 'Facebook lead - pontosítás szükséges',
        'postal_code' => lead_import_extract_postal_code($location),
        'city' => $city,
        'mailing_address' => '',
        'mother_name' => '',
        'birth_place' => '',
        'birth_date' => '',
        'contact_data_accepted' => 0,
        'source' => 'Facebook instant form',
        'status' => 'Előregisztrált lead',
        'notes' => lead_import_customer_note($payload),
    ];
}

function lead_import_customer_display_name(array $payload): string
{
    $name = trim((string) $payload['full_name']);

    if ($name !== '') {
        return lead_import_limit($name, 160);
    }

    if ((string) $payload['email'] !== '') {
        return lead_import_limit((string) $payload['email'], 160);
    }

    if ((string) $payload['phone'] !== '') {
        return lead_import_limit((string) $payload['phone'], 160);
    }

    return 'Facebook lead';
}

function lead_import_create_work_request(int $customerId, array $payload, string $externalLeadId): int
{
    $customer = find_customer($customerId);
    $location = (string) $payload['property_location'];
    $city = (string) $payload['city'];
    $workType = (string) $payload['work_type'];
    $customerSummary = lead_import_customer_work_summary($payload);
    $adminImportNote = lead_import_work_request_note($payload, $externalLeadId);
    $requestData = normalize_connection_request_data([
        'request_type' => lead_import_request_type_from_work_type($workType),
        'project_name' => lead_import_build_work_request_title($payload),
        'site_address' => $location !== '' ? $location : ($city !== '' ? $city : 'Facebook lead - pontosítás szükséges'),
        'site_postal_code' => lead_import_extract_postal_code($location),
        'existing_general_power' => 'Ismeretlen',
        'requested_general_power' => '',
        'existing_h_tariff_power' => '',
        'requested_h_tariff_power' => '',
        'existing_controlled_power' => '',
        'requested_controlled_power' => '',
        'notes' => $customerSummary,
        'work_note' => $adminImportNote,
    ], is_array($customer) ? $customer : null);

    $requestId = save_connection_request($customerId, $requestData, null, null, true);

    if (db_column_exists('connection_requests', 'admin_workflow_stage')) {
        db_query(
            'UPDATE `connection_requests`
             SET `admin_workflow_stage` = ?
             WHERE `id` = ?',
            ['case_starting', $requestId]
        );
    }

    record_connection_request_activity(
        $requestId,
        'lead_import',
        'Facebook lead import',
        'A munkaigény a Google Sheet / Facebook lead import API-n keresztül jött létre.',
        null,
        'Lead import API'
    );

    return $requestId;
}

function lead_import_request_type_from_work_type(string $workType): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($workType, 'UTF-8') : strtolower($workType);

    if (str_contains($value, 'h tarifa') || str_contains($value, 'h-tarifa')) {
        return 'h_tariff';
    }

    if (str_contains($value, 'új bekapcsol') || str_contains($value, 'uj bekapcsol') || str_contains($value, 'új fogyaszt') || str_contains($value, 'uj fogyaszt')) {
        return 'new_connection';
    }

    if (str_contains($value, 'szabvány') || str_contains($value, 'szabvany')) {
        return 'standardization';
    }

    if (str_contains($value, 'sötét') || str_contains($value, 'sotet')) {
        return str_contains($value, 'sürgős') || str_contains($value, 'surgos')
            ? 'urgent_dark_address'
            : 'dark_address';
    }

    if (str_contains($value, 'teljesítmény') || str_contains($value, 'teljesitmeny') || str_contains($value, 'amper')) {
        return 'power_increase';
    }

    if (str_contains($value, '3 fáz') || str_contains($value, '3 faz') || str_contains($value, 'három fáz') || str_contains($value, 'harom faz')) {
        return 'phase_upgrade';
    }

    return 'phase_upgrade';
}

function lead_import_project_name(array $payload): string
{
    return lead_import_build_work_request_title($payload);
}

function lead_import_build_work_request_title(array $payload): string
{
    $explicitTitle = lead_import_title_text((string) ($payload['work_request_title'] ?? ''));

    if ($explicitTitle !== '') {
        return lead_import_limit($explicitTitle, 180);
    }

    $city = lead_import_title_text((string) ($payload['city'] ?? ''));
    $location = lead_import_title_text((string) ($payload['property_location'] ?? ''));
    $workType = lead_import_title_text((string) ($payload['work_type'] ?? ''));
    $context = $city !== '' ? $city : lead_import_short_location_title($location);
    $base = lead_import_strip_leading_title_context($workType, array_filter([$city, $context]));

    if ($base === '') {
        $base = 'Mérőhelyi munka';
    }

    if ($context !== '' && !lead_import_title_contains($base, $context)) {
        $base .= ' – ' . $context;
    }

    return lead_import_limit($base, 180);
}

function lead_import_title_text(string $value): string
{
    $value = str_replace('_', ' ', $value);
    $value = preg_replace('/[ \t\r\n]+/u', ' ', $value);
    $value = is_string($value) ? trim($value, " \t\n\r\0\x0B-_–") : '';

    return $value;
}

function lead_import_short_location_title(string $location): string
{
    $location = lead_import_title_text($location);

    if ($location === '') {
        return '';
    }

    $parts = preg_split('/[,;]/u', $location);
    $firstPart = is_array($parts) ? trim((string) ($parts[0] ?? '')) : $location;

    return $firstPart !== '' ? $firstPart : $location;
}

function lead_import_title_key(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    $value = is_string($value) ? trim($value) : '';

    return $value;
}

function lead_import_title_contains(string $title, string $context): bool
{
    $titleKey = lead_import_title_key($title);
    $contextKey = lead_import_title_key($context);

    return $contextKey !== '' && str_contains($titleKey, $contextKey);
}

function lead_import_strip_leading_title_context(string $title, array $contexts): string
{
    $title = lead_import_title_text($title);

    if ($title === '') {
        return '';
    }

    foreach ($contexts as $context) {
        $context = lead_import_title_text((string) $context);

        if ($context === '') {
            continue;
        }

        do {
            $previous = $title;
            $pattern = '/^' . preg_quote($context, '/') . '[\s_\-–:]+/iu';
            $title = preg_replace($pattern, '', $title);
            $title = is_string($title) ? lead_import_title_text($title) : $previous;
        } while ($title !== $previous);
    }

    return $title;
}

function lead_import_customer_work_summary(array $payload): string
{
    $rows = [
        'Ingatlan helye' => (string) $payload['property_location'],
        'Munka típusa' => (string) $payload['work_type'],
        'Van már beadott igény' => (string) $payload['has_existing_utility_request'],
        'Település' => (string) $payload['city'],
    ];

    $lines = ['Az Ön igényének összefoglalója:'];
    foreach ($rows as $label => $value) {
        if (trim($value) === '') {
            continue;
        }

        $lines[] = '- ' . $label . ': ' . lead_import_note_value($value);
    }

    $lines[] = '';
    $lines[] = 'Kérjük, ellenőrizze és szükség esetén pontosítsa az adatokat az ügyfélportálon.';

    return implode("\n", $lines);
}

function lead_import_customer_note(array $payload): string
{
    return "Facebook lead import\n"
        . 'Forrás: Google Sheet / Facebook instant form' . "\n"
        . 'Lead státusz: ' . lead_import_note_value((string) $payload['lead_status']) . "\n"
        . 'Created time: ' . lead_import_note_value((string) $payload['created_time']);
}

function lead_import_work_request_note(array $payload, string $externalLeadId): string
{
    $rows = [
        'Forrás' => LEAD_IMPORT_SOURCE_FACEBOOK,
        'Kampány' => (string) $payload['campaign_name'],
        'Űrlap' => (string) $payload['form_name'],
        'Ingatlan helye' => (string) $payload['property_location'],
        'Munka típusa' => (string) $payload['work_type'],
        'Van már beadott igény' => (string) $payload['has_existing_utility_request'],
        'Település' => (string) $payload['city'],
        'Lead státusz' => (string) $payload['lead_status'],
        'Facebook/Sheet lead ID' => $externalLeadId,
        'Created time' => (string) $payload['created_time'],
    ];

    if ($payload['sheet_row'] !== null) {
        $rows['Google Sheet sor'] = (string) $payload['sheet_row'];
    }

    $lines = ['Facebook lead import:'];
    foreach ($rows as $label => $value) {
        $lines[] = '- ' . $label . ': ' . lead_import_note_value($value);
    }

    return implode("\n", $lines);
}

function lead_import_note_value(string $value): string
{
    $value = trim($value);

    return $value !== '' ? $value : '-';
}

function lead_import_send_activation_email_if_needed(int $userId, bool $userCreated, int $requestId): array
{
    if (!$userCreated || $userId <= 0) {
        return ['ok' => true, 'message' => 'No new user activation email needed.'];
    }

    if (!password_reset_table_exists()) {
        return ['ok' => false, 'message' => 'password_reset_tokens table is missing.'];
    }

    $user = find_user_by_id($userId);
    if (!is_array($user)) {
        return ['ok' => false, 'message' => 'Imported user account was not found.'];
    }

    try {
        $token = create_password_reset_token($userId);
        $result = send_account_activation_email($user, $token);

        record_connection_request_activity(
            $requestId,
            'lead_import_activation',
            !empty($result['ok']) ? 'Aktiváló email kiküldve' : 'Aktiváló email küldése sikertelen',
            (string) ($result['message'] ?? ''),
            null,
            'Lead import API'
        );

        return $result;
    } catch (Throwable $exception) {
        record_connection_request_activity(
            $requestId,
            'lead_import_activation',
            'Aktiváló email küldése sikertelen',
            lead_import_public_exception_message($exception),
            null,
            'Lead import API'
        );

        return ['ok' => false, 'message' => lead_import_public_exception_message($exception)];
    }
}

function lead_import_resolved_external_id(array $payload): string
{
    $externalLeadId = trim((string) $payload['external_lead_id']);

    if ($externalLeadId !== '') {
        return lead_import_identifier($externalLeadId);
    }

    return 'generated:' . hash(
        'sha256',
        strtolower((string) $payload['email'])
        . '|'
        . lead_import_phone_digits((string) $payload['phone'])
        . '|'
        . (string) $payload['created_time']
    );
}

function lead_import_identifier(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return 'generated:' . hash('sha256', random_bytes(16));
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') <= 190) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, 120, 'UTF-8'), ': ') . ':' . hash('sha256', $value);
    }

    if (strlen($value) <= 190) {
        return $value;
    }

    return rtrim(substr($value, 0, 120), ': ') . ':' . hash('sha256', $value);
}

function lead_import_payload_hash(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return hash('sha256', is_string($json) ? $json : serialize($payload));
}

function lead_import_email_is_valid(string $email): bool
{
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function lead_import_phone_digits(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function lead_import_extract_postal_code(string $value): string
{
    if (preg_match('/\b([1-9][0-9]{3})\b/', $value, $matches)) {
        return (string) $matches[1];
    }

    return '';
}

function lead_import_limit(string $value, int $limit): string
{
    if ($limit < 1) {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $limit
            ? mb_substr($value, 0, $limit, 'UTF-8')
            : $value;
    }

    return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
}

function lead_import_public_exception_message(Throwable $exception): string
{
    return APP_DEBUG ? $exception->getMessage() : 'Import failed';
}
