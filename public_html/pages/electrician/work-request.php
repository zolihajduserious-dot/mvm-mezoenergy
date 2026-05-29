<?php
declare(strict_types=1);

require_role(['electrician']);

$schemaErrors = electrician_schema_errors();
$user = current_user();
$electrician = current_electrician();

if (!is_array($user) || ($schemaErrors === [] && $electrician === null && !is_admin_user())) {
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

if ($schemaErrors === [] && $request !== null) {
    $request = minicrm_sync_connection_request_customer_contact((int) $request['id']) ?? $request;
}

$errors = [];
$flash = get_flash();
$requestTypeOptions = connection_request_type_options();
$priceItems = active_price_items();
$quoteSections = quote_price_sections();
$quantityOptions = quote_quantity_options();
$priceItemsBySection = array_fill_keys(array_keys($quoteSections), []);
$selectedQuantities = [];
$customRows = [];
$quotePhotoMessages = [];
$documentPrefillToken = document_prefill_token((string) ($_POST['document_prefill_token'] ?? ''));
$documentPrefillResult = null;
$customerForm = normalize_customer_data([
    'source' => 'Szerelői felmérés',
    'status' => 'Szerelői felmérés',
    'contact_data_accepted' => 1,
]);
$workForm = normalize_connection_request_data([]);
$quoteForm = [
    'subject' => APP_NAME . ' árajánlat',
    'customer_message' => '',
];
$quoteSurveyForm = normalize_survey_data([]);
$customer = null;

if ($request !== null) {
    $customer = find_customer((int) $request['customer_id']);
    $customerForm = $customer !== null ? normalize_customer_data($customer) : $customerForm;
    $workForm = normalize_connection_request_data($request);
    $quoteForm['subject'] = APP_NAME . ' árajánlat' . (!empty($request['project_name']) ? ' - ' . (string) $request['project_name'] : '');
    $quoteSurveyForm = normalize_survey_data(connection_request_quote_survey_seed($request));
}

$acceptedQuote = $request !== null ? accepted_quote_for_connection_request((int) $request['id']) : null;
$latestQuote = $request !== null ? latest_quote_for_connection_request((int) $request['id']) : null;
$requestDocuments = $request !== null ? connection_request_documents((int) $request['id']) : [];
$initialDataEditable = $request !== null
    ? connection_request_initial_data_is_editable($request, $latestQuote, $acceptedQuote, $requestDocuments)
    : true;

foreach ($priceItems as $item) {
    $category = quote_effective_category((string) $item['category'], (string) $item['name']);
    $priceItemsBySection[$category][] = $item;
}

if (is_post() && $schemaErrors === []) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? '');
    $deleteRequestFileId = filter_input(INPUT_POST, 'delete_request_file_id', FILTER_VALIDATE_INT);
    $deleteWorkFileId = filter_input(INPUT_POST, 'delete_work_file_id', FILTER_VALIDATE_INT);

    if ($request !== null && $deleteRequestFileId) {
        $result = delete_connection_request_file((int) $deleteRequestFileId, (int) $request['id']);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A fájl törlése sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id']);
    }

    if ($request !== null && $deleteWorkFileId) {
        $result = delete_connection_request_work_file((int) $deleteWorkFileId, (int) $request['id']);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A munkafájl törlése sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id']);
    }

    if ($action === 'extract_document_prefill') {
        $customerForm = normalize_customer_data($_POST);
        $customerForm['source'] = $customerForm['source'] !== '' ? $customerForm['source'] : 'Szerelői felmérés';
        $customerForm['status'] = $customerForm['status'] !== '' ? $customerForm['status'] : 'Szerelői felmérés';
        $customerForm['contact_data_accepted'] = 1;
        $workForm = normalize_connection_request_data($_POST, $customerForm);
        $documentPrefillResult = handle_connection_request_document_prefill($documentPrefillToken, $_FILES, $customerForm, $workForm);
        $customerForm = (array) ($documentPrefillResult['customer_form'] ?? $customerForm);
        $workForm = (array) ($documentPrefillResult['request_form'] ?? $workForm);
    }

    if ($request !== null && in_array($action, ['schedule_open_day', 'schedule_book_day', 'schedule_close_day'], true)) {
        $date = trim((string) ($_POST['work_date'] ?? ''));
        $status = match ($action) {
            'schedule_book_day' => 'booked',
            'schedule_close_day' => 'closed',
            default => 'open',
        };
        $result = connection_request_schedule_upsert_slot((int) $request['id'], $date, $status, 'electrician', (int) $user['id']);
        if (($result['ok'] ?? false)) {
            $scheduleLabels = [
                'open' => 'Szabad nap megnyitása',
                'booked' => 'Időpont lefoglalása',
                'closed' => 'Nap lezárása',
            ];
            send_admin_activity_notification(
                'Szerelő naptárat módosított',
                'Egy szerelő módosította egy munka kivitelezési naptárát.',
                [
                    [
                        'title' => 'Naptár művelet',
                        'rows' => [
                            ['label' => 'Művelet', 'value' => $scheduleLabels[$status] ?? $status],
                            ['label' => 'Nap', 'value' => connection_request_schedule_day_label($date)],
                            ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                            ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                        ],
                    ],
                ],
                [
                    ['label' => 'Munka megnyitása', 'url' => absolute_url('/admin/work-request-view?request=' . (int) $request['id'])],
                ],
                ['email' => $electrician['email'] ?? $user['email'], 'name' => $electrician['name'] ?? $user['name']],
                null,
                'Szerelő naptárművelet'
            );
        }
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A naptár frissítése sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id']);
    }

    if ($request !== null && $action === 'send_authorization_form') {
        $result = send_prefilled_authorization_form_email((int) $request['id']);
        if (($result['ok'] ?? false)) {
            send_admin_activity_notification(
                'Szerelő meghatalmazást küldött ügyfélnek',
                'Egy szerelő kiküldte az ügyfélnek az előre kitöltött meghatalmazás nyomtatványt.',
                [
                    [
                        'title' => 'Igény adatai',
                        'rows' => [
                            ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                            ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                        ],
                    ],
                ],
                [
                    ['label' => 'Munka megnyitása', 'url' => absolute_url('/admin/work-request-view?request=' . (int) $request['id'])],
                ],
                ['email' => $electrician['email'] ?? $user['email'], 'name' => $electrician['name'] ?? $user['name']],
                null,
                'Szerelő meghatalmazás küldés'
            );
        }
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A meghatalmazás email küldése sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id']);
    }

    if ($request !== null && $action === 'send_customer_document_upload_request') {
        $requestedDocumentTypes = customer_document_upload_requested_types_from_source($_POST['requested_document_types'] ?? []);
        $result = send_connection_request_customer_document_upload_request((int) $request['id'], $requestedDocumentTypes);
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A dokumentum bekérő email küldése sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id'] . '&customer_document_notice=1#customer-document-request-panel');
    }

    if ($request !== null && $action === 'send_customer_message') {
        $result = send_connection_request_manual_message(
            (int) $request['id'],
            'customer',
            trim((string) ($_POST['message_subject'] ?? '')),
            trim((string) ($_POST['message_body'] ?? '')),
            trim((string) ($_POST['customer_recipient_email'] ?? '')),
            trim((string) ($_POST['customer_recipient_name'] ?? ''))
        );
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az ügyfélüzenet küldése sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id'] . '#electrician-communication');
    }

    if ($request !== null && $action === 'sync_customer_mailbox') {
        $result = sync_mvm_mailbox_replies();
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A válaszok frissítése sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id'] . '#electrician-communication');
    }

    if ($request !== null && $action === 'save_mvm_uk_number') {
        $result = update_connection_request_mvm_uk_number((int) $request['id'], (string) ($_POST['mvm_uk_number'] ?? ''));
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az ÜK szám mentése sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id']);
    }

    if ($request !== null && $action === 'save_work_note') {
        $result = update_connection_request_work_note((int) $request['id'], (string) ($_POST['work_note'] ?? ''));
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A munka megjegyzés mentése sikertelen.'));
        redirect('/electrician/work-request?id=' . (int) $request['id']);
    }

    if ($request !== null && $action === 'save_initial_data') {
        if (!$initialDataEditable) {
            $errors[] = 'Az adatlap Folyamatban vagy későbbi státuszban van, ezért az alapadatok már nem módosíthatók.';
        }

        $customerForm = normalize_customer_data(array_merge($customer ?? [], $_POST));
        $customerForm['source'] = $customerForm['source'] !== '' ? $customerForm['source'] : (string) (($customer['source'] ?? '') ?: 'Szerelői felmérés');
        $customerForm['status'] = $customerForm['status'] !== '' ? $customerForm['status'] : (string) (($customer['status'] ?? '') ?: 'Szerelői felmérés');
        $customerForm['contact_data_accepted'] = (int) (($customer['contact_data_accepted'] ?? 0) ?: 1);
        $customerForm['notes'] = (string) ($customer['notes'] ?? '');
        $workForm = normalize_connection_request_data($_POST, $customerForm);
        $regularPrefillResult = handle_connection_request_document_prefill_from_regular_uploads($_FILES, $customerForm, $workForm, true);

        if (!($regularPrefillResult['no_files'] ?? false)) {
            $documentPrefillResult = $regularPrefillResult;

            if (($documentPrefillResult['ok'] ?? false)) {
                $customerForm = (array) ($documentPrefillResult['customer_form'] ?? $customerForm);
                $workForm = (array) ($documentPrefillResult['request_form'] ?? $workForm);
            }
        }

        $errors = array_merge(
            $errors,
            validate_customer_data($customerForm, false),
            validate_connection_request_data($workForm, $_FILES, false, (int) $request['id'])
        );

        if ($errors === []) {
            try {
                update_customer((int) $request['customer_id'], $customerForm);
                save_connection_request((int) $request['customer_id'], $workForm, (int) $request['id'], (int) $user['id'], true);
                record_connection_request_activity(
                    (int) $request['id'],
                    'request_update',
                    'Szerelő adatlapot módosított',
                    'A szerelő az ügyfél vagy a munka alapadatait javította.'
                );
                set_flash('success', 'Az adatlap alapadatai mentve lettek.');
                redirect('/electrician/work-request?id=' . (int) $request['id']);
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az adatlap mentése sikertelen.';
            }
        }
    }

    if ($action === 'create_survey') {
        $customerForm = normalize_customer_data($_POST);
        $customerForm['source'] = $customerForm['source'] !== '' ? $customerForm['source'] : 'Szerelői felmérés';
        $customerForm['status'] = $customerForm['status'] !== '' ? $customerForm['status'] : 'Szerelői felmérés';
        $customerForm['contact_data_accepted'] = 1;
        $workForm = normalize_connection_request_data($_POST);
        $regularPrefillResult = handle_connection_request_document_prefill_from_regular_uploads($_FILES, $customerForm, $workForm, true);

        if (!($regularPrefillResult['no_files'] ?? false)) {
            $documentPrefillResult = $regularPrefillResult;

            if (($documentPrefillResult['ok'] ?? false)) {
                $customerForm = (array) ($documentPrefillResult['customer_form'] ?? $customerForm);
                $workForm = (array) ($documentPrefillResult['request_form'] ?? $workForm);
            }
        }

        $quoteSubmit = (string) ($_POST['quote_submit'] ?? 'survey_only');
        $shouldSaveQuote = in_array($quoteSubmit, ['save_quote', 'send_quote'], true);
        $shouldSendQuote = $quoteSubmit === 'send_quote';
        $quoteForm = [
            'subject' => trim((string) ($_POST['quote_subject'] ?? '')),
            'customer_message' => trim((string) ($_POST['quote_customer_message'] ?? '')),
        ];
        $quoteSurveyForm = normalize_survey_data(is_array($_POST['quote_survey'] ?? null) ? $_POST['quote_survey'] : []);
        $quoteLines = collect_quote_lines($_POST);
        $errors = array_merge(validate_customer_data($customerForm, false), validate_connection_request_data($workForm, $_FILES, false));

        if ($shouldSaveQuote) {
            if ($quoteForm['subject'] === '') {
                $errors[] = 'Az árajánlat tárgya kötelező.';
            }

            if ($quoteLines === []) {
                $errors[] = 'Legalább egy ajánlati tételt adj meg.';
            }
        }

        if ($errors === []) {
            try {
                $customerId = create_customer($customerForm, null, (int) $user['id']);
                $savedRequestId = save_connection_request($customerId, $workForm, null, (int) $user['id']);
                assign_connection_request_to_electrician($savedRequestId, (int) $user['id']);
                document_prefill_attach_session_files($savedRequestId, $documentPrefillToken);
                $saveMessages = [];
                $flashType = 'success';
                $uploadMessages = handle_connection_request_uploads($savedRequestId, $_FILES, false);
                $savedQuoteId = null;

                if ($uploadMessages !== []) {
                    $flashType = 'error';
                    $saveMessages[] = 'Néhány fájl nem lett mentve: ' . implode(' ', $uploadMessages);
                }

                if ($shouldSaveQuote) {
                    $savedQuoteId = save_quote((int) $customerId, $quoteForm, $quoteSurveyForm, $quoteLines, null, $savedRequestId);

                    if (!empty($_FILES['quote_photos'])) {
                        $savedQuote = find_quote($savedQuoteId);
                        $savedSurveyId = isset($savedQuote['survey_id']) ? (int) $savedQuote['survey_id'] : null;
                        $quotePhotoMessages = handle_quote_photo_uploads($savedQuoteId, $savedSurveyId, $_FILES['quote_photos']);

                        if ($quotePhotoMessages !== []) {
                            $flashType = 'error';
                            $saveMessages[] = 'Néhány ajánlati fotó nem lett mentve: ' . implode(' ', $quotePhotoMessages);
                        }
                    }

                    if ($shouldSendQuote) {
                        $mailResult = send_quote_email($savedQuoteId);
                        $flashType = ($mailResult['ok'] ?? false) ? $flashType : 'error';
                        $saveMessages[] = (string) $mailResult['message'];
                    } else {
                        $saveMessages[] = 'Az árajánlat piszkozatként mentve lett.';
                    }
                }

                send_admin_activity_notification(
                    'Szerelő új felmérést rögzített',
                    'Egy szerelő új ügyfelet és mérőhelyi felmérést rögzített a szerelői portálon.',
                    [
                        [
                            'title' => 'Felmérés adatai',
                            'rows' => [
                                ['label' => 'Igény', 'value' => $workForm['project_name'] ?? '-'],
                                ['label' => 'Igénytípus', 'value' => connection_request_type_label($workForm['request_type'] ?? null)],
                                ['label' => 'Ügyfél', 'value' => ($customerForm['requester_name'] ?? '-') . "\n" . ($customerForm['email'] ?? '-') . "\n" . ($customerForm['phone'] ?? '-')],
                                ['label' => 'Cím', 'value' => trim((string) ($workForm['site_postal_code'] ?? '') . ' ' . (string) ($workForm['site_address'] ?? ''))],
                                ['label' => 'Árajánlat', 'value' => $savedQuoteId !== null ? ($shouldSendQuote ? 'Elkészült és ki lett küldve' : 'Piszkozatként mentve') : 'Nem készült ajánlat'],
                            ],
                        ],
                    ],
                    [
                        ['label' => 'Munka megnyitása', 'url' => absolute_url('/admin/work-request-view?request=' . $savedRequestId)],
                    ],
                    ['email' => $electrician['email'] ?? $user['email'], 'name' => $electrician['name'] ?? $user['name']],
                    $savedQuoteId,
                    'Szerelői felmérés'
                );
                set_flash($flashType, 'A felmérés rögzítve lett, és a te munkáid között marad.' . ($saveMessages !== [] ? ' ' . implode(' ', $saveMessages) : ''));
                redirect('/electrician/work-request?id=' . $savedRequestId);
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'A felmérés mentése sikertelen.';
            }
        }
    }

    if ($request !== null && $action === 'upload_request_files') {
        $hasAnyUpload = false;

        foreach (connection_request_upload_definitions() as $key => $definition) {
            foreach (uploaded_files_for_key($_FILES, 'file_' . (string) $key) as $file) {
                if (uploaded_file_is_present($file)) {
                    $hasAnyUpload = true;
                    break 2;
                }
            }
        }

        if (!$hasAnyUpload) {
            $errors[] = 'Válassz legalább egy feltöltendő fotót vagy dokumentumot.';
        }

        $errors = array_merge($errors, validate_connection_request_data(normalize_connection_request_data($request), $_FILES, false, (int) $request['id']));

        if ($errors === []) {
            try {
                $uploadMessages = handle_connection_request_uploads((int) $request['id'], $_FILES, true);

                if ($uploadMessages !== []) {
                    set_flash('error', 'Néhány fájl nem lett mentve: ' . implode(' ', $uploadMessages));
                } else {
                    set_flash('success', 'A fotók és dokumentumok mentve lettek.');
                }

                redirect('/electrician/work-request?id=' . (int) $request['id']);
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'A fájlok mentése sikertelen.';
            }
        }
    }

    if ($request !== null && $action === 'save_quote') {
        $quoteSubmit = (string) ($_POST['quote_submit'] ?? 'save_quote');
        $shouldSendQuote = $quoteSubmit === 'send_quote';
        $quoteForm = [
            'subject' => trim((string) ($_POST['quote_subject'] ?? '')),
            'customer_message' => trim((string) ($_POST['quote_customer_message'] ?? '')),
        ];
        $quoteSurveyForm = normalize_survey_data(is_array($_POST['quote_survey'] ?? null) ? $_POST['quote_survey'] : []);
        $quoteLines = collect_quote_lines($_POST);

        if (accepted_quote_for_connection_request((int) $request['id']) !== null) {
            $errors[] = 'Ehhez a munkához már van elfogadott árajánlat, ezért új ajánlatot csak admin tud rögzíteni.';
        }

        if ($quoteForm['subject'] === '') {
            $errors[] = 'Az árajánlat tárgya kötelező.';
        }

        if ($quoteLines === []) {
            $errors[] = 'Legalább egy ajánlati tételt adj meg.';
        }

        if ($errors === []) {
            try {
                $savedQuoteId = save_quote((int) $request['customer_id'], $quoteForm, $quoteSurveyForm, $quoteLines, null, (int) $request['id']);
                $saveMessages = [];
                $flashType = 'success';

                if (!empty($_FILES['quote_photos'])) {
                    $savedQuote = find_quote($savedQuoteId);
                    $savedSurveyId = isset($savedQuote['survey_id']) ? (int) $savedQuote['survey_id'] : null;
                    $quotePhotoMessages = handle_quote_photo_uploads($savedQuoteId, $savedSurveyId, $_FILES['quote_photos']);

                    if ($quotePhotoMessages !== []) {
                        $flashType = 'error';
                        $saveMessages[] = 'Néhány ajánlati fotó nem lett mentve: ' . implode(' ', $quotePhotoMessages);
                    }
                }

                if ($shouldSendQuote) {
                    $mailResult = send_quote_email($savedQuoteId);
                    $flashType = ($mailResult['ok'] ?? false) ? $flashType : 'error';
                    $saveMessages[] = (string) $mailResult['message'];
                } else {
                    $saveMessages[] = 'Az árajánlat piszkozatként mentve lett.';
                }

                send_admin_activity_notification(
                    $shouldSendQuote ? 'Szerelő árajánlatot küldött' : 'Szerelő árajánlat piszkozatot mentett',
                    $shouldSendQuote
                        ? 'Egy szerelő új árajánlatot készített és kiküldött az ügyfélnek.'
                        : 'Egy szerelő új árajánlatot készített piszkozatként.',
                    [
                        [
                            'title' => 'Ajánlat adatai',
                            'rows' => [
                                ['label' => 'Tárgy', 'value' => $quoteForm['subject'] ?? '-'],
                                ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                                ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                                ['label' => 'Tételek száma', 'value' => (string) count($quoteLines)],
                            ],
                        ],
                    ],
                    [
                        ['label' => 'Munka megnyitása', 'url' => absolute_url('/admin/work-request-view?request=' . (int) $request['id'])],
                        ['label' => 'Ajánlat megnyitása', 'url' => absolute_url('/quick-quote?quote_id=' . $savedQuoteId)],
                    ],
                    ['email' => $electrician['email'] ?? $user['email'], 'name' => $electrician['name'] ?? $user['name']],
                    $savedQuoteId,
                    'Szerelői árajánlat'
                );
                set_flash($flashType, implode(' ', $saveMessages));
                redirect('/electrician/work-request?id=' . (int) $request['id']);
            } catch (Throwable $exception) {
                $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az árajánlat mentése sikertelen.';
            }
        }
    }

    if ($request !== null && $action === 'close_workflow_stage') {
        $result = close_connection_request_workflow_stage((int) $request['id']);
        if (($result['ok'] ?? false)) {
            send_admin_activity_notification(
                'Szerelő munkafolyamat-lépést zárt',
                'Egy szerelő lezárt egy munkafolyamat-lépést a szerelői portálon.',
                [
                    [
                        'title' => 'Munka adatai',
                        'rows' => [
                            ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                            ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                            ['label' => 'Művelet', 'value' => 'Aktuális munkafolyamat-lépés lezárása'],
                        ],
                    ],
                ],
                [
                    ['label' => 'Munka megnyitása', 'url' => absolute_url('/admin/work-request-view?request=' . (int) $request['id'])],
                ],
                ['email' => $electrician['email'] ?? $user['email'], 'name' => $electrician['name'] ?? $user['name']],
                null,
                'Szerelői munkafolyamat'
            );
        }
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
$quoteSeedLines = $request !== null && $acceptedQuote === null && $latestQuote !== null ? quote_lines((int) $latestQuote['id']) : [];

foreach ($quoteSeedLines as $line) {
    $priceItemId = isset($line['price_item_id']) ? (int) $line['price_item_id'] : 0;

    if ($priceItemId > 0) {
        $selectedQuantities[$priceItemId] = quote_quantity_value((string) (int) round((float) $line['quantity']));
        continue;
    }

    $customRows[] = $line;
}

if (is_post() && in_array((string) ($_POST['action'] ?? ''), ['create_survey', 'save_quote'], true)) {
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

$electricianDueBreakdown = $request !== null ? connection_request_electrician_due_breakdown((int) $request['id']) : ['quote' => null, 'registered' => 0.0, 'specialist' => 0.0, 'total' => 0.0];
$quoteStatusLabels = quote_status_labels();
$requestDocuments = $request !== null ? connection_request_documents((int) $request['id']) : [];
$beforeFiles = $request !== null ? connection_request_work_files((int) $request['id'], 'before') : [];
$afterFiles = $request !== null ? connection_request_work_files((int) $request['id'], 'after') : [];
$requestFiles = $request !== null ? connection_request_files((int) $request['id']) : [];
$minicrmFiles = $request !== null ? minicrm_connection_request_files((int) $request['id']) : [];
$customerFiles = [];
$internalRequestFiles = [];
$requestFileStoragePaths = [];

foreach ($requestFiles as $requestFile) {
    $requestFileStoragePath = (string) ($requestFile['storage_path'] ?? '');

    if ($requestFileStoragePath !== '') {
        $requestFileStoragePaths[$requestFileStoragePath] = true;
    }

    if (connection_request_file_is_internal_upload($requestFile)) {
        $internalRequestFiles[] = $requestFile;
    } else {
        $customerFiles[] = $requestFile;
    }
}

$minicrmFiles = array_values(array_filter(
    $minicrmFiles,
    static fn (array $file): bool => !isset($requestFileStoragePaths[(string) ($file['storage_path'] ?? '')])
));

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
if ($request !== null) {
    maybe_sync_mvm_mailbox_replies(40, 60);
}

$mvmEmailThreads = $request !== null ? mvm_email_threads_with_messages((int) $request['id']) : [];
$customerCommunicationThreads = array_values(array_filter(
    $mvmEmailThreads,
    static fn (array $thread): bool => empty($thread['connection_request_document_id'])
));
$mvmEmailMessageCount = array_reduce(
    $customerCommunicationThreads,
    static fn (int $count, array $thread): int => $count + count(is_array($thread['messages'] ?? null) ? $thread['messages'] : []),
    0
);
$mvmThreadStatusLabels = mvm_email_thread_status_labels();
$scheduleSchemaErrors = connection_request_schedule_schema_errors();
$scheduleSlots = $request !== null && $scheduleSchemaErrors === [] ? connection_request_schedule_slots((int) $request['id']) : [];
$scheduleSlotsByDate = [];

foreach ($scheduleSlots as $slot) {
    $scheduleSlotsByDate[(string) $slot['work_date']] = $slot;
}

$customerDocumentUploadDefinitions = [];
$customerDocumentDefaultTypeMap = [];
$customerDocumentExistingTypeMap = [];
$customerDocumentRecipientEmail = '';
$customerDocumentPanelUrl = $request !== null ? url_path('/electrician/work-request') . '?id=' . (int) $request['id'] : url_path('/electrician/work-request');

if ($request !== null) {
    $customerDocumentUploadDefinitions = customer_document_upload_definitions();
    $customerDocumentDefaultTypes = customer_document_upload_default_types((int) $request['id']);
    $customerDocumentDefaultTypeMap = array_fill_keys($customerDocumentDefaultTypes, true);

    foreach (array_keys($customerDocumentUploadDefinitions) as $customerDocumentFileType) {
        $customerDocumentExistingTypeMap[$customerDocumentFileType] = customer_document_upload_type_has_file((int) $request['id'], (string) $customerDocumentFileType);
    }

    $customerDocumentRecipientEmail = trim((string) ($request['email'] ?? ''));
}

$customerDocumentNoticeTarget = (string) ($_GET['customer_document_notice'] ?? '') === '1';
$customerDocumentFlash = $customerDocumentNoticeTarget ? $flash : null;
$topFlash = $customerDocumentNoticeTarget ? null : $flash;

$scheduleWeekdays = connection_request_schedule_weekdays(30);
$renderQuoteFields = static function (string $fieldPrefix, array $quoteFormData, array $surveyFormData) use ($quoteSections, $priceItemsBySection, $selectedQuantities, $quantityOptions, $customRows): void {
    ?>
    <section class="auth-panel form-block">
        <h2>Szerelői árajánlat</h2>
        <p class="muted-text">Opcionális: tölts ki legalább egy árajánlati tételt, majd válaszd az ajánlat mentését vagy elküldését.</p>
        <label for="<?= h($fieldPrefix); ?>_quote_subject">Árajánlat tárgya</label>
        <input id="<?= h($fieldPrefix); ?>_quote_subject" name="quote_subject" value="<?= h($quoteFormData['subject']); ?>">
        <label for="<?= h($fieldPrefix); ?>_quote_customer_message">Üzenet az ügyfélnek</label>
        <textarea id="<?= h($fieldPrefix); ?>_quote_customer_message" name="quote_customer_message" rows="3"><?= h($quoteFormData['customer_message']); ?></textarea>
    </section>

    <section class="auth-panel form-block">
        <h2>Helyszíni felmérés az ajánlathoz</h2>
        <div class="form-grid two compact">
            <div><label for="<?= h($fieldPrefix); ?>_survey_site_address">Helyszín címe</label><input id="<?= h($fieldPrefix); ?>_survey_site_address" name="quote_survey[site_address]" value="<?= h($surveyFormData['site_address']); ?>"></div>
            <div><label for="<?= h($fieldPrefix); ?>_survey_hrsz">HRSZ</label><input id="<?= h($fieldPrefix); ?>_survey_hrsz" name="quote_survey[hrsz]" value="<?= h($surveyFormData['hrsz']); ?>"></div>
            <div><label for="<?= h($fieldPrefix); ?>_survey_work_type">Munka típusa</label><input id="<?= h($fieldPrefix); ?>_survey_work_type" name="quote_survey[work_type]" value="<?= h($surveyFormData['work_type']); ?>"></div>
            <div><label for="<?= h($fieldPrefix); ?>_survey_meter_serial">Mérő gyári száma</label><input id="<?= h($fieldPrefix); ?>_survey_meter_serial" name="quote_survey[meter_serial]" value="<?= h($surveyFormData['meter_serial']); ?>"></div>
            <div><label for="<?= h($fieldPrefix); ?>_survey_meter_location">Mérőhely helye</label><input id="<?= h($fieldPrefix); ?>_survey_meter_location" name="quote_survey[meter_location]" value="<?= h($surveyFormData['meter_location']); ?>"></div>
            <div><label for="<?= h($fieldPrefix); ?>_survey_current_phase">Jelenlegi fázis</label><input id="<?= h($fieldPrefix); ?>_survey_current_phase" name="quote_survey[current_phase]" value="<?= h($surveyFormData['current_phase']); ?>"></div>
            <div><label for="<?= h($fieldPrefix); ?>_survey_current_ampere">Jelenlegi amper</label><input id="<?= h($fieldPrefix); ?>_survey_current_ampere" name="quote_survey[current_ampere]" value="<?= h($surveyFormData['current_ampere']); ?>"></div>
            <div><label for="<?= h($fieldPrefix); ?>_survey_requested_phase">Igényelt fázis</label><input id="<?= h($fieldPrefix); ?>_survey_requested_phase" name="quote_survey[requested_phase]" value="<?= h($surveyFormData['requested_phase']); ?>"></div>
            <div><label for="<?= h($fieldPrefix); ?>_survey_requested_ampere">Igényelt amper</label><input id="<?= h($fieldPrefix); ?>_survey_requested_ampere" name="quote_survey[requested_ampere]" value="<?= h($surveyFormData['requested_ampere']); ?>"></div>
        </div>
        <label for="<?= h($fieldPrefix); ?>_survey_network_notes">Hálózati megjegyzés</label>
        <textarea id="<?= h($fieldPrefix); ?>_survey_network_notes" name="quote_survey[network_notes]" rows="3"><?= h($surveyFormData['network_notes']); ?></textarea>
        <label for="<?= h($fieldPrefix); ?>_survey_cabinet_notes">Kapcsolószekrény / mérőhely megjegyzés</label>
        <textarea id="<?= h($fieldPrefix); ?>_survey_cabinet_notes" name="quote_survey[cabinet_notes]" rows="3"><?= h($surveyFormData['cabinet_notes']); ?></textarea>
        <label for="<?= h($fieldPrefix); ?>_survey_notes">Általános megjegyzés</label>
        <textarea id="<?= h($fieldPrefix); ?>_survey_notes" name="quote_survey[survey_notes]" rows="3"><?= h($surveyFormData['survey_notes']); ?></textarea>
        <label class="checkbox-row"><input type="checkbox" name="quote_survey[has_controlled_meter]" value="1" <?= (int) $surveyFormData['has_controlled_meter'] === 1 ? 'checked' : ''; ?>><span>Vezérelt mérő van</span></label>
        <label class="checkbox-row"><input type="checkbox" name="quote_survey[has_solar]" value="1" <?= (int) $surveyFormData['has_solar'] === 1 ? 'checked' : ''; ?>><span>Napelemes rendszer érintett</span></label>
        <label class="checkbox-row"><input type="checkbox" name="quote_survey[has_h_tariff]" value="1" <?= (int) $surveyFormData['has_h_tariff'] === 1 ? 'checked' : ''; ?>><span>H-tarifa érintett</span></label>
    </section>

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

    <section class="auth-panel form-block">
        <h2>Ajánlathoz kapcsolódó helyszíni fotók</h2>
        <input name="quote_photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple capture="environment">
    </section>
    <?php
};

$renderElectricianWorkPhotoForm = static function (array $request, string $stage, bool $stageLocked, string $buttonLabel): void {
    $requestId = (int) $request['id'];
    ?>
    <?php if ($stageLocked): ?>
        <div class="alert alert-info"><p>A befejezés csak azután menthető, hogy a kivitelezés előtti fotókat feltöltötted és a munkakezdést lezártad.</p></div>
    <?php endif; ?>
    <form class="form electrician-work-photo-form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/electrician/work-request') . '?id=' . $requestId); ?>">
        <?= csrf_field(); ?>
        <input type="hidden" name="action" value="<?= $stage === 'before' ? 'complete_before' : 'complete_after'; ?>">
        <div class="file-upload-grid">
            <?php foreach (electrician_work_file_definitions($stage) as $key => $definition): ?>
                <?php $hasExisting = connection_request_has_work_file_type($requestId, $stage, (string) $key); ?>
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
            <button class="button" type="submit" <?= $stageLocked ? 'disabled' : ''; ?>><?= h($buttonLabel); ?></button>
            <button class="button button-secondary" type="button" data-work-dialog-close>Bezárás</button>
        </div>
    </form>
    <?php
};
?>
<section class="admin-section electrician-work-detail-page">
    <div class="container admin-requests-container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Szerelői portál</p>
                <h1><?= $request === null ? 'Új ügyfél felmérése' : 'Kivitelezési munka'; ?></h1>
                <p><?= $request === null ? 'Új ügyfelet és mérőhelyi igényt rögzíthetsz, ami a te neved alatt marad.' : h((string) $displayCustomerName . ' · ' . (string) $request['project_name']); ?></p>
            </div>
            <div class="admin-actions">
                <?php if ($request !== null): ?>
                    <?php $topAfterLocked = empty($request['before_photos_completed_at']); ?>
                    <button class="button" type="button" data-work-dialog-open="before">Megkezdem a kivitelezést</button>
                    <button class="button button-secondary" type="button" data-work-dialog-open="after" <?= $topAfterLocked ? 'title="Előbb a kivitelezés előtti fotókat kell menteni."' : ''; ?>>Befejezem a kivitelezést</button>
                <?php endif; ?>
                <?php if ($request !== null): ?><a class="button" href="<?= h(authorization_signature_url($request)); ?>" target="_blank">Meghatalmazás online aláírása</a><?php endif; ?>
                <?php if ($request !== null): ?>
                    <form method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="send_authorization_form">
                        <button class="button button-secondary" type="submit">Nyomtatható meghatalmazás küldése</button>
                    </form>
                <?php endif; ?>
                <a class="button button-secondary" href="<?= h(url_path('/electrician/work-requests')); ?>">Munkáim</a>
            </div>
        </div>

        <?php if ($request !== null): ?>
            <?php $afterPhotoDialogLocked = empty($request['before_photos_completed_at']); ?>
            <dialog class="electrician-work-dialog" id="electricianWorkBeforeDialog" data-work-dialog="before" aria-labelledby="electricianWorkBeforeTitle">
                <div class="electrician-work-dialog-card">
                    <div>
                        <p class="eyebrow">Kivitelezés indítása</p>
                        <h2 id="electricianWorkBeforeTitle">Kivitelezés előtti fotók</h2>
                        <p>Mentsd el a kötelező induló képeket, mielőtt megkezded a helyszíni munkát.</p>
                    </div>
                    <?php $renderElectricianWorkPhotoForm($request, 'before', false, 'Megkezdem a kivitelezést'); ?>
                </div>
            </dialog>
            <dialog class="electrician-work-dialog" id="electricianWorkAfterDialog" data-work-dialog="after" aria-labelledby="electricianWorkAfterTitle">
                <div class="electrician-work-dialog-card">
                    <div>
                        <p class="eyebrow">Kivitelezés lezárása</p>
                        <h2 id="electricianWorkAfterTitle">Kivitelezés utáni fotók</h2>
                        <p>Mentsd el a kész munka fotóit és az elkészült beavatkozási lapot, amikor végeztél.</p>
                    </div>
                    <?php $renderElectricianWorkPhotoForm($request, 'after', $afterPhotoDialogLocked, 'Befejezem a kivitelezést'); ?>
                </div>
            </dialog>
        <?php endif; ?>

        <?php if ($topFlash !== null): ?>
            <div class="alert alert-<?= h((string) $topFlash['type']); ?>"><p><?= h((string) $topFlash['message']); ?></p></div>
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
            <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/electrician/work-request')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="create_survey">
                <?php render_connection_request_document_prefill_panel($documentPrefillToken, $documentPrefillResult); ?>

                <div class="form-grid two">
                    <section class="auth-panel">
                        <h2>Ügyfél adatai</h2>
                        <label for="requester_name">Név</label>
                        <input id="requester_name" name="requester_name" value="<?= h($customerForm['requester_name']); ?>" required>
                        <label for="phone">Telefon</label>
                        <input id="phone" name="phone" value="<?= h($customerForm['phone']); ?>" required>
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= h($customerForm['email']); ?>" required>
                        <label for="mvm_uk_number">ÜK szám</label>
                        <input id="mvm_uk_number" name="mvm_uk_number" value="<?= h($workForm['mvm_uk_number']); ?>" placeholder="MVM ÜK szám">
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
                        <select id="request_type" name="request_type" data-request-type-select required>
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
                        <label for="requested_general_power">Igényelt teljesítmény mindennapszaki</label>
                        <input id="requested_general_power" name="requested_general_power" value="<?= h($workForm['requested_general_power']); ?>">
                        <label for="existing_h_tariff_power">Meglévő teljesítmény H tarifa</label>
                        <input id="existing_h_tariff_power" name="existing_h_tariff_power" value="<?= h($workForm['existing_h_tariff_power']); ?>">
                        <label for="requested_h_tariff_power">Igényelt teljesítmény H tarifa</label>
                        <input id="requested_h_tariff_power" name="requested_h_tariff_power" value="<?= h($workForm['requested_h_tariff_power']); ?>">
                        <label for="existing_controlled_power">Meglévő teljesítmény vezérelt</label>
                        <input id="existing_controlled_power" name="existing_controlled_power" value="<?= h($workForm['existing_controlled_power']); ?>">
                        <label for="requested_controlled_power">Igényelt teljesítmény vezérelt</label>
                        <input id="requested_controlled_power" name="requested_controlled_power" value="<?= h($workForm['requested_controlled_power']); ?>">
                        <label for="work_note">Munka megjegyzés</label>
                        <textarea id="work_note" name="work_note" rows="3" placeholder="Belső megjegyzés a munkához"><?= h($workForm['work_note']); ?></textarea>
                    </section>
                </div>

                <section class="auth-panel form-block">
                    <h2>Fotók és kitöltött dokumentumok</h2>
                    <p class="muted-text">Ugyanazokat a fotókat és dokumentumokat töltheted fel, mint az ügyféloldali igényrögzítésnél.</p>
                    <div class="file-upload-grid">
                        <?php foreach (connection_request_upload_definitions() as $key => $definition): ?>
                            <?php
                            $isImage = $definition['kind'] === 'image';
                            $isHTariffRequired = !empty($definition['h_tariff_required']);
                            $accept = connection_request_upload_accept($definition);
                            ?>
                            <label class="file-upload-item">
                                <span><?= h($definition['label']); ?><?= $isHTariffRequired ? ' *' : ''; ?></span>
                                <small><?= $isHTariffRequired ? 'H tarifa esetén kötelező, PDF vagy kép formátumban.' : 'Opcionális, több fájl is feltölthető.'; ?></small>
                                <input name="file_<?= h($key); ?>[]" type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?> <?= $isHTariffRequired ? 'data-h-tariff-required="1" data-has-existing="0"' : ''; ?>>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="form-actions">
                    <button class="button button-secondary" name="quote_submit" value="survey_only" type="submit">Felmérés mentése</button>
                </div>
            </form>
        <?php elseif ($schemaErrors === [] && $request !== null): ?>
            <article class="request-admin-card electrician-module-stack">
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

                <?php
                $initialDataTitle = ui_module_text('electrician_work_detail', 'initial_data', 'title', 'Adatlap alapadatok javítása');
                $initialDataSubtitle = ui_module_text('electrician_work_detail', 'initial_data', 'subtitle', 'Folyamatban előtt');
                ?>
                <?php if ($initialDataEditable): ?>
                    <section <?= ui_module_attrs('electrician_work_detail', 'initial_data', 'admin-request-panel admin-request-panel-wide'); ?>>
                        <div class="admin-request-section-title">
                            <h3><?= h($initialDataTitle); ?></h3>
                            <span><?= h($initialDataSubtitle); ?></span>
                        </div>
                        <form class="form" method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="save_initial_data">
                            <div class="form-grid two compact">
                                <div><input type="hidden" name="is_legal_entity" value="0"><label class="checkbox-row"><input type="checkbox" name="is_legal_entity" value="1" <?= (int) $customerForm['is_legal_entity'] === 1 ? 'checked' : ''; ?>><span>Jogi személy</span></label></div>
                                <div><label>Név</label><input name="requester_name" value="<?= h($customerForm['requester_name']); ?>" required></div>
                                <div><label>Cégnév</label><input name="company_name" value="<?= h($customerForm['company_name']); ?>"></div>
                                <div><label>Adószám</label><input name="tax_number" value="<?= h($customerForm['tax_number']); ?>"></div>
                                <div>
                                    <label>Telefon</label>
                                    <input name="phone" value="<?= h($customerForm['phone']); ?>" required>
                                    <?php if (trim((string) $customerForm['phone']) !== ''): ?><div class="phone-field-call"><?= phone_link_html($customerForm['phone'], ''); ?></div><?php endif; ?>
                                </div>
                                <div><label>Email</label><input name="email" type="email" value="<?= h($customerForm['email']); ?>" required></div>
                                <div><label>ÜK szám</label><input name="mvm_uk_number" value="<?= h($workForm['mvm_uk_number']); ?>" placeholder="MVM ÜK szám"></div>
                                <div><label>Irányítószám</label><input name="postal_code" value="<?= h($customerForm['postal_code']); ?>" required></div>
                                <div><label>Település</label><input name="city" value="<?= h($customerForm['city']); ?>" required></div>
                                <div><label>Postai cím</label><input name="postal_address" value="<?= h($customerForm['postal_address']); ?>" required></div>
                                <div><label>Levelezési cím</label><input name="mailing_address" value="<?= h($customerForm['mailing_address']); ?>"></div>
                                <div><label>Születési név</label><input name="birth_name" value="<?= h($customerForm['birth_name']); ?>"></div>
                                <div><label>Anyja neve</label><input name="mother_name" value="<?= h($customerForm['mother_name']); ?>"></div>
                                <div><label>Születési hely</label><input name="birth_place" value="<?= h($customerForm['birth_place']); ?>"></div>
                                <div><label>Születési idő</label><input name="birth_date" type="date" value="<?= h($customerForm['birth_date']); ?>"></div>
                                <div><label>Igénytípus</label><select name="request_type" required><?php foreach ($requestTypeOptions as $typeKey => $typeLabel): ?><option value="<?= h($typeKey); ?>" <?= $workForm['request_type'] === $typeKey ? 'selected' : ''; ?>><?= h($typeLabel); ?></option><?php endforeach; ?></select></div>
                                <div><label>Igény megnevezése</label><input name="project_name" value="<?= h($workForm['project_name']); ?>"></div>
                                <div><label>Kivitelezés irányítószáma</label><input name="site_postal_code" value="<?= h($workForm['site_postal_code']); ?>"></div>
                                <div><label>Kivitelezés címe</label><input name="site_address" value="<?= h($workForm['site_address']); ?>"></div>
                                <div><label>HRSZ</label><input name="hrsz" value="<?= h($workForm['hrsz']); ?>"></div>
                                <div><label>Mérő gyári száma</label><input name="meter_serial" value="<?= h($workForm['meter_serial']); ?>"></div>
                                <div><label>Fogyasztási hely azonosító</label><input name="consumption_place_id" value="<?= h($workForm['consumption_place_id']); ?>"></div>
                                <div><label>Meglévő teljesítmény</label><input name="existing_general_power" value="<?= h($workForm['existing_general_power']); ?>"></div>
                                <div><label>Igényelt teljesítmény</label><input name="requested_general_power" value="<?= h($workForm['requested_general_power']); ?>"></div>
                                <div><label>Meglévő H tarifa</label><input name="existing_h_tariff_power" value="<?= h($workForm['existing_h_tariff_power']); ?>"></div>
                                <div><label>Igényelt H tarifa</label><input name="requested_h_tariff_power" value="<?= h($workForm['requested_h_tariff_power']); ?>"></div>
                                <div><label>Meglévő vezérelt</label><input name="existing_controlled_power" value="<?= h($workForm['existing_controlled_power']); ?>"></div>
                                <div><label>Igényelt vezérelt</label><input name="requested_controlled_power" value="<?= h($workForm['requested_controlled_power']); ?>"></div>
                            </div>
                            <label>Megjegyzés</label><textarea name="notes" rows="3"><?= h($workForm['notes']); ?></textarea>
                            <label>Munka megjegyzés</label><textarea name="work_note" rows="3" placeholder="Belső megjegyzés a munkához"><?= h($workForm['work_note']); ?></textarea>
                            <div class="form-actions">
                                <button class="button" type="submit">Alapadatok mentése</button>
                            </div>
                        </form>
                    </section>
                <?php else: ?>
                    <div <?= ui_module_attrs('electrician_work_detail', 'initial_data', 'alert alert-info'); ?>><p>Az adatlap már Folyamatban vagy későbbi státuszban van, ezért az MVM-beadás alapadatai itt nem módosíthatók.</p></div>
                <?php endif; ?>

                <?php if ((float) ($electricianDueBreakdown['total'] ?? 0) > 0): ?>
                    <?php
                    $paymentSummaryTitle = ui_module_text('electrician_work_detail', 'payment_summary', 'title', 'Kivitelezéskor beszedendő összeg');
                    $paymentSummarySubtitle = ui_module_text('electrician_work_detail', 'payment_summary', 'subtitle', format_money((float) $electricianDueBreakdown['total']));
                    ?>
                    <section <?= ui_module_attrs('electrician_work_detail', 'payment_summary', 'admin-request-panel workflow-stage-panel'); ?>>
                        <div class="admin-request-section-title">
                            <h3><?= h($paymentSummaryTitle); ?></h3>
                            <span><?= h($paymentSummarySubtitle); ?></span>
                        </div>
                        <dl class="admin-request-data-list admin-request-data-list-compact">
                            <div><dt>Regisztrált villanyszerelői tételek</dt><dd><?= h(format_money((float) $electricianDueBreakdown['registered'])); ?></dd></div>
                            <div><dt>Villanyszerelői szakmunkás tételek</dt><dd><?= h(format_money((float) $electricianDueBreakdown['specialist'])); ?></dd></div>
                            <div><dt>Nem része</dt><dd>MVM csekk és ügykezelési díj</dd></div>
                        </dl>
                    </section>
                <?php endif; ?>

                <?php if ($workflowDefinition !== null): ?>
                    <?php
                    $workflowModuleTitle = ui_module_text('electrician_work_detail', 'workflow', 'title', 'Munkafolyamat');
                    $workflowModuleSubtitle = ui_module_text('electrician_work_detail', 'workflow', 'subtitle', (int) $workflowDefinition['number'] . '. ' . (string) $workflowDefinition['title']);
                    $workflowModuleBody = ui_module_text('electrician_work_detail', 'workflow', 'body', (string) $workflowDefinition['description']);
                    ?>
                    <section <?= ui_module_attrs('electrician_work_detail', 'workflow', 'admin-request-panel workflow-stage-panel electrician-workflow-panel'); ?>>
                        <div class="admin-request-section-title">
                            <h3><?= h($workflowModuleTitle); ?></h3>
                            <span><?= h($workflowModuleSubtitle); ?></span>
                        </div>
                        <p class="muted-text"><?= h($workflowModuleBody); ?></p>
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

                <?php if ($scheduleSchemaErrors === []): ?>
                    <?php
                    $scheduleModuleTitle = ui_module_text('electrician_work_detail', 'schedule_calendar', 'title', 'Kivitelezési naptár');
                    $scheduleModuleSubtitle = ui_module_text('electrician_work_detail', 'schedule_calendar', 'subtitle', 'Csak hétköznap');
                    $scheduleModuleBody = ui_module_text('electrician_work_detail', 'schedule_calendar', 'body', 'Nyisd meg azokat a napokat, amikor vállalható a munka. A kiválasztott nap egyetlen munkanapként foglalódik.');
                    ?>
                    <details <?= ui_module_attrs('electrician_work_detail', 'schedule_calendar', 'admin-request-panel admin-request-documents electrician-schedule-details'); ?>>
                        <summary class="admin-request-section-title electrician-schedule-summary">
                            <h3><?= h($scheduleModuleTitle); ?></h3>
                            <span>
                                <span class="electrician-schedule-open-label">Megnyitás</span>
                                <span class="electrician-schedule-close-label">Bezárás</span>
                                · <?= h($scheduleModuleSubtitle); ?>
                            </span>
                        </summary>
                        <div class="electrician-schedule-body">
                            <p class="muted-text"><?= h($scheduleModuleBody); ?></p>
                            <div class="quote-mini-list">
                                <?php foreach ($scheduleWeekdays as $workDate): ?>
                                <?php
                                $slot = $scheduleSlotsByDate[$workDate] ?? null;
                                $slotStatus = (string) ($slot['status'] ?? '');
                                $slotLabel = match ($slotStatus) {
                                    'booked' => 'Foglalva',
                                    'closed' => 'Lezárva',
                                    'open' => 'Szabad',
                                    default => 'Nincs megnyitva',
                                };
                                $slotActorLabel = is_array($slot) ? connection_request_schedule_slot_actor_label($slot, $request) : '';
                                ?>
                                <article class="quote-mini-card">
                                    <strong><?= h(connection_request_schedule_day_label($workDate)); ?></strong>
                                    <span><?= h($slotLabel); ?></span>
                                    <?php if ($slotActorLabel !== ''): ?><span><?= h($slotActorLabel); ?></span><?php endif; ?>
                                    <form class="inline-form" method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="work_date" value="<?= h($workDate); ?>">
                                        <button class="button button-secondary" name="action" value="schedule_open_day" type="submit">Nyitás</button>
                                        <button class="button" name="action" value="schedule_book_day" type="submit">Erre a napra teszem</button>
                                        <button class="button button-secondary" name="action" value="schedule_close_day" type="submit">Lezárás</button>
                                    </form>
                                </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>
                <?php else: ?>
                    <div <?= ui_module_attrs('electrician_work_detail', 'schedule_calendar', 'alert alert-error'); ?>><?php foreach ($scheduleSchemaErrors as $scheduleError): ?><p><?= h($scheduleError); ?></p><?php endforeach; ?></div>
                <?php endif; ?>

                <div <?= ui_module_attrs('electrician_work_detail', 'request_data', 'admin-request-panel-grid'); ?>>
                    <section class="admin-request-panel">
                        <h3>Ügyfél</h3>
                        <dl class="admin-request-data-list">
                            <div><dt>Név</dt><dd><?= h($displayCustomerName !== '' ? $displayCustomerName : '-'); ?></dd></div>
                            <div><dt>Email</dt><dd><?= h($displayCustomerEmail !== '' ? $displayCustomerEmail : '-'); ?></dd></div>
                            <div><dt>Telefon</dt><dd><?= phone_link_html($displayCustomerPhone); ?></dd></div>
                            <div><dt>ÜK szám</dt><dd><?= h((string) (($request['mvm_uk_number'] ?? '') ?: '-')); ?></dd></div>
                        </dl>
                        <form class="inline-form" method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="save_mvm_uk_number">
                            <input name="mvm_uk_number" value="<?= h((string) ($request['mvm_uk_number'] ?? '')); ?>" placeholder="MVM ÜK szám" aria-label="MVM ÜK szám">
                            <button class="button button-secondary" type="submit">ÜK szám mentése</button>
                        </form>
                        <form class="inline-form" method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="save_work_note">
                            <textarea name="work_note" rows="3" placeholder="Megjegyzés a munkához"><?= h((string) ($request['work_note'] ?? '')); ?></textarea>
                            <button class="button button-secondary" type="submit">Megjegyzés mentése</button>
                        </form>
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
                            <?php if (!empty($request['work_note'])): ?>
                                <div class="admin-request-data-wide"><dt>Munka megjegyzés</dt><dd><?= h((string) $request['work_note']); ?></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($request['notes'])): ?>
                                <div class="admin-request-data-wide"><dt>Megjegyzés</dt><dd><?= h((string) $request['notes']); ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    </section>
                </div>

                <div class="request-admin-footer">
                    <?php
                    $filesModuleTitle = ui_module_text('electrician_work_detail', 'files', 'title', 'Ügyfél által feltöltött fájlok');
                    $filesModuleSubtitle = ui_module_text('electrician_work_detail', 'files', 'subtitle', count($customerFiles) . ' db');
                    ?>
                    <section <?= ui_module_attrs('electrician_work_detail', 'files', 'admin-request-panel admin-request-documents'); ?>>
                        <div class="admin-request-section-title">
                            <h3><?= h($filesModuleTitle); ?></h3>
                            <span><?= h($filesModuleSubtitle); ?></span>
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
                                            <span>Feltöltő: <?= h(portal_file_uploader_label($file)); ?></span>
                                            <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                            <form method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                                                <?= csrf_field(); ?>
                                                <button class="table-action-button table-action-danger" name="delete_request_file_id" value="<?= (int) $file['id']; ?>" type="submit" onclick="return confirm('Biztosan törlöd ezt a fájlt?');">Törlés</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="admin-request-section-title admin-request-subtitle">
                            <h3>Szerelői és MiniCRM fájlok</h3>
                            <span><?= count($internalRequestFiles) + count($minicrmFiles); ?> db</span>
                        </div>
                        <?php if ($internalRequestFiles === [] && $minicrmFiles === []): ?>
                            <p class="request-admin-empty">Nincs szerelő, admin vagy MiniCRM által feltöltött fájl ehhez a munkához.</p>
                        <?php else: ?>
                            <div class="admin-request-doc-grid">
                                <?php foreach ($internalRequestFiles as $file): ?>
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
                                            <span>Feltöltő: <?= h(portal_file_uploader_label($file)); ?></span>
                                            <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                            <form method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                                                <?= csrf_field(); ?>
                                                <button class="table-action-button table-action-danger" name="delete_request_file_id" value="<?= (int) $file['id']; ?>" type="submit" onclick="return confirm('Biztosan törlöd ezt a fájlt?');">Törlés</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                                <?php foreach ($minicrmFiles as $file): ?>
                                    <?php
                                    $fileUrl = url_path('/electrician/work-requests/minicrm-file') . '?id=' . (int) $file['id'];
                                    $previewKind = portal_file_preview_kind($file);
                                    ?>
                                    <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
                                        <div class="admin-request-doc-thumb">
                                            <?php if ($previewKind === 'image'): ?>
                                                <a href="<?= h($fileUrl); ?>" target="_blank" aria-label="<?= h((string) ($file['label'] ?? 'MiniCRM fájl')); ?> megnyitása">
                                                    <img src="<?= h($fileUrl); ?>" alt="<?= h((string) ($file['label'] ?? 'MiniCRM fájl')); ?>" width="112" height="112" loading="lazy">
                                                </a>
                                            <?php elseif ($previewKind === 'pdf'): ?>
                                                <iframe src="<?= h($fileUrl); ?>#toolbar=0&navpanes=0" title="<?= h((string) ($file['label'] ?? 'MiniCRM fájl')); ?>" width="112" height="112" loading="lazy"></iframe>
                                            <?php else: ?>
                                                <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($file)); ?></span></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="admin-request-doc-meta">
                                            <strong><?= h((string) ($file['label'] ?? 'MiniCRM fájl')); ?></strong>
                                            <span><?= h((string) ($file['original_name'] ?? '-')); ?></span>
                                            <span>Feltöltő: <?= h(portal_file_uploader_label($file)); ?></span>
                                            <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="admin-request-section-title admin-request-subtitle" id="electrician-request-files">
                            <h3>Fotók és dokumentumok feltöltése</h3>
                            <span>adatlap dokumentum</span>
                        </div>
                        <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="upload_request_files">
                            <div class="file-upload-grid">
                                <?php foreach (connection_request_upload_definitions() as $key => $definition): ?>
                                    <?php
                                    $isImage = $definition['kind'] === 'image';
                                    $isHTariffRequired = !empty($definition['h_tariff_required']);
                                    $accept = connection_request_upload_accept($definition);

                                    ?>
                                    <label class="file-upload-item">
                                        <span><?= h($definition['label']); ?></span>
                                        <small><?= ($isHTariffRequired ? connection_request_has_package_file_type((int) $request['id'], (string) $key) : connection_request_has_file_type((int) $request['id'], (string) $key)) ? 'Már van ilyen feltöltés, de új fájlt is hozzáadhatsz.' : ($isHTariffRequired ? 'H tarifa esetén kötelező, PDF vagy kép formátumban.' : 'Opcionális, több fájl is feltölthető.'); ?></small>
                                        <input name="file_<?= h($key); ?>[]" type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?>>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-actions">
                                <button class="button button-secondary" type="submit">Fotók és dokumentumok mentése</button>
                            </div>
                        </form>

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

                        <?php if ($acceptedQuote === null): ?>
                            <div class="admin-request-section-title admin-request-subtitle">
                                <h3>Gyors árajánlat</h3>
                                <span><?= $latestQuote !== null ? 'frissített ajánlat' : 'új ajánlat'; ?></span>
                            </div>
                            <p class="muted-text">Árajánlatot egységesen a gyors árajánlat felületen lehet készíteni, módosítani és kiküldeni.</p>
                            <div class="form-actions">
                                <a class="button" href="<?= h(url_path('/quick-quote') . '?request_id=' . (int) $request['id']); ?>">Gyors árajánlat megnyitása</a>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section id="customer-document-request-panel" class="admin-request-panel admin-request-documents customer-document-request-panel">
                        <div class="admin-header compact">
                            <div>
                                <p class="eyebrow">Ügyféldokumentum</p>
                                <h3>Dokumentum bekérése ügyféltől</h3>
                                <p>Tokenes feltöltőlinket küld az ügyfél email címére, így regisztráció nélkül tudja pótolni a hiányzó dokumentumokat és fotókat. A feltöltés közvetlenül erre az adatlapra kerül.</p>
                            </div>
                            <span class="status-badge <?= $customerDocumentRecipientEmail !== '' ? 'status-badge-sent' : 'status-badge-failed'; ?>">
                                <?= $customerDocumentRecipientEmail !== '' ? h($customerDocumentRecipientEmail) : 'Nincs email cím'; ?>
                            </span>
                        </div>

                        <?php if ($customerDocumentRecipientEmail === ''): ?>
                            <div class="alert alert-error"><p>Az ügyfél email címe hiányzik, ezért a bekérő link nem küldhető ki.</p></div>
                        <?php endif; ?>

                        <?php if ($customerDocumentFlash !== null): ?>
                            <div class="alert alert-<?= h((string) $customerDocumentFlash['type']); ?>"><p><?= h((string) $customerDocumentFlash['message']); ?></p></div>
                        <?php endif; ?>

                        <form class="form" method="post" action="<?= h($customerDocumentPanelUrl . '#customer-document-request-panel'); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="send_customer_document_upload_request">

                            <div class="customer-document-request-list">
                                <?php foreach ($customerDocumentUploadDefinitions as $fileType => $definition): ?>
                                    <?php
                                    $fileType = (string) $fileType;
                                    $hasCustomerDocumentFile = !empty($customerDocumentExistingTypeMap[$fileType]);
                                    $isDefaultCustomerDocument = isset($customerDocumentDefaultTypeMap[$fileType]);
                                    ?>
                                    <label class="customer-document-request-option">
                                        <input
                                            type="checkbox"
                                            name="requested_document_types[]"
                                            value="<?= h($fileType); ?>"
                                            <?= $isDefaultCustomerDocument ? 'checked' : ''; ?>
                                        >
                                        <span>
                                            <strong><?= h((string) $definition['label']); ?></strong>
                                            <small><?= h(customer_document_upload_type_help_text($fileType)); ?></small>
                                        </span>
                                        <span class="status-badge <?= $hasCustomerDocumentFile ? 'status-badge-sent' : 'status-badge-pending'; ?>">
                                            <?= $hasCustomerDocumentFile ? 'Már van' : 'Hiányzik'; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <div class="form-actions">
                                <button class="button" type="submit" <?= $customerDocumentRecipientEmail === '' ? 'disabled' : ''; ?>>Bekérő email küldése</button>
                            </div>
                        </form>
                    </section>

                    <?php
                    $communicationModuleTitle = ui_module_text('electrician_work_detail', 'customer_communication', 'title', 'Ügyfélkommunikáció');
                    $communicationModuleSubtitle = ui_module_text('electrician_work_detail', 'customer_communication', 'subtitle', $mvmEmailMessageCount . ' üzenet');
                    $communicationModuleBody = ui_module_text('electrician_work_detail', 'customer_communication', 'body', 'Itt ugyanaz az ügyféllel folytatott levelezés látszik, amit az admin is lát. Ha az ügyfél válaszol, a válasz az azonosító alapján ehhez az adatlaphoz kerül.');
                    ?>
                    <section id="electrician-communication" <?= ui_module_attrs('electrician_work_detail', 'customer_communication', 'admin-request-panel admin-request-documents communication-panel'); ?>>
                        <div class="admin-request-section-title">
                            <h3><?= h($communicationModuleTitle); ?></h3>
                            <span><?= h($communicationModuleSubtitle); ?></span>
                        </div>
                        <p class="muted-text"><?= h($communicationModuleBody); ?></p>
                        <dl class="admin-request-data-list admin-request-data-list-compact">
                            <div><dt>Ügy állása</dt><dd><?= h($workflowDefinition !== null ? (string) $workflowDefinition['title'] : admin_workflow_stage_label($workflowStage)); ?></dd></div>
                            <div><dt>Ajánlat</dt><dd><?= h($quoteSummaryLabel . ' · ' . $quoteSummaryAmount); ?></dd></div>
                            <div><dt>Ügyfél email</dt><dd><?= h($displayCustomerEmail !== '' ? $displayCustomerEmail : '-'); ?></dd></div>
                        </dl>
                        <form class="portal-message-form" method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id'] . '#electrician-communication'); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="send_customer_message">
                            <div class="form-grid two compact">
                                <div>
                                    <label for="electrician_message_subject_<?= (int) $request['id']; ?>">Tárgy</label>
                                    <input id="electrician_message_subject_<?= (int) $request['id']; ?>" name="message_subject" value="<?= h(APP_NAME . ' üzenet - ' . (string) $request['project_name']); ?>">
                                </div>
                                <div>
                                    <label for="electrician_customer_recipient_email_<?= (int) $request['id']; ?>">Ügyfél email címzett</label>
                                    <input id="electrician_customer_recipient_email_<?= (int) $request['id']; ?>" name="customer_recipient_email" type="email" inputmode="email" autocomplete="email" value="<?= h($displayCustomerEmail); ?>" required>
                                </div>
                            </div>
                            <label for="electrician_customer_recipient_name_<?= (int) $request['id']; ?>">Ügyfél címzett neve</label>
                            <input id="electrician_customer_recipient_name_<?= (int) $request['id']; ?>" name="customer_recipient_name" value="<?= h($displayCustomerName); ?>">
                            <label for="electrician_message_body_<?= (int) $request['id']; ?>">Üzenet</label>
                            <textarea id="electrician_message_body_<?= (int) $request['id']; ?>" name="message_body" rows="4" required></textarea>
                            <div class="form-actions">
                                <button class="button" type="submit">Üzenet küldése az ügyfélnek</button>
                            </div>
                        </form>
                        <div class="portal-mail-auto-sync-note">
                            <strong>Válaszok automatikusan</strong>
                            <span>Az ügyfél válasza a <?= h(mvm_mail_reply_address()); ?> postafiókra érkezik, és az emailben lévő azonosító alapján erre az adatlapra kerül.</span>
                        </div>
                        <form class="inline-form portal-mail-sync-form" method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id'] . '#electrician-communication'); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="sync_customer_mailbox">
                            <button class="button button-secondary" type="submit">Válaszok frissítése most</button>
                        </form>
                        <?php if (!mvm_mailbox_sync_can_run()): ?>
                            <div class="alert alert-info"><p><?= h(mvm_mailbox_sync_setup_message()); ?></p></div>
                        <?php endif; ?>
                        <?php if ($customerCommunicationThreads === []): ?>
                            <p class="request-admin-empty">Ehhez az adatlaphoz még nincs ügyfélkommunikáció.</p>
                        <?php else: ?>
                            <div class="mvm-mail-thread-list mvm-mail-thread-list-compact">
                                <?php foreach ($customerCommunicationThreads as $thread): ?>
                                    <?php $messages = is_array($thread['messages'] ?? null) ? $thread['messages'] : []; ?>
                                    <article class="mvm-mail-thread">
                                        <div class="mvm-mail-thread-head">
                                            <div>
                                                <span class="portal-kicker"><?= h((string) $thread['token']); ?></span>
                                                <strong><?= h((string) $thread['document_label']); ?></strong>
                                                <p><?= h((string) $thread['subject']); ?></p>
                                            </div>
                                            <span class="status-badge status-badge-<?= h((string) $thread['status']); ?>"><?= h($mvmThreadStatusLabels[$thread['status']] ?? (string) $thread['status']); ?></span>
                                        </div>
                                        <?php if ($messages === []): ?>
                                            <p><?= h(latest_mvm_email_message_preview($thread)); ?></p>
                                        <?php else: ?>
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

            <?php foreach (['before' => 'Kivitelezés előtti kötelező fotók', 'after' => 'Kivitelezés utáni kötelező fotók'] as $stage => $stageDefaultTitle): ?>
                <?php
                $stageFiles = $stage === 'before' ? $beforeFiles : $afterFiles;
                $stageLocked = $stage === 'after' && empty($request['before_photos_completed_at']);
                $stageModuleKey = $stage === 'before' ? 'work_photos_before' : 'work_photos_after';
                $stageTitle = ui_module_text('electrician_work_detail', $stageModuleKey, 'title', $stageDefaultTitle);
                $stageSubtitle = ui_module_text('electrician_work_detail', $stageModuleKey, 'subtitle', count($stageFiles) . ' db');
                $stageDescriptionDefault = $stage === 'after' ? 'Az elkészült beavatkozási lap fotója is kötelező.' : 'Ezeket a képeket a munka megkezdése előtt kell feltölteni.';
                $stageDescription = ui_module_text('electrician_work_detail', $stageModuleKey, 'body', $stageDescriptionDefault);
                ?>
                <section <?= ui_module_attrs('electrician_work_detail', $stageModuleKey, 'admin-request-panel admin-request-documents electrician-work-stage-panel'); ?>>
                    <div class="admin-request-section-title">
                        <h3><?= h($stageTitle); ?></h3>
                        <span><?= h($stageSubtitle); ?></span>
                    </div>
                    <p class="muted-text"><?= h($stageDescription); ?></p>
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
                                        <span>Feltöltő: <?= h(portal_file_uploader_label($file)); ?></span>
                                        <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                        <form method="post" action="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">
                                            <?= csrf_field(); ?>
                                            <button class="table-action-button table-action-danger" name="delete_work_file_id" value="<?= (int) $file['id']; ?>" type="submit" onclick="return confirm('Biztosan törlöd ezt a munkafotót?');">Törlés</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button
                            class="button"
                            type="button"
                            data-work-dialog-open="<?= h($stage); ?>"
                            <?= $stageLocked ? 'title="Előbb az induló fotókat kell lezárni."' : ''; ?>
                        ><?= $stage === 'before' ? 'Induló fotók feltöltése' : 'Kész munka fotóinak feltöltése'; ?></button>
                    </div>
                </section>
            <?php endforeach; ?>
                    <?php foreach (ui_modules_for_area('electrician_work_detail') as $customModule): ?>
                        <?php if (empty($customModule['is_custom'])) {
                            continue;
                        } ?>
                        <section <?= ui_module_attrs('electrician_work_detail', (string) $customModule['module_key'], 'admin-request-panel admin-request-documents ui-custom-module-card'); ?>>
                            <div class="admin-request-section-title">
                                <h3><?= h((string) $customModule['title']); ?></h3>
                                <?php if (trim((string) $customModule['subtitle']) !== ''): ?><span><?= h((string) $customModule['subtitle']); ?></span><?php endif; ?>
                            </div>
                            <?php if (trim((string) $customModule['body']) !== ''): ?>
                                <p class="muted-text"><?= nl2br(h((string) $customModule['body'])); ?></p>
                            <?php endif; ?>
                            <?php if (trim((string) $customModule['href']) !== ''): ?>
                                <div class="form-actions">
                                    <a class="button button-secondary" href="<?= h(ui_module_public_url((string) $customModule['href'])); ?>">Megnyitás</a>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endif; ?>
    </div>
</section>
<script>
(() => {
    const moduleTitleFallbacks = {
        initial_data: 'Adatlap alapadatok',
        payment_summary: 'Kivitelezéskor beszedendő összeg',
        workflow: 'Munkafolyamat',
        request_data: 'Adatlap adatai',
        files: 'Fájlok és dokumentumok',
        customer_communication: 'Ügyfélkommunikáció',
        work_photos_before: 'Kivitelezés előtti fotók',
        work_photos_after: 'Kivitelezés utáni fotók',
    };
    const moduleSelectors = [
        '.electrician-module-stack > .ui-configurable-module:not(details)',
        '.electrician-module-stack > .request-admin-footer > .admin-request-panel',
    ];
    const modules = Array.from(new Set(moduleSelectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)))));

    const moduleStorageKey = (module, index) => {
        const key = module.dataset.uiModule || module.id || `module-${index}`;
        return `electrician-module-collapsed:${key}`;
    };
    const getStoredCollapseState = (key) => {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    };
    const setStoredCollapseState = (key, collapsed) => {
        try {
            window.localStorage.setItem(key, collapsed ? '1' : '0');
        } catch (error) {
            // Local storage can be blocked by browser settings; toggling still works for this page view.
        }
    };

    const findModuleTitle = (module) => {
        const title = module.querySelector(':scope > .admin-request-section-title h3, :scope > .admin-header.compact h3, :scope > .admin-header.compact h2');

        if (title && title.textContent.trim() !== '') {
            return title.textContent.trim();
        }

        const moduleKey = module.dataset.uiModule || '';

        return moduleTitleFallbacks[moduleKey] || 'Modul';
    };

    const ensureModuleHeader = (module) => {
        const existingHeader = module.querySelector(':scope > .admin-request-section-title, :scope > .admin-header.compact');

        if (existingHeader) {
            existingHeader.classList.add('electrician-module-title');
            return existingHeader;
        }

        const header = document.createElement('div');
        header.className = 'admin-request-section-title electrician-module-title electrician-generated-module-title';

        const title = document.createElement('h3');
        title.textContent = findModuleTitle(module);
        header.append(title);
        module.prepend(header);

        return header;
    };

    const setCollapsed = (module, button, collapsed) => {
        module.classList.toggle('is-collapsed', collapsed);
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    };

    modules.forEach((module, index) => {
        if (module.dataset.electricianCollapsibleReady === '1') {
            return;
        }

        const header = ensureModuleHeader(module);
        const button = document.createElement('button');
        button.className = 'electrician-module-toggle';
        button.type = 'button';
        button.innerHTML = '<span class="electrician-module-toggle-open">Összecsukás</span><span class="electrician-module-toggle-closed">Megnyitás</span>';
        header.append(button);

        const storageKey = moduleStorageKey(module, index);
        const shouldStartCollapsed = getStoredCollapseState(storageKey) === '1';
        module.classList.add('electrician-collapsible-module');
        module.dataset.electricianCollapsibleReady = '1';
        setCollapsed(module, button, shouldStartCollapsed);

        button.addEventListener('click', () => {
            const collapsed = !module.classList.contains('is-collapsed');
            setCollapsed(module, button, collapsed);
            setStoredCollapseState(storageKey, collapsed);
        });
    });
})();

(() => {
    const select = document.querySelector('[data-request-type-select]');
    const tariffInputs = document.querySelectorAll('[data-h-tariff-required]');

    if (!select) {
        return;
    }

    const syncHTariffFields = () => {
        const isHTariff = select.value === 'h_tariff';

        tariffInputs.forEach((input) => {
            input.required = isHTariff && input.dataset.hasExisting !== '1';
        });
    };

    select.addEventListener('change', syncHTariffFields);
    syncHTariffFields();
})();

(() => {
    const dialogs = new Map();

    document.querySelectorAll('[data-work-dialog]').forEach((dialog) => {
        dialogs.set(dialog.dataset.workDialog, dialog);

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                if (typeof dialog.close === 'function') {
                    dialog.close();
                } else {
                    dialog.removeAttribute('open');
                }
            }
        });
    });

    const openWorkDialog = (stage) => {
        const dialog = dialogs.get(stage);

        if (!dialog) {
            return;
        }

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
        }
    };

    document.querySelectorAll('[data-work-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }

            openWorkDialog(button.dataset.workDialogOpen);
        });
    });

    document.querySelectorAll('[data-work-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = button.closest('[data-work-dialog]');

            if (!dialog) {
                return;
            }

            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        });
    });

    const autoStage = new URLSearchParams(window.location.search).get('work_stage');

    if (autoStage === 'before' || autoStage === 'after') {
        window.setTimeout(() => openWorkDialog(autoStage), 120);
    }
})();
</script>
