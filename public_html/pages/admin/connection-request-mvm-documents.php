<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$minicrmItemId = filter_input(INPUT_GET, 'minicrm_item', FILTER_VALIDATE_INT);
$minicrmItem = null;
$minicrmLinkResult = null;

if ($minicrmItemId) {
    $minicrmItem = find_minicrm_work_item((int) $minicrmItemId);

    if ($minicrmItem === null) {
        set_flash('error', 'A MiniCRM munka nem található.');
        redirect('/admin/minicrm-import?item=' . (int) $minicrmItemId . '#minicrm-work-' . (int) $minicrmItemId);
    }

    $linkedRequestId = minicrm_work_item_connection_request_id((int) $minicrmItemId);

    if ($linkedRequestId !== null) {
        $requestId = $linkedRequestId;
    } else {
        $minicrmLinkResult = ensure_minicrm_work_item_connection_request((int) $minicrmItemId);

        if (!($minicrmLinkResult['ok'] ?? false)) {
            set_flash('error', (string) ($minicrmLinkResult['message'] ?? 'A MiniCRM munka MVM dokumentumhoz kapcsolasa sikertelen.'));
            redirect('/admin/minicrm-import?item=' . (int) $minicrmItemId . '#minicrm-work-' . (int) $minicrmItemId);
        }

        $requestId = (int) ($minicrmLinkResult['request_id'] ?? 0);
    }
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
$isMvmFormPost = in_array($mvmFormAction, ['save_mvm_form', 'generate_mvm_docx', 'generate_mvm_pdf', 'generate_plan_docx', 'generate_plan_pdf', 'generate_handover_docx', 'generate_handover_pdf', 'generate_seal_removal_docx', 'generate_seal_removal_pdf', 'generate_h_tariff_docx', 'generate_h_tariff_pdf'], true);
$isHandoverFormPost = in_array($mvmFormAction, ['generate_handover_docx', 'generate_handover_pdf', 'upload_after_work_photos'], true);
$isSealRemovalFormPost = in_array($mvmFormAction, ['generate_seal_removal_docx', 'generate_seal_removal_pdf'], true);
$isHTariffFormPost = in_array($mvmFormAction, ['generate_h_tariff_docx', 'generate_h_tariff_pdf'], true);
$selectedType = (string) ($_POST['document_type'] ?? 'submitted_request');
$title = trim((string) ($_POST['title'] ?? ''));
$mvmFormValues = $isMvmFormPost
    ? array_merge(mvm_form_default_values($request), normalize_mvm_form_data($_POST))
    : connection_request_mvm_form_values($request);
$mvmSourceValues = connection_request_mvm_source_form_values($request, $isMvmFormPost ? $_POST : null);
$mvmSourceBirthDateParts = connection_request_mvm_source_birth_date_parts((string) ($mvmSourceValues['birth_date'] ?? ''));

if ($isMvmFormPost && connection_request_mvm_source_birth_date_parts_submitted($_POST)) {
    $mvmSourceBirthDateParts = [
        'year' => trim((string) ($_POST['source_birth_date_year'] ?? '')),
        'month' => trim((string) ($_POST['source_birth_date_month'] ?? '')),
        'day' => trim((string) ($_POST['source_birth_date_day'] ?? '')),
    ];
}

$templateErrors = mvm_form_template_errors((string) ($mvmFormValues['mvm_contractor'] ?? ''));
$planTemplateErrors = mvm_plan_template_errors((string) ($mvmFormValues['mvm_contractor'] ?? ''));
$handoverTemplateErrors = mvm_technical_handover_template_errors((string) ($mvmFormValues['mvm_contractor'] ?? ''));
$sealRemovalTemplateErrors = mvm_seal_removal_template_errors((string) ($mvmFormValues['mvm_contractor'] ?? ''));
$hTariffTemplateErrors = mvm_h_tariff_template_errors();
$mvmSubmissionApproved = connection_request_mvm_submission_is_allowed($request);
$mvmSubmissionGuardMessage = connection_request_mvm_submission_guard_message($request);
$mvmPaymentApproverLabel = $mvmSubmissionApproved ? connection_request_mvm_fee_payment_approver_label($request) : '';

if (is_post()) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? 'upload');
    $deleteSketch = isset($_POST['delete_mvm_sketch']);
    $deleteDocumentId = filter_input(INPUT_POST, 'delete_mvm_document_id', FILTER_VALIDATE_INT);

    if ($deleteSketch) {
        $result = delete_connection_request_mvm_form_sketch((int) $request['id']);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A skicc kép törlése sikertelen.'));
        redirect($mvmRedirectPath . '&mvm_notice=1#mvm-generator-actions');
    }

    if ($deleteDocumentId) {
        $result = delete_connection_request_document((int) $deleteDocumentId, (int) $request['id']);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A dokumentum törlése sikertelen.'));
        redirect($mvmRedirectPath . '#mvm-documents-list');
    }

    $requiresMvmSubmissionApproval = in_array($action, [
        'generate_mvm_docx',
        'generate_mvm_pdf',
        'generate_plan_docx',
        'generate_plan_pdf',
        'generate_handover_docx',
        'generate_handover_pdf',
        'generate_seal_removal_docx',
        'generate_seal_removal_pdf',
        'generate_h_tariff_docx',
        'generate_h_tariff_pdf',
        'build_package',
        'build_execution_plan_package',
        'build_handover_package',
        'build_seal_removal_package',
        'generate_technical_declaration',
        'send_mvm_document',
    ], true);

    if ($action === 'confirm_mvm_fee_payment') {
        $result = confirm_connection_request_mvm_fee_payment((int) $request['id'], trim((string) ($_POST['payment_note'] ?? '')));
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az ügykezelési díj beérkezésének rögzítése sikertelen.'));
        redirect($mvmRedirectPath . '#mvm-payment-gate');
    }

    if ($requiresMvmSubmissionApproval && !$mvmSubmissionApproved) {
        $errors[] = $mvmSubmissionGuardMessage;
    } elseif (in_array($action, ['save_mvm_form', 'generate_mvm_docx', 'generate_mvm_pdf', 'generate_plan_docx', 'generate_plan_pdf', 'generate_handover_docx', 'generate_handover_pdf', 'generate_seal_removal_docx', 'generate_seal_removal_pdf', 'generate_h_tariff_docx', 'generate_h_tariff_pdf'], true)) {
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

        if (in_array($action, ['generate_seal_removal_docx', 'generate_seal_removal_pdf'], true) && $sealRemovalTemplateErrors !== []) {
            $errors = array_merge($errors, $sealRemovalTemplateErrors);
        }

        if (in_array($action, ['generate_h_tariff_docx', 'generate_h_tariff_pdf'], true) && $hTariffTemplateErrors !== []) {
            $errors = array_merge($errors, $hTariffTemplateErrors);
        }

        if ($errors === []) {
            try {
                if ($action === 'save_mvm_form') {
                    save_connection_request_mvm_form((int) $request['id'], $_POST, $_FILES['sketch_image'] ?? null);
                    $sourceSaveWarning = '';

                    try {
                        save_connection_request_mvm_source_data((int) $request['id'], $_POST, true);
                        $request = find_connection_request((int) $request['id']) ?? $request;
                    } catch (Throwable $sourceException) {
                        $sourceSaveWarning = APP_DEBUG
                            ? ' Az adatlap alapadatainak frissítése nem sikerült: ' . $sourceException->getMessage()
                            : ' Az adatlap alapadatai közül nem mindent sikerült frissíteni, de az MVM űrlap piszkozat mentve lett.';
                    }

                    set_flash('success', 'Az MVM űrlap piszkozatként elmentve.' . $sourceSaveWarning);
                    redirect($mvmRedirectPath . '&mvm_notice=1#mvm-generator-actions');
                }

                save_connection_request_mvm_source_data((int) $request['id'], $_POST);
                $request = find_connection_request((int) $request['id']) ?? $request;
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
                } elseif ($action === 'generate_seal_removal_pdf') {
                    $result = generate_mvm_seal_removal_pdf((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } elseif ($action === 'generate_seal_removal_docx') {
                    $result = generate_mvm_seal_removal_docx((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } elseif ($action === 'generate_h_tariff_pdf') {
                    $result = generate_mvm_h_tariff_pdf((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } elseif ($action === 'generate_h_tariff_docx') {
                    $result = generate_mvm_h_tariff_docx((int) $request['id']);
                    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                } else {
                    set_flash('success', 'Az MVM űrlap adatai elmentve.');
                }

                if (in_array($action, ['generate_handover_docx', 'generate_handover_pdf'], true)) {
                    $noticeTarget = '&handover_notice=1#technical-handover-section';
                } elseif (in_array($action, ['generate_seal_removal_docx', 'generate_seal_removal_pdf'], true)) {
                    $noticeTarget = '&seal_removal_notice=1#seal-removal-section';
                } elseif (in_array($action, ['generate_h_tariff_docx', 'generate_h_tariff_pdf'], true)) {
                    $noticeTarget = '&h_tariff_notice=1#h-tariff-section';
                } else {
                    $noticeTarget = '&mvm_notice=1#mvm-generator-actions';
                }

                redirect($mvmRedirectPath . $noticeTarget);
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
                redirect($mvmRedirectPath . '&handover_notice=1#technical-handover-section');
            }

            $errors[] = (string) $result['message'];
        }
    } elseif ($action === 'build_seal_removal_package') {
        if ($schemaErrors !== []) {
            $errors = array_merge($errors, $schemaErrors);
        } else {
            $result = generate_connection_request_seal_removal_package((int) $request['id']);

            if ($result['ok']) {
                set_flash('success', $result['message']);
                redirect($mvmRedirectPath . '&seal_removal_notice=1#seal-removal-section');
            }

            $errors[] = (string) $result['message'];
        }
    } elseif ($action === 'generate_technical_declaration') {
        if ($schemaErrors !== []) {
            $errors = array_merge($errors, $schemaErrors);
        } else {
            $result = generate_connection_request_technical_declaration((int) $request['id']);
            set_flash($result['ok'] ? 'success' : 'error', $result['message']);
            redirect($mvmRedirectPath . '&handover_notice=1#technical-handover-section');
        }
    } elseif ($action === 'upload_after_work_photos') {
        $errors = validate_connection_request_after_work_photo_uploads((int) $request['id'], $_FILES);

        if ($errors === []) {
            try {
                $uploadResult = store_connection_request_after_work_photo_uploads((int) $request['id'], $_FILES);
                $uploadMessages = $uploadResult['messages'] ?? [];

                if ($uploadMessages !== []) {
                    $errors = array_merge($errors, $uploadMessages);
                } elseif ((int) ($uploadResult['saved'] ?? 0) <= 0) {
                    $errors[] = 'Nem választottál ki új befejező fotót.';
                } else {
                    complete_electrician_work_stage((int) $request['id'], 'after');
                    set_flash('success', 'A szerelői befejező fotók feltöltve.');
                    redirect($mvmRedirectPath . '&handover_notice=1#technical-handover-section');
                }
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'A szerelői befejező fotók feltöltése sikertelen.';
            }
        }
    } elseif ($action === 'send_authorization_form') {
        $result = send_prefilled_authorization_form_email((int) $request['id']);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect($mvmRedirectPath);
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
                if (in_array($selectedType, ['completed_intervention_sheet', 'construction_log'], true)) {
                    $uploadTarget = '&handover_notice=1#technical-handover-section';
                } elseif (in_array($selectedType, ['authorization', 'seal_removal'], true)) {
                    $uploadTarget = '&seal_removal_notice=1#seal-removal-section';
                } elseif ($selectedType === 'h_tariff_declaration') {
                    $uploadTarget = '&h_tariff_notice=1#h-tariff-section';
                } else {
                    $uploadTarget = '';
                }
                redirect($mvmRedirectPath . $uploadTarget);
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
$sealRemovalPackages = connection_request_seal_removal_packages((int) $request['id']);
$packageParts = connection_request_complete_package_parts((int) $request['id']);
$missingItems = connection_request_complete_package_missing_items((int) $request['id']);
$executionPlanPackageParts = connection_request_execution_plan_package_parts((int) $request['id']);
$executionPlanMissingItems = connection_request_execution_plan_package_missing_items((int) $request['id']);
$technicalHandoverPackageParts = connection_request_technical_handover_package_parts((int) $request['id']);
$technicalHandoverMissingItems = connection_request_technical_handover_package_missing_items((int) $request['id']);
$sealRemovalPackageParts = connection_request_seal_removal_package_parts((int) $request['id']);
$sealRemovalMissingItems = connection_request_seal_removal_package_missing_items((int) $request['id']);
$technicalHandoverDocument = latest_connection_request_technical_handover_document((int) $request['id']);
$sealRemovalDocument = latest_connection_request_seal_removal_document((int) $request['id']);
$hTariffDeclarationDocument = latest_connection_request_h_tariff_declaration_document((int) $request['id'], false);
$hTariffSectionFilled = mvm_h_tariff_form_values_are_filled($mvmFormValues);
$authorizationPart = latest_connection_request_authorization_package_part((int) $request['id']);
$completedInterventionSheetDocument = latest_connection_request_technical_document((int) $request['id'], 'completed_intervention_sheet');
$constructionLogDocument = latest_connection_request_technical_document((int) $request['id'], 'construction_log');
$technicalDeclarationDocument = latest_connection_request_technical_document((int) $request['id'], 'technical_declaration');
$technicalDeclarationSourceDocument = latest_connection_request_technical_declaration_source_document((int) $request['id']);
$afterWorkPhotoParts = connection_request_after_work_photo_parts((int) $request['id']);
$afterWorkPhotoMissingItems = connection_request_required_after_photo_missing_items((int) $request['id']);
$afterWorkPhotoLabels = connection_request_required_after_photo_labels();
$afterWorkPhotoExistingTypes = [];

foreach ($afterWorkPhotoLabels as $photoType => $photoLabel) {
    $afterWorkPhotoExistingTypes[$photoType] = connection_request_has_work_file_type((int) $request['id'], 'after', (string) $photoType);
}

$technicalHandoverChecklist = [
    [
        'key' => 'technical_handover',
        'label' => 'Műszaki átadási dokumentum',
        'source' => 'Generált Word/PDF dokumentum',
        'status' => $technicalHandoverDocument !== null ? 'Rendben' : 'Hiányzik',
        'ok' => $technicalHandoverDocument !== null,
        'detail' => $technicalHandoverDocument !== null ? (string) $technicalHandoverDocument['original_name'] : 'A lenti Word/PDF gombokkal generálható.',
    ],
    [
        'key' => 'completed_intervention_sheet',
        'label' => 'Kész beavatkozási lap',
        'source' => 'Adminisztrátor tölti fel',
        'status' => $completedInterventionSheetDocument !== null ? 'Rendben' : 'Hiányzik',
        'ok' => $completedInterventionSheetDocument !== null,
        'detail' => $completedInterventionSheetDocument !== null ? (string) $completedInterventionSheetDocument['original_name'] : 'Fent az Új MVM dokumentum feltöltésnél válaszd a Kész beavatkozási lap típust.',
    ],
    [
        'key' => 'construction_log',
        'label' => 'Építési napló',
        'source' => 'Adminisztrátor tölti fel',
        'status' => $constructionLogDocument !== null ? 'Rendben' : 'Hiányzik',
        'ok' => $constructionLogDocument !== null,
        'detail' => $constructionLogDocument !== null ? (string) $constructionLogDocument['original_name'] : 'Fent az Új MVM dokumentum feltöltésnél válaszd az Építési napló típust.',
    ],
    [
        'key' => 'technical_declaration',
        'label' => 'Nyilatkozat adatlap',
        'source' => 'Jóváhagyási dokumentumból kinyerve',
        'status' => $technicalDeclarationDocument !== null ? 'Rendben' : ($technicalDeclarationSourceDocument !== null ? 'Kinyerhető' : 'Hiányzik'),
        'ok' => $technicalDeclarationDocument !== null || $technicalDeclarationSourceDocument !== null,
        'detail' => $technicalDeclarationDocument !== null
            ? (string) $technicalDeclarationDocument['original_name']
            : ($technicalDeclarationSourceDocument !== null ? 'A jóváhagyási dokumentum megvan, a lenti gombbal kinyerhető.' : 'Előbb MVM jóváhagyási dokumentum PDF szükséges.'),
    ],
    [
        'key' => 'after_work_photos',
        'label' => 'Kivitelezési fotók',
        'source' => 'Szerelői befejező fotókból',
        'status' => $afterWorkPhotoMissingItems === [] ? 'Rendben' : 'Hiányzik',
        'ok' => $afterWorkPhotoMissingItems === [],
        'detail' => $afterWorkPhotoMissingItems === []
            ? count($afterWorkPhotoParts) . ' fájl készen áll.'
            : 'Hiányzik: ' . implode(', ', $afterWorkPhotoMissingItems) . '.',
    ],
];
$sealRemovalChecklist = [
    [
        'key' => 'seal_removal',
        'label' => 'Plombabontási engedély',
        'source' => 'Generált Word/PDF dokumentum',
        'status' => $sealRemovalDocument !== null ? 'Rendben' : 'Hiányzik',
        'ok' => $sealRemovalDocument !== null,
        'detail' => $sealRemovalDocument !== null ? (string) $sealRemovalDocument['original_name'] : 'A lenti Word/PDF gombokkal generálható.',
    ],
    [
        'key' => 'authorization',
        'label' => 'Meghatalmazás',
        'source' => 'Ügyfél, szerelő vagy admin tölti fel',
        'status' => $authorizationPart !== null ? 'Rendben' : 'Hiányzik',
        'ok' => $authorizationPart !== null,
        'detail' => $authorizationPart !== null ? (string) ($authorizationPart['original_name'] ?? '') : 'Elfogadjuk az ügyfél feltöltését, a szerelő által hozott fájlt vagy az admin feltöltést.',
    ],
];
$mvmMailboxAutoSync = maybe_sync_mvm_mailbox_replies(40, 60);
$mvmEmailThreads = mvm_email_threads_with_messages((int) $request['id']);
$mvmThreadStatusLabels = mvm_email_thread_status_labels();
$mvmFormRow = connection_request_mvm_form((int) $request['id']);
$pdfMergeAvailable = class_exists('\\setasign\\Fpdi\\Fpdi');
$flash = get_flash();
$mvmNoticeTarget = (string) ($_GET['mvm_notice'] ?? '') === '1';
$handoverNoticeTarget = (string) ($_GET['handover_notice'] ?? '') === '1';
$sealRemovalNoticeTarget = (string) ($_GET['seal_removal_notice'] ?? '') === '1';
$hTariffNoticeTarget = (string) ($_GET['h_tariff_notice'] ?? '') === '1';
$topFlash = ($mvmNoticeTarget || $handoverNoticeTarget || $sealRemovalNoticeTarget || $hTariffNoticeTarget) ? null : $flash;
$mvmFormFlash = $mvmNoticeTarget ? $flash : null;
$technicalHandoverFlash = $handoverNoticeTarget ? $flash : null;
$sealRemovalFlash = $sealRemovalNoticeTarget ? $flash : null;
$hTariffFlash = $hTariffNoticeTarget ? $flash : null;
$topErrors = $isMvmFormPost ? [] : $errors;
$mvmFormErrors = ($isMvmFormPost && !$isHandoverFormPost && !$isSealRemovalFormPost && !$isHTariffFormPost) ? $errors : [];
$technicalHandoverErrors = $isHandoverFormPost ? $errors : [];
$sealRemovalErrors = $isSealRemovalFormPost ? $errors : [];
$hTariffErrors = $isHTariffFormPost ? $errors : [];
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
                <form method="post" action="<?= h($mvmPageUrl); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="send_authorization_form">
                    <button class="button button-secondary" type="submit">Nyomtatható meghatalmazás küldése</button>
                </form>
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

        <?php if ($topErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($topErrors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section id="mvm-payment-gate" class="auth-panel form-block mvm-payment-gate">
            <div class="admin-header compact">
                <div>
                    <p class="eyebrow">Ügykezelési díj</p>
                    <h2>MVM ügyindítás admin jóváhagyása</h2>
                    <?php if ($mvmSubmissionApproved): ?>
                        <p>Az ügykezelési díj beérkezése rögzítve lett. A dokumentumgenerálás és az MVM felé küldés engedélyezett.</p>
                    <?php else: ?>
                        <p><?= h($mvmSubmissionGuardMessage); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($mvmSubmissionApproved): ?>
                    <span class="status-badge status-badge-sent">Engedélyezve</span>
                <?php else: ?>
                    <span class="status-badge status-badge-failed">Zárolva</span>
                <?php endif; ?>
            </div>

            <?php if ($mvmSubmissionApproved): ?>
                <dl class="admin-request-data-list admin-request-data-list-compact">
                    <div><dt>Jóváhagyta</dt><dd><?= h($mvmPaymentApproverLabel); ?></dd></div>
                    <div><dt>Időpont</dt><dd><?= h((string) ($request['mvm_fee_payment_confirmed_at'] ?? '-')); ?></dd></div>
                    <div><dt>Megjegyzés</dt><dd><?= h((string) (($request['mvm_fee_payment_note'] ?? '') ?: '-')); ?></dd></div>
                </dl>
            <?php else: ?>
                <form class="form" method="post" action="<?= h($mvmPageUrl . '#mvm-payment-gate'); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="confirm_mvm_fee_payment">
                    <label for="payment_note">Belső megjegyzés</label>
                    <textarea id="payment_note" name="payment_note" rows="2" placeholder="Például: banki jóváírás ellenőrizve, díjbekérő kiegyenlítve"></textarea>
                    <button class="button" type="submit">Pénz beérkezett, ügyindítást jóváhagyom</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="auth-panel form-block mvm-docx-panel">
            <div class="admin-header compact">
                <div>
                    <p class="eyebrow">Fővállalkozói sablon</p>
                    <h2>MVM igénybejelentő kitöltése</h2>
                    <p>Az ügyfél- és munkaadatok itt javíthatók, az MVM-hez szükséges plusz mezőket pedig ugyanebben az űrlapban adja meg az admin. A kiválasztott fővállalkozói sablonba kerülnek az adatok és a feltöltött skicc kép.</p>
                </div>
            </div>

            <form id="mvm-docx-form" class="form mvm-docx-form" method="post" enctype="multipart/form-data" action="<?= h($mvmPageUrl); ?>">
                <?= csrf_field(); ?>

                <section class="mvm-form-section">
                    <div>
                        <h3>Adatlap adatai</h3>
                        <p>Ezek az adatok közvetlenül az ügyfélhez és a munkához mentődnek, ezért a generált MVM dokumentumok is a javított értékeket használják.</p>
                    </div>
                    <div class="mvm-field-grid">
                        <div class="mvm-input-field">
                            <label for="source_requester_name">Ügyfél neve</label>
                            <input id="source_requester_name" name="source_requester_name" value="<?= h($mvmSourceValues['requester_name']); ?>" required>
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_birth_name">Születési név</label>
                            <input id="source_birth_name" name="source_birth_name" value="<?= h($mvmSourceValues['birth_name']); ?>">
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_mother_name">Anyja neve</label>
                            <input id="source_mother_name" name="source_mother_name" value="<?= h($mvmSourceValues['mother_name']); ?>">
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_birth_place">Születési hely</label>
                            <input id="source_birth_place" name="source_birth_place" value="<?= h($mvmSourceValues['birth_place']); ?>">
                        </div>
                        <div class="mvm-input-field">
                            <label>Születési idő</label>
                            <div class="mvm-date-parts" role="group" aria-label="Születési idő">
                                <input id="source_birth_date_year" name="source_birth_date_year" value="<?= h($mvmSourceBirthDateParts['year']); ?>" inputmode="numeric" maxlength="4" placeholder="Év" aria-label="Születési év">
                                <input id="source_birth_date_month" name="source_birth_date_month" value="<?= h($mvmSourceBirthDateParts['month']); ?>" inputmode="numeric" maxlength="2" placeholder="Hó" aria-label="Születési hónap">
                                <input id="source_birth_date_day" name="source_birth_date_day" value="<?= h($mvmSourceBirthDateParts['day']); ?>" inputmode="numeric" maxlength="2" placeholder="Nap" aria-label="Születési nap">
                            </div>
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_tax_number">Adószám</label>
                            <input id="source_tax_number" name="source_tax_number" value="<?= h($mvmSourceValues['tax_number']); ?>">
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_project_name">Munka megnevezése</label>
                            <input id="source_project_name" name="source_project_name" value="<?= h($mvmSourceValues['project_name']); ?>" readonly>
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_request_type">Munka típusa</label>
                            <select id="source_request_type" name="source_request_type">
                                <?php foreach (connection_request_type_options() as $typeKey => $typeLabel): ?>
                                    <option value="<?= h((string) $typeKey); ?>" <?= (string) $mvmSourceValues['request_type'] === (string) $typeKey ? 'selected' : ''; ?>><?= h($typeLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_site_postal_code">Felhasználási hely irányítószáma</label>
                            <input id="source_site_postal_code" name="source_site_postal_code" value="<?= h($mvmSourceValues['site_postal_code']); ?>" required>
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_site_address">Felhasználási / kivitelezési cím</label>
                            <input id="source_site_address" name="source_site_address" value="<?= h($mvmSourceValues['site_address']); ?>" required>
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_hrsz">HRSZ</label>
                            <input id="source_hrsz" name="source_hrsz" value="<?= h($mvmSourceValues['hrsz']); ?>">
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_consumption_place_id">Fogyasztási hely azonosító</label>
                            <input id="source_consumption_place_id" name="source_consumption_place_id" value="<?= h($mvmSourceValues['consumption_place_id']); ?>">
                        </div>
                        <div class="mvm-input-field">
                            <label for="source_meter_serial">Mérő gyári szám</label>
                            <input id="source_meter_serial" name="source_meter_serial" value="<?= h($mvmSourceValues['meter_serial']); ?>">
                        </div>
                    </div>
                </section>

                <?php
                    $ampereOptions = [0, 2, 6, 10, 16, 20, 25, 32, 35, 40, 50, 63, 80, 100];
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
                            <button class="table-action-button table-action-danger" name="delete_mvm_sketch" value="1" type="submit" formnovalidate onclick="return confirm('Biztosan törlöd a skicc képet?');">Skicc kép törlése</button>
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
                    <button class="button button-secondary" name="action" value="save_mvm_form" type="submit" formnovalidate <?= $mvmFormSchemaErrors !== [] ? 'disabled' : ''; ?>>Adatok mentése</button>
                    <button class="button button-secondary" name="action" value="generate_mvm_docx" type="submit" <?= ($mvmFormSchemaErrors !== [] || $templateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Kitöltött Word dokumentum generálása</button>
                    <button class="button" name="action" value="generate_mvm_pdf" type="submit" <?= ($mvmFormSchemaErrors !== [] || $templateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>PDF generálása Word dokumentumból</button>
                    <button class="button button-secondary" name="action" value="generate_plan_docx" type="submit" <?= ($mvmFormSchemaErrors !== [] || $planTemplateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Terv Word dokumentum generálása</button>
                    <button class="button" name="action" value="generate_plan_pdf" type="submit" <?= ($mvmFormSchemaErrors !== [] || $planTemplateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Terv PDF generálása Word dokumentumból</button>
                    </div>
                </div>

                <section id="h-tariff-section" class="mvm-form-section">
                    <div>
                        <h3>H tarifa nyilatkozat generálása</h3>
                        <p>Ha a H tarifa nyilatkozat mezői ki vannak töltve, a PDF automatikusan bekerül az MVM jóváhagyási csomagba. H tarifa esetén a klíma matrica és a klíma adatlap PDF/kép feltöltése is szükséges. A kiválasztott hőszivattyú működési rendszer a Word sablonban aláhúzva jelenik meg.</p>
                    </div>
                    <div>
                        <?php if ($hTariffFlash !== null): ?>
                            <div class="alert alert-<?= h((string) $hTariffFlash['type']); ?>"><p><?= h((string) $hTariffFlash['message']); ?></p></div>
                        <?php endif; ?>

                        <?php if ($hTariffErrors !== []): ?>
                            <div class="alert alert-error">
                                <?php foreach ($hTariffErrors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($hTariffTemplateErrors !== []): ?>
                            <div class="alert alert-info">
                                <?php foreach ($hTariffTemplateErrors as $templateError): ?><p><?= h($templateError); ?></p><?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <p class="muted-text">
                            <?= $hTariffDeclarationDocument !== null
                                ? 'Jelenlegi H tarifa dokumentum: ' . h((string) $hTariffDeclarationDocument['original_name'])
                                : ($hTariffSectionFilled ? 'A H tarifa adatok ki vannak töltve, a dokumentum generálható.' : 'A H tarifa dokumentum csak akkor kerül a csomagba, ha a fenti H tarifa mezők közül legalább egy ki van töltve.'); ?>
                        </p>
                        <div class="form-actions">
                            <button class="button button-secondary" name="action" value="generate_h_tariff_docx" type="submit" <?= ($mvmFormSchemaErrors !== [] || $hTariffTemplateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>H tarifa Word generálása</button>
                            <button class="button" name="action" value="generate_h_tariff_pdf" type="submit" <?= ($mvmFormSchemaErrors !== [] || $hTariffTemplateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>H tarifa PDF generálása</button>
                        </div>
                    </div>
                </section>
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
                    <p>Sorrend: MVM dokumentum, H tarifa nyilatkozat ha ki van töltve, H tarifa esetén klíma matrica és klíma adatlap, meghatalmazás, tulajdoni lap, térképmásolat, hozzájáruló nyilatkozat ha van, majd fotók. Ezt küldjük el az MVM-nek jóváhagyásra. A kiviteli terv ebbe a csomagba már nem kerül bele.</p>
                </div>
                <form method="post" action="<?= h($mvmPageUrl); ?>">
                    <?= csrf_field(); ?>
                    <button class="button" name="action" value="build_package" type="submit" <?= ($missingItems !== [] || !$pdfMergeAvailable || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>MVM jóváhagyási csomag generálása</button>
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
                            <form method="post" action="<?= h($mvmPageUrl); ?>">
                                <?= csrf_field(); ?>
                                <button class="table-action-button table-action-danger" name="delete_mvm_document_id" value="<?= (int) $package['id']; ?>" type="submit" onclick="return confirm('Biztosan törlöd ezt a dokumentumot?');">Törlés</button>
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
                    <button class="button" name="action" value="build_execution_plan_package" type="submit" <?= ($executionPlanMissingItems !== [] || !$pdfMergeAvailable || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Kiviteli terv csomag generálása</button>
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
                            <form method="post" action="<?= h($mvmPageUrl); ?>">
                                <?= csrf_field(); ?>
                                <button class="table-action-button table-action-danger" name="delete_mvm_document_id" value="<?= (int) $package['id']; ?>" type="submit" onclick="return confirm('Biztosan törlöd ezt a dokumentumot?');">Törlés</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section id="technical-handover-section" class="auth-panel form-block technical-handover-panel">
            <div class="admin-header">
                <div>
                    <p class="eyebrow">Műszaki átadás</p>
                    <h2>Átadás-átvételi PDF csomag</h2>
                    <p>A sorrend: műszaki átadási dokumentum, kész beavatkozási lap, építési napló, nyilatkozat adatlap, majd a szerelői kivitelezési fotók. A kész beavatkozási lapot és az építési naplót az admin tölti fel, a nyilatkozat adatlapot a jóváhagyási dokumentumból nyerjük ki.</p>
                </div>
                <div class="mvm-handover-action-stack">
                    <div class="form-actions">
                        <form method="post" action="<?= h($mvmPageUrl . '#technical-handover-section'); ?>">
                            <?= csrf_field(); ?>
                            <button class="button" name="action" value="build_handover_package" type="submit" <?= ($technicalHandoverMissingItems !== [] || !$pdfMergeAvailable || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Műszaki átadás csomag generálása</button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($technicalHandoverFlash !== null): ?>
                <div class="alert alert-<?= h((string) $technicalHandoverFlash['type']); ?>"><p><?= h((string) $technicalHandoverFlash['message']); ?></p></div>
            <?php endif; ?>

            <?php if ($technicalHandoverErrors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($technicalHandoverErrors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($handoverTemplateErrors !== []): ?>
                <div class="alert alert-info">
                    <?php foreach ($handoverTemplateErrors as $templateError): ?><p><?= h($templateError); ?></p><?php endforeach; ?>
                    <p>A sablon hiánya csak a műszaki átadás Word/PDF generálását érinti. A többi MVM dokumentum és az adatok mentése ettől még használható.</p>
                </div>
            <?php endif; ?>

            <?php if (!$pdfMergeAvailable): ?>
                <div class="alert alert-info">
                    <p>Az automatikus PDF-összefűzéshez hiányzik az FPDI csomag az éles tárhely vendor mappájából.</p>
                </div>
            <?php endif; ?>

            <div class="handover-checklist">
                <?php foreach ($technicalHandoverChecklist as $item): ?>
                    <?php $isReady = (bool) ($item['ok'] ?? false); ?>
                    <article class="handover-check-card <?= $isReady ? 'handover-check-card-ready' : 'handover-check-card-missing'; ?>">
                        <div>
                            <span class="portal-kicker"><?= h((string) ($item['source'] ?? '')); ?></span>
                            <h3><?= h((string) ($item['label'] ?? '')); ?></h3>
                            <p><?= h((string) ($item['detail'] ?? '')); ?></p>
                        </div>
                        <span class="status-badge <?= $isReady ? 'status-badge-sent' : 'status-badge-failed'; ?>"><?= h((string) ($item['status'] ?? '')); ?></span>

                        <?php if (($item['key'] ?? '') === 'technical_handover'): ?>
                            <div class="handover-card-actions">
                                <button class="button button-secondary" form="mvm-docx-form" name="action" value="generate_handover_docx" type="submit" <?= ($mvmFormSchemaErrors !== [] || $handoverTemplateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Word generálás</button>
                                <button class="button" form="mvm-docx-form" name="action" value="generate_handover_pdf" type="submit" <?= ($mvmFormSchemaErrors !== [] || $handoverTemplateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>PDF generálás</button>
                            </div>
                        <?php elseif (in_array(($item['key'] ?? ''), ['completed_intervention_sheet', 'construction_log'], true)): ?>
                            <form class="handover-card-upload-form" method="post" enctype="multipart/form-data" action="<?= h($mvmPageUrl . '#technical-handover-section'); ?>">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="document_type" value="<?= h((string) $item['key']); ?>">
                                <input type="hidden" name="title" value="<?= h((string) $item['label']); ?>">
                                <label>
                                    <span><?= h((string) $item['label']); ?> feltöltése</span>
                                    <input name="mvm_documents[]" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required>
                                </label>
                                <button class="button button-secondary" name="action" value="upload" type="submit">Feltöltés</button>
                            </form>
                        <?php elseif (($item['key'] ?? '') === 'technical_declaration'): ?>
                            <form class="handover-card-upload-form" method="post" action="<?= h($mvmPageUrl . '#technical-handover-section'); ?>">
                                <?= csrf_field(); ?>
                                <button class="button button-secondary" name="action" value="generate_technical_declaration" type="submit" <?= (!$pdfMergeAvailable || $technicalDeclarationSourceDocument === null || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Nyilatkozat kinyerése</button>
                            </form>
                        <?php elseif (($item['key'] ?? '') === 'after_work_photos'): ?>
                            <form class="handover-card-upload-form handover-photo-upload-form" method="post" enctype="multipart/form-data" action="<?= h($mvmPageUrl . '#technical-handover-section'); ?>">
                                <?= csrf_field(); ?>
                                <?php foreach ($afterWorkPhotoLabels as $photoType => $photoLabel): ?>
                                    <?php $hasPhoto = (bool) ($afterWorkPhotoExistingTypes[$photoType] ?? false); ?>
                                    <label>
                                        <span><?= h((string) $photoLabel); ?></span>
                                        <small><?= $hasPhoto ? 'Már van feltöltve' : 'Hiányzik'; ?></small>
                                        <input name="work_file_after_<?= h((string) $photoType); ?>[]" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" <?= ((string) $photoType === 'seals') ? 'multiple' : ''; ?> <?= $hasPhoto ? '' : 'required'; ?>>
                                    </label>
                                <?php endforeach; ?>
                                <button class="button button-secondary" name="action" value="upload_after_work_photos" type="submit">Fotók feltöltése</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

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
                            <form method="post" action="<?= h($mvmPageUrl); ?>">
                                <?= csrf_field(); ?>
                                <button class="table-action-button table-action-danger" name="delete_mvm_document_id" value="<?= (int) $package['id']; ?>" type="submit" onclick="return confirm('Biztosan törlöd ezt a dokumentumot?');">Törlés</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section id="seal-removal-section" class="auth-panel form-block technical-handover-panel">
            <div class="admin-header">
                <div>
                    <p class="eyebrow">Plombabontás</p>
                    <h2>Plombabontási PDF csomag</h2>
                    <p>A sorrend: generált plombabontási engedély, majd meghatalmazás. A meghatalmazás jöhet az ügyféltől, a szerelőtől vagy admin feltöltésből; a csomaghoz PDF vagy kép fájl szükséges.</p>
                </div>
                <div class="mvm-handover-action-stack">
                    <div class="form-actions">
                        <form method="post" action="<?= h($mvmPageUrl . '#seal-removal-section'); ?>">
                            <?= csrf_field(); ?>
                            <button class="button" name="action" value="build_seal_removal_package" type="submit" <?= ($sealRemovalMissingItems !== [] || !$pdfMergeAvailable || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Plombabontás csomag generálása</button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($sealRemovalFlash !== null): ?>
                <div class="alert alert-<?= h((string) $sealRemovalFlash['type']); ?>"><p><?= h((string) $sealRemovalFlash['message']); ?></p></div>
            <?php endif; ?>

            <?php if ($sealRemovalErrors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($sealRemovalErrors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($sealRemovalTemplateErrors !== []): ?>
                <div class="alert alert-info">
                    <?php foreach ($sealRemovalTemplateErrors as $templateError): ?><p><?= h($templateError); ?></p><?php endforeach; ?>
                    <p>A sablon hiánya csak a plombabontási Word/PDF generálását érinti. A meghatalmazás feltöltése ettől még használható.</p>
                </div>
            <?php endif; ?>

            <?php if (!$pdfMergeAvailable): ?>
                <div class="alert alert-info">
                    <p>Az automatikus PDF-összefűzéshez hiányzik az FPDI csomag az éles tárhely vendor mappájából.</p>
                </div>
            <?php endif; ?>

            <div class="handover-checklist">
                <?php foreach ($sealRemovalChecklist as $item): ?>
                    <?php $isReady = (bool) ($item['ok'] ?? false); ?>
                    <article class="handover-check-card <?= $isReady ? 'handover-check-card-ready' : 'handover-check-card-missing'; ?>">
                        <div>
                            <span class="portal-kicker"><?= h((string) ($item['source'] ?? '')); ?></span>
                            <h3><?= h((string) ($item['label'] ?? '')); ?></h3>
                            <p><?= h((string) ($item['detail'] ?? '')); ?></p>
                        </div>
                        <span class="status-badge <?= $isReady ? 'status-badge-sent' : 'status-badge-failed'; ?>"><?= h((string) ($item['status'] ?? '')); ?></span>

                        <?php if (($item['key'] ?? '') === 'seal_removal'): ?>
                            <div class="handover-card-actions">
                                <button class="button button-secondary" form="mvm-docx-form" name="action" value="generate_seal_removal_docx" type="submit" <?= ($mvmFormSchemaErrors !== [] || $sealRemovalTemplateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Word generálás</button>
                                <button class="button" form="mvm-docx-form" name="action" value="generate_seal_removal_pdf" type="submit" <?= ($mvmFormSchemaErrors !== [] || $sealRemovalTemplateErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>PDF generálás</button>
                            </div>
                        <?php elseif (($item['key'] ?? '') === 'authorization'): ?>
                            <form class="handover-card-upload-form" method="post" enctype="multipart/form-data" action="<?= h($mvmPageUrl . '#seal-removal-section'); ?>">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="document_type" value="authorization">
                                <input type="hidden" name="title" value="Meghatalmazás">
                                <label>
                                    <span>Meghatalmazás feltöltése</span>
                                    <input name="mvm_documents[]" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required>
                                </label>
                                <button class="button button-secondary" name="action" value="upload" type="submit">Feltöltés</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($sealRemovalMissingItems !== []): ?>
                <div class="alert alert-info">
                    <p>A generáláshoz még hiányzik: <?= h(implode(', ', $sealRemovalMissingItems)); ?>.</p>
                </div>
            <?php endif; ?>

            <?php if ($sealRemovalPackageParts !== []): ?>
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
                            <?php foreach ($sealRemovalPackageParts as $index => $part): ?>
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
                <p class="muted-text">Még nincs összefűzhető plombabontási dokumentum.</p>
            <?php endif; ?>

            <?php if ($sealRemovalPackages !== []): ?>
                <div class="portal-card-files existing-file-panel">
                    <h3>Elkészült plombabontás csomagok</h3>
                    <div class="inline-link-list">
                        <?php foreach ($sealRemovalPackages as $package): ?>
                            <a href="<?= h(url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $package['id']); ?>" target="_blank"><?= h($package['title']); ?> - <?= h(format_bytes((int) $package['file_size'])); ?></a>
                            <form method="post" action="<?= h($mvmPageUrl); ?>">
                                <?= csrf_field(); ?>
                                <button class="table-action-button table-action-danger" name="delete_mvm_document_id" value="<?= (int) $package['id']; ?>" type="submit" onclick="return confirm('Biztosan törlöd ezt a dokumentumot?');">Törlés</button>
                            </form>
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
                    <p>A CRM-ből küldött levelek válaszcíme a <?= h(mvm_mail_reply_address()); ?> postafiók. A beérkező válaszokat a rendszer megnyitáskor frissíti, és az egyedi azonosító alapján ehhez az igényhez köti.</p>
                </div>
                <form method="post" action="<?= h($mvmPageUrl . '#mvm-mailbox'); ?>">
                    <?= csrf_field(); ?>
                    <button class="button button-secondary" name="action" value="sync_mvm_mailbox" type="submit" <?= $mvmMailSchemaErrors !== [] ? 'disabled' : ''; ?>>Válaszok frissítése most</button>
                </form>
            </div>

            <?php if (!mvm_mailbox_sync_can_run()): ?>
                <div class="alert alert-info">
                    <p><?= h(mvm_mailbox_sync_setup_message()); ?></p>
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

        <section id="mvm-documents-list" class="form-block">
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
                                <th>Művelet</th>
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
                                            <button class="button button-secondary" name="action" value="send_mvm_document" type="submit" <?= ($mvmMailSchemaErrors !== [] || !$mvmSubmissionApproved) ? 'disabled' : ''; ?>>Küldés MVM-nek</button>
                                        </form>
                                        <?php else: ?>
                                            <span class="muted-text">MVM-nek csak PDF csomagot kuldunk.</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="<?= h($mvmPageUrl); ?>">
                                            <?= csrf_field(); ?>
                                            <button class="table-action-button table-action-danger" name="delete_mvm_document_id" value="<?= (int) $document['id']; ?>" type="submit" onclick="return confirm('Biztosan törlöd ezt a dokumentumot?');">Törlés</button>
                                        </form>
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
