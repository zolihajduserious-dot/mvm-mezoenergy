<?php
declare(strict_types=1);

$errors = [];
$name = '';
$email = '';
$usersTableReady = false;
$adminExists = false;

try {
    $usersTableReady = users_table_exists();
    $adminExists = $usersTableReady && has_admin_user();
} catch (Throwable $exception) {
    $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az adatbazis tabla ellenorzese sikertelen.';
}

if ($adminExists) {
    set_flash('info', 'Admin felhasznalo mar letezik. Jelentkezz be.');
    redirect('/login');
}

if (is_post() && $usersTableReady) {
    require_valid_csrf_token();

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($name === '') {
        $errors[] = 'A nev megadasa kotelezo.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ervenyes email cim megadasa kotelezo.';
    }

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'A jelszo legalabb ' . PASSWORD_MIN_LENGTH . ' karakter legyen.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'A ket jelszo nem egyezik.';
    }

    if ($errors === []) {
        try {
            db_query(
                'INSERT INTO `users` (`name`, `email`, `password_hash`, `is_admin`, `role`) VALUES (?, ?, ?, ?, ?)',
                [
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    1,
                    'admin',
                ]
            );

            $user = find_user_by_email($email);

            if ($user === null) {
                throw new RuntimeException('Az admin felhasznalo letrejott, de nem olvashato vissza.');
            }

            login_user($user);
            set_flash('success', 'Az első főadmin felhasználó létrejött.');
            redirect('/admin/dashboard');
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az admin felhasznalo letrehozasa sikertelen.';
        }
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Főadmin</p>
            <h1>Első főadmin létrehozása</h1>
            <p>
                Ez az oldal csak addig használható, amíg nincs admin felhasználó az adatbázisban.
                A jelszo biztonsagos hash formaban kerul tarolasra.
            </p>
        </div>

        <div class="auth-panel">
            <h2>Főadmin adatok</h2>

            <?php if (!$usersTableReady): ?>
                <div class="alert alert-error">
                    Előbb futtasd le a <strong>database/schema.sql</strong>, majd a <strong>database/quote_price_items_catalog.sql</strong>, <strong>database/quote_response_actions.sql</strong>, <strong>database/quote_assignment_guard.sql</strong>, <strong>database/admin_workflow_stages.sql</strong>, <strong>database/connection_request_drafts.sql</strong>, <strong>database/connection_request_types.sql</strong>, <strong>database/mvm_docx_form.sql</strong> és <strong>database/password_reset_tokens.sql</strong> fájlt phpMyAdminban.
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= h($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($usersTableReady): ?>
                <form class="form" method="post" action="<?= h(url_path('/admin/setup')); ?>">
                    <?= csrf_field(); ?>

                    <label for="name">Nev</label>
                    <input id="name" name="name" type="text" value="<?= h($name); ?>" required maxlength="120" autocomplete="name">

                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="<?= h($email); ?>" required maxlength="190" autocomplete="email">

                    <label for="password">Jelszo</label>
                    <input id="password" name="password" type="password" required minlength="<?= PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">

                    <label for="password_confirm">Jelszo ujra</label>
                    <input id="password_confirm" name="password_confirm" type="password" required minlength="<?= PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">

                    <button class="button" type="submit">Főadmin létrehozása</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
