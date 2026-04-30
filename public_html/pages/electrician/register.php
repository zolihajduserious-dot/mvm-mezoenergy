<?php
declare(strict_types=1);

if (is_logged_in()) {
    redirect(dashboard_path_for_user());
}

$errors = [];
$form = normalize_electrician_data(['is_active' => 1]);
$flash = get_flash();
$schemaErrors = [];

try {
    $schemaErrors = electrician_schema_errors();
} catch (Throwable $exception) {
    $schemaErrors[] = APP_DEBUG ? $exception->getMessage() : 'Az adatbázis állapotát nem sikerült ellenőrizni.';
}

if (is_post()) {
    require_valid_csrf_token();

    $form = normalize_electrician_data($_POST);
    $form['is_active'] = 1;
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $errors = $schemaErrors !== []
        ? array_merge(['A szerelői regisztrációhoz előbb futtasd le a database/electrician_workflow.sql fájlt phpMyAdminban.'], $schemaErrors)
        : validate_electrician_data($form, true, $password);

    if ($schemaErrors === [] && $password !== $passwordConfirm) {
        $errors[] = 'A két jelszó nem egyezik.';
    }

    if ($schemaErrors === [] && find_user_by_email($form['email']) !== null) {
        $errors[] = 'Ezzel az email címmel már létezik felhasználó.';
    }

    if ($errors === []) {
        try {
            create_electrician_account($form, $password);
            send_admin_activity_notification(
                'Új szerelő regisztrált',
                'Új szerelő hozott létre fiókot a weboldalon.',
                [
                    [
                        'title' => 'Szerelő adatai',
                        'rows' => [
                            ['label' => 'Név', 'value' => $form['name'] ?? '-'],
                            ['label' => 'Email', 'value' => $form['email'] ?? '-'],
                            ['label' => 'Telefon', 'value' => $form['phone'] ?? '-'],
                            ['label' => 'Megjegyzés', 'value' => $form['notes'] ?? '-'],
                        ],
                    ],
                ],
                [
                    ['label' => 'Szerelők megnyitása', 'url' => absolute_url('/admin/electricians')],
                ],
                ['email' => $form['email'], 'name' => $form['name']],
                null,
                'Szerelő regisztráció'
            );
            $user = find_user_by_email($form['email']);

            if ($user !== null) {
                login_user($user);
            }

            set_flash('success', 'Sikeres szerelői regisztráció. Most már rögzíthetsz saját felmérést, illetve az admin ki tud adni neked munkát.');
            redirect('/electrician/work-requests');
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $errors[] = (str_contains($message, 'electrician') || str_contains($message, 'electricians'))
                ? 'A regisztrációhoz hiányzik az adatbázis-frissítés. Futtasd le a database/electrician_workflow.sql fájlt phpMyAdminban.'
                : (APP_DEBUG ? $message : 'A szerelői regisztráció sikertelen.');
        }
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Szerelői portál</p>
            <h1>Szerelői regisztráció</h1>
            <p>Hozz létre saját szerelői fiókot. Belépés után látod a neked kiadott munkákat, és saját felmérést is rögzíthetsz.</p>
        </div>

        <div class="auth-panel">
            <h2>Szerelő adatai</h2>

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
            <?php endif; ?>

            <?php if ($schemaErrors !== []): ?>
                <div class="alert alert-info">
                    <p><strong>Adatbázis frissítés szükséges.</strong></p>
                    <p>Futtasd le phpMyAdminban a <strong>database/electrician_workflow.sql</strong> fájlt, majd próbáld újra a regisztrációt.</p>
                    <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="form" method="post" action="<?= h(url_path('/electrician/register')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="is_active" value="1">

                <label for="name">Szerelő neve</label>
                <input id="name" name="name" value="<?= h($form['name']); ?>" required>

                <label for="phone">Telefonszám</label>
                <input id="phone" name="phone" value="<?= h($form['phone']); ?>">

                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="<?= h($form['email']); ?>" required>

                <label for="notes">Megjegyzés</label>
                <textarea id="notes" name="notes" rows="3"><?= h($form['notes']); ?></textarea>

                <label for="password">Jelszó</label>
                <input id="password" name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH; ?>" required>

                <label for="password_confirm">Jelszó újra</label>
                <input id="password_confirm" name="password_confirm" type="password" minlength="<?= PASSWORD_MIN_LENGTH; ?>" required>

                <button class="button" type="submit">Szerelői regisztráció</button>
            </form>

            <div class="auth-panel-links">
                <a href="<?= h(url_path('/electrician/login')); ?>">Már van szerelői fiókom</a>
            </div>
        </div>
    </div>
</section>
