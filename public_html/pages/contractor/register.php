<?php
declare(strict_types=1);

if (is_logged_in()) {
    redirect(dashboard_path_for_user());
}

$errors = [];
$form = normalize_contractor_data([]);
$flash = get_flash();
$schemaErrors = [];
$emailVerificationErrors = [];

try {
    $schemaErrors = contractor_schema_errors();
} catch (Throwable $exception) {
    $schemaErrors[] = APP_DEBUG ? $exception->getMessage() : 'Az adatbázis állapotát nem sikerült ellenőrizni.';
}

try {
    $emailVerificationErrors = email_verification_schema_errors();
} catch (Throwable $exception) {
    $emailVerificationErrors[] = APP_DEBUG ? $exception->getMessage() : 'Az email megerősítés adatbázis-ellenőrzése sikertelen.';
}

if (is_post()) {
    require_valid_csrf_token();

    $form = normalize_contractor_data($_POST);
    $errors = $schemaErrors !== []
        ? array_merge(['A generálkivitelező regisztrációhoz előbb futtasd le a database/upgrade_connection_requests.sql fájlt phpMyAdminban.'], $schemaErrors)
        : validate_contractor_data($form);
    if ($emailVerificationErrors !== []) {
        $errors = array_merge(['Az email megerősítéshez előbb futtasd le a database/email_verification.sql fájlt phpMyAdminban.'], $errors, $emailVerificationErrors);
    }
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($schemaErrors === [] && strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'A jelszo legalabb ' . PASSWORD_MIN_LENGTH . ' karakter legyen.';
    }

    if ($schemaErrors === [] && $password !== $passwordConfirm) {
        $errors[] = 'A ket jelszo nem egyezik.';
    }

    if ($schemaErrors === [] && empty($_POST['legal_aszf_accepted'])) {
        $errors[] = 'Az ÁSZF elolvasása és elfogadása kötelező.';
    }

    if ($schemaErrors === [] && empty($_POST['legal_privacy_accepted'])) {
        $errors[] = 'Az Adatkezelési tájékoztató elolvasásának igazolása kötelező.';
    }

    if ($schemaErrors === [] && empty($_POST['legal_processor_accepted'])) {
        $errors[] = 'Az Adatfeldolgozói megállapodás elolvasása és elfogadása kötelező.';
    }

    if ($schemaErrors === [] && find_user_by_email($form['email']) !== null) {
        $errors[] = 'Ezzel az email cimmel mar letezik felhasznalo.';
    }

    if ($errors === []) {
        try {
            $userId = create_contractor_account($form, $password);
            $user = find_user_by_id($userId);

            if ($user === null) {
                throw new RuntimeException('A felhasználó létrejött, de nem olvasható vissza.');
            }

            set_pending_email_verification_redirect($userId, '/contractor/work-request');
            $verificationCode = create_email_verification_code($userId);
            $verificationResult = send_email_verification_email($user, $verificationCode);

            if (!$verificationResult['ok']) {
                throw new RuntimeException((string) $verificationResult['message']);
            }

            set_flash('success', 'Elküldtük a 6 számjegyű megerősítő kódot emailben. A generálkivitelezői fiók a kód megadása után lesz aktív.');
            redirect('/verify-email?email=' . rawurlencode((string) $user['email']));
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $errors[] = (str_contains($message, 'general_contractor') || str_contains($message, 'contractors') || str_contains($message, 'created_by_user_id'))
                ? 'A regisztrációhoz hiányzik az adatbázis-frissítés. Futtasd le a database/upgrade_connection_requests.sql fájlt phpMyAdminban.'
                : (APP_DEBUG ? $message : 'A regisztracio sikertelen.');
        }
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Generálkivitelező portál</p>
            <h1>Generálkivitelező regisztráció</h1>
            <p>Hozz létre saját kivitelezői fiókot. Belépés után minden munkához külön végügyfelet, munkaadatot és dokumentumot rögzíthetsz.</p>
        </div>

        <div class="auth-panel">
            <h2>Kivitelezo adatok</h2>

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
            <?php endif; ?>

            <?php if ($schemaErrors !== []): ?>
                <div class="alert alert-info">
                    <p><strong>Adatbázis frissítés szükséges.</strong></p>
                    <p>Futtasd le phpMyAdminban a <strong>database/upgrade_connection_requests.sql</strong> fájlt, majd próbáld újra a regisztrációt.</p>
                    <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($emailVerificationErrors !== []): ?>
                <div class="alert alert-info">
                    <p><strong>Email megerősítés beállítása szükséges.</strong></p>
                    <p>Futtasd le phpMyAdminban a <strong>database/email_verification.sql</strong> fájlt, majd próbáld újra a regisztrációt.</p>
                    <?php foreach ($emailVerificationErrors as $emailVerificationError): ?><p><?= h($emailVerificationError); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="form" method="post" action="<?= h(url_path('/contractor/register')); ?>">
                <?= csrf_field(); ?>

                <label for="contractor_name">Generálkivitelező neve</label>
                <input id="contractor_name" name="contractor_name" value="<?= h($form['contractor_name']); ?>" required>

                <label for="company_name">Cégnév</label>
                <input id="company_name" name="company_name" value="<?= h($form['company_name']); ?>">

                <label for="tax_number">Adószám</label>
                <input id="tax_number" name="tax_number" value="<?= h($form['tax_number']); ?>">

                <label for="contact_name">Kapcsolattarto neve</label>
                <input id="contact_name" name="contact_name" value="<?= h($form['contact_name']); ?>" required>

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

                <label for="notes">Megjegyzes</label>
                <textarea id="notes" name="notes" rows="3"><?= h($form['notes']); ?></textarea>

                <label for="password">Jelszo</label>
                <input id="password" name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH; ?>" required>

                <label for="password_confirm">Jelszo ujra</label>
                <input id="password_confirm" name="password_confirm" type="password" minlength="<?= PASSWORD_MIN_LENGTH; ?>" required>

                <div class="legal-consent-group" role="group" aria-labelledby="contractor-legal-consents-title">
                    <p id="contractor-legal-consents-title" class="legal-consent-title">A regisztráció csak akkor küldhető be, ha mindhárom jogi dokumentumot elolvastad és elfogadtad.</p>
                    <label class="checkbox-row legal-consent-row">
                        <input type="checkbox" name="legal_aszf_accepted" value="1" required <?= !empty($_POST['legal_aszf_accepted']) ? 'checked' : ''; ?>>
                        <span>Elolvastam és elfogadom az <a href="<?= h(url_path('/aszf')); ?>" target="_blank" rel="noopener">Általános Szerződési Feltételeket (ÁSZF)</a>.</span>
                    </label>
                    <label class="checkbox-row legal-consent-row">
                        <input type="checkbox" name="legal_privacy_accepted" value="1" required <?= !empty($_POST['legal_privacy_accepted']) ? 'checked' : ''; ?>>
                        <span>Elolvastam és megismertem az <a href="<?= h(url_path('/adatkezelesi-tajekoztato')); ?>" target="_blank" rel="noopener">Adatkezelési tájékoztatót</a>.</span>
                    </label>
                    <label class="checkbox-row legal-consent-row">
                        <input type="checkbox" name="legal_processor_accepted" value="1" required <?= !empty($_POST['legal_processor_accepted']) ? 'checked' : ''; ?>>
                        <span>Üzleti felhasználóként elolvastam és elfogadom az <a href="<?= h(url_path('/adatfeldolgozoi-megallapodas')); ?>" target="_blank" rel="noopener">Adatfeldolgozói megállapodást</a>.</span>
                    </label>
                </div>

                <button class="button" type="submit">Generálkivitelező regisztráció</button>
            </form>
        </div>
    </div>
</section>
