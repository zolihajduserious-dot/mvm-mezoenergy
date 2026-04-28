<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$schemaErrors = electrician_schema_errors();
$mvmSchemaErrors = mvm_document_schema_errors();
$quoteMissingReasonSchemaErrors = connection_request_quote_missing_reason_schema_errors();
$canManageMvmDocuments = can_manage_mvm_documents();
$flash = get_flash();
$assignmentErrors = [];
$interventionErrors = [];
$workflowErrors = [];
$workflowStageSchemaReady = db_table_exists('connection_requests') && db_column_exists('connection_requests', 'admin_workflow_stage');

if (is_post() && ($_POST['action'] ?? '') === 'assign_electrician') {
    require_valid_csrf_token();

    if ($schemaErrors !== []) {
        $assignmentErrors[] = 'Előbb futtasd le a database/electrician_workflow.sql fájlt.';
    } else {
        $assignRequestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
        $electricianUserIdRaw = (string) ($_POST['electrician_user_id'] ?? '');
        $electricianUserId = $electricianUserIdRaw !== '' ? (int) $electricianUserIdRaw : null;
        $requestToAssign = $assignRequestId ? find_connection_request($assignRequestId) : null;
        $quoteMissingReason = trim((string) ($_POST['quote_missing_reason'] ?? ''));
        $requestHasQuote = $requestToAssign !== null && (
            latest_quote_for_connection_request((int) $assignRequestId) !== null
            || accepted_quote_for_registration_duplicate_request((int) $assignRequestId) !== null
        );

        if ($requestToAssign === null) {
            $assignmentErrors[] = 'Az igény nem található.';
        }

        if ($electricianUserId !== null && find_electrician_by_user($electricianUserId) === null) {
            $assignmentErrors[] = 'A kiválasztott szerelő nem található.';
        }

        if ($requestToAssign !== null && !$requestHasQuote) {
            if ($quoteMissingReason === '') {
                $assignmentErrors[] = 'Ha még nincs árajánlat, kötelező megadni, hogy miért nincs.';
            } elseif ($quoteMissingReasonSchemaErrors === []) {
                save_connection_request_quote_missing_reason((int) $assignRequestId, $quoteMissingReason);
            } else {
                $assignmentErrors = array_merge($assignmentErrors, $quoteMissingReasonSchemaErrors);
            }

            if ($electricianUserId !== null) {
                $assignmentErrors[] = 'Szerelőnek csak olyan igényt lehet kiadni, amelyhez már készült vagy feltöltött árajánlat.';
            }
        }

        if ($assignmentErrors === []) {
            if (!$requestHasQuote && $electricianUserId === null) {
                assign_connection_request_to_electrician((int) $assignRequestId, null);
                set_flash('success', 'Az árajánlat hiányának oka mentve. A munka nincs szerelőnek kiadva.');
                redirect(connection_crm_detail_path((int) $assignRequestId));
            }

            assign_connection_request_to_electrician((int) $assignRequestId, $electricianUserId);
            $assignmentMessage = $electricianUserId === null ? 'A munka visszakerült kiosztatlan állapotba.' : 'A munka ki lett adva a szerelőnek.';

            if ($electricianUserId !== null) {
                $notification = send_electrician_assignment_email((int) $assignRequestId, $electricianUserId);
                $assignmentMessage .= ' ' . $notification['message'];
            }

            set_flash('success', $assignmentMessage);
            redirect(connection_crm_detail_path((int) $assignRequestId));
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'update_workflow_stage') {
    require_valid_csrf_token();

    $workflowRequestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $workflowRequest = $workflowRequestId ? find_connection_request($workflowRequestId) : null;
    $selectedWorkflowStage = normalize_admin_workflow_stage((string) ($_POST['admin_workflow_stage'] ?? ''));

    if (!$workflowStageSchemaReady) {
        $workflowErrors[] = 'A folyamatlépések mentéséhez futtasd le a database/admin_workflow_stages.sql fájlt phpMyAdminban.';
    }

    if ($workflowRequest === null) {
        $workflowErrors[] = 'Az igény nem található.';
    }

    if ($selectedWorkflowStage === null) {
        $workflowErrors[] = 'Érvénytelen folyamatlépés.';
    }

    if ($workflowErrors === []) {
        update_connection_request_admin_workflow_stage((int) $workflowRequestId, $selectedWorkflowStage);
        set_flash('success', 'A folyamatlépés frissítve.');
        redirect(connection_crm_detail_path((int) $workflowRequestId));
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'upload_intervention_sheet' && !$canManageMvmDocuments) {
    http_response_code(403);
    exit('Nincs jogosultságod az MVM dokumentumok kezeléséhez.');
}

if (is_post() && ($_POST['action'] ?? '') === 'upload_intervention_sheet' && $canManageMvmDocuments) {
    require_valid_csrf_token();

    $uploadRequestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $requestForUpload = $uploadRequestId ? find_connection_request($uploadRequestId) : null;
    $uploadedFiles = uploaded_files_for_key($_FILES, 'intervention_sheet_documents');

    if ($requestForUpload === null) {
        $interventionErrors[] = 'Az igény nem található.';
    }

    if ($mvmSchemaErrors !== []) {
        $interventionErrors = array_merge($interventionErrors, $mvmSchemaErrors);
    } else {
        $interventionErrors = array_merge($interventionErrors, validate_connection_request_document_upload('intervention_sheet', $uploadedFiles));
    }

    if ($interventionErrors === []) {
        try {
            $messages = store_connection_request_documents((int) $uploadRequestId, 'intervention_sheet', 'Új beavatkozási lap', $uploadedFiles);
            $message = 'A beavatkozási lap feltöltve.';

            if ($messages !== []) {
                $message .= ' Figyelmeztetés: ' . implode(' ', $messages);
            }

            set_flash('success', $message);
            redirect(connection_crm_detail_path((int) $uploadRequestId));
        } catch (Throwable $exception) {
            $interventionErrors[] = APP_DEBUG ? $exception->getMessage() : 'A beavatkozási lap feltöltése sikertelen.';
        }
    }
}

$requests = all_connection_requests();
$electricians = $schemaErrors === [] ? electrician_users(true) : [];
$requestStatusLabels = connection_request_status_labels();
$electricianStatusLabels = electrician_work_status_labels();
$quoteStatusLabels = quote_status_labels();
$emailStatusLabels = [
    'pending' => 'Értesítésre vár',
    'sent' => 'Admin értesítve',
    'failed' => 'Küldési hiba',
];
$workflowStages = admin_workflow_stage_definitions();
$workflowBuckets = array_fill_keys(array_keys($workflowStages), []);
$requestContexts = [];
$selectedRequestId = isset($_GET['request']) ? max(0, (int) $_GET['request']) : 0;

$totalRequests = count($requests);
$draftCount = 0;
$finalizedCount = 0;
$failedEmailCount = 0;

foreach ($requests as $summaryRequest) {
    $summaryStatus = (string) ($summaryRequest['request_status'] ?? 'finalized');
    $summaryEmailStatus = (string) ($summaryRequest['email_status'] ?? 'pending');

    if ($summaryStatus === 'finalized') {
        $finalizedCount++;
    } else {
        $draftCount++;
    }

    if ($summaryEmailStatus === 'failed') {
        $failedEmailCount++;
    }
}

foreach ($requests as $workflowRequest) {
    $workflowRequestId = (int) $workflowRequest['id'];

    if ($selectedRequestId === 0) {
        $selectedRequestId = $workflowRequestId;
    }

    $allMvmDocuments = $canManageMvmDocuments && $mvmSchemaErrors === [] ? connection_request_documents($workflowRequestId) : [];
    $quotes = quotes_for_connection_request($workflowRequestId);
    $acceptedQuote = accepted_quote_for_connection_request($workflowRequestId)
        ?? accepted_quote_for_registration_duplicate_request($workflowRequestId);
    $latestQuote = $quotes[0] ?? null;

    if ($latestQuote === null && $acceptedQuote !== null) {
        $latestQuote = $acceptedQuote;
        $quotes = [$acceptedQuote];
    }

    $workflowStage = connection_request_admin_workflow_stage($workflowRequest, $latestQuote, $acceptedQuote, $allMvmDocuments);
    $quoteMissingReason = connection_request_quote_missing_reason($workflowRequest);
    $quoteState = quote_state_summary($latestQuote, $acceptedQuote, $quoteMissingReason);
    $requestContexts[$workflowRequestId] = [
        'files' => connection_request_files($workflowRequestId),
        'before_work_files' => $schemaErrors === [] ? connection_request_work_files($workflowRequestId, 'before') : [],
        'after_work_files' => $schemaErrors === [] ? connection_request_work_files($workflowRequestId, 'after') : [],
        'all_mvm_documents' => $allMvmDocuments,
        'mvm_documents' => array_values(array_filter(
            $allMvmDocuments,
            static fn (array $document): bool => (string) ($document['document_type'] ?? '') === 'intervention_sheet'
        )),
        'quotes' => $quotes,
        'accepted_quote' => $acceptedQuote,
        'latest_quote' => $latestQuote,
        'workflow_stage' => $workflowStage,
        'quote_missing_reason' => $quoteMissingReason,
        'quote_state' => $quoteState,
    ];
    $workflowBuckets[$workflowStage][] = $workflowRequest;
}

function connection_crm_short_text(string $value, int $length = 130): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');
    $stringLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

    if ($value === '' || $stringLength <= $length) {
        return $value;
    }

    $substring = function_exists('mb_substr') ? mb_substr($value, 0, $length - 1) : substr($value, 0, $length - 1);

    return rtrim($substring) . '…';
}

function connection_crm_detail_path(int $requestId): string
{
    return '/admin/connection-requests?request=' . $requestId . '#request-' . $requestId;
}

function connection_crm_value($value): string
{
    $value = trim((string) ($value ?? ''));

    return $value !== '' ? $value : '-';
}

function connection_crm_latest_date(array $rows, string $fallback): string
{
    foreach ($rows as $row) {
        foreach (['accepted_at', 'sent_at', 'generated_at', 'uploaded_at', 'created_at', 'updated_at'] as $column) {
            if (!empty($row[$column])) {
                return (string) $row[$column];
            }
        }
    }

    return $fallback;
}

function connection_crm_timeline_event(array &$events, string $date, string $title, string $actor, string $body, string $kind = 'system'): void
{
    $date = connection_crm_value($date);
    $events[] = [
        'date' => $date,
        'sort' => $date === '-' ? '' : $date,
        'title' => $title,
        'actor' => $actor,
        'body' => $body,
        'kind' => $kind,
    ];
}

function connection_crm_request_timeline_events(
    array $request,
    string $workflowStageTitle,
    array $quoteState,
    array $mvmDocuments,
    array $files,
    array $beforeWorkFiles,
    array $afterWorkFiles,
    array $quotes,
    ?array $acceptedQuote,
    string $quoteMissingReason,
    array $requestStatusLabels,
    array $electricianStatusLabels,
    array $emailStatusLabels
): array {
    $events = [];
    $baseDate = (string) ($request['updated_at'] ?? $request['closed_at'] ?? $request['submitted_at'] ?? $request['created_at'] ?? '');
    $requestStatus = (string) ($request['request_status'] ?? 'finalized');
    $electricianStatus = (string) ($request['electrician_status'] ?? 'unassigned');
    $emailStatus = (string) ($request['email_status'] ?? 'pending');

    connection_crm_timeline_event(
        $events,
        $baseDate,
        'Folyamatlépés',
        !empty($request['electrician_name']) ? (string) $request['electrician_name'] : 'Mező Energy',
        $workflowStageTitle,
        'status'
    );

    connection_crm_timeline_event(
        $events,
        (string) ($request['closed_at'] ?? $request['submitted_at'] ?? $request['created_at'] ?? ''),
        'Igény állapota',
        connection_crm_value($request['requester_name'] ?? ''),
        $requestStatusLabels[$requestStatus] ?? $requestStatus,
        $requestStatus === 'finalized' ? 'success' : 'system'
    );

    if ($acceptedQuote !== null) {
        connection_crm_timeline_event(
            $events,
            (string) ($acceptedQuote['accepted_at'] ?? $acceptedQuote['sent_at'] ?? $acceptedQuote['created_at'] ?? $baseDate),
            'Árajánlat elfogadva',
            'Ügyfél',
            quote_display_total($acceptedQuote),
            'success'
        );
    } elseif ($quotes !== []) {
        $latestQuote = $quotes[0];
        connection_crm_timeline_event(
            $events,
            (string) ($latestQuote['sent_at'] ?? $latestQuote['created_at'] ?? $baseDate),
            'Árajánlat',
            'Mező Energy',
            (string) $quoteState['title'] . ' · ' . (string) $quoteState['amount'],
            'system'
        );
    } elseif ($quoteMissingReason !== '') {
        connection_crm_timeline_event($events, $baseDate, 'Árajánlat hiánya', 'Mező Energy', $quoteMissingReason, 'warning');
    }

    if (!empty($request['assigned_electrician_user_id']) || $electricianStatus !== 'unassigned') {
        connection_crm_timeline_event(
            $events,
            $baseDate,
            'Szerelői kiadás',
            connection_crm_value($request['electrician_name'] ?? ''),
            $electricianStatusLabels[$electricianStatus] ?? $electricianStatus,
            'status'
        );
    }

    if ($mvmDocuments !== []) {
        connection_crm_timeline_event($events, connection_crm_latest_date($mvmDocuments, $baseDate), 'MVM dokumentumok', 'Rendszer', count($mvmDocuments) . ' dokumentum elérhető.', 'document');
    }

    $fileCount = count($files) + count($beforeWorkFiles) + count($afterWorkFiles);
    if ($fileCount > 0) {
        connection_crm_timeline_event(
            $events,
            connection_crm_latest_date(array_merge($files, $beforeWorkFiles, $afterWorkFiles), $baseDate),
            'Fotók és kitöltött dokumentumok',
            'Rendszer',
            $fileCount . ' fájl kapcsolva az igényhez.',
            'document'
        );
    }

    if ($emailStatus !== 'pending') {
        $emailBody = $emailStatusLabels[$emailStatus] ?? $emailStatus;
        if (!empty($request['email_error'])) {
            $emailBody .= ' · ' . (string) $request['email_error'];
        }

        connection_crm_timeline_event($events, $baseDate, 'Admin értesítés', 'Rendszer', $emailBody, $emailStatus === 'failed' ? 'warning' : 'success');
    }

    usort($events, static function (array $a, array $b): int {
        $timeA = strtotime((string) ($a['sort'] ?? '')) ?: 0;
        $timeB = strtotime((string) ($b['sort'] ?? '')) ?: 0;

        return $timeB <=> $timeA;
    });

    return $events;
}

function connection_crm_request_field_groups(
    array $request,
    string $siteAddress,
    string $requestOwnerType,
    string $workflowStageTitle,
    string $quoteSummaryAmount,
    string $quoteSummaryLabel,
    string $quoteMissingReason,
    array $requestStatusLabels,
    array $electricianStatusLabels,
    array $emailStatusLabels
): array {
    $requestStatus = (string) ($request['request_status'] ?? 'finalized');
    $electricianStatus = (string) ($request['electrician_status'] ?? 'unassigned');
    $emailStatus = (string) ($request['email_status'] ?? 'pending');

    return [
        [
            'title' => 'Ügyfél és cím',
            'fields' => [
                ['label' => 'Név', 'value' => connection_crm_value($request['requester_name'] ?? '')],
                ['label' => 'Típus', 'value' => $requestOwnerType],
                ['label' => 'Email', 'value' => connection_crm_value($request['email'] ?? '')],
                ['label' => 'Telefon', 'value' => connection_crm_value($request['phone'] ?? '')],
                ['label' => 'Generálkivitelező', 'value' => connection_crm_value($request['contractor_name'] ?? '')],
                ['label' => 'Kapcsolattartó', 'value' => connection_crm_value($request['contractor_contact_name'] ?? '')],
                ['label' => 'Cím', 'value' => $siteAddress !== '' ? $siteAddress : '-'],
                ['label' => 'HRSZ', 'value' => connection_crm_value($request['hrsz'] ?? '')],
            ],
        ],
        [
            'title' => 'Munka és mérő',
            'fields' => [
                ['label' => 'Munka neve', 'value' => connection_crm_value($request['project_name'] ?? '')],
                ['label' => 'Igénytípus', 'value' => connection_request_type_label($request['request_type'] ?? null)],
                ['label' => 'Folyamatlépés', 'value' => $workflowStageTitle],
                ['label' => 'Igény állapota', 'value' => $requestStatusLabels[$requestStatus] ?? $requestStatus],
                ['label' => 'Szerelő státusz', 'value' => $electricianStatusLabels[$electricianStatus] ?? $electricianStatus],
                ['label' => 'Kiadva szerelőnek', 'value' => connection_crm_value($request['electrician_name'] ?? '')],
                ['label' => 'Mérő gyári szám', 'value' => connection_crm_value($request['meter_serial'] ?? '')],
                ['label' => 'Fogyasztási hely', 'value' => connection_crm_value($request['consumption_place_id'] ?? '')],
            ],
        ],
        [
            'title' => 'Teljesítmény és ajánlat',
            'fields' => [
                ['label' => 'Mindennapszaki meglévő', 'value' => connection_crm_value($request['existing_general_power'] ?? '')],
                ['label' => 'Mindennapszaki igényelt', 'value' => connection_crm_value($request['requested_general_power'] ?? '')],
                ['label' => 'H tarifa', 'value' => connection_crm_value($request['existing_h_tariff_power'] ?? '') . ' / ' . connection_crm_value($request['requested_h_tariff_power'] ?? '')],
                ['label' => 'Vezérelt', 'value' => connection_crm_value($request['existing_controlled_power'] ?? '') . ' / ' . connection_crm_value($request['requested_controlled_power'] ?? '')],
                ['label' => 'Árajánlat', 'value' => $quoteSummaryAmount . ' · ' . $quoteSummaryLabel],
                ['label' => 'Árajánlat hiányának oka', 'value' => connection_crm_value($quoteMissingReason)],
                ['label' => 'Email állapot', 'value' => $emailStatusLabels[$emailStatus] ?? $emailStatus],
                ['label' => 'Megjegyzés', 'value' => connection_crm_value($request['notes'] ?? '')],
            ],
        ],
    ];
}
?>
<section class="admin-section connection-crm-page">
    <div class="container admin-requests-container">
        <div class="admin-header customer-crm-hero">
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Mérőhelyi igények</h1>
                <p>Ügyféloldalon rögzített piszkozatok, végleges igények és feltöltött dokumentumok.</p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($assignmentErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($assignmentErrors as $assignmentError): ?><p><?= h($assignmentError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($interventionErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($interventionErrors as $interventionError): ?><p><?= h($interventionError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($workflowErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($workflowErrors as $workflowError): ?><p><?= h($workflowError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-info">
                <p>A szerelői munkakiadáshoz futtasd le phpMyAdminban a <strong>database/electrician_workflow.sql</strong> fájlt.</p>
            </div>
        <?php endif; ?>

        <?php if ($canManageMvmDocuments && $mvmSchemaErrors !== []): ?>
            <div class="alert alert-info">
                <p>A beavatkozási lap feltöltéséhez futtasd le phpMyAdminban a <strong>database/upgrade_connection_requests.sql</strong> fájlt.</p>
            </div>
        <?php endif; ?>

        <?php if ($quoteMissingReasonSchemaErrors !== []): ?>
            <div class="alert alert-info">
                <p>Az árajánlat nélküli igények megjegyzéséhez futtasd le phpMyAdminban a <strong>database/quote_assignment_guard.sql</strong> fájlt.</p>
            </div>
        <?php endif; ?>

        <?php if (!$workflowStageSchemaReady): ?>
            <div class="alert alert-info">
                <p>A kézi folyamatlépések mentéséhez futtasd le phpMyAdminban a <strong>database/admin_workflow_stages.sql</strong> fájlt.</p>
            </div>
        <?php endif; ?>

        <?php if ($requests !== []): ?>
            <div class="minicrm-list-tools connection-crm-search">
                <label for="connection-crm-search">Keresés munka, ügyfél, cím, felelős vagy státusz alapján</label>
                <input id="connection-crm-search" type="search" placeholder="Keresés..." data-connection-crm-search>
                <span data-connection-crm-count><?= count($requests); ?> db</span>
            </div>

            <nav class="minicrm-status-nav connection-crm-status-nav" aria-label="Folyamatlépések">
                <?php foreach ($workflowStages as $stageKey => $stage): ?>
                    <?php $stageCount = count($workflowBuckets[$stageKey] ?? []); ?>
                    <a href="#workflow-stage-<?= h($stageKey); ?>">
                        <?= (int) $stage['number']; ?>. <?= h((string) $stage['title']); ?>
                        <strong><?= $stageCount; ?></strong>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="admin-grid summary-grid request-summary-grid">
                <article class="metric-card metric-card-primary">
                    <span class="metric-label">Összes igény</span>
                    <strong><?= $totalRequests; ?></strong>
                    <p>Ügyfél vagy generálkivitelező által rögzített igények.</p>
                </article>
                <article class="metric-card metric-card-accent">
                    <span class="metric-label">Lezárt igény</span>
                    <strong><?= $finalizedCount; ?></strong>
                    <p>Véglegesített, feldolgozható igénybejelentések.</p>
                </article>
                <article class="metric-card metric-card-system">
                    <span class="metric-label">Piszkozat</span>
                    <strong><?= $draftCount; ?></strong>
                    <p>Még módosítható, ügyféloldali folyamatban lévő igények.</p>
                </article>
                <article class="metric-card <?= $failedEmailCount > 0 ? 'metric-card-alert' : 'metric-card-system'; ?>">
                    <span class="metric-label">Email hiba</span>
                    <strong><?= $failedEmailCount; ?></strong>
                    <p>Sikertelen admin értesítések száma.</p>
                </article>
            </div>
        <?php endif; ?>

        <?php if ($requests === []): ?>
            <div class="empty-state">
                <h2>Nincs rögzített igény</h2>
                <p>Az ügyfelek által mentett piszkozatok és lezárt igények itt jelennek meg.</p>
            </div>
        <?php else: ?>
            <div class="admin-workflow-board connection-crm-overview" aria-label="Admin folyamatlépések">
                <?php foreach ($workflowStages as $stageKey => $stage): ?>
                    <?php $stageCount = count($workflowBuckets[$stageKey] ?? []); ?>
                    <a class="admin-workflow-card admin-workflow-card-<?= h((string) $stage['variant']); ?>" href="#workflow-stage-<?= h($stageKey); ?>">
                        <span><?= (int) $stage['number']; ?></span>
                        <strong><?= h((string) $stage['title']); ?></strong>
                        <em><?= $stageCount; ?> igény</em>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="admin-workflow-list minicrm-status-groups connection-crm-list" data-connection-crm-list>
                <?php foreach ($workflowStages as $stageKey => $stage): ?>
                    <?php $stageRequests = $workflowBuckets[$stageKey] ?? []; ?>
                    <section class="admin-workflow-stage minicrm-status-group" id="workflow-stage-<?= h($stageKey); ?>" data-connection-crm-status-group>
                        <div class="admin-workflow-stage-head minicrm-status-group-head">
                            <div>
                                <span class="portal-kicker"><?= (int) $stage['number']; ?>. lépés</span>
                                <h2><?= h((string) $stage['title']); ?></h2>
                                <p><?= h((string) $stage['description']); ?></p>
                            </div>
                            <strong data-connection-crm-status-count><?= count($stageRequests); ?> látható</strong>
                        </div>

                        <?php if ($stageRequests === []): ?>
                            <p class="request-admin-empty">Ebben a folyamatlépésben most nincs igény.</p>
                        <?php else: ?>
                            <div class="request-admin-list minicrm-work-table">
                                <div class="minicrm-work-table-head" role="row">
                                    <span>Munka</span>
                                    <span>Folyamat / ajánlat</span>
                                    <span>Dátum</span>
                                    <span>Anyag</span>
                                </div>
                <?php foreach ($stageRequests as $request): ?>
                    <?php
                    $requestId = (int) $request['id'];
                    $context = $requestContexts[$requestId] ?? [];
                    $files = $context['files'] ?? [];
                    $beforeWorkFiles = $context['before_work_files'] ?? [];
                    $afterWorkFiles = $context['after_work_files'] ?? [];
                    $mvmDocuments = $context['mvm_documents'] ?? [];
                    $quotes = $context['quotes'] ?? [];
                    $acceptedQuote = $context['accepted_quote'] ?? null;
                    $requestStatus = (string) ($request['request_status'] ?? 'finalized');
                    $emailStatus = (string) ($request['email_status'] ?? 'pending');
                    $dateLabel = $requestStatus === 'finalized' ? 'Lezárva' : 'Utoljára mentve';
                    $dateValue = $requestStatus === 'finalized'
                        ? ($request['closed_at'] ?: '-')
                        : ($request['updated_at'] ?: $request['created_at']);
                    $siteAddress = trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));
                    $assignedElectrician = !empty($request['electrician_name']) ? (string) $request['electrician_name'] : 'Nincs kiadva';
                    $latestQuote = $context['latest_quote'] ?? null;
                    $latestQuoteStatus = $latestQuote !== null ? (string) ($latestQuote['status'] ?? 'draft') : '';
                    $quoteMissingReason = (string) ($context['quote_missing_reason'] ?? '');
                    $quoteState = $context['quote_state'] ?? quote_state_summary($latestQuote, $acceptedQuote, $quoteMissingReason);
                    $workflowStage = (string) ($context['workflow_stage'] ?? $stageKey);
                    $workflowStageDefinition = $workflowStages[$workflowStage] ?? $stage;
                    $quoteSummaryLabel = (string) $quoteState['status'];
                    $quoteSummaryAmount = (string) $quoteState['amount'];
                    $isSelectedRequest = $requestId === $selectedRequestId;
                    $detailUrl = url_path('/admin/connection-requests') . '?request=' . $requestId . '#request-' . $requestId;
                    $fileCount = count($files) + count($beforeWorkFiles) + count($afterWorkFiles);
                    $materialCount = $fileCount + count($mvmDocuments) + count($quotes);
                    $searchText = implode(' ', [
                        (string) ($request['project_name'] ?? ''),
                        (string) ($request['requester_name'] ?? ''),
                        (string) ($request['email'] ?? ''),
                        (string) ($request['phone'] ?? ''),
                        (string) ($workflowStageDefinition['title'] ?? ''),
                        (string) ($requestStatusLabels[$requestStatus] ?? $requestStatus),
                        (string) ($request['electrician_name'] ?? ''),
                        $siteAddress,
                        $quoteSummaryAmount,
                    ]);
                    $requestOwnerType = !empty($request['contractor_name']) ? 'Generálkivitelezőn keresztül' : 'Közvetlen ügyfél';
                    $timelineEvents = $isSelectedRequest ? connection_crm_request_timeline_events(
                        $request,
                        (string) $workflowStageDefinition['title'],
                        $quoteState,
                        $mvmDocuments,
                        $files,
                        $beforeWorkFiles,
                        $afterWorkFiles,
                        $quotes,
                        $acceptedQuote,
                        $quoteMissingReason,
                        $requestStatusLabels,
                        $electricianStatusLabels,
                        $emailStatusLabels
                    ) : [];
                    $fieldGroups = $isSelectedRequest ? connection_crm_request_field_groups(
                        $request,
                        $siteAddress,
                        $requestOwnerType,
                        (string) $workflowStageDefinition['title'],
                        $quoteSummaryAmount,
                        $quoteSummaryLabel,
                        $quoteMissingReason,
                        $requestStatusLabels,
                        $electricianStatusLabels,
                        $emailStatusLabels
                    ) : [];
                    ?>

                    <details class="admin-workflow-request minicrm-work-row connection-crm-row" id="request-<?= $requestId; ?>" data-connection-crm-item data-connection-crm-search-text="<?= h($searchText); ?>" data-connection-crm-loaded="<?= $isSelectedRequest ? '1' : '0'; ?>" data-connection-crm-detail-url="<?= h($detailUrl); ?>" <?= $isSelectedRequest ? 'open' : ''; ?>>
                        <summary class="admin-workflow-request-summary minicrm-work-row-summary">
                            <span class="admin-workflow-request-main">
                                <strong><?= h(connection_crm_short_text((string) $request['project_name'], 90)); ?></strong>
                                <small>#<?= $requestId; ?> · <?= h($request['requester_name']); ?> · <?= h($siteAddress !== '' ? $siteAddress : '-'); ?></small>
                            </span>
                            <span class="admin-workflow-request-meta">
                                <span><?= h((string) $workflowStageDefinition['title']); ?></span>
                                <strong><?= h($quoteSummaryAmount); ?></strong>
                                <?php if ($workflowStage === 'assigned_waiting_execution'): ?>
                                    <span><?= h(admin_workflow_assignment_due_text($request)); ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="minicrm-work-date"><?= h((string) $dateValue); ?></span>
                            <span class="admin-workflow-request-badges">
                                <strong><?= $materialCount; ?> anyag</strong>
                                <small><?= count($mvmDocuments); ?> MVM · <?= $fileCount; ?> fájl · <?= count($quotes); ?> ajánlat</small>
                            </span>
                        </summary>

                    <?php if (!$isSelectedRequest): ?>
                        <div class="request-admin-card minicrm-work-card connection-crm-card connection-crm-placeholder">
                            <p class="request-admin-empty">A teljes adatlap külön töltődik be, hogy a munkalista gyors és áttekinthető maradjon.</p>
                            <a class="button button-secondary" href="<?= h($detailUrl); ?>">Adatlap megnyitása</a>
                        </div>
                    <?php else: ?>
                    <article class="request-admin-card minicrm-work-card connection-crm-card">
                        <div class="request-admin-card-head">
                            <div>
                                <span class="portal-kicker">#<?= (int) $request['id']; ?> · <?= h($dateLabel); ?>: <?= h($dateValue); ?></span>
                                <h2><?= h($request['project_name']); ?></h2>
                                <p><?= h(connection_request_type_label($request['request_type'] ?? null)); ?> · <?= h($request['site_postal_code'] . ' ' . $request['site_address']); ?></p>
                            </div>
                            <div class="request-admin-status">
                                <span class="status-badge status-badge-<?= h($requestStatus); ?>"><?= h($requestStatusLabels[$requestStatus] ?? $requestStatus); ?></span>
                                <?php if ($schemaErrors === []): ?>
                                    <span class="status-badge status-badge-<?= h((string) ($request['electrician_status'] ?? 'unassigned')); ?>"><?= h($electricianStatusLabels[$request['electrician_status'] ?? 'unassigned'] ?? (string) ($request['electrician_status'] ?? 'unassigned')); ?></span>
                                <?php endif; ?>
                                <?php if ($acceptedQuote !== null): ?>
                                    <span class="status-badge status-badge-accepted">Ajánlat elfogadva</span>
                                <?php elseif ($quotes !== []): ?>
                                    <?php $latestQuoteStatus = (string) ($quotes[0]['status'] ?? 'draft'); ?>
                                    <span class="status-badge status-badge-<?= h($latestQuoteStatus); ?>"><?= h($quoteStatusLabels[$latestQuoteStatus] ?? $latestQuoteStatus); ?></span>
                                <?php endif; ?>
                                <span class="status-badge status-badge-<?= h($emailStatus); ?>"><?= h($emailStatusLabels[$emailStatus] ?? $emailStatus); ?></span>
                            </div>
                        </div>

                        <div class="minicrm-work-detail-layout connection-crm-detail-layout">
                            <aside class="minicrm-work-facts connection-crm-facts">
                                <dl>
                                    <div><dt>Ügyfél</dt><dd><?= h(connection_crm_value($request['requester_name'] ?? '')); ?></dd></div>
                                    <div><dt>Felelős</dt><dd><?= h(connection_crm_value($request['electrician_name'] ?? '')); ?></dd></div>
                                    <div><dt>Folyamat</dt><dd><?= h((string) $workflowStageDefinition['title']); ?></dd></div>
                                    <div><dt>Cím</dt><dd><?= h($siteAddress !== '' ? $siteAddress : '-'); ?></dd></div>
                                    <div><dt>HRSZ</dt><dd><?= h(connection_crm_value($request['hrsz'] ?? '')); ?></dd></div>
                                    <div><dt>Mérő</dt><dd><?= h(connection_crm_value($request['meter_serial'] ?? '')); ?></dd></div>
                                    <div><dt>Leadás</dt><dd><?= h(connection_crm_value($dateValue)); ?></dd></div>
                                </dl>

                                <section class="minicrm-compact-docs connection-crm-quick-actions">
                                    <h3>Anyagok <span><?= $materialCount; ?></span></h3>
                                    <div>
                                        <?php if ($canManageMvmDocuments): ?>
                                            <a href="<?= h(url_path('/admin/connection-requests/mvm-documents') . '?id=' . (int) $request['id']); ?>">MVM dokumentumok<span><?= count($mvmDocuments); ?></span></a>
                                        <?php endif; ?>
                                        <a href="<?= h(url_path('/admin/connection-requests/edit') . '?id=' . (int) $request['id']); ?>">Igény szerkesztése<span>Edit</span></a>
                                        <a href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . (int) $request['customer_id'] . '&request_id=' . (int) $request['id']); ?>">Árajánlat készítése<span>Ajánlat</span></a>
                                        <a href="<?= h(url_path('/admin/connection-requests/quote-upload') . '?id=' . (int) $request['id']); ?>">Árajánlat feltöltése<span>PDF</span></a>
                                    </div>
                                </section>
                            </aside>

                            <div class="minicrm-work-main connection-crm-main">
                                <section class="minicrm-timeline-panel">
                                    <div class="admin-request-section-title">
                                        <h3>Előzmények</h3>
                                        <span><?= count($timelineEvents); ?> esemény</span>
                                    </div>
                                    <ol class="minicrm-timeline">
                                        <?php foreach ($timelineEvents as $event): ?>
                                            <li class="minicrm-timeline-event minicrm-timeline-<?= h((string) $event['kind']); ?>">
                                                <time><?= h((string) $event['date']); ?></time>
                                                <div>
                                                    <strong><?= h((string) $event['title']); ?></strong>
                                                    <span><?= h((string) $event['actor']); ?></span>
                                                    <p><?= h((string) $event['body']); ?></p>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                </section>

                        <div class="quote-state-card quote-state-card-<?= h((string) $quoteState['class']); ?>">
                            <div>
                                <span class="portal-kicker">Árajánlat állapota</span>
                                <strong><?= h((string) $quoteState['title']); ?></strong>
                                <p><?= h((string) $quoteState['description']); ?></p>
                            </div>
                            <strong><?= h((string) $quoteState['amount']); ?></strong>
                        </div>

                        <?php if (!empty($request['email_error'])): ?>
                            <div class="alert alert-error request-admin-alert"><p><?= h($request['email_error']); ?></p></div>
                        <?php endif; ?>

                        <section class="minicrm-readable-groups minicrm-field-groups connection-crm-field-groups">
                            <?php foreach ($fieldGroups as $groupIndex => $fieldGroup): ?>
                                <details class="minicrm-field-group" <?= $groupIndex < 3 ? 'open' : ''; ?>>
                                    <summary>
                                        <strong><?= h((string) $fieldGroup['title']); ?></strong>
                                        <span><?= count($fieldGroup['fields']); ?> mező</span>
                                    </summary>
                                    <div class="minicrm-readable-grid">
                                        <?php foreach ($fieldGroup['fields'] as $field): ?>
                                            <article class="minicrm-readable-row">
                                                <span><?= h((string) $field['label']); ?></span>
                                                <strong><?= h((string) $field['value']); ?></strong>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </section>

                        <div class="request-admin-footer connection-crm-function-stack">
                            <section class="admin-request-panel admin-request-documents minicrm-document-preview-panel connection-crm-documents">
                                <?php if ($canManageMvmDocuments): ?>
                                <div class="admin-request-section-title">
                                    <h3>Beavatkozási lap</h3>
                                    <span><?= count($mvmDocuments); ?> db</span>
                                </div>
                                <form class="intervention-upload-form" method="post" enctype="multipart/form-data" action="<?= h($detailUrl); ?>">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="upload_intervention_sheet">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id']; ?>">
                                    <label for="intervention_sheet_<?= (int) $request['id']; ?>">Új beavatkozási lap feltöltése</label>
                                    <div>
                                        <input id="intervention_sheet_<?= (int) $request['id']; ?>" name="intervention_sheet_documents[]" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" <?= $mvmSchemaErrors !== [] ? 'disabled' : 'required'; ?>>
                                        <button class="button" type="submit" <?= $mvmSchemaErrors !== [] ? 'disabled' : ''; ?>>Beavatkozási lap feltöltése</button>
                                    </div>
                                </form>
                                <?php if ($mvmDocuments !== []): ?>
                                    <div class="admin-request-doc-grid">
                                        <?php foreach ($mvmDocuments as $document): ?>
                                            <?php
                                            $documentUrl = url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $document['id'];
                                            $documentPreviewKind = portal_file_preview_kind($document);
                                            ?>
                                            <article class="admin-request-doc-card admin-request-doc-card-<?= h($documentPreviewKind); ?>">
                                                <div class="admin-request-doc-thumb">
                                                    <?php if ($documentPreviewKind === 'image'): ?>
                                                        <a href="<?= h($documentUrl); ?>" target="_blank" aria-label="<?= h((string) $document['title']); ?> megnyitása">
                                                            <img src="<?= h($documentUrl); ?>" alt="<?= h((string) $document['title']); ?>" width="92" height="92" loading="lazy">
                                                        </a>
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

                                <?php endif; ?>

                                <div class="admin-request-section-title admin-request-subtitle">
                                    <h3>Fájlok</h3>
                                    <span><?= count($files); ?> db</span>
                                </div>
                                <?php if ($files === []): ?>
                                    <p class="request-admin-empty">Nincs feltöltött fájl ehhez az igényhez.</p>
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
                                                        <a href="<?= h($fileUrl); ?>" target="_blank" aria-label="<?= h($file['label']); ?> megnyitása">
                                                            <img src="<?= h($fileUrl); ?>" alt="<?= h($file['label']); ?>" width="92" height="92" loading="lazy">
                                                        </a>
                                                    <?php elseif ($previewKind === 'pdf'): ?>
                                                        <iframe src="<?= h($fileUrl); ?>#toolbar=0&navpanes=0" title="<?= h($file['label']); ?>" width="92" height="92" loading="lazy"></iframe>
                                                    <?php else: ?>
                                                        <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($file)); ?></span></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="admin-request-doc-meta">
                                                    <strong><?= h($file['label']); ?></strong>
                                                    <span><?= h($file['original_name']); ?></span>
                                                    <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($beforeWorkFiles !== [] || $afterWorkFiles !== []): ?>
                                    <div class="admin-request-section-title admin-request-subtitle">
                                        <h3>Szerelői fotók - kivitelezés előtt</h3>
                                        <span><?= count($beforeWorkFiles); ?> db</span>
                                    </div>
                                    <?php if ($beforeWorkFiles === []): ?>
                                        <p class="request-admin-empty">Még nincs kivitelezés előtti szerelői fotó feltöltve.</p>
                                    <?php else: ?>
                                        <div class="admin-request-doc-grid">
                                            <?php foreach ($beforeWorkFiles as $workFile): ?>
                                                <?php
                                                $workFileUrl = url_path('/admin/connection-requests/work-file') . '?id=' . (int) $workFile['id'];
                                                $workPreviewKind = portal_file_preview_kind($workFile);
                                                ?>
                                                <article class="admin-request-doc-card admin-request-doc-card-<?= h($workPreviewKind); ?>">
                                                    <div class="admin-request-doc-thumb">
                                                        <?php if ($workPreviewKind === 'image'): ?>
                                                            <a href="<?= h($workFileUrl); ?>" target="_blank" aria-label="<?= h($workFile['label']); ?> megnyitása">
                                                                <img src="<?= h($workFileUrl); ?>" alt="<?= h($workFile['label']); ?>" width="92" height="92" loading="lazy">
                                                            </a>
                                                        <?php else: ?>
                                                            <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($workFile)); ?></span></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="admin-request-doc-meta">
                                                        <strong><?= h($workFile['label']); ?></strong>
                                                        <span><?= h($workFile['original_name']); ?></span>
                                                        <a href="<?= h($workFileUrl); ?>" target="_blank">Megnyitás</a>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="admin-request-section-title admin-request-subtitle">
                                        <h3>Szerelői fotók - kivitelezés után</h3>
                                        <span><?= count($afterWorkFiles); ?> db</span>
                                    </div>
                                    <?php if ($afterWorkFiles === []): ?>
                                        <p class="request-admin-empty">Még nincs kivitelezés utáni szerelői fotó feltöltve.</p>
                                    <?php else: ?>
                                        <div class="admin-request-doc-grid">
                                            <?php foreach ($afterWorkFiles as $workFile): ?>
                                                <?php
                                                $workFileUrl = url_path('/admin/connection-requests/work-file') . '?id=' . (int) $workFile['id'];
                                                $workPreviewKind = portal_file_preview_kind($workFile);
                                                ?>
                                                <article class="admin-request-doc-card admin-request-doc-card-<?= h($workPreviewKind); ?>">
                                                    <div class="admin-request-doc-thumb">
                                                        <?php if ($workPreviewKind === 'image'): ?>
                                                            <a href="<?= h($workFileUrl); ?>" target="_blank" aria-label="<?= h($workFile['label']); ?> megnyitása">
                                                                <img src="<?= h($workFileUrl); ?>" alt="<?= h($workFile['label']); ?>" width="92" height="92" loading="lazy">
                                                            </a>
                                                        <?php else: ?>
                                                            <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($workFile)); ?></span></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="admin-request-doc-meta">
                                                        <strong><?= h($workFile['label']); ?></strong>
                                                        <span><?= h($workFile['original_name']); ?></span>
                                                        <a href="<?= h($workFileUrl); ?>" target="_blank">Megnyitás</a>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="admin-request-section-title admin-request-subtitle">
                                    <h3>Árajánlat</h3>
                                    <span><?= count($quotes); ?> db</span>
                                </div>
                                <?php if ($quotes === []): ?>
                                    <p class="request-admin-empty">
                                        Még nincs ehhez az igényhez készített árajánlat.
                                        <?php if ($quoteMissingReason !== ''): ?>
                                            <br>Oka: <?= h($quoteMissingReason); ?>
                                        <?php endif; ?>
                                    </p>
                                <?php else: ?>
                                    <div class="quote-mini-list">
                                        <?php foreach ($quotes as $quote): ?>
                                            <?php $quoteStatus = (string) ($quote['status'] ?? 'draft'); ?>
                                            <article class="quote-mini-card">
                                                <div>
                                                    <strong><?= h((string) $quote['quote_number']); ?></strong>
                                                    <span><?= h((string) $quote['subject']); ?></span>
                                                    <?php if (!empty($quote['accepted_at'])): ?>
                                                        <span>Elfogadva: <?= h((string) $quote['accepted_at']); ?></span>
                                                    <?php elseif (!empty($quote['sent_at'])): ?>
                                                        <span>Kiküldve: <?= h((string) $quote['sent_at']); ?></span>
                                                    <?php endif; ?>
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

                            <div class="request-admin-actions connection-crm-action-panel">
                                <?php if ($workflowStageSchemaReady): ?>
                                    <form class="workflow-stage-form" method="post" action="<?= h($detailUrl); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_workflow_stage">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id']; ?>">
                                        <label for="workflow_stage_<?= (int) $request['id']; ?>">Folyamatlépés</label>
                                        <select id="workflow_stage_<?= (int) $request['id']; ?>" name="admin_workflow_stage">
                                            <?php foreach ($workflowStages as $optionStageKey => $optionStage): ?>
                                                <option value="<?= h($optionStageKey); ?>" <?= $workflowStage === $optionStageKey ? 'selected' : ''; ?>>
                                                    <?= (int) $optionStage['number']; ?>. <?= h((string) $optionStage['title']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="button button-secondary" type="submit">Folyamat mentése</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($schemaErrors === []): ?>
                                    <form class="assignment-form" method="post" action="<?= h($detailUrl); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="assign_electrician">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id']; ?>">
                                        <?php if ($latestQuote === null): ?>
                                            <input type="hidden" name="electrician_user_id" value="">
                                            <div class="assignment-lock-note">
                                                <strong>Nem adható ki szerelőnek</strong>
                                                <span>Előbb készíts vagy tölts fel árajánlatot. Addig kötelező megadni, miért nincs ajánlat.</span>
                                            </div>
                                            <label for="quote_missing_reason_<?= (int) $request['id']; ?>">Miért nincs árajánlat?</label>
                                            <textarea id="quote_missing_reason_<?= (int) $request['id']; ?>" name="quote_missing_reason" rows="3" required <?= $quoteMissingReasonSchemaErrors !== [] ? 'disabled' : ''; ?>><?= h($quoteMissingReason); ?></textarea>
                                            <button class="button button-secondary" type="submit" <?= $quoteMissingReasonSchemaErrors !== [] ? 'disabled' : ''; ?>><?= !empty($request['assigned_electrician_user_id']) ? 'Megjegyzés mentése és kiadás visszavonása' : 'Megjegyzés mentése'; ?></button>
                                        <?php else: ?>
                                            <label for="electrician_<?= (int) $request['id']; ?>">Szerelő</label>
                                            <select id="electrician_<?= (int) $request['id']; ?>" name="electrician_user_id">
                                                <option value="">Nincs kiadva</option>
                                                <?php foreach ($electricians as $electrician): ?>
                                                    <option value="<?= (int) $electrician['user_id']; ?>" <?= (int) ($request['assigned_electrician_user_id'] ?? 0) === (int) $electrician['user_id'] ? 'selected' : ''; ?>>
                                                        <?= h((string) $electrician['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="button button-secondary" type="submit">Kiadás mentése</button>
                                            <?php if ($acceptedQuote === null): ?>
                                                <p class="assignment-warning">Van árajánlat, de az ügyfél még nem fogadta el.</p>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canManageMvmDocuments): ?>
                                    <a class="button" href="<?= h(url_path('/admin/connection-requests/mvm-documents') . '?id=' . (int) $request['id']); ?>">MVM dokumentumok</a>
                                <?php endif; ?>
                                <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests/edit') . '?id=' . (int) $request['id']); ?>">Igény szerkesztése</a>
                                <a class="button" href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . (int) $request['customer_id'] . '&request_id=' . (int) $request['id']); ?>">Árajánlat készítése</a>
                                <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests/quote-upload') . '?id=' . (int) $request['id']); ?>">Árajánlat feltöltése</a>
                            </div>
                        </div>
                            </div>
                        </div>
                    </article>
                    <?php endif; ?>
                    </details>
                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const input = document.querySelector('[data-connection-crm-search]');
    const count = document.querySelector('[data-connection-crm-count]');
    const items = Array.from(document.querySelectorAll('[data-connection-crm-item]'));
    const groups = Array.from(document.querySelectorAll('[data-connection-crm-status-group]'));

    items.forEach((item) => {
        item.addEventListener('toggle', () => {
            if (!item.open || item.dataset.connectionCrmLoaded === '1' || !item.dataset.connectionCrmDetailUrl) {
                return;
            }

            window.location.href = item.dataset.connectionCrmDetailUrl;
        });
    });

    if (!input || !count || items.length === 0) {
        return;
    }

    const searchable = items.map((item) => ({
        item,
        text: `${item.textContent} ${item.dataset.connectionCrmSearchText || ''}`.toLocaleLowerCase('hu-HU'),
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
            const groupItems = Array.from(group.querySelectorAll('[data-connection-crm-item]'));
            const groupVisible = groupItems.filter((item) => !item.hidden).length;
            const groupCount = group.querySelector('[data-connection-crm-status-count]');

            group.hidden = groupVisible === 0;

            if (groupCount) {
                groupCount.textContent = `${groupVisible} látható`;
            }
        });

        count.textContent = `${visible} db`;
    });
});
</script>
