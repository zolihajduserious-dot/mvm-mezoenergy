<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$customerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
$request = $requestId ? find_connection_request($requestId) : null;

if ($requestId && $request === null) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

if ($request !== null) {
    $customerId = (int) $request['customer_id'];
}

$customer = $customerId ? find_customer($customerId) : null;

if ($customer === null) {
    set_flash('error', 'Az ügyfél nem található.');
    redirect('/admin/customers');
}

$errors = [];
$uploadMessages = [];
$flash = get_flash();
$form = normalize_connection_request_data($request ?? [], $customer);
$existingFiles = $request !== null ? connection_request_files((int) $request['id']) : [];
$downloads = download_documents(true);
$requestTypeOptions = connection_request_type_options();
$actionUrl = $requestId
    ? url_path('/admin/connection-requests/edit') . '?id=' . (int) $requestId
    : url_path('/admin/connection-requests/edit') . '?customer_id=' . (int) $customer['id'];

if (is_post()) {
    require_valid_csrf_token();

    $form = normalize_connection_request_data($_POST, $customer);
    $errors = validate_connection_request_data($form, $_FILES, false, $requestId ?: null);

    if ($errors === []) {
        try {
            $savedRequestId = save_connection_request((int) $customer['id'], $form, $requestId ?: null, null, true);
            $requestId = $savedRequestId;
            $uploadMessages = handle_connection_request_uploads($savedRequestId, $_FILES, false);

            if ($uploadMessages === []) {
                set_flash('success', 'Az igényt mentettük.');
                redirect('/admin/connection-requests/edit?id=' . $savedRequestId);
            }

            $request = find_connection_request($savedRequestId);
            $existingFiles = connection_request_files($savedRequestId);
            $actionUrl = url_path('/admin/connection-requests/edit') . '?id=' . $savedRequestId;
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az igény mentése sikertelen.';
        }
    }
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1><?= $requestId ? 'Igény szerkesztése' : 'Új igény rögzítése'; ?></h1>
                <p><?= h((string) $customer['requester_name']); ?> · <?= h((string) $customer['email']); ?></p>
            </div>
            <div class="form-actions">
                <a class="button button-secondary" href="<?= h(url_path('/admin/customers')); ?>">Ügyfelek</a>
                <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests')); ?>">Igénylista</a>
                <a class="button button-secondary" href="<?= h(url_path('/admin/customers/edit') . '?id=' . (int) $customer['id']); ?>">Ügyfél szerkesztése</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($uploadMessages as $message): ?>
            <div class="alert alert-error"><p><?= h($message); ?></p></div>
        <?php endforeach; ?>

        <?php if ($request !== null): ?>
            <section class="download-panel">
                <div>
                    <h2>Igény állapota</h2>
                    <p><?= h(connection_request_status_label((string) ($request['request_status'] ?? 'draft'))); ?><?= !empty($request['created_at']) ? ' · Létrehozva: ' . h((string) $request['created_at']) : ''; ?></p>
                </div>
                <div class="inline-link-list">
                    <a href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . (int) $customer['id'] . '&request_id=' . (int) $request['id']); ?>">Árajánlat készítése</a>
                    <a href="<?= h(url_path('/admin/connection-requests/mvm-documents') . '?id=' . (int) $request['id']); ?>">MVM dokumentumok</a>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($downloads !== []): ?>
            <section class="download-panel">
                <div>
                    <h2>Letölthető dokumentumok</h2>
                    <p>Az admin felületről is elérhetők az ügyfélnek szánt dokumentumok.</p>
                </div>
                <div class="inline-link-list">
                    <?php foreach ($downloads as $document): ?>
                        <a href="<?= h(url_path('/documents/file') . '?id=' . (int) $document['id']); ?>" target="_blank"><?= h($document['title']); ?></a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($request !== null): ?>
            <section class="download-panel">
                <div>
                    <h2>Meghatalmazás online aláírása</h2>
                    <p>Az aláíró link adminból is megnyitható, ha segíteni kell az ügyfélnek.</p>
                </div>
                <a class="button" href="<?= h(authorization_signature_url($request)); ?>" target="_blank">Online aláírás megnyitása</a>
            </section>
        <?php endif; ?>

        <form class="form" method="post" enctype="multipart/form-data" action="<?= h($actionUrl); ?>">
            <?= csrf_field(); ?>

            <div class="form-grid two">
                <section class="auth-panel">
                    <h2>Ügyfél adatai</h2>
                    <div class="status-list">
                        <li><span class="status-label">Név</span><span class="status-value"><?= h((string) $customer['requester_name']); ?></span></li>
                        <li><span class="status-label">Születési név</span><span class="status-value"><?= h((string) ($customer['birth_name'] ?? '-')); ?></span></li>
                        <li><span class="status-label">Telefon</span><span class="status-value"><?= h((string) $customer['phone']); ?></span></li>
                        <li><span class="status-label">Email</span><span class="status-value"><?= h((string) $customer['email']); ?></span></li>
                        <li><span class="status-label">Postacím</span><span class="status-value"><?= h((string) $customer['postal_code'] . ' ' . (string) $customer['city'] . ', ' . (string) $customer['postal_address']); ?></span></li>
                    </div>
                </section>

                <section class="auth-panel">
                    <h2>Igény adatai</h2>
                    <label for="request_type">Igénytípus</label>
                    <select id="request_type" name="request_type" data-request-type-select required>
                        <?php foreach ($requestTypeOptions as $typeKey => $typeLabel): ?>
                            <option value="<?= h($typeKey); ?>" <?= $form['request_type'] === $typeKey ? 'selected' : ''; ?>><?= h($typeLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Igény megnevezése</label><input name="project_name" value="<?= h($form['project_name']); ?>" placeholder="Példa: Szeged, Petőfi utca 12. - mérőhely szabványosítás">
                    <label>Kivitelezés címe</label><input name="site_address" value="<?= h($form['site_address']); ?>">
                    <label>Kivitelezés irányítószáma</label><input name="site_postal_code" value="<?= h($form['site_postal_code']); ?>">
                    <label>Helyrajzi szám</label><input name="hrsz" value="<?= h($form['hrsz']); ?>">
                    <label>Saját mérő gyári száma</label><input name="meter_serial" value="<?= h($form['meter_serial']); ?>">
                    <label>Fogyasztási hely azonosító</label><input name="consumption_place_id" value="<?= h($form['consumption_place_id']); ?>">
                </section>
            </div>

            <section class="auth-panel form-block">
                <h2>Teljesítmény adatok</h2>
                <div class="form-grid two compact">
                    <div><label>Meglévő teljesítmény mindennapszaki</label><input name="existing_general_power" value="<?= h($form['existing_general_power']); ?>"></div>
                    <div><label>Igényelt teljesítmény mindennapszaki</label><input name="requested_general_power" value="<?= h($form['requested_general_power']); ?>"></div>
                    <div><label>Meglévő teljesítmény H tarifa</label><input name="existing_h_tariff_power" value="<?= h($form['existing_h_tariff_power']); ?>"></div>
                    <div><label>Igényelt teljesítmény H tarifa</label><input name="requested_h_tariff_power" value="<?= h($form['requested_h_tariff_power']); ?>"></div>
                    <div><label>Meglévő teljesítmény vezérelt</label><input name="existing_controlled_power" value="<?= h($form['existing_controlled_power']); ?>"></div>
                    <div><label>Igényelt teljesítmény vezérelt</label><input name="requested_controlled_power" value="<?= h($form['requested_controlled_power']); ?>"></div>
                </div>
                <label>Megjegyzés</label><textarea name="notes" rows="4"><?= h($form['notes']); ?></textarea>
            </section>

            <section class="auth-panel form-block">
                <h2>Fotók és kitöltött dokumentumok</h2>
                <p class="muted-text">Adminból is lehet új fájlokat feltölteni az igényhez. A korábbi fájlok megmaradnak.</p>

                <?php if ($existingFiles !== []): ?>
                    <div class="portal-card-files existing-file-panel">
                        <h3>Már feltöltött fájlok</h3>
                        <div class="inline-link-list">
                            <?php foreach ($existingFiles as $file): ?>
                                <a href="<?= h(url_path('/admin/connection-requests/file') . '?id=' . (int) $file['id']); ?>" target="_blank"><?= h((string) $file['label']); ?>: <?= h((string) $file['original_name']); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="file-upload-grid">
                    <?php foreach (connection_request_upload_definitions() as $key => $definition): ?>
                        <?php
                        $isImage = $definition['kind'] === 'image';
                        $accept = $isImage ? 'image/jpeg,image/png,image/webp' : '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp';
                        $hasExistingFile = connection_request_has_file_type($requestId ?: null, (string) $key);
                        $isHTariffRequired = !empty($definition['h_tariff_required']);
                        ?>
                        <label class="file-upload-item" <?= $isHTariffRequired ? 'data-h-tariff-upload="1"' : ''; ?>>
                            <span><?= h((string) $definition['label']); ?><?= ($definition['required'] || $isHTariffRequired) ? ' *' : ''; ?></span>
                            <small><?= $definition['required'] ? 'Lezáráskor mindig kötelező. Több fájl is feltölthető.' : ($isHTariffRequired ? 'H tarifa esetén lezáráskor kötelező.' : 'Opcionális. Több fájl is feltölthető.'); ?></small>
                            <input name="file_<?= h((string) $key); ?>[]" type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?> <?= $isHTariffRequired ? 'data-h-tariff-required="1" data-has-existing="' . ($hasExistingFile ? '1' : '0') . '"' : ''; ?>>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="form-actions">
                <button class="button" name="action" value="save" type="submit" formnovalidate>Igény mentése</button>
                <a class="button button-secondary" href="<?= h(url_path('/admin/customers')); ?>">Vissza az ügyfelekhez</a>
            </div>
        </form>
    </div>
</section>
<script>
(() => {
    const select = document.querySelector('[data-request-type-select]');
    const tariffItems = document.querySelectorAll('[data-h-tariff-upload]');
    const tariffInputs = document.querySelectorAll('[data-h-tariff-required]');

    if (!select) {
        return;
    }

    const syncHTariffFields = () => {
        const isHTariff = select.value === 'h_tariff';

        tariffItems.forEach((item) => {
            item.hidden = !isHTariff;
        });

        tariffInputs.forEach((input) => {
            input.required = isHTariff && input.dataset.hasExisting !== '1';
        });
    };

    select.addEventListener('change', syncHTariffFields);
    syncHTariffFields();
})();
</script>
