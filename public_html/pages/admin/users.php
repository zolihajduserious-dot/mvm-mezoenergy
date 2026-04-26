<?php
declare(strict_types=1);

require_role(['admin']);

$errors = [];
$flash = get_flash();
$form = [
    'name' => '',
    'email' => '',
];

if (is_post()) {
    require_valid_csrf_token();

    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($form['name'] === '') {
        $errors[] = 'A név megadása kötelező.';
    }

    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Érvényes email cím megadása kötelező.';
    }

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'A jelszó legalább ' . PASSWORD_MIN_LENGTH . ' karakter legyen.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'A két jelszó nem egyezik.';
    }

    if ($errors === []) {
        try {
            db_query(
                'INSERT INTO `users` (`name`, `email`, `password_hash`, `is_admin`, `role`, `customer_id`)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $form['name'],
                    $form['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                    0,
                    'specialist',
                    null,
                ]
            );

            set_flash('success', 'Az adminisztrátori profil elkészült.');
            redirect('/admin/users');
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az adminisztrátori profil létrehozása sikertelen.';
        }
    }
}

$staffUsers = users_table_exists()
    ? db_query(
        'SELECT `id`, `name`, `email`, `is_admin`, `role`, `created_at`
         FROM `users`
         WHERE `role` IN (?, ?) OR `is_admin` = ?
         ORDER BY `role` ASC, `name` ASC, `id` DESC',
        ['admin', 'specialist', 1]
    )->fetchAll()
    : [];
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Főadmin</p>
                <h1>Adminisztrátorok</h1>
                <p>Az adminisztrátor kezelheti az ügyfeleket, igényeket, árajánlatokat és MVM dokumentumokat, de az árajánlati tételeket nem módosíthatja.</p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <div class="form-grid two">
            <section class="auth-panel">
                <h2>Új adminisztrátor</h2>

                <?php if ($errors !== []): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $error): ?>
                            <p><?= h($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= h(url_path('/admin/users')); ?>">
                    <?= csrf_field(); ?>

                    <label for="name">Név</label>
                    <input id="name" name="name" value="<?= h($form['name']); ?>" required maxlength="120" autocomplete="name">

                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="<?= h($form['email']); ?>" required maxlength="190" autocomplete="email">

                    <label for="password">Kezdő jelszó</label>
                    <input id="password" name="password" type="password" required minlength="<?= PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">

                    <label for="password_confirm">Kezdő jelszó újra</label>
                    <input id="password_confirm" name="password_confirm" type="password" required minlength="<?= PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">

                    <button class="button" type="submit">Adminisztrátor létrehozása</button>
                </form>
            </section>

            <section class="auth-panel">
                <h2>Rögzített admin profilok</h2>

                <?php if ($staffUsers === []): ?>
                    <p class="muted-text">Még nincs admin profil.</p>
                <?php else: ?>
                    <div class="status-list">
                        <?php foreach ($staffUsers as $staffUser): ?>
                            <?php $role = !empty($staffUser['is_admin']) ? 'admin' : (string) $staffUser['role']; ?>
                            <li>
                                <span class="status-label"><?= h((string) $staffUser['name']); ?></span>
                                <span class="status-value">
                                    <?= h((string) $staffUser['email']); ?> · <?= h(user_role_label($role)); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>
