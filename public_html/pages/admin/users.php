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

    $action = (string) ($_POST['action'] ?? 'create_staff');

    if ($action === 'delete_user') {
        $deleteUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if (!$deleteUserId) {
            set_flash('error', 'A törlendő felhasználó nem található.');
            redirect('/admin/users');
        }

        try {
            $summary = delete_user_account_with_related_data((int) $deleteUserId);
            set_flash(
                'success',
                'A felhasználó törölve: ' . (string) ($summary['user_name'] ?? '')
                    . '. Kapcsolódó adatok: ' . (int) ($summary['requests'] ?? 0) . ' igény, '
                    . (int) ($summary['quotes'] ?? 0) . ' árajánlat, '
                    . (int) ($summary['files'] ?? 0) . ' fájl.'
            );
        } catch (Throwable $exception) {
            set_flash('error', APP_DEBUG ? $exception->getMessage() : 'A felhasználó törlése sikertelen.');
        }

        redirect('/admin/users');
    }

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
            create_user_account_record($form['name'], $form['email'], $password, 'specialist', null, false, true);

            set_flash('success', 'Az adminisztrátori profil elkészült.');
            redirect('/admin/users');
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az adminisztrátori profil létrehozása sikertelen.';
        }
    }
}

$allUsers = admin_user_accounts();
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
                    <input type="hidden" name="action" value="create_staff">

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
                <h2>Felhasználók és törlés</h2>

                <?php if ($allUsers === []): ?>
                    <p class="muted-text">Még nincs felhasználói profil.</p>
                <?php else: ?>
                    <div class="status-list">
                        <?php foreach ($allUsers as $account): ?>
                            <?php
                            $role = !empty($account['is_admin']) ? 'admin' : (string) $account['role'];
                            $displayName = trim((string) ($account['name'] ?? ''));

                            if ($role === 'electrician' && !empty($account['electrician_name'])) {
                                $displayName = (string) $account['electrician_name'];
                            } elseif ($role === 'general_contractor' && !empty($account['contractor_name'])) {
                                $displayName = (string) $account['contractor_name'];
                            } elseif ($role === 'customer' && !empty($account['customer_name'])) {
                                $displayName = (string) $account['customer_name'];
                            }

                            $phone = match ($role) {
                                'electrician' => (string) ($account['electrician_phone'] ?? ''),
                                'general_contractor' => (string) ($account['contractor_phone'] ?? ''),
                                'customer' => (string) ($account['customer_phone'] ?? ''),
                                default => '',
                            };
                            $verifiedLabel = user_email_verification_column_exists()
                                ? (trim((string) ($account['email_verified_at'] ?? '')) !== '' ? 'email megerősítve' : 'email nincs megerősítve')
                                : 'email ellenőrzés nincs telepítve';
                            ?>
                            <li>
                                <span class="status-label"><?= h($displayName !== '' ? $displayName : ('Felhasználó #' . (int) $account['id'])); ?></span>
                                <span class="status-value">
                                    <?= h((string) $account['email']); ?> · <?= h(user_role_label($role)); ?> · <?= h($verifiedLabel); ?>
                                    <?= $phone !== '' ? ' · ' . h($phone) : ''; ?>
                                </span>
                                <?php if (can_delete_user_account($account)): ?>
                                    <form method="post" action="<?= h(url_path('/admin/users')); ?>" onsubmit="return confirm('Biztosan törlöd ezt a felhasználót? Ez a művelet nem visszavonható.');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int) $account['id']; ?>">
                                        <button class="table-action-button table-action-danger" type="submit">Törlés</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>
