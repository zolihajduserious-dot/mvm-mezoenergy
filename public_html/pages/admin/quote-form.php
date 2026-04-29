<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$quoteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$customerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);
$minicrmItemId = filter_input(INPUT_GET, 'minicrm_item', FILTER_VALIDATE_INT);
$quote = $quoteId ? find_quote($quoteId) : null;
$request = $requestId ? find_connection_request($requestId) : null;
$minicrmItem = $minicrmItemId ? find_minicrm_work_item((int) $minicrmItemId) : null;

if ($quoteId && $quote === null) {
    set_flash('error', 'Az ajánlat nem található.');
    redirect('/admin/quotes');
}

if ($quote !== null) {
    $customerId = (int) $quote['customer_id'];
    $requestId = !empty($quote['connection_request_id']) ? (int) $quote['connection_request_id'] : $requestId;
    $request = $requestId ? find_connection_request($requestId) : null;
} elseif ($requestId && $request !== null) {
    $customerId = (int) $request['customer_id'];
} elseif ($minicrmItemId) {
    $minicrmLinkResult = ensure_minicrm_work_item_connection_request((int) $minicrmItemId);

    if (!($minicrmLinkResult['ok'] ?? false)) {
        set_flash('error', (string) ($minicrmLinkResult['message'] ?? 'A MiniCRM munka árajánlathoz kapcsolása sikertelen.'));
        redirect('/admin/minicrm-import?item=' . (int) $minicrmItemId . '#minicrm-work-' . (int) $minicrmItemId);
    }

    $requestId = (int) ($minicrmLinkResult['request_id'] ?? 0);
    $request = $requestId ? find_connection_request($requestId) : null;
    $customerId = (int) ($minicrmLinkResult['customer_id'] ?? 0);
}

$customer = $customerId ? find_customer($customerId) : null;

if ($customer === null) {
    set_flash('error', 'Előbb válassz ügyfelet.');
    redirect('/admin/customers');
}

$backUrl = $minicrmItemId
    ? url_path('/admin/minicrm-import') . '?item=' . (int) $minicrmItemId . '#minicrm-work-' . (int) $minicrmItemId
    : ($request !== null ? url_path('/admin/minicrm-import') . '?request=' . (int) $request['id'] . '#portal-work-' . (int) $request['id'] : url_path('/admin/quotes'));
$backLabel = $minicrmItemId
    ? 'Vissza a MiniCRM munkához'
    : ($request !== null ? 'Vissza a munkához' : 'Ajánlatlista');

$customerRequests = connection_requests_for_customer((int) $customer['id']);

if ($quote === null && $request === null && count($customerRequests) === 1) {
    $requestId = (int) $customerRequests[0]['id'];
    $request = find_connection_request($requestId);
}

$survey = $quote !== null ? quote_survey(isset($quote['survey_id']) ? (int) $quote['survey_id'] : null) : null;
$priceItems = active_price_items();
$quoteSections = quote_price_sections();
$quantityOptions = quote_quantity_options();
$existingLines = $quote !== null ? quote_lines((int) $quote['id']) : [];
$photos = $quote !== null ? quote_photos((int) $quote['id']) : [];
$errors = [];
$photoMessages = [];
$quoteForm = [
    'subject' => $quote['subject'] ?? APP_NAME . ' árajánlat' . ($request !== null ? ' - ' . (string) $request['project_name'] : ''),
    'customer_message' => $quote['customer_message'] ?? '',
];
$surveySeed = $survey ?? [];

if ($survey === null && $request !== null) {
    $surveySeed = [
        'site_address' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? '')),
        'hrsz' => $request['hrsz'] ?? '',
        'work_type' => connection_request_type_label($request['request_type'] ?? null),
        'meter_serial' => $request['meter_serial'] ?? '',
        'current_ampere' => $request['existing_general_power'] ?? '',
        'requested_ampere' => $request['requested_general_power'] ?? '',
        'survey_notes' => $request['notes'] ?? '',
        'has_h_tariff' => ((string) ($request['request_type'] ?? '') === 'h_tariff') ? 1 : 0,
    ];
}

$surveyForm = normalize_survey_data($surveySeed);

if (is_post()) {
    require_valid_csrf_token();
    $postedRequestId = filter_input(INPUT_POST, 'connection_request_id', FILTER_VALIDATE_INT);

    if ($postedRequestId) {
        $postedRequest = find_connection_request((int) $postedRequestId);

        if ($postedRequest === null || (int) $postedRequest['customer_id'] !== (int) $customer['id']) {
            $errors[] = 'A kiválasztott igény nem ehhez az ügyfélhez tartozik.';
        } else {
            $requestId = (int) $postedRequestId;
            $request = $postedRequest;
        }
    } elseif ($customerRequests !== []) {
        $requestId = null;
        $request = null;
        $errors[] = 'Válaszd ki, melyik igényhez kapcsolódik az árajánlat.';
    }

    $quoteForm = [
        'subject' => trim((string) ($_POST['subject'] ?? '')),
        'customer_message' => trim((string) ($_POST['customer_message'] ?? '')),
    ];
    $surveyForm = normalize_survey_data($_POST);
    $lines = collect_quote_lines($_POST);

    if ($quoteForm['subject'] === '') {
        $errors[] = 'Az ajánlat tárgya kötelező.';
    }

    if ($lines === []) {
        $errors[] = 'Legalább egy ajánlati tételt adj meg.';
    }

    if ($errors === []) {
        try {
            $savedQuoteId = save_quote((int) $customer['id'], $quoteForm, $surveyForm, $lines, $quoteId ?: null, $requestId ?: null);

            if (!empty($_FILES['photos'])) {
                $savedQuote = find_quote($savedQuoteId);
                $savedSurveyId = isset($savedQuote['survey_id']) ? (int) $savedQuote['survey_id'] : null;
                $photoMessages = handle_quote_photo_uploads($savedQuoteId, $savedSurveyId, $_FILES['photos']);
            }

            set_flash('success', $quoteId ? 'Az ajánlat frissült.' : 'Az ajánlat létrejött.');
            $savedQuoteUrl = '/admin/quotes/edit?id=' . $savedQuoteId;

            if ($minicrmItemId) {
                $savedQuoteUrl .= '&minicrm_item=' . (int) $minicrmItemId;
            }

            redirect($savedQuoteUrl);
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az ajánlat mentése sikertelen.';
        }
    }
}

$priceItemsBySection = array_fill_keys(array_keys($quoteSections), []);
$activePriceItemIds = [];

foreach ($priceItems as $item) {
    $category = quote_normalize_category((string) $item['category']);
    $priceItemsBySection[$category][] = $item;
    $activePriceItemIds[(int) $item['id']] = true;
}

$selectedQuantities = [];
$customRows = [];

foreach ($existingLines as $line) {
    $priceItemId = isset($line['price_item_id']) ? (int) $line['price_item_id'] : 0;

    if ($priceItemId > 0 && isset($activePriceItemIds[$priceItemId])) {
        $selectedQuantities[$priceItemId] = quote_quantity_value((string) (int) round((float) $line['quantity']));
        continue;
    }

    $customRows[] = $line;
}

if (is_post()) {
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

if ($customRows === []) {
    $customRows = array_fill(0, 3, []);
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow"><?= $minicrmItemId ? 'MiniCRM' : 'Admin'; ?></p>
                <h1><?= $quoteId ? 'Ajánlat szerkesztése' : 'Ajánlat készítése'; ?></h1>
                <p><?= h($customer['requester_name']); ?> - <?= h($customer['email']); ?><?= $request !== null ? ' · ' . h((string) $request['project_name']) : ''; ?></p>
                <?php if ($minicrmItem !== null): ?>
                    <p class="muted-text">MiniCRM adatlap: <?= h((string) ($minicrmItem['card_name'] ?? $minicrmItem['source_id'] ?? '')); ?></p>
                <?php endif; ?>
            </div>
            <a class="button button-secondary" href="<?= h($backUrl); ?>"><?= h($backLabel); ?></a>
        </div>

        <?php if ($errors !== []): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php foreach ($photoMessages as $message): ?><div class="alert alert-info"><p><?= h($message); ?></p></div><?php endforeach; ?>

        <?php
        $formAction = $quoteId
            ? url_path('/admin/quotes/edit') . '?id=' . $quoteId
            : url_path('/admin/quotes/create') . '?customer_id=' . (int) $customer['id'];

        if (!$quoteId && $requestId) {
            $formAction .= '&request_id=' . (int) $requestId;
        }

        if ($minicrmItemId) {
            $formAction .= '&minicrm_item=' . (int) $minicrmItemId;
        }
        ?>
        <form class="form" method="post" enctype="multipart/form-data" action="<?= h($formAction); ?>">
            <?= csrf_field(); ?>

            <div class="form-grid two">
                <section class="auth-panel">
                    <h2>Ajánlat alapadatok</h2>
                    <?php if ($customerRequests !== []): ?>
                        <label for="connection_request_id">Kapcsolódó igény</label>
                        <select id="connection_request_id" name="connection_request_id" required>
                            <option value="">Válassz igényt</option>
                            <?php foreach ($customerRequests as $customerRequest): ?>
                                <option value="<?= (int) $customerRequest['id']; ?>" <?= (int) $requestId === (int) $customerRequest['id'] ? 'selected' : ''; ?>>
                                    #<?= (int) $customerRequest['id']; ?> - <?= h((string) $customerRequest['project_name']); ?><?= !empty($customerRequest['site_address']) ? ' · ' . h((string) $customerRequest['site_address']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Ez alapján jelenik meg az ajánlat az adott mérőhelyi igény adatlapján.</small>
                    <?php endif; ?>
                    <label>Tárgy</label><input name="subject" value="<?= h($quoteForm['subject']); ?>" required>
                    <label>Üzenet az ügyfélnek</label><textarea name="customer_message" rows="4"><?= h($quoteForm['customer_message']); ?></textarea>
                </section>

                <section class="auth-panel">
                    <h2>Helyszíni felmérés</h2>
                    <label>Helyszín címe</label><input name="site_address" value="<?= h($surveyForm['site_address']); ?>">
                    <label>HRSZ</label><input name="hrsz" value="<?= h($surveyForm['hrsz']); ?>">
                    <label>Munka típusa</label><input name="work_type" value="<?= h($surveyForm['work_type']); ?>">
                    <label>Mérő gyári száma</label><input name="meter_serial" value="<?= h($surveyForm['meter_serial']); ?>">
                    <label>Mérőhely helye</label><input name="meter_location" value="<?= h($surveyForm['meter_location']); ?>">
                    <div class="form-grid two compact">
                        <div><label>Jelenlegi fázis</label><input name="current_phase" value="<?= h($surveyForm['current_phase']); ?>"></div>
                        <div><label>Jelenlegi amper</label><input name="current_ampere" value="<?= h($surveyForm['current_ampere']); ?>"></div>
                        <div><label>Igényelt fázis</label><input name="requested_phase" value="<?= h($surveyForm['requested_phase']); ?>"></div>
                        <div><label>Igényelt amper</label><input name="requested_ampere" value="<?= h($surveyForm['requested_ampere']); ?>"></div>
                    </div>
                    <label>Hálózati megjegyzés</label><textarea name="network_notes" rows="3"><?= h($surveyForm['network_notes']); ?></textarea>
                    <label>Kapcsolószekrény / mérőhely megjegyzés</label><textarea name="cabinet_notes" rows="3"><?= h($surveyForm['cabinet_notes']); ?></textarea>
                    <label>Általános megjegyzés</label><textarea name="survey_notes" rows="3"><?= h($surveyForm['survey_notes']); ?></textarea>
                    <label class="checkbox-row"><input type="checkbox" name="has_controlled_meter" value="1" <?= (int) $surveyForm['has_controlled_meter'] === 1 ? 'checked' : ''; ?>><span>Vezérelt mérő van</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="has_solar" value="1" <?= (int) $surveyForm['has_solar'] === 1 ? 'checked' : ''; ?>><span>Napelemes rendszer érintett</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="has_h_tariff" value="1" <?= (int) $surveyForm['has_h_tariff'] === 1 ? 'checked' : ''; ?>><span>H-tarifa érintett</span></label>
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
                                <strong><?= h($item['name']); ?></strong>
                                <span><?= h(format_money($item['unit_price'])); ?> bruttó / <?= h($item['unit']); ?></span>
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
                <h2>Egyedi / meglévő tételek</h2>
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

            <section class="auth-panel form-block">
                <h2>Helyszíni fotók</h2>
                <input name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
                <?php if ($photos !== []): ?>
                    <div class="photo-grid">
                        <?php foreach ($photos as $photo): ?>
                            <a href="<?= h(url_path('/admin/quotes/photo') . '?id=' . (int) $photo['id']); ?>" target="_blank"><?= h($photo['original_name']); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="form-actions">
                <button class="button" type="submit">Ajánlat mentése</button>
                <?php if ($quoteId): ?><a class="button button-secondary" href="<?= h(url_path('/admin/quotes/send') . '?id=' . $quoteId); ?>">PDF / küldés</a><?php endif; ?>
            </div>
        </form>
    </div>
</section>
