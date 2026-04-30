<?php
declare(strict_types=1);

require_role(['general_contractor']);

$user = current_user();
$contractor = current_contractor();

if (!is_array($user) || $contractor === null) {
    set_flash('error', 'A generálkivitelező adatok nem találhatók.');
    redirect('/login');
}

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$request = $requestId ? find_connection_request($requestId) : null;

if ($requestId && ($request === null || !contractor_can_manage_connection_request($request))) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

$managedCustomer = $request !== null ? find_customer((int) $request['customer_id']) : null;

if ($request !== null && $managedCustomer === null) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

if ($request !== null && !connection_request_is_editable($request)) {
    set_flash('info', 'Ez az igény már le van zárva, ezért nem módosítható.');
    redirect('/contractor/work-requests');
}

$isEdit = $request !== null;
$errors = [];
$uploadMessages = [];
$flash = get_flash();
$customerSeed = $managedCustomer ?? [
    'source' => 'Generálkivitelező',
    'status' => 'Mérőhelyi igény',
];
$customerForm = normalize_customer_data($customerSeed);
$workForm = normalize_connection_request_data($request ?? []);
$existingFiles = $isEdit ? connection_request_files((int) $request['id']) : [];
$downloads = download_documents(true);
$requestTypeOptions = connection_request_type_options();

if (is_post()) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? 'save');
    $finalize = $action === 'finalize';
    $customerForm = normalize_customer_data($_POST);
    $customerForm['source'] = $customerForm['source'] !== '' ? $customerForm['source'] : 'Generálkivitelező';
    $customerForm['status'] = $customerForm['status'] !== '' ? $customerForm['status'] : 'Mérőhelyi igény';
    $workForm = normalize_connection_request_data($_POST);

    $errors = array_merge(
        validate_customer_data($customerForm, $finalize),
        validate_connection_request_data($workForm, $_FILES, $finalize, $requestId ?: null)
    );

    if ($errors === []) {
        try {
            $wasNewRequest = !$isEdit;
            if ($isEdit) {
                update_customer((int) $request['customer_id'], $customerForm);
                $savedRequestId = save_connection_request((int) $request['customer_id'], $workForm, (int) $request['id'], (int) $user['id']);
            } else {
                $customerId = create_customer($customerForm, null, (int) $user['id']);
                $savedRequestId = save_connection_request($customerId, $workForm, null, (int) $user['id']);
                $requestId = $savedRequestId;
                $isEdit = true;
            }

            $uploadMessages = handle_connection_request_uploads($savedRequestId, $_FILES, !$finalize);

            if ($uploadMessages === []) {
                if ($finalize) {
                    $result = finalize_connection_request($savedRequestId);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                    redirect('/contractor/work-requests');
                } else {
                    send_admin_activity_notification(
                        $wasNewRequest ? 'Generálkivitelező új igényt mentett' : 'Generálkivitelező igényt módosított',
                        $wasNewRequest
                            ? 'Egy generálkivitelező új ügyfélhez tartozó mérőhelyi igényt mentett piszkozatként.'
                            : 'Egy generálkivitelező módosított egy mérőhelyi igény piszkozatot.',
                        [
                            [
                                'title' => 'Igény adatai',
                                'rows' => [
                                    ['label' => 'Igény', 'value' => $workForm['project_name'] ?? '-'],
                                    ['label' => 'Igénytípus', 'value' => connection_request_type_label($workForm['request_type'] ?? null)],
                                    ['label' => 'Végügyfél', 'value' => ($customerForm['requester_name'] ?? '-') . "\n" . ($customerForm['email'] ?? '-') . "\n" . ($customerForm['phone'] ?? '-')],
                                    ['label' => 'Cím', 'value' => trim((string) ($workForm['site_postal_code'] ?? '') . ' ' . (string) ($workForm['site_address'] ?? ''))],
                                ],
                            ],
                        ],
                        [
                            ['label' => 'Munka megnyitása', 'url' => absolute_url('/admin/minicrm-import?request=' . $savedRequestId . '#portal-work-' . $savedRequestId)],
                        ],
                        ['email' => $contractor['email'] ?? '', 'name' => $contractor['contact_name'] ?? $contractor['contractor_name'] ?? ''],
                        null,
                        'Generálkivitelező igény mentés'
                    );
                    set_flash('success', 'Az igényt piszkozatként mentettük. Később folytatható, amíg le nem zárod.');
                    redirect('/contractor/work-request?id=' . $savedRequestId);
                }
            }

            $request = $savedRequestId ? find_connection_request($savedRequestId) : $request;
            $existingFiles = $savedRequestId ? connection_request_files($savedRequestId) : $existingFiles;
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'A munka mentése sikertelen.';
        }
    }
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Generálkivitelező portál</p>
                <h1><?= $isEdit ? 'Igény módosítása' : 'Új ügyfél + igény'; ?></h1>
                <p>Az igényt piszkozatként mentheted, és később folytathatod. Lezárás után végleges igénybejelentésként értesítjük az admint, és már nem módosítható.</p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path('/contractor/work-requests')); ?>">Igények</a>
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
                    <p>Töltsd le, töltsd ki, majd ehhez az igényhez töltsd fel a kész dokumentumokat. A meghatalmazást elég az ár elfogadása után pótolni, és online is aláírható.</p>
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
                    <p>Telefonon ujjal, számítógépen egérrel aláírható. A link megnyitható az ügyfél telefonján is, a kész PDF automatikusan az igényhez kerül.</p>
                </div>
                <a class="button" href="<?= h(authorization_signature_url($request)); ?>" target="_blank">Online aláírás megnyitása</a>
            </section>
        <?php endif; ?>

        <form class="form" method="post" enctype="multipart/form-data" action="<?= h($requestId ? url_path('/contractor/work-request') . '?id=' . $requestId : url_path('/contractor/work-request')); ?>">
            <?= csrf_field(); ?>

            <div class="form-grid two">
                <section class="auth-panel">
                    <h2>Generálkivitelező adatok</h2>
                    <div class="status-list">
                        <li><span class="status-label">Név / cég</span><span class="status-value"><?= h($contractor['contractor_name']); ?></span></li>
                        <li><span class="status-label">Kapcsolattartó</span><span class="status-value"><?= h($contractor['contact_name']); ?></span></li>
                        <li><span class="status-label">Telefon</span><span class="status-value"><?= h($contractor['phone']); ?></span></li>
                        <li><span class="status-label">Email</span><span class="status-value"><?= h($contractor['email']); ?></span></li>
                    </div>
                </section>

                <section class="auth-panel">
                    <h2>Végügyfél adatok</h2>
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_legal_entity" value="1" <?= (int) $customerForm['is_legal_entity'] === 1 ? 'checked' : ''; ?>>
                        <span>Jogi személyként jár el</span>
                    </label>

                    <label for="requester_name">Ajánlatkérő neve</label>
                    <input id="requester_name" name="requester_name" value="<?= h($customerForm['requester_name']); ?>" required>

                    <label for="birth_name">Születési név</label>
                    <input id="birth_name" name="birth_name" value="<?= h($customerForm['birth_name']); ?>" required>

                    <label for="company_name">Cégnév</label>
                    <input id="company_name" name="company_name" value="<?= h($customerForm['company_name']); ?>">

                    <label for="tax_number">Adószám</label>
                    <input id="tax_number" name="tax_number" value="<?= h($customerForm['tax_number']); ?>">

                    <label for="phone">Telefonszám</label>
                    <input id="phone" name="phone" value="<?= h($customerForm['phone']); ?>" required>

                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="<?= h($customerForm['email']); ?>" required>

                    <label for="postal_address">Postai cím</label>
                    <input id="postal_address" name="postal_address" value="<?= h($customerForm['postal_address']); ?>" required>

                    <label for="postal_code">Irányítószám</label>
                    <input id="postal_code" name="postal_code" value="<?= h($customerForm['postal_code']); ?>" required>

                    <label for="city">Település</label>
                    <input id="city" name="city" value="<?= h($customerForm['city']); ?>" required>

                    <label for="mother_name">Anyja neve</label>
                    <input id="mother_name" name="mother_name" value="<?= h($customerForm['mother_name']); ?>" required>

                    <label for="birth_place">Születési hely</label>
                    <input id="birth_place" name="birth_place" value="<?= h($customerForm['birth_place']); ?>" required>

                    <label for="birth_date">Születési idő</label>
                    <input id="birth_date" name="birth_date" type="date" value="<?= h($customerForm['birth_date']); ?>" required>

                    <label class="checkbox-row">
                        <input type="checkbox" name="contact_data_accepted" value="1" <?= (int) $customerForm['contact_data_accepted'] === 1 ? 'checked' : ''; ?> required>
                        <span>A végügyfél adatait jogosan rögzítjük és továbbítjuk ügyintézéshez</span>
                    </label>
                </section>
            </div>

            <section class="auth-panel form-block">
                <h2>Igény adatai</h2>
                <div class="form-grid two compact">
                    <div>
                        <label for="request_type">Igénytípus</label>
                        <select id="request_type" name="request_type" data-request-type-select required>
                            <?php foreach ($requestTypeOptions as $typeKey => $typeLabel): ?>
                                <option value="<?= h($typeKey); ?>" <?= $workForm['request_type'] === $typeKey ? 'selected' : ''; ?>><?= h($typeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Igény megnevezése</label><input name="project_name" value="<?= h($workForm['project_name']); ?>" placeholder="Példa: Kovács Béla - Szeged, Petőfi utca 12."></div>
                    <div><label>Kivitelezés irányítószáma</label><input name="site_postal_code" value="<?= h($workForm['site_postal_code']); ?>" required></div>
                    <div><label>Kivitelezés címe</label><input name="site_address" value="<?= h($workForm['site_address']); ?>" required></div>
                    <div><label>Helyrajzi szám</label><input name="hrsz" value="<?= h($workForm['hrsz']); ?>"></div>
                    <div><label>Saját mérő gyári száma</label><input name="meter_serial" value="<?= h($workForm['meter_serial']); ?>"></div>
                    <div><label>Fogyasztási hely azonosító</label><input name="consumption_place_id" value="<?= h($workForm['consumption_place_id']); ?>"></div>
                </div>
            </section>

            <section class="auth-panel form-block">
                <h2>Teljesítmény adatok</h2>
                <div class="form-grid two compact">
                    <div><label>Meglévő teljesítmény mindennapszaki</label><input name="existing_general_power" value="<?= h($workForm['existing_general_power']); ?>" required></div>
                    <div><label>Igényelt teljesítmény mindennapszaki</label><input name="requested_general_power" value="<?= h($workForm['requested_general_power']); ?>"></div>
                    <div><label>Meglévő teljesítmény H tarifa</label><input name="existing_h_tariff_power" value="<?= h($workForm['existing_h_tariff_power']); ?>"></div>
                    <div><label>Igényelt teljesítmény H tarifa</label><input name="requested_h_tariff_power" value="<?= h($workForm['requested_h_tariff_power']); ?>"></div>
                    <div><label>Meglévő teljesítmény vezérelt</label><input name="existing_controlled_power" value="<?= h($workForm['existing_controlled_power']); ?>"></div>
                    <div><label>Igényelt teljesítmény vezérelt</label><input name="requested_controlled_power" value="<?= h($workForm['requested_controlled_power']); ?>"></div>
                </div>
                <label>Megjegyzés</label>
                <textarea name="notes" rows="4"><?= h($workForm['notes']); ?></textarea>
            </section>

            <section class="auth-panel form-block">
                <h2>Fotók és dokumentumok</h2>
                <p class="muted-text">Több részletben is feltöltheted a fájlokat. A meghatalmazás az ajánlatkéréshez nem kötelező, később is pótolható.</p>

                <?php if ($existingFiles !== []): ?>
                    <div class="portal-card-files existing-file-panel">
                        <h3>Már feltöltött fájlok</h3>
                        <div class="inline-link-list">
                            <?php foreach ($existingFiles as $file): ?>
                                <a href="<?= h(url_path('/contractor/work-requests/file') . '?id=' . (int) $file['id']); ?>" target="_blank"><?= h($file['label']); ?>: <?= h($file['original_name']); ?></a>
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
                        $isRequired = !empty($definition['required']) && !$hasExistingFile;
                        ?>
                        <label class="file-upload-item" <?= $isHTariffRequired ? 'data-h-tariff-upload="1"' : ''; ?>>
                            <span><?= h($definition['label']); ?><?= ($definition['required'] || $isHTariffRequired) ? ' *' : ''; ?></span>
                            <small><?= $definition['required'] ? ($isRequired ? 'Kötelező. Több fájl is feltölthető.' : 'Már feltöltve. Több fájl is feltölthető.') : ($isHTariffRequired ? 'H tarifa esetén kötelező.' : 'Opcionális vagy már feltöltve. Több fájl is feltölthető.'); ?></small>
                            <input name="file_<?= h($key); ?>[]" type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?> <?= $isRequired ? 'required' : ''; ?> <?= $isHTariffRequired ? 'data-h-tariff-required="1" data-has-existing="' . ($hasExistingFile ? '1' : '0') . '"' : ''; ?>>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="form-actions">
                <button class="button button-secondary" name="action" value="save" type="submit" formnovalidate>Mentés piszkozatként</button>
                <button class="button" name="action" value="finalize" type="submit">Lezárom és beküldöm</button>
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
