<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$flash = get_flash();
$customers = [];
$requestsByCustomer = [];
$requestContexts = [];
$minicrmProfilesByCustomer = [];
$customerSaveErrors = [];
$requestStatusLabels = connection_request_status_labels();
$quoteStatusLabels = quote_status_labels();
$workflowStages = admin_workflow_stage_definitions();
$downloads = download_documents(true);
$canManageMvmDocuments = can_manage_mvm_documents();
$mvmSchemaErrors = mvm_document_schema_errors();
$electricianSchemaErrors = electrician_schema_errors();
$workflowStageSchemaReady = db_table_exists('connection_requests') && db_column_exists('connection_requests', 'admin_workflow_stage');

if (is_post() && ($_POST['action'] ?? '') === 'save_customer_card') {
    require_valid_csrf_token();

    $customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
    $customer = $customerId ? find_customer((int) $customerId) : null;
    $form = normalize_customer_data($_POST);
    $customerSaveErrors = validate_customer_data($form, false);

    if ($customer === null) {
        $customerSaveErrors[] = 'Az ügyfél nem található.';
    }

    if ($customerSaveErrors === []) {
        try {
            update_customer((int) $customerId, $form);
            set_flash('success', 'Az ügyféladatok mentve.');
            redirect('/admin/customers?customer=' . (int) $customerId . '#customer-' . (int) $customerId);
        } catch (Throwable $exception) {
            $customerSaveErrors[] = APP_DEBUG ? $exception->getMessage() : 'Az ügyfél mentése sikertelen.';
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_customer') {
    require_valid_csrf_token();

    if (!is_admin_user()) {
        set_flash('error', 'Ügyfelet törölni csak admin jogosultsággal lehet.');
        redirect('/admin/customers');
    }

    $deleteCustomerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);

    if (!$deleteCustomerId) {
        set_flash('error', 'Az ügyfél nem található.');
        redirect('/admin/customers');
    }

    try {
        $deleteSummary = delete_customer_with_related_data((int) $deleteCustomerId);
        set_flash(
            'success',
            'Az ügyfél törölve: ' . $deleteSummary['customer_name']
                . '. Kapcsolódó adatok: ' . (int) $deleteSummary['requests'] . ' igény, '
                . (int) $deleteSummary['quotes'] . ' árajánlat, '
                . (int) $deleteSummary['users'] . ' felhasználói fiók, '
                . (int) $deleteSummary['files'] . ' fájl.'
        );
    } catch (Throwable $exception) {
        set_flash('error', APP_DEBUG ? $exception->getMessage() : 'Az ügyfél törlése sikertelen.');
    }

    redirect('/admin/customers');
}

try {
    $customers = all_customers();

    foreach ($customers as $customer) {
        $customerId = (int) $customer['id'];
        $customerRequests = connection_requests_for_customer($customerId);
        $requestsByCustomer[$customerId] = $customerRequests;
        $minicrmProfilesByCustomer[$customerId] = minicrm_customer_profiles_for_customer($customerId);

        foreach ($customerRequests as $request) {
            $requestId = (int) $request['id'];
            $mvmDocuments = $canManageMvmDocuments && $mvmSchemaErrors === [] ? connection_request_documents($requestId) : [];
            $quotes = quotes_for_connection_request($requestId);
            $acceptedQuote = accepted_quote_for_connection_request($requestId)
                ?? accepted_quote_for_registration_duplicate_request($requestId);
            $latestQuote = $quotes[0] ?? null;

            if ($latestQuote === null && $acceptedQuote !== null) {
                $latestQuote = $acceptedQuote;
                $quotes = [$acceptedQuote];
            }

            $workflowStage = connection_request_admin_workflow_stage($request, $latestQuote, $acceptedQuote, $mvmDocuments);
            $requestContexts[$requestId] = [
                'files' => connection_request_files($requestId),
                'before_work_files' => $electricianSchemaErrors === [] ? connection_request_work_files($requestId, 'before') : [],
                'after_work_files' => $electricianSchemaErrors === [] ? connection_request_work_files($requestId, 'after') : [],
                'mvm_documents' => $mvmDocuments,
                'quotes' => $quotes,
                'accepted_quote' => $acceptedQuote,
                'latest_quote' => $latestQuote,
                'workflow_stage' => $workflowStage,
                'workflow_stage_definition' => $workflowStages[$workflowStage] ?? null,
            ];
        }
    }
} catch (Throwable $exception) {
    $flash = ['type' => 'error', 'message' => APP_DEBUG ? $exception->getMessage() : 'Az ügyfelek betöltése sikertelen.'];
}

$selectedCustomerId = isset($_GET['customer']) ? max(0, (int) $_GET['customer']) : 0;
$selectedRequestId = isset($_GET['request']) ? max(0, (int) $_GET['request']) : 0;
$customersByStatus = [];

foreach ($customers as $customer) {
    if ($selectedCustomerId === 0) {
        $selectedCustomerId = (int) $customer['id'];
    }

    $statusName = trim((string) ($customer['status'] ?? '')) ?: 'Új ügyfél';
    $customersByStatus[$statusName][] = $customer;
}

uasort($customersByStatus, static fn (array $a, array $b): int => count($b) <=> count($a));

function customer_crm_dom_id(string $value): string
{
    $id = preg_replace('/[^a-z0-9]+/', '-', minicrm_import_lower($value)) ?: '';
    $id = trim($id, '-');

    return $id !== '' ? $id : 'nincs-statusz';
}

function customer_crm_short_text(string $value, int $length = 130): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');
    $stringLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

    if ($value === '' || $stringLength <= $length) {
        return $value;
    }

    $substring = function_exists('mb_substr') ? mb_substr($value, 0, $length - 1) : substr($value, 0, $length - 1);

    return rtrim($substring) . '…';
}

function customer_crm_date_label(?string $value): string
{
    $timestamp = $value !== null && trim($value) !== '' ? strtotime($value) : false;

    return $timestamp !== false ? date('Y.m.d. H:i', $timestamp) : '-';
}

function customer_crm_primary_request(array $requests): ?array
{
    return $requests[0] ?? null;
}

function customer_crm_timeline_events(array $customer, array $requests, array $requestContexts): array
{
    $events = [[
        'date' => (string) ($customer['created_at'] ?? ''),
        'title' => 'Ügyfél létrejött',
        'actor' => customer_owner_label($customer),
        'body' => trim((string) ($customer['source'] ?? '')) ?: 'Regisztrált vagy admin által rögzített ügyfél.',
        'kind' => 'customer',
    ]];

    foreach ($requests as $request) {
        $requestId = (int) $request['id'];
        $context = $requestContexts[$requestId] ?? [];
        $projectName = trim((string) ($request['project_name'] ?? '')) ?: 'Mérőhelyi igény #' . $requestId;

        $events[] = [
            'date' => (string) ($request['created_at'] ?? ''),
            'title' => 'Igény rögzítve',
            'actor' => $projectName,
            'body' => connection_request_type_label($request['request_type'] ?? null),
            'kind' => 'request',
        ];

        if (!empty($request['closed_at'])) {
            $events[] = [
                'date' => (string) $request['closed_at'],
                'title' => 'Igény lezárva',
                'actor' => $projectName,
                'body' => 'Az ügyfél véglegesítette az igénybejelentést.',
                'kind' => 'request',
            ];
        }

        foreach (($context['quotes'] ?? []) as $quote) {
            $quoteStatus = (string) ($quote['status'] ?? 'draft');
            $events[] = [
                'date' => (string) ($quote['accepted_at'] ?: $quote['sent_at'] ?: $quote['created_at'] ?? ''),
                'title' => 'Árajánlat: ' . (string) ($quote['quote_number'] ?? ''),
                'actor' => quote_display_total($quote),
                'body' => (string) ($quote['subject'] ?? '') . ' · ' . $quoteStatus,
                'kind' => 'quote',
            ];
        }

        $documentCount = count($context['mvm_documents'] ?? []);
        if ($documentCount > 0) {
            $events[] = [
                'date' => (string) (($context['mvm_documents'][0]['created_at'] ?? '') ?: ($request['updated_at'] ?? '')),
                'title' => 'MVM dokumentumok',
                'actor' => $projectName,
                'body' => $documentCount . ' db MVM dokumentum vagy generált csomag kapcsolva.',
                'kind' => 'document',
            ];
        }
    }

    usort($events, static function (array $a, array $b): int {
        return (strtotime((string) ($b['date'] ?? '')) ?: 0) <=> (strtotime((string) ($a['date'] ?? '')) ?: 0);
    });

    return $events;
}
?>
<section class="admin-section customer-crm-page">
    <div class="container admin-requests-container">
        <div class="admin-header customer-crm-hero">
            <div>
                <p class="eyebrow">Admin CRM</p>
                <h1>Ügyfelek és munkák</h1>
                <p>MiniCRM-szerű ügyféladatlapok a meglévő MVM dokumentum, meghatalmazás, fotó és árajánlat funkciókkal.</p>
            </div>
            <div class="form-actions">
                <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
                <a class="button" href="<?= h(url_path('/admin/customers/edit')); ?>">Új ügyfél</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($customerSaveErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($customerSaveErrors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($customers === []): ?>
            <div class="empty-state"><h2>Még nincs ügyfél</h2><p>Hozd létre az első ügyfelet, vagy várj ügyfélregisztrációra.</p></div>
        <?php else: ?>
            <section class="admin-workflow-stage minicrm-workspace customer-crm-workspace">
                <div class="minicrm-list-tools">
                    <label for="customer-crm-search">Keresés ügyfél, cím, email, munka vagy státusz alapján</label>
                    <input id="customer-crm-search" type="search" placeholder="Keresés..." data-customer-crm-search>
                    <span data-customer-crm-count><?= count($customers); ?> db</span>
                </div>

                <nav class="minicrm-status-nav" aria-label="Ügyfél státuszok">
                    <?php foreach ($customersByStatus as $statusName => $statusCustomers): ?>
                        <a href="#customer-status-<?= h(customer_crm_dom_id((string) $statusName)); ?>">
                            <?= h((string) $statusName); ?>
                            <strong><?= count($statusCustomers); ?></strong>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="minicrm-status-groups" data-customer-crm-list>
                    <?php foreach ($customersByStatus as $statusName => $statusCustomers): ?>
                        <?php $statusClass = minicrm_status_class((string) $statusName); ?>
                        <section class="minicrm-status-group" id="customer-status-<?= h(customer_crm_dom_id((string) $statusName)); ?>" data-customer-crm-status-group>
                            <header class="minicrm-status-group-head">
                                <div>
                                    <span class="status-badge status-badge-<?= h($statusClass); ?>"><?= h((string) $statusName); ?></span>
                                    <strong><?= count($statusCustomers); ?> ügyfél</strong>
                                </div>
                                <span data-customer-crm-status-count><?= count($statusCustomers); ?> látható</span>
                            </header>

                            <div class="minicrm-work-table" role="table" aria-label="<?= h((string) $statusName); ?> ügyfelek">
                                <div class="minicrm-work-table-head" role="row">
                                    <span>Ügyfél / munka</span>
                                    <span>Felelős</span>
                                    <span>Regisztráció</span>
                                    <span>Anyag</span>
                                </div>

                                <?php foreach ($statusCustomers as $customer): ?>
                                    <?php
                                    $customerId = (int) $customer['id'];
                                    $customerRequests = $requestsByCustomer[$customerId] ?? [];
                                    $primaryRequest = customer_crm_primary_request($customerRequests);
                                    $isSelectedCustomer = $customerId === $selectedCustomerId;
                                    $customerAddress = trim((string) ($customer['postal_code'] ?? '') . ' ' . (string) ($customer['city'] ?? '') . ', ' . (string) ($customer['postal_address'] ?? ''));
                                    $ownerLabel = customer_owner_label($customer);
                                    $detailUrl = url_path('/admin/customers') . '?customer=' . $customerId . '#customer-' . $customerId;
                                    $requestCount = count($customerRequests);
                                    $documentCount = 0;
                                    $quoteCount = 0;
                                    $fileCount = 0;
                                    $customerSearchText = implode(' ', [
                                        (string) ($customer['requester_name'] ?? ''),
                                        (string) ($customer['email'] ?? ''),
                                        (string) ($customer['phone'] ?? ''),
                                        $customerAddress,
                                        (string) ($customer['status'] ?? ''),
                                        $ownerLabel,
                                    ]);

                                    foreach ($customerRequests as $request) {
                                        $context = $requestContexts[(int) $request['id']] ?? [];
                                        $documentCount += count($context['mvm_documents'] ?? []);
                                        $quoteCount += count($context['quotes'] ?? []);
                                        $fileCount += count($context['files'] ?? []) + count($context['before_work_files'] ?? []) + count($context['after_work_files'] ?? []);
                                        $customerSearchText .= ' ' . (string) ($request['project_name'] ?? '') . ' ' . (string) ($request['site_address'] ?? '');
                                    }

                                    $rowTitle = $primaryRequest !== null && trim((string) ($primaryRequest['project_name'] ?? '')) !== ''
                                        ? (string) $primaryRequest['project_name']
                                        : (string) ($customer['requester_name'] ?? 'Ügyfél');
                                    ?>
                                    <details class="admin-workflow-request minicrm-work-row customer-crm-row" id="customer-<?= $customerId; ?>" data-customer-crm-item data-customer-crm-search-text="<?= h($customerSearchText); ?>" data-customer-crm-loaded="<?= $isSelectedCustomer ? '1' : '0'; ?>" data-customer-crm-detail-url="<?= h($detailUrl); ?>" <?= $isSelectedCustomer ? 'open' : ''; ?>>
                                        <summary class="admin-workflow-request-summary minicrm-work-row-summary">
                                            <span class="admin-workflow-request-main">
                                                <strong><?= h(customer_crm_short_text((string) $customer['requester_name'], 72)); ?></strong>
                                                <small><?= h($rowTitle); ?></small>
                                            </span>
                                            <span class="admin-workflow-request-meta">
                                                <span><?= h($ownerLabel); ?></span>
                                                <strong><?= h((string) ($customer['email'] ?? '')); ?></strong>
                                            </span>
                                            <span class="minicrm-work-date"><?= h(customer_crm_date_label($customer['created_at'] ?? null)); ?></span>
                                            <span class="admin-workflow-request-badges">
                                                <strong><?= $requestCount; ?> munka</strong>
                                                <small><?= $fileCount; ?> fájl · <?= $documentCount; ?> MVM · <?= $quoteCount; ?> ajánlat</small>
                                            </span>
                                        </summary>

                                        <?php if (!$isSelectedCustomer): ?>
                                            <div class="minicrm-work-card minicrm-work-card-placeholder">
                                                <p class="request-admin-empty">Az ügyfél teljes adatlapjához kattints a sorra; a részletek külön töltődnek be, hogy a lista gyors maradjon.</p>
                                                <a class="button button-secondary" href="<?= h($detailUrl); ?>">Adatlap megnyitása</a>
                                            </div>
                                        <?php else: ?>
                                            <?php
                                            $selectedRequest = null;
                                            foreach ($customerRequests as $requestCandidate) {
                                                if ($selectedRequestId > 0 && (int) $requestCandidate['id'] === $selectedRequestId) {
                                                    $selectedRequest = $requestCandidate;
                                                    break;
                                                }
                                            }
                                            if ($selectedRequest === null) {
                                                $selectedRequest = $primaryRequest;
                                            }
                                            $timelineEvents = customer_crm_timeline_events($customer, $customerRequests, $requestContexts);
                                            $customerForm = normalize_customer_data($customer);
                                            $minicrmProfiles = $minicrmProfilesByCustomer[$customerId] ?? [];
                                            ?>
                                            <article class="request-admin-card minicrm-work-card customer-crm-card">
                                                <div class="request-admin-card-head">
                                                    <div>
                                                        <span class="portal-kicker">Ügyfél azonosító: #<?= $customerId; ?></span>
                                                        <h2><?= h((string) $customer['requester_name']); ?></h2>
                                                        <p><?= h($customerAddress !== '' ? $customerAddress : (string) ($customer['email'] ?? '')); ?></p>
                                                    </div>
                                                    <div class="request-admin-status">
                                                        <span class="status-badge status-badge-<?= h($statusClass); ?>"><?= h((string) ($customer['status'] ?: 'Új ügyfél')); ?></span>
                                                        <span class="status-badge status-badge-finalized"><?= h($ownerLabel); ?></span>
                                                    </div>
                                                </div>

                                                <div class="minicrm-work-detail-layout">
                                                    <aside class="minicrm-work-facts">
                                                        <dl>
                                                            <div><dt>Név</dt><dd><?= h((string) ($customer['requester_name'] ?: '-')); ?></dd></div>
                                                            <div><dt>Email</dt><dd><?= h((string) ($customer['email'] ?: '-')); ?></dd></div>
                                                            <div><dt>Telefon</dt><dd><?= h((string) ($customer['phone'] ?: '-')); ?></dd></div>
                                                            <div><dt>Cím</dt><dd><?= h($customerAddress !== '' ? $customerAddress : '-'); ?></dd></div>
                                                            <div><dt>Státusz</dt><dd><?= h((string) ($customer['status'] ?: '-')); ?></dd></div>
                                                            <div><dt>Munkák</dt><dd><?= $requestCount; ?> db</dd></div>
                                                            <div><dt>Árajánlatok</dt><dd><?= $quoteCount; ?> db</dd></div>
                                                        </dl>

                                                        <section class="minicrm-compact-docs">
                                                            <h3>Gyors műveletek <span><?= $requestCount; ?></span></h3>
                                                            <div>
                                                                <a href="<?= h(url_path('/admin/connection-requests/edit') . '?customer_id=' . $customerId); ?>">Új munka / igény<span>Igény</span></a>
                                                                <a href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . $customerId . ($selectedRequest !== null ? '&request_id=' . (int) $selectedRequest['id'] : '')); ?>">Árajánlat minimális adatokkal<span>Ajánlat</span></a>
                                                                <a href="<?= h(url_path('/admin/customers/edit') . '?id=' . $customerId); ?>">Régi ügyfél űrlap<span>Edit</span></a>
                                                            </div>
                                                        </section>
                                                    </aside>

                                                    <div class="minicrm-work-main">
                                                        <section class="minicrm-timeline-panel">
                                                            <div class="admin-request-section-title">
                                                                <h3>Előzmények</h3>
                                                                <span><?= count($timelineEvents); ?> esemény</span>
                                                            </div>
                                                            <ol class="minicrm-timeline">
                                                                <?php foreach ($timelineEvents as $event): ?>
                                                                    <li class="minicrm-timeline-event minicrm-timeline-<?= h((string) $event['kind']); ?>">
                                                                        <time><?= h(customer_crm_date_label($event['date'] ?? null)); ?></time>
                                                                        <div>
                                                                            <strong><?= h((string) $event['title']); ?></strong>
                                                                            <span><?= h((string) $event['actor']); ?></span>
                                                                            <p><?= h((string) $event['body']); ?></p>
                                                                        </div>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ol>
                                                        </section>

                                                        <section class="minicrm-document-preview-panel customer-crm-minicrm-profile">
                                                            <div class="admin-request-section-title">
                                                                <h3>MiniCRM &#252;gyf&#233;l adatlap</h3>
                                                                <span><?= count($minicrmProfiles); ?> adatlap</span>
                                                            </div>
                                                            <?php if ($minicrmProfiles === []): ?>
                                                                <p class="request-admin-empty">Ehhez az &#252;gyf&#233;lhez m&#233;g nincs import&#225;lt MiniCRM &#252;gyf&#233;l adatlap.</p>
                                                            <?php else: ?>
                                                                <div class="minicrm-readable-groups">
                                                                    <?php foreach ($minicrmProfiles as $profile): ?>
                                                                        <?php
                                                                        $profileFields = minicrm_customer_profile_raw_fields($profile);
                                                                        $profileTitle = trim((string) ($profile['card_name'] ?? '')) ?: 'MiniCRM adatlap';
                                                                        ?>
                                                                        <details class="minicrm-field-group" open>
                                                                            <summary>
                                                                                <strong><?= h($profileTitle); ?></strong>
                                                                                <span><?= h((string) ($profile['source_id'] ?? '')); ?></span>
                                                                            </summary>
                                                                            <div class="admin-request-section-title">
                                                                                <h4>Szem&#233;ly / el&#233;rhet&#337;s&#233;g</h4>
                                                                                <span><?= h((string) (($profile['person_type'] ?? '') ?: 'MiniCRM')); ?></span>
                                                                            </div>
                                                                            <div class="minicrm-readable-grid">
                                                                                <div class="minicrm-readable-row"><span>N&#233;v</span><strong><?= h((string) (($profile['person_name'] ?? '') ?: (($profile['card_name'] ?? '') ?: '-'))); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Vezet&#233;kn&#233;v</span><strong><?= h((string) (($profile['person_last_name'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Keresztn&#233;v</span><strong><?= h((string) (($profile['person_first_name'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Email</span><strong><?= h((string) (($profile['person_email'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Telefon</span><strong><?= h((string) (($profile['person_phone'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Beoszt&#225;s</span><strong><?= h((string) (($profile['person_position'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Weboldal</span><strong><?= h((string) (($profile['person_website'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Adatkezel&#233;si hozz&#225;j&#225;rul&#225;s</span><strong><?= h((string) (($profile['person_consent'] ?? '') ?: '-')); ?></strong></div>
                                                                                <?php if (trim((string) ($profile['person_summary'] ?? '')) !== ''): ?>
                                                                                    <div class="minicrm-readable-row customer-crm-wide"><span>&#214;sszefoglal&#243;</span><strong><?= h((string) ($profile['person_summary'] ?? '')); ?></strong></div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <div class="admin-request-section-title">
                                                                                <h4>MiniCRM adatlap</h4>
                                                                                <span><?= h((string) (($profile['minicrm_status'] ?? '') ?: '-')); ?></span>
                                                                            </div>
                                                                            <div class="minicrm-readable-grid">
                                                                                <div class="minicrm-readable-row"><span>MiniCRM azonos&#237;t&#243;</span><strong><?= h((string) ($profile['source_id'] ?? '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Projekt ID</span><strong><?= h((string) (($profile['project_id'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Felel&#337;s</span><strong><?= h((string) (($profile['responsible'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>St&#225;tusz</span><strong><?= h((string) (($profile['minicrm_status'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>St&#225;tuszcsoport</span><strong><?= h((string) (($profile['status_group'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>St&#225;tusz m&#243;dos&#237;tva</span><strong><?= h((string) (($profile['status_updated_at'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Adatlap r&#246;gz&#237;t&#337;</span><strong><?= h((string) (($profile['created_by_name'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Adatlap r&#246;gz&#237;t&#233;s</span><strong><?= h((string) (($profile['created_date'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Adatlap m&#243;dos&#237;t&#243;</span><strong><?= h((string) (($profile['modified_by_name'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Adatlap m&#243;dos&#237;t&#225;s</span><strong><?= h((string) (($profile['modified_date'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Szem&#233;ly r&#246;gz&#237;t&#337;</span><strong><?= h((string) (($profile['person_created_by_name'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Szem&#233;ly r&#246;gz&#237;t&#233;s</span><strong><?= h((string) (($profile['person_created_date'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Szem&#233;ly m&#243;dos&#237;t&#243;</span><strong><?= h((string) (($profile['person_modified_by_name'] ?? '') ?: '-')); ?></strong></div>
                                                                                <div class="minicrm-readable-row"><span>Szem&#233;ly m&#243;dos&#237;t&#225;s</span><strong><?= h((string) (($profile['person_modified_date'] ?? '') ?: '-')); ?></strong></div>
                                                                                <?php if (trim((string) ($profile['card_url'] ?? '')) !== ''): ?>
                                                                                    <div class="minicrm-readable-row customer-crm-wide"><span>MiniCRM link</span><strong><a href="<?= h((string) $profile['card_url']); ?>" target="_blank" rel="noopener">Adatlap megnyit&#225;sa</a></strong></div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <?php if ($profileFields !== []): ?>
                                                                                <details class="minicrm-field-group">
                                                                                    <summary><strong>Nyers import mez&#337;k</strong><span><?= count($profileFields); ?> mez&#337;</span></summary>
                                                                                    <div class="minicrm-readable-grid">
                                                                                        <?php foreach ($profileFields as $field): ?>
                                                                                            <div class="minicrm-readable-row"><span><?= h((string) $field['label']); ?></span><strong><?= h((string) $field['value']); ?></strong></div>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                </details>
                                                                            <?php endif; ?>
                                                                        </details>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </section>

                                                        <form class="minicrm-readable-groups minicrm-field-groups customer-crm-edit-form" method="post" action="<?= h(url_path('/admin/customers') . '?customer=' . $customerId . '#customer-' . $customerId); ?>">
                                                            <?= csrf_field(); ?>
                                                            <input type="hidden" name="action" value="save_customer_card">
                                                            <input type="hidden" name="customer_id" value="<?= $customerId; ?>">
                                                            <details class="minicrm-field-group" open>
                                                                <summary><strong>Ügyféladatok</strong><span>18 mező</span></summary>
                                                                <div class="minicrm-readable-grid customer-crm-edit-grid">
                                                                    <label class="minicrm-readable-row minicrm-editable-row customer-crm-checkbox">
                                                                        <span>Jogi személy</span>
                                                                        <input type="checkbox" name="is_legal_entity" value="1" <?= (int) $customerForm['is_legal_entity'] === 1 ? 'checked' : ''; ?>>
                                                                    </label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Név</span><input name="requester_name" value="<?= h($customerForm['requester_name']); ?>" required></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Születési név</span><input name="birth_name" value="<?= h($customerForm['birth_name']); ?>"></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Cégnév</span><input name="company_name" value="<?= h($customerForm['company_name']); ?>"></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Adószám</span><input name="tax_number" value="<?= h($customerForm['tax_number']); ?>"></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Telefon</span><input name="phone" value="<?= h($customerForm['phone']); ?>" required></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Email</span><input name="email" type="email" value="<?= h($customerForm['email']); ?>" required></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Postai cím</span><input name="postal_address" value="<?= h($customerForm['postal_address']); ?>" required></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Irányítószám</span><input name="postal_code" value="<?= h($customerForm['postal_code']); ?>" required></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Település</span><input name="city" value="<?= h($customerForm['city']); ?>" required></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Levelezési cím</span><input name="mailing_address" value="<?= h($customerForm['mailing_address']); ?>"></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Anyja neve</span><input name="mother_name" value="<?= h($customerForm['mother_name']); ?>"></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Születési hely</span><input name="birth_place" value="<?= h($customerForm['birth_place']); ?>"></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Születési idő</span><input name="birth_date" type="date" value="<?= h($customerForm['birth_date']); ?>"></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Forrás</span><input name="source" value="<?= h($customerForm['source']); ?>"></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row"><span>Státusz</span><input name="status" value="<?= h($customerForm['status']); ?>"></label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row customer-crm-checkbox">
                                                                        <span>Kapcsolattartási adatok egyeznek</span>
                                                                        <input type="checkbox" name="contact_data_accepted" value="1" <?= (int) $customerForm['contact_data_accepted'] === 1 ? 'checked' : ''; ?>>
                                                                    </label>
                                                                    <label class="minicrm-readable-row minicrm-editable-row customer-crm-wide"><span>Megjegyzés</span><textarea name="notes" rows="4"><?= h($customerForm['notes']); ?></textarea></label>
                                                                </div>
                                                            </details>
                                                            <div class="minicrm-field-edit-actions minicrm-field-edit-actions-bottom">
                                                                <span>Az új regisztrációk is ilyen ügyfélkártyán jelennek meg, innen indítható a munka és az ajánlat.</span>
                                                                <button class="button button-primary" type="submit">Ügyféladatok mentése</button>
                                                            </div>
                                                        </form>

                                                        <section class="minicrm-document-preview-panel customer-crm-downloads">
                                                            <div class="admin-request-section-title">
                                                                <h3>Letölthető dokumentumok</h3>
                                                                <span><?= count($downloads); ?> sablon</span>
                                                            </div>
                                                            <?php if ($downloads === []): ?>
                                                                <p class="request-admin-empty">Nincs aktív letölthető dokumentum.</p>
                                                            <?php else: ?>
                                                                <div class="inline-link-list">
                                                                    <?php foreach ($downloads as $document): ?>
                                                                        <a href="<?= h(url_path('/documents/file') . '?id=' . (int) $document['id']); ?>" target="_blank"><?= h((string) $document['title']); ?></a>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </section>

                                                        <section class="customer-crm-request-stack">
                                                            <div class="admin-request-section-title">
                                                                <h3>Munkák és funkciók</h3>
                                                                <span><?= $requestCount; ?> munka</span>
                                                            </div>

                                                            <?php if ($customerRequests === []): ?>
                                                                <div class="minicrm-document-preview-panel">
                                                                    <p class="request-admin-empty">Ehhez az ügyfélhez még nincs mérőhelyi igény. Új regisztráció után innen indítható az első MiniCRM-szerű munkaadatlap.</p>
                                                                    <div class="customer-crm-actions">
                                                                        <a class="button" href="<?= h(url_path('/admin/connection-requests/edit') . '?customer_id=' . $customerId); ?>">Új munka / igény</a>
                                                                        <a class="button button-secondary" href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . $customerId); ?>">Árajánlat minimális adatokkal</a>
                                                                    </div>
                                                                </div>
                                                            <?php else: ?>
                                                                <?php foreach ($customerRequests as $request): ?>
                                                                    <?php
                                                                    $requestId = (int) $request['id'];
                                                                    $context = $requestContexts[$requestId] ?? [];
                                                                    $mvmDocuments = $context['mvm_documents'] ?? [];
                                                                    $files = $context['files'] ?? [];
                                                                    $beforeWorkFiles = $context['before_work_files'] ?? [];
                                                                    $afterWorkFiles = $context['after_work_files'] ?? [];
                                                                    $quotes = $context['quotes'] ?? [];
                                                                    $acceptedQuote = $context['accepted_quote'] ?? null;
                                                                    $latestQuote = $context['latest_quote'] ?? null;
                                                                    $workflowStageKey = (string) ($context['workflow_stage'] ?? '');
                                                                    $workflowStageDefinition = $context['workflow_stage_definition'] ?? null;
                                                                    $requestStatus = (string) ($request['request_status'] ?? 'draft');
                                                                    $requestAddress = trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));
                                                                    ?>
                                                                    <article class="minicrm-document-preview-panel customer-crm-request-card" id="request-<?= $requestId; ?>">
                                                                        <div class="customer-crm-request-head">
                                                                            <div>
                                                                                <span class="portal-kicker">Munka #<?= $requestId; ?></span>
                                                                                <h3><?= h((string) ($request['project_name'] ?: 'Mérőhelyi igény #' . $requestId)); ?></h3>
                                                                                <p><?= h(connection_request_type_label($request['request_type'] ?? null)); ?> · <?= h($requestAddress !== '' ? $requestAddress : '-'); ?></p>
                                                                            </div>
                                                                            <div class="request-admin-status">
                                                                                <span class="status-badge status-badge-<?= h($requestStatus); ?>"><?= h($requestStatusLabels[$requestStatus] ?? $requestStatus); ?></span>
                                                                                <?php if ($workflowStageDefinition !== null): ?>
                                                                                    <span class="status-badge status-badge-<?= h(minicrm_status_class((string) $workflowStageDefinition['title'])); ?>"><?= h((string) $workflowStageDefinition['title']); ?></span>
                                                                                <?php endif; ?>
                                                                                <?php if ($acceptedQuote !== null): ?>
                                                                                    <span class="status-badge status-badge-accepted">Ajánlat elfogadva</span>
                                                                                <?php elseif ($latestQuote !== null): ?>
                                                                                    <?php $latestQuoteStatus = (string) ($latestQuote['status'] ?? 'draft'); ?>
                                                                                    <span class="status-badge status-badge-<?= h($latestQuoteStatus); ?>"><?= h($quoteStatusLabels[$latestQuoteStatus] ?? $latestQuoteStatus); ?></span>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </div>

                                                                        <div class="customer-crm-request-facts">
                                                                            <div><span>Cím</span><strong><?= h($requestAddress !== '' ? $requestAddress : '-'); ?></strong></div>
                                                                            <div><span>HRSZ</span><strong><?= h((string) ($request['hrsz'] ?: '-')); ?></strong></div>
                                                                            <div><span>Mérő</span><strong><?= h((string) ($request['meter_serial'] ?: '-')); ?></strong></div>
                                                                            <div><span>Fogyasztási hely</span><strong><?= h((string) ($request['consumption_place_id'] ?: '-')); ?></strong></div>
                                                                            <div><span>Igényelt teljesítmény</span><strong><?= h((string) ($request['requested_general_power'] ?: '-')); ?></strong></div>
                                                                            <div><span>Frissítve</span><strong><?= h(customer_crm_date_label($request['updated_at'] ?? $request['created_at'] ?? null)); ?></strong></div>
                                                                        </div>

                                                                        <div class="customer-crm-actions">
                                                                            <?php if ($canManageMvmDocuments): ?>
                                                                                <a class="button" href="<?= h(url_path('/admin/connection-requests/mvm-documents') . '?id=' . $requestId); ?>">MVM dokumentumok / generálás</a>
                                                                            <?php endif; ?>
                                                                            <a class="button button-secondary" href="<?= h(authorization_signature_url($request)); ?>" target="_blank">Meghatalmazás online aláírása</a>
                                                                            <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests/edit') . '?id=' . $requestId); ?>">Munka szerkesztése</a>
                                                                            <a class="button" href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . $customerId . '&request_id=' . $requestId); ?>">Árajánlat készítése</a>
                                                                            <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests/quote-upload') . '?id=' . $requestId); ?>">Árajánlat feltöltése</a>
                                                                        </div>

                                                                        <div class="customer-crm-subpanels">
                                                                            <section>
                                                                                <div class="admin-request-section-title">
                                                                                    <h4>MVM dokumentumok</h4>
                                                                                    <span><?= count($mvmDocuments); ?> db</span>
                                                                                </div>
                                                                                <?php if ($mvmDocuments === []): ?>
                                                                                    <p class="request-admin-empty">Még nincs MVM dokumentum vagy generált csomag.</p>
                                                                                <?php else: ?>
                                                                                    <div class="admin-request-doc-grid">
                                                                                        <?php foreach ($mvmDocuments as $document): ?>
                                                                                            <?php
                                                                                            $documentUrl = url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $document['id'];
                                                                                            $documentPreviewKind = portal_file_preview_kind($document);
                                                                                            ?>
                                                                                            <article class="admin-request-doc-card admin-request-doc-card-<?= h($documentPreviewKind); ?>">
                                                                                                <div class="admin-request-doc-thumb">
                                                                                                    <?php if ($documentPreviewKind === 'image'): ?>
                                                                                                        <a href="<?= h($documentUrl); ?>" target="_blank"><img src="<?= h($documentUrl); ?>" alt="<?= h((string) $document['title']); ?>" width="92" height="92" loading="lazy"></a>
                                                                                                    <?php elseif ($documentPreviewKind === 'pdf'): ?>
                                                                                                        <iframe src="<?= h($documentUrl); ?>#toolbar=0&navpanes=0" title="<?= h((string) $document['title']); ?>" width="92" height="92" loading="lazy"></iframe>
                                                                                                    <?php else: ?>
                                                                                                        <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($document)); ?></span></div>
                                                                                                    <?php endif; ?>
                                                                                                </div>
                                                                                                <div class="admin-request-doc-meta">
                                                                                                    <strong><?= h((string) $document['title']); ?></strong>
                                                                                                    <span><?= h((string) $document['original_name']); ?></span>
                                                                                                    <a href="<?= h($documentUrl); ?>" target="_blank">Megnyitás</a>
                                                                                                </div>
                                                                                            </article>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </section>

                                                                            <section>
                                                                                <div class="admin-request-section-title">
                                                                                    <h4>Fotók és kitöltött dokumentumok</h4>
                                                                                    <span><?= count($files) + count($beforeWorkFiles) + count($afterWorkFiles); ?> fájl</span>
                                                                                </div>
                                                                                <?php $allFiles = array_merge($files, $beforeWorkFiles, $afterWorkFiles); ?>
                                                                                <?php if ($allFiles === []): ?>
                                                                                    <p class="request-admin-empty">Még nincs feltöltött fotó vagy dokumentum.</p>
                                                                                <?php else: ?>
                                                                                    <div class="admin-request-doc-grid">
                                                                                        <?php foreach ($files as $file): ?>
                                                                                            <?php
                                                                                            $fileUrl = url_path('/admin/connection-requests/file') . '?id=' . (int) $file['id'];
                                                                                            $previewKind = portal_file_preview_kind($file);
                                                                                            ?>
                                                                                            <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
                                                                                                <div class="admin-request-doc-thumb">
                                                                                                    <?php if ($previewKind === 'image'): ?>
                                                                                                        <a href="<?= h($fileUrl); ?>" target="_blank"><img src="<?= h($fileUrl); ?>" alt="<?= h((string) $file['label']); ?>" width="92" height="92" loading="lazy"></a>
                                                                                                    <?php elseif ($previewKind === 'pdf'): ?>
                                                                                                        <iframe src="<?= h($fileUrl); ?>#toolbar=0&navpanes=0" title="<?= h((string) $file['label']); ?>" width="92" height="92" loading="lazy"></iframe>
                                                                                                    <?php else: ?>
                                                                                                        <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($file)); ?></span></div>
                                                                                                    <?php endif; ?>
                                                                                                </div>
                                                                                                <div class="admin-request-doc-meta">
                                                                                                    <strong><?= h((string) $file['label']); ?></strong>
                                                                                                    <span><?= h((string) $file['original_name']); ?></span>
                                                                                                    <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                                                                                </div>
                                                                                            </article>
                                                                                        <?php endforeach; ?>
                                                                                        <?php foreach (array_merge($beforeWorkFiles, $afterWorkFiles) as $workFile): ?>
                                                                                            <?php
                                                                                            $workFileUrl = url_path('/admin/connection-requests/work-file') . '?id=' . (int) $workFile['id'];
                                                                                            $workPreviewKind = portal_file_preview_kind($workFile);
                                                                                            ?>
                                                                                            <article class="admin-request-doc-card admin-request-doc-card-<?= h($workPreviewKind); ?>">
                                                                                                <div class="admin-request-doc-thumb">
                                                                                                    <?php if ($workPreviewKind === 'image'): ?>
                                                                                                        <a href="<?= h($workFileUrl); ?>" target="_blank"><img src="<?= h($workFileUrl); ?>" alt="<?= h((string) $workFile['label']); ?>" width="92" height="92" loading="lazy"></a>
                                                                                                    <?php else: ?>
                                                                                                        <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($workFile)); ?></span></div>
                                                                                                    <?php endif; ?>
                                                                                                </div>
                                                                                                <div class="admin-request-doc-meta">
                                                                                                    <strong><?= h((string) $workFile['label']); ?></strong>
                                                                                                    <span><?= h((string) $workFile['original_name']); ?></span>
                                                                                                    <a href="<?= h($workFileUrl); ?>" target="_blank">Megnyitás</a>
                                                                                                </div>
                                                                                            </article>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </section>

                                                                            <section>
                                                                                <div class="admin-request-section-title">
                                                                                    <h4>Árajánlatok</h4>
                                                                                    <span><?= count($quotes); ?> db</span>
                                                                                </div>
                                                                                <?php if ($quotes === []): ?>
                                                                                    <p class="request-admin-empty">Még nincs ehhez a munkához készített árajánlat.</p>
                                                                                <?php else: ?>
                                                                                    <div class="quote-mini-list">
                                                                                        <?php foreach ($quotes as $quote): ?>
                                                                                            <?php $quoteStatus = (string) ($quote['status'] ?? 'draft'); ?>
                                                                                            <article class="quote-mini-card">
                                                                                                <div>
                                                                                                    <strong><?= h((string) $quote['quote_number']); ?></strong>
                                                                                                    <span><?= h((string) $quote['subject']); ?></span>
                                                                                                </div>
                                                                                                <div>
                                                                                                    <span class="status-badge status-badge-<?= h($quoteStatus); ?>"><?= h($quoteStatusLabels[$quoteStatus] ?? $quoteStatus); ?></span>
                                                                                                    <strong><?= h(quote_display_total($quote)); ?></strong>
                                                                                                </div>
                                                                                                <div class="inline-link-list">
                                                                                                    <a href="<?= h(url_path('/admin/quotes/edit') . '?id=' . (int) $quote['id']); ?>">Szerkesztés</a>
                                                                                                    <a href="<?= h(url_path('/admin/quotes/send') . '?id=' . (int) $quote['id']); ?>">PDF / email</a>
                                                                                                    <?php if (quote_file_is_available($quote)): ?>
                                                                                                        <a href="<?= h(url_path('/admin/quotes/file') . '?id=' . (int) $quote['id']); ?>" target="_blank">Megnyitás</a>
                                                                                                    <?php endif; ?>
                                                                                                </div>
                                                                                            </article>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </section>
                                                                        </div>
                                                                    </article>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </section>

                                                        <?php if (is_admin_user()): ?>
                                                            <section class="minicrm-document-preview-panel customer-crm-danger">
                                                                <div class="admin-request-section-title">
                                                                    <h3>Admin műveletek</h3>
                                                                    <span>Óvatosan</span>
                                                                </div>
                                                                <form method="post" action="<?= h(url_path('/admin/customers')); ?>" onsubmit="return confirm('Biztosan törlöd ezt az ügyfelet és minden kapcsolódó adatát? Ez nem visszavonható.');">
                                                                    <?= csrf_field(); ?>
                                                                    <input type="hidden" name="action" value="delete_customer">
                                                                    <input type="hidden" name="customer_id" value="<?= $customerId; ?>">
                                                                    <button class="table-action-button table-action-danger" type="submit">Ügyfél törlése minden kapcsolódó adattal</button>
                                                                </form>
                                                            </section>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </article>
                                        <?php endif; ?>
                                    </details>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const input = document.querySelector('[data-customer-crm-search]');
    const count = document.querySelector('[data-customer-crm-count]');
    const items = Array.from(document.querySelectorAll('[data-customer-crm-item]'));
    const groups = Array.from(document.querySelectorAll('[data-customer-crm-status-group]'));

    items.forEach((item) => {
        item.addEventListener('toggle', () => {
            if (!item.open || item.dataset.customerCrmLoaded === '1' || !item.dataset.customerCrmDetailUrl) {
                return;
            }

            window.location.href = item.dataset.customerCrmDetailUrl;
        });
    });

    if (!input || !count || items.length === 0) {
        return;
    }

    const searchable = items.map((item) => ({
        item,
        text: `${item.textContent} ${item.dataset.customerCrmSearchText || ''}`.toLocaleLowerCase('hu-HU'),
    }));

    input.addEventListener('input', () => {
        const query = input.value.trim().toLocaleLowerCase('hu-HU');
        let visible = 0;

        searchable.forEach(({ item, text }) => {
            const show = query === '' || text.includes(query);
            item.hidden = !show;
            visible += show ? 1 : 0;
        });

        groups.forEach((group) => {
            const groupItems = Array.from(group.querySelectorAll('[data-customer-crm-item]'));
            const groupVisible = groupItems.filter((item) => !item.hidden).length;
            const groupCount = group.querySelector('[data-customer-crm-status-count]');

            group.hidden = groupVisible === 0;

            if (groupCount) {
                groupCount.textContent = `${groupVisible} látható`;
            }
        });

        count.textContent = `${visible} db`;
    });
});
</script>
