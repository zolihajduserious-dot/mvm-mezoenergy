<?php
declare(strict_types=1);

require_role(['electrician']);

$schemaErrors = electrician_schema_errors();
$user = current_user();
$electrician = current_electrician();

if (!is_array($user) || ($schemaErrors === [] && $electrician === null)) {
    set_flash('error', 'A szerelői adatok nem találhatók.');
    redirect('/login');
}

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$request = $requestId ? find_connection_request($requestId) : null;

if ($schemaErrors === [] && $requestId && ($request === null || !electrician_can_manage_connection_request($request))) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

$errors = [];
$flash = get_flash();
$requestTypeOptions = connection_request_type_options();
$customerForm = normalize_customer_data([
    'source' => 'Szerelői felmérés',
    'status' => 'Szerelői felmérés',
    'contact_data_accepted' => 1,
]);
$workForm = normalize_connection_request_data([]);

if ($request !== null) {
    $customer = find_customer((int) $request['customer_id']);
    $customerForm = $customer !== null ? normalize_customer_data($customer) : $customerForm;
    $workForm = normalize_connection_request_data($request);
}

if (is_post() && $schemaErrors === []) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_survey') {
        $customerForm = normalize_customer_data($_POST);
        $customerForm['source'] = $customerForm['source'] !== '' ? $customerForm['source'] : 'Szerelői felmérés';
        $customerForm['status'] = $customerForm['status'] !== '' ? $customerForm['status'] : 'Szerelői felmérés';
        $customerForm['contact_data_accepted'] = 1;
        $workForm = normalize_connection_request_data($_POST);
        $errors = array_merge(validate_customer_data($customerForm, false), validate_connection_request_data($workForm, [], false));

        if ($errors === []) {
            try {
                $customerId = create_customer($customerForm, null, (int) $user['id']);
                $savedRequestId = save_connection_request($customerId, $workForm, null, (int) $user['id']);
                assign_connection_request_to_electrician($savedRequestId, (int) $user['id']);
                set_flash('success', 'A felmérés rögzítve lett, és a te munkáid között marad.');
                redirect('/electrician/work-request?id=' . $savedRequestId);
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'A felmérés mentése sikertelen.';
            }
        }
    }

    if ($request !== null && $action === 'close_workflow_stage') {
        $result = close_connection_request_workflow_stage((int) $request['id']);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A munkafolyamat lezárása sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id']);
    }

    if ($request !== null && in_array($action, ['complete_before', 'complete_after'], true)) {
        $stage = $action === 'complete_before' ? 'before' : 'after';
        $errors = validate_electrician_work_uploads((int) $request['id'], $stage, $_FILES);

        if ($stage === 'after' && empty($request['before_photos_completed_at'])) {
            $errors[] = 'Az utána fotók előtt előbb az induló fotókat kell lezárni.';
        }

        if ($errors === []) {
            try {
                $uploadMessages = handle_electrician_work_uploads((int) $request['id'], $stage, $_FILES);

                if ($uploadMessages !== []) {
                    $errors = array_merge($errors, $uploadMessages);
                } else {
                    complete_electrician_work_stage((int) $request['id'], $stage);
                    $notification = send_electrician_work_stage_notification((int) $request['id'], $stage);
                    set_flash('success', ($stage === 'before' ? 'Az induló fotók mentve lettek.' : 'A kész munka fotói mentve lettek.') . ' ' . $notification['message']);
                    redirect('/electrician/work-request?id=' . (int) $request['id']);
                }
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'A munkafotók mentése sikertelen.';
            }
        }
    }
}

$acceptedQuote = $request !== null ? accepted_quote_for_connection_request((int) $request['id']) : null;
$latestQuote = $request !== null ? latest_quote_for_connection_request((int) $request['id']) : null;
$workQuote = $acceptedQuote ?? $latestQuote;
$workQuoteLines = $workQuote !== null ? quote_lines((int) $workQuote['id']) : [];
$quoteStatusLabels = quote_status_labels();
$requestDocuments = $request !== null ? connection_request_documents((int) $request['id']) : [];
$beforeFiles = $request !== null ? connection_request_work_files((int) $request['id'], 'before') : [];
$afterFiles = $request !== null ? connection_request_work_files((int) $request['id'], 'after') : [];
$customerFiles = $request !== null ? connection_request_files((int) $request['id']) : [];
$workflowStage = $request !== null ? connection_request_admin_workflow_stage($request, $latestQuote, $acceptedQuote, $requestDocuments) : 'case_starting';
$workflowDefinition = admin_workflow_stage_definitions()[$workflowStage] ?? null;
$nextWorkflowStage = next_admin_workflow_stage($workflowStage);
$siteAddress = $request !== null ? trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? '')) : '';
$workQuoteStatus = $workQuote !== null ? (string) ($workQuote['status'] ?? 'draft') : '';
$quoteState = quote_state_summary($latestQuote, $acceptedQuote, $request !== null ? connection_request_quote_missing_reason($request) : '');
$quoteSummaryLabel = (string) $quoteState['status'];
$quoteSummaryAmount = (string) $quoteState['amount'];
$minicrmProfile = $request !== null ? minicrm_customer_profile_for_connection_request($request) : null;
$minicrmName = is_array($minicrmProfile) ? minicrm_customer_profile_display_value($minicrmProfile, 'person_name', ['Szemely1 Nev', 'Személy1: Név']) : '';
$minicrmEmail = is_array($minicrmProfile) ? minicrm_customer_profile_display_value($minicrmProfile, 'person_email', ['Szemely1 Email', 'Személy1: Email']) : '';
$minicrmPhone = is_array($minicrmProfile) ? minicrm_customer_profile_display_value($minicrmProfile, 'person_phone', ['Szemely1 Telefon', 'Személy1: Telefon']) : '';
$displayCustomerName = $request !== null ? (trim((string) ($request['requester_name'] ?? '')) ?: $minicrmName) : '';
$displayCustomerEmail = $request !== null ? (trim((string) ($request['email'] ?? '')) ?: $minicrmEmail) : '';
$displayCustomerPhone = $request !== null ? (trim((string) ($request['phone'] ?? '')) ?: $minicrmPhone) : '';
$mvmEmailThreads = $request !== null ? mvm_email_threads_with_messages((int) $request['id']) : [];
$mvmThreadStatusLabels = mvm_email_thread_status_labels();
?>
<section class="admin-section">
    <div class="container admin-requests-container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Szerelői portál</p>
                <h1><?= $request === null ? 'Új ügyfél felmérése' : 'Kivitelezési munka'; ?></h1>
                <p><?= $request === null ? 'Új ügyfelet és mérőhelyi igényt rögzíthetsz, ami a te neved alatt marad.' : h((string) $displayCustomerName . ' · ' . (string) $request['project_name']); ?></p>
            </div>
            <div class="admin-actions">
                <?php if ($request !== null): ?><a class="button" href="<?= h(authorization_signature_url($request)); ?>" target="_blank">Meghatalmazás online aláírása</a><?php endif; ?>
                <a class="button button-secondary" href="<?= h(url_path('/electrician/work-requests')); ?>">Munkáim</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-error">
                <p>Előbb futtasd le phpMyAdminban a <strong>database/electrician_workflow.sql</strong> fájlt.</p>
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($schemaErrors === [] && $request === null): ?>
            <form class="form" method="post" action="<?= h(url_path('/electrician/work-request')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="create_survey">

                <div class="form-grid two">
                    <section class="auth-panel">
                        <h2>Ügyfél adatai</h2>
                        <label for="requester_name">Név</label>
                        <input id="requester_name" name="requester_name" value="<?= h($customerForm['requester_name']); ?>" required>
                        <label for="phone">Telefon</label>
                        <input id="phone" name="phone" value="<?= h($customerForm['phone']); ?>" required>
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= h($customerForm['email']); ?>" required>
                        <label for="postal_code">Irányítószám</label>
                        <input id="postal_code" name="postal_code" value="<?= h($customerForm['postal_code']); ?>" required>
                        <label for="city">Település</label>
                        <input id="city" name="city" value="<?= h($customerForm['city']); ?>" required>
                        <label for="postal_address">Cím</label>
                        <input id="postal_address" name="postal_address" value="<?= h($customerForm['postal_address']); ?>" required>
                        <label for="notes_customer">Ügyfél megjegyzés</label>
                        <textarea id="notes_customer" name="notes" rows="4"><?= h($customerForm['notes']); ?></textarea>
                    </section>

                    <section class="auth-panel">
                        <h2>Igény adatai</h2>
                        <label for="request_type">Igénytípus</label>
                        <select id="request_type" name="request_type" required>
                            <?php foreach ($requestTypeOptions as $typeKey => $typeLabel): ?>
                                <option value="<?= h($typeKey); ?>" <?= $workForm['request_type'] === $typeKey ? 'selected' : ''; ?>><?= h($typeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="project_name">Igény megnevezése</label>
                        <input id="project_name" name="project_name" value="<?= h($workForm['project_name']); ?>" placeholder="Példa: Kovács Béla - 3 fázisra átállás">
                        <label for="site_postal_code">Kivitelezés irányítószáma</label>
                        <input id="site_postal_code" name="site_postal_code" value="<?= h($workForm['site_postal_code']); ?>" required>
                        <label for="site_address">Kivitelezés címe</label>
                        <input id="site_address" name="site_address" value="<?= h($workForm['site_address']); ?>" required>
                        <label for="hrsz">HRSZ</label>
                        <input id="hrsz" name="hrsz" value="<?= h($workForm['hrsz']); ?>">
                        <label for="meter_serial">Mérő gyári száma</label>
                        <input id="meter_serial" name="meter_serial" value="<?= h($workForm['meter_serial']); ?>">
                        <label for="existing_general_power">Meglévő teljesítmény</label>
                        <input id="existing_general_power" name="existing_general_power" value="<?= h($workForm['existing_general_power']); ?>">
                    </section>
                </div>

                <div class="form-actions">
                    <button class="button" type="submit">Felmérés mentése</button>
                </div>
            </form>
        <?php elseif ($schemaErrors === [] && $request !== null): ?>
            <article class="request-admin-card">
                <div class="request-admin-card-head">
                    <div>
                        <span class="portal-kicker">#<?= (int) $request['id']; ?> · <?= h($workflowDefinition !== null ? (string) $workflowDefinition['title'] : electrician_work_status_label((string) ($request['electrician_status'] ?? 'assigned'))); ?></span>
                        <h2><?= h((string) $request['project_name']); ?></h2>
                        <p><?= h(connection_request_type_label($request['request_type'] ?? null)); ?> · <?= h($siteAddress !== '' ? $siteAddress : '-'); ?></p>
                    </div>
                    <div class="request-admin-status">
                        <?php if ($workflowDefinition !== null): ?>
                            <span class="status-badge status-badge-<?= h((string) ($workflowDefinition['variant'] ?? 'draft')); ?>"><?= h((string) $workflowDefinition['title']); ?></span>
                        <?php endif; ?>
                        <span class="status-badge status-badge-<?= h((string) ($request['electrician_status'] ?? 'assigned')); ?>"><?= h(electrician_work_status_label((string) ($request['electrician_status'] ?? 'assigned'))); ?></span>
                        <?php if ($acceptedQuote !== null): ?>
                            <span class="status-badge status-badge-accepted">Ajánlat elfogadva</span>
                        <?php elseif ($workQuote !== null): ?>
                            <span class="status-badge status-badge-<?= h($workQuoteStatus); ?>"><?= h($quoteStatusLabels[$workQuoteStatus] ?? $workQuoteStatus); ?></span>
                        <?php endif; ?>
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

                <?php if ($workflowDefinition !== null): ?>
                    <section class="admin-request-panel workflow-stage-panel electrician-workflow-panel">
                        <div class="admin-request-section-title">
                            <h3>Munkafolyamat</h3>
                            <span><?= (int) $workflowDefinition['number']; ?>. <?= h((string) $workflowDefinition['title']); ?></span>
                        </div>
                        <p class="muted-text"><?= h((string) $workflowDefinition['description']); ?></p>
                        <form class="portal-assignment-form" method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>" onsubmit="return confirm('Biztosan lezárod ezt a munkafolyamat-lépést?') && confirm('Második megerősítés: tényleg tovább lépteted a munka státuszát?');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="close_workflow_stage">
                            <?php if ($nextWorkflowStage !== null): ?>
                                <button class="button" type="submit">Lezárom ezt a folyamatot</button>
                                <small>Következő státusz: <?= h(admin_workflow_stage_label($nextWorkflowStage)); ?></small>
                            <?php else: ?>
                                <button class="button" type="submit" disabled>Utolsó státuszban van</button>
                            <?php endif; ?>
                        </form>
                    </section>
                <?php endif; ?>

                <div class="admin-request-panel-grid">
                    <section class="admin-request-panel">
                        <h3>Ügyfél</h3>
                        <dl class="admin-request-data-list">
                            <div><dt>Név</dt><dd><?= h($displayCustomerName !== '' ? $displayCustomerName : '-'); ?></dd></div>
                            <div><dt>Email</dt><dd><?= h($displayCustomerEmail !== '' ? $displayCustomerEmail : '-'); ?></dd></div>
                            <div><dt>Telefon</dt><dd><?= h($displayCustomerPhone !== '' ? $displayCustomerPhone : '-'); ?></dd></div>
                        </dl>
                    </section>

                    <section class="admin-request-panel">
                        <h3>Igény adatai</h3>
                        <dl class="admin-request-data-list">
                            <div><dt>Igénytípus</dt><dd><?= h(connection_request_type_label($request['request_type'] ?? null)); ?></dd></div>
                            <div><dt>Cím</dt><dd><?= h($siteAddress !== '' ? $siteAddress : '-'); ?></dd></div>
                            <div><dt>HRSZ</dt><dd><?= h((string) ($request['hrsz'] ?: '-')); ?></dd></div>
                            <div><dt>Mérő</dt><dd><?= h((string) ($request['meter_serial'] ?: '-')); ?></dd></div>
                            <div><dt>Fogyasztási hely</dt><dd><?= h((string) ($request['consumption_place_id'] ?: '-')); ?></dd></div>
                        </dl>
                    </section>

                    <section class="admin-request-panel admin-request-panel-wide">
                        <h3>Teljesítmény és ajánlat</h3>
                        <dl class="admin-request-data-list admin-request-data-list-compact">
                            <div><dt>Mindennapszaki</dt><dd><?= h((string) ($request['existing_general_power'] ?: '-')); ?></dd></div>
                            <div><dt>Igényelt</dt><dd><?= h((string) ($request['requested_general_power'] ?: '-')); ?></dd></div>
                            <div><dt>H tarifa</dt><dd><?= h(($request['existing_h_tariff_power'] ?: '-') . ' / ' . ($request['requested_h_tariff_power'] ?: '-')); ?></dd></div>
                            <div><dt>Vezérelt</dt><dd><?= h(($request['existing_controlled_power'] ?: '-') . ' / ' . ($request['requested_controlled_power'] ?: '-')); ?></dd></div>
                            <div><dt>Árajánlat</dt><dd><?= h($quoteSummaryAmount); ?> · <?= h($quoteSummaryLabel); ?></dd></div>
                            <?php if (!empty($request['notes'])): ?>
                                <div class="admin-request-data-wide"><dt>Megjegyzés</dt><dd><?= h((string) $request['notes']); ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    </section>
                </div>

                <div class="request-admin-footer">
                    <section class="admin-request-panel admin-request-documents">
                        <div class="admin-request-section-title">
                            <h3>Ügyfél által feltöltött fájlok</h3>
                            <span><?= count($customerFiles); ?> db</span>
                        </div>
                        <?php if ($customerFiles === []): ?>
                            <p class="request-admin-empty">Nincs ügyfél által feltöltött fájl ehhez a munkához.</p>
                        <?php else: ?>
                            <div class="admin-request-doc-grid">
                                <?php foreach ($customerFiles as $file): ?>
                                    <?php
                                    $fileUrl = url_path('/electrician/work-requests/customer-file') . '?id=' . (int) $file['id'];
                                    $previewKind = portal_file_preview_kind($file);
                                    ?>
                                    <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
                                        <div class="admin-request-doc-thumb">
                                            <?php if ($previewKind === 'image'): ?>
                                                <a href="<?= h($fileUrl); ?>" target="_blank" aria-label="<?= h((string) $file['label']); ?> megnyitása">
                                                    <img src="<?= h($fileUrl); ?>" alt="<?= h((string) $file['label']); ?>" width="112" height="112" loading="lazy">
                                                </a>
                                            <?php elseif ($previewKind === 'pdf'): ?>
                                                <iframe src="<?= h($fileUrl); ?>#toolbar=0&navpanes=0" title="<?= h((string) $file['label']); ?>" width="112" height="112" loading="lazy"></iframe>
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
                            </div>
                        <?php endif; ?>

                        <div class="admin-request-section-title admin-request-subtitle">
                            <h3>Árajánlat a kivitelezéshez</h3>
                            <span><?= $workQuote === null ? '0 db' : '1 db'; ?></span>
                        </div>
                        <?php if ($workQuote === null): ?>
                            <p class="request-admin-empty">Ehhez a munkához még nincs árajánlat kapcsolva.</p>
                        <?php else: ?>
                            <div class="quote-mini-list">
                                <article class="quote-mini-card quote-mini-card-featured">
                                    <div>
                                        <strong><?= h((string) $workQuote['quote_number']); ?></strong>
                                        <span><?= h((string) $workQuote['subject']); ?></span>
                                        <?php if ($acceptedQuote === null): ?>
                                            <span>Figyelem: ez az ajánlat még nincs ügyfél által elfogadva.</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="status-badge status-badge-<?= h($workQuoteStatus); ?>"><?= h($quoteStatusLabels[$workQuoteStatus] ?? $workQuoteStatus); ?></span>
                                        <strong><?= h(quote_display_total($workQuote)); ?></strong>
                                    </div>
                                </article>
                            </div>
                            <?php if ($workQuoteLines !== []): ?>
                                <div class="table-wrap compact-table-wrap">
                                    <table class="data-table">
                                        <thead><tr><th>Tétel</th><th>Mennyiség</th><th>Összesen</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($workQuoteLines as $line): ?>
                                                <tr>
                                                    <td><strong><?= h((string) $line['name']); ?></strong><span><?= h((string) $line['category']); ?></span></td>
                                                    <td><?= h((string) $line['quantity']); ?> <?= h((string) $line['unit']); ?></td>
                                                    <td><?= h(format_money($line['line_gross'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr>
                                                <td colspan="2"><strong>Ügyféltől elkérendő összeg</strong></td>
                                                <td><strong><?= h(format_money($workQuote['total_gross'])); ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </section>

                    <?php if ($mvmEmailThreads !== []): ?>
                        <section class="admin-request-panel admin-request-documents">
                            <div class="admin-request-section-title">
                                <h3>MVM levelezés</h3>
                                <span><?= count($mvmEmailThreads); ?> db</span>
                            </div>
                            <div class="mvm-mail-thread-list mvm-mail-thread-list-compact">
                                <?php foreach ($mvmEmailThreads as $thread): ?>
                                    <article class="mvm-mail-thread">
                                        <div class="mvm-mail-thread-head">
                                            <div>
                                                <span class="portal-kicker"><?= h((string) $thread['document_label']); ?></span>
                                                <strong><?= h($mvmThreadStatusLabels[$thread['status']] ?? (string) $thread['status']); ?></strong>
                                            </div>
                                            <span><?= h((string) ($thread['last_message_at'] ?: $thread['created_at'])); ?></span>
                                        </div>
                                        <p><?= h(latest_mvm_email_message_preview($thread)); ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

            <?php foreach (['before' => 'Kivitelezés előtti kötelező fotók', 'after' => 'Kivitelezés utáni kötelező fotók'] as $stage => $stageTitle): ?>
                <?php
                $stageFiles = $stage === 'before' ? $beforeFiles : $afterFiles;
                $stageLocked = $stage === 'after' && empty($request['before_photos_completed_at']);
                ?>
                <section class="admin-request-panel admin-request-documents electrician-work-stage-panel">
                    <div class="admin-request-section-title">
                        <h3><?= h($stageTitle); ?></h3>
                        <span><?= count($stageFiles); ?> db</span>
                    </div>
                    <p class="muted-text"><?= $stage === 'after' ? 'Az elkészült beavatkozási lap fotója is kötelező.' : 'Ezeket a képeket a munka megkezdése előtt kell feltölteni.'; ?></p>
                    <?php if ($stageLocked): ?>
                        <div class="alert alert-info"><p>Az utána fotókat az induló fotók lezárása után lehet feltölteni.</p></div>
                    <?php endif; ?>

                    <?php if ($stageFiles !== []): ?>
                        <div class="admin-request-doc-grid">
                            <?php foreach ($stageFiles as $file): ?>
                                <?php
                                $fileUrl = url_path('/electrician/work-requests/file') . '?id=' . (int) $file['id'];
                                $previewKind = portal_file_preview_kind($file);
                                ?>
                                <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
                                    <div class="admin-request-doc-thumb">
                                        <a href="<?= h($fileUrl); ?>" target="_blank" aria-label="<?= h((string) $file['label']); ?> megnyitása">
                                            <img src="<?= h($fileUrl); ?>" alt="<?= h((string) $file['label']); ?>" width="112" height="112" loading="lazy">
                                        </a>
                                    </div>
                                    <div class="admin-request-doc-meta">
                                        <strong><?= h((string) $file['label']); ?></strong>
                                        <span><?= h((string) $file['original_name']); ?></span>
                                        <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="<?= $stage === 'before' ? 'complete_before' : 'complete_after'; ?>">
                        <div class="file-upload-grid">
                            <?php foreach (electrician_work_file_definitions($stage) as $key => $definition): ?>
                                <?php $hasExisting = connection_request_has_work_file_type((int) $request['id'], $stage, (string) $key); ?>
                                <label class="file-upload-item">
                                    <span><?= h((string) $definition['label']); ?> *</span>
                                    <small><?= $hasExisting ? 'Már feltöltve, de új képet is hozzáadhatsz.' : 'Kötelező fotó. Telefonon a kamera megnyílik.'; ?></small>
                                    <input
                                        name="work_file_<?= h($stage); ?>_<?= h((string) $key); ?>[]"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        capture="environment"
                                        <?= !empty($definition['multiple']) ? 'multiple' : ''; ?>
                                        <?= $hasExisting ? '' : 'required'; ?>
                                        <?= $stageLocked ? 'disabled' : ''; ?>
                                    >
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-actions">
                            <button class="button" type="submit" <?= $stageLocked ? 'disabled' : ''; ?>><?= $stage === 'before' ? 'Induló fotók mentése' : 'Kész munka lezárása'; ?></button>
                        </div>
                    </form>
                </section>
            <?php endforeach; ?>
                </div>
            </article>
        <?php endif; ?>
    </div>
</section>
