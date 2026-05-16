<?php
declare(strict_types=1);

$errors = [];
$email = trim((string) ($_POST['email'] ?? $_GET['email'] ?? ''));
$code = '';
$flash = get_flash();
$schemaErrors = [];

try {
    $schemaErrors = email_verification_schema_errors();
} catch (Throwable $exception) {
    $schemaErrors[] = APP_DEBUG ? $exception->getMessage() : 'Az email megerősítés adatbázis-ellenőrzése sikertelen.';
}

if (is_logged_in()) {
    $currentUser = current_user();
    $currentDbUser = is_array($currentUser) ? find_user_by_id((int) ($currentUser['id'] ?? 0)) : null;

    if ($currentDbUser !== null && user_email_is_verified($currentDbUser)) {
        redirect(dashboard_path_for_user($currentDbUser));
    }

    if ($currentDbUser !== null && $email === '') {
        $email = (string) ($currentDbUser['email'] ?? '');
    }
}

$user = filter_var($email, FILTER_VALIDATE_EMAIL) ? find_user_by_email($email) : null;

if (is_post()) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? 'verify');
    $code = trim((string) ($_POST['code'] ?? ''));

    if ($schemaErrors !== []) {
        $errors[] = 'Az email megerősítéshez előbb futtasd le a database/email_verification.sql fájlt phpMyAdminban.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Érvényes email cím megadása kötelező.';
    }

    if ($user === null && $errors === []) {
        $errors[] = 'Nem található ilyen email címhez tartozó fiók.';
    }

    if ($errors === [] && $user !== null && user_email_is_verified($user)) {
        set_flash('success', 'Ez az email cím már meg van erősítve. Be tudsz jelentkezni.');
        redirect('/login');
    }

    if ($errors === [] && $user !== null && $action === 'resend') {
        try {
            $verificationCode = create_email_verification_code((int) $user['id']);
            $result = send_email_verification_email($user, $verificationCode);

            if (!$result['ok']) {
                $errors[] = (string) $result['message'];
            } else {
                set_flash('success', 'Új megerősítő kódot küldtünk emailben.');
                redirect('/verify-email?email=' . rawurlencode($email));
            }
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az új kód küldése sikertelen.';
        }
    }

    if ($errors === [] && $user !== null && $action !== 'resend') {
        try {
            $verification = verify_user_email_with_code((int) $user['id'], $code);

            if (!$verification['ok']) {
                $errors[] = (string) $verification['message'];
            } else {
                $verifiedUser = find_user_by_id((int) $user['id']);

                if ($verifiedUser === null) {
                    throw new RuntimeException('A felhasználó nem olvasható vissza.');
                }

                login_user($verifiedUser);

                if (!empty($verification['newly_verified'])) {
                    try {
                        send_verified_registration_admin_notification($verifiedUser);
                    } catch (Throwable) {
                        // Az admin értesítés nem akadályozhatja a sikeres email megerősítést.
                    }
                }

                set_flash('success', 'Az email címet megerősítettük, a fiókod aktív.');
                redirect(consume_pending_email_verification_redirect((int) $verifiedUser['id']) ?? dashboard_path_for_user($verifiedUser));
            }
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az email megerősítése sikertelen.';
        }
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Email megerősítés</p>
            <h1>Regisztráció aktiválása</h1>
            <p>Add meg az emailben kapott 6 számjegyű kódot. Ha elírtad vagy lejárt, kérhetsz újat.</p>
        </div>

        <div class="auth-panel">
            <h2>Megerősítő kód</h2>

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
            <?php endif; ?>

            <?php if ($schemaErrors !== []): ?>
                <div class="alert alert-info">
                    <p><strong>Email megerősítés beállítása szükséges.</strong></p>
                    <p>Futtasd le phpMyAdminban a <strong>database/email_verification.sql</strong> fájlt.</p>
                    <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="form" method="post" action="<?= h(url_path('/verify-email')); ?>">
                <?= csrf_field(); ?>

                <label for="email">Email cím</label>
                <input id="email" name="email" type="email" value="<?= h($email); ?>" required maxlength="190" autocomplete="email">

                <label for="code">Megerősítő kód</label>
                <input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" value="<?= h($code); ?>" required autocomplete="one-time-code">

                <button class="button" type="submit" name="action" value="verify">Email megerősítése</button>
            </form>

            <form class="form compact-form" method="post" action="<?= h(url_path('/verify-email')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="email" value="<?= h($email); ?>">
                <button class="button button-secondary" type="submit" name="action" value="resend">Új kód küldése</button>
            </form>

            <div class="auth-panel-links">
                <a href="<?= h(url_path('/login')); ?>">Vissza a bejelentkezéshez</a>
            </div>
        </div>
    </div>
</section>
