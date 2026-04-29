<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$minicrmItemId = filter_input(INPUT_GET, 'minicrm_item', FILTER_VALIDATE_INT);
$minicrmItem = null;
$minicrmLinkResult = null;

if ($minicrmItemId) {
    $minicrmLinkResult = ensure_minicrm_work_item_connection_request((int) $minicrmItemId);

    if (!($minicrmLinkResult['ok'] ?? false)) {
        set_flash('error', (string) ($minicrmLinkResult['message'] ?? 'A MiniCRM munka MVM dokumentumhoz kapcsolasa sikertelen.'));
        redirect('/admin/minicrm-import?item=' . (int) $minicrmItemId . '#minicrm-work-' . (int) $minicrmItemId);
    }

    $minicrmItem = find_minicrm_work_item((int) $minicrmItemId);
    $requestId = (int) ($minicrmLinkResult['request_id'] ?? 0);
} else {
    $requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
}

$request = $requestId ? find_connection_request((int) $requestId) : null;

if ($request === null) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

$isMiniCrmContext = $minicrmItemId && $minicrmItem !== null;
$mvmPageUrl = $isMiniCrmContext
    ? url_path('/admin/minicrm-import/mvm-documents') . '?minicrm_item=' . (int) $minicrmItemId
    : url_path('/admin/connection-requests/mvm-documents') . '?id=' . (int) $request['id'];
$mvmRedirectPath = $isMiniCrmContext
    ? '/admin/minicrm-import/mvm-documents?minicrm_item=' . (int) $minicrmItemId
    : '/admin/connection-requests/mvm-documents?id=' . (int) $request['id'];
$mvmBackUrl = $isMiniCrmContext
    ? url_path('/admin/minicrm-import') . '?item=' . (int) $minicrmItemId . '#minicrm-work-' . (int) $minicrmItemId
    : url_path('/admin/minicrm-import') . '?request=' . (int) $request['id'] . '#portal-work-' . (int) $request['id'];
$mvmBackLabel = $isMiniCrmContext ? 'Vissza a MiniCRM munkahoz' : 'Vissza a munkahoz';

$schemaErrors = mvm_document_schema_errors();
$mvmFormSchemaErrors = mvm_form_schema_errors();
$mvmMailSchemaErrors = mvm_mail_schema_errors();
$types = mvm_document_types();
$uploadTypes = mvm_document_types();
$errors = [];
$mvmFormAction = is_post() ? (string) ($_POST['action'] ?? '') : '';
$isMvmFormPost = in_array($mvmFormAction, ['save_mvm_form', 'generate_mvm_docx', 'generate_mvm_pdf', 'generate_plan_docx', 'generate_plan_pdf', 'generate_handover_docx', 'generate_handover_pdf'], true);
$selectedType = (string) ($_POST['document_type'] ?? 'submitted_request');
$title = trim((string) ($_POST['title'] ?? ''));
$mvmFormValues = $isMvmFormPost
    ? array_merge(mvm_form_default_values($request), normalize_mvm_form_data($_POST))
    : connection_request_mvm_form_values($request);
$templateErrors = mvm_form_template_errors((string) ($mvmFormValues['mvm_contractor'] ?? ''));
$planTemplateErrors = mvm_plan_template_errors((string) ($mvmFormValues['mvm_contractor'] ?? ''));
$handoverTemplateErrors = mvm_technical_handover_template_errors((string) ($mvmFormValues['mvm_contractor'] ?? ''));

if (is_post()) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? 'upload');

    if (in_array($action, ['save_mvm_form', 'generate_mvm_docx', 'generate_mvm_pdf', 'generate_plan_docx', 'generate_plan_pdf', 'generate_handover_docx', 'generate_handover_pdf'], true)) {
        if ($mvmFormSchemaErrors !== []) {
            $errors = array_merge($errors, $mvmFormSchemaErrors);
        }

        if (in_array($action, ['generate_mvm_docx', 'generate_mvm_pdf'], true) && $templateErrors !== []) {
            $errors = array_merge($errors, $templateErrors);
        }

        if (in_array($action, ['generate_plan_docx', 'generate_plan_pdf'], true) && $planTemplateErrors !== []) {
            $errors = array_merge($errors, $planTemplateErrors);
        }

        if (in_array($action, ['generate_handover_docx', 'generate_handover_pdf'], true) && $handoverTemplateErrors !== []) {
            $errors = array_merge($errors, $handoverTemplateErrors);
        }

        if ($errors === []) {
            try {
                save_connection_request_mvm_form((int) $request['id'], $_POST, $_FILES['sketch_image'] ?? null);

                if ($action === 'generate_mvm_pdf') {
                    $result = generate_primavill_mvm_pdf((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } elseif ($action === 'generate_mvm_docx') {
                    $result = generate_primavill_mvm_docx((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } elseif ($action === 'generate_plan_pdf') {
                    $result = generate_mvm_execution_plan_pdf((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } elseif ($action === 'generate_plan_docx') {
                    $result = generate_mvm_execution_plan_docx((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } elseif ($action === 'generate_handover_pdf') {
                    $result = generate_mvm_technical_handover_pdf((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } elseif ($action === 'generate_handover_docx') {
                    $result = generate_mvm_technical_handover_docx((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } else {
                    set_flash('success', 'Az MVM űrlap adatai elmentve.');
                }

                redirect($mvmRedirectPath . '&mvm_notice=1#mvm-generator-actions');
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az MVM űrlap mentése sikertelen.';
            }
        }
    } elseif ($action === 'build_package') {
        if ($schemaErrors !== []) {
            $errors = array_merge($errors, $schemaErrors);
        } else {
            $result = generate_connection_request_complete_package((int) $request['id']);

            if ($result['ok']) {
                set_flash('success', $result['message']);
                redirect($mvmRedirectPath);
            }

            $errors[] = (string) $result['message'];
        }
    } elseif ($action === 'build_execution_plan_package') {
        if ($schemaErrors !== []) {
            $errors = array_merge($errors, $schemaErrors);
        } else {
            $result = generate_connection_request_execution_plan_package((int) $request['id']);

            if ($result['ok']) {
                set_flash('success', $result['message']);
                redirect($mvmRedirectPath);
            }

            $errors[] = (string) $result['message'];
        }
    } elseif ($action === 'build_handover_package') {
        if ($schemaErrors !== []) {
            $errors = array_merge($errors, $schemaErrors);
        } else {
            $result = generate_connection_request_technical_handover_package((int) $request['id']);

            if ($result['ok']) {
                set_flash('success', $result['message']);
                redirect($mvmRedirectPath);
            }

            $errors[] = (string) $result['message'];
        }
    } elseif ($action === 'generate_technical_declaration') {
        if ($schemaErrors !== []) {
            $errors = array_merge($errors, $schemaErrors);
        } else {
            $result = generate_connection_request_technical_declaration((int) $request['id']);
            set_flash($result['ok'] ? 'success' : 'error', $result['message']);
            redirect($mvmRedirectPath);
        }
    } elseif ($action === 'send_package') {
        $documentId = filter_input(INPUT_POST, 'document_id', FILTER_VALIDATE_INT);
        $document = $documentId ? find_connection_request_document($documentId) : null;

        if ($document === null || (int) $document['connection_request_id'] !== (int) $request['id']) {
            $errors[] = 'A komplett dokumentum nem található.';
        } else {
            $result = send_connection_request_complete_package_to_customer((int) $document['id']);
            set_flash($result['ok'] ? 'success' : 'error', $result['message']);
            redirect($mvmRedirectPath);
        }
    } elseif ($action === 'send_mvm_document') {
        $documentId = filter_input(INPUT_POST, 'document_id', FILTER_VALIDATE_INT);
        $recipientEmail = trim((string) ($_POST['mvm_recipient'] ?? ''));
        $note = trim((string) ($_POST['mvm_note'] ?? ''));
        $document = $documentId ? find_connection_request_document($documentId) : null;

        if ($mvmMailSchemaErrors !== []) {
            $errors = array_merge($errors, $mvmMailSchemaErrors);
        } elseif ($document === null || (int) $document['connection_request_id'] !== (int) $request['id']) {
            $errors[] = 'A küldendő MVM dokumentum nem található.';
        } elseif (!mvm_document_is_mvm_sendable_package((string) ($document['document_type'] ?? ''))) {
            $errors[] = 'MVM-nek csak az osszefuzott PDF csomag kuldheto.';
        } else {
            $result = send_connection_request_document_to_mvm((int) $document['id'], $recipientEmail, $note);
            set_flash($result['ok'] ? 'success' : 'error', $result['message']);
            redirect($mvmRedirectPath . '#mvm-mailbox');
        }
    } elseif ($action === 'sync_mvm_mailbox') {
        if ($mvmMailSchemaErrors !== []) {
            $errors = array_merge($errors, $mvmMailSchemaErrors);
        } else {
            $result = sync_mvm_mailbox_replies();
            set_flash($result['ok'] ? 'success' : 'error', $result['message']);
            redirect($mvmRedirectPath . '#mvm-mailbox');
        }
    } else {
        $uploadedFiles = uploaded_files_for_key($_FILES, 'mvm_documents');

        if ($schemaErrors !== []) {
            $errors = array_merge($errors, $schemaErrors);
        } else {
            $errors = validate_connection_request_document_upload($selectedType, $uploadedFiles);
        }

        if ($errors === []) {
            try {
                $messages = store_connection_request_documents((int) $request['id'], $selectedType, $title, $uploadedFiles);
                $message = 'Az MVM dokumentum feltöltve.';

                if ($messages !== []) {
                    $message .= ' Figyelmeztetés: ' . implode(' ', $messages);
                }

                set_flash('success', $message);
                redirect($mvmRedirectPath);
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az MVM dokumentum feltöltése sikertelen.';
            }
        }
    }
}

$documents = connection_request_documents((int) $request['id']);
$completePackages = connection_request_complete_packages((int) $request['id']);
$executionPlanPackages = connection_request_execution_plan_packages((int) $request['id']);
$technicalHandoverPackages = connection_request_technical_handover_packages((int) $request['id']);
$packageParts = connection_request_complete_package_parts((int) $request['id']);
$missingItems = connection_request_complete_package_missing_items((int) $request['id']);
$executionPlanPackageParts = connection_request_execution_plan_package_parts((int) $request['id']);
$executionPlanMissingItems = connection_request_execution_plan_package_missing_items((int) $request['id']);
$technicalHandoverPackageParts = connection_request_technical_handover_package_parts((int) $request['id']);
$technicalHandoverMissingItems = connection_request_technical_handover_package_missing_items((int) $request['id']);
$mvmEmailThreads = mvm_email_threads_with_messages((int) $request['id']);
$mvmThreadStatusLabels = mvm_email_thread_status_labels();
$mvmFormRow = connection_request_mvm_form((int) $request['id']);
$pdfMergeAvailable = class_exists('\\setasign\\Fpdi\\Fpdi');
$flash = get_flash();
$mvmNoticeTarget = (string) ($_GET['mvm_notice'] ?? '') === '1';
$topFlash = $mvmNoticeTarget ? null : $flash;
$mvmFormFlash = $mvmNoticeTarget ? $flash : null;
$topErrors = $isMvmFormPost ? [] : $errors;
$mvmFormErrors = $isMvmFormPost ? $errors : [];
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow"><?= $isMiniCrmContext ? 'MiniCRM' : 'Admin'; ?></p>
                <h1>MVM dokumentumok</h1>
                <p><?= h($request['requester_name']); ?> - <?= h($request['project_name']); ?></p>
                <?php if ($isMiniCrmContext): ?>
                    <p class="muted-text">MiniCRM azonosito: <?= h((string) ($minicrmItem['source_id'] ?? '')); ?></p>
                <?php endif; ?>
            </div>
            <div class="admin-actions">
                <a class="button" href="<?= h(authorization_signature_url($request)); ?>" target="_blank">Meghatalmazás online aláírása</a>
                <a class="button button-secondary" href="<?= h($mvmBackUrl); ?>"><?= h($mvmBackLabel); ?></a>
            </div>
        </div>

        <?php if ($topFlash !== null): ?>
            <div class="alert alert-<?= h((string) $topFlash['type']); ?>"><p><?= h((string) $topFlash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-info">
                <p>Az MVM dokumentumokhoz futtasd le phpMyAdminban a <strong>database/upgrade_connection_requests.sql</strong> fájlt.</p>
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($mvmFormSchemaErrors !== []): ?>
            <div class="alert alert-info">
                <p>Az MVM DOCX űrlapmezőkhöz futtasd le phpMyAdminban a <strong>database/mvm_docx_form.sql</strong> fájlt.</p>
                <?php foreach ($mvmFormSchemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($mvmMailSchemaErrors !== []): ?>
            <div class="alert alert-info">
                <p>Az MVM levelezési naplóhoz automatikus adatbázis-tábla létrehozás szükséges.</p>
                <?php foreach ($mvmMailSchemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($templateErrors !== []): ?>
            <div class="alert alert-info">
                <?php foreach ($templateErrors as $templateError): ?><p><?= h($templateError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($planTemplateErrors !== []): ?>
            <div class="alert alert-info">
                <?php foreach ($planTemplateErrors as $templateError): ?><p><?= h($templateError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($handoverTemplateErrors !== []): ?>
            <div class="alert alert-info">
                <?php foreach ($handoverTemplateErrors as $templateError): ?><p><?= h($templateError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($topErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($topErrors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="auth-panel form-block mvm-docx-panel">
            <div class="admin-header compact">
                <div>
                    <p class="eyebrow">Fővállalkozói sablon</p>
                    <h2>MVM igénybejelentő kitöltése</h2>
                    <p>Az ügyféladatokat a rendszer tölti, az MVM-hez szükséges plusz mezőket itt adja meg az admin. A kiválasztott fővállalkozói sablonba kerülnek az adatok és a feltöltött skicc kép.</p>
                </div>
            </div>

            <form class="form mvm-docx-form" method="post" enctype="multipart/form-data" action="<?= h($mvmPageUrl); ?>">
                <?= csrf_field(); ?>

                <div class="mvm-docx-auto-data">
                    <div>
                        <span>Ügyfél</span>
                        <strong><?= h($request['requester_name']); ?></strong>
                    </div>
                    <div>
                        <span>Születési adatok</span>
                        <strong><?= h(trim((string) ($request['birth_place'] ?? '') . ' ' . (string) ($request['birth_date'] ?? '')) ?: '-'); ?></strong>
                    </div>
                    <div>
                        <span>Anyja neve</span>
                        <strong><?= h($request['mother_name'] ?: '-'); ?></strong>
                    </div>
                    <div>
                        <span>HRSZ</span>
                        <strong><?= h($request['hrsz'] ?: '-'); ?></strong>
                    </div>
                </div>

                <?php
                    $ampereOptions = [0, 10, 16, 20, 25, 32, 35, 40, 50, 63, 80, 100];
                    $performanceGroups = [
                        [
                            'title' => 'Meglévő teljesítmény',
                            'rows' => [
                                ['label' => 'Nappali', 'keys' => ['jml1', 'jml2', 'jml3']],
                                ['label' => 'Vezérelt', 'keys' => ['jvl1', 'jvl2', 'jvl3']],
                                ['label' => 'H-Tarifa', 'keys' => ['jelenlegi_hl1', 'jelenlegi_hl2', 'jelenlegi_hl3']],
                            ],
                        ],
                        [
                            'title' => 'Igényelt teljesítmény',
                            'rows' => [
                                ['label' => 'Nappali', 'keys' => ['iml1', 'iml2', 'iml3']],
                                ['label' => 'Vezérelt', 'keys' => ['ivl1', 'ivl2', 'ivl3']],
                                ['label' => 'H-Tarifa', 'keys' => ['ihl1', 'ihl2', 'ihl3']],
                            ],
                        ],
                    ];
                    $phaseLabels = ['L1', 'L2', 'L3'];
                    $normalizedAmpereValue = static function (mixed $value): string {
                        $value = trim((string) $value);

                        if ($value === '') {
                            return '0';
                        }

                        if (preg_match('/([0-9]+)/', $value, $matches)) {
                            return (string) (int) $matches[1];
                        }

                        return '0';
                    };
                ?>

                <div class="mvm-form-sections">
                    <?php foreach (mvm_form_field_sections() as $sectionKey => $section): ?>
                        <section class="mvm-form-section">
                            <div>
                                <h3><?= h($section['title']); ?></h3>
                                <p><?= h($section['description']); ?></p>
                            </div>
                            <?php if ($sectionKey === 'performance'): ?>
                                <div class="mvm-performance-board">
                                    <?php foreach ($performanceGroups as $group): ?>
                                        <section class="mvm-performance-card" aria-label="<?= h($group['title']); ?>">
                                            <h4><?= h($group['title']); ?></h4>
                                            <div class="mvm-performance-table">
                                                <div class="mvm-performance-head">Tarifa</div>
                                                <?php foreach ($phaseLabels as $phaseLabel): ?>
                                                    <div class="mvm-performance-head"><?= h($phaseLabel); ?></div>
                                                <?php endforeach; ?>

                                                <?php foreach ($group['rows'] as $row): ?>
                                                    <div class="mvm-performance-tariff"><?= h($row['label']); ?></div>
                                                    <?php foreach ($row['keys'] as $index => $fieldKey): ?>
                                                        <?php
                                                            $fieldId = 'mvm_' . $fieldKey;
                                                            $selectedAmpere = $normalizedAmpereValue($mvmFormValues[$fieldKey] ?? '');
                                                        ?>
                                                        <label class="sr-only" for="<?= h($fieldId); ?>"><?= h($row['label'] . ' ' . $phaseLabels[$index]); ?></label>
                                                        <select id="<?= h($fieldId); ?>" name="<?= h($fieldKey); ?>" class="mvm-ampere-select">
                                                            <?php if (!in_array((int) $selectedAmpere, $ampereOptions, true)): ?>
                                                                <option value="<?= h($selectedAmpere); ?>" selected><?= h($selectedAmpere); ?>A</option>
                                                            <?php endif; ?>
                                                            <?php foreach ($ampereOptions as $ampereOption): ?>
                                                                <option value="<?= $ampereOption; ?>" <?= $selectedAmpere === (string) $ampereOption ? 'selected' : ''; ?>><?= $ampereOption; ?>A</option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </section>
                                    <?php endforeach; ?>
                                    <input type="hidden" name="igenyelt_osszes_teljesitmeny" value="<?= h($mvmFormValues['igenyelt_osszes_teljesitmeny'] ?? ''); ?>">
                                    <input type="hidden" name="osszes_igenyelt_h_teljesitmeny" value="<?= h($mvmFormValues['osszes_igenyelt_h_teljesitmeny'] ?? ''); ?>">
                                </div>
                            <?php else: ?>
                            <div class="mvm-field-grid">
                                <?php foreach ($section['fields'] as $fieldKey => $field): ?>
                                    <?php $fieldId = 'mvm_' . $fieldKey; ?>
                                    <?php if (($field['type'] ?? 'text') === 'checkbox'): ?>
                                        <label class="checkbox-card" for="<?= h($fieldId); ?>">
                                            <input id="<?= h($fieldId); ?>" name="<?= h($fieldKey); ?>" type="checkbox" value="X" <?= ($mvmFormValues[$fieldKey] ?? '') !== '' ? 'checked' : ''; ?>>
                                            <span><?= h($field['label']); ?></span>
                                        </label>
                                    <?php elseif (($field['type'] ?? 'text') === 'textarea'): ?>
                                        <div class="mvm-input-field">
                                            <label for="<?= h($fieldId); ?>"><?= h($field['label']); ?></label>
                                            <textarea id="<?= h($fieldId); ?>" name="<?= h($fieldKey); ?>" rows="3"><?= h($mvmFormValues[$fieldKey] ?? ''); ?></textarea>
                                        </div>
                                    <?php elseif (($field['type'] ?? 'text') === 'select'): ?>
                                        <div class="mvm-input-field">
                                            <label for="<?= h($fieldId); ?>"><?= h($field['label']); ?></label>
                                            <select id="<?= h($fieldId); ?>" name="<?= h($fieldKey); ?>">
                                                <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                                                    <option value="<?= h((string) $optionValue); ?>" <?= (string) ($mvmFormValues[$fieldKey] ?? '') === (string) $optionValue ? 'selected' : ''; ?>><?= h((string) $optionLabel); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php else: ?>
                                        <div class="mvm-input-field">
                                            <label for="<?= h($fieldId); ?>"><?= h($field['label']); ?></label>
                                            <input id="<?= h($fieldId); ?>" name="<?= h($fieldKey); ?>" value="<?= h($mvmFormValues[$fieldKey] ?? ''); ?>" <?= !empty($field['readonly']) ? 'readonly data-mvm-calculated="1"' : ''; ?>>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>

                <section class="mvm-form-section mvm-sketch-section">
                    <div>
                        <h3>3. oldali kockázott kép</h3>
                        <p>JPG, PNG vagy WEBP kép tölthető fel. A rendszer fehér keretbe illeszti, hogy a PDF sablonban ne torzuljon.</p>
                    </div>
                    <div>
                        <label for="sketch_image">Skicc kép feltöltése</label>
                        <input id="sketch_image" name="sketch_image" type="file" accept="image/jpeg,image/png,image/webp">
                        <?php if ($mvmFormRow !== null && !empty($mvmFormRow['sketch_original_name'])): ?>
                            <p class="muted-text">Jelenlegi kép: <strong><?= h($mvmFormRow['sketch_original_name']); ?></strong></p>
                        <?php else: ?>
                            <p class="muted-text">Még nincs feltöltött skicc kép ehhez az igényhez.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <div id="mvm-generator-actions" class="mvm-generator-actions">
                    <?php if ($mvmFormFlash !== null): ?>
                        <div class="alert alert-<?= h((string) $mvmFormFlash['type']); ?>"><p><?= h((string) $mvmFormFlash['message']); ?></p></div>
                    <?php endif; ?>

                    <?php if ($mvmFormErrors !== []): ?>
                        <div class="alert alert-error">
                            <?php foreach ($mvmFormErrors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions">
                    <button class="button button-secondary" name="action" value="save_mvm_form" type="submit" <?= $mvmFormSchemaErrors !== [] ? 'disabled' : ''; ?>>Adatok mentése</button>
                    <button class="button button-secondary" name="action" value="generate_mvm_docx" type="submit" <?= ($mvmFormSchemaErrors !== [] || $templateErrors !== []) ? 'disabled' : ''; ?>>Kitöltött Word dokumentum generálása</button>
                    <button class="button" name="action" value="generate_mvm_pdf" type="submit" <?= ($mvmFormSchemaErrors !== [] || $templateErrors !== []) ? 'disabled' : ''; ?>>PDF generálása Word dokumentumból</button>
                    <button class="button button-secondary" name="action" value="generate_plan_docx" type="submit" <?= ($mvmFormSchemaErrors !== [] || $planTemplateErrors !== []) ? 'disabled' : ''; ?>>Terv Word dokumentum generálása</button>
                    <button class="button" name="action" value="generate_plan_pdf" type="submit" <?= ($mvmFormSchemaErrors !== [] || $planTemplateErrors !== []) ? 'disabled' : ''; ?>>Terv PDF generálása Word dokumentumból</button>
                    <button class="button button-secondary" name="action" value="generate_handover_docx" type="submit" <?= ($mvmFormSchemaErrors !== [] || $handoverTemplateErrors !== []) ? 'disabled' : ''; ?>>Műszaki átadás Word generálása</button>
                    <button class="button" name="action" value="generate_handover_pdf" type="submit" <?= ($mvmFormSchemaErrors !== [] || $handoverTemplateErrors !== []) ? 'disabled' : ''; ?>>Műszaki átadás PDF generálása</button>
                    </div>
                </div>
            </form>
        </section>

        <div class="form-grid two">
            <section class="auth-panel">
                <h2>Új MVM dokumentum</h2>
                <form class="form" method="post" enctype="multipart/form-data" action="<?= h($mvmPageUrl); ?>">
                    <?= csrf_field(); ?>

                    <label for="document_type">Dokumentum állapota</label>
                    <select id="document_type" name="document_type" required>
                        <?php foreach ($uploadTypes as $key => $label): ?>
                            <option value="<?= h($key); ?>" <?= $selectedType === $key ? 'selected' : ''; ?>><?= h($label); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="title">Megjelenő cím</label>
                    <input id="title" name="title" value="<?= h($title); ?>" placeholder="Ha üres, a kiválasztott állapot neve jelenik meg">

                    <label for="mvm_documents">Dokumentumok</label>
                    <input id="mvm_documents" name="mvm_documents[]" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required>
                    <p class="muted-text">Több dokumentum is feltölthető egyszerre. Az összefűzött PDF csomagokba csak PDF és kép fájl fűzhető be, ezért az MVM dokumentumot és a kiviteli tervet PDF-ként érdemes feltölteni.</p>

                    <button class="button" name="action" value="upload" type="submit">MVM dokumentum feltöltése</button>
                </form>
            </section>

            <section class="auth-panel">
                <h2>Munka adatai</h2>
                <p><strong><?= h($request['requester_name']); ?></strong></p>
                <p><?= h($request['email']); ?> | <?= h($request['phone']); ?></p>
                <p><?= h($request['site_postal_code'] . ' ' . $request['site_address']); ?></p>
                <p>HRSZ: <?= h($request['hrsz'] ?: '-'); ?></p>
            </section>
        </div>

        <section class="auth-panel form-block">
            <div class="admin-header">
                <div>
                    <p class="eyebrow">1. csomag</p>
                    <h2>MVM jóváhagyási PDF csomag</h2>
                    <p>Sorrend: MVM dokumentum, meghatalmazás, tulajdoni lap, térképmásolat, hozzájáruló nyilatkozat ha van, majd fotók. Ezt küldjük el az MVM-nek jóváhagyásra. A kiviteli terv ebbe a csomagba már nem kerül bele.</p>
                </div>
                <form method="post" action="<?= h($mvmPageUrl); ?>">
                    <?= csrf_field(); ?>
                    <button class="button" name="action" value="build_package" type="submit" <?= ($missingItems !== [] || !$pdfMergeAvailable) ? 'disabled' : ''; ?>>MVM jóváhagyási csomag generálása</button>
                </form>
            </div>

            <?php if (!$pdfMergeAvailable): ?>
                <div class="alert alert-info">
                    <p>Az automatikus PDF-összefűzéshez hiányzik az FPDI csomag az éles tárhely vendor mappájából. Töltsd fel a frissített <strong>vendor.zip</strong> tartalmát a tárhelyre, vagy futtasd a <strong>composer install</strong> parancsot a tárhelyen.</p>
                </div>
            <?php endif; ?>

            <?php if ($missingItems !== []): ?>
                <div class="alert alert-info">
                    <p>A generáláshoz még hiányzik: <?= h(implode(', ', $missingItems)); ?>.</p>
                </div>
            <?php endif; ?>

            <?php if ($packageParts !== []): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Sorrend</th>
                                <th>Dokumentum</th>
                                <th>Fájl</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($packageParts as $index => $part): ?>
                                <tr>
                                    <td><?= $index + 1; ?>. <?= h($part['group']); ?></td>
                                    <td><strong><?= h($part['label']); ?></strong></td>
                                    <td><?= h($part['original_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted-text">Még nincs összefűzhető dokumentum.</p>
            <?php endif; ?>

            <?php if ($completePackages !== []): ?>
                <div class="portal-card-files existing-file-panel">
                    <h3>Elkészült MVM jóváhagyási csomagok</h3>
                    <div class="inline-link-list">
                        <?php foreach ($completePackages as $package): ?>
                            <a href="<?= h(url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $package['id']); ?>" target="_blank"><?= h($package['title']); ?> - <?= h(format_bytes((int) $package['file_size'])); ?></a>
                            <form method="post" action="<?= h($mvmPageUrl); ?>">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="document_id" value="<?= (int) $package['id']; ?>">
                                <button class="button button-secondary" name="action" value="send_package" type="submit">Email küldése ügyfélnek</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="auth-panel form-block">
            <div class="admin-header">
                <div>
                    <p class="eyebrow">2. csomag</p>
                    <h2>Kiviteli terv PDF csomag</h2>
                    <p>Az MVM jóváhagyás után ezt a külön csomagot kell generálni. Sorrend: kiviteli terv, majd fotók.</p>
                </div>
                <form method="post" action="<?= h($mvmPageUrl); ?>">
                    <?= csrf_field(); ?>
                    <button class="button" name="action" value="build_execution_plan_package" type="submit" <?= ($executionPlanMissingItems !== [] || !$pdfMergeAvailable) ? 'disabled' : ''; ?>>Kiviteli terv csomag generálása</button>
                </form>
            </div>

            <?php if (!$pdfMergeAvailable): ?>
                <div class="alert alert-info">
                    <p>Az automatikus PDF-összefűzéshez hiányzik az FPDI csomag az éles tárhely vendor mappájából.</p>
                </div>
            <?php endif; ?>

            <?php if ($executionPlanMissingItems !== []): ?>
                <div class="alert alert-info">
                    <p>A generáláshoz még hiányzik: <?= h(implode(', ', $executionPlanMissingItems)); ?>.</p>
                </div>
            <?php endif; ?>

            <?php if ($executionPlanPackageParts !== []): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Sorrend</th>
                                <th>Dokumentum</th>
                                <th>Fájl</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($executionPlanPackageParts as $index => $part): ?>
                                <tr>
                                    <td><?= $index + 1; ?>. <?= h($part['group']); ?></td>
                                    <td><strong><?= h($part['label']); ?></strong></td>
                                    <td><?= h($part['original_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted-text">Még nincs összefűzhető kiviteli terv csomag.</p>
            <?php endif; ?>

            <?php if ($executionPlanPackages !== []): ?>
                <div class="portal-card-files existing-file-panel">
                    <h3>Elkészült kiviteli terv csomagok</h3>
                    <div class="inline-link-list">
                        <?php foreach ($executionPlanPackages as $package): ?>
                            <a href="<?= h(url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $package['id']); ?>" target="_blank"><?= h($package['title']); ?> - <?= h(format_bytes((int) $package['file_size'])); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="auth-panel form-block">
            <div class="admin-header">
                <div>
                    <p class="eyebrow">Műszaki átadás</p>
                    <h2>Átadás-átvételi PDF csomag</h2>
                    <p>A minta szerinti sorrend: kész beavatkozási lap, építési napló, műszaki átadás-átvételi jegyzőkönyv, nyilatkozatok adatlap, majd a kivitelezés utáni fotók. A nyilatkozatok adatlap az MVM igénybejelentő PDF 9-11. oldalából készül külön PDF-ként.</p>
                </div>
                <form method="post" action="<?= h($mvmPageUrl); ?>">
                    <?= csrf_field(); ?>
                    <button class="button button-secondary" name="action" value="generate_technical_declaration" type="submit" <?= (!$pdfMergeAvailable || latest_connection_request_mvm_request_pdf_document((int) $request['id']) === null) ? 'disabled' : ''; ?>>Nyilatkozatok adatlap kinyerése</button>
                </form>
                <form method="post" action="<?= h($mvmPageUrl); ?>">
                    <?= csrf_field(); ?>
                    <button class="button" name="action" value="build_handover_package" type="submit" <?= ($technicalHandoverMissingItems !== [] || !$pdfMergeAvailable) ? 'disabled' : ''; ?>>Műszaki átadás csomag generálása</button>
                </form>
            </div>

            <?php if (!$pdfMergeAvailable): ?>
                <div class="alert alert-info">
                    <p>Az automatikus PDF-összefűzéshez hiányzik az FPDI csomag az éles tárhely vendor mappájából.</p>
                </div>
            <?php endif; ?>

            <?php if ($technicalHandoverMissingItems !== []): ?>
                <div class="alert alert-info">
                    <p>A generáláshoz még hiányzik: <?= h(implode(', ', $technicalHandoverMissingItems)); ?>.</p>
                </div>
            <?php endif; ?>

            <?php if ($technicalHandoverPackageParts !== []): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Sorrend</th>
                                <th>Dokumentum</th>
                                <th>Fájl</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($technicalHandoverPackageParts as $index => $part): ?>
                                <tr>
                                    <td><?= $index + 1; ?>. <?= h($part['group']); ?></td>
                                    <td><strong><?= h($part['label']); ?></strong></td>
                                    <td><?= h($part['original_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted-text">Még nincs összefűzhető műszaki átadás dokumentum.</p>
            <?php endif; ?>

            <?php if ($technicalHandoverPackages !== []): ?>
                <div class="portal-card-files existing-file-panel">
                    <h3>Elkészült műszaki átadás csomagok</h3>
                    <div class="inline-link-list">
                        <?php foreach ($technicalHandoverPackages as $package): ?>
                            <a href="<?= h(url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $package['id']); ?>" target="_blank"><?= h($package['title']); ?> - <?= h(format_bytes((int) $package['file_size'])); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section id="mvm-mailbox" class="auth-panel form-block mvm-mail-panel">
            <div class="admin-header">
                <div>
                    <p class="eyebrow">MVM levelezés</p>
                    <h2>Küldések és válaszok</h2>
                    <p>A CRM-ből küldött MVM levelek tárgyába egy egyedi azonosító kerül. Ha az MVM erre válaszol, a válasz csak ennél az igénynél jelenik meg.</p>
                </div>
                <form method="post" action="<?= h($mvmPageUrl . '#mvm-mailbox'); ?>">
                    <?= csrf_field(); ?>
                    <button class="button button-secondary" name="action" value="sync_mvm_mailbox" type="submit" <?= $mvmMailSchemaErrors !== [] ? 'disabled' : ''; ?>>MVM válaszok szinkronizálása</button>
                </form>
            </div>

            <?php if (trim(mvm_config_value('MVM_IMAP_PASS', '')) === ''): ?>
                <div class="alert alert-info">
                    <p>A válaszok automatikus beolvasásához állítsd be a <strong>MVM_IMAP_HOST</strong>, <strong>MVM_IMAP_USER</strong> és <strong>MVM_IMAP_PASS</strong> értékeket a <strong>storage/config/local.php</strong> fájlban. A jelszót ne írd be a chatbe.</p>
                </div>
            <?php endif; ?>

            <?php if ($mvmEmailThreads === []): ?>
                <p class="muted-text">Még nincs MVM-nek küldött dokumentum ehhez az igényhez.</p>
            <?php else: ?>
                <div class="mvm-mail-thread-list">
                    <?php foreach ($mvmEmailThreads as $thread): ?>
                        <?php $messages = is_array($thread['messages'] ?? null) ? $thread['messages'] : []; ?>
                        <article class="mvm-mail-thread">
                            <div class="mvm-mail-thread-head">
                                <div>
                                    <span class="portal-kicker"><?= h((string) $thread['token']); ?></span>
                                    <h3><?= h((string) $thread['document_label']); ?></h3>
                                    <p><?= h((string) $thread['subject']); ?></p>
                                </div>
                                <span class="status-badge status-badge-<?= h((string) $thread['status']); ?>"><?= h($mvmThreadStatusLabels[$thread['status']] ?? (string) $thread['status']); ?></span>
                            </div>
                            <dl class="admin-request-data-list admin-request-data-list-compact">
                                <div><dt>MVM címzett</dt><dd><?= h((string) $thread['mvm_recipient']); ?></dd></div>
                                <div><dt>Utolsó levél</dt><dd><?= h((string) ($thread['last_message_at'] ?: $thread['created_at'])); ?></dd></div>
                            </dl>
                            <?php if ($messages !== []): ?>
                                <div class="mvm-mail-message-list">
                                    <?php foreach ($messages as $message): ?>
                                        <article class="mvm-mail-message mvm-mail-message-<?= h((string) $message['direction']); ?>">
                                            <div>
                                                <strong><?= h((string) $message['subject']); ?></strong>
                                                <span><?= h((string) ($message['sender_name'] ?: $message['sender_email'] ?: '-')); ?> · <?= h((string) ($message['received_at'] ?: $message['created_at'])); ?></span>
                                            </div>
                                            <p><?= nl2br(h(latest_mvm_email_message_preview(['messages' => [$message]]))); ?></p>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="form-block">
            <?php if ($documents === []): ?>
                <div class="empty-state">
                    <h2>Még nincs MVM dokumentum</h2>
                    <p>Feltöltés után az admin kezeli a dokumentumot, a komplett csomag pedig emailben küldhető ki az ügyfélnek.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Állapot</th>
                                <th>Dokumentum</th>
                                <th>Datum</th>
                                <th>Fájl</th>
                                <th>MVM küldés</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $document): ?>
                                <?php
                                $documentType = (string) $document['document_type'];
                                $defaultRecipient = default_mvm_document_recipient($documentType);
                                $isMvmSendablePackage = mvm_document_is_mvm_sendable_package($documentType);
                                ?>
                                <tr>
                                    <td><?= h($types[$documentType] ?? $documentType); ?></td>
                                    <td><strong><?= h($document['title']); ?></strong></td>
                                    <td><?= h($document['created_at']); ?></td>
                                    <td><a href="<?= h(url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $document['id']); ?>" target="_blank"><?= h($document['original_name']); ?></a></td>
                                    <td>
                                        <?php if ($isMvmSendablePackage): ?>
                                        <form class="mvm-send-form" method="post" action="<?= h($mvmPageUrl . '#mvm-mailbox'); ?>">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="document_id" value="<?= (int) $document['id']; ?>">
                                            <input name="mvm_recipient" type="email" value="<?= h($defaultRecipient); ?>" placeholder="mvm@email.hu" required>
                                            <textarea name="mvm_note" rows="2" placeholder="Rövid megjegyzés az MVM-nek (opcionális)"></textarea>
                                            <button class="button button-secondary" name="action" value="send_mvm_document" type="submit" <?= $mvmMailSchemaErrors !== [] ? 'disabled' : ''; ?>>Küldés MVM-nek</button>
                                        </form>
                                        <?php else: ?>
                                            <span class="muted-text">MVM-nek csak PDF csomagot kuldunk.</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>
<script>
(function () {
    const form = document.querySelector('.mvm-docx-form');

    if (!form) {
        return;
    }

    const requestedGeneralKeys = ['iml1', 'iml2', 'iml3'];
    const requestedHTariffKeys = ['ihl1', 'ihl2', 'ihl3'];
    const requestedControlledKeys = ['ivl1', 'ivl2', 'ivl3'];
    const existingKeys = ['jml1', 'jml2', 'jml3', 'jelenlegi_hl1', 'jelenlegi_hl2', 'jelenlegi_hl3', 'jvl1', 'jvl2', 'jvl3'];
    const additionalCostKeys = [
        'szekreny_brutto_egysegar',
        'szekreny_felulvizsgalati_dij',
        'oszloptelepites_koltseg',
        'legvezetekes_csatlakozo_koltseg',
        'szfd',
        'csatlakozo_berendezes_helyreallitas_koltseg',
        'foldkabel_tobletkoltseg'
    ];
    const observedKeys = requestedGeneralKeys.concat(requestedHTariffKeys, requestedControlledKeys, existingKeys, additionalCostKeys);
    const ones = ['', 'egy', 'kettő', 'három', 'négy', 'öt', 'hat', 'hét', 'nyolc', 'kilenc'];
    const tens = {3: 'harminc', 4: 'negyven', 5: 'ötven', 6: 'hatvan', 7: 'hetven', 8: 'nyolcvan', 9: 'kilencven'};

    const input = (key) => form.querySelector('[name="' + key + '"]');
    const setValue = (key, value) => {
        const field = input(key);

        if (field) {
            field.value = value;
        }
    };
    const ampere = (raw) => {
        raw = String(raw || '').trim().toLowerCase();

        if (!raw || raw === '-' || raw === '- / -') {
            return 0;
        }

        const phaseMatch = raw.match(/^([123])\s*x\s*([0-9]+(?:[,.][0-9]+)?)/);

        if (phaseMatch) {
            return Math.round(Number(phaseMatch[1]) * Number(phaseMatch[2].replace(',', '.')));
        }

        const numberMatch = raw.match(/([0-9]+(?:[,.][0-9]+)?)/);

        return numberMatch ? Math.round(Number(numberMatch[1].replace(',', '.'))) : 0;
    };
    const total = (keys) => keys.reduce((sum, key) => sum + ampere(input(key)?.value), 0);
    const amount = (raw) => {
        const normalized = String(raw || '').replace(/[^\d-]/g, '');

        return normalized && normalized !== '-' ? Number(normalized) : 0;
    };
    const amountTotal = (keys) => keys.reduce((sum, key) => sum + amount(input(key)?.value), 0);
    const formatAmount = (amount) => String(Math.round(amount)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const underThousandToWords = (number) => {
        number = Math.max(0, Math.min(999, number));
        let words = '';
        const hundreds = Math.floor(number / 100);
        const remainder = number % 100;

        if (hundreds > 0) {
            words += hundreds === 1 ? 'száz' : (hundreds === 2 ? 'kétszáz' : ones[hundreds] + 'száz');
        }

        if (remainder === 0) return words;
        if (remainder < 10) return words + ones[remainder];
        if (remainder === 10) return words + 'tíz';
        if (remainder < 20) return words + 'tizen' + ones[remainder - 10];
        if (remainder === 20) return words + 'húsz';
        if (remainder < 30) return words + 'huszon' + ones[remainder - 20];

        const ten = Math.floor(remainder / 10);
        const one = remainder % 10;

        return words + tens[ten] + (one > 0 ? ones[one] : '');
    };
    const numberToWords = (number) => {
        number = Math.abs(Math.round(number));

        if (number === 0) {
            return 'nulla';
        }

        const millions = Math.floor(number / 1000000);
        number %= 1000000;
        const thousands = Math.floor(number / 1000);
        const remainder = number % 1000;
        const parts = [];

        if (millions > 0) {
            parts.push(underThousandToWords(millions) + 'millió');
        }

        if (thousands > 0) {
            parts.push(thousands === 1 ? 'ezer' : (thousands === 2 ? 'kétezer' : underThousandToWords(thousands) + 'ezer'));
        }

        if (remainder > 0) {
            parts.push(underThousandToWords(remainder));
        }

        return parts.length > 1 && (millions > 0 || thousands > 2) ? parts.join('-') : parts.join('');
    };
    const recalculate = () => {
        const requestedHTotal = total(requestedHTariffKeys);
        const requestedTotal = total(requestedGeneralKeys) + requestedHTotal + total(requestedControlledKeys);
        const existingTotal = total(existingKeys);
        const deductible = requestedTotal > 0 ? Math.max(32, existingTotal) : 0;
        const payableAmpere = requestedTotal > 0 ? Math.max(0, requestedTotal - deductible) : 0;
        const payableAmount = payableAmpere * 4953;
        const additionalCosts = amountTotal(additionalCostKeys);
        const totalAmount = payableAmount + additionalCosts;
        const hasTotal = requestedTotal > 0 || additionalCosts > 0;

        setValue('igenyelt_osszes_teljesitmeny', requestedTotal > 0 ? requestedTotal : '');
        setValue('osszes_igenyelt_h_teljesitmeny', requestedHTotal > 0 ? requestedHTotal : '');
        setValue('ingyenes_teljesitmeny_ampere', deductible > 0 ? deductible : '');
        setValue('fizetendo_teljesitmeny_ampere', requestedTotal > 0 ? payableAmpere : '');
        setValue('fizetendo_teljesitmeny_osszeg', requestedTotal > 0 ? formatAmount(payableAmount) : '');
        setValue('ofo', hasTotal ? formatAmount(totalAmount) : '');
        setValue('ofosz', hasTotal ? numberToWords(totalAmount) : '');
    };

    observedKeys.forEach((key) => {
        const field = input(key);

        if (!field) {
            return;
        }

        field.addEventListener('input', recalculate);
        field.addEventListener('change', recalculate);
    });
    recalculate();
})();
</script>
<?php if ($mvmFormErrors !== []): ?>
<script>
document.getElementById('mvm-generator-actions')?.scrollIntoView({block: 'center'});
</script>
<?php endif; ?>
