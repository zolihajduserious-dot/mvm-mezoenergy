<?php
declare(strict_types=1);

$errors = [];
$email = '';
$requestedReturnPath = auth_safe_return_path((string) ($_GET['return'] ?? $_POST['return'] ?? ''));

if (is_logged_in()) {
    redirect($requestedReturnPath ?? dashboard_path_for_user());
}

$currentLoginRoute = current_route();
$loginPath = match ($currentLoginRoute) {
    'admin/login' => '/admin/login',
    'electrician/login' => '/electrician/login',
    default => '/login',
};
$loginAction = login_path_with_return($loginPath, $requestedReturnPath);
$adminLoginPath = login_path_with_return('/admin/login', $requestedReturnPath);
$electricianLoginPath = login_path_with_return('/electrician/login', $requestedReturnPath);
$loginTitle = match ($currentLoginRoute) {
    'admin/login' => 'Admin belépés',
    'electrician/login' => 'Szerelői belépés',
    default => 'Belépés',
};
$loginLead = match ($currentLoginRoute) {
    'admin/login' => 'Adminisztrátorként itt tudsz belépni az ügyfelek, munkák, árajánlatok és MVM dokumentumok kezeléséhez.',
    'electrician/login' => 'Szerelőként itt tudsz belépni a kiadott munkáidhoz. A munka előtt és után innen töltheted fel a kötelező fotókat és az elkészült beavatkozási lapot.',
    default => 'Jelentkezz be az ügyfélportálhoz, az admin felülethez vagy a szerelői munkákhoz. Belépés után a rendszer automatikusan a saját felületedre visz.',
};
$usersTableReady = false;
$adminExists = false;
$flash = get_flash();

try {
    $usersTableReady = users_table_exists();
    $adminExists = $usersTableReady && has_admin_user();
} catch (Throwable $exception) {
    $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az adatbázistábla ellenőrzése sikertelen.';
}

if (is_post() && $usersTableReady) {
    require_valid_csrf_token();

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Érvényes email cím megadása kötelező.';
    }

    if ($password === '') {
        $errors[] = 'A jelszó megadása kötelező.';
    }

    if ($errors === []) {
        $user = find_user_by_email($email);
        $loginMatched = $user !== null
            && password_verify($password, (string) $user['password_hash']);

        if ($loginMatched) {
            if (!user_email_is_verified($user)) {
                try {
                    $verificationCode = create_email_verification_code((int) $user['id']);
                    $verificationResult = send_email_verification_email($user, $verificationCode);

                    if (!$verificationResult['ok']) {
                        $errors[] = (string) $verificationResult['message'];
                    } else {
                        set_flash('success', 'A belépéshez előbb erősítsd meg az email címedet. Új kódot küldtünk.');
                        redirect('/verify-email?email=' . rawurlencode((string) $user['email']));
                    }
                } catch (Throwable $exception) {
                    $errors[] = APP_DEBUG ? $exception->getMessage() : 'A megerősítő kód küldése sikertelen.';
                }
            }

            if ($errors === []) {
                login_user($user);
                redirect($requestedReturnPath ?? dashboard_path_for_user($user));
            }
        }

        if (!$loginMatched) {
            $errors[] = 'Hibás email cím vagy jelszó.';
        }
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Mező Energy Kft.</p>
            <h1><?= h($loginTitle); ?></h1>
            <p><?= h($loginLead); ?></p>
        </div>

        <div class="auth-panel">
            <h2><?= h($loginTitle); ?></h2>

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= h((string) $flash['type']); ?>">
                    <p><?= h((string) $flash['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$usersTableReady): ?>
                <div class="alert alert-error">
                    Előbb futtasd le a <strong>database/schema.sql</strong> fájlt phpMyAdminban.
                </div>
            <?php elseif (!$adminExists): ?>
                <div class="alert alert-info">
                    Még nincs admin felhasználó. Hozd létre az első admint.
                </div>
                <a class="button" href="<?= h(url_path('/admin/setup')); ?>">Admin létrehozása</a>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= h($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($usersTableReady): ?>
                <form class="form" method="post" action="<?= h(url_path($loginAction)); ?>">
                    <?= csrf_field(); ?>
                    <?php if ($requestedReturnPath !== null): ?>
                        <input type="hidden" name="return" value="<?= h($requestedReturnPath); ?>">
                    <?php endif; ?>

                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="<?= h($email); ?>" required maxlength="190" autocomplete="email">

                    <label for="password">Jelszó</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password">

                    <button class="button" type="submit">Belépés</button>
                </form>
                <div class="auth-panel-links">
                    <?php if ($currentLoginRoute === 'electrician/login'): ?>
                        <a href="<?= h(url_path('/electrician/register')); ?>">Még nincs szerelői fiókod?</a>
                    <?php endif; ?>
                    <?php if ($currentLoginRoute !== 'admin/login'): ?>
                        <a href="<?= h(url_path($adminLoginPath)); ?>">Admin belépés</a>
                    <?php endif; ?>
                    <?php if ($currentLoginRoute !== 'electrician/login'): ?>
                        <a href="<?= h(url_path($electricianLoginPath)); ?>">Szerelői belépés</a>
                    <?php endif; ?>
                    <a href="<?= h(url_path('/verify-email')); ?>">Megerősítő kód megadása</a>
                    <a href="<?= h(url_path('/forgot-password')); ?>">Elfelejtetted a jelszavad?</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
