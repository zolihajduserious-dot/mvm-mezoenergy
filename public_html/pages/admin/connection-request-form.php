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

if ($customerId && $customer === null) {
    set_flash('error', 'Az ügyfél nem található.');
    redirect('/admin/customers');
}

$errors = [];
$uploadMessages = [];
$flash = get_flash();
$customerForm = $customer !== null
    ? normalize_customer_data($customer)
    : normalize_customer_data([
        'source' => 'Admin rögzítés',
        'status' => 'Mérőhelyi igény',
        'contact_data_accepted' => 1,
    ]);
$form = normalize_connection_request_data($request ?? [], $customer ?? $customerForm);
$existingFiles = $request !== null ? connection_request_files((int) $request['id']) : [];
$downloads = download_documents(true);
$requestTypeOptions = connection_request_type_options();
$documentPrefillToken = document_prefill_token((string) ($_POST['document_prefill_token'] ?? ''));
$documentPrefillResult = null;
$requestQuotes = [];
$requestAcceptedQuote = null;
$requestDocuments = [];
$requestWorkflowStage = null;
$initialDataEditable = true;

if ($request !== null) {
    $requestQuotes = quotes_for_connection_request((int) $request['id']);
    $requestAcceptedQuote = accepted_quote_for_connection_request((int) $request['id'])
        ?? accepted_quote_for_registration_duplicate_request((int) $request['id']);
    $requestDocuments = connection_request_documents((int) $request['id']);
    $requestWorkflowStage = connection_request_admin_workflow_stage($request, $requestQuotes[0] ?? null, $requestAcceptedQuote, $requestDocuments);
    $initialDataEditable = connection_request_initial_data_is_editable($request, $requestQuotes[0] ?? null, $requestAcceptedQuote, $requestDocuments);
}

$actionUrl = $requestId
    ? url_path('/admin/connection-requests/edit') . '?id=' . (int) $requestId
    : ($customer !== null
        ? url_path('/admin/connection-requests/edit') . '?customer_id=' . (int) $customer['id']
        : url_path('/admin/connection-requests/edit'));

if (is_post()) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? '');
    $deleteFileId = filter_input(INPUT_POST, 'delete_request_file_id', FILTER_VALIDATE_INT);

    if ($request !== null && $deleteFileId) {
        $result = delete_connection_request_file((int) $deleteFileId, (int) $request['id']);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A fájl törlése sikertelen.'));
        redirect('/admin/connection-requests/edit?id=' . (int) $request['id']);
    }

    if ($request !== null && $action === 'save_mvm_uk_number') {
        $result = update_connection_request_mvm_uk_number((int) $request['id'], (string) ($_POST['mvm_uk_number'] ?? ''));
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az ÜK szám mentése sikertelen.'));
        redirect('/admin/connection-requests/edit?id=' . (int) $request['id']);
    }

    $skipSave = false;

    if ($action === 'extract_document_prefill') {
        $isNewCustomerRequest = $customer === null;
        $shouldSaveCustomerData = $isNewCustomerRequest || $initialDataEditable;
        $customerForm = $shouldSaveCustomerData ? normalize_customer_data(array_merge($customer ?? [], $_POST)) : $customerForm;

        if ($shouldSaveCustomerData && $customer !== null) {
            $customerForm['notes'] = (string) ($customer['notes'] ?? '');
        }

        if ($shouldSaveCustomerData) {
            $customerForm['source'] = $customerForm['source'] !== '' ? $customerForm['source'] : 'Admin rögzítés';
            $customerForm['status'] = $customerForm['status'] !== '' ? $customerForm['status'] : 'Mérőhelyi igény';
            $customerForm['contact_data_accepted'] = (int) $customerForm['contact_data_accepted'] === 1 ? 1 : ($isNewCustomerRequest ? 1 : 0);
        }

        $customerDefaultsForRequest = $shouldSaveCustomerData ? $customerForm : ($customer ?? $customerForm);
        $form = normalize_connection_request_data($_POST, $customerDefaultsForRequest);

        if ($request !== null && !$initialDataEditable) {
            $documentPrefillResult = [
                'ok' => false,
                'message' => 'Az adatlap Folyamatban vagy későbbi státuszban van, ezért az alapadatok már nem módosíthatók ezen az oldalon.',
                'data' => [],
            ];
        } else {
            $documentPrefillResult = handle_connection_request_document_prefill($documentPrefillToken, $_FILES, $customerForm, $form);
            $customerForm = (array) ($documentPrefillResult['customer_form'] ?? $customerForm);
            $form = (array) ($documentPrefillResult['request_form'] ?? $form);
        }

        $skipSave = true;
    }

    if (!$skipSave) {
    $user = current_user();
    $submittedByUserId = is_array($user) ? (int) $user['id'] : null;
    $isNewCustomerRequest = $customer === null;
    $shouldSaveCustomerData = $isNewCustomerRequest || $initialDataEditable;
    $customerForm = $shouldSaveCustomerData ? normalize_customer_data(array_merge($customer ?? [], $_POST)) : $customerForm;
    if ($shouldSaveCustomerData && $customer !== null) {
        $customerForm['notes'] = (string) ($customer['notes'] ?? '');
    }
    if ($shouldSaveCustomerData) {
        $customerForm['source'] = $customerForm['source'] !== '' ? $customerForm['source'] : 'Admin rögzítés';
        $customerForm['status'] = $customerForm['status'] !== '' ? $customerForm['status'] : 'Mérőhelyi igény';
        $customerForm['contact_data_accepted'] = (int) $customerForm['contact_data_accepted'] === 1 ? 1 : ($isNewCustomerRequest ? 1 : 0);
    }
    $customerDefaultsForRequest = $shouldSaveCustomerData ? $customerForm : ($customer ?? $customerForm);
    $form = normalize_connection_request_data($_POST, $customerDefaultsForRequest);
    $errors = [];

    if ($request === null || $initialDataEditable) {
        $regularPrefillResult = handle_connection_request_document_prefill_from_regular_uploads($_FILES, $customerForm, $form, true);

        if (!($regularPrefillResult['no_files'] ?? false)) {
            $documentPrefillResult = $regularPrefillResult;

            if (($documentPrefillResult['ok'] ?? false)) {
                $customerForm = (array) ($documentPrefillResult['customer_form'] ?? $customerForm);
                $form = (array) ($documentPrefillResult['request_form'] ?? $form);
            }
        }
    }

    if ($request !== null && !$initialDataEditable) {
        $errors[] = 'Az adatlap Folyamatban vagy későbbi státuszban van, ezért az alapadatok már nem módosíthatók ezen az oldalon.';
    }

    $errors = array_merge(
        $errors,
        $shouldSaveCustomerData ? validate_customer_data($customerForm, false) : [],
        validate_connection_request_data($form, $_FILES, false, $requestId ?: null)
    );

    if ($errors === []) {
        try {
            if ($customer !== null) {
                $savedCustomerId = (int) $customer['id'];

                if ($shouldSaveCustomerData) {
                    update_customer($savedCustomerId, $customerForm);
                }
            } else {
                $savedCustomerId = create_customer($customerForm, null, $submittedByUserId);
            }

            $customer = find_customer($savedCustomerId);
            $savedRequestId = save_connection_request($savedCustomerId, $form, $requestId ?: null, $submittedByUserId, true);
            $requestId = $savedRequestId;
            document_prefill_attach_session_files($savedRequestId, $documentPrefillToken);
            $uploadMessages = handle_connection_request_uploads($savedRequestId, $_FILES, false);

            if ($uploadMessages === []) {
                set_flash('success', $shouldSaveCustomerData ? 'Az ügyfél és az igény mentve.' : 'Az igényt mentettük.');
                redirect('/admin/connection-requests/edit?id=' . $savedRequestId);
            }

            $request = find_connection_request($savedRequestId);
            $existingFiles = connection_request_files($savedRequestId);
            $requestQuotes = quotes_for_connection_request($savedRequestId);
            $requestAcceptedQuote = accepted_quote_for_connection_request($savedRequestId)
                ?? accepted_quote_for_registration_duplicate_request($savedRequestId);
            $requestDocuments = connection_request_documents($savedRequestId);
            $requestWorkflowStage = $request !== null
                ? connection_request_admin_workflow_stage($request, $requestQuotes[0] ?? null, $requestAcceptedQuote, $requestDocuments)
                : null;
            $initialDataEditable = $request !== null
                ? connection_request_initial_data_is_editable($request, $requestQuotes[0] ?? null, $requestAcceptedQuote, $requestDocuments)
                : true;
            $actionUrl = url_path('/admin/connection-requests/edit') . '?id=' . $savedRequestId;
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az igény mentése sikertelen.';
        }
    }
    }
}

$pageTitle = $requestId
    ? 'Igény szerkesztése'
    : ($customer === null ? 'Új ügyfél + igény rögzítése' : 'Új igény rögzítése');
$pageSubtitle = $customer !== null
    ? (string) $customer['requester_name'] . ' · ' . (string) $customer['email']
    : 'A kezdőlapról indított admin rögzítésnél itt tudod felvenni az ügyfelet és a hozzá tartozó munkaigényt egyben.';
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1><?= h($pageTitle); ?></h1>
                <p><?= h($pageSubtitle); ?></p>
            </div>
            <div class="form-actions">
                <a class="button button-secondary" href="<?= h(url_path('/admin/customers')); ?>">Ügyfelek</a>
                <a class="button button-secondary" href="<?= h($request !== null ? url_path('/admin/minicrm-import') . '?request=' . (int) $request['id'] . '#portal-work-' . (int) $request['id'] : url_path('/admin/minicrm-import') . '#portal-works'); ?>">Munkalista</a>
                <?php if ($customer !== null && $initialDataEditable): ?>
                    <a class="button button-secondary" href="<?= h(url_path('/admin/customers/edit') . '?id=' . (int) $customer['id']); ?>">Ügyfél szerkesztése</a>
                <?php endif; ?>
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
                <?php if ($requestWorkflowStage !== null): ?>
                    <div>
                        <p>Munkafolyamat: <?= h(admin_workflow_stage_label($requestWorkflowStage)); ?><?= !$initialDataEditable ? ' · Az alapadatok már zárolva vannak.' : ' · Az alapadatok még szerkeszthetők.'; ?></p>
                    </div>
                <?php endif; ?>
                <div class="inline-link-list">
                    <a href="<?= h(url_path('/quick-quote') . '?request_id=' . (int) $request['id']); ?>">Gyors árajánlat</a>
                    <a href="<?= h(url_path('/admin/connection-requests/mvm-documents') . '?id=' . (int) $request['id']); ?>">MVM dokumentumok</a>
                </div>
            </section>

            <section class="download-panel">
                <div>
                    <h2>ÜK szám</h2>
                    <p>Az MVM által adott ügyfélkapcsolati szám. Mentés után az adatlap nevének végére is rákerül.</p>
                </div>
                <form class="inline-form" method="post" action="<?= h(url_path('/admin/connection-requests/edit') . '?id=' . (int) $request['id']); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_mvm_uk_number">
                    <input name="mvm_uk_number" value="<?= h((string) ($request['mvm_uk_number'] ?? '')); ?>" placeholder="MVM ÜK szám" aria-label="MVM ÜK szám">
                    <button class="button button-secondary" type="submit">ÜK szám mentése</button>
                </form>
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

        <?php if ($request !== null && !$initialDataEditable): ?>
            <div class="alert alert-info"><p>Ez az adatlap már Folyamatban vagy későbbi munkafolyamatban van. Az MVM-beadás alapadatai ezen az oldalon csak megtekinthetők.</p></div>
        <?php endif; ?>

        <form class="form" method="post" enctype="multipart/form-data" action="<?= h($actionUrl); ?>">
            <?= csrf_field(); ?>
            <?php render_connection_request_document_prefill_panel($documentPrefillToken, $documentPrefillResult); ?>

            <div class="form-grid two">
                <section class="auth-panel">
                    <h2>Ügyfél adatai</h2>
                    <?php if ($customer === null || $initialDataEditable): ?>
                        <input type="hidden" name="is_legal_entity" value="0">
                        <label class="checkbox-row"><input type="checkbox" name="is_legal_entity" value="1" <?= (int) $customerForm['is_legal_entity'] === 1 ? 'checked' : ''; ?>><span>Jogi személy</span></label>
                        <label>Név</label><input name="requester_name" value="<?= h($customerForm['requester_name']); ?>" required>
                        <label>Cégnév</label><input name="company_name" value="<?= h($customerForm['company_name']); ?>">
                        <label>Adószám</label><input name="tax_number" value="<?= h($customerForm['tax_number']); ?>">
                        <label>Telefon</label><input name="phone" value="<?= h($customerForm['phone']); ?>" required>
                        <label>Email</label><input name="email" type="email" value="<?= h($customerForm['email']); ?>" required>
                        <label>ÜK szám</label><input name="mvm_uk_number" value="<?= h($form['mvm_uk_number']); ?>" placeholder="MVM ÜK szám">
                        <label>Postai cím</label><input name="postal_address" value="<?= h($customerForm['postal_address']); ?>" required>
                        <label>Irányítószám</label><input name="postal_code" value="<?= h($customerForm['postal_code']); ?>" required>
                        <label>Település</label><input name="city" value="<?= h($customerForm['city']); ?>" required>
                        <label>Levelezési cím</label><input name="mailing_address" value="<?= h($customerForm['mailing_address']); ?>">
                        <label>Születési név</label><input name="birth_name" value="<?= h($customerForm['birth_name']); ?>">
                        <label>Anyja neve</label><input name="mother_name" value="<?= h($customerForm['mother_name']); ?>">
                        <label>Születési hely</label><input name="birth_place" value="<?= h($customerForm['birth_place']); ?>">
                        <label>Születési idő</label><input name="birth_date" type="date" value="<?= h($customerForm['birth_date']); ?>">
                        <input type="hidden" name="source" value="<?= h($customerForm['source']); ?>">
                        <input type="hidden" name="status" value="<?= h($customerForm['status']); ?>">
                    <?php else: ?>
                        <div class="status-list">
                            <li><span class="status-label">Név</span><span class="status-value"><?= h((string) $customer['requester_name']); ?></span></li>
                            <li><span class="status-label">Születési név</span><span class="status-value"><?= h((string) ($customer['birth_name'] ?? '-')); ?></span></li>
                            <li><span class="status-label">Telefon</span><span class="status-value"><?= h((string) $customer['phone']); ?></span></li>
                            <li><span class="status-label">Email</span><span class="status-value"><?= h((string) $customer['email']); ?></span></li>
                            <li><span class="status-label">Postacím</span><span class="status-value"><?= h((string) $customer['postal_code'] . ' ' . (string) $customer['city'] . ', ' . (string) $customer['postal_address']); ?></span></li>
                        </div>
                        <p class="muted-text">Az adatlap már Folyamatban vagy későbbi státuszban van, ezért az MVM-nek beadott alapadatok itt nem módosíthatók.</p>
                    <?php endif; ?>
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
                                <span class="file-link-row">
                                    <a href="<?= h(url_path('/admin/connection-requests/file') . '?id=' . (int) $file['id']); ?>" target="_blank"><?= h((string) $file['label']); ?>: <?= h((string) $file['original_name']); ?> - <?= h(portal_file_uploader_label($file)); ?></a>
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
                        $isHTariffRequired = !empty($definition['h_tariff_required']);
                        $accept = connection_request_upload_accept($definition);
                        $hasExistingFile = $isHTariffRequired
                            ? connection_request_has_package_file_type($requestId ?: null, (string) $key)
                            : connection_request_has_file_type($requestId ?: null, (string) $key);
                        ?>
                        <label class="file-upload-item" <?= $isHTariffRequired ? 'data-h-tariff-upload="1"' : ''; ?>>
                            <span><?= h((string) $definition['label']); ?><?= ($definition['required'] || $isHTariffRequired) ? ' *' : ''; ?></span>
                            <small><?= $definition['required'] ? 'Lezáráskor mindig kötelező. Több fájl is feltölthető.' : ($isHTariffRequired ? 'H tarifa esetén lezáráskor kötelező, PDF vagy kép formátumban.' : 'Opcionális. Több fájl is feltölthető.'); ?></small>
                            <input name="file_<?= h((string) $key); ?>[]" type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?> <?= $isHTariffRequired ? 'data-h-tariff-required="1" data-has-existing="' . ($hasExistingFile ? '1' : '0') . '"' : ''; ?>>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="form-actions">
                <?php if ($initialDataEditable): ?>
                    <button class="button" name="action" value="save" type="submit" formnovalidate>Igény mentése</button>
                <?php else: ?>
                    <span class="status-badge status-badge-finalized">Alapadatok zárolva</span>
                <?php endif; ?>
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
