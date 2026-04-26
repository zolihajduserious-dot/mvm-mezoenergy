<?php
declare(strict_types=1);

require_role(['customer']);

$user = current_user();
$account = is_array($user) ? find_user_by_id((int) $user['id']) : null;
$customer = current_customer();
$profileErrors = [];
$passwordErrors = [];
$flash = get_flash();
$form = $customer !== null ? normalize_customer_data($customer) : normalize_customer_data(['email' => $user['email'] ?? '', 'requester_name' => $user['name'] ?? '']);

if (is_post()) {
    require_valid_csrf_token();
    $action = (string) ($_POST['action'] ?? 'save_profile');

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($account === null) {
            $passwordErrors[] = 'A felhasználói fiók nem található.';
        } else {
            $passwordErrors = validate_password_change($account, $currentPassword, $password, $passwordConfirm);
        }

        if ($passwordErrors === []) {
            update_user_password((int) $account['id'], $password);
            set_flash('success', 'A jelszó módosítva.');
            redirect('/customer/profile');
        }
    } else {
        $form = normalize_customer_data($_POST);
        $profileErrors = validate_customer_data($form, true);

        if ($profileErrors === []) {
            try {
                if ($customer === null) {
                    $customerId = create_customer($form, (int) $user['id']);
                    db_query('UPDATE `users` SET `customer_id` = ? WHERE `id` = ?', [$customerId, $user['id']]);
                    $_SESSION['user']['customer_id'] = $customerId;
                } else {
                    update_customer((int) $customer['id'], $form);
                }

                set_flash('success', 'Az adatok mentve.');
                redirect('/customer/profile');
            } catch (Throwable $exception) {
                $profileErrors[] = APP_DEBUG ? $exception->getMessage() : 'Az adatok mentése sikertelen.';
            }
        }
    }
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Ügyfélportál</p>
            <h1>Ügyfél adatok</h1>
            <p>Ezek az adatok kellenek a mérőhelyi igény beküldéséhez. A jelszavadat ugyanitt tudod módosítani.</p>
        </div>

        <div class="form-grid">
            <div class="auth-panel">
                <h2>Adatlap</h2>

                <?php if ($flash !== null): ?>
                    <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
                <?php endif; ?>

                <?php if ($profileErrors !== []): ?>
                    <div class="alert alert-error">
                        <?php foreach ($profileErrors as $error): ?>
                            <p><?= h($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= h(url_path('/customer/profile')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_profile">
                    <label class="checkbox-row"><input type="checkbox" name="is_legal_entity" value="1" <?= (int) $form['is_legal_entity'] === 1 ? 'checked' : ''; ?>><span>Jogi személyként járok el</span></label>
                    <label>Név</label><input name="requester_name" value="<?= h($form['requester_name']); ?>" required>
                    <label>Születési név</label><input name="birth_name" value="<?= h($form['birth_name']); ?>" required>
                    <label>Cégnév</label><input name="company_name" value="<?= h($form['company_name']); ?>">
                    <label>Adószám</label><input name="tax_number" value="<?= h($form['tax_number']); ?>">
                    <label>Telefonszám</label><input name="phone" value="<?= h($form['phone']); ?>" required>
                    <label>Email</label><input name="email" type="email" value="<?= h($form['email']); ?>" required>
                    <label>Postai cím</label><input name="postal_address" value="<?= h($form['postal_address']); ?>" required>
                    <label>Irányítószám</label><input name="postal_code" value="<?= h($form['postal_code']); ?>" required>
                    <label>Település</label><input name="city" value="<?= h($form['city']); ?>" required>
                    <label>Levelezési cím</label><input name="mailing_address" value="<?= h($form['mailing_address']); ?>">
                    <label>Anyja neve</label><input name="mother_name" value="<?= h($form['mother_name']); ?>" required>
                    <label>Születési hely</label><input name="birth_place" value="<?= h($form['birth_place']); ?>" required>
                    <label>Születési idő</label><input name="birth_date" type="date" value="<?= h($form['birth_date']); ?>" required>
                    <label class="checkbox-row"><input type="checkbox" name="contact_data_accepted" value="1" <?= (int) $form['contact_data_accepted'] === 1 ? 'checked' : ''; ?>><span>A kapcsolattartási adataim megegyeznek az ajánlatkérő adataival</span></label>
                    <div class="form-actions">
                        <button class="button" type="submit">Mentés</button>
                        <a class="button button-secondary" href="<?= h(url_path('/customer/work-requests')); ?>">Igényeim</a>
                    </div>
                </form>
            </div>

            <div class="auth-panel">
                <h2>Jelszó módosítása</h2>

                <?php if ($passwordErrors !== []): ?>
                    <div class="alert alert-error">
                        <?php foreach ($passwordErrors as $error): ?>
                            <p><?= h($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= h(url_path('/customer/profile')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="change_password">

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
