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
$documentPrefillToken = document_prefill_token((string) ($_POST['document_prefill_token'] ?? ''));
$documentPrefillResult = null;
$mvmAmpereOptions = ['', '10', '16', '20', '25', '32', '40', '50', '63', '80'];
$mvmCustomerFieldKeys = [
    'uj_fogyaszto',
    'n13',
    'tn',
    'sc',
    'h_tarifa_vagy_melleszereles',
    'csatlakozasi_mod_valtasa',
    'csatlakozovezetek_athelyezese',
    'csatlakozovezetek_csereje',
    'mero_athelyezese',
    'vezerelt_mero_szerelese',
    'rendeltetes_lakas_haz',
    'rendeltetes_iroda_uzlet_rendelo',
    'rendeltetes_ipari_uzemi_terulet',
    'rendeltetes_zartkert_pince_tanya',
    'rendeltetes_udulo_nyaralo',
    'rendeltetes_garazs',
    'rendeltetes_tarsashazi_kozosseg',
    'rendeltetes_egyeb',
    'rendeltetes_egyeb_szoveg',
    'jogcim_tulajdonos',
    'jogcim_berlo',
    'jogcim_haszonelvezo',
    'jogcim_kezelo',
    'jogcim_egyeb',
    'jogcim_egyeb_szoveg',
    'foldkabeles',
    'legvezetekes',
    'jml1',
    'jml2',
    'jml3',
    'iml1',
    'iml2',
    'iml3',
    'jelenlegi_hl1',
    'jelenlegi_hl2',
    'jelenlegi_hl3',
    'ihl1',
    'ihl2',
    'ihl3',
    'jvl1',
    'jvl2',
    'jvl3',
    'ivl1',
    'ivl2',
    'ivl3',
];
$usageTypeFields = [
    'rendeltetes_lakas_haz' => 'Családi ház',
    'rendeltetes_tarsashazi_kozosseg' => 'Társasház',
    'rendeltetes_iroda_uzlet_rendelo' => 'Iroda / üzlet',
    'rendeltetes_ipari_uzemi_terulet' => 'Ipari, üzemi létesítmény',
    'rendeltetes_zartkert_pince_tanya' => 'Tanya, zártkert',
    'rendeltetes_udulo_nyaralo' => 'Nyaraló',
    'rendeltetes_garazs' => 'Garázs',
    'rendeltetes_egyeb' => 'Egyéb',
];
$legalTitleFields = [
    'jogcim_tulajdonos' => 'Tulajdonos',
    'jogcim_berlo' => 'Bérlő',
    'jogcim_haszonelvezo' => 'Haszonélvező',
    'jogcim_kezelo' => 'Kezelő',
    'jogcim_egyeb' => 'Egyéb',
];
$requestGoalFields = [
    'uj_fogyaszto' => 'Új fogyasztásmérő felszerelése',
    'tn' => 'Teljesítménybővítés / növelés',
    'n13' => 'Fázisbővítés',
    'sc' => 'Szabványosítás / felújítás',
    'h_tarifa_vagy_melleszereles' => 'H tarifa vagy mellészerelés',
    'csatlakozasi_mod_valtasa' => 'Csatlakozási mód váltás',
    'csatlakozovezetek_athelyezese' => 'Csatlakozó áthelyezése',
    'csatlakozovezetek_csereje' => 'Csatlakozó csere',
    'mero_athelyezese' => 'Mérőáthelyezés',
    'vezerelt_mero_szerelese' => 'Vezérelt mérő szerelése',
];
$powerGroups = [
    'existing_general_power' => [
        'title' => 'Meglévő összes teljesítmény (mindennapszaki)',
        'fields' => ['jml1', 'jml2', 'jml3'],
        'labels' => ['1. fázis', '2. fázis', '3. fázis'],
    ],
    'requested_general_power' => [
        'title' => 'Igényelt összes teljesítmény (mindennapszaki)',
        'fields' => ['iml1', 'iml2', 'iml3'],
        'labels' => ['1. fázis', '2. fázis', '3. fázis'],
    ],
    'existing_controlled_power' => [
        'title' => 'Vezérelt áramkörön meglévő teljesítmény (éjszakai)',
        'fields' => ['jvl1', 'jvl2', 'jvl3'],
        'labels' => ['1. fázis', '2. fázis', '3. fázis'],
    ],
    'requested_controlled_power' => [
        'title' => 'Vezérelt áramkörön igényelt teljesítmény (éjszakai)',
        'fields' => ['ivl1', 'ivl2', 'ivl3'],
        'labels' => ['1. fázis', '2. fázis', '3. fázis'],
    ],
    'existing_h_tariff_power' => [
        'title' => 'Idényjellegű különmért áramkörön meglévő teljesítmény (H tarifa)',
        'fields' => ['jelenlegi_hl1', 'jelenlegi_hl2', 'jelenlegi_hl3'],
        'labels' => ['1. fázis', '2. fázis', '3. fázis'],
    ],
    'requested_h_tariff_power' => [
        'title' => 'Idényjellegű különmért áramkörön igényelt teljesítmény (H tarifa)',
        'fields' => ['ihl1', 'ihl2', 'ihl3'],
        'labels' => ['1. fázis', '2. fázis', '3. fázis'],
    ],
];

if (is_post()) {
    require_valid_csrf_token();

    $deleteFileId = filter_input(INPUT_POST, 'delete_request_file_id', FILTER_VALIDATE_INT);

    if ($request !== null && $deleteFileId) {
        $result = delete_connection_request_file((int) $deleteFileId, (int) $request['id']);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A fájl törlése sikertelen.'));
        redirect('/customer/work-request?id=' . (int) $request['id']);
    }

    $action = (string) ($_POST['action'] ?? 'save');
    $skipSave = false;
    $finalize = $action === 'finalize' && !$requestAlreadyFinalized;
    $customerForm = normalize_customer_data(array_merge($customer, $_POST));
    $customerForm['source'] = $customerForm['source'] !== '' ? $customerForm['source'] : (string) ($customer['source'] ?? 'Ügyfélportál');
    $customerForm['status'] = $customerForm['status'] !== '' ? $customerForm['status'] : (string) ($customer['status'] ?? 'Mérőhelyi igény');
    $customerForm['contact_data_accepted'] = (int) ($customer['contact_data_accepted'] ?? 0);
    $customerForm['notes'] = (string) ($customer['notes'] ?? '');
    $form = normalize_connection_request_data($_POST, $customerForm);

    if ($action === 'extract_document_prefill') {
        $documentPrefillResult = handle_connection_request_document_prefill($documentPrefillToken, $_FILES, $customerForm, $form);
        $customerForm = (array) ($documentPrefillResult['customer_form'] ?? $customerForm);
        $form = (array) ($documentPrefillResult['request_form'] ?? $form);
        $skipSave = true;
    }

    if (!$skipSave) {
    $regularPrefillResult = handle_connection_request_document_prefill_from_regular_uploads($_FILES, $customerForm, $form, true);

    if (!($regularPrefillResult['no_files'] ?? false)) {
        $documentPrefillResult = $regularPrefillResult;

        if (($documentPrefillResult['ok'] ?? false)) {
            $customerForm = (array) ($documentPrefillResult['customer_form'] ?? $customerForm);
            $form = (array) ($documentPrefillResult['request_form'] ?? $form);
        }
    }

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
            document_prefill_attach_session_files($savedRequestId, $documentPrefillToken);
            if (db_table_exists('connection_request_mvm_forms')) {
                try {
                    $savedRequest = find_connection_request($savedRequestId);

                    if ($savedRequest !== null) {
                        $existingMvmValues = connection_request_mvm_form_values($savedRequest);
                        $submittedMvmValues = normalize_mvm_form_data($_POST);

                        foreach ($mvmCustomerFieldKeys as $fieldKey) {
                            $existingMvmValues[$fieldKey] = (string) ($submittedMvmValues[$fieldKey] ?? '');
                        }

                        save_connection_request_mvm_form($savedRequestId, $existingMvmValues);
                    }
                } catch (Throwable $mvmException) {
                    error_log('Customer MVM helper data save failed for request ' . $savedRequestId . ': ' . $mvmException->getMessage());
                }
            }
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
}

$mvmRequestSeed = array_merge($customerForm, $form, [
    'id' => (int) ($request['id'] ?? $requestId ?? 0),
    'customer_id' => (int) ($customer['id'] ?? 0),
    'is_legal_entity' => (int) ($customerForm['is_legal_entity'] ?? 0),
    'tax_number' => (string) ($customerForm['tax_number'] ?? ''),
    'postal_address' => (string) ($customerForm['postal_address'] ?? ''),
    'postal_code' => (string) ($customerForm['postal_code'] ?? ''),
    'city' => (string) ($customerForm['city'] ?? ''),
]);
$mvmFormValues = $request !== null
    ? connection_request_mvm_form_values($request)
    : mvm_form_default_values($mvmRequestSeed);

if (is_post()) {
    $submittedMvmValues = normalize_mvm_form_data($_POST);

    foreach ($mvmCustomerFieldKeys as $fieldKey) {
        $mvmFormValues[$fieldKey] = (string) ($submittedMvmValues[$fieldKey] ?? '');
    }
}

$mvmChecked = static fn (string $fieldKey): bool => trim((string) ($mvmFormValues[$fieldKey] ?? '')) !== '';
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

        <form class="form mvm-customer-wizard" method="post" enctype="multipart/form-data" action="<?= h($requestId ? url_path('/customer/work-request') . '?id=' . $requestId : url_path('/customer/work-request')); ?>" data-mvm-customer-wizard novalidate>
            <?= csrf_field(); ?>
            <div class="mvm-wizard-shell">
                <nav class="mvm-wizard-progress" aria-label="Igényrögzítési lépések">
                    <button type="button" class="mvm-wizard-progress-item" data-wizard-target="0"><span>1. lépés</span><strong>Igény indítása</strong></button>
                    <button type="button" class="mvm-wizard-progress-item" data-wizard-target="1"><span>2. lépés</span><strong>Adatok</strong></button>
                    <button type="button" class="mvm-wizard-progress-item" data-wizard-target="2"><span>3. lépés</span><strong>Műszaki igény</strong></button>
                    <button type="button" class="mvm-wizard-progress-item" data-wizard-target="3"><span>4. lépés</span><strong>Dokumentumok</strong></button>
                </nav>

                <div class="mvm-wizard-step" data-wizard-step="0">
                    <section class="auth-panel mvm-wizard-panel">
                        <div class="mvm-wizard-panel-head">
                            <p class="eyebrow">Igénybejelentés indítása</p>
                            <h2>Áramhálózati MVM ügy előkészítése</h2>
                            <p>A felület lépésenként végigvezet azokon az adatokon, amelyeket az MVM saját rendszerében is kér. Amit nem tudsz biztosan, azt jelezheted, a hiányzó műszaki részeket a Mező Energy pótolja.</p>
                        </div>
                        <div class="mvm-network-choice">
                            <div class="mvm-network-card is-selected">
                                <span class="mvm-network-icon">A</span>
                                <div>
                                    <strong>Csatlakozás áramhálózathoz</strong>
                                    <small>Kelet-magyarországi MVM területen, villamos hálózati igény előkészítéséhez.</small>
                                </div>
                            </div>
                        </div>
                    </section>

                    <?php render_connection_request_document_prefill_panel($documentPrefillToken, $documentPrefillResult); ?>
                </div>

                <div class="mvm-wizard-step" data-wizard-step="1" hidden>

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
                    <label>ÜK szám</label><input name="mvm_uk_number" value="<?= h($form['mvm_uk_number']); ?>" placeholder="MVM ÜK szám">
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
                    <label>Kivitelezés címe</label><input name="site_address" value="<?= h($form['site_address']); ?>" required>
                    <label>Kivitelezés irányítószáma</label><input name="site_postal_code" value="<?= h($form['site_postal_code']); ?>" required>
                    <label>Helyrajzi szám</label><input name="hrsz" value="<?= h($form['hrsz']); ?>">
                    <label>Saját mérő gyári száma</label><input name="meter_serial" value="<?= h($form['meter_serial']); ?>">
                    <label>Fogyasztási hely azonosító</label><input name="consumption_place_id" value="<?= h($form['consumption_place_id']); ?>">
                    <div class="mvm-subsection">
                        <h3>Használat jogcíme</h3>
                        <div class="mvm-inline-choice-grid">
                            <?php foreach ($legalTitleFields as $fieldKey => $label): ?>
                                <label class="mvm-small-choice">
                                    <input type="checkbox" name="<?= h($fieldKey); ?>" value="1" <?= $mvmChecked($fieldKey) ? 'checked' : ''; ?>>
                                    <span><?= h($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label>Egyéb jogcím megnevezése</label><input name="jogcim_egyeb_szoveg" value="<?= h($mvmFormValues['jogcim_egyeb_szoveg'] ?? ''); ?>">
                    </div>
                    <div class="mvm-subsection">
                        <h3>Felhasználási hely típusa</h3>
                        <div class="mvm-inline-choice-grid">
                            <?php foreach ($usageTypeFields as $fieldKey => $label): ?>
                                <label class="mvm-small-choice">
                                    <input type="checkbox" name="<?= h($fieldKey); ?>" value="1" <?= $mvmChecked($fieldKey) ? 'checked' : ''; ?>>
                                    <span><?= h($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label>Egyéb rendeltetés megnevezése</label><input name="rendeltetes_egyeb_szoveg" value="<?= h($mvmFormValues['rendeltetes_egyeb_szoveg'] ?? ''); ?>">
                    </div>
                </section>
            </div>
                </div>

                <div class="mvm-wizard-step" data-wizard-step="2" hidden>
            <section class="auth-panel form-block mvm-wizard-panel">
                <h2>Teljesítmény adatok</h2>
                <div class="form-grid two compact">
                    <div><label>Meglévő teljesítmény mindennapszaki</label><input name="existing_general_power" value="<?= h($form['existing_general_power']); ?>" data-power-summary="existing_general_power" required></div>
                    <div><label>Igényelt teljesítmény mindennapszaki</label><input name="requested_general_power" value="<?= h($form['requested_general_power']); ?>" data-power-summary="requested_general_power"></div>
                    <div><label>Meglévő teljesítmény H tarifa</label><input name="existing_h_tariff_power" value="<?= h($form['existing_h_tariff_power']); ?>" data-power-summary="existing_h_tariff_power"></div>
                    <div><label>Igényelt teljesítmény H tarifa</label><input name="requested_h_tariff_power" value="<?= h($form['requested_h_tariff_power']); ?>" data-power-summary="requested_h_tariff_power"></div>
                    <div><label>Meglévő teljesítmény vezérelt</label><input name="existing_controlled_power" value="<?= h($form['existing_controlled_power']); ?>" data-power-summary="existing_controlled_power"></div>
                    <div><label>Igényelt teljesítmény vezérelt</label><input name="requested_controlled_power" value="<?= h($form['requested_controlled_power']); ?>" data-power-summary="requested_controlled_power"></div>
                </div>
                <div class="mvm-subsection">
                    <h3>Igénybejelentés célja</h3>
                    <div class="mvm-inline-choice-grid">
                        <?php foreach ($requestGoalFields as $fieldKey => $label): ?>
                            <label class="mvm-small-choice">
                                <input type="checkbox" name="<?= h($fieldKey); ?>" value="1" <?= $mvmChecked($fieldKey) ? 'checked' : ''; ?>>
                                <span><?= h($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mvm-subsection">
                    <h3>Csatlakozó vezeték</h3>
                    <div class="mvm-inline-choice-grid">
                        <label class="mvm-small-choice"><input type="checkbox" name="legvezetekes" value="1" <?= $mvmChecked('legvezetekes') ? 'checked' : ''; ?>><span>Szabadvezetékes</span></label>
                        <label class="mvm-small-choice"><input type="checkbox" name="foldkabeles" value="1" <?= $mvmChecked('foldkabeles') ? 'checked' : ''; ?>><span>Földkábeles</span></label>
                    </div>
                </div>

                <div class="mvm-power-stack">
                    <?php foreach ($powerGroups as $summaryField => $group): ?>
                        <div class="mvm-power-card" data-power-card="<?= h($summaryField); ?>">
                            <div class="mvm-power-card-head">
                                <h3><?= h($group['title']); ?></h3>
                                <button class="table-action-button" type="button" data-fill-unknown="<?= h($summaryField); ?>">Nem tudom, Mező Energy pontosítja</button>
                            </div>
                            <div class="mvm-phase-grid">
                                <?php foreach ($group['fields'] as $fieldIndex => $fieldKey): ?>
                                    <label>
                                        <span><?= h($group['labels'][$fieldIndex]); ?></span>
                                        <select name="<?= h($fieldKey); ?>" data-power-field="<?= h($fieldKey); ?>" data-power-group="<?= h($summaryField); ?>">
                                            <option value="">Válasszon</option>
                                            <?php foreach ($mvmAmpereOptions as $ampere): ?>
                                                <?php if ($ampere === '') { continue; } ?>
                                                <option value="<?= h($ampere); ?>" <?= (string) ($mvmFormValues[$fieldKey] ?? '') === $ampere ? 'selected' : ''; ?>><?= h($ampere); ?> A</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <output data-power-total="<?= h($summaryField); ?>">0 A</output>
                        </div>
                    <?php endforeach; ?>
                </div>

                <label>Megjegyzés</label><textarea name="notes" rows="4"><?= h($form['notes']); ?></textarea>
                <label>Munka megjegyzés</label><textarea name="work_note" rows="3" placeholder="Belső megjegyzés a munkához"><?= h($form['work_note']); ?></textarea>
            </section>
                </div>

                <div class="mvm-wizard-step" data-wizard-step="3" hidden>
            <section class="auth-panel form-block mvm-wizard-panel">
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
                        $isHTariffRequired = !empty($definition['h_tariff_required']);
                        $accept = connection_request_upload_accept($definition);
                        $hasExistingFile = $isHTariffRequired
                            ? connection_request_has_package_file_type($requestId ?: null, (string) $key)
                            : connection_request_has_file_type($requestId ?: null, (string) $key);
                        ?>
                        <label class="file-upload-item">
                            <span><?= h($definition['label']); ?><?= ($definition['required'] || $isHTariffRequired) ? ' *' : ''; ?></span>
                            <small><?= $definition['required'] ? 'Lezáráskor mindig kötelező. Több fájl is feltölthető.' : ($isHTariffRequired ? 'H tarifa esetén lezáráskor kötelező, PDF vagy kép formátumban.' : 'Opcionális. Több fájl is feltölthető.'); ?></small>
                            <input name="file_<?= h($key); ?>[]" type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?> <?= $isHTariffRequired ? 'data-h-tariff-required="1" data-has-existing="' . ($hasExistingFile ? '1' : '0') . '"' : ''; ?>>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
                </div>
            </div>

            <div class="form-actions mvm-wizard-actions">
                <button class="button button-secondary" type="button" data-wizard-prev>Vissza</button>
                <button class="button" type="button" data-wizard-next>Tovább</button>
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
    const form = document.querySelector('[data-mvm-customer-wizard]');
    const select = form ? form.querySelector('[data-request-type-select]') : null;
    const tariffInputs = document.querySelectorAll('[data-h-tariff-required]');
    const steps = form ? Array.from(form.querySelectorAll('[data-wizard-step]')) : [];
    const progressItems = form ? Array.from(form.querySelectorAll('[data-wizard-target]')) : [];
    const prevButton = form ? form.querySelector('[data-wizard-prev]') : null;
    const nextButton = form ? form.querySelector('[data-wizard-next]') : null;
    let currentStep = 0;

    if (!form || !select || steps.length === 0) {
        return;
    }

    const showStep = (stepIndex) => {
        currentStep = Math.max(0, Math.min(stepIndex, steps.length - 1));

        steps.forEach((step, index) => {
            step.hidden = index !== currentStep;
        });

        progressItems.forEach((item, index) => {
            item.classList.toggle('is-active', index === currentStep);
            item.classList.toggle('is-complete', index < currentStep);
        });

        if (prevButton) {
            prevButton.hidden = currentStep === 0;
        }

        if (nextButton) {
            nextButton.hidden = currentStep === steps.length - 1;
        }

        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const visibleRequiredFields = (step) => Array.from(step.querySelectorAll('input, select, textarea'))
        .filter((field) => field.required && !field.disabled && field.type !== 'hidden');

    const validateStep = (stepIndex) => {
        const step = steps[stepIndex];

        if (!step) {
            return true;
        }

        for (const field of visibleRequiredFields(step)) {
            if (!field.checkValidity()) {
                field.reportValidity();
                return false;
            }
        }

        return true;
    };

    const syncHTariffFields = () => {
        const isHTariff = select.value === 'h_tariff';

        tariffInputs.forEach((input) => {
            input.required = isHTariff && input.dataset.hasExisting !== '1';
        });
    };

    const syncPowerSummaries = () => {
        form.querySelectorAll('[data-power-summary]').forEach((summary) => {
            const group = summary.dataset.powerSummary;
            const fields = Array.from(form.querySelectorAll(`[data-power-group="${group}"]`));
            const values = fields
                .map((field, index) => ({ index: index + 1, value: field.value }))
                .filter((item) => item.value !== '');

            if (values.length > 0) {
                summary.value = values.map((item) => `${item.index}. fázis: ${item.value} A`).join(', ');
            }
        });

        form.querySelectorAll('[data-power-total]').forEach((output) => {
            const group = output.dataset.powerTotal;
            const total = Array.from(form.querySelectorAll(`[data-power-group="${group}"]`))
                .reduce((sum, field) => sum + (parseInt(field.value, 10) || 0), 0);
            output.value = `${total} A`;
            output.textContent = `${total} A`;
        });
    };

    select.addEventListener('change', syncHTariffFields);
    form.querySelectorAll('[data-power-field]').forEach((field) => {
        field.addEventListener('change', syncPowerSummaries);
    });
    form.querySelectorAll('[data-fill-unknown]').forEach((button) => {
        button.addEventListener('click', () => {
            const summary = form.querySelector(`[data-power-summary="${button.dataset.fillUnknown}"]`);

            if (summary) {
                summary.value = 'Nem tudom, Mező Energy pontosítja a fotók alapján';
            }
        });
    });
    progressItems.forEach((item) => {
        item.addEventListener('click', () => {
            const target = parseInt(item.dataset.wizardTarget || '0', 10);

            if (target <= currentStep || validateStep(currentStep)) {
                showStep(target);
            }
        });
    });

    if (prevButton) {
        prevButton.addEventListener('click', () => showStep(currentStep - 1));
    }

    if (nextButton) {
        nextButton.addEventListener('click', () => {
            if (validateStep(currentStep)) {
                showStep(currentStep + 1);
            }
        });
    }

    form.addEventListener('submit', (event) => {
        syncPowerSummaries();

        if (event.submitter && event.submitter.value !== 'finalize') {
            return;
        }

        for (let index = 0; index < steps.length; index++) {
            showStep(index);

            if (!validateStep(index)) {
                event.preventDefault();
                return;
            }
        }
    });

    syncHTariffFields();
    syncPowerSummaries();
    showStep(0);
})();
</script>
