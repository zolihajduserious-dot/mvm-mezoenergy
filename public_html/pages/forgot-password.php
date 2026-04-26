<?php
declare(strict_types=1);

if (is_logged_in()) {
    redirect(dashboard_path_for_user());
}

$errors = [];
$email = '';
$flash = get_flash();
$usersTableReady = false;
$resetTableReady = false;

try {
    $usersTableReady = users_table_exists();
    $resetTableReady = password_reset_table_exists();
} catch (Throwable $exception) {
    $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az adatbázis ellenőrzése sikertelen.';
}

if (is_post() && $usersTableReady && $resetTableReady) {
    require_valid_csrf_token();

    $email = trim((string) ($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Érvényes email cím megadása kötelező.';
    }

    if ($errors === []) {
        try {
            $user = find_user_by_email($email);

            if ($user !== null) {
                $token = create_password_reset_token((int) $user['id']);
                $result = send_password_reset_email($user, $token);

                if (!$result['ok']) {
                    $errors[] = $result['message'];
                }
            }

            if ($errors === []) {
                set_flash('success', 'Ha létezik ilyen email címmel fiók, elküldtük a jelszó-visszaállító linket.');
                redirect('/forgot-password');
            }
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'A jelszó-visszaállítás indítása sikertelen.';
        }
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Mező Energy Kft.</p>
            <h1>Elfelejtett jelszó</h1>
            <p>Add meg a fiókodhoz tartozó email címet. Küldünk egy 1 óráig érvényes linket, ahol új jelszót állíthatsz be.</p>
        </div>

        <div class="auth-panel">
            <h2>Jelszó-visszaállítás</h2>

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= h((string) $flash['type']); ?>">
                    <p><?= h((string) $flash['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$usersTableReady || !$resetTableReady): ?>
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

            <?php if ($usersTableReady && $resetTableReady): ?>
                <form class="form" method="post" action="<?= h(url_path('/forgot-password')); ?>">
                    <?= csrf_field(); ?>

                    <label for="email">Email cím</label>
                    <input id="email" name="email" type="email" value="<?= h($email); ?>" required maxlength="190" autocomplete="email">

                    <button class="button" type="submit">Visszaállító link küldése</button>
                </form>
            <?php endif; ?>

            <div class="auth-panel-links">
                <a href="<?= h(url_path('/login')); ?>">Vissza a bejelentkezéshez</a>
            </div>
        </div>
    </div>
</section>
