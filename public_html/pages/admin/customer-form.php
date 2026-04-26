<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$customer = $id ? find_customer($id) : null;
$isEdit = $id && $customer !== null;
$errors = [];
$form = $isEdit ? normalize_customer_data($customer) : normalize_customer_data([]);

if ($id && !$customer) {
    set_flash('error', 'Az ügyfél nem található.');
    redirect('/admin/customers');
}

if (is_post()) {
    require_valid_csrf_token();
    $form = normalize_customer_data($_POST);
    $errors = validate_customer_data($form, false);

    if ($errors === []) {
        try {
            if ($isEdit) {
                update_customer((int) $id, $form);
                set_flash('success', 'Az ügyfél frissült.');
            } else {
                create_customer($form, null);
                set_flash('success', 'Az ügyfél létrejött.');
            }
            redirect('/admin/customers');
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az ügyfél mentése sikertelen.';
        }
    }
}
?>
<section class="admin-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Admin</p>
            <h1><?= $isEdit ? 'Ügyfél szerkesztése' : 'Új ügyfél'; ?></h1>
            <p>A szakember helyszínen is rögzíthet ügyfelet, majd innen indíthat ajánlatot.</p>
        </div>
        <div class="auth-panel">
            <h2>Ugyfel adatok</h2>
            <?php if ($errors !== []): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?></div><?php endif; ?>
            <form class="form" method="post" action="<?= h($isEdit ? url_path('/admin/customers/edit') . '?id=' . (int) $id : url_path('/admin/customers/edit')); ?>">
                <?= csrf_field(); ?>
                <label class="checkbox-row"><input type="checkbox" name="is_legal_entity" value="1" <?= (int) $form['is_legal_entity'] === 1 ? 'checked' : ''; ?>><span>Jogi szemely</span></label>
                <label>Nev</label><input name="requester_name" value="<?= h($form['requester_name']); ?>" required>
                <label>Születési név</label><input name="birth_name" value="<?= h($form['birth_name']); ?>">
                <label>Cégnév</label><input name="company_name" value="<?= h($form['company_name']); ?>">
                <label>Adószám</label><input name="tax_number" value="<?= h($form['tax_number']); ?>">
                <label>Telefon</label><input name="phone" value="<?= h($form['phone']); ?>" required>
                <label>Email</label><input name="email" type="email" value="<?= h($form['email']); ?>" required>
                <label>Postai cím</label><input name="postal_address" value="<?= h($form['postal_address']); ?>" required>
                <label>Irányítószám</label><input name="postal_code" value="<?= h($form['postal_code']); ?>" required>
                <label>Település</label><input name="city" value="<?= h($form['city']); ?>" required>
                <label>Levelezesi cim</label><input name="mailing_address" value="<?= h($form['mailing_address']); ?>">
                <label>Anyja neve</label><input name="mother_name" value="<?= h($form['mother_name']); ?>">
                <label>Születési hely</label><input name="birth_place" value="<?= h($form['birth_place']); ?>">
                <label>Születési idő</label><input name="birth_date" type="date" value="<?= h($form['birth_date']); ?>">
                <label>Hol talalt rank?</label><input name="source" value="<?= h($form['source']); ?>">
                <label>Statusz</label><input name="status" value="<?= h($form['status']); ?>">
                <label>Megjegyzes</label><textarea name="notes" rows="4"><?= h($form['notes']); ?></textarea>
                <label class="checkbox-row"><input type="checkbox" name="contact_data_accepted" value="1" <?= (int) $form['contact_data_accepted'] === 1 ? 'checked' : ''; ?>><span>Kapcsolattartasi adatok egyeznek</span></label>
                <div class="form-actions">
                    <button class="button" type="submit">Mentés</button>
                    <a class="button button-secondary" href="<?= h(url_path('/admin/customers')); ?>">Megsem</a>
                </div>
            </form>
        </div>
    </div>
</section>
