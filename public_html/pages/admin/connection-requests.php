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
                redirect('/admin/connection-requests');
            }

            assign_connection_request_to_electrician((int) $assignRequestId, $electricianUserId);
            $assignmentMessage = $electricianUserId === null ? 'A munka visszakerült kiosztatlan állapotba.' : 'A munka ki lett adva a szerelőnek.';

            if ($electricianUserId !== null) {
                $notification = send_electrician_assignment_email((int) $assignRequestId, $electricianUserId);
                $assignmentMessage .= ' ' . $notification['message'];
            }

            set_flash('success', $assignmentMessage);
            redirect('/admin/connection-requests');
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
        redirect('/admin/connection-requests');
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
            redirect('/admin/connection-requests');
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
?>
<section class="admin-section">
    <div class="container admin-requests-container">
        <div class="admin-header">
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
            <div class="admin-workflow-board" aria-label="Admin folyamatlépések">
                <?php foreach ($workflowStages as $stageKey => $stage): ?>
                    <?php $stageCount = count($workflowBuckets[$stageKey] ?? []); ?>
                    <a class="admin-workflow-card admin-workflow-card-<?= h((string) $stage['variant']); ?>" href="#workflow-stage-<?= h($stageKey); ?>">
                        <span><?= (int) $stage['number']; ?></span>
                        <strong><?= h((string) $stage['title']); ?></strong>
                        <em><?= $stageCount; ?> igény</em>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="admin-workflow-list">
                <?php foreach ($workflowStages as $stageKey => $stage): ?>
                    <?php $stageRequests = $workflowBuckets[$stageKey] ?? []; ?>
                    <section class="admin-workflow-stage" id="workflow-stage-<?= h($stageKey); ?>">
                        <div class="admin-workflow-stage-head">
                            <div>
                                <span class="portal-kicker"><?= (int) $stage['number']; ?>. lépés</span>
                                <h2><?= h((string) $stage['title']); ?></h2>
                                <p><?= h((string) $stage['description']); ?></p>
                            </div>
                            <strong><?= count($stageRequests); ?> db</strong>
                        </div>

                        <?php if ($stageRequests === []): ?>
                            <p class="request-admin-empty">Ebben a folyamatlépésben most nincs igény.</p>
                        <?php else: ?>
                            <div class="request-admin-list">
                <?php foreach ($stageRequests as $request): ?>
                    <?php
                    $context = $requestContexts[(int) $request['id']] ?? [];
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
                    $requestOwnerType = !empty($request['contractor_name']) ? 'Generálkivitelezőn keresztül' : 'Közvetlen ügyfél';
                    ?>

                    <details class="admin-workflow-request">
                        <summary class="admin-workflow-request-summary">
                            <span class="admin-workflow-request-id">#<?= (int) $request['id']; ?></span>
                            <span class="admin-workflow-request-main">
                                <strong><?= h($request['project_name']); ?></strong>
                                <small><?= h($request['requester_name']); ?> · <?= h($siteAddress !== '' ? $siteAddress : '-'); ?></small>
                            </span>
                            <span class="admin-workflow-request-meta">
                                <span><?= h((string) $workflowStageDefinition['title']); ?></span>
                                <strong><?= h($quoteSummaryAmount); ?></strong>
                                <?php if ($workflowStage === 'assigned_waiting_execution'): ?>
                                    <span><?= h(admin_workflow_assignment_due_text($request)); ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="admin-workflow-request-badges">
                                <span class="status-badge status-badge-<?= h($requestStatus); ?>"><?= h($requestStatusLabels[$requestStatus] ?? $requestStatus); ?></span>
                                <?php if ($schemaErrors === []): ?>
                                    <span class="status-badge status-badge-<?= h((string) ($request['electrician_status'] ?? 'unassigned')); ?>"><?= h($electricianStatusLabels[$request['electrician_status'] ?? 'unassigned'] ?? (string) ($request['electrician_status'] ?? 'unassigned')); ?></span>
                                <?php endif; ?>
                            </span>
                        </summary>

                    <article class="request-admin-card">
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

                        <div class="admin-request-panel-grid">
                            <section class="admin-request-panel">
                                <h3>Ügyfél</h3>
                                <dl class="admin-request-data-list">
                                    <div><dt>Név</dt><dd><?= h($request['requester_name']); ?></dd></div>
                                    <div><dt>Típus</dt><dd><?= h($requestOwnerType); ?></dd></div>
                                    <div><dt>Email</dt><dd><?= h($request['email']); ?></dd></div>
                                    <div><dt>Telefon</dt><dd><?= h($request['phone']); ?></dd></div>
                                    <?php if (!empty($request['contractor_name'])): ?>
                                        <div><dt>Generálkivitelező</dt><dd><?= h($request['contractor_name']); ?></dd></div>
                                        <div><dt>Kapcsolattartó</dt><dd><?= h($request['contractor_contact_name'] ?: '-'); ?></dd></div>
                                    <?php endif; ?>
                                    <?php if ($schemaErrors === []): ?>
                                        <div><dt>Kiadva szerelőnek</dt><dd><?= !empty($request['electrician_name']) ? h((string) $request['electrician_name']) : 'Nincs kiadva'; ?></dd></div>
                                    <?php endif; ?>
                                </dl>
                            </section>

                            <section class="admin-request-panel">
                                <h3>Igény adatai</h3>
                                <dl class="admin-request-data-list">
                                    <div><dt>Igénytípus</dt><dd><?= h(connection_request_type_label($request['request_type'] ?? null)); ?></dd></div>
                                    <div><dt>Cím</dt><dd><?= h($siteAddress !== '' ? $siteAddress : '-'); ?></dd></div>
                                    <div><dt>HRSZ</dt><dd><?= h($request['hrsz'] ?: '-'); ?></dd></div>
                                    <div><dt>Mérő</dt><dd><?= h($request['meter_serial'] ?: '-'); ?></dd></div>
                                    <div><dt>Fogyasztási hely</dt><dd><?= h($request['consumption_place_id'] ?: '-'); ?></dd></div>
                                </dl>
                            </section>

                            <section class="admin-request-panel admin-request-panel-wide">
                                <h3>Teljesítmény és ajánlat</h3>
                                <dl class="admin-request-data-list admin-request-data-list-compact">
                                    <div><dt>Mindennapszaki</dt><dd><?= h($request['existing_general_power'] ?: '-'); ?></dd></div>
                                    <div><dt>Igényelt</dt><dd><?= h($request['requested_general_power'] ?: '-'); ?></dd></div>
                                    <div><dt>H tarifa</dt><dd><?= h(($request['existing_h_tariff_power'] ?: '-') . ' / ' . ($request['requested_h_tariff_power'] ?: '-')); ?></dd></div>
                                    <div><dt>Vezérelt</dt><dd><?= h(($request['existing_controlled_power'] ?: '-') . ' / ' . ($request['requested_controlled_power'] ?: '-')); ?></dd></div>
                                    <?php if (!empty($request['notes'])): ?>
                                        <div class="admin-request-data-wide"><dt>Megjegyzés</dt><dd><?= h($request['notes']); ?></dd></div>
                                    <?php endif; ?>
                                    <div><dt>Árajánlat</dt><dd><?= h($quoteSummaryAmount); ?> · <?= h($quoteSummaryLabel); ?></dd></div>
                                </dl>
                            </section>
                        </div>

                        <div class="request-admin-footer">
                            <section class="admin-request-panel admin-request-documents">
                                <?php if ($canManageMvmDocuments): ?>
                                <div class="admin-request-section-title">
                                    <h3>Beavatkozási lap</h3>
                                    <span><?= count($mvmDocuments); ?> db</span>
                                </div>
                                <form class="intervention-upload-form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/admin/connection-requests')); ?>">
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

                            <div class="request-admin-actions">
                                <?php if ($workflowStageSchemaReady): ?>
                                    <form class="workflow-stage-form" method="post" action="<?= h(url_path('/admin/connection-requests')); ?>">
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
                                    <form class="assignment-form" method="post" action="<?= h(url_path('/admin/connection-requests')); ?>">
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
                    </article>
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
