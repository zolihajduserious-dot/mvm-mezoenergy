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

function quick_quote_required_photo_types(): array
{
    return ['meter_close', 'meter_far', 'roof_hook', 'utility_pole'];
}

function quick_quote_missing_required_photo_types(?int $requestId): array
{
    $definitions = connection_request_upload_definitions();
    $missing = [];

    foreach (quick_quote_required_photo_types() as $type) {
        if ($requestId === null || !connection_request_has_file_type($requestId, $type)) {
            $missing[$type] = (string) ($definitions[$type]['label'] ?? $type);
        }
    }

    return $missing;
}

function quick_quote_uploaded_file_present(array $files, string $type): bool
{
    foreach (uploaded_files_for_key($files, 'file_' . $type) as $file) {
        if (uploaded_file_is_present($file)) {
            return true;
        }
    }

    return false;
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
$requiredPhotoDefinitions = array_intersect_key(connection_request_upload_definitions(), array_flip(quick_quote_required_photo_types()));
$existingFiles = $requestId ? connection_request_files($requestId) : [];
$existingFilesByType = [];

foreach ($existingFiles as $file) {
    $existingFilesByType[(string) $file['file_type']][] = $file;
}

$priceItems = active_price_items();
$quoteSections = quote_price_sections();
$quantityOptions = quote_quantity_options();
$priceItemsBySection = array_fill_keys(array_keys($quoteSections), []);

foreach ($priceItems as $item) {
    $category = quote_normalize_category((string) $item['category']);
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
    'site_address' => '',
    'email' => '',
    'phone' => '',
    'request_type' => 'phase_upgrade',
    'subject' => APP_NAME . ' árajánlat',
    'customer_message' => '',
];
$surveyForm = normalize_survey_data([
    'site_address' => '',
    'work_type' => connection_request_type_label('phase_upgrade'),
]);
$selectedQuantities = [];
$customRows = array_fill(0, 3, []);

if (is_post()) {
    require_valid_csrf_token();
    $action = (string) ($_POST['quick_action'] ?? 'save_quote');

    if ($quote !== null && in_array($action, ['pdf', 'send'], true)) {
        $result = $action === 'send' ? send_quote_email((int) $quote['id']) : generate_quote_pdf((int) $quote['id']);
        $message = (string) $result['message'];

        if (!$result['ok'] && preg_match('/dompdf|phpmailer|composer|vendor|smtp/i', $message)) {
            $message = 'A művelet jelenleg nem indítható. Kérlek próbáld újra később, vagy jelezd a weboldal karbantartójának.';
        }

        set_flash($result['ok'] ? 'success' : 'error', $message);
        redirect('/quick-quote?quote_id=' . (int) $quote['id']);
    }

    if ($quote !== null && $action === 'upload_required_photos') {
        if ($requestId === null) {
            $errors[] = 'Ehhez az árajánlathoz nem tartozik munkacím, ezért nem lehet fotókat feltölteni.';
        } else {
            foreach (quick_quote_missing_required_photo_types($requestId) as $type => $label) {
                if (!quick_quote_uploaded_file_present($_FILES, (string) $type)) {
                    $errors[] = $label . ' feltöltése kötelező.';
                }
            }

            if ($errors === []) {
                $uploadMessages = handle_connection_request_uploads($requestId, $_FILES, false);

                foreach ($uploadMessages as $uploadMessage) {
                    $errors[] = $uploadMessage;
                }

                if ($errors === [] && quick_quote_missing_required_photo_types($requestId) === []) {
                    set_flash('success', 'A kötelező helyszíni fotók feltöltve.');
                    redirect('/quick-quote?quote_id=' . (int) $quote['id']);
                } elseif ($errors === []) {
                    $errors[] = 'Nem érkezett meg minden kötelező helyszíni fotó.';
                }
            }
        }
    }

    if ($quote === null && $action === 'save_quote') {
        $form = [
            'requester_name' => trim((string) ($_POST['requester_name'] ?? '')),
            'site_address' => trim((string) ($_POST['site_address'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'request_type' => trim((string) ($_POST['request_type'] ?? '')),
            'subject' => trim((string) ($_POST['subject'] ?? '')),
            'customer_message' => trim((string) ($_POST['customer_message'] ?? '')),
        ];
        $surveyForm = normalize_survey_data([
            'site_address' => $form['site_address'],
            'work_type' => connection_request_type_label($form['request_type']),
        ]);
        $lines = collect_quote_lines($_POST);

        if ($form['requester_name'] === '') {
            $errors[] = 'A név megadása kötelező.';
        }

        if ($form['site_address'] === '') {
            $errors[] = 'A cím megadása kötelező.';
        }

        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Érvényes email cím megadása kötelező.';
        }

        if ($form['phone'] === '') {
            $errors[] = 'A telefonszám megadása kötelező.';
        }

        if (!isset(connection_request_type_options()[$form['request_type']])) {
            $errors[] = 'A munka típusának kiválasztása kötelező.';
        }

        if ($form['subject'] === '') {
            $errors[] = 'Az ajánlat tárgya kötelező.';
        }

        if ($lines === []) {
            $errors[] = 'Legalább egy ajánlati tételt adj meg.';
        }

        if ($errors === []) {
            $customerForm = [
                'is_legal_entity' => 0,
                'requester_name' => $form['requester_name'],
                'birth_name' => '',
                'company_name' => '',
                'tax_number' => '',
                'phone' => $form['phone'],
                'email' => $form['email'],
                'postal_address' => $form['site_address'],
                'postal_code' => '',
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
            $requestForm = normalize_connection_request_data([
                'request_type' => $form['request_type'],
                'project_name' => 'Gyors árajánlat - ' . $form['requester_name'],
                'site_address' => $form['site_address'],
                'site_postal_code' => '',
                'existing_general_power' => '',
                'notes' => 'Gyors árajánlat helyszíni vagy telefonos egyeztetéshez.',
            ]);
            $quoteForm = [
                'subject' => $form['subject'],
                'customer_message' => $form['customer_message'],
            ];

            try {
                $customerId = create_customer($customerForm, null, is_array($user) ? (int) $user['id'] : null);
                $savedRequestId = save_connection_request($customerId, $requestForm, null, is_array($user) ? (int) $user['id'] : null);
                $savedQuoteId = save_quote($customerId, $quoteForm, $surveyForm, $lines, null, $savedRequestId);
                ensure_quote_public_token($savedQuoteId);

                set_flash('success', 'A gyors árajánlat elkészült. Innen megnyitható, PDF-be menthető vagy emailben kiküldhető.');
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

$missingRequiredPhotos = quick_quote_missing_required_photo_types($requestId);
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

            <section class="auth-panel form-block">
                <h2>Kötelező fotók szóbeli elfogadás után</h2>
                <p>Ha az ügyfélnek a helyszínen megfelel az ajánlat, töltsd fel ezt a 4 fotót: mérő közelről, mérő távolról, tetőtartó/falihorog és villanyoszlop.</p>

                <?php if ($missingRequiredPhotos === []): ?>
                    <div class="alert alert-success"><p>Mind a négy kötelező helyszíni fotó fel van töltve.</p></div>
                <?php else: ?>
                    <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/quick-quote') . '?quote_id=' . (int) $quote['id']); ?>">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="quick_action" value="upload_required_photos">
                        <div class="form-grid two">
                            <?php foreach ($requiredPhotoDefinitions as $type => $definition): ?>
                                <div>
                                    <label for="file_<?= h((string) $type); ?>"><?= h((string) $definition['label']); ?><?= isset($missingRequiredPhotos[$type]) ? ' *' : ''; ?></label>
                                    <input id="file_<?= h((string) $type); ?>" name="file_<?= h((string) $type); ?>[]" type="file" accept="image/jpeg,image/png,image/webp" <?= isset($missingRequiredPhotos[$type]) ? 'required' : ''; ?>>
                                    <?php if (!empty($existingFilesByType[$type])): ?>
                                        <small>Már feltöltve: <?= h((string) $existingFilesByType[$type][0]['original_name']); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="button" type="submit">Fotók feltöltése</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <form class="form" method="post" action="<?= h(url_path('/quick-quote')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="quick_action" value="save_quote">

                <div class="form-grid two">
                    <section class="auth-panel">
                        <h2>Minimális ügyféladatok</h2>
                        <label for="requester_name">Név</label>
                        <input id="requester_name" name="requester_name" value="<?= h($form['requester_name']); ?>" required>

                        <label for="site_address">Cím</label>
                        <input id="site_address" name="site_address" value="<?= h($form['site_address']); ?>" required>

                        <label for="email">Email cím</label>
                        <input id="email" name="email" type="email" value="<?= h($form['email']); ?>" required>

                        <label for="phone">Telefonszám</label>
                        <input id="phone" name="phone" value="<?= h($form['phone']); ?>" required>

                        <label for="request_type">Munka típusa</label>
                        <select id="request_type" name="request_type" required>
                            <?php foreach (connection_request_type_options() as $type => $label): ?>
                                <option value="<?= h($type); ?>" <?= $form['request_type'] === $type ? 'selected' : ''; ?>><?= h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </section>

                    <section class="auth-panel">
                        <h2>Ajánlat alapadatok</h2>
                        <label for="subject">Tárgy</label>
                        <input id="subject" name="subject" value="<?= h($form['subject']); ?>" required>

                        <label for="customer_message">Üzenet az ügyfélnek</label>
                        <textarea id="customer_message" name="customer_message" rows="8"><?= h($form['customer_message']); ?></textarea>
                    </section>
                </div>

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
                    <button class="button" type="submit">Gyors árajánlat mentése</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
