<?php
declare(strict_types=1);

require_role(['admin', 'specialist', 'electrician', 'general_contractor']);

function quick_quote_user_can_manage(array $quote): bool
{
    if (is_staff_user()) {
        return true;
    }

    $user = current_user();

    if (!is_array($user)) {
        return false;
    }

    $userId = (int) $user['id'];

    if (!empty($quote['connection_request_id'])) {
        $request = find_connection_request((int) $quote['connection_request_id']);

        if ($request !== null) {
            return (int) ($request['submitted_by_user_id'] ?? 0) === $userId
                || (int) ($request['assigned_electrician_user_id'] ?? 0) === $userId;
        }
    }

    $customer = find_customer((int) $quote['customer_id']);

    return $customer !== null && (int) ($customer['created_by_user_id'] ?? 0) === $userId;
}

function quick_quote_request_file_url(array $file): string
{
    $fileId = (int) ($file['id'] ?? 0);

    if (is_staff_user()) {
        return url_path('/admin/connection-requests/file') . '?id=' . $fileId;
    }

    if (is_electrician_user()) {
        return url_path('/electrician/work-requests/customer-file') . '?id=' . $fileId;
    }

    if (is_general_contractor_user()) {
        return url_path('/contractor/work-requests/file') . '?id=' . $fileId;
    }

    return '#';
}

function quick_quote_request_context_url(?array $request): string
{
    if ($request === null) {
        return '';
    }

    $requestId = (int) ($request['id'] ?? 0);

    if ($requestId <= 0) {
        return '';
    }

    if (is_staff_user()) {
        return url_path('/admin/minicrm-import') . '?request=' . $requestId . '#portal-work-' . $requestId;
    }

    if (is_electrician_user()) {
        return url_path('/electrician/work-request') . '?id=' . $requestId;
    }

    if (is_general_contractor_user()) {
        return url_path('/contractor/work-request') . '?id=' . $requestId;
    }

    return '';
}

function quick_quote_render_connection_request_upload_panel(?int $requestId, array $existingFiles, string $requestType): void
{
    ?>
    <section class="auth-panel form-block">
        <h2>Fotók és kitöltött dokumentumok</h2>
        <p class="muted-text">Ugyanazokat a fotókat és dokumentumokat töltheted fel, mint az ügyfél saját adatlapján. Több fájl is feltölthető egy mezőhöz.</p>

        <?php if ($existingFiles !== []): ?>
            <div class="portal-card-files existing-file-panel">
                <h3>Már feltöltött fájlok</h3>
                <div class="inline-link-list">
                    <?php foreach ($existingFiles as $file): ?>
                        <a href="<?= h(quick_quote_request_file_url($file)); ?>" target="_blank">
                            <?= h((string) ($file['label'] ?? 'Fájl')); ?>: <?= h((string) ($file['original_name'] ?? '-')); ?> - <?= h(portal_file_uploader_label($file)); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="file-upload-grid">
            <?php foreach (connection_request_upload_definitions() as $key => $definition): ?>
                <?php
                $isImage = ($definition['kind'] ?? '') === 'image';
                $accept = $isImage ? 'image/jpeg,image/png,image/webp' : '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp';
                $hasExistingFile = connection_request_has_file_type($requestId, (string) $key);
                $isHTariffRequired = !empty($definition['h_tariff_required']);
                $hideHTariff = $isHTariffRequired && $requestType !== 'h_tariff';
                ?>
                <label class="file-upload-item" <?= $isHTariffRequired ? 'data-h-tariff-upload="1"' : ''; ?> <?= $hideHTariff ? 'hidden' : ''; ?>>
                    <span><?= h((string) $definition['label']); ?><?= ($definition['required'] || $isHTariffRequired) ? ' *' : ''; ?></span>
                    <small><?= $definition['required'] ? 'Lezáráskor mindig kötelező. Több fájl is feltölthető.' : ($isHTariffRequired ? 'H tarifa esetén tölthető fel.' : 'Opcionális. Több fájl is feltölthető.'); ?></small>
                    <input name="file_<?= h((string) $key); ?>[]" type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?> <?= $isHTariffRequired ? 'data-h-tariff-required="1" data-has-existing="' . ($hasExistingFile ? '1' : '0') . '"' : ''; ?>>
                </label>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function quick_quote_customer_operation_message(array $result): string
{
    $message = (string) ($result['message'] ?? '');

    if (!($result['ok'] ?? false) && preg_match('/dompdf|phpmailer|composer|vendor|smtp/i', $message)) {
        return 'A művelet jelenleg nem indítható. Kérlek próbáld újra később, vagy jelezd a weboldal karbantartójának.';
    }

    return $message;
}

$user = current_user();
$quoteId = filter_input(INPUT_GET, 'quote_id', FILTER_VALIDATE_INT);
$quote = $quoteId ? find_quote($quoteId) : null;

if ($quoteId && ($quote === null || !quick_quote_user_can_manage($quote))) {
    set_flash('error', 'Az árajánlat nem található, vagy nincs jogosultságod megnyitni.');
    redirect('/quick-quote');
}

$requestId = $quote !== null && !empty($quote['connection_request_id']) ? (int) $quote['connection_request_id'] : null;
$request = $requestId ? find_connection_request($requestId) : null;
$existingFiles = $requestId ? connection_request_files($requestId) : [];

$priceItems = active_price_items();
$quoteSections = quote_price_sections();
$quantityOptions = quote_quantity_options();
$priceItemsBySection = array_fill_keys(array_keys($quoteSections), []);

foreach ($priceItems as $item) {
    $category = quote_effective_category((string) $item['category'], (string) $item['name']);
    $priceItemsBySection[$category][] = $item;
}

$errors = [];
$flash = get_flash();
$publicQuoteUrl = null;

if ($quote !== null) {
    $token = ensure_quote_public_token((int) $quote['id']);

    if ($token !== null) {
        $quote['public_token'] = $token;
    }

    $publicQuoteUrl = quote_public_url($quote);
}

$form = [
    'requester_name' => '',
    'email' => '',
    'phone' => '',
    'subject' => APP_NAME . ' árajánlat',
    'customer_message' => '',
];
$requestForm = normalize_connection_request_data($request ?? [
    'request_type' => 'phase_upgrade',
    'project_name' => '',
    'site_address' => '',
    'site_postal_code' => '',
    'notes' => '',
]);
$surveyForm = normalize_survey_data([
    'site_address' => trim((string) ($requestForm['site_postal_code'] ?? '') . ' ' . (string) ($requestForm['site_address'] ?? '')),
    'work_type' => connection_request_type_label($requestForm['request_type'] ?? 'phase_upgrade'),
]);
$selectedQuantities = [];
$customRows = array_fill(0, 3, []);

if (is_post()) {
    require_valid_csrf_token();
    $action = (string) ($_POST['quick_action'] ?? 'save_quote');

    if ($quote !== null && in_array($action, ['pdf', 'send'], true)) {
        $result = $action === 'send' ? send_quote_email((int) $quote['id']) : generate_quote_pdf((int) $quote['id']);
        $message = quick_quote_customer_operation_message($result);
        set_flash($result['ok'] ? 'success' : 'error', $message);
        redirect('/quick-quote?quote_id=' . (int) $quote['id']);
    }

    if ($quote !== null && $action === 'upload_request_files') {
        if ($requestId === null || $request === null) {
            $errors[] = 'Ehhez az árajánlathoz nem tartozik munkaadatlap, ezért nem lehet fájlokat feltölteni.';
        } else {
            $requestForm = normalize_connection_request_data($request);
            $errors = validate_connection_request_data($requestForm, $_FILES, false, $requestId);

            if ($errors === []) {
                $uploadMessages = handle_connection_request_uploads($requestId, $_FILES, false, 'Gyors árajánlat');

                set_flash(
                    $uploadMessages === [] ? 'success' : 'error',
                    $uploadMessages === []
                        ? 'A fotókat és dokumentumokat mentettük az adatlaphoz.'
                        : 'Az adatlap megmaradt, de néhány fájlt nem sikerült feltölteni: ' . implode(' ', $uploadMessages)
                );
                redirect('/quick-quote?quote_id=' . (int) $quote['id']);
            }
        }
    }

    if ($quote === null && $action === 'save_quote') {
        $form = [
            'requester_name' => trim((string) ($_POST['requester_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'subject' => trim((string) ($_POST['subject'] ?? '')),
            'customer_message' => trim((string) ($_POST['customer_message'] ?? '')),
        ];
        $requestForm = normalize_connection_request_data($_POST);

        if ($requestForm['project_name'] === '' && $form['requester_name'] !== '') {
            $requestForm['project_name'] = 'Gyors árajánlat - ' . $form['requester_name'];
        }

        $surveyForm = normalize_survey_data([
            'site_address' => trim((string) $requestForm['site_postal_code'] . ' ' . (string) $requestForm['site_address']),
            'work_type' => connection_request_type_label($requestForm['request_type']),
            'hrsz' => $requestForm['hrsz'],
            'meter_serial' => $requestForm['meter_serial'],
            'current_ampere' => $requestForm['existing_general_power'],
            'requested_ampere' => $requestForm['requested_general_power'],
            'survey_notes' => $requestForm['notes'],
            'has_h_tariff' => $requestForm['request_type'] === 'h_tariff' ? 1 : 0,
        ]);
        $lines = collect_quote_lines($_POST);

        if ($form['requester_name'] === '') {
            $errors[] = 'A név megadása kötelező.';
        }

        if ($requestForm['site_address'] === '') {
            $errors[] = 'A cím megadása kötelező.';
        }

        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Érvényes email cím megadása kötelező.';
        }

        if ($form['phone'] === '') {
            $errors[] = 'A telefonszám megadása kötelező.';
        }

        if (!isset(connection_request_type_options()[$requestForm['request_type']])) {
            $errors[] = 'A munka típusának kiválasztása kötelező.';
        }

        if ($form['subject'] === '') {
            $errors[] = 'Az ajánlat tárgya kötelező.';
        }

        if ($lines === []) {
            $errors[] = 'Legalább egy ajánlati tételt adj meg.';
        }

        $errors = array_merge($errors, validate_connection_request_data($requestForm, $_FILES, false, null));

        if ($errors === []) {
            $customerForm = [
                'is_legal_entity' => 0,
                'requester_name' => $form['requester_name'],
                'birth_name' => '',
                'company_name' => '',
                'tax_number' => '',
                'phone' => $form['phone'],
                'email' => $form['email'],
                'postal_address' => $requestForm['site_address'],
                'postal_code' => $requestForm['site_postal_code'],
                'city' => '',
                'mailing_address' => '',
                'mother_name' => '',
                'birth_place' => '',
                'birth_date' => '',
                'contact_data_accepted' => 0,
                'source' => 'Gyors árajánlat',
                'status' => 'Árajánlat',
                'notes' => 'Gyors árajánlat miatt csak a feltétlenül szükséges adatok lettek rögzítve.',
            ];
            if ($requestForm['notes'] === '') {
                $requestForm['notes'] = 'Gyors árajánlat helyszíni vagy telefonos egyeztetéshez.';
            }
            $quoteForm = [
                'subject' => $form['subject'],
                'customer_message' => $form['customer_message'],
            ];

            try {
                $customerId = create_customer($customerForm, null, is_array($user) ? (int) $user['id'] : null);
                $savedRequestId = save_connection_request($customerId, $requestForm, null, is_array($user) ? (int) $user['id'] : null);
                $uploadMessages = handle_connection_request_uploads($savedRequestId, $_FILES, false, 'Gyors árajánlat');
                $savedQuoteId = save_quote($customerId, $quoteForm, $surveyForm, $lines, null, $savedRequestId);
                ensure_quote_public_token($savedQuoteId);
                $mailResult = send_quote_email($savedQuoteId);
                $mailMessage = quick_quote_customer_operation_message($mailResult);
                $messages = [];

                if ($mailResult['ok']) {
                    $messages[] = 'A gyors árajánlat és a hozzá tartozó adatlap elkészült, az ajánlatot emailben elküldtük az ügyfélnek. Az ügyfél a levélből meg tudja nyitni és el tudja fogadni az ajánlatot.';
                } else {
                    $messages[] = 'A gyors árajánlat és a hozzá tartozó adatlap elkészült, de az email küldése nem sikerült: ' . $mailMessage . ' Az ajánlat oldalán az Email küldése gombbal újrapróbálható.';
                }

                if ($uploadMessages !== []) {
                    $messages[] = 'Néhány fájlt nem sikerült feltölteni: ' . implode(' ', $uploadMessages);
                }

                set_flash(
                    $mailResult['ok'] && $uploadMessages === [] ? 'success' : 'error',
                    implode(' ', $messages)
                );
                redirect('/quick-quote?quote_id=' . $savedQuoteId);
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'A gyors árajánlat mentése sikertelen.';
            }
        }

        $postedQuantities = $_POST['price_item_quantity'] ?? [];

        foreach ($priceItems as $item) {
            $selectedQuantities[(int) $item['id']] = quote_quantity_value($postedQuantities[$item['id']] ?? 0);
        }

        $customRows = [];
        $customNames = $_POST['custom_name'] ?? [];
        $customCategories = $_POST['custom_category'] ?? [];
        $customUnits = $_POST['custom_unit'] ?? [];
        $customQuantities = $_POST['custom_quantity'] ?? [];
        $customPrices = $_POST['custom_unit_price'] ?? [];
        $rowCount = max(count((array) $customNames), 3);

        for ($index = 0; $index < $rowCount; $index++) {
            $customRows[] = [
                'category' => quote_normalize_category(trim((string) ($customCategories[$index] ?? ''))),
                'name' => trim((string) ($customNames[$index] ?? '')),
                'unit' => trim((string) ($customUnits[$index] ?? 'db')) ?: 'db',
                'quantity' => quote_quantity_value($customQuantities[$index] ?? 0),
                'unit_price' => trim((string) ($customPrices[$index] ?? '')),
                'vat_rate' => 27,
            ];
        }
    }
}

$requestContextUrl = quick_quote_request_context_url($request);
$quoteLines = $quote !== null ? quote_lines((int) $quote['id']) : [];
$quoteTotal = $quote !== null ? quote_display_total($quote) : null;
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Gyors árajánlat</p>
                <h1>Árajánlat minimális ügyféladatokkal</h1>
                <p>Név, cím, email, telefonszám és munka típusa elég az első ajánlathoz. Részletes ügyféladat csak elfogadás után kell.</p>
            </div>
            <div class="admin-actions">
                <?php if ($quote !== null): ?><a class="button" href="<?= h(url_path('/quick-quote')); ?>">Új gyors árajánlat</a><?php endif; ?>
                <a class="button button-secondary" href="<?= h(url_path(dashboard_path_for_user())); ?>">Vissza</a>
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

        <?php if ($quote !== null): ?>
            <section class="auth-panel quote-send-panel">
                <div class="quote-send-summary">
                    <div><span>Ajánlatszám</span><strong><?= h((string) $quote['quote_number']); ?></strong></div>
                    <div><span>Ügyfél</span><strong><?= h((string) $quote['requester_name']); ?></strong></div>
                    <div><span>Email</span><strong><?= h((string) $quote['email']); ?></strong></div>
                    <div><span>Összeg</span><strong><?= h((string) $quoteTotal); ?></strong></div>
                </div>

                <div class="admin-actions">
                    <?php if ($publicQuoteUrl !== null): ?><a class="button" href="<?= h($publicQuoteUrl); ?>" target="_blank">Árajánlat megnyitása</a><?php endif; ?>
                    <?php if ($requestContextUrl !== ''): ?><a class="button button-secondary" href="<?= h($requestContextUrl); ?>">Adatlap megnyitása</a><?php endif; ?>
                    <?php if ($request !== null): ?><a class="button button-secondary" href="<?= h(authorization_signature_url($request)); ?>" target="_blank">Meghatalmazás online aláírása</a><?php endif; ?>
                    <form method="post" action="<?= h(url_path('/quick-quote') . '?quote_id=' . (int) $quote['id']); ?>">
                        <?= csrf_field(); ?>
                        <button class="button button-secondary" name="quick_action" value="pdf" type="submit">PDF generálása</button>
                    </form>
                    <form method="post" action="<?= h(url_path('/quick-quote') . '?quote_id=' . (int) $quote['id']); ?>">
                        <?= csrf_field(); ?>
                        <button class="button button-secondary" name="quick_action" value="send" type="submit">Email küldése</button>
                    </form>
                </div>

                <?php if ($quoteLines !== []): ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Tétel</th><th>Mennyiség</th><th>Bruttó ár</th><th>Összesen</th></tr></thead>
                            <tbody>
                                <?php foreach ($quoteLines as $line): ?>
                                    <tr>
                                        <td><strong><?= h((string) $line['name']); ?></strong><span><?= h((string) $line['category']); ?></span></td>
                                        <td><?= h((string) $line['quantity']); ?> <?= h((string) $line['unit']); ?></td>
                                        <td><?= h(format_money((float) $line['unit_price'])); ?></td>
                                        <td><?= h(format_money((float) $line['line_gross'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($request !== null): ?>
                <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/quick-quote') . '?quote_id=' . (int) $quote['id']); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="quick_action" value="upload_request_files">
                    <?php quick_quote_render_connection_request_upload_panel($requestId, $existingFiles, (string) ($request['request_type'] ?? '')); ?>
                    <div class="form-actions">
                        <button class="button" type="submit">Fotók és dokumentumok feltöltése</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/quick-quote')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="quick_action" value="save_quote">

                <div class="form-grid two">
                    <section class="auth-panel">
                        <h2>Ügyfél alapadatai</h2>
                        <label for="requester_name">Név</label>
                        <input id="requester_name" name="requester_name" value="<?= h($form['requester_name']); ?>" required>

                        <label for="email">Email cím</label>
                        <input id="email" name="email" type="email" value="<?= h($form['email']); ?>" required>

                        <label for="phone">Telefonszám</label>
                        <input id="phone" name="phone" value="<?= h($form['phone']); ?>" required>
                    </section>

                    <section class="auth-panel">
                        <h2>Ajánlat alapadatai</h2>
                        <label for="subject">Tárgy</label>
                        <input id="subject" name="subject" value="<?= h($form['subject']); ?>" required>

                        <label for="customer_message">Üzenet az ügyfélnek</label>
                        <textarea id="customer_message" name="customer_message" rows="8"><?= h($form['customer_message']); ?></textarea>
                    </section>
                </div>

                <section class="auth-panel form-block">
                    <h2>Adatlap adatai</h2>
                    <div class="form-grid two compact">
                        <div>
                            <label for="request_type">Munka típusa</label>
                            <select id="request_type" name="request_type" data-request-type-select required>
                                <?php foreach (connection_request_type_options() as $type => $label): ?>
                                    <option value="<?= h($type); ?>" <?= $requestForm['request_type'] === $type ? 'selected' : ''; ?>><?= h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Igény megnevezése</label><input name="project_name" value="<?= h($requestForm['project_name']); ?>" placeholder="Példa: Szeged, Petőfi utca 12. - mérőhely szabványosítás"></div>
                        <div><label>Kivitelezés címe</label><input name="site_address" value="<?= h($requestForm['site_address']); ?>" required></div>
                        <div><label>Kivitelezés irányítószáma</label><input name="site_postal_code" value="<?= h($requestForm['site_postal_code']); ?>"></div>
                        <div><label>Helyrajzi szám</label><input name="hrsz" value="<?= h($requestForm['hrsz']); ?>"></div>
                        <div><label>Saját mérő gyári száma</label><input name="meter_serial" value="<?= h($requestForm['meter_serial']); ?>"></div>
                        <div><label>Fogyasztási hely azonosító</label><input name="consumption_place_id" value="<?= h($requestForm['consumption_place_id']); ?>"></div>
                    </div>
                </section>

                <section class="auth-panel form-block">
                    <h2>Teljesítmény adatok</h2>
                    <div class="form-grid two compact">
                        <div><label>Meglévő teljesítmény mindennapszaki</label><input name="existing_general_power" value="<?= h($requestForm['existing_general_power']); ?>"></div>
                        <div><label>Igényelt teljesítmény mindennapszaki</label><input name="requested_general_power" value="<?= h($requestForm['requested_general_power']); ?>"></div>
                        <div><label>Meglévő teljesítmény H tarifa</label><input name="existing_h_tariff_power" value="<?= h($requestForm['existing_h_tariff_power']); ?>"></div>
                        <div><label>Igényelt teljesítmény H tarifa</label><input name="requested_h_tariff_power" value="<?= h($requestForm['requested_h_tariff_power']); ?>"></div>
                        <div><label>Meglévő teljesítmény vezérelt</label><input name="existing_controlled_power" value="<?= h($requestForm['existing_controlled_power']); ?>"></div>
                        <div><label>Igényelt teljesítmény vezérelt</label><input name="requested_controlled_power" value="<?= h($requestForm['requested_controlled_power']); ?>"></div>
                    </div>
                    <label>Megjegyzés az adatlaphoz</label>
                    <textarea name="notes" rows="4"><?= h($requestForm['notes']); ?></textarea>
                </section>

                <?php quick_quote_render_connection_request_upload_panel(null, [], (string) $requestForm['request_type']); ?>

                <?php foreach ($quoteSections as $category => $section): ?>
                    <section class="auth-panel form-block quote-section-panel">
                        <h2><?= h((string) $section['title']); ?></h2>
                        <div class="quote-item-grid quote-item-head"><span>Tétel</span><span>Mennyiség</span></div>
                        <?php foreach ($priceItemsBySection[$category] as $item): ?>
                            <?php $selectedQuantity = $selectedQuantities[(int) $item['id']] ?? 0; ?>
                            <div class="quote-item-grid">
                                <div>
                                    <strong><?= h((string) $item['name']); ?></strong>
                                    <span><?= h(format_money((float) $item['unit_price'])); ?> bruttó / <?= h((string) $item['unit']); ?></span>
                                </div>
                                <select name="price_item_quantity[<?= (int) $item['id']; ?>]">
                                    <?php foreach ($quantityOptions as $option): ?>
                                        <option value="<?= (int) $option; ?>" <?= (int) $option === (int) $selectedQuantity ? 'selected' : ''; ?>><?= (int) $option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>

                <section class="auth-panel form-block">
                    <h2>Egyedi tételek</h2>
                    <?php foreach ($customRows as $line): ?>
                        <div class="form-grid five compact custom-line">
                            <?php $selectedCategory = quote_normalize_category((string) ($line['category'] ?? '')); ?>
                            <select name="custom_category[]" aria-label="Kategória">
                                <?php foreach ($quoteSections as $category => $section): ?>
                                    <option value="<?= h($category); ?>" <?= $selectedCategory === $category ? 'selected' : ''; ?>><?= h($category); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="custom_name[]" placeholder="Megnevezés" value="<?= h($line['name'] ?? ''); ?>">
                            <input name="custom_unit[]" placeholder="Egység" value="<?= h($line['unit'] ?? 'db'); ?>">
                            <?php $customQuantity = isset($line['quantity']) ? quote_quantity_value((string) (int) round((float) $line['quantity'])) : 0; ?>
                            <select name="custom_quantity[]" aria-label="Mennyiség">
                                <?php foreach ($quantityOptions as $option): ?>
                                    <option value="<?= (int) $option; ?>" <?= (int) $option === (int) $customQuantity ? 'selected' : ''; ?>><?= (int) $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="custom_unit_price[]" type="number" step="1" min="0" placeholder="Bruttó egységár" value="<?= h($line['unit_price'] ?? ''); ?>">
                            <input name="custom_vat_rate[]" type="hidden" value="<?= h($line['vat_rate'] ?? '27'); ?>">
                        </div>
                    <?php endforeach; ?>
                </section>

                <div class="form-actions">
                    <button class="button" type="submit">Gyors árajánlat mentése és elküldése</button>
                </div>
            </form>
        <?php endif; ?>
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
            input.required = false;
        });
    };

    select.addEventListener('change', syncHTariffFields);
    syncHTariffFields();
})();
</script>
