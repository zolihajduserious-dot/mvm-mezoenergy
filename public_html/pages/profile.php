<?php
declare(strict_types=1);

$route = current_route();

if ($route === 'admin/profile') {
    require_role(['admin', 'specialist']);
} elseif ($route === 'electrician/profile') {
    require_role(['electrician']);
} elseif ($route === 'contractor/profile') {
    require_role(['general_contractor']);
} else {
    require_login();
}

if (is_customer_user()) {
    redirect('/customer/profile');
}

$user = current_user();
$account = is_array($user) ? find_user_by_id((int) $user['id']) : null;
$errors = [];
$flash = get_flash();
$profilePath = '/' . ($route !== '' ? $route : 'profile');
$role = current_user_role();

if (is_post()) {
    require_valid_csrf_token();

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($account === null) {
        $errors[] = 'A felhasználói fiók nem található.';
    } else {
        $errors = validate_password_change($account, $currentPassword, $password, $passwordConfirm);
    }

    if ($errors === []) {
        update_user_password((int) $account['id'], $password);
        set_flash('success', 'A jelszó módosítva.');
        redirect($profilePath);
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Profil</p>
            <h1>Fiókbeállítások</h1>
            <p>Itt tudod módosítani a belépési jelszavadat.</p>
        </div>

        <div class="form-grid">
            <div class="auth-panel">
                <h2>Fiók adatai</h2>

                <?php if ($flash !== null): ?>
                    <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
                <?php endif; ?>

                <dl class="admin-request-data-list">
                    <div>
                        <dt>Név</dt>
                        <dd><?= h((string) ($user['name'] ?? '-')); ?></dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd><?= h((string) ($user['email'] ?? '-')); ?></dd>
                    </div>
                    <div>
                        <dt>Jogosultság</dt>
                        <dd><?= h(user_role_label($role)); ?></dd>
                    </div>
                </dl>

                <div class="form-actions">
                    <a class="button" href="<?= h(url_path('/quick-quote')); ?>">Gyors árajánlat</a>
                    <a class="button button-secondary" href="<?= h(url_path(dashboard_path_for_user())); ?>">Vissza</a>
                </div>
            </div>

            <div class="auth-panel">
                <h2>Jelszó módosítása</h2>

                <?php if ($errors !== []): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $error): ?>
                            <p><?= h($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= h(url_path($profilePath)); ?>">
                    <?= csrf_field(); ?>

                    <label for="current_password">Jelenlegi jelszó</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password">

                    <label for="password">Új jelszó</label>
                    <input id="password" name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH; ?>" required autocomplete="new-password">

                    <label for="password_confirm">Új jelszó újra</label>
                    <input id="password_confirm" name="password_confirm" type="password" minlength="<?= PASSWORD_MIN_LENGTH; ?>" required autocomplete="new-password">

                    <button class="button" type="submit">Jelszó módosítása</button>
                </form>
            </div>
        </div>
    </div>
</section>
