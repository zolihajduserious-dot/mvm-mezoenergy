<?php
declare(strict_types=1);

if (is_logged_in() && !is_customer_user()) {
    redirect(work_request_create_path_for_user());
}

require_role(['customer']);

$customer = current_customer();

if ($customer === null) {
    set_flash('error', 'Előbb töltsd ki az ügyféladataidat.');
    redirect('/customer/profile');
}

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$request = $requestId ? find_connection_request($requestId) : null;

if ($requestId && ($request === null || (int) $request['customer_id'] !== (int) $customer['id'])) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

if ($request !== null && !connection_request_is_editable($request)) {
    set_flash('info', 'Ez az igény már le van zárva, ezért nem módosítható.');
    redirect('/customer/work-requests');
}

$errors = [];
$uploadMessages = [];
$flash = get_flash();
$customerForm = normalize_customer_data($customer);
$form = normalize_connection_request_data($request ?? [], $customer);
$existingFiles = $request !== null ? connection_request_files((int) $request['id']) : [];
$downloads = download_documents(true);
$requestTypeOptions = connection_request_type_options();
$requestAlreadyFinalized = $request !== null && (string) ($request['request_status'] ?? '') === 'finalized';

if (is_post()) {
    require_valid_csrf_token();

    $deleteFileId = filter_input(INPUT_POST, 'delete_request_file_id', FILTER_VALIDATE_INT);

    if ($request !== null && $deleteFileId) {
        $result = delete_connection_request_file((int) $deleteFileId, (int) $request['id']);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A fájl törlése sikertelen.'));
        redirect('/customer/work-request?id=' . (int) $request['id']);
    }

    $action = (string) ($_POST['action'] ?? 'save');
    $finalize = $action === 'finalize' && !$requestAlreadyFinalized;
    $customerForm = normalize_customer_data(array_merge($customer, $_POST));
    $customerForm['source'] = $customerForm['source'] !== '' ? $customerForm['source'] : (string) ($customer['source'] ?? 'Ügyfélportál');
    $customerForm['status'] = $customerForm['status'] !== '' ? $customerForm['status'] : (string) ($customer['status'] ?? 'Mérőhelyi igény');
    $customerForm['contact_data_accepted'] = (int) ($customer['contact_data_accepted'] ?? 0);
    $customerForm['notes'] = (string) ($customer['notes'] ?? '');
    $form = normalize_connection_request_data($_POST, $customerForm);
    $errors = array_merge(
        validate_customer_data($customerForm, false),
        validate_connection_request_data($form, $_FILES, $finalize, $requestId ?: null)
    );

    if ($finalize) {
        foreach ([
            'birth_name' => 'Születési név',
            'mother_name' => 'Anyja neve',
            'birth_place' => 'Születési hely',
            'birth_date' => 'Születési idő',
        ] as $key => $label) {
            if (trim((string) ($customerForm[$key] ?? '')) === '') {
                $errors[] = $label . ' hiányzik az ügyféladatlapról. Előbb pótold az Adataim oldalon.';
            }
        }
    }

    if ($errors === []) {
        try {
            $wasNewRequest = !$requestId;
            update_customer((int) $customer['id'], $customerForm);
            $customer = find_customer((int) $customer['id']) ?: $customer;
            $savedRequestId = save_connection_request((int) $customer['id'], $form, $requestId ?: null);
            $requestId = $savedRequestId;
            $uploadMessages = handle_connection_request_uploads($savedRequestId, $_FILES, !$finalize);

            if ($uploadMessages === []) {
                if ($finalize) {
                    $result = finalize_connection_request($savedRequestId);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                    redirect('/customer/work-requests');
                } else {
                    send_admin_activity_notification(
                        $wasNewRequest ? 'Ügyfél új igény piszkozatot mentett' : 'Ügyfél igény piszkozatot módosított',
                        $wasNewRequest
                            ? 'Egy ügyfél új mérőhelyi igényt mentett piszkozatként.'
                            : 'Egy ügyfél módosított egy mérőhelyi igény piszkozatot.',
                        [
                            [
                                'title' => 'Igény adatai',
                                'rows' => [
                                    ['label' => 'Igény', 'value' => $form['project_name'] ?? '-'],
                                    ['label' => 'Igénytípus', 'value' => connection_request_type_label($form['request_type'] ?? null)],
                                    ['label' => 'Ügyfél', 'value' => ($customer['requester_name'] ?? '-') . "\n" . ($customer['email'] ?? '-') . "\n" . ($customer['phone'] ?? '-')],
                                    ['label' => 'Cím', 'value' => trim((string) ($form['site_postal_code'] ?? '') . ' ' . (string) ($form['site_address'] ?? ''))],
                                ],
                            ],
                        ],
                        [
                            ['label' => 'Munka megnyitása', 'url' => absolute_url('/admin/minicrm-import?request=' . $savedRequestId . '#portal-work-' . $savedRequestId)],
                        ],
                        ['email' => $customer['email'] ?? '', 'name' => $customer['requester_name'] ?? ''],
                        null,
                        'Ügyfél igény mentés'
                    );
                    set_flash('success', $requestAlreadyFinalized ? 'A módosításokat mentettük.' : 'Az igényt mentettük. Később folytathatod, amíg le nem zárod.');
                    redirect('/customer/work-request?id=' . $savedRequestId);
                }
            }

            $request = find_connection_request($savedRequestId);
            $existingFiles = connection_request_files($savedRequestId);
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az igény mentése sikertelen.';
        }
    }
}
?>
<section class="admin-section customer-crm-page customer-work-request-crm-page">
    <div class="container">
        <div class="admin-header customer-crm-hero">
            <div>
                <p class="eyebrow">Ügyfélportál</p>
                <h1><?= $requestId ? 'Igény módosítása' : 'Új igény rögzítése'; ?></h1>
                <p>Az igényt addig módosíthatod, amíg az MVM ügyintézés Folyamatban státuszba nem kerül. A beküldött módosításokról értesítjük az admint.</p>
            </div>
            <div class="form-actions">
                <a class="button button-secondary" href="<?= h(url_path('/customer/work-requests')); ?>">Igényeim</a>
                <a class="button button-secondary" href="<?= h(url_path('/customer/profile')); ?>">Adataim</a>
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

        <?php if ($downloads !== []): ?>
            <section class="download-panel">
                <div>
                    <h2>Letölthető dokumentumok</h2>
                    <p>Töltsd le, töltsd ki, majd ugyanitt töltsd fel a kész dokumentumokat. A meghatalmazást elég az ár elfogadása után pótolni, és online is aláírható.</p>
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
                    <p>Telefonon ujjal, számítógépen egérrel aláírható. Az ügyfél és a két tanú aláírása után a rendszer automatikusan az igényhez menti a meghatalmazást.</p>
                </div>
                <a class="button" href="<?= h(authorization_signature_url($request)); ?>" target="_blank">Online aláírás megnyitása</a>
            </section>
        <?php endif; ?>

        <form class="form" method="post" enctype="multipart/form-data" action="<?= h($requestId ? url_path('/customer/work-request') . '?id=' . $requestId : url_path('/customer/work-request')); ?>">
            <?= csrf_field(); ?>

            <div class="form-grid two">
                <section class="auth-panel">
                    <h2>Saját adatok</h2>
                    <input type="hidden" name="is_legal_entity" value="0">
                    <label class="checkbox-row"><input type="checkbox" name="is_legal_entity" value="1" <?= (int) $customerForm['is_legal_entity'] === 1 ? 'checked' : ''; ?>><span>Jogi személy</span></label>
                    <label>Név</label><input name="requester_name" value="<?= h($customerForm['requester_name']); ?>" required>
                    <label>Cégnév</label><input name="company_name" value="<?= h($customerForm['company_name']); ?>">
                    <label>Adószám</label><input name="tax_number" value="<?= h($customerForm['tax_number']); ?>">
                    <label>Telefon</label><input name="phone" value="<?= h($customerForm['phone']); ?>" required>
                    <label>Email</label><input name="email" type="email" value="<?= h($customerForm['email']); ?>" required>
                    <label>Postai cím</label><input name="postal_address" value="<?= h($customerForm['postal_address']); ?>" required>
                    <label>Irányítószám</label><input name="postal_code" value="<?= h($customerForm['postal_code']); ?>" required>
                    <label>Település</label><input name="city" value="<?= h($customerForm['city']); ?>" required>
                    <label>Levelezési cím</label><input name="mailing_address" value="<?= h($customerForm['mailing_address']); ?>">
                    <label>Születési név</label><input name="birth_name" value="<?= h($customerForm['birth_name']); ?>">
                    <label>Anyja neve</label><input name="mother_name" value="<?= h($customerForm['mother_name']); ?>">
                    <label>Születési hely</label><input name="birth_place" value="<?= h($customerForm['birth_place']); ?>">
                    <label>Születési idő</label><input name="birth_date" type="date" value="<?= h($customerForm['birth_date']); ?>">
                    <div class="status-list" hidden>
                        <li><span class="status-label">Név</span><span class="status-value"><?= h($customer['requester_name']); ?></span></li>
                        <li><span class="status-label">Születési név</span><span class="status-value"><?= h($customer['birth_name'] ?? '-'); ?></span></li>
                        <li><span class="status-label">Telefon</span><span class="status-value"><?= h($customer['phone']); ?></span></li>
                        <li><span class="status-label">Email</span><span class="status-value"><?= h($customer['email']); ?></span></li>
                        <li><span class="status-label">Postacím</span><span class="status-value"><?= h($customer['postal_code'] . ' ' . $customer['city'] . ', ' . $customer['postal_address']); ?></span></li>
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
                <p class="muted-text">Több részletben is feltöltheted a fájlokat. A meghatalmazás az ajánlatkéréshez nem kötelező, később is pótolható.</p>

                <?php if ($existingFiles !== []): ?>
                    <div class="portal-card-files existing-file-panel">
                        <h3>Már feltöltött fájlok</h3>
                        <div class="inline-link-list">
                            <?php foreach ($existingFiles as $file): ?>
                                <span class="file-link-row">
                                    <a href="<?= h(url_path('/customer/work-requests/file') . '?id=' . (int) $file['id']); ?>" target="_blank"><?= h($file['label']); ?>: <?= h($file['original_name']); ?></a>
                                    <button class="table-action-button table-action-danger" name="delete_request_file_id" value="<?= (int) $file['id']; ?>" type="submit" formnovalidate onclick="return confirm('Biztosan törlöd ezt a fájlt?');">Törlés</button>
                                </span>
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
                            <span><?= h($definition['label']); ?><?= ($definition['required'] || $isHTariffRequired) ? ' *' : ''; ?></span>
                            <small><?= $definition['required'] ? 'Lezáráskor mindig kötelező. Több fájl is feltölthető.' : ($isHTariffRequired ? 'H tarifa esetén lezáráskor kötelező.' : 'Opcionális. Több fájl is feltölthető.'); ?></small>
                            <input name="file_<?= h($key); ?>[]" type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?> <?= $isHTariffRequired ? 'data-h-tariff-required="1" data-has-existing="' . ($hasExistingFile ? '1' : '0') . '"' : ''; ?>>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="form-actions">
                <?php if ($requestAlreadyFinalized): ?>
                    <button class="button" name="action" value="save" type="submit" formnovalidate>Módosítás mentése</button>
                <?php else: ?>
                    <button class="button button-secondary" name="action" value="save" type="submit" formnovalidate>Mentés piszkozatként</button>
                    <button class="button" name="action" value="finalize" type="submit">Lezárom és beküldöm</button>
                <?php endif; ?>
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
