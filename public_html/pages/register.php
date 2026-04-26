<?php
declare(strict_types=1);

if (is_logged_in()) {
    redirect(dashboard_path_for_user());
}

$errors = [];
$form = normalize_customer_data([]);
$flash = get_flash();

if (is_post()) {
    require_valid_csrf_token();

    $form = normalize_customer_data($_POST);
    $errors = validate_customer_data($form, true);
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'A jelszó legalább ' . PASSWORD_MIN_LENGTH . ' karakter legyen.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'A két jelszó nem egyezik.';
    }

    if (find_user_by_email($form['email']) !== null) {
        $errors[] = 'Ezzel az email címmel már létezik felhasználó.';
    }

    if ($errors === []) {
        try {
            create_customer_account($form, $password);
            $user = find_user_by_email($form['email']);

            if ($user !== null) {
                login_user($user);
            }

            set_flash('success', 'Sikeres regisztráció. Most add meg az igény adatait, és töltsd fel a fájlokat.');
            redirect('/customer/work-request');
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'A regisztráció sikertelen.';
        }
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Ügyfélportál</p>
            <h1>Regisztráció</h1>
            <p>Add meg az adataidat. Ezek kellenek a mérőhelyi igény beküldéséhez.</p>
        </div>

        <div class="auth-panel">
            <h2>Ügyféladatok</h2>

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= h($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="form" method="post" action="<?= h(url_path('/register')); ?>">
                <?= csrf_field(); ?>
                <label class="checkbox-row">
                    <input type="checkbox" name="is_legal_entity" value="1" <?= (int) $form['is_legal_entity'] === 1 ? 'checked' : ''; ?>>
                    <span>Jogi személyként járok el</span>
                </label>

                <label for="requester_name">Ajánlatkérő neve</label>
                <input id="requester_name" name="requester_name" value="<?= h($form['requester_name']); ?>" required>

                <label for="birth_name">Születési név</label>
                <input id="birth_name" name="birth_name" value="<?= h($form['birth_name']); ?>" required>

                <label for="company_name">Cégnév</label>
                <input id="company_name" name="company_name" value="<?= h($form['company_name']); ?>">

                <label for="phone">Telefonszám</label>
                <input id="phone" name="phone" value="<?= h($form['phone']); ?>" required>

                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="<?= h($form['email']); ?>" required>

                <label for="postal_address">Postai cím</label>
                <input id="postal_address" name="postal_address" value="<?= h($form['postal_address']); ?>" required>

                <label for="postal_code">Irányítószám</label>
                <input id="postal_code" name="postal_code" value="<?= h($form['postal_code']); ?>" required>

                <label for="city">Település</label>
                <input id="city" name="city" value="<?= h($form['city']); ?>" required>

                <label for="mother_name">Anyja neve</label>
                <input id="mother_name" name="mother_name" value="<?= h($form['mother_name']); ?>" required>

                <label for="birth_place">Születési hely</label>
                <input id="birth_place" name="birth_place" value="<?= h($form['birth_place']); ?>" required>

                <label for="birth_date">Születési idő</label>
                <input id="birth_date" name="birth_date" type="date" value="<?= h($form['birth_date']); ?>" required>

                <label for="password">Jelszó</label>
                <input id="password" name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH; ?>" required>

                <label for="password_confirm">Jelszó újra</label>
                <input id="password_confirm" name="password_confirm" type="password" minlength="<?= PASSWORD_MIN_LENGTH; ?>" required>

                <label class="checkbox-row">
                    <input type="checkbox" name="contact_data_accepted" value="1" <?= (int) $form['contact_data_accepted'] === 1 ? 'checked' : ''; ?>>
                    <span>A kapcsolattartási adataim megegyeznek az ajánlatkérő adataival</span>
                </label>

                <button class="button" type="submit">Regisztráció</button>
            </form>
        </div>
    </div>
</section>
