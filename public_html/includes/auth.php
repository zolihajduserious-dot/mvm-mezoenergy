<?php
declare(strict_types=1);

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function is_valid_csrf_token(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}

function require_valid_csrf_token(): void
{
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Érvénytelen biztonsági token.');
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (empty($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        return null;
    }

    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);

    return $flash;
}

function users_table_exists(): bool
{
    $statement = db_query(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
        [DB_NAME, 'users']
    );

    return (bool) $statement->fetchColumn();
}

function has_admin_user(): bool
{
    if (!users_table_exists()) {
        return false;
    }

    $statement = db_query(
        'SELECT COUNT(*) FROM `users` WHERE `is_admin` = ? OR `role` = ?',
        [1, 'admin']
    );

    return (int) $statement->fetchColumn() > 0;
}

function find_user_by_email(string $email): ?array
{
    if (!users_table_exists()) {
        return null;
    }

    $statement = db_query(
        'SELECT * FROM `users` WHERE `email` = ? LIMIT 1',
        [$email]
    );
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function find_user_by_id(int $id): ?array
{
    if (!users_table_exists()) {
        return null;
    }

    $statement = db_query(
        'SELECT * FROM `users` WHERE `id` = ? LIMIT 1',
        [$id]
    );
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function user_email_verification_column_exists(): bool
{
    if (!users_table_exists()) {
        return false;
    }

    $statement = db_query(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [DB_NAME, 'users', 'email_verified_at']
    );

    return (bool) $statement->fetchColumn();
}

function email_verification_table_exists(): bool
{
    $statement = db_query(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
        [DB_NAME, 'email_verification_codes']
    );

    return (bool) $statement->fetchColumn();
}

function email_verification_schema_errors(): array
{
    $errors = [];

    if (!users_table_exists()) {
        $errors[] = 'Hiányzik a users tábla.';
    } elseif (!user_email_verification_column_exists()) {
        $errors[] = 'Hiányzik a users.email_verified_at oszlop.';
    }

    if (!email_verification_table_exists()) {
        $errors[] = 'Hiányzik az email_verification_codes tábla.';
    }

    return $errors;
}

function create_user_account_record(
    string $name,
    string $email,
    string $password,
    string $role,
    ?int $customerId = null,
    bool $isAdmin = false,
    bool $emailVerified = false
): int {
    $columns = ['name', 'email', 'password_hash', 'is_admin', 'role', 'customer_id'];
    $values = [
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $isAdmin ? 1 : 0,
        $role,
        $customerId,
    ];

    if (user_email_verification_column_exists()) {
        $columns[] = 'email_verified_at';
        $values[] = $emailVerified ? date('Y-m-d H:i:s') : null;
    }

    $quotedColumns = array_map(static fn (string $column): string => '`' . $column . '`', $columns);

    db_query(
        'INSERT INTO `users` (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')',
        $values
    );

    return (int) db()->lastInsertId();
}

function user_email_is_verified(array $user): bool
{
    if (!user_email_verification_column_exists()) {
        return true;
    }

    return trim((string) ($user['email_verified_at'] ?? '')) !== '';
}

function email_verification_code_hash(string $code): string
{
    $secret = (defined('DB_PASS') ? (string) DB_PASS : '') . '|' . APP_NAME . '|email-verification';

    return hash_hmac('sha256', $code, $secret);
}

function create_email_verification_code(int $userId): string
{
    if (!email_verification_table_exists()) {
        throw new RuntimeException('Az email_verification_codes tábla hiányzik.');
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    db_query(
        'UPDATE `email_verification_codes`
         SET `used_at` = COALESCE(`used_at`, NOW())
         WHERE `user_id` = ? AND `used_at` IS NULL',
        [$userId]
    );

    db_query(
        'INSERT INTO `email_verification_codes` (`user_id`, `code_hash`, `expires_at`, `ip_address`)
         VALUES (?, ?, ?, ?)',
        [$userId, email_verification_code_hash($code), $expiresAt, $ipAddress !== '' ? $ipAddress : null]
    );

    return $code;
}

function find_pending_email_verification_code(int $userId): ?array
{
    if (!email_verification_table_exists()) {
        return null;
    }

    $statement = db_query(
        'SELECT *
         FROM `email_verification_codes`
         WHERE `user_id` = ?
           AND `used_at` IS NULL
           AND `expires_at` > NOW()
         ORDER BY `id` DESC
         LIMIT 1',
        [$userId]
    );
    $code = $statement->fetch();

    return is_array($code) ? $code : null;
}

function mark_user_email_verified(int $userId): void
{
    if (!user_email_verification_column_exists()) {
        return;
    }

    db_query(
        'UPDATE `users`
         SET `email_verified_at` = COALESCE(`email_verified_at`, NOW())
         WHERE `id` = ?',
        [$userId]
    );
}

function verify_user_email_with_code(int $userId, string $code): array
{
    $code = preg_replace('/\D+/', '', $code) ?? '';

    if (strlen($code) !== 6) {
        return ['ok' => false, 'message' => 'A megerősítő kód 6 számjegyből áll.', 'newly_verified' => false];
    }

    $user = find_user_by_id($userId);

    if ($user === null) {
        return ['ok' => false, 'message' => 'A felhasználó nem található.', 'newly_verified' => false];
    }

    if (user_email_is_verified($user)) {
        return ['ok' => true, 'message' => 'Az email cím már meg van erősítve.', 'newly_verified' => false];
    }

    $pendingCode = find_pending_email_verification_code($userId);

    if ($pendingCode === null) {
        return ['ok' => false, 'message' => 'A megerősítő kód lejárt. Kérj új kódot.', 'newly_verified' => false];
    }

    $attempts = (int) ($pendingCode['attempts'] ?? 0);

    if ($attempts >= 5) {
        db_query('UPDATE `email_verification_codes` SET `used_at` = NOW() WHERE `id` = ?', [(int) $pendingCode['id']]);
        return ['ok' => false, 'message' => 'Túl sok hibás próbálkozás történt. Kérj új kódot.', 'newly_verified' => false];
    }

    if (!hash_equals((string) $pendingCode['code_hash'], email_verification_code_hash($code))) {
        $attempts++;
        db_query('UPDATE `email_verification_codes` SET `attempts` = ? WHERE `id` = ?', [$attempts, (int) $pendingCode['id']]);

        if ($attempts >= 5) {
            db_query('UPDATE `email_verification_codes` SET `used_at` = NOW() WHERE `id` = ?', [(int) $pendingCode['id']]);
            return ['ok' => false, 'message' => 'Túl sok hibás próbálkozás történt. Kérj új kódot.', 'newly_verified' => false];
        }

        return ['ok' => false, 'message' => 'Hibás megerősítő kód.', 'newly_verified' => false];
    }

    mark_user_email_verified($userId);
    db_query('UPDATE `email_verification_codes` SET `used_at` = NOW() WHERE `id` = ?', [(int) $pendingCode['id']]);

    return ['ok' => true, 'message' => 'Az email címet megerősítettük.', 'newly_verified' => true];
}

function set_pending_email_verification_redirect(int $userId, string $path): void
{
    $safePath = auth_safe_return_path($path);

    if ($safePath === null) {
        return;
    }

    if (!isset($_SESSION['_email_verification_redirects']) || !is_array($_SESSION['_email_verification_redirects'])) {
        $_SESSION['_email_verification_redirects'] = [];
    }

    $_SESSION['_email_verification_redirects'][$userId] = $safePath;
}

function consume_pending_email_verification_redirect(int $userId): ?string
{
    if (empty($_SESSION['_email_verification_redirects']) || !is_array($_SESSION['_email_verification_redirects'])) {
        return null;
    }

    $path = $_SESSION['_email_verification_redirects'][$userId] ?? null;
    unset($_SESSION['_email_verification_redirects'][$userId]);

    return is_string($path) ? auth_safe_return_path($path) : null;
}

function validate_password_change(array $user, string $currentPassword, string $password, string $passwordConfirm): array
{
    $errors = [];

    if ($currentPassword === '') {
        $errors[] = 'A jelenlegi jelszó megadása kötelező.';
    } elseif (!password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
        $errors[] = 'A jelenlegi jelszó nem megfelelő.';
    }

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Az új jelszó legalább ' . PASSWORD_MIN_LENGTH . ' karakter legyen.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'A két új jelszó nem egyezik.';
    }

    return $errors;
}

function update_user_password(int $userId, string $password): void
{
    db_query(
        'UPDATE `users` SET `password_hash` = ? WHERE `id` = ?',
        [password_hash($password, PASSWORD_DEFAULT), $userId]
    );
}

function password_reset_table_exists(): bool
{
    $statement = db_query(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
        [DB_NAME, 'password_reset_tokens']
    );

    return (bool) $statement->fetchColumn();
}

function create_password_reset_token(int $userId): string
{
    if (!password_reset_table_exists()) {
        throw new RuntimeException('A password_reset_tokens tábla hiányzik.');
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    db_query(
        'UPDATE `password_reset_tokens`
         SET `used_at` = COALESCE(`used_at`, NOW())
         WHERE `user_id` = ? AND `used_at` IS NULL',
        [$userId]
    );

    db_query(
        'INSERT INTO `password_reset_tokens` (`user_id`, `token_hash`, `expires_at`, `ip_address`)
         VALUES (?, ?, ?, ?)',
        [$userId, $tokenHash, $expiresAt, $ipAddress !== '' ? $ipAddress : null]
    );

    return $token;
}

function find_password_reset_token(string $token): ?array
{
    if (!password_reset_table_exists() || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $statement = db_query(
        'SELECT prt.*, u.name, u.email
         FROM `password_reset_tokens` prt
         INNER JOIN `users` u ON u.id = prt.user_id
         WHERE prt.token_hash = ?
           AND prt.used_at IS NULL
           AND prt.expires_at > NOW()
         LIMIT 1',
        [hash('sha256', $token)]
    );
    $reset = $statement->fetch();

    return is_array($reset) ? $reset : null;
}

function reset_user_password_with_token(string $token, string $password): bool
{
    $reset = find_password_reset_token($token);

    if ($reset === null) {
        return false;
    }

    $userUpdateSql = 'UPDATE `users` SET `password_hash` = ?';
    $userUpdateParams = [password_hash($password, PASSWORD_DEFAULT)];

    if (user_email_verification_column_exists()) {
        $userUpdateSql .= ', `email_verified_at` = COALESCE(`email_verified_at`, NOW())';
    }

    $userUpdateSql .= ' WHERE `id` = ?';
    $userUpdateParams[] = (int) $reset['user_id'];

    db_query($userUpdateSql, $userUpdateParams);

    db_query(
        'UPDATE `password_reset_tokens` SET `used_at` = NOW() WHERE `id` = ?',
        [(int) $reset['id']]
    );

    return true;
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user'])
        ? $_SESSION['user']
        : null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function auth_safe_return_path(?string $path): ?string
{
    if ($path === null) {
        return null;
    }

    $path = trim($path);

    if (
        $path === ''
        || !str_starts_with($path, '/')
        || str_starts_with($path, '//')
        || str_contains($path, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $path)
    ) {
        return null;
    }

    $parts = parse_url($path);

    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return null;
    }

    $safePath = (string) ($parts['path'] ?? '/');

    if ($safePath === '' || !str_starts_with($safePath, '/')) {
        return null;
    }

    $target = $safePath;

    if (isset($parts['query'])) {
        $target .= '?' . (string) $parts['query'];
    }

    if (isset($parts['fragment'])) {
        $target .= '#' . (string) $parts['fragment'];
    }

    return auth_is_login_return_target($target) ? null : $target;
}

function auth_is_login_return_target(string $path): bool
{
    $parts = parse_url($path);

    if ($parts === false) {
        return true;
    }

    $route = trim(strtolower((string) ($parts['path'] ?? '')), '/');

    if ($route === 'index.php' && isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        $route = trim(strtolower((string) ($query['route'] ?? '')), '/');
    }

    return in_array($route, ['login', 'admin/login', 'electrician/login', 'verify-email', 'forgot-password', 'reset-password', 'logout', 'admin/logout'], true);
}

function auth_current_request_return_path(): ?string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $parts = parse_url($uri);

    if ($parts === false) {
        return null;
    }

    $path = (string) ($parts['path'] ?? '/');
    $target = $path !== '' ? $path : '/';

    if (isset($parts['query'])) {
        $target .= '?' . (string) $parts['query'];
    }

    if (!isset($parts['fragment'])) {
        $fragment = auth_inferred_current_request_fragment();

        if ($fragment !== '') {
            $target .= '#' . $fragment;
        }
    }

    return auth_safe_return_path($target);
}

function auth_inferred_current_request_fragment(): string
{
    if (current_route() !== 'admin/minicrm-import') {
        return '';
    }

    $requestId = isset($_GET['request']) ? max(0, (int) $_GET['request']) : 0;

    if ($requestId > 0) {
        return 'portal-work-' . $requestId;
    }

    $itemId = isset($_GET['item']) ? max(0, (int) $_GET['item']) : 0;

    if ($itemId > 0) {
        return 'minicrm-work-' . $itemId;
    }

    return '';
}

function login_path_with_return(string $loginPath = '/login', ?string $returnPath = null): string
{
    $returnPath = auth_safe_return_path($returnPath) ?? auth_current_request_return_path();

    if ($returnPath === null) {
        return $loginPath;
    }

    $separator = str_contains($loginPath, '?') ? '&' : '?';

    return $loginPath . $separator . 'return=' . rawurlencode($returnPath);
}

function login_user(array $user): void
{
    session_regenerate_id(true);

    $role = (string) ($user['role'] ?? '');

    if ($role === '') {
        $role = (int) ($user['is_admin'] ?? 0) === 1 ? 'admin' : 'customer';
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'is_admin' => (int) ($user['is_admin'] ?? 0) === 1 || $role === 'admin',
        'role' => $role,
        'customer_id' => isset($user['customer_id']) ? (int) $user['customer_id'] : null,
    ];
}

function logout_user(): void
{
    unset($_SESSION['user']);
    session_regenerate_id(true);
}

function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('error', 'A folytatáshoz be kell jelentkezned.');
        redirect(login_path_with_return('/login'));
    }
}

function current_user_role(): string
{
    $user = current_user();

    if (!is_array($user)) {
        return 'guest';
    }

    if (!empty($user['role'])) {
        return (string) $user['role'];
    }

    return !empty($user['is_admin']) ? 'admin' : 'customer';
}

function is_admin_user(): bool
{
    $user = current_user();

    return current_user_role() === 'admin'
        || (is_array($user) && !empty($user['is_admin']));
}

function can_manage_mvm_documents(): bool
{
    return is_staff_user();
}

function can_manage_price_items(): bool
{
    return is_admin_user();
}

function can_manage_admin_users(): bool
{
    return is_admin_user();
}

function can_view_super_admin_overview(): bool
{
    return is_admin_user();
}

function can_submit_development_suggestion(): bool
{
    return is_logged_in() && !is_customer_user();
}

function can_manage_development_suggestions(): bool
{
    return is_admin_user();
}

function is_specialist_user(): bool
{
    return current_user_role() === 'specialist';
}

function is_staff_user(): bool
{
    return is_admin_user() || current_user_role() === 'specialist';
}

function is_customer_user(): bool
{
    return current_user_role() === 'customer';
}

function is_general_contractor_user(): bool
{
    return current_user_role() === 'general_contractor';
}

function is_electrician_user(): bool
{
    return current_user_role() === 'electrician';
}

function user_role_labels(): array
{
    return [
        'admin' => 'Főadmin',
        'specialist' => 'Adminisztrátor',
        'customer' => 'Ügyfél',
        'general_contractor' => 'Generálkivitelező',
        'electrician' => 'Szerelő',
        'guest' => 'Vendég',
    ];
}

function user_role_label(?string $role = null): string
{
    $role = $role ?? current_user_role();
    $labels = user_role_labels();

    return $labels[$role] ?? $role;
}

function dashboard_path_for_user(?array $user = null): string
{
    $role = $user !== null ? (string) ($user['role'] ?? '') : current_user_role();

    if ($role === 'admin' || $role === 'specialist') {
        return '/admin/dashboard';
    }

    if ($role === 'general_contractor') {
        return '/contractor/work-requests';
    }

    if ($role === 'electrician') {
        return '/electrician/app';
    }

    return '/customer/work-requests';
}

function work_request_create_path_for_user(?array $user = null): string
{
    $role = $user !== null ? (string) ($user['role'] ?? '') : current_user_role();

    if ($role === 'admin' || $role === 'specialist') {
        return '/admin/connection-requests/edit';
    }

    if ($role === 'general_contractor') {
        return '/contractor/work-request';
    }

    if ($role === 'electrician') {
        return '/electrician/work-request';
    }

    return '/customer/work-request';
}

function require_role(array $roles): void
{
    require_login();

    if (in_array('admin', $roles, true) && is_admin_user()) {
        return;
    }

    if (in_array('electrician', $roles, true) && is_admin_user()) {
        return;
    }

    if (!in_array(current_user_role(), $roles, true)) {
        http_response_code(403);
        exit('Nincs jogosultságod az oldal megnyitásához.');
    }
}

start_secure_session();
