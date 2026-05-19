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
            return quick_quote_user_can_manage_request($request);
        }
    }

    $customer = find_customer((int) $quote['customer_id']);

    return $customer !== null && (int) ($customer['created_by_user_id'] ?? 0) === $userId;
}

function quick_quote_user_can_manage_request(array $request): bool
{
    if (is_staff_user()) {
        return true;
    }

    $user = current_user();

    if (!is_array($user)) {
        return false;
    }

    $userId = (int) $user['id'];

    return (int) ($request['submitted_by_user_id'] ?? 0) === $userId
        || (int) ($request['assigned_electrician_user_id'] ?? 0) === $userId;
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

function quick_quote_current_redirect_path(?array $quote, ?array $request, ?array $customer, ?int $minicrmItemId = null): string
{
    if ($quote !== null) {
        return '/quick-quote?quote_id=' . (int) $quote['id'];
    }

    if ($request !== null) {
        return '/quick-quote?request_id=' . (int) $request['id'];
    }

    if ($customer !== null) {
        return '/quick-quote?customer_id=' . (int) $customer['id'];
    }

    if ($minicrmItemId !== null && $minicrmItemId > 0) {
        return '/quick-quote?minicrm_item=' . (int) $minicrmItemId;
    }

    return '/quick-quote';
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
                        <span class="file-link-row">
                        <a href="<?= h(quick_quote_request_file_url($file)); ?>" target="_blank">
                            <?= h((string) ($file['label'] ?? 'Fájl')); ?>: <?= h((string) ($file['original_name'] ?? '-')); ?> - <?= h(portal_file_uploader_label($file)); ?>
                        </a>
                        <button class="table-action-button table-action-danger" name="delete_request_file_id" value="<?= (int) $file['id']; ?>" type="submit" formnovalidate onclick="return confirm('Biztosan törlöd ezt a fájlt?');">Törlés</button>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="file-upload-grid">
            <?php foreach (connection_request_upload_definitions() as $key => $definition): ?>
                <?php
                $isImage = ($definition['kind'] ?? '') === 'image';
                $isHTariffRequired = !empty($definition['h_tariff_required']);
                $accept = connection_request_upload_accept($definition);
                $hasExistingFile = $isHTariffRequired
                    ? connection_request_has_package_file_type($requestId, (string) $key)
                    : connection_request_has_file_type($requestId, (string) $key);
                ?>
                <label class="file-upload-item">
                    <span><?= h((string) $definition['label']); ?><?= ($definition['required'] || $isHTariffRequired) ? ' *' : ''; ?></span>
                    <small><?= $definition['required'] ? 'Lezáráskor mindig kötelező. Több fájl is feltölthető.' : ($isHTariffRequired ? 'H tarifa esetén PDF vagy kép formátumban tölthető fel.' : 'Opcionális. Több fájl is feltölthető.'); ?></small>
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

function quick_quote_verbal_acceptance_blocker(array $quote): ?string
{
    $quoteId = (int) ($quote['id'] ?? 0);

    if ($quoteId <= 0) {
        return 'Az ajánlat nem található.';
    }

    $selection = quote_fee_request_selection($quoteId);

    if (!($selection['ok'] ?? false) && empty($selection['skipped'])) {
        return (string) ($selection['message'] ?? 'A díjbekérő tétel nem egyértelmű.');
    }

    $line = is_array($selection['line'] ?? null) ? $selection['line'] : null;

    if ($line !== null && (float) ($line['line_gross'] ?? 0) > 0 && szamlazz_quote_fee_request_agent_key($quote) === '') {
        return 'A kiválasztott díjbekérő-kibocsátóhoz nincs beállítva Számlázz.hu Agent kulcs.';
    }

    return null;
}

function quick_quote_send_verbal_acceptance(int $quoteId): array
{
    $quote = find_quote($quoteId);

    if ($quote === null) {
        return ['ok' => false, 'message' => 'Az ajánlat nem található.'];
    }

    $blocker = quick_quote_verbal_acceptance_blocker($quote);

    if ($blocker !== null) {
        return ['ok' => false, 'message' => $blocker];
    }

    $mailResult = send_quote_email($quoteId);

    if (!($mailResult['ok'] ?? false)) {
        return $mailResult;
    }

    if ((string) ($quote['status'] ?? '') === 'accepted') {
        $feeRequest = send_quote_fee_request_email($quoteId, '', true);
        $message = 'Az elfogadott árajánlatot emailben elküldtük.';
        $message .= ' ' . (string) ($feeRequest['message'] ?? '');

        return [
            'ok' => (bool) ($feeRequest['ok'] ?? false),
            'message' => trim($message),
        ];
    }

    $acceptance = record_quote_customer_response($quoteId, 'accept', 'Szóbeli elfogadás rögzítve a helyszínen.');
    $message = 'Az árajánlatot emailben elküldtük.';
    $message .= ' ' . (string) ($acceptance['message'] ?? '');

    return [
        'ok' => (bool) ($acceptance['ok'] ?? false),
        'message' => trim($message),
    ];
}

$user = current_user();
$quoteId = filter_input(INPUT_GET, 'quote_id', FILTER_VALIDATE_INT);
$requestIdFromQuery = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);
$customerIdFromQuery = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
$minicrmItemId = filter_input(INPUT_GET, 'minicrm_item', FILTER_VALIDATE_INT);
$quote = $quoteId ? find_quote($quoteId) : null;

if ($quoteId && ($quote === null || !quick_quote_user_can_manage($quote))) {
    set_flash('error', 'Az árajánlat nem található, vagy nincs jogosultságod megnyitni.');
    redirect('/quick-quote');
}

$requestId = $quote !== null && !empty($quote['connection_request_id']) ? (int) $quote['connection_request_id'] : null;

if ($quote === null && $minicrmItemId) {
    $minicrmLinkResult = ensure_minicrm_work_item_connection_request((int) $minicrmItemId);

    if (!($minicrmLinkResult['ok'] ?? false)) {
        set_flash('error', (string) ($minicrmLinkResult['message'] ?? 'A MiniCRM munka árajánlathoz kapcsolása sikertelen.'));
        redirect('/admin/minicrm-import?item=' . (int) $minicrmItemId . '#minicrm-work-' . (int) $minicrmItemId);
    }

    $requestId = (int) ($minicrmLinkResult['request_id'] ?? 0);
} elseif ($quote === null && $requestIdFromQuery) {
    $requestId = (int) $requestIdFromQuery;
}

$request = $requestId ? find_connection_request($requestId) : null;

if ($quote === null && $requestId && ($request === null || !quick_quote_user_can_manage_request($request))) {
    set_flash('error', 'Az adatlap nem található, vagy nincs jogosultságod árajánlatot készíteni hozzá.');
    redirect('/quick-quote');
}

if ($request !== null) {
    $syncedRequest = minicrm_sync_connection_request_customer_contact((int) $request['id']);

    if ($syncedRequest !== null) {
        $request = $syncedRequest;

        if ($quote !== null) {
            $quote = find_quote((int) $quote['id']) ?? $quote;
        }
    }
}

$customer = null;

if ($quote !== null) {
    $customer = find_customer((int) $quote['customer_id']);
} elseif ($request !== null) {
    $customer = find_customer((int) $request['customer_id']);
} elseif ($customerIdFromQuery) {
    $customer = find_customer((int) $customerIdFromQuery);

    if ($customer === null || (!is_staff_user() && (int) ($customer['created_by_user_id'] ?? 0) !== (int) ($user['id'] ?? 0))) {
        set_flash('error', 'Az ügyfél nem található, vagy nincs jogosultságod árajánlatot készíteni hozzá.');
        redirect('/quick-quote');
    }
}

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
$publicQuoteFileUrl = null;

if ($quote !== null) {
    $token = ensure_quote_public_token((int) $quote['id']);

    if ($token !== null) {
        $quote['public_token'] = $token;
    }

    $publicQuoteUrl = quote_public_url($quote);
    $publicQuoteFileUrl = quote_file_is_available($quote) && !empty($quote['public_token'])
        ? url_path('/quote/file') . '?id=' . (int) $quote['id'] . '&token=' . rawurlencode((string) $quote['public_token'])
        : null;
}

$form = [
    'requester_name' => '',
    'email' => '',
    'phone' => '',
    'subject' => APP_NAME . ' árajánlat',
    'customer_message' => '',
    'fee_request_issuer' => quote_fee_request_default_issuer(),
];
$requestForm = normalize_connection_request_data($request ?? [
    'request_type' => 'phase_upgrade',
    'project_name' => '',
    'site_address' => '',
    'site_postal_code' => '',
    'notes' => '',
]);
$documentPrefillToken = document_prefill_token((string) ($_POST['document_prefill_token'] ?? ''));
$documentPrefillResult = null;
$documentPrefillCustomerForm = null;
$surveyForm = normalize_survey_data([
    'site_address' => trim((string) ($requestForm['site_postal_code'] ?? '') . ' ' . (string) ($requestForm['site_address'] ?? '')),
    'work_type' => connection_request_type_label($requestForm['request_type'] ?? 'phase_upgrade'),
]);
$selectedQuantities = [];
$customRows = array_fill(0, 3, []);

if ($quote !== null) {
    $form = [
        'requester_name' => (string) ($quote['requester_name'] ?? ''),
        'email' => (string) ($quote['email'] ?? ''),
        'phone' => (string) ($quote['phone'] ?? ''),
        'subject' => (string) ($quote['subject'] ?? (APP_NAME . ' árajánlat')),
        'customer_message' => (string) ($quote['customer_message'] ?? ''),
        'fee_request_issuer' => quote_fee_request_issuer_for_quote($quote),
    ];
    $survey = quote_survey(isset($quote['survey_id']) ? (int) $quote['survey_id'] : null);
    $surveyForm = normalize_survey_data($survey ?? ($request !== null ? connection_request_quote_survey_seed($request) : []));
} elseif ($request !== null) {
    $form = [
        'requester_name' => (string) ($request['requester_name'] ?? ''),
        'email' => (string) ($request['email'] ?? ''),
        'phone' => (string) ($request['phone'] ?? ''),
        'subject' => APP_NAME . ' árajánlat' . (!empty($request['project_name']) ? ' - ' . (string) $request['project_name'] : ''),
        'customer_message' => '',
        'fee_request_issuer' => quote_fee_request_default_issuer(),
    ];
    $requestForm = normalize_connection_request_data($request);
    $surveyForm = normalize_survey_data(connection_request_quote_survey_seed($request));
} elseif ($customer !== null) {
    $form = [
        'requester_name' => (string) ($customer['requester_name'] ?? ''),
        'email' => (string) ($customer['email'] ?? ''),
        'phone' => (string) ($customer['phone'] ?? ''),
        'subject' => APP_NAME . ' árajánlat',
        'customer_message' => '',
        'fee_request_issuer' => quote_fee_request_default_issuer(),
    ];
    $requestForm['site_address'] = (string) ($customer['postal_address'] ?? '');
    $requestForm['site_postal_code'] = (string) ($customer['postal_code'] ?? '');
    $surveyForm = normalize_survey_data([
        'site_address' => trim((string) ($customer['postal_code'] ?? '') . ' ' . (string) ($customer['city'] ?? '') . ' ' . (string) ($customer['postal_address'] ?? '')),
    ]);
}

$existingLines = $quote !== null ? quote_lines((int) $quote['id']) : [];
$activePriceItemIds = [];

foreach ($priceItems as $item) {
    $activePriceItemIds[(int) $item['id']] = true;
}

if ($quote !== null) {
    $customRows = [];
}

foreach ($existingLines as $line) {
    $priceItemId = isset($line['price_item_id']) ? (int) $line['price_item_id'] : 0;

    if ($priceItemId > 0 && isset($activePriceItemIds[$priceItemId])) {
        $selectedQuantities[$priceItemId] = quote_quantity_value((string) (int) round((float) $line['quantity']));
        continue;
    }

    $customRows[] = $line;
}

if ($customRows === []) {
    $customRows = array_fill(0, 3, []);
}

if (is_post()) {
    require_valid_csrf_token();

    $deleteFileId = filter_input(INPUT_POST, 'delete_request_file_id', FILTER_VALIDATE_INT);

    if ($request !== null && $deleteFileId) {
        $result = delete_connection_request_file((int) $deleteFileId, (int) $request['id']);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A fájl törlése sikertelen.'));
        redirect(quick_quote_current_redirect_path($quote, $request, $customer, $minicrmItemId ? (int) $minicrmItemId : null));
    }

    $postedAction = (string) ($_POST['action'] ?? '');
    $action = $postedAction === 'extract_document_prefill'
        ? 'extract_document_prefill'
        : (string) ($_POST['quick_action'] ?? 'save_quote');

    if ($quote === null && $action === 'extract_document_prefill') {
        $form = [
            'requester_name' => trim((string) ($_POST['requester_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'subject' => trim((string) ($_POST['subject'] ?? (APP_NAME . ' árajánlat'))),
            'customer_message' => trim((string) ($_POST['customer_message'] ?? '')),
            'fee_request_issuer' => normalize_quote_fee_request_issuer($_POST['fee_request_issuer'] ?? quote_fee_request_default_issuer()),
        ];
        $requestForm = normalize_connection_request_data($_POST);
        $customerSeed = normalize_customer_data($customer ?? [
            'requester_name' => $form['requester_name'],
            'email' => $form['email'],
            'phone' => $form['phone'],
            'source' => 'Gyors árajánlat',
            'status' => 'Árajánlat',
        ]);
        $customerSeed['requester_name'] = $form['requester_name'];
        $customerSeed['email'] = $form['email'];
        $customerSeed['phone'] = $form['phone'];
        $documentPrefillResult = handle_connection_request_document_prefill($documentPrefillToken, $_FILES, $customerSeed, $requestForm);
        $customerSeed = (array) ($documentPrefillResult['customer_form'] ?? $customerSeed);
        $documentPrefillCustomerForm = $customerSeed;
        $requestForm = (array) ($documentPrefillResult['request_form'] ?? $requestForm);
        $form['requester_name'] = (string) ($customerSeed['requester_name'] ?? $form['requester_name']);
        $form['email'] = (string) ($customerSeed['email'] ?? $form['email']);
        $form['phone'] = (string) ($customerSeed['phone'] ?? $form['phone']);
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
    }

    if ($quote !== null && in_array($action, ['pdf', 'send'], true)) {
        $result = $action === 'send' ? send_quote_email((int) $quote['id']) : generate_quote_pdf((int) $quote['id']);
        $message = quick_quote_customer_operation_message($result);
        set_flash($result['ok'] ? 'success' : 'error', $message);
        redirect('/quick-quote?quote_id=' . (int) $quote['id']);
    }

    if ($quote !== null && $action === 'verbal_accept_send') {
        $result = quick_quote_send_verbal_acceptance((int) $quote['id']);
        $message = quick_quote_customer_operation_message($result);
        set_flash($result['ok'] ? 'success' : 'error', $message);
        redirect('/quick-quote?quote_id=' . (int) $quote['id']);
    }

    if ($quote !== null && $action === 'fee_request') {
        $result = send_quote_fee_request_email((int) $quote['id']);
        $message = quick_quote_customer_operation_message($result);
        set_flash($result['ok'] ? 'success' : 'error', $message);
        redirect('/quick-quote?quote_id=' . (int) $quote['id']);
    }

    if ($quote !== null && $action === 'fee_request_sms') {
        $result = send_quote_fee_request_reminder_sms((int) $quote['id']);
        $message = quick_quote_customer_operation_message($result);
        set_flash($result['ok'] ? 'success' : 'error', $message);
        redirect('/quick-quote?quote_id=' . (int) $quote['id']);
    }

    if ($quote !== null && $action === 'save_quote') {
        $form = [
            'requester_name' => trim((string) ($_POST['requester_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'subject' => trim((string) ($_POST['subject'] ?? '')),
            'customer_message' => trim((string) ($_POST['customer_message'] ?? '')),
            'fee_request_issuer' => is_staff_user()
                ? normalize_quote_fee_request_issuer($_POST['fee_request_issuer'] ?? quote_fee_request_default_issuer())
                : quote_fee_request_issuer_for_quote($quote),
        ];
        $requestForm = normalize_connection_request_data($_POST);
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
        $quoteSubmit = (string) ($_POST['quote_submit'] ?? 'save');
        $shouldSendQuote = $quoteSubmit === 'send';
        $shouldAcceptAndSend = $quoteSubmit === 'accept_send';

        if ($form['requester_name'] === '') {
            $errors[] = 'A név megadása kötelező.';
        }

        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Érvényes email cím megadása kötelező.';
        }

        if ($form['phone'] === '') {
            $errors[] = 'A telefonszám megadása kötelező.';
        }

        if ($requestForm['site_address'] === '') {
            $errors[] = 'A cím megadása kötelező.';
        }

        if ($form['subject'] === '') {
            $errors[] = 'Az ajánlat tárgya kötelező.';
        }

        if ($lines === []) {
            $errors[] = 'Legalább egy ajánlati tételt adj meg.';
        }

        if ($errors === []) {
            try {
                if ($customer !== null) {
                    $billingAddress = quote_billing_address_parts(
                        (string) $requestForm['site_postal_code'],
                        (string) $requestForm['site_address'],
                        (string) ($customer['city'] ?? '')
                    );
                    $customerForm = normalize_customer_data($customer);
                    $customerForm['requester_name'] = $form['requester_name'];
                    $customerForm['email'] = $form['email'];
                    $customerForm['phone'] = $form['phone'];
                    $customerForm['postal_code'] = (string) $billingAddress['postal_code'];
                    $customerForm['city'] = (string) $billingAddress['city'];
                    $customerForm['postal_address'] = (string) $billingAddress['postal_address'];
                    update_customer((int) $customer['id'], $customerForm);
                }

                $savedQuoteId = save_quote((int) $quote['customer_id'], $form, $surveyForm, $lines, (int) $quote['id'], $requestId);
                $messages = ['Az árajánlat frissült.'];
                $flashType = 'success';

                if ($shouldAcceptAndSend) {
                    $acceptanceResult = quick_quote_send_verbal_acceptance($savedQuoteId);
                    $flashType = ($acceptanceResult['ok'] ?? false) ? $flashType : 'error';
                    $messages[] = quick_quote_customer_operation_message($acceptanceResult);
                } elseif ($shouldSendQuote) {
                    $mailResult = send_quote_email($savedQuoteId);
                    $flashType = ($mailResult['ok'] ?? false) ? $flashType : 'error';
                    $messages[] = quick_quote_customer_operation_message($mailResult);
                }

                set_flash($flashType, implode(' ', $messages));
                redirect('/quick-quote?quote_id=' . $savedQuoteId);
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az árajánlat mentése sikertelen.';
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
            'fee_request_issuer' => is_staff_user()
                ? normalize_quote_fee_request_issuer($_POST['fee_request_issuer'] ?? quote_fee_request_default_issuer())
                : quote_fee_request_default_issuer(),
        ];
        $requestForm = normalize_connection_request_data($_POST);
        $customerSeed = normalize_customer_data($customer ?? [
            'requester_name' => $form['requester_name'],
            'email' => $form['email'],
            'phone' => $form['phone'],
            'source' => 'Gyors árajánlat',
            'status' => 'Árajánlat',
        ]);
        $customerSeed['requester_name'] = $form['requester_name'];
        $customerSeed['email'] = $form['email'];
        $customerSeed['phone'] = $form['phone'];
        $regularPrefillResult = handle_connection_request_document_prefill_from_regular_uploads($_FILES, $customerSeed, $requestForm, true);

        if (!($regularPrefillResult['no_files'] ?? false)) {
            $documentPrefillResult = $regularPrefillResult;

            if (($documentPrefillResult['ok'] ?? false)) {
                $documentPrefillCustomerForm = (array) ($documentPrefillResult['customer_form'] ?? $customerSeed);
                $requestForm = (array) ($documentPrefillResult['request_form'] ?? $requestForm);
                $form['requester_name'] = (string) ($documentPrefillCustomerForm['requester_name'] ?? $form['requester_name']);
                $form['email'] = (string) ($documentPrefillCustomerForm['email'] ?? $form['email']);
                $form['phone'] = (string) ($documentPrefillCustomerForm['phone'] ?? $form['phone']);
            }
        }

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
        $quoteSubmit = (string) ($_POST['quote_submit'] ?? 'save');
        $shouldSendQuote = $quoteSubmit === 'send';

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

        $errors = array_merge($errors, validate_connection_request_data($requestForm, $_FILES, false, $requestId));

        if ($errors === []) {
            $billingAddress = quote_billing_address_parts(
                (string) $requestForm['site_postal_code'],
                (string) $requestForm['site_address'],
                $customer !== null ? (string) ($customer['city'] ?? '') : ''
            );
            $customerForm = [
                'is_legal_entity' => 0,
                'requester_name' => $form['requester_name'],
                'birth_name' => trim((string) (($documentPrefillCustomerForm['birth_name'] ?? '') ?: ($_POST['birth_name'] ?? ''))),
                'company_name' => '',
                'tax_number' => '',
                'phone' => $form['phone'],
                'email' => $form['email'],
                'postal_address' => (string) $billingAddress['postal_address'],
                'postal_code' => (string) $billingAddress['postal_code'],
                'city' => (string) $billingAddress['city'],
                'mailing_address' => '',
                'mother_name' => trim((string) (($documentPrefillCustomerForm['mother_name'] ?? '') ?: ($_POST['mother_name'] ?? ''))),
                'birth_place' => trim((string) (($documentPrefillCustomerForm['birth_place'] ?? '') ?: ($_POST['birth_place'] ?? ''))),
                'birth_date' => normalize_connection_request_mvm_source_date((string) (($documentPrefillCustomerForm['birth_date'] ?? '') ?: ($_POST['birth_date'] ?? ''))),
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
                'fee_request_issuer' => $form['fee_request_issuer'],
            ];

            try {
                if ($customer !== null) {
                    $existingCustomerForm = normalize_customer_data($customer);
                    $existingCustomerForm['requester_name'] = $customerForm['requester_name'];
                    $existingCustomerForm['phone'] = $customerForm['phone'];
                    $existingCustomerForm['email'] = $customerForm['email'];
                    $existingCustomerForm['postal_address'] = $customerForm['postal_address'];
                    $existingCustomerForm['postal_code'] = $customerForm['postal_code'];
                    $existingCustomerForm['city'] = $customerForm['city'];
                    foreach (['birth_name', 'mother_name', 'birth_place', 'birth_date'] as $customerField) {
                        if (trim((string) ($customerForm[$customerField] ?? '')) !== '') {
                            $existingCustomerForm[$customerField] = $customerForm[$customerField];
                        }
                    }
                    $customerForm = $existingCustomerForm;
                    $customerId = (int) $customer['id'];
                    update_customer($customerId, $customerForm);
                } else {
                    $customerId = create_customer($customerForm, null, is_array($user) ? (int) $user['id'] : null);
                }

                $savedRequestId = $request !== null
                    ? (int) $request['id']
                    : save_connection_request($customerId, $requestForm, null, is_array($user) ? (int) $user['id'] : null);
                document_prefill_attach_session_files($savedRequestId, $documentPrefillToken);
                $uploadMessages = handle_connection_request_uploads($savedRequestId, $_FILES, false, 'Gyors árajánlat');
                $savedQuoteId = save_quote($customerId, $quoteForm, $surveyForm, $lines, null, $savedRequestId);
                ensure_quote_public_token($savedQuoteId);
                $messages = [];
                $flashType = 'success';

                if ($shouldSendQuote) {
                    $mailResult = send_quote_email($savedQuoteId);
                    $mailMessage = quick_quote_customer_operation_message($mailResult);

                    if ($mailResult['ok']) {
                        $messages[] = 'A gyors árajánlat és a hozzá tartozó adatlap elkészült, az ajánlatot emailben elküldtük az ügyfélnek.';
                    } else {
                        $messages[] = 'A gyors árajánlat és a hozzá tartozó adatlap elkészült, de az email küldése nem sikerült: ' . $mailMessage . ' Az ajánlat oldalán az Email küldése gombbal újrapróbálható.';
                        $flashType = 'error';
                    }
                } else {
                    $messages[] = 'A gyors árajánlat és a hozzá tartozó adatlap elkészült. Email nem ment ki, az ügyfél ezen a képernyőn vagy az Árajánlat megnyitása gombbal azonnal megnézheti.';
                }

                if ($uploadMessages !== []) {
                    $messages[] = 'Néhány fájlt nem sikerült feltölteni: ' . implode(' ', $uploadMessages);
                    $flashType = 'error';
                }

                set_flash($flashType, implode(' ', $messages));
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
$feeRequestSelection = $quote !== null ? quote_fee_request_selection((int) $quote['id']) : null;
$feeRequestFileUrl = $quote !== null && quote_fee_request_file_is_available($quote)
    ? url_path('/admin/quotes/fee-request-file') . '?id=' . (int) $quote['id']
    : null;
$feeRequestSmsState = $quote !== null ? quote_fee_request_reminder_sms_state((int) $quote['id']) : null;
$latestFeeRequestSmsLog = $quote !== null ? quote_latest_sms_log((int) $quote['id']) : null;
$feeRequestIssuerLabel = $quote !== null ? quote_fee_request_issuer_label($quote['fee_request_issuer'] ?? quote_fee_request_default_issuer()) : quote_fee_request_issuer_label($form['fee_request_issuer']);
$feeRequestIssuerAgentKey = $quote !== null ? szamlazz_quote_fee_request_agent_key($quote) : '';
$verbalAcceptanceBlockedMessage = $quote !== null && (string) ($quote['status'] ?? '') !== 'accepted'
    ? quick_quote_verbal_acceptance_blocker($quote)
    : null;
$quickQuoteCreateAction = url_path('/quick-quote');

if ($quote === null) {
    $query = [];

    if ($request !== null) {
        $query['request_id'] = (int) $request['id'];
    } elseif ($customer !== null) {
        $query['customer_id'] = (int) $customer['id'];
    }

    if ($minicrmItemId) {
        $query['minicrm_item'] = (int) $minicrmItemId;
    }

    if ($query !== []) {
        $quickQuoteCreateAction .= '?' . http_build_query($query);
    }
}
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
                    <div><span>Díjbekérő</span><strong><?= h($feeRequestIssuerLabel); ?></strong></div>
                </div>

                <div class="admin-actions">
                    <?php if ($publicQuoteUrl !== null): ?><a class="button" href="<?= h($publicQuoteUrl); ?>" target="_blank">Árajánlat megnyitása</a><?php endif; ?>
                    <?php if ($publicQuoteFileUrl !== null): ?><a class="button button-secondary" href="<?= h($publicQuoteFileUrl); ?>" target="_blank" download>PDF letöltése</a><?php endif; ?>
                    <?php if ($publicQuoteUrl !== null): ?><button class="button button-secondary" type="button" data-share-quote-url="<?= h($publicQuoteUrl); ?>" data-share-quote-title="<?= h((string) ($quote['quote_number'] ?? 'Árajánlat')); ?>">Megosztás</button><?php endif; ?>
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
                    <?php if ((string) ($quote['status'] ?? '') !== 'accepted'): ?>
                        <form method="post" action="<?= h(url_path('/quick-quote') . '?quote_id=' . (int) $quote['id']); ?>">
                            <?= csrf_field(); ?>
                            <button class="button" name="quick_action" value="verbal_accept_send" type="submit" <?= $verbalAcceptanceBlockedMessage !== null ? 'disabled' : ''; ?> onclick="return confirm('Az ügyfél szóban elfogadta az ajánlatot? Emailt küldünk, majd elindul a díjbekérő.');">Elfogadva, email + díjbekérő</button>
                        </form>
                    <?php endif; ?>
                    <?php if ((string) ($quote['status'] ?? '') === 'accepted'): ?>
                        <?php if ($feeRequestFileUrl !== null): ?>
                            <a class="button button-secondary" href="<?= h($feeRequestFileUrl); ?>" target="_blank">Díjbekérő PDF</a>
                            <form method="post" action="<?= h(url_path('/quick-quote') . '?quote_id=' . (int) $quote['id']); ?>">
                                <?= csrf_field(); ?>
                                <button class="button button-secondary" name="quick_action" value="fee_request_sms" type="submit" <?= !($feeRequestSmsState['can_send'] ?? false) ? 'disabled' : ''; ?>>Díjbekérő SMS</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= h(url_path('/quick-quote') . '?quote_id=' . (int) $quote['id']); ?>">
                                <?= csrf_field(); ?>
                                <button class="button button-secondary" name="quick_action" value="fee_request" type="submit" <?= (!($feeRequestSelection['ok'] ?? false) || $feeRequestIssuerAgentKey === '') ? 'disabled' : ''; ?>>Díjbekérő küldése</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ((string) ($quote['status'] ?? '') === 'accepted' && $feeRequestFileUrl === null && $feeRequestIssuerAgentKey === ''): ?>
                    <div class="alert alert-info"><p>A kiválasztott díjbekérő-kibocsátóhoz nincs beállítva Számlázz.hu Agent kulcs.</p></div>
                <?php endif; ?>

                <?php if ((string) ($quote['status'] ?? '') !== 'accepted' && $verbalAcceptanceBlockedMessage !== null): ?>
                    <div class="alert alert-info"><p><?= h($verbalAcceptanceBlockedMessage); ?></p></div>
                <?php endif; ?>

                <?php if ((string) ($quote['status'] ?? '') === 'accepted' && $feeRequestFileUrl === null && $feeRequestSelection !== null && !($feeRequestSelection['ok'] ?? false)): ?>
                    <div class="alert alert-info"><p><?= h((string) ($feeRequestSelection['message'] ?? 'Ehhez az árajánlathoz nem készül díjbekérő.')); ?></p></div>
                <?php endif; ?>

                <?php if ((string) ($quote['status'] ?? '') === 'accepted' && $feeRequestFileUrl !== null && $feeRequestSmsState !== null && !($feeRequestSmsState['can_send'] ?? false)): ?>
                    <div class="alert alert-info"><p><?= h((string) ($feeRequestSmsState['message'] ?? 'A díjbekérő SMS jelenleg nem küldhető.')); ?></p></div>
                <?php endif; ?>

                <?php if ($latestFeeRequestSmsLog !== null): ?>
                    <div class="alert alert-info"><p>Legutóbbi díjbekérő SMS: <?= h((string) $latestFeeRequestSmsLog['created_at']); ?> · <?= h((string) $latestFeeRequestSmsLog['status']); ?> · <?= h((string) $latestFeeRequestSmsLog['recipient_phone']); ?></p></div>
                <?php endif; ?>

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

            <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/quick-quote') . '?quote_id=' . (int) $quote['id']); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="quick_action" value="save_quote">

                <div class="form-grid two">
                    <section class="auth-panel">
                        <h2>Ügyfél és ajánlat</h2>
                        <label for="requester_name">Név</label>
                        <input id="requester_name" name="requester_name" value="<?= h($form['requester_name']); ?>" required>

                        <label for="email">Email cím</label>
                        <input id="email" name="email" type="email" value="<?= h($form['email']); ?>" required>

                        <label for="phone">Telefonszám</label>
                        <input id="phone" name="phone" value="<?= h($form['phone']); ?>" required>

                        <label for="subject">Tárgy</label>
                        <input id="subject" name="subject" value="<?= h($form['subject']); ?>" required>

                        <label for="customer_message">Üzenet az ügyfélnek</label>
                        <textarea id="customer_message" name="customer_message" rows="4"><?= h($form['customer_message']); ?></textarea>

                        <?php if (is_staff_user()): ?>
                            <label for="fee_request_issuer">Díjbekérő kibocsátója</label>
                            <select id="fee_request_issuer" name="fee_request_issuer">
                                <?php foreach (quote_fee_request_issuer_options() as $issuerKey => $issuerOption): ?>
                                    <option value="<?= h((string) $issuerKey); ?>" <?= $form['fee_request_issuer'] === $issuerKey ? 'selected' : ''; ?>><?= h((string) $issuerOption['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>Az árajánlat elfogadásakor automatikusan ez a Számlázz.hu fiók állítja ki a díjbekérőt.</small>
                        <?php else: ?>
                            <input type="hidden" name="fee_request_issuer" value="<?= h($form['fee_request_issuer']); ?>">
                        <?php endif; ?>
                    </section>

                    <section class="auth-panel">
                        <h2>Adatlap adatai</h2>
                        <label for="request_type">Munka típusa</label>
                        <select id="request_type" name="request_type" data-request-type-select required>
                            <?php foreach (connection_request_type_options() as $type => $label): ?>
                                <option value="<?= h($type); ?>" <?= $requestForm['request_type'] === $type ? 'selected' : ''; ?>><?= h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label>Igény megnevezése</label><input name="project_name" value="<?= h($requestForm['project_name']); ?>">
                        <label>Kivitelezés címe</label><input name="site_address" value="<?= h($requestForm['site_address']); ?>" placeholder="Település, utca, házszám" required>
                        <label>Kivitelezés irányítószáma</label><input name="site_postal_code" value="<?= h($requestForm['site_postal_code']); ?>">
                        <div class="form-grid two compact">
                            <div><label>Helyrajzi szám</label><input name="hrsz" value="<?= h($requestForm['hrsz']); ?>"></div>
                            <div><label>Saját mérő gyári száma</label><input name="meter_serial" value="<?= h($requestForm['meter_serial']); ?>"></div>
                            <div><label>Meglévő teljesítmény</label><input name="existing_general_power" value="<?= h($requestForm['existing_general_power']); ?>"></div>
                            <div><label>Igényelt teljesítmény</label><input name="requested_general_power" value="<?= h($requestForm['requested_general_power']); ?>"></div>
                            <div><label>Meglévő H tarifa</label><input name="existing_h_tariff_power" value="<?= h($requestForm['existing_h_tariff_power']); ?>"></div>
                            <div><label>Igényelt H tarifa</label><input name="requested_h_tariff_power" value="<?= h($requestForm['requested_h_tariff_power']); ?>"></div>
                            <div><label>Meglévő vezérelt</label><input name="existing_controlled_power" value="<?= h($requestForm['existing_controlled_power']); ?>"></div>
                            <div><label>Igényelt vezérelt</label><input name="requested_controlled_power" value="<?= h($requestForm['requested_controlled_power']); ?>"></div>
                        </div>
                        <label>Megjegyzés az adatlaphoz</label>
                        <textarea name="notes" rows="3"><?= h($requestForm['notes']); ?></textarea>
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
                    <button class="button" name="quote_submit" value="save" type="submit">Árajánlat mentése</button>
                    <button class="button button-secondary" name="quote_submit" value="send" type="submit">Mentés és email küldése</button>
                    <?php if ((string) ($quote['status'] ?? '') !== 'accepted'): ?>
                        <button class="button button-secondary" name="quote_submit" value="accept_send" type="submit" onclick="return confirm('Az ügyfél szóban elfogadta az ajánlatot? Mentés után emailt küldünk, majd elindul a díjbekérő.');">Mentés + elfogadás</button>
                    <?php endif; ?>
                </div>
            </form>

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
            <form class="form" method="post" enctype="multipart/form-data" action="<?= h($quickQuoteCreateAction); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="quick_action" value="save_quote">
                <?php render_connection_request_document_prefill_panel($documentPrefillToken, $documentPrefillResult); ?>

                <div class="form-grid two">
                    <section class="auth-panel">
                        <h2>Ügyfél alapadatai</h2>
                        <label for="requester_name">Név</label>
                        <input id="requester_name" name="requester_name" value="<?= h($form['requester_name']); ?>" required>

                        <label for="email">Email cím</label>
                        <input id="email" name="email" type="email" value="<?= h($form['email']); ?>" required>

                        <label for="phone">Telefonszám</label>
                        <input id="phone" name="phone" value="<?= h($form['phone']); ?>" required>
                        <?php $quickPrefillCustomerForm = is_array($documentPrefillCustomerForm) ? $documentPrefillCustomerForm : (is_array($documentPrefillResult['customer_form'] ?? null) ? (array) $documentPrefillResult['customer_form'] : normalize_customer_data($customer ?? [])); ?>
                        <input type="hidden" name="birth_name" value="<?= h((string) ($quickPrefillCustomerForm['birth_name'] ?? '')); ?>">
                        <input type="hidden" name="mother_name" value="<?= h((string) ($quickPrefillCustomerForm['mother_name'] ?? '')); ?>">
                        <input type="hidden" name="birth_place" value="<?= h((string) ($quickPrefillCustomerForm['birth_place'] ?? '')); ?>">
                        <input type="hidden" name="birth_date" value="<?= h((string) ($quickPrefillCustomerForm['birth_date'] ?? '')); ?>">
                    </section>

                    <section class="auth-panel">
                        <h2>Ajánlat alapadatai</h2>
                        <label for="subject">Tárgy</label>
                        <input id="subject" name="subject" value="<?= h($form['subject']); ?>" required>

                        <label for="customer_message">Üzenet az ügyfélnek</label>
                        <textarea id="customer_message" name="customer_message" rows="8"><?= h($form['customer_message']); ?></textarea>

                        <?php if (is_staff_user()): ?>
                            <label for="fee_request_issuer">Díjbekérő kibocsátója</label>
                            <select id="fee_request_issuer" name="fee_request_issuer">
                                <?php foreach (quote_fee_request_issuer_options() as $issuerKey => $issuerOption): ?>
                                    <option value="<?= h((string) $issuerKey); ?>" <?= $form['fee_request_issuer'] === $issuerKey ? 'selected' : ''; ?>><?= h((string) $issuerOption['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>Az árajánlat elfogadásakor automatikusan ez a Számlázz.hu fiók állítja ki a díjbekérőt.</small>
                        <?php else: ?>
                            <input type="hidden" name="fee_request_issuer" value="<?= h($form['fee_request_issuer']); ?>">
                        <?php endif; ?>
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
                        <div><label>Kivitelezés címe</label><input name="site_address" value="<?= h($requestForm['site_address']); ?>" placeholder="Település, utca, házszám" required></div>
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

                <?php quick_quote_render_connection_request_upload_panel($requestId, $existingFiles, (string) $requestForm['request_type']); ?>

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
                    <button class="button" name="quote_submit" value="save" type="submit">Gyors árajánlat mentése</button>
                    <button class="button button-secondary" name="quote_submit" value="send" type="submit">Mentés és email küldése</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
<script>
(() => {
    const select = document.querySelector('[data-request-type-select]');
    const tariffInputs = document.querySelectorAll('[data-h-tariff-required]');

    if (!select) {
        return;
    }

    const syncHTariffFields = () => {
        tariffInputs.forEach((input) => {
            input.required = false;
        });
    };

    select.addEventListener('change', syncHTariffFields);
    syncHTariffFields();
})();

(() => {
    document.querySelectorAll('[data-share-quote-url]').forEach((button) => {
        const originalText = button.textContent || 'Megosztás';

        button.addEventListener('click', async () => {
            const url = button.dataset.shareQuoteUrl || '';
            const title = button.dataset.shareQuoteTitle || 'Árajánlat';

            if (!url) {
                return;
            }

            try {
                if (navigator.share) {
                    await navigator.share({
                        title,
                        text: title,
                        url,
                    });
                    return;
                }

                await navigator.clipboard.writeText(url);
                button.textContent = 'Link másolva';
                window.setTimeout(() => {
                    button.textContent = originalText;
                }, 2200);
            } catch (error) {
                button.textContent = 'Nem sikerült';
                window.setTimeout(() => {
                    button.textContent = originalText;
                }, 2200);
            }
        });
    });
})();
</script>
