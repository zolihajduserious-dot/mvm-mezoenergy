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

    db_query(
        'UPDATE `users` SET `password_hash` = ? WHERE `id` = ?',
        [password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']]
    );

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
        redirect('/login');
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
        return '/electrician/work-requests';
    }

    return '/customer/work-requests';
}

function require_role(array $roles): void
{
    require_login();

    if (in_array('admin', $roles, true) && is_admin_user()) {
        return;
    }

    if (!in_array(current_user_role(), $roles, true)) {
        http_response_code(403);
        exit('Nincs jogosultságod az oldal megnyitásához.');
    }
}

start_secure_session();
