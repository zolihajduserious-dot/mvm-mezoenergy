<?php
declare(strict_types=1);

if (is_logged_in()) {
    redirect(dashboard_path_for_user());
}

$errors = [];
$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$reset = null;
$schemaReady = false;

try {
    $schemaReady = users_table_exists() && password_reset_table_exists();
    $reset = $schemaReady ? find_password_reset_token($token) : null;
} catch (Throwable $exception) {
    $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az adatbázis ellenőrzése sikertelen.';
}

if (is_post() && $schemaReady) {
    require_valid_csrf_token();

    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($reset === null) {
        $errors[] = 'A jelszó-visszaállító link érvénytelen vagy lejárt.';
    }

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'A jelszónak legalább ' . PASSWORD_MIN_LENGTH . ' karakter hosszúnak kell lennie.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'A két jelszó nem egyezik.';
    }

    if ($errors === []) {
        try {
            if (reset_user_password_with_token($token, $password)) {
                set_flash('success', 'Az új jelszót elmentettük. Most már be tudsz jelentkezni.');
                redirect('/login');
            }

            $errors[] = 'A jelszó-visszaállító link érvénytelen vagy lejárt.';
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az új jelszó mentése sikertelen.';
        }
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Mező Energy Kft.</p>
            <h1>Új jelszó</h1>
            <p>Adj meg egy új, biztonságos jelszót. A visszaállító link egyszer használható és 1 óráig érvényes.</p>
        </div>

        <div class="auth-panel">
            <h2>Új jelszó beállítása</h2>

            <?php if (!$schemaReady): ?>
                <div class="alert alert-error">
                    <p>Előbb futtasd le a <strong>database/password_reset_tokens.sql</strong> fájlt phpMyAdminban.</p>
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= h($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($schemaReady && $reset !== null): ?>
                <form class="form" method="post" action="<?= h(url_path('/reset-password')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="token" value="<?= h($token); ?>">

                    <label for="password">Új jelszó</label>
                    <input id="password" name="password" type="password" required minlength="<?= PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">

                    <label for="password_confirm">Új jelszó újra</label>
                    <input id="password_confirm" name="password_confirm" type="password" required minlength="<?= PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">

                    <button class="button" type="submit">Új jelszó mentése</button>
                </form>
            <?php elseif ($schemaReady): ?>
                <div class="alert alert-error">
                    <p>A jelszó-visszaállító link érvénytelen vagy lejárt.</p>
                </div>
                <a class="button" href="<?= h(url_path('/forgot-password')); ?>">Új link kérése</a>
            <?php endif; ?>

            <div class="auth-panel-links">
                <a href="<?= h(url_path('/login')); ?>">Vissza a bejelentkezéshez</a>
            </div>
        </div>
    </div>
</section>
