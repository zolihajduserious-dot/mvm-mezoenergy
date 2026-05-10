<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$schemaErrors = minicrm_import_schema_errors();
$electricianSchemaErrors = electrician_schema_errors();
$deps = dependency_status();
$flash = get_flash();
$importErrors = [];
$showArchived = (string) ($_GET['show_archived'] ?? '') === '1';

if (is_post() && in_array(($_POST['action'] ?? ''), ['import_minicrm_file', 'import_minicrm_files'], true)) {
    require_valid_csrf_token();

    $result = minicrm_import_uploads($_FILES);

    if ($result['ok'] ?? false) {
        set_flash('success', (string) $result['message']);
    } else {
        set_flash('error', (string) ($result['message'] ?? 'A MiniCRM import sikertelen.'));
    }

    redirect('/admin/minicrm-import');
}

if (is_post() && ($_POST['action'] ?? '') === 'import_minicrm_document_zip') {
    require_valid_csrf_token();

    $result = minicrm_import_document_zips($_FILES);

    if ($result['ok'] ?? false) {
        set_flash('success', (string) $result['message']);
    } else {
        set_flash('error', (string) ($result['message'] ?? 'A MiniCRM dokumentum ZIP feldolgozása sikertelen.'));
    }

    redirect('/admin/minicrm-import');
}

if (is_post() && ($_POST['action'] ?? '') === 'import_minicrm_customer_profiles') {
    require_valid_csrf_token();

    $result = minicrm_customer_profile_uploads($_FILES);
    $redirectPath = '/admin/minicrm-import';
    $redirectItemId = isset($_GET['item']) ? max(0, (int) $_GET['item']) : 0;

    if ($redirectItemId > 0) {
        $redirectPath .= '?item=' . $redirectItemId . '#minicrm-work-' . $redirectItemId;
    }

    if ($result['ok'] ?? false) {
        set_flash('success', (string) $result['message']);
    } else {
        set_flash('error', (string) ($result['message'] ?? 'A MiniCRM ugyfeladat import sikertelen.'));
    }

    redirect($redirectPath);
}

if (is_post() && ($_POST['action'] ?? '') === 'upload_minicrm_work_files') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $result = store_minicrm_work_item_files(
        $workItemId,
        uploaded_files_for_key($_FILES, 'minicrm_work_files'),
        (string) ($_POST['file_label'] ?? 'Kézi feltöltés')
    );

    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A MiniCRM fájl feltöltése sikertelen.'));
    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_minicrm_work_file') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $fileId = max(0, (int) ($_POST['file_id'] ?? 0));
    $result = $workItemId > 0 && $fileId > 0
        ? delete_minicrm_work_item_file($fileId, $workItemId)
        : ['ok' => false, 'message' => 'Hiányzó MiniCRM munka vagy fájl azonosító.'];

    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A MiniCRM fájl törlése sikertelen.'));
    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_portal_work_file') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $fileId = max(0, (int) ($_POST['file_id'] ?? 0));
    $fileSource = (string) ($_POST['file_source'] ?? '');

    if ($requestId <= 0 || $fileId <= 0) {
        set_flash('error', 'Hiányzó munka vagy fájl azonosító.');
        redirect('/admin/minicrm-import#portal-works');
    }

    if ($fileSource === 'request_file') {
        $result = delete_connection_request_file($fileId, $requestId);
    } elseif ($fileSource === 'work_file') {
        $result = delete_connection_request_work_file($fileId, $requestId);
    } elseif ($fileSource === 'mvm_document') {
        $result = delete_connection_request_document($fileId, $requestId);
    } else {
        $result = ['ok' => false, 'message' => 'Ismeretlen fájltípus, a törlés nem futott le.'];
    }

    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A fájl törlése sikertelen.'));
    redirect('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
}

if (is_post() && ($_POST['action'] ?? '') === 'upload_portal_work_files') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $request = $requestId > 0 ? find_connection_request($requestId) : null;

    if ($request === null) {
        set_flash('error', 'A munka nem talalhato, a fajlokat nem lehet feltolteni.');
        redirect('/admin/minicrm-import#portal-works');
    }

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
        set_flash('error', 'Valassz legalabb egy feltoltendo fotot vagy dokumentumot.');
        redirect('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
    }

    $uploadErrors = validate_connection_request_data(normalize_connection_request_data($request), $_FILES, false, $requestId);

    if ($uploadErrors !== []) {
        set_flash('error', implode(' ', $uploadErrors));
        redirect('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
    }

    $uploadMessages = handle_connection_request_uploads($requestId, $_FILES, false);

    set_flash(
        $uploadMessages === [] ? 'success' : 'error',
        $uploadMessages === [] ? 'A fotok es dokumentumok mentve lettek.' : 'Nehany fajl nem lett mentve: ' . implode(' ', $uploadMessages)
    );
    redirect('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_portal_work_request') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $redirectItemId = max(0, (int) ($_POST['redirect_item_id'] ?? 0));
    $confirmation = trim((string) ($_POST['delete_confirmation'] ?? ''));
    $returnToArchived = !empty($_POST['return_to_archived']);
    $returnBase = '/admin/minicrm-import' . ($returnToArchived ? '?show_archived=1' : '');

    if (!can_view_super_admin_overview()) {
        set_flash('error', 'Adatlapot törölni csak szuperadmin jogosultsággal lehet.');
        redirect('/admin/minicrm-import');
    }

    if ($requestId <= 0) {
        set_flash('error', 'Hiányzó adatlap azonosító.');
        redirect($returnBase . '#portal-works');
    }

    if ($confirmation !== 'TORLES') {
        set_flash('error', 'A törléshez írd be pontosan: TORLES.');
        redirect($redirectItemId > 0 ? $returnBase . ($returnToArchived ? '&' : '?') . 'item=' . $redirectItemId . '#minicrm-work-' . $redirectItemId : $returnBase . ($returnToArchived ? '&' : '?') . 'request=' . $requestId . '#portal-work-' . $requestId);
    }

    try {
        $deleteSummary = delete_connection_request_with_related_data($requestId);
        set_flash(
            'success',
            'Az adatlap törölve: #' . (int) $deleteSummary['request_id'] . ' - ' . (string) $deleteSummary['request_title']
                . '. Kapcsolódó adatok: ' . (int) $deleteSummary['quotes'] . ' árajánlat, '
                . (int) $deleteSummary['surveys'] . ' felmérés, '
                . (int) $deleteSummary['files'] . ' fájl.'
        );
    } catch (Throwable $exception) {
        set_flash('error', APP_DEBUG ? $exception->getMessage() : 'Az adatlap törlése sikertelen.');
        redirect($redirectItemId > 0 ? $returnBase . ($returnToArchived ? '&' : '?') . 'item=' . $redirectItemId . '#minicrm-work-' . $redirectItemId : $returnBase . ($returnToArchived ? '&' : '?') . 'request=' . $requestId . '#portal-work-' . $requestId);
    }

    redirect($redirectItemId > 0 ? $returnBase . '#minicrm-works' : $returnBase . '#portal-works');
}

if (is_post() && ($_POST['action'] ?? '') === 'archive_portal_work_request') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $archiveState = (string) ($_POST['archive_state'] ?? 'archive');
    $archive = $archiveState !== 'restore';

    if ($requestId <= 0) {
        set_flash('error', 'Hiányzó adatlap azonosító.');
        redirect('/admin/minicrm-import#portal-works');
    }

    $result = set_connection_request_archived($requestId, $archive);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az archiválás sikertelen.'));

    if ($archive) {
        redirect('/admin/minicrm-import#portal-works');
    }

    redirect('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
}

if (is_post() && ($_POST['action'] ?? '') === 'archive_minicrm_work_item') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $archiveState = (string) ($_POST['archive_state'] ?? 'archive');
    $archive = $archiveState !== 'restore';

    if ($workItemId <= 0) {
        set_flash('error', 'Hiányzó MiniCRM adatlap azonosító.');
        redirect('/admin/minicrm-import#minicrm-works');
    }

    $result = set_minicrm_work_item_archived($workItemId, $archive);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az archiválás sikertelen.'));

    if ($archive) {
        redirect('/admin/minicrm-import#minicrm-works');
    }

    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_minicrm_work_item') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $confirmation = trim((string) ($_POST['delete_confirmation'] ?? ''));
    $returnToArchived = !empty($_POST['return_to_archived']);

    if (!can_view_super_admin_overview()) {
        set_flash('error', 'MiniCRM adatlapot törölni csak szuperadmin jogosultsággal lehet.');
        redirect('/admin/minicrm-import');
    }

    if ($workItemId <= 0) {
        set_flash('error', 'Hiányzó MiniCRM adatlap azonosító.');
        redirect('/admin/minicrm-import#minicrm-works');
    }

    if ($confirmation !== 'TORLES') {
        set_flash('error', 'A törléshez írd be pontosan: TORLES.');
        redirect('/admin/minicrm-import' . ($returnToArchived ? '?show_archived=1' : '') . '#minicrm-work-' . $workItemId);
    }

    try {
        $deleteSummary = delete_minicrm_work_item_with_related_data($workItemId);
        set_flash(
            'success',
            'A MiniCRM adatlap törölve: #' . (int) $deleteSummary['work_item_id'] . ' - ' . (string) $deleteSummary['card_name']
                . '. Törölt fájlok: ' . (int) $deleteSummary['files'] . '.'
                . (!empty($deleteSummary['linked_request_deleted']) ? ' A kapcsolt MVM adatlap is törölve lett.' : '')
        );
    } catch (Throwable $exception) {
        set_flash('error', APP_DEBUG ? $exception->getMessage() : 'A MiniCRM adatlap törlése sikertelen.');
        redirect('/admin/minicrm-import' . ($returnToArchived ? '?show_archived=1' : '') . '#minicrm-work-' . $workItemId);
    }

    redirect('/admin/minicrm-import' . ($returnToArchived ? '?show_archived=1' : '') . '#minicrm-works');
}

if (is_post() && ($_POST['action'] ?? '') === 'assign_portal_work_electrician') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $electricianUserIdRaw = trim((string) ($_POST['electrician_user_id'] ?? ''));
    $electricianUserId = $electricianUserIdRaw !== '' ? (int) $electricianUserIdRaw : null;
    $requestToAssign = $requestId > 0 ? find_connection_request($requestId) : null;

    if ($electricianSchemaErrors !== []) {
        set_flash('error', 'A szerelői kiosztáshoz előbb futtasd le a database/electrician_workflow.sql fájlt.');
    } elseif ($requestToAssign === null) {
        set_flash('error', 'A munka nem található.');
    } elseif ($electricianUserId !== null && find_electrician_by_user($electricianUserId) === null) {
        set_flash('error', 'A kiválasztott szerelő nem található.');
    } else {
        assign_connection_request_to_electrician($requestId, $electricianUserId);
        $message = $electricianUserId === null ? 'A munka visszakerült kiosztatlan állapotba.' : 'A munka ki lett adva a szerelőnek.';

        if ($electricianUserId !== null) {
            $notification = send_electrician_assignment_email($requestId, $electricianUserId);
            $message .= ' ' . $notification['message'];
        }

        set_flash('success', $message);
    }

    redirect('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
}

if (is_post() && ($_POST['action'] ?? '') === 'close_portal_workflow_stage') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $targetStageRaw = trim((string) ($_POST['target_stage'] ?? ''));
    $targetStage = $targetStageRaw === '__auto__' ? null : $targetStageRaw;
    $notifyCustomer = !empty($_POST['notify_customer']);
    $notifyResponsible = !empty($_POST['notify_responsible']);

    if ($requestId <= 0) {
        set_flash('error', 'Hiányzó munka azonosító.');
        redirect('/admin/minicrm-import#portal-works');
    }

    if ($targetStageRaw === '') {
        $request = find_connection_request($requestId);
        $documents = connection_request_documents($requestId);
        $latestQuote = latest_quote_for_connection_request($requestId);
        $acceptedQuote = accepted_quote_for_connection_request($requestId);
        $currentStage = is_array($request) ? connection_request_admin_workflow_stage($request, $latestQuote, $acceptedQuote, $documents) : '';
        $targetStage = $currentStage !== '' ? next_admin_workflow_stage($currentStage) : null;
    }

    $result = set_connection_request_workflow_stage($requestId, $targetStage, $notifyCustomer, $notifyResponsible);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A munkafolyamat lezárása sikertelen.'));
    redirect('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
}

if (is_post() && ($_POST['action'] ?? '') === 'update_portal_work_customer_email') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $redirectItemId = max(0, (int) ($_POST['redirect_item_id'] ?? 0));
    $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));

    if ($requestId <= 0) {
        set_flash('error', 'Hiányzó munka azonosító.');
        redirect('/admin/minicrm-import#portal-works');
    }

    $result = update_connection_request_customer_email($requestId, $customerEmail);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az ügyfél email címének mentése sikertelen.'));
    redirect($redirectItemId > 0 ? '/admin/minicrm-import?item=' . $redirectItemId . '#minicrm-work-' . $redirectItemId : '/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
}

if (is_post() && ($_POST['action'] ?? '') === 'update_portal_work_details') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));

    if ($requestId <= 0) {
        set_flash('error', 'Hiányzó munka azonosító.');
        redirect('/admin/minicrm-import#portal-works');
    }

    $result = update_connection_request_portal_details($requestId, $_POST);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az adatlap adatainak mentése sikertelen.'));
    redirect('/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
}

if (is_post() && ($_POST['action'] ?? '') === 'send_portal_work_message') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $recipient = (string) ($_POST['message_recipient'] ?? '');
    $subject = trim((string) ($_POST['message_subject'] ?? ''));
    $body = trim((string) ($_POST['message_body'] ?? ''));
    $customerEmail = trim((string) ($_POST['customer_recipient_email'] ?? ''));
    $customerName = trim((string) ($_POST['customer_recipient_name'] ?? ''));
    $redirectItemId = max(0, (int) ($_POST['redirect_item_id'] ?? 0));

    if ($requestId <= 0) {
        set_flash('error', 'Hiányzó munka azonosító.');
        redirect('/admin/minicrm-import#portal-works');
    }

    $result = send_connection_request_manual_message($requestId, $recipient, $subject, $body, $customerEmail, $customerName);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az üzenet küldése sikertelen.'));
    redirect($redirectItemId > 0 ? '/admin/minicrm-import?item=' . $redirectItemId . '#minicrm-work-' . $redirectItemId : '/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
}

if (is_post() && ($_POST['action'] ?? '') === 'sync_portal_work_mailbox') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $redirectItemId = max(0, (int) ($_POST['redirect_item_id'] ?? 0));
    $result = sync_mvm_mailbox_replies();
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A központi postafiók szinkronizálása sikertelen.'));
    redirect($redirectItemId > 0 ? '/admin/minicrm-import?item=' . $redirectItemId . '#minicrm-work-' . $redirectItemId : ($requestId > 0 ? '/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId : '/admin/minicrm-import#portal-works'));
}

if (is_post() && ($_POST['action'] ?? '') === 'update_minicrm_work_item') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $result = update_minicrm_work_item_fields(
        $workItemId,
        is_array($_POST['minicrm_fields'] ?? null) ? $_POST['minicrm_fields'] : []
    );

    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A MiniCRM mezők mentése sikertelen.'));
    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'save_minicrm_work_mvm_uk_number') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $result = $workItemId > 0
        ? update_minicrm_work_item_mvm_uk_number($workItemId, (string) ($_POST['mvm_uk_number'] ?? ''))
        : ['ok' => false, 'message' => 'Hiányzó MiniCRM adatlap azonosító.'];

    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az ÜK szám mentése sikertelen.'));
    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'save_minicrm_work_note') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $result = $workItemId > 0
        ? update_minicrm_work_item_work_note($workItemId, (string) ($_POST['work_note'] ?? ''))
        : ['ok' => false, 'message' => 'Hiányzó MiniCRM adatlap azonosító.'];

    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A munka megjegyzés mentése sikertelen.'));
    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'save_portal_work_note') {
    require_valid_csrf_token();

    $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
    $redirectItemId = max(0, (int) ($_POST['redirect_item_id'] ?? 0));
    $request = $requestId > 0 ? find_connection_request($requestId) : null;

    if ($request === null) {
        $result = ['ok' => false, 'message' => 'Az adatlap nem található.'];
    } else {
        $result = update_connection_request_work_note($requestId, (string) ($_POST['work_note'] ?? ''));
    }

    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A munka megjegyzés mentése sikertelen.'));
    redirect($redirectItemId > 0 ? '/admin/minicrm-import?item=' . $redirectItemId . '#minicrm-work-' . $redirectItemId : '/admin/minicrm-import?request=' . $requestId . '#portal-work-' . $requestId);
}

if (is_post() && ($_POST['action'] ?? '') === 'fix_szabo_dezso_5_apartment_power') {
    require_valid_csrf_token();

    if (!can_view_super_admin_overview()) {
        set_flash('error', 'Ezt a csoportos adatjavítást csak szuperadmin indíthatja.');
        redirect('/admin/minicrm-import#minicrm-status-szab-dezs-5');
    }

    $result = apply_minicrm_szabo_dezso_5_apartment_power_fix();
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A Szabó Dezső 5 teljesítményjavítás sikertelen.'));
    redirect('/admin/minicrm-import#minicrm-status-szab-dezs-5');
}

if (is_post() && ($_POST['action'] ?? '') === 'assign_minicrm_electricians') {
    require_valid_csrf_token();

    $result = minicrm_assign_imported_work_items_to_electricians();
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A MiniCRM munkak szereloi kiosztasa sikertelen.'));
    redirect('/admin/minicrm-import');
}

if (is_post() && ($_POST['action'] ?? '') === 'assign_minicrm_work_electrician') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $electricianUserIdRaw = trim((string) ($_POST['electrician_user_id'] ?? ''));
    $electricianUserId = $electricianUserIdRaw !== '' ? (int) $electricianUserIdRaw : null;

    if ($workItemId <= 0) {
        set_flash('error', 'Hiányzó MiniCRM munka azonosító.');
        redirect('/admin/minicrm-import');
    }

    if ($electricianSchemaErrors !== []) {
        set_flash('error', 'A szerelői kiosztáshoz előbb futtasd le a database/electrician_workflow.sql fájlt.');
        redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
    }

    if ($electricianUserId !== null && find_electrician_by_user($electricianUserId) === null) {
        set_flash('error', 'A kiválasztott szerelő nem található.');
        redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
    }

    $linkResult = ensure_minicrm_work_item_connection_request($workItemId);

    if (!($linkResult['ok'] ?? false)) {
        set_flash('error', (string) ($linkResult['message'] ?? 'A MiniCRM munka normál munkához kapcsolása sikertelen.'));
        redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
    }

    $requestId = (int) ($linkResult['request_id'] ?? 0);

    if ($requestId <= 0) {
        set_flash('error', 'A MiniCRM munka normál munka azonosítója hiányzik.');
        redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
    }

    if ($electricianUserId === null) {
        assign_connection_request_to_electrician($requestId, null);
        set_flash('success', 'A MiniCRM munka visszakerült kiosztatlan állapotba.');
    } else {
        minicrm_set_request_electrician_assignment($requestId, $electricianUserId);
        $notification = send_electrician_assignment_email($requestId, $electricianUserId);
        set_flash('success', 'A MiniCRM munka ki lett adva a szerelőnek. ' . $notification['message']);
    }

    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'close_minicrm_workflow_stage') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $targetStageRaw = trim((string) ($_POST['target_stage'] ?? ''));
    $targetStage = $targetStageRaw === '__auto__' ? null : $targetStageRaw;
    $notifyCustomer = !empty($_POST['notify_customer']);
    $notifyResponsible = !empty($_POST['notify_responsible']);

    if ($workItemId <= 0) {
        set_flash('error', 'Hiányzó MiniCRM munka azonosító.');
        redirect('/admin/minicrm-import');
    }

    $linkResult = ensure_minicrm_work_item_connection_request($workItemId);

    if (!($linkResult['ok'] ?? false)) {
        set_flash('error', (string) ($linkResult['message'] ?? 'A MiniCRM munka normál munkához kapcsolása sikertelen.'));
        redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
    }

    $requestId = (int) ($linkResult['request_id'] ?? 0);

    if ($requestId <= 0) {
        set_flash('error', 'A MiniCRM munka normál munka azonosítója hiányzik.');
        redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
    }

    if ($targetStageRaw === '') {
        $request = find_connection_request($requestId);
        $documents = connection_request_documents($requestId);
        $latestQuote = latest_quote_for_connection_request($requestId);
        $acceptedQuote = accepted_quote_for_connection_request($requestId);
        $currentStage = is_array($request) ? connection_request_admin_workflow_stage($request, $latestQuote, $acceptedQuote, $documents) : '';
        $targetStage = $currentStage !== '' ? next_admin_workflow_stage($currentStage) : null;
    }

    $result = set_connection_request_workflow_stage($requestId, $targetStage, $notifyCustomer, $notifyResponsible);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A munkafolyamat lezárása sikertelen.'));
    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'send_minicrm_quote_fee_request') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $quoteId = max(0, (int) ($_POST['quote_id'] ?? 0));
    $feeNote = trim((string) ($_POST['fee_note'] ?? ''));
    $requestId = $workItemId > 0 ? minicrm_work_item_connection_request_id($workItemId) : null;

    if ($workItemId <= 0 || $quoteId <= 0) {
        set_flash('error', 'Hiányzó MiniCRM munka vagy árajánlat azonosító.');
        redirect('/admin/minicrm-import');
    }

    if ($requestId === null) {
        $linkResult = ensure_minicrm_work_item_connection_request($workItemId);

        if (!($linkResult['ok'] ?? false)) {
            set_flash('error', (string) ($linkResult['message'] ?? 'A MiniCRM munka normál igényhez kapcsolása sikertelen.'));
            redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
        }

        $requestId = (int) ($linkResult['request_id'] ?? 0);
    }

    $quoteIds = array_map(
        static fn (array $quote): int => (int) $quote['id'],
        $requestId > 0 ? quotes_for_connection_request($requestId) : []
    );

    if (!in_array($quoteId, $quoteIds, true)) {
        set_flash('error', 'Ez az árajánlat nem ehhez a MiniCRM munkához tartozik.');
        redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
    }

    $result = send_quote_fee_request_email($quoteId, $feeNote);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A díjbekérő küldése sikertelen.'));
    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'send_minicrm_service_fee_request') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $feeType = (string) ($_POST['fee_type'] ?? '');
    $feeNote = trim((string) ($_POST['fee_note'] ?? ''));

    if (!is_admin_user()) {
        set_flash('error', 'Ezt a díjbekérőt csak admin indíthatja.');
        redirect($workItemId > 0 ? '/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId : '/admin/minicrm-import');
    }

    if ($workItemId <= 0 || service_fee_request_option($feeType) === null) {
        set_flash('error', 'Hiányzó MiniCRM munka vagy ügykezelési díj típus.');
        redirect('/admin/minicrm-import');
    }

    $linkResult = ensure_minicrm_work_item_connection_request($workItemId);

    if (!($linkResult['ok'] ?? false)) {
        set_flash('error', (string) ($linkResult['message'] ?? 'A MiniCRM munka normál igényhez kapcsolása sikertelen.'));
        redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
    }

    $requestId = (int) ($linkResult['request_id'] ?? 0);

    if ($requestId <= 0) {
        set_flash('error', 'A MiniCRM munka normál munka azonosítója hiányzik.');
        redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
    }

    $result = send_connection_request_service_fee_request($requestId, $feeType, $feeNote);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az ügykezelési díjbekérő küldése sikertelen.'));
    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'install_minicrm_schema') {
    require_valid_csrf_token();

    $result = minicrm_import_install_schema();
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) $result['message']);
    redirect('/admin/minicrm-import');
}

$items = $schemaErrors === [] ? minicrm_work_items(1000, $showArchived) : [];
$standaloneRequests = $schemaErrors === [] ? admin_standalone_connection_request_items(1000, $showArchived) : [];
$electricians = $electricianSchemaErrors === [] ? electrician_users(true) : [];
$batches = $schemaErrors === [] ? minicrm_import_batches(8) : [];
$statusCounts = $schemaErrors === [] ? minicrm_work_item_status_counts($showArchived) : [];
$customerProfilesBySource = $schemaErrors === [] ? minicrm_customer_profiles_by_source_ids(array_column($items, 'source_id')) : [];
$customerProfilesByRequest = $schemaErrors === [] ? minicrm_customer_profiles_by_connection_request_ids(array_column($standaloneRequests, 'id')) : [];
$quoteStatusLabels = $schemaErrors === [] ? quote_status_labels() : [];
$requestStatusLabels = $schemaErrors === [] ? connection_request_status_labels() : [];
$electricianStatusLabels = $schemaErrors === [] ? electrician_work_status_labels() : [];
$mvmThreadStatusLabels = mvm_email_thread_status_labels();
$workflowStages = admin_workflow_stage_definitions();
$totalItems = count($items);
$totalUnifiedItems = $totalItems + count($standaloneRequests);
$activeUnifiedItems = $schemaErrors === [] ? minicrm_work_item_count(false) + admin_standalone_connection_request_count(false) : 0;
$archivedUnifiedItems = $schemaErrors === [] ? minicrm_work_item_count(true) + admin_standalone_connection_request_count(true) : 0;
$localDocumentFileCount = $schemaErrors === [] ? minicrm_work_item_file_count($showArchived) : 0;
$localDocumentSizeTotal = $schemaErrors === [] ? minicrm_work_item_file_size_total($showArchived) : 0;
$documentZipCandidates = minicrm_document_zip_candidates();
$itemsByStatus = [];
$selectedItemId = isset($_GET['item']) ? max(0, (int) $_GET['item']) : 0;
$selectedRequestId = isset($_GET['request']) ? max(0, (int) $_GET['request']) : 0;
$standaloneRequestsByWorkflowStage = [];
$standaloneRequestWorkflowStages = minicrm_import_request_list_workflow_stages($standaloneRequests);

foreach ($items as $item) {
    if ($selectedItemId === 0 && $selectedRequestId === 0) {
        $selectedItemId = (int) ($item['id'] ?? 0);
    }

    $statusName = trim((string) ($item['minicrm_status'] ?? '')) ?: 'Nincs státusz';
    $itemsByStatus[$statusName][] = $item;
}

foreach ($itemsByStatus as &$statusItems) {
    usort(
        $statusItems,
        static fn (array $a, array $b): int => minicrm_import_compare_rows_chronologically(
            $a,
            $b,
            ['submitted_date', 'date_value', 'updated_at', 'created_at']
        )
    );
}
unset($statusItems);

uasort($itemsByStatus, static fn (array $a, array $b): int => count($b) <=> count($a));

foreach ($standaloneRequests as $request) {
    if ($selectedItemId === 0 && $selectedRequestId === 0) {
        $selectedRequestId = (int) ($request['id'] ?? 0);
    }

    $requestId = (int) ($request['id'] ?? 0);
    $workflowStage = $standaloneRequestWorkflowStages[$requestId] ?? minicrm_import_request_list_workflow_stage($request);
    $standaloneRequestsByWorkflowStage[$workflowStage][] = $request;
}

foreach ($standaloneRequestsByWorkflowStage as &$requestItems) {
    usort(
        $requestItems,
        static fn (array $a, array $b): int => minicrm_import_compare_rows_chronologically(
            $a,
            $b,
            ['created_at', 'submitted_at', 'updated_at']
        )
    );
}
unset($requestItems);

uksort($standaloneRequestsByWorkflowStage, static function (string $a, string $b): int {
    $comparison = admin_workflow_stage_number($a) <=> admin_workflow_stage_number($b);

    return $comparison !== 0 ? $comparison : strcmp($a, $b);
});

$portalMailboxAutoSync = ($selectedItemId > 0 || $selectedRequestId > 0)
    ? maybe_sync_mvm_mailbox_replies(40, 60)
    : ['ok' => true, 'message' => '', 'matched' => 0, 'ignored' => 0, 'skipped' => true];

function minicrm_import_dom_id(string $value): string
{
    $id = preg_replace('/[^a-z0-9]+/', '-', minicrm_import_lower($value)) ?: '';
    $id = trim($id, '-');

    return $id !== '' ? $id : 'nincs-statusz';
}

function minicrm_import_sort_timestamp(mixed $value): ?int
{
    $value = trim((string) $value);

    if ($value === '' || $value === '-') {
        return null;
    }

    $normalized = preg_replace('/\s+/', ' ', $value) ?: $value;
    $normalized = preg_replace('/^(\d{4})\.(\d{1,2})\.(\d{1,2})\.?/', '$1-$2-$3', $normalized) ?: $normalized;
    $timestamp = strtotime($normalized);

    return $timestamp === false ? null : $timestamp;
}

function minicrm_import_row_sort_timestamp(array $row, array $dateKeys): ?int
{
    foreach ($dateKeys as $dateKey) {
        $timestamp = minicrm_import_sort_timestamp($row[$dateKey] ?? null);

        if ($timestamp !== null) {
            return $timestamp;
        }
    }

    return null;
}

function minicrm_import_compare_rows_chronologically(array $a, array $b, array $dateKeys): int
{
    $aTimestamp = minicrm_import_row_sort_timestamp($a, $dateKeys);
    $bTimestamp = minicrm_import_row_sort_timestamp($b, $dateKeys);

    if ($aTimestamp === null && $bTimestamp === null) {
        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    }

    if ($aTimestamp === null) {
        return 1;
    }

    if ($bTimestamp === null) {
        return -1;
    }

    $dateComparison = $aTimestamp <=> $bTimestamp;

    return $dateComparison !== 0 ? $dateComparison : ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
}

function minicrm_import_connection_request_document_type_map(array $requestIds): array
{
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));

    if ($requestIds === [] || !db_table_exists('connection_request_documents')) {
        return [];
    }

    $rows = db_query(
        'SELECT `connection_request_id`, `document_type`
         FROM `connection_request_documents`
         WHERE `connection_request_id` IN (' . db_in_placeholders($requestIds) . ')
         GROUP BY `connection_request_id`, `document_type`',
        $requestIds
    )->fetchAll();
    $map = [];

    foreach ($rows as $row) {
        $requestId = (int) ($row['connection_request_id'] ?? 0);
        $documentType = trim((string) ($row['document_type'] ?? ''));

        if ($requestId > 0 && $documentType !== '') {
            $map[$requestId][$documentType] = true;
        }
    }

    return $map;
}

function minicrm_import_request_list_workflow_stages(array $requests): array
{
    $documentTypesByRequest = minicrm_import_connection_request_document_type_map(array_column($requests, 'id'));
    $stages = [];

    foreach ($requests as $request) {
        $requestId = (int) ($request['id'] ?? 0);

        if ($requestId > 0) {
            $stages[$requestId] = minicrm_import_request_list_workflow_stage($request, array_keys($documentTypesByRequest[$requestId] ?? []));
        }
    }

    return $stages;
}

function minicrm_import_request_list_workflow_stage(array $request, array $documentTypes = []): string
{
    $manualStage = normalize_admin_workflow_stage((string) ($request['admin_workflow_stage'] ?? ''));

    if ($manualStage !== null) {
        return $manualStage;
    }

    if ((string) ($request['electrician_status'] ?? '') === 'completed' || !empty($request['after_photos_completed_at'])) {
        return 'completed';
    }

    $documentTypeSet = array_fill_keys(array_map('strval', $documentTypes), true);
    $hasAnyDocumentType = static function (array $types) use ($documentTypeSet): bool {
        foreach ($types as $type) {
            if (isset($documentTypeSet[$type])) {
                return true;
            }
        }

        return false;
    };

    if ($hasAnyDocumentType(['intervention_sheet', 'completed_intervention_sheet'])) {
        return 'under_construction';
    }

    if ($hasAnyDocumentType(['execution_plan_package', 'execution_plan'])) {
        return 'waiting_intervention_sheet';
    }

    if ($hasAnyDocumentType(['accepted_request'])) {
        return 'waiting_plan';
    }

    if ($hasAnyDocumentType(['complete_package', 'submitted_request'])) {
        return 'in_progress';
    }

    return 'case_starting';
}

function minicrm_import_portal_group_dom_id(string $workflowStage, int $index): string
{
    return $index === 0 ? 'portal-works' : 'portal-workflow-' . minicrm_import_dom_id($workflowStage);
}

function minicrm_import_short_text(string $value, int $length = 150): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');
    $stringLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

    if ($value === '' || $stringLength <= $length) {
        return $value;
    }

    $substring = function_exists('mb_substr') ? mb_substr($value, 0, $length - 1) : substr($value, 0, $length - 1);

    return rtrim($substring) . '…';
}

function minicrm_import_first_matching_field(array $rawFields, array $patterns): string
{
    foreach ($rawFields as $field) {
        $label = (string) ($field['label'] ?? '');
        $value = trim((string) ($field['value'] ?? ''));

        if ($value === '' || $value === '-') {
            continue;
        }

        $key = minicrm_import_key($label);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return $value;
            }
        }
    }

    return '';
}

function minicrm_import_actual_document_links(array $documentLinks): array
{
    return array_values(array_filter($documentLinks, static function (mixed $link): bool {
        if (!is_array($link)) {
            return false;
        }

        $value = trim((string) ($link['value'] ?? ''));

        return $value !== '' && (str_starts_with($value, 'http://') || str_starts_with($value, 'https://'));
    }));
}

function minicrm_import_document_link_count(array $item): int
{
    $decoded = json_decode((string) ($item['document_links_json'] ?? '[]'), true);

    if (!is_array($decoded)) {
        return 0;
    }

    return count(minicrm_import_actual_document_links($decoded));
}

function minicrm_import_timeline_events(array $item, array $rawFields, array $localFiles): array
{
    $events = [];
    $responsible = trim((string) ($item['responsible'] ?? '')) ?: 'Mező Energy kft';
    $status = trim((string) ($item['minicrm_status'] ?? '')) ?: 'Nincs státusz';
    $updatedAt = trim((string) ($item['updated_at'] ?? '')) ?: trim((string) ($item['created_at'] ?? ''));

    $events[] = [
        'date' => $updatedAt !== '' ? $updatedAt : 'Aktuális állapot',
        'title' => 'Státusz',
        'actor' => $responsible,
        'body' => $status,
        'kind' => 'status',
    ];

    $dateEvents = 0;
    $noteEvents = 0;

    foreach ($rawFields as $field) {
        $label = trim((string) ($field['label'] ?? ''));
        $value = trim((string) ($field['value'] ?? ''));

        if ($label === '' || $value === '' || $value === '-' || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            continue;
        }

        $key = minicrm_import_key($label);

        if ($dateEvents < 8 && preg_match('/(datum|idopont|bekotes|kikuldes|keszrejelentes|ugyinditas|kivitelezes varhato|muszaki atadas|hatarido)/', $key)) {
            $events[] = [
                'date' => $value,
                'title' => $label,
                'actor' => 'MiniCRM adat',
                'body' => 'Rögzített időpont vagy határidő.',
                'kind' => 'date',
            ];
            $dateEvents++;
            continue;
        }

        if ($noteEvents < 5 && preg_match('/(megjegyzes|uzenet|szoveg|visszahivas|informacio|leiras)/', $key)) {
            $events[] = [
                'date' => 'MiniCRM előzmény',
                'title' => $label,
                'actor' => $responsible,
                'body' => minicrm_import_short_text($value, 320),
                'kind' => 'note',
            ];
            $noteEvents++;
        }
    }

    if ($localFiles !== []) {
        $firstFile = $localFiles[0];
        $events[] = [
            'date' => trim((string) ($firstFile['created_at'] ?? '')) ?: 'Dokumentum import',
            'title' => 'Saját tárhelyes dokumentumok',
            'actor' => 'MiniCRM dokumentumtár',
            'body' => count($localFiles) . ' saját tárhelyes fájl kapcsolódik ehhez a munkához.',
            'kind' => 'document',
        ];
    }

    return array_slice($events, 0, 14);
}

function minicrm_customer_profile_inline_import_form(int $itemId, array $schemaErrors, array $deps): void
{
    ?>
    <form class="form minicrm-inline-import-form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/admin/minicrm-import') . '?item=' . $itemId . '#minicrm-work-' . $itemId); ?>">
        <?= csrf_field(); ?>
        <input type="hidden" name="action" value="import_minicrm_customer_profiles">
        <label for="minicrm_customer_profile_inline_<?= $itemId; ?>">B&#337;v&#237;tett &#252;gyf&#233;l adatlap Excel felt&#246;lt&#233;se</label>
        <div>
            <input id="minicrm_customer_profile_inline_<?= $itemId; ?>" name="minicrm_customer_profile_files[]" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" <?= ($schemaErrors !== [] || !$deps['phpspreadsheet']) ? 'disabled' : 'required'; ?>>
            <button class="button" type="submit" <?= ($schemaErrors !== [] || !$deps['phpspreadsheet']) ? 'disabled' : ''; ?>>Kontaktadatok import&#225;l&#225;sa</button>
        </div>
        <p class="muted-text">Ezt a teljes b&#337;v&#237;tett &#252;gyf&#233;ladat exportot egyszer kell felt&#246;lteni, nem munk&#225;nk&#233;nt. Az import minden MiniCRM azonos&#237;t&#243;hoz p&#225;ros&#237;tja az emailt &#233;s telefonsz&#225;mot.</p>
    </form>
    <?php
}
?>
<section class="admin-section minicrm-import-page">
    <div class="container admin-requests-container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">MiniCRM</p>
                <h1>Importált munkák</h1>
                <p>Excel exportokból áthozott munkaállomány MiniCRM azonosító szerinti frissítéssel.</p>
            </div>
            <div class="form-actions">
                <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
                <a class="button button-secondary" href="<?= h(url_path('/admin/minicrm-export')); ?>">MiniCRM export</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-info">
                <p>Az importált MiniCRM munkák tárolásához futtasd le phpMyAdminban a <strong>database/minicrm_import.sql</strong> fájlt.</p>
                <?php if (is_admin_user()): ?>
                    <form class="inline-form" method="post" action="<?= h(url_path('/admin/minicrm-import')); ?>">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="install_minicrm_schema">
                        <button class="button button-secondary" type="submit">MiniCRM import táblák létrehozása</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$deps['phpspreadsheet']): ?>
            <div class="alert alert-error">
                <p>A PhpSpreadsheet nincs telepítve, ezért az Excel import nem használható. Töltsd fel a Composer vendor mappát a tárhelyre.</p>
            </div>
        <?php endif; ?>

        <div class="form-grid two minicrm-import-tools" id="minicrm-import-tools">
            <section class="auth-panel" data-minicrm-panel="import">
                <h2>Excel import</h2>
                <p class="muted-text">Az 5 külön MiniCRM mezőexport egyszerre kijelölhető. Az import MiniCRM azonosító alapján összefésüli őket, ezért ugyanaz a munka nem duplikálódik, hanem kiegészül.</p>
                <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/admin/minicrm-import')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="import_minicrm_files">
                    <label for="minicrm_files">MiniCRM XLSX/XLS fájlok</label>
                    <input id="minicrm_files" name="minicrm_files[]" type="file" multiple accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" <?= ($schemaErrors !== [] || !$deps['phpspreadsheet']) ? 'disabled' : 'required'; ?>>
                    <button class="button" type="submit" <?= ($schemaErrors !== [] || !$deps['phpspreadsheet']) ? 'disabled' : ''; ?>>Import indítása</button>
                </form>
            </section>

            <section class="auth-panel" data-minicrm-panel="import">
                <h2>Szerel&#337;i kioszt&#225;s</h2>
                <form class="form" method="post" action="<?= h(url_path('/admin/minicrm-import')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="assign_minicrm_electricians">
                    <button class="button button-secondary" type="submit" <?= ($schemaErrors !== [] || $electricianSchemaErrors !== [] || $totalItems === 0) ? 'disabled' : ''; ?>>Munk&#225;k sz&#233;toszt&#225;sa szerel&#337;knek</button>
                    <p class="muted-text">A MiniCRM szerel&#337;i mez&#337;j&#233;ben szerepl&#337; n&#233;v alapj&#225;n a rendszer megkeresi az akt&#237;v szerel&#337;i fi&#243;kot, &#233;s a munk&#225;t kiadja neki.</p>
                </form>
            </section>
            <section class="auth-panel" data-minicrm-panel="import">
                <h2>&#220;gyf&#233;l adatlap import</h2>
                <p class="muted-text">Az adatlap-exportot MiniCRM azonos&#237;t&#243;, adatlap URL &#233;s n&#233;v alapj&#225;n hozz&#225;rendelj&#252;k a m&#225;r l&#233;tez&#337; &#252;gyfelekhez, majd k&#252;l&#246;n blokkban megjelen&#237;tj&#252;k az &#252;gyf&#233;lkartonon.</p>
                <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/admin/minicrm-import')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="import_minicrm_customer_profiles">
                    <label for="minicrm_customer_profile_files">MiniCRM &#252;gyf&#233;l adatlap XLSX/XLS</label>
                    <input id="minicrm_customer_profile_files" name="minicrm_customer_profile_files[]" type="file" multiple accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" <?= ($schemaErrors !== [] || !$deps['phpspreadsheet']) ? 'disabled' : 'required'; ?>>
                    <button class="button" type="submit" <?= ($schemaErrors !== [] || !$deps['phpspreadsheet']) ? 'disabled' : ''; ?>>&#220;gyf&#233;l adatok import&#225;l&#225;sa</button>
                </form>
            </section>
            <section class="auth-panel" id="minicrm-documents" data-minicrm-panel="documents">
                <h2>Dokumentum ZIP összefűzés</h2>
                <p class="muted-text">A MiniCRM dokumentum ZIP fájljai a fájlnév elején lévő projektazonosító alapján kapcsolódnak a munkákhoz. Nagy ZIP esetén FTP-vel töltsd fel ide: <strong>storage/imports/minicrm-documents.zip</strong>, majd indítsd el a feldolgozást.</p>
                <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/admin/minicrm-import')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="import_minicrm_document_zip">
                    <label for="minicrm_document_zips">MiniCRM dokumentum ZIP-ek (opcionális)</label>
                    <input id="minicrm_document_zips" name="minicrm_document_zips[]" type="file" multiple accept=".zip,application/zip" <?= ($schemaErrors !== [] || !$deps['zip']) ? 'disabled' : ''; ?>>
                    <button class="button" type="submit" <?= ($schemaErrors !== [] || !$deps['zip']) ? 'disabled' : ''; ?>>ZIP dokumentumok összefűzése</button>
                </form>
                <div class="status-list">
                    <li><span class="status-label">Saját tárhelyes fájlok</span><span class="status-value"><?= $localDocumentFileCount; ?> db</span></li>
                    <li><span class="status-label">Mentett méret</span><span class="status-value"><?= number_format($localDocumentSizeTotal / 1024 / 1024, 1, ',', ' '); ?> MB</span></li>
                    <li><span class="status-label">ZIP motor</span><span class="status-value"><?= $deps['zip'] ? 'OK' : 'Hiányzik'; ?></span></li>
                    <li><span class="status-label">FTP-s ZIP-ek</span><span class="status-value"><?= count($documentZipCandidates); ?> db</span></li>
                </div>
            </section>

            <section class="auth-panel" data-minicrm-panel="documents">
                <h2>Fontos a dokumentumokról</h2>
                <p class="muted-text">Az Excelben szereplő MiniCRM dokumentummezők linkként kerülnek át. Ha a MiniCRM előfizetés megszűnik, ezek a MiniCRM-es letöltési linkek később nem biztos, hogy elérhetők lesznek.</p>
                <div class="status-list">
                    <li><span class="status-label">Importált munkák</span><span class="status-value"><?= $totalItems; ?> db</span></li>
                    <li><span class="status-label">Duplikáció kezelés</span><span class="status-value">MiniCRM azonosító alapján</span></li>
                    <li><span class="status-label">Excel motor</span><span class="status-value"><?= $deps['phpspreadsheet'] ? 'OK' : 'Hiányzik'; ?></span></li>
                </div>
            </section>
        </div>

        <?php if ($schemaErrors === [] && $items !== []): ?>
            <div class="admin-grid summary-grid request-summary-grid" data-minicrm-panel="works">
                <article class="metric-card metric-card-primary">
                    <span class="metric-label">Összes MiniCRM munka</span>
                    <strong><?= $totalItems; ?></strong>
                    <p>Az importált, visszakereshető MiniCRM munkaállomány.</p>
                </article>
                <article class="metric-card metric-card-system">
                    <span class="metric-label">Sajat tarhelyes fajlok</span>
                    <strong><?= $localDocumentFileCount; ?></strong>
                    <p>ZIP-bol osszefuzott MiniCRM kepek es dokumentumok.</p>
                </article>
                <?php foreach (array_slice($statusCounts, 0, 3, true) as $statusName => $statusCount): ?>
                    <article class="metric-card metric-card-system">
                        <span class="metric-label"><?= h((string) $statusName); ?></span>
                        <strong><?= (int) $statusCount; ?></strong>
                        <p>MiniCRM státusz szerinti csoport.</p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($batches !== []): ?>
            <section class="auth-panel form-block" id="minicrm-latest-imports" data-minicrm-panel="log">
                <h2>Legutóbbi importok</h2>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Fájl</th><th>Sorok</th><th>Új</th><th>Frissített</th><th>Kihagyott</th><th>Hibás</th><th>Dátum</th></tr></thead>
                        <tbody>
                            <?php foreach ($batches as $batch): ?>
                                <tr>
                                    <td><strong><?= h((string) $batch['original_name']); ?></strong><span><?= h((string) ($batch['created_by_name'] ?? '')); ?></span></td>
                                    <td><?= (int) $batch['row_count']; ?></td>
                                    <td><?= (int) $batch['imported_count']; ?></td>
                                    <td><?= (int) $batch['updated_count']; ?></td>
                                    <td><?= (int) $batch['skipped_count']; ?></td>
                                    <td><?= (int) $batch['error_count']; ?></td>
                                    <td><?= h((string) $batch['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($schemaErrors === [] && $items === [] && $standaloneRequests === []): ?>
            <div class="empty-state" data-minicrm-panel="works">
                <h2><?= $showArchived ? 'Nincs archivált adatlap' : 'Nincs aktív adatlap a listában'; ?></h2>
                <p><?= $showArchived ? 'Az archivált munkák később itt lesznek visszakereshetők és visszaállíthatók.' : 'Tölts fel egy MiniCRM Excel exportot, vagy nézd meg az archívumot, ha régebbi munkát keresel.'; ?></p>
                <?php if (!$showArchived && $archivedUnifiedItems > 0): ?>
                    <a class="button button-secondary" href="<?= h(url_path('/admin/minicrm-import') . '?show_archived=1'); ?>">Archívum megnyitása</a>
                <?php endif; ?>
            </div>
        <?php elseif ($items !== [] || $standaloneRequests !== []): ?>
            <div class="admin-workflow-list minicrm-workspace" id="minicrm-works" data-minicrm-panel="works">
                <section class="admin-workflow-stage">
                    <div class="admin-workflow-stage-head minicrm-workspace-head">
                        <div>
                            <span class="portal-kicker">Munkák</span>
                            <h2><?= $showArchived ? 'Archív munkaállomány' : 'MiniCRM munkaállomány'; ?></h2>
                            <p><?= $showArchived ? 'Archivált, visszaállítható adatlapok. Ezek nem jelennek meg az aktív napi listában.' : 'Státuszonként csoportosított, kompakt lista kereséssel és munkán belüli idővonallal.'; ?></p>
                        </div>
                        <div class="minicrm-archive-switch">
                            <strong><?= $totalUnifiedItems; ?> db</strong>
                            <a class="button button-secondary" href="<?= h(url_path('/admin/minicrm-import') . ($showArchived ? '' : '?show_archived=1')); ?>">
                                <?= $showArchived ? 'Aktív munkák' : 'Archívum'; ?>
                            </a>
                        </div>
                    </div>

                    <div class="minicrm-list-tools">
                        <label for="minicrm_search">Keresés a munkák között</label>
                        <input id="minicrm_search" type="search" placeholder="Név, azonosító, cím, felelős, státusz vagy mezőérték" data-minicrm-search>
                        <span data-minicrm-count><?= $totalUnifiedItems; ?> db</span>
                        <span class="minicrm-archive-counts">Aktív: <?= $activeUnifiedItems; ?> · Archív: <?= $archivedUnifiedItems; ?></span>
                    </div>

                    <nav class="minicrm-status-nav" aria-label="MiniCRM státuszok">
                        <?php foreach ($itemsByStatus as $statusName => $statusItems): ?>
                            <a href="#minicrm-status-<?= h(minicrm_import_dom_id((string) $statusName)); ?>">
                                <span><?= h((string) $statusName); ?></span>
                                <strong><?= count($statusItems); ?></strong>
                            </a>
                        <?php endforeach; ?>
                        <?php $portalNavIndex = 0; ?>
                        <?php foreach ($standaloneRequestsByWorkflowStage as $workflowStage => $requestItems): ?>
                            <?php
                            $workflowDefinition = $workflowStages[$workflowStage] ?? null;
                            $workflowLabel = $workflowDefinition !== null ? (string) $workflowDefinition['title'] : admin_workflow_stage_label((string) $workflowStage);
                            $portalGroupDomId = minicrm_import_portal_group_dom_id((string) $workflowStage, $portalNavIndex);
                            ?>
                            <a href="#<?= h($portalGroupDomId); ?>">
                                <span>Port&#225;l: <?= h($workflowLabel); ?></span>
                                <strong><?= count($requestItems); ?></strong>
                            </a>
                            <?php $portalNavIndex++; ?>
                        <?php endforeach; ?>
                    </nav>

                    <div class="minicrm-status-groups" data-minicrm-list>
                        <?php foreach ($itemsByStatus as $statusName => $statusItems): ?>
                            <?php $statusClass = minicrm_status_class((string) $statusName); ?>
                            <section class="minicrm-status-group" id="minicrm-status-<?= h(minicrm_import_dom_id((string) $statusName)); ?>" data-minicrm-status-group>
                                <header class="minicrm-status-group-head">
                                    <div>
                                        <span class="status-badge status-badge-<?= h($statusClass); ?>"><?= h((string) $statusName); ?></span>
                                        <strong><?= count($statusItems); ?> munka</strong>
                                    </div>
                                    <span data-minicrm-status-count><?= count($statusItems); ?> látható</span>
                                </header>

                                <?php if (can_view_super_admin_overview() && minicrm_import_key((string) $statusName) === 'szabo dezso 5'): ?>
                                    <?php $szaboPowerPreview = minicrm_szabo_dezso_5_apartment_power_preview($statusItems); ?>
                                    <section class="minicrm-document-preview-panel minicrm-bulk-fix-panel">
                                        <div class="admin-request-section-title">
                                            <h3>Szabó Dezső 5 lakás teljesítményjavítás</h3>
                                            <span><?= (int) $szaboPowerPreview['pending']; ?> javítandó / <?= (int) $szaboPowerPreview['target']; ?> cél</span>
                                        </div>
                                        <p class="muted-text">A pontosan beazonosított 20 lakás igényelt mindennapszaki teljesítménye 1x32 A helyett 3x16 A lesz. Az üzletek és garázsok kimaradnak.</p>
                                        <form method="post" action="<?= h(url_path('/admin/minicrm-import') . '#minicrm-status-szab-dezs-5'); ?>" onsubmit="return confirm('Biztosan átállítod a 20 Szabó Dezső 5 lakás igényelt teljesítményét 3x16 A-re?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="fix_szabo_dezso_5_apartment_power">
                                            <button class="button" type="submit" <?= (int) $szaboPowerPreview['pending'] === 0 ? 'disabled' : ''; ?>>20 lakás átállítása 3x16 A-re</button>
                                            <small><?= (int) $szaboPowerPreview['found']; ?> cél adatlap található ebben a csoportban, <?= (int) $szaboPowerPreview['already_fixed']; ?> már 3x16 A.</small>
                                        </form>
                                    </section>
                                <?php endif; ?>

                                <div class="minicrm-work-table" role="table" aria-label="<?= h((string) $statusName); ?> munkák">
                                    <div class="minicrm-work-table-head" role="row">
                                        <span>Munka</span>
                                        <span>Felelős</span>
                                        <span>Dátum</span>
                                        <span>Anyag</span>
                                        <span>Művelet</span>
                                    </div>
                                    <?php foreach ($statusItems as $item): ?>
                            <?php
                            $itemId = (int) ($item['id'] ?? 0);
                            $isSelectedItem = $itemId === $selectedItemId;
                            $siteAddress = trim((string) ($item['postal_code'] ?? '') . ' ' . (string) ($item['site_address'] ?? ''));
                            $statusClass = minicrm_status_class($item['minicrm_status'] ?? null);
                            $displayDate = trim((string) ($item['submitted_date'] ?: $item['date_value'] ?: $item['updated_at'] ?: $item['created_at'] ?: ''));
                            $detailUrl = url_path('/admin/minicrm-import') . '?item=' . $itemId . '#minicrm-work-' . $itemId;
                            $documentLinks = $isSelectedItem ? minicrm_work_item_document_links($item) : [];
                            $actualDocumentLinks = $isSelectedItem ? minicrm_import_actual_document_links($documentLinks) : [];
                            $localFiles = $isSelectedItem ? minicrm_work_item_files($itemId) : [];
                            $rawFields = $isSelectedItem ? minicrm_work_item_raw_fields($item) : [];
                            $fieldGroups = $isSelectedItem ? minicrm_work_item_field_groups($item, true) : [];
                            $timelineEvents = $isSelectedItem ? minicrm_import_timeline_events($item, $rawFields, $localFiles) : [];
                            $summaryNote = $isSelectedItem ? minicrm_import_first_matching_field($rawFields, ['/megjegyzes/', '/uzenet/', '/munka rovid leirasa/', '/szoveg/']) : '';
                            $documentLinkCount = $isSelectedItem ? count($actualDocumentLinks) : minicrm_import_document_link_count($item);
                            $linkedMvmRequestId = $isSelectedItem ? minicrm_work_item_connection_request_id($itemId) : null;
                            $linkedMvmRequest = $linkedMvmRequestId !== null ? find_connection_request((int) $linkedMvmRequestId) : null;
                            $miniCrmUkNumber = trim((string) ($item['mvm_uk_number'] ?? ''));
                            $linkedUkNumber = is_array($linkedMvmRequest) ? trim((string) ($linkedMvmRequest['mvm_uk_number'] ?? '')) : '';
                            $displayUkNumber = $miniCrmUkNumber !== '' ? $miniCrmUkNumber : $linkedUkNumber;
                            $miniCrmWorkNote = trim((string) ($item['work_note'] ?? ''));
                            $linkedWorkNote = is_array($linkedMvmRequest) ? trim((string) ($linkedMvmRequest['work_note'] ?? '')) : '';
                            $displayWorkNote = $linkedWorkNote !== '' ? $linkedWorkNote : $miniCrmWorkNote;
                            $linkedMvmDocuments = $linkedMvmRequestId !== null ? connection_request_documents($linkedMvmRequestId) : [];
                            $linkedRequestEmailThreads = is_array($linkedMvmRequest) ? mvm_email_threads_with_messages((int) $linkedMvmRequest['id']) : [];
                            $linkedRequestTimelineEvents = is_array($linkedMvmRequest) ? connection_request_timeline_events($linkedMvmRequest) : [];
                            $mvmGeneratorUrl = url_path('/admin/minicrm-import/mvm-documents') . '?minicrm_item=' . $itemId;
                            $linkedMiniCrmQuotes = $linkedMvmRequestId !== null ? quotes_for_connection_request($linkedMvmRequestId) : [];
                            $linkedAcceptedQuote = $linkedMvmRequestId !== null ? accepted_quote_for_connection_request($linkedMvmRequestId) : null;
                            $assignedElectricianName = minicrm_work_item_electrician_assignment_name($item);
                            $linkedAssignedElectricianUserId = is_array($linkedMvmRequest) ? (int) ($linkedMvmRequest['assigned_electrician_user_id'] ?? 0) : 0;
                            $linkedWorkflowStage = is_array($linkedMvmRequest)
                                ? connection_request_admin_workflow_stage($linkedMvmRequest, $linkedMiniCrmQuotes[0] ?? null, $linkedAcceptedQuote, $linkedMvmDocuments)
                                : 'case_starting';
                            $linkedWorkflowDefinition = admin_workflow_stage_definitions()[$linkedWorkflowStage] ?? null;
                            $customerProfile = $customerProfilesBySource[minicrm_source_id_key((string) ($item['source_id'] ?? ''))] ?? null;
                            if ($customerProfile === null && $isSelectedItem) {
                                $customerProfile = minicrm_customer_profile_for_work_item($item);
                            }
                            $profileName = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_name', ['Szemely1 Nev', 'Személy1: Név', 'Nev', 'Név']) : '';
                            $profileEmail = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_email', ['Szemely1 Email', 'Személy1: Email', 'Ceg Email', 'Cég: Email', 'Email']) : '';
                            $linkedCustomerEmail = is_array($linkedMvmRequest) ? trim((string) ($linkedMvmRequest['email'] ?? '')) : '';
                            $displayEmail = $linkedCustomerEmail !== '' ? $linkedCustomerEmail : $profileEmail;
                            $profilePhone = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_phone', ['Szemely1 Telefon', 'Személy1: Telefon', 'Ceg Telefon', 'Cég: Telefon', 'Telefon']) : '';
                            $profileConsent = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_consent', ['Szemely1 Adatkezelesi hozzajarulas', 'Személy1: Adatkezelési hozzájárulás']) : '';
                            $profilePosition = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_position', ['Szemely1 Beosztas', 'Személy1: Beosztás']) : '';
                            $profileWebsite = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_website', ['Szemely1 Weboldal', 'Személy1: Weboldal']) : '';
                            $profileSummary = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_summary', ['Szemely1 Osszefoglalo', 'Személy1: Összefoglaló']) : '';
                            $profileContactLine = trim(implode(' · ', array_filter([$displayEmail, $profilePhone], static fn (string $value): bool => $value !== '')));
                            $profileHasContact = $displayEmail !== '' || $profilePhone !== '';
                            $searchText = implode(' ', [
                                (string) ($item['card_name'] ?? ''),
                                (string) ($item['source_id'] ?? ''),
                                (string) ($item['responsible'] ?? ''),
                                (string) ($item['minicrm_status'] ?? ''),
                                $assignedElectricianName,
                                $profileName,
                                $displayEmail,
                                $profilePhone,
                                $displayUkNumber,
                                $displayWorkNote,
                                $siteAddress,
                            ]);
                            ?>
                            <details class="admin-workflow-request minicrm-work-row" id="minicrm-work-<?= $itemId; ?>" data-minicrm-item data-minicrm-search-text="<?= h($searchText); ?>" data-minicrm-loaded="<?= $isSelectedItem ? '1' : '0'; ?>" data-minicrm-detail-url="<?= h($detailUrl); ?>" <?= $isSelectedItem ? 'open' : ''; ?>>
                                <summary class="admin-workflow-request-summary minicrm-work-row-summary">
                                    <span class="admin-workflow-request-main">
                                        <strong><?= h((string) $item['card_name']); ?></strong>
                                        <small><?= h($siteAddress !== '' ? $siteAddress : (string) $item['source_id']); ?></small>
                                    </span>
                                    <span class="admin-workflow-request-meta">
                                        <span><?= h((string) ($item['responsible'] ?: 'Nincs felelős')); ?></span>
                                        <strong><?= h($profileContactLine !== '' ? $profileContactLine : (string) ($item['card_name'] ?? '')); ?></strong>
                                    </span>
                                    <span class="minicrm-work-date">
                                        <?= h($displayDate !== '' ? $displayDate : '-'); ?>
                                    </span>
                                    <span class="admin-workflow-request-badges">
                                        <strong><?= $isSelectedItem ? count($localFiles) . ' fájl' : 'Adatlap'; ?></strong>
                                        <small><?= $isSelectedItem ? count($rawFields) . ' mező · ' . count($documentLinks) . ' link' : $documentLinkCount . ' link'; ?></small>
                                    </span>
                                    <span class="minicrm-row-actions" onclick="event.stopPropagation();">
                                        <form method="post" action="<?= h(url_path('/admin/minicrm-import') . '#minicrm-works'); ?>" onsubmit="event.stopPropagation(); return confirm('Biztosan <?= $showArchived ? 'visszaállítod az archívumból' : 'archiválod'; ?> ezt az adatlapot?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="archive_minicrm_work_item">
                                            <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                            <input type="hidden" name="archive_state" value="<?= $showArchived ? 'restore' : 'archive'; ?>">
                                            <button class="table-action-button" type="submit"><?= $showArchived ? 'Visszaállítás' : 'Archiválás'; ?></button>
                                        </form>
                                        <?php if (can_view_super_admin_overview()): ?>
                                            <form method="post" action="<?= h(url_path('/admin/minicrm-import') . ($showArchived ? '?show_archived=1' : '') . '#minicrm-works'); ?>" onsubmit="event.stopPropagation(); return confirm('Biztosan végleg törlöd ezt a MiniCRM adatlapot?') && confirm('A törlés a kapcsolódó fájlokat és MVM adatlapot is törli. Folytatod?');">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_minicrm_work_item">
                                                <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                                <input type="hidden" name="delete_confirmation" value="TORLES">
                                                <input type="hidden" name="return_to_archived" value="<?= $showArchived ? '1' : '0'; ?>">
                                                <button class="table-action-button table-action-danger" type="submit">Törlés</button>
                                            </form>
                                        <?php endif; ?>
                                    </span>
                                </summary>

                                <?php if (!$isSelectedItem): ?>
                                    <div class="minicrm-work-card minicrm-work-card-placeholder">
                                        <p class="request-admin-empty">Az adatlap megnyitásához kattints a sorra; a részletek külön töltődnek be, hogy a lista gyors maradjon.</p>
                                        <a class="button button-secondary" href="<?= h($detailUrl); ?>">Adatlap megnyitása</a>
                                    </div>
                                <?php else: ?>
                                <article class="request-admin-card minicrm-work-card">
                                    <div class="request-admin-card-head">
                                        <div>
                                            <span class="portal-kicker">MiniCRM azonosító: <?= h((string) $item['source_id']); ?></span>
                                            <h2><?= h((string) $item['card_name']); ?></h2>
                                            <p><?= h($summaryNote !== '' ? minicrm_import_short_text($summaryNote, 220) : ($siteAddress !== '' ? $siteAddress : '')); ?></p>
                                        </div>
                                        <div class="request-admin-status">
                                            <?php if ($linkedWorkflowDefinition !== null): ?><span class="status-badge status-badge-<?= h((string) ($linkedWorkflowDefinition['variant'] ?? 'draft')); ?>"><?= h((string) $linkedWorkflowDefinition['title']); ?></span><?php endif; ?>
                                            <span class="status-badge status-badge-<?= h($statusClass); ?>"><?= h((string) ($item['minicrm_status'] ?: 'Nincs státusz')); ?></span>
                                            <?php if (!empty($item['responsible'])): ?><span class="status-badge status-badge-finalized"><?= h((string) $item['responsible']); ?></span><?php endif; ?>
                                            <a class="button button-secondary" href="<?= h($mvmGeneratorUrl); ?>">MVM dokumentumok</a>
                                        </div>
                                    </div>

                                    <div class="minicrm-work-detail-layout">
                                        <aside class="minicrm-work-facts">
                                            <dl>
                                                <div><dt>Ügyfél</dt><dd><?= h((string) ($item['customer_name'] ?: $item['card_name'] ?: '-')); ?></dd></div>
                                                <div>
                                                    <dt><label for="minicrm_mvm_uk_number_<?= $itemId; ?>">ÜK szám</label></dt>
                                                    <dd>
                                                        <form class="portal-customer-email-form" method="post" action="<?= h($detailUrl); ?>">
                                                            <?= csrf_field(); ?>
                                                            <input type="hidden" name="action" value="save_minicrm_work_mvm_uk_number">
                                                            <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                                            <input id="minicrm_mvm_uk_number_<?= $itemId; ?>" name="mvm_uk_number" value="<?= h($displayUkNumber); ?>" placeholder="MVM ÜK szám" aria-label="MVM ÜK szám">
                                                            <button class="button button-secondary" type="submit">Mentés</button>
                                                        </form>
                                                    </dd>
                                                </div>
                                                <div class="admin-request-data-wide">
                                                    <dt><label for="minicrm_work_note_<?= $itemId; ?>">Munka megjegyzés</label></dt>
                                                    <dd>
                                                        <form class="portal-assignment-form" method="post" action="<?= h($detailUrl); ?>">
                                                            <?= csrf_field(); ?>
                                                            <input type="hidden" name="action" value="save_minicrm_work_note">
                                                            <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                                            <textarea id="minicrm_work_note_<?= $itemId; ?>" name="work_note" rows="4" placeholder="Megjegyzés a munkához"><?= h($displayWorkNote); ?></textarea>
                                                            <button class="button button-secondary" type="submit">Megjegyzés mentése</button>
                                                        </form>
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt>Email</dt>
                                                    <dd>
                                                        <?php if (is_array($linkedMvmRequest)): ?>
                                                            <form class="portal-customer-email-form" method="post" action="<?= h($detailUrl); ?>">
                                                                <?= csrf_field(); ?>
                                                                <input type="hidden" name="action" value="update_portal_work_customer_email">
                                                                <input type="hidden" name="request_id" value="<?= (int) $linkedMvmRequest['id']; ?>">
                                                                <input type="hidden" name="redirect_item_id" value="<?= $itemId; ?>">
                                                                <input name="customer_email" type="email" autocomplete="email" value="<?= h($displayEmail); ?>" placeholder="ugyfel@email.hu" aria-label="Ugyfel alap email cime" required>
                                                                <button class="button button-secondary" type="submit">Ment&#233;s</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <?= h($displayEmail !== '' ? $displayEmail : 'Nincs importalt email'); ?>
                                                        <?php endif; ?>
                                                    </dd>
                                                </div>
                                                <div><dt>Telefon</dt><dd><?= h($profilePhone !== '' ? $profilePhone : 'Nincs importalt telefon'); ?></dd></div>
                                                <div><dt>Felelős</dt><dd><?= h((string) ($item['responsible'] ?: '-')); ?></dd></div>
                                                <div><dt>Cím</dt><dd><?= h($siteAddress !== '' ? $siteAddress : '-'); ?></dd></div>
                                                <div><dt>HRSZ</dt><dd><?= h((string) ($item['hrsz'] ?: '-')); ?></dd></div>
                                                <div><dt>Munka típusa</dt><dd><?= h((string) ($item['work_type'] ?: $item['work_kind'] ?: '-')); ?></dd></div>
                                                <div><dt>Mérő</dt><dd><?= h((string) ($item['meter_serial'] ?: $item['controlled_meter_serial'] ?: '-')); ?></dd></div>
                                                <div><dt>Leadás</dt><dd><?= h((string) ($item['submitted_date'] ?: '-')); ?></dd></div>
                                            </dl>

                                            <section class="minicrm-compact-docs">
                                                <h3><?= $localFiles !== [] ? 'Régi MiniCRM linkek' : 'MiniCRM linkek'; ?> <span><?= count($actualDocumentLinks); ?></span></h3>
                                                <?php if ($localFiles !== []): ?>
                                                    <p class="request-admin-empty">A dokumentumok már saját tárhelyről nyílnak. Ezek csak ellenőrzéshez maradnak.</p>
                                                <?php elseif ($actualDocumentLinks === []): ?>
                                                    <p class="request-admin-empty">Ehhez a munkához még nincs saját tárhelyes fájl és nincs használható MiniCRM letöltési link sem.</p>
                                                <?php else: ?>
                                                    <div>
                                                        <?php foreach ($actualDocumentLinks as $documentLink): ?>
                                                            <a href="<?= h((string) $documentLink['value']); ?>" target="_blank" rel="noopener"><?= h(minicrm_import_short_text((string) $documentLink['label'], 64)); ?><span>MiniCRM</span></a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </section>

                                            <section class="minicrm-compact-docs portal-assignment-panel">
                                                <h3>Szerelőhöz rendelés</h3>
                                                <p class="muted-text">Itt lehet kézzel kiadni vagy visszavenni ezt a MiniCRM munkát a szerelői felületről. Mentéskor a rendszer normál munkához kapcsolja a MiniCRM tételt.</p>
                                                <?php if ($electricianSchemaErrors !== []): ?>
                                                    <p class="request-admin-empty">A szerelői kiosztáshoz futtasd le a database/electrician_workflow.sql fájlt.</p>
                                                <?php elseif ($electricians === []): ?>
                                                    <p class="request-admin-empty">Nincs aktív szerelői fiók. Előbb hozz létre szerelőt a Szerelők menüben.</p>
                                                <?php else: ?>
                                                    <form class="portal-assignment-form" method="post" action="<?= h($detailUrl); ?>">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="action" value="assign_minicrm_work_electrician">
                                                        <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                                        <label for="minicrm_electrician_<?= $itemId; ?>">Szerelő</label>
                                                        <select id="minicrm_electrician_<?= $itemId; ?>" name="electrician_user_id">
                                                            <option value="">Nincs szerelőnek kiadva</option>
                                                            <?php foreach ($electricians as $electrician): ?>
                                                                <?php $electricianUserId = (int) ($electrician['user_id'] ?? 0); ?>
                                                                <option value="<?= $electricianUserId; ?>" <?= $linkedAssignedElectricianUserId === $electricianUserId ? 'selected' : ''; ?>>
                                                                    <?= h((string) ($electrician['name'] ?? $electrician['user_name'] ?? $electrician['user_email'] ?? ('#' . $electricianUserId))); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button class="button" type="submit">Szerelő mentése</button>
                                                    </form>
                                                <?php endif; ?>
                                            </section>

                                            <section class="minicrm-compact-docs portal-assignment-panel workflow-stage-panel">
                                                <h3>Munkafolyamat</h3>
                                                <?php if ($linkedWorkflowDefinition !== null): ?>
                                                    <p class="muted-text">
                                                        <strong><?= (int) $linkedWorkflowDefinition['number']; ?>. <?= h((string) $linkedWorkflowDefinition['title']); ?></strong><br>
                                                        <?= h((string) $linkedWorkflowDefinition['description']); ?>
                                                    </p>
                                                    <form class="portal-assignment-form" method="post" action="<?= h($detailUrl); ?>" onsubmit="return confirm('Biztosan mented a kiválasztott munkafolyamat státuszt?');">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="action" value="close_minicrm_workflow_stage">
                                                        <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                                        <label for="workflow_stage_minicrm_<?= $itemId; ?>">Új státusz</label>
                                                        <select id="workflow_stage_minicrm_<?= $itemId; ?>" name="target_stage" required>
                                                            <?php foreach ($workflowStages as $stageKey => $stageDefinition): ?>
                                                                <option value="<?= h((string) $stageKey); ?>" <?= $linkedWorkflowStage === (string) $stageKey ? 'selected' : ''; ?>>
                                                                    <?= (int) $stageDefinition['number']; ?>. <?= h((string) $stageDefinition['title']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                            <option value="__auto__">Automatikus állapot visszaállítása</option>
                                                        </select>
                                                        <label class="checkbox-row"><input type="checkbox" name="notify_customer" value="1"><span>Ügyfél tájékoztatása emailben</span></label>
                                                        <label class="checkbox-row"><input type="checkbox" name="notify_responsible" value="1"><span>Adatlap felelőse / szerelő tájékoztatása emailben</span></label>
                                                        <button class="button" type="submit">Státusz mentése</button>
                                                        <small>Email csak akkor megy ki, ha külön bepipálod.</small>
                                                    </form>
                                                <?php else: ?>
                                                    <p class="request-admin-empty">A munkafolyamat státusza nem olvasható.</p>
                                                <?php endif; ?>
                                            </section>

                                            <?php if (can_view_super_admin_overview() && is_array($linkedMvmRequest)): ?>
                                                <section class="minicrm-compact-docs portal-assignment-panel customer-crm-danger">
                                                    <h3>Szuperadmin törlés</h3>
                                                    <p class="muted-text">Téves vagy feleslegessé vált adatlap törlése a kapcsolódó ajánlatokkal, fájlokkal, MVM dokumentumokkal, email szálakkal és naplókkal együtt. Az ügyfél törzsadata külön megmarad.</p>
                                                    <form class="portal-assignment-form" method="post" action="<?= h($detailUrl); ?>" onsubmit="return confirm('Biztosan törlöd ezt az adatlapot és minden kapcsolódó adatát?') && confirm('Második megerősítés: ez nem visszavonható. Folytatod?');">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete_portal_work_request">
                                                        <input type="hidden" name="request_id" value="<?= (int) $linkedMvmRequest['id']; ?>">
                                                        <input type="hidden" name="redirect_item_id" value="<?= $itemId; ?>">
                                                        <input type="hidden" name="return_to_archived" value="<?= $showArchived ? '1' : '0'; ?>">
                                                        <label for="delete_confirmation_minicrm_<?= $itemId; ?>">Megerősítés: TORLES</label>
                                                        <input id="delete_confirmation_minicrm_<?= $itemId; ?>" name="delete_confirmation" placeholder="TORLES" autocomplete="off" required>
                                                        <button class="table-action-button table-action-danger" type="submit">Adatlap törlése</button>
                                                    </form>
                                                </section>
                                            <?php endif; ?>
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

                                            <?php if (is_array($linkedMvmRequest)): ?>
                                                <?php
                                                $linkedRequestId = (int) $linkedMvmRequest['id'];
                                                $linkedRequestTitle = trim((string) ($linkedMvmRequest['project_name'] ?? '')) ?: (string) ($item['card_name'] ?? 'Munka');
                                                ?>
                                                <section class="minicrm-document-preview-panel communication-panel">
                                                    <div class="admin-request-section-title">
                                                        <h3>Kommunikáció</h3>
                                                        <span><?= count($linkedRequestEmailThreads); ?> email szál</span>
                                                    </div>
                                                    <p class="muted-text">A normál adatlaphoz tartozó ügyfél és felelős levelezés. A válaszazonosító alapján a központi postafiókból beolvasott válaszok ide kerülnek.</p>
                                                    <form class="portal-message-form" method="post" action="<?= h($detailUrl); ?>">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="action" value="send_portal_work_message">
                                                        <input type="hidden" name="request_id" value="<?= $linkedRequestId; ?>">
                                                        <input type="hidden" name="redirect_item_id" value="<?= $itemId; ?>">
                                                        <div class="form-grid two compact">
                                                            <div>
                                                                <label for="minicrm_message_recipient_<?= $itemId; ?>">Címzett</label>
                                                                <select id="minicrm_message_recipient_<?= $itemId; ?>" name="message_recipient" required>
                                                                    <option value="responsible">Adatlap felelőse<?= !empty($linkedMvmRequest['electrician_name']) ? ' - ' . h((string) $linkedMvmRequest['electrician_name']) : ''; ?></option>
                                                                    <option value="customer">Ügyfél<?= $displayEmail !== '' ? ' - ' . h($displayEmail) : ''; ?></option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label for="minicrm_message_subject_<?= $itemId; ?>">Tárgy</label>
                                                                <input id="minicrm_message_subject_<?= $itemId; ?>" name="message_subject" value="<?= h(APP_NAME . ' üzenet - ' . $linkedRequestTitle); ?>">
                                                            </div>
                                                        </div>
                                                        <div class="form-grid two compact">
                                                            <div>
                                                                <label for="minicrm_customer_recipient_email_<?= $itemId; ?>">Ügyfél email címzett</label>
                                                                <input id="minicrm_customer_recipient_email_<?= $itemId; ?>" name="customer_recipient_email" type="email" inputmode="email" autocomplete="email" value="<?= h($displayEmail); ?>">
                                                            </div>
                                                            <div>
                                                                <label for="minicrm_customer_recipient_name_<?= $itemId; ?>">Ügyfél címzett neve</label>
                                                                <input id="minicrm_customer_recipient_name_<?= $itemId; ?>" name="customer_recipient_name" value="<?= h($profileName !== '' ? $profileName : (string) ($item['customer_name'] ?: $item['card_name'] ?: '')); ?>">
                                                            </div>
                                                        </div>
                                                        <label for="minicrm_message_body_<?= $itemId; ?>">Üzenet</label>
                                                        <textarea id="minicrm_message_body_<?= $itemId; ?>" name="message_body" rows="4" required></textarea>
                                                        <div class="form-actions"><button class="button" type="submit">Üzenet küldése</button></div>
                                                    </form>
                                                    <div class="portal-mail-auto-sync-note">
                                                        <strong>Válaszok automatikusan</strong>
                                                        <span>Az ügyfél válasza a <?= h(mvm_mail_reply_address()); ?> postafiókra érkezik, és az emailben lévő azonosító alapján erre az adatlapra kerül.</span>
                                                    </div>
                                                    <form class="inline-form portal-mail-sync-form" method="post" action="<?= h($detailUrl); ?>">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="action" value="sync_portal_work_mailbox">
                                                        <input type="hidden" name="request_id" value="<?= $linkedRequestId; ?>">
                                                        <input type="hidden" name="redirect_item_id" value="<?= $itemId; ?>">
                                                        <button class="button button-secondary" type="submit">Válaszok frissítése most</button>
                                                    </form>
                                                    <?php if (!mvm_mailbox_sync_can_run()): ?>
                                                        <div class="alert alert-info"><p><?= h(mvm_mailbox_sync_setup_message()); ?></p></div>
                                                    <?php endif; ?>
                                                    <?php if ($linkedRequestEmailThreads !== []): ?>
                                                        <div class="mvm-mail-thread-list mvm-mail-thread-list-compact">
                                                            <?php foreach ($linkedRequestEmailThreads as $thread): ?>
                                                                <article class="mvm-mail-thread">
                                                                    <div class="mvm-mail-thread-head">
                                                                        <div>
                                                                            <span class="portal-kicker"><?= h((string) $thread['token']); ?></span>
                                                                            <strong><?= h((string) $thread['document_label']); ?></strong>
                                                                            <p><?= h((string) $thread['subject']); ?></p>
                                                                        </div>
                                                                        <span class="status-badge status-badge-<?= h((string) $thread['status']); ?>"><?= h($mvmThreadStatusLabels[$thread['status']] ?? (string) $thread['status']); ?></span>
                                                                    </div>
                                                                    <p><?= h(latest_mvm_email_message_preview($thread)); ?></p>
                                                                </article>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </section>

                                                <section class="minicrm-timeline-panel">
                                                    <div class="admin-request-section-title">
                                                        <h3>Adatlap idővonal</h3>
                                                        <span><?= count($linkedRequestTimelineEvents); ?> esemény</span>
                                                    </div>
                                                    <?php if ($linkedRequestTimelineEvents === []): ?>
                                                        <p class="request-admin-empty">Ehhez az adatlaphoz még nincs naplózott esemény.</p>
                                                    <?php else: ?>
                                                        <ol class="minicrm-timeline">
                                                            <?php foreach ($linkedRequestTimelineEvents as $event): ?>
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
                                                    <?php endif; ?>
                                                </section>
                                            <?php endif; ?>

                                            <section class="minicrm-document-preview-panel">
                                                <div class="admin-request-section-title">
                                                    <h3>&#220;gyf&#233;l el&#233;rhet&#337;s&#233;ge</h3>
                                                    <span><?= $customerProfile !== null ? ($profileHasContact ? 'MiniCRM adatlap' : 'Kontaktadat hi&#225;nyzik') : 'Nincs adat'; ?></span>
                                                </div>
                                                <?php if ($customerProfile === null): ?>
                                                    <p class="request-admin-empty">Ehhez a munk&#225;hoz m&#233;g nincs import&#225;lt MiniCRM &#252;gyf&#233;l adatlap. T&#246;ltsd fel a b&#337;v&#237;tett &#252;gyf&#233;l adatlap exportot az Import&#225;l&#225;s f&#252;l&#246;n.</p>
                                                    <?php minicrm_customer_profile_inline_import_form($itemId, $schemaErrors, $deps); ?>
                                                <?php elseif (!$profileHasContact): ?>
                                                    <p class="request-admin-empty">Ehhez a MiniCRM azonos&#237;t&#243;hoz van &#252;gyf&#233;l adatlap, de nincs benne Szem&#233;ly1: Email vagy Szem&#233;ly1: Telefon. A 13 oszlopos Custom export nem tartalmaz kontaktadatot; a b&#337;v&#237;tett &#252;gyf&#233;l adatlap exportot kell felt&#246;lteni.</p>
                                                    <div class="minicrm-readable-grid">
                                                        <div class="minicrm-readable-row"><span>MiniCRM azonos&#237;t&#243;</span><strong><?= h((string) ($item['source_id'] ?? '-')); ?></strong></div>
                                                        <div class="minicrm-readable-row"><span>&#220;gyf&#233;l adatlap sor</span><strong><?= h((string) ($customerProfile['card_name'] ?? '-')); ?></strong></div>
                                                    </div>
                                                    <?php minicrm_customer_profile_inline_import_form($itemId, $schemaErrors, $deps); ?>
                                                <?php else: ?>
                                                    <div class="minicrm-readable-grid">
                                                        <div class="minicrm-readable-row"><span>N&#233;v</span><strong><?= h($profileName !== '' ? $profileName : (string) ($customerProfile['card_name'] ?? '-')); ?></strong></div>
                                                        <div class="minicrm-readable-row"><span>Email</span><strong><?= h($displayEmail !== '' ? $displayEmail : '-'); ?></strong></div>
                                                        <div class="minicrm-readable-row"><span>Telefon</span><strong><?= h($profilePhone !== '' ? $profilePhone : '-'); ?></strong></div>
                                                        <div class="minicrm-readable-row"><span>Adatkezel&#233;si hozz&#225;j&#225;rul&#225;s</span><strong><?= h($profileConsent !== '' ? $profileConsent : '-'); ?></strong></div>
                                                        <div class="minicrm-readable-row"><span>Beoszt&#225;s</span><strong><?= h($profilePosition !== '' ? $profilePosition : '-'); ?></strong></div>
                                                        <div class="minicrm-readable-row"><span>Weboldal</span><strong><?= h($profileWebsite !== '' ? $profileWebsite : '-'); ?></strong></div>
                                                        <?php if ($profileSummary !== ''): ?>
                                                            <div class="minicrm-readable-row customer-crm-wide"><span>&#214;sszefoglal&#243;</span><strong><?= h($profileSummary); ?></strong></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </section>

                                            <?php if ($linkedMiniCrmQuotes !== []): ?>
                                                <section class="minicrm-document-preview-panel minicrm-quote-panel">
                                                    <div class="admin-request-section-title">
                                                        <h3>Árajánlatok</h3>
                                                        <span><?= count($linkedMiniCrmQuotes); ?> ajánlat</span>
                                                    </div>
                                                    <div class="quote-mini-list">
                                                        <?php foreach ($linkedMiniCrmQuotes as $quote): ?>
                                                            <?php
                                                            $quoteId = (int) $quote['id'];
                                                            $quoteStatus = (string) ($quote['status'] ?? 'draft');
                                                            $quoteEditUrl = url_path('/quick-quote') . '?quote_id=' . $quoteId;
                                                            $quoteFileUrl = quote_file_is_available($quote) ? url_path('/admin/quotes/file') . '?id=' . $quoteId : null;
                                                            ?>
                                                            <article class="quote-mini-card">
                                                                <div>
                                                                    <strong><?= h((string) ($quote['quote_number'] ?? ('#' . $quoteId))); ?></strong>
                                                                    <span><?= h((string) ($quote['subject'] ?? 'Árajánlat')); ?></span>
                                                                </div>
                                                                <div>
                                                                    <span class="status-badge status-badge-<?= h($quoteStatus); ?>"><?= h($quoteStatusLabels[$quoteStatus] ?? $quoteStatus); ?></span>
                                                                    <strong><?= h(quote_display_total($quote)); ?></strong>
                                                                </div>
                                                                <div class="inline-link-list">
                                                                    <a href="<?= h($quoteEditUrl); ?>">Megnyitás</a>
                                                                    <?php if ($quoteFileUrl !== null): ?>
                                                                        <a href="<?= h($quoteFileUrl); ?>" target="_blank">PDF megnyitása</a>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </article>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </section>
                                            <?php endif; ?>

                                            <section class="minicrm-document-preview-panel minicrm-mvm-generator-panel">
                                                <div class="admin-request-section-title">
                                                    <h3>MVM dokumentum generálás</h3>
                                                    <span><?= count($linkedMvmDocuments); ?> dokumentum</span>
                                                </div>
                                                <p class="muted-text">A MiniCRM munka adataiból normál MVM igény készül a háttérben, így ugyanaz a Word/PDF generátor, dokumentumfeltöltés, komplett csomag és MVM küldés használható.</p>
                                                <div class="form-actions">
                                                    <a class="button" href="<?= h($mvmGeneratorUrl); ?>">MVM dokumentum generáló megnyitása</a>
                                                    <?php if ($linkedMvmRequestId !== null): ?>
                                                        <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests/mvm-documents') . '?id=' . (int) $linkedMvmRequestId); ?>">Normál igény MVM oldala</a>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($linkedMvmDocuments !== []): ?>
                                                    <div class="inline-link-list">
                                                        <?php foreach (array_slice($linkedMvmDocuments, 0, 6) as $mvmDocument): ?>
                                                            <a href="<?= h(url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $mvmDocument['id']); ?>" target="_blank"><?= h((string) $mvmDocument['title']); ?></a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </section>

                                            <section class="minicrm-document-preview-panel">
                                                <div class="admin-request-section-title">
                                                    <h3>Fotók és dokumentumok</h3>
                                                    <span><?= count($localFiles); ?> fájl</span>
                                                </div>
                                                <form class="intervention-upload-form minicrm-manual-upload-form" method="post" enctype="multipart/form-data" action="<?= h($detailUrl); ?>">
                                                    <?= csrf_field(); ?>
                                                    <input type="hidden" name="action" value="upload_minicrm_work_files">
                                                    <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                                    <label for="minicrm_work_files_<?= $itemId; ?>">Új fotó vagy dokumentum feltöltése</label>
                                                    <div>
                                                        <input id="minicrm_work_files_<?= $itemId; ?>" name="minicrm_work_files[]" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/jpeg,image/png,image/webp">
                                                        <input name="file_label" type="text" value="Kézi feltöltés" aria-label="Fájl címke">
                                                        <button class="button" type="submit">Feltöltés</button>
                                                    </div>
                                                </form>
                                                <?php if ($localFiles === []): ?>
                                                    <p class="request-admin-empty">Ehhez a munkához még nincs saját tárhelyes fájl kapcsolva. Tölthetsz fel plusz fotókat és dokumentumokat, vagy importálhatod őket ZIP-ből.</p>
                                                <?php else: ?>
                                                    <div class="admin-request-doc-grid">
                                                        <?php foreach ($localFiles as $localFile): ?>
                                                            <?php
                                                            $localFileUrl = url_path('/admin/minicrm-import/file') . '?id=' . (int) $localFile['id'];
                                                            $previewKind = portal_file_preview_kind($localFile);
                                                            ?>
                                                            <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
                                                                <div class="admin-request-doc-thumb">
                                                                    <?php if ($previewKind === 'image'): ?>
                                                                        <a href="<?= h($localFileUrl); ?>" target="_blank" aria-label="<?= h((string) $localFile['label']); ?> megnyitása">
                                                                            <img src="<?= h($localFileUrl); ?>" alt="<?= h((string) $localFile['label']); ?>" width="92" height="92" loading="lazy">
                                                                        </a>
                                                                    <?php elseif ($previewKind === 'pdf'): ?>
                                                                        <iframe src="<?= h($localFileUrl); ?>#toolbar=0&navpanes=0" title="<?= h((string) $localFile['label']); ?>" width="92" height="92" loading="lazy"></iframe>
                                                                    <?php else: ?>
                                                                        <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($localFile)); ?></span></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="admin-request-doc-meta">
                                                                    <strong><?= h((string) $localFile['label']); ?></strong>
                                                                    <span><?= h((string) $localFile['original_name']); ?></span>
                                                                    <a href="<?= h($localFileUrl); ?>" target="_blank">Megnyitás</a>
                                                                    <form method="post" action="<?= h(url_path('/admin/minicrm-import') . '?item=' . $itemId . '#minicrm-work-' . $itemId); ?>">
                                                                        <?= csrf_field(); ?>
                                                                        <input type="hidden" name="action" value="delete_minicrm_work_file">
                                                                        <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                                                        <input type="hidden" name="file_id" value="<?= (int) $localFile['id']; ?>">
                                                                        <button class="table-action-button table-action-danger" type="submit" onclick="return confirm('Biztosan törlöd ezt a fájlt?');">Törlés</button>
                                                                    </form>
                                                                </div>
                                                            </article>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </section>

                                            <?php if ($fieldGroups === []): ?>
                                                <section class="minicrm-readable-panel">
                                                    <p class="request-admin-empty">Ehhez a tételhez nincs részletes MiniCRM mező eltárolva.</p>
                                                </section>
                                            <?php else: ?>
                                                <form class="minicrm-readable-groups minicrm-field-groups minicrm-edit-form" method="post" action="<?= h(url_path('/admin/minicrm-import') . '?item=' . $itemId . '#minicrm-work-' . $itemId); ?>">
                                                    <?= csrf_field(); ?>
                                                    <input type="hidden" name="action" value="update_minicrm_work_item">
                                                    <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                                    <div class="minicrm-field-edit-actions">
                                                        <div>
                                                            <strong>MiniCRM mezők szerkesztése</strong>
                                                            <span>Minden látható importált mező menthető. A lista adatai is frissülnek.</span>
                                                        </div>
                                                        <button class="button button-primary" type="submit">Mezők mentése</button>
                                                    </div>
                                                    <?php $groupIndex = 0; ?>
                                                    <?php foreach ($fieldGroups as $group): ?>
                                                        <details class="minicrm-field-group" <?= $groupIndex < 3 ? 'open' : ''; ?>>
                                                            <summary>
                                                                <strong><?= h((string) $group['title']); ?></strong>
                                                                <span><?= count($group['fields']); ?> mező</span>
                                                            </summary>
                                                            <div class="minicrm-readable-grid">
                                                                <?php foreach ($group['fields'] as $rawField): ?>
                                                                    <?php
                                                                    $fieldIndex = (int) ($rawField['index'] ?? 0);
                                                                    if ($fieldIndex <= 0) {
                                                                        continue;
                                                                    }
                                                                    $rawValue = (string) $rawField['value'];
                                                                    $rawKey = minicrm_import_key((string) $rawField['label']);
                                                                    $rawIsUrl = str_starts_with($rawValue, 'http://') || str_starts_with($rawValue, 'https://');
                                                                    $useTextarea = $rawIsUrl || strlen($rawValue) > 90 || preg_match('/(megjegyzes|uzenet|szoveg|link|dokumentum|foto|feltoltes|leiras)/', $rawKey);
                                                                    $textareaRows = max(2, min(6, (int) ceil(max(1, strlen($rawValue)) / 90)));
                                                                    $fieldDomId = 'minicrm-field-' . $itemId . '-' . $fieldIndex;
                                                                    ?>
                                                                    <article class="minicrm-readable-row minicrm-editable-row">
                                                                        <label for="<?= h($fieldDomId); ?>"><span><?= h((string) $rawField['label']); ?></span></label>
                                                                        <?php if ($useTextarea): ?>
                                                                            <textarea id="<?= h($fieldDomId); ?>" name="minicrm_fields[<?= $fieldIndex; ?>]" rows="<?= $textareaRows; ?>"><?= h($rawValue); ?></textarea>
                                                                        <?php else: ?>
                                                                            <input id="<?= h($fieldDomId); ?>" type="text" name="minicrm_fields[<?= $fieldIndex; ?>]" value="<?= h($rawValue); ?>">
                                                                        <?php endif; ?>
                                                                        <?php if ($rawIsUrl): ?>
                                                                            <a href="<?= h($rawValue); ?>" target="_blank" rel="noopener">Link megnyitása</a>
                                                                        <?php endif; ?>
                                                                    </article>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </details>
                                                        <?php $groupIndex++; ?>
                                                    <?php endforeach; ?>
                                                    <div class="minicrm-field-edit-actions minicrm-field-edit-actions-bottom">
                                                        <span>A mentéssel az adatlap neve, státusza, felelőse és címe is újraszámolódik.</span>
                                                        <button class="button button-primary" type="submit">Mezők mentése</button>
                                                    </div>
                                                </form>
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

                        <?php $portalGroupIndex = 0; ?>
                        <?php foreach ($standaloneRequestsByWorkflowStage as $workflowStage => $requestItems): ?>
                            <?php
                            $requestGroupDefinition = $workflowStages[$workflowStage] ?? null;
                            $requestGroupName = $requestGroupDefinition !== null ? (string) $requestGroupDefinition['title'] : admin_workflow_stage_label((string) $workflowStage);
                            $requestGroupClass = (string) ($requestGroupDefinition['variant'] ?? 'finalized');
                            $portalGroupDomId = minicrm_import_portal_group_dom_id((string) $workflowStage, $portalGroupIndex);
                            ?>
                        <section class="minicrm-status-group" id="<?= h($portalGroupDomId); ?>" data-minicrm-status-group>
                            <header class="minicrm-status-group-head">
                                <div>
                                    <span class="status-badge status-badge-<?= h($requestGroupClass); ?>"><?= h($requestGroupName); ?></span>
                                    <strong><?= count($requestItems); ?> port&#225;los munka</strong>
                                </div>
                                <span data-minicrm-status-count><?= count($requestItems); ?> l&#225;that&#243;</span>
                            </header>

                                <div class="minicrm-work-table" role="table" aria-label="<?= h((string) $requestGroupName); ?> port&#225;los munk&#225;k">
                                    <div class="minicrm-work-table-head" role="row">
                                        <span>Ügyfél / cím / munka</span>
                                        <span>Szerel&#337;</span>
                                        <span>D&#225;tum</span>
                                        <span>Állapot</span>
                                        <span>Művelet</span>
                                    </div>
                                    <?php foreach ($requestItems as $request): ?>
                                        <?php
                                        $requestId = (int) ($request['id'] ?? 0);
                                        $isSelectedRequest = $requestId === $selectedRequestId;
                                        $requestStatus = (string) ($request['request_status'] ?? 'finalized');
                                        $electricianStatus = (string) ($request['electrician_status'] ?? 'unassigned');
                                        $requestProjectName = trim((string) ($request['project_name'] ?? ''));
                                        $requestTitle = $requestProjectName !== '' ? $requestProjectName : connection_request_type_label($request['request_type'] ?? null);
                                        $requestCustomerName = trim((string) ($request['requester_name'] ?? '')) ?: '-';
                                        $requestSubmitterLabel = connection_request_submitter_label($request);
                                        $requestSitePostalCode = trim((string) ($request['site_postal_code'] ?? ''));
                                        $requestSiteAddressOnly = trim((string) ($request['site_address'] ?? ''));
                                        $requestSiteAddress = connection_request_join_postal_address($requestSitePostalCode, $requestSiteAddressOnly);
                                        $requestLotNumber = trim((string) ($request['hrsz'] ?? ''));
                                        $requestMeterSerial = trim((string) ($request['meter_serial'] ?? ''));
                                        $requestDetailUrl = url_path('/admin/minicrm-import') . '?request=' . $requestId . '#portal-work-' . $requestId;
                                        $requestQuotes = $isSelectedRequest ? quotes_for_connection_request($requestId) : [];
                                        $requestFiles = $isSelectedRequest ? connection_request_files($requestId) : [];
                                        $requestWorkFiles = $isSelectedRequest ? connection_request_work_files($requestId) : [];
                                        $requestDocuments = $isSelectedRequest ? connection_request_documents($requestId) : [];
                                        $requestEmailThreads = $isSelectedRequest ? mvm_email_threads_with_messages($requestId) : [];
                                        $requestQuoteEmailCount = 0;
                                        foreach ($requestQuotes as $quoteForEmailCount) {
                                            $quoteForEmailCountId = (int) ($quoteForEmailCount['id'] ?? 0);
                                            if (trim((string) ($quoteForEmailCount['sent_at'] ?? '')) !== '' || ($quoteForEmailCountId > 0 && quote_latest_email_log($quoteForEmailCountId, 'sent') !== null)) {
                                                $requestQuoteEmailCount++;
                                            }
                                        }
                                        $requestTimelineEvents = $isSelectedRequest ? connection_request_timeline_events($request) : [];
                                        $requestAcceptedQuote = $isSelectedRequest ? accepted_quote_for_connection_request($requestId) : null;
                                        $requestWorkflowStage = $isSelectedRequest
                                            ? connection_request_admin_workflow_stage($request, $requestQuotes[0] ?? null, $requestAcceptedQuote, $requestDocuments)
                                            : (string) $workflowStage;
                                        $requestInitialDataEditable = $isSelectedRequest
                                            ? connection_request_initial_data_is_editable($request, $requestQuotes[0] ?? null, $requestAcceptedQuote, $requestDocuments)
                                            : admin_workflow_stage_number($requestWorkflowStage) < admin_workflow_stage_number('in_progress');
                                        $requestWorkflowDefinition = $workflowStages[$requestWorkflowStage] ?? null;
                                        $requestWorkflowLabel = $requestWorkflowDefinition !== null ? (string) $requestWorkflowDefinition['title'] : admin_workflow_stage_label($requestWorkflowStage);
                                        $requestProfile = $customerProfilesByRequest[$requestId] ?? null;
                                        $profileEmail = is_array($requestProfile) ? minicrm_customer_profile_display_value($requestProfile, 'person_email', ['Szemely1 Email', 'Személy1: Email']) : '';
                                        $profilePhone = is_array($requestProfile) ? minicrm_customer_profile_display_value($requestProfile, 'person_phone', ['Szemely1 Telefon', 'Személy1: Telefon']) : '';
                                        $savedCustomerEmail = trim((string) ($request['email'] ?? ''));
                                        $savedCustomerPhone = trim((string) ($request['phone'] ?? ''));
                                        $displayEmail = $savedCustomerEmail !== '' ? $savedCustomerEmail : $profileEmail;
                                        $displayPhone = $savedCustomerPhone !== '' ? $savedCustomerPhone : $profilePhone;
                                        $requestWorkNote = trim((string) ($request['work_note'] ?? ''));
                                        $requestContactLine = trim(implode(' · ', array_filter([$displayEmail, $displayPhone], static fn (string $value): bool => $value !== '')));
                                        $requestSearchText = implode(' ', [
                                            $requestTitle,
                                            $requestCustomerName,
                                            $displayEmail,
                                            $displayPhone,
                                            (string) ($request['mvm_uk_number'] ?? ''),
                                            $requestWorkNote,
                                            $requestSiteAddress,
                                            (string) ($request['electrician_name'] ?? ''),
                                            $requestSubmitterLabel,
                                            $requestWorkflowLabel,
                                            $requestStatusLabels[$requestStatus] ?? $requestStatus,
                                            $electricianStatusLabels[$electricianStatus] ?? $electricianStatus,
                                        ]);
                                        ?>
                                        <details class="admin-workflow-request minicrm-work-row portal-work-row" id="portal-work-<?= $requestId; ?>" data-minicrm-item data-minicrm-search-text="<?= h($requestSearchText); ?>" data-minicrm-loaded="<?= $isSelectedRequest ? '1' : '0'; ?>" data-minicrm-detail-url="<?= h($requestDetailUrl); ?>" <?= $isSelectedRequest ? 'open' : ''; ?>>
                                            <summary class="admin-workflow-request-summary minicrm-work-row-summary">
                                                <span class="admin-workflow-request-main">
                                                    <strong><?= h($requestCustomerName); ?></strong>
                                                    <small><?= h($requestSiteAddress !== '' ? $requestSiteAddress : '-'); ?></small>
                                                    <small class="portal-work-type"><?= h($requestTitle); ?></small>
                                                </span>
                                                <span class="admin-workflow-request-meta">
                                                    <strong><?= h((string) ($request['electrician_name'] ?? 'Nincs szerelő')); ?></strong>
                                                    <span><?= h($requestContactLine !== '' ? $requestContactLine : '-'); ?></span>
                                                </span>
                                                <span class="minicrm-work-date"><?= h((string) ($request['created_at'] ?? '-')); ?></span>
                                                <span class="admin-workflow-request-badges">
                                                    <strong><?= h($requestWorkflowLabel); ?></strong>
                                                    <small><?= h(($requestStatusLabels[$requestStatus] ?? $requestStatus) . ' / ' . ($electricianStatusLabels[$electricianStatus] ?? $electricianStatus)); ?></small>
                                                </span>
                                                <span class="minicrm-row-actions" onclick="event.stopPropagation();">
                                                    <form method="post" action="<?= h(url_path('/admin/minicrm-import') . '#portal-works'); ?>" onsubmit="event.stopPropagation(); return confirm('Biztosan <?= $showArchived ? 'visszaállítod az archívumból' : 'archiválod'; ?> ezt az adatlapot?');">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="action" value="archive_portal_work_request">
                                                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                        <input type="hidden" name="archive_state" value="<?= $showArchived ? 'restore' : 'archive'; ?>">
                                                        <button class="table-action-button" type="submit"><?= $showArchived ? 'Visszaállítás' : 'Archiválás'; ?></button>
                                                    </form>
                                                    <?php if (can_view_super_admin_overview()): ?>
                                                        <form method="post" action="<?= h(url_path('/admin/minicrm-import') . ($showArchived ? '?show_archived=1' : '') . '#portal-works'); ?>" onsubmit="event.stopPropagation(); return confirm('Biztosan végleg törlöd ezt az adatlapot?') && confirm('A törlés a képeket, dokumentumokat, ajánlatokat és email szálakat is törli. Folytatod?');">
                                                            <?= csrf_field(); ?>
                                                            <input type="hidden" name="action" value="delete_portal_work_request">
                                                            <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                            <input type="hidden" name="delete_confirmation" value="TORLES">
                                                            <input type="hidden" name="return_to_archived" value="<?= $showArchived ? '1' : '0'; ?>">
                                                            <button class="table-action-button table-action-danger" type="submit">Törlés</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </span>
                                            </summary>

                                            <?php if (!$isSelectedRequest): ?>
                                                <div class="minicrm-work-card minicrm-work-card-placeholder">
                                                    <p class="request-admin-empty">Az adatlap megnyit&#225;s&#225;hoz kattints a sorra; a r&#233;szletek k&#252;l&#246;n t&#246;lt&#337;dnek be, hogy a lista gyors maradjon.</p>
                                                    <a class="button button-secondary" href="<?= h($requestDetailUrl); ?>">Adatlap megnyit&#225;sa</a>
                                                </div>
                                            <?php else: ?>
                                                <article class="request-admin-card minicrm-work-card">
                                                    <div class="request-admin-card-head">
                                                        <div>
                                                            <span class="portal-kicker">Port&#225;l munka #<?= $requestId; ?></span>
                                                            <h2><?= h($requestTitle); ?></h2>
                                                            <p><?= h($requestSiteAddress !== '' ? $requestSiteAddress : $requestCustomerName); ?></p>
                                                        </div>
                                                        <div class="request-admin-status">
                                                            <?php if ($requestWorkflowDefinition !== null): ?><span class="status-badge status-badge-<?= h((string) ($requestWorkflowDefinition['variant'] ?? 'draft')); ?>"><?= h((string) $requestWorkflowDefinition['title']); ?></span><?php endif; ?>
                                                            <span class="status-badge status-badge-<?= h($requestStatus); ?>"><?= h($requestStatusLabels[$requestStatus] ?? $requestStatus); ?></span>
                                                            <span class="status-badge status-badge-<?= h($electricianStatus); ?>"><?= h($electricianStatusLabels[$electricianStatus] ?? $electricianStatus); ?></span>
                                                            <a class="button" href="<?= h(url_path('/quick-quote') . '?request_id=' . $requestId); ?>">Aj&#225;nlat</a>
                                                            <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests/mvm-documents') . '?id=' . $requestId); ?>">MVM dokumentumok</a>
                                                        </div>
                                                    </div>

                                                    <div class="minicrm-work-detail-layout">
                                                        <aside class="minicrm-work-facts">
                                                            <form class="portal-work-details-form" method="post" action="<?= h($requestDetailUrl); ?>">
                                                                <?= csrf_field(); ?>
                                                                <input type="hidden" name="action" value="update_portal_work_details">
                                                                <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                <dl>
                                                                    <div>
                                                                        <dt><label for="portal_project_name_<?= $requestId; ?>">Adatlap neve</label></dt>
                                                                        <dd><input id="portal_project_name_<?= $requestId; ?>" name="project_name" value="<?= h($requestProjectName); ?>" placeholder="Automatikus n&#233;v"></dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt><label for="portal_requester_name_<?= $requestId; ?>">&#220;gyf&#233;l</label></dt>
                                                                        <dd><input id="portal_requester_name_<?= $requestId; ?>" name="requester_name" value="<?= h($requestCustomerName !== '-' ? $requestCustomerName : ''); ?>" required></dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt><label for="portal_customer_email_<?= $requestId; ?>">Email</label></dt>
                                                                        <dd><input id="portal_customer_email_<?= $requestId; ?>" name="customer_email" type="email" autocomplete="email" value="<?= h($displayEmail); ?>" placeholder="ugyfel@email.hu" required></dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt><label for="portal_customer_phone_<?= $requestId; ?>">Telefon</label></dt>
                                                                        <dd><input id="portal_customer_phone_<?= $requestId; ?>" name="phone" type="tel" autocomplete="tel" value="<?= h($displayPhone); ?>"></dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt><label for="portal_mvm_uk_number_<?= $requestId; ?>">&#220;K sz&#225;m</label></dt>
                                                                        <dd><input id="portal_mvm_uk_number_<?= $requestId; ?>" name="mvm_uk_number" value="<?= h((string) ($request['mvm_uk_number'] ?? '')); ?>" placeholder="MVM &#220;K sz&#225;m"></dd>
                                                                    </div>
                                                                    <div class="admin-request-data-wide">
                                                                        <dt>Munka megjegyz&#233;s</dt>
                                                                        <dd><?= h($requestWorkNote !== '' ? $requestWorkNote : '-'); ?></dd>
                                                                    </div>
                                                                    <div><dt>Szerel&#337;</dt><dd><?= h((string) ($request['electrician_name'] ?? '-')); ?></dd></div>
                                                                    <div><dt>R&#246;gz&#237;tette</dt><dd><?= h($requestSubmitterLabel); ?></dd></div>
                                                                    <div>
                                                                        <dt><label for="portal_site_postal_code_<?= $requestId; ?>">Ir&#225;ny&#237;t&#243;sz&#225;m</label></dt>
                                                                        <dd><input id="portal_site_postal_code_<?= $requestId; ?>" name="site_postal_code" value="<?= h($requestSitePostalCode); ?>" inputmode="numeric"></dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt><label for="portal_site_address_<?= $requestId; ?>">C&#237;m</label></dt>
                                                                        <dd><input id="portal_site_address_<?= $requestId; ?>" name="site_address" value="<?= h($requestSiteAddressOnly); ?>"></dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt><label for="portal_hrsz_<?= $requestId; ?>">HRSZ</label></dt>
                                                                        <dd><input id="portal_hrsz_<?= $requestId; ?>" name="hrsz" value="<?= h($requestLotNumber); ?>"></dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt><label for="portal_request_type_<?= $requestId; ?>">Munka t&#237;pusa</label></dt>
                                                                        <dd>
                                                                            <select id="portal_request_type_<?= $requestId; ?>" name="request_type">
                                                                                <?php foreach (connection_request_type_options() as $typeKey => $typeLabel): ?>
                                                                                    <option value="<?= h((string) $typeKey); ?>" <?= (string) ($request['request_type'] ?? '') === (string) $typeKey ? 'selected' : ''; ?>><?= h($typeLabel); ?></option>
                                                                                <?php endforeach; ?>
                                                                            </select>
                                                                        </dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt><label for="portal_meter_serial_<?= $requestId; ?>">M&#233;r&#337;</label></dt>
                                                                        <dd><input id="portal_meter_serial_<?= $requestId; ?>" name="meter_serial" value="<?= h($requestMeterSerial); ?>"></dd>
                                                                    </div>
                                                                    <div><dt>R&#246;gz&#237;tve</dt><dd><?= h((string) ($request['created_at'] ?? '-')); ?></dd></div>
                                                                </dl>
                                                                <button class="button button-secondary" type="submit">Adatok ment&#233;se</button>
                                                            </form>

                                                            <section class="minicrm-compact-docs portal-assignment-panel">
                                                                <h3>Munka megjegyz&#233;s</h3>
                                                                <form class="portal-assignment-form" method="post" action="<?= h($requestDetailUrl); ?>">
                                                                    <?= csrf_field(); ?>
                                                                    <input type="hidden" name="action" value="save_portal_work_note">
                                                                    <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                    <textarea name="work_note" rows="4" placeholder="Megjegyz&#233;s a munk&#225;hoz"><?= h($requestWorkNote); ?></textarea>
                                                                    <button class="button button-secondary" type="submit">Megjegyz&#233;s ment&#233;se</button>
                                                                </form>
                                                            </section>

                                                            <section class="minicrm-compact-docs portal-assignment-panel">
                                                                <h3>Szerel&#337;h&#246;z rendel&#233;s</h3>
                                                                <p class="muted-text">Itt lehet egy&#233;rtelm&#369;en kiadni vagy visszavenni ezt a munk&#225;t a szerel&#337;i fel&#252;letr&#337;l.</p>
                                                                <?php if ($electricianSchemaErrors !== []): ?>
                                                                    <p class="request-admin-empty">A szerel&#337;i kioszt&#225;shoz futtasd le a database/electrician_workflow.sql f&#225;jlt.</p>
                                                                <?php elseif ($electricians === []): ?>
                                                                    <p class="request-admin-empty">Nincs akt&#237;v szerel&#337;i fi&#243;k. El&#337;bb hozz l&#233;tre szerel&#337;t a Szerel&#337;k men&#252;ben.</p>
                                                                <?php else: ?>
                                                                    <form class="portal-assignment-form" method="post" action="<?= h($requestDetailUrl); ?>">
                                                                        <?= csrf_field(); ?>
                                                                        <input type="hidden" name="action" value="assign_portal_work_electrician">
                                                                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                        <label for="portal_electrician_<?= $requestId; ?>">Szerel&#337;</label>
                                                                        <select id="portal_electrician_<?= $requestId; ?>" name="electrician_user_id">
                                                                            <option value="">Nincs szerel&#337;nek kiadva</option>
                                                                            <?php foreach ($electricians as $electrician): ?>
                                                                                <?php $electricianUserId = (int) ($electrician['user_id'] ?? 0); ?>
                                                                                <option value="<?= $electricianUserId; ?>" <?= (int) ($request['assigned_electrician_user_id'] ?? 0) === $electricianUserId ? 'selected' : ''; ?>>
                                                                                    <?= h((string) ($electrician['name'] ?? $electrician['user_name'] ?? $electrician['user_email'] ?? ('#' . $electricianUserId))); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                        <button class="button" type="submit">Szerel&#337; ment&#233;se</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </section>

                                                            <section class="minicrm-compact-docs portal-assignment-panel workflow-stage-panel">
                                                                <h3>Munkafolyamat</h3>
                                                                <?php if ($requestWorkflowDefinition !== null): ?>
                                                                    <p class="muted-text">
                                                                        <strong><?= (int) $requestWorkflowDefinition['number']; ?>. <?= h((string) $requestWorkflowDefinition['title']); ?></strong><br>
                                                                        <?= h((string) $requestWorkflowDefinition['description']); ?>
                                                                    </p>
                                                                    <form class="portal-assignment-form" method="post" action="<?= h($requestDetailUrl); ?>" onsubmit="return confirm('Biztosan mented a kiválasztott munkafolyamat státuszt?');">
                                                                        <?= csrf_field(); ?>
                                                                        <input type="hidden" name="action" value="close_portal_workflow_stage">
                                                                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                        <label for="workflow_stage_request_<?= $requestId; ?>">Új státusz</label>
                                                                        <select id="workflow_stage_request_<?= $requestId; ?>" name="target_stage" required>
                                                                            <?php foreach ($workflowStages as $stageKey => $stageDefinition): ?>
                                                                                <option value="<?= h((string) $stageKey); ?>" <?= $requestWorkflowStage === (string) $stageKey ? 'selected' : ''; ?>>
                                                                                    <?= (int) $stageDefinition['number']; ?>. <?= h((string) $stageDefinition['title']); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                            <option value="__auto__">Automatikus állapot visszaállítása</option>
                                                                        </select>
                                                                        <label class="checkbox-row"><input type="checkbox" name="notify_customer" value="1"><span>Ügyfél tájékoztatása emailben</span></label>
                                                                        <label class="checkbox-row"><input type="checkbox" name="notify_responsible" value="1"><span>Adatlap felelőse / szerelő tájékoztatása emailben</span></label>
                                                                        <button class="button" type="submit">Státusz mentése</button>
                                                                        <small>Email csak akkor megy ki, ha külön bepipálod.</small>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <p class="request-admin-empty">A munkafolyamat státusza nem olvasható.</p>
                                                                <?php endif; ?>
                                                            </section>

                                                            <?php if (can_view_super_admin_overview()): ?>
                                                                <section class="minicrm-compact-docs portal-assignment-panel customer-crm-danger">
                                                                    <h3>Szuperadmin törlés</h3>
                                                                    <p class="muted-text">Téves vagy feleslegessé vált adatlap törlése a kapcsolódó ajánlatokkal, fájlokkal, MVM dokumentumokkal, email szálakkal és naplókkal együtt. Az ügyfél törzsadata külön megmarad.</p>
                                                                    <form class="portal-assignment-form" method="post" action="<?= h($requestDetailUrl); ?>" onsubmit="return confirm('Biztosan törlöd ezt az adatlapot és minden kapcsolódó adatát?') && confirm('Második megerősítés: ez nem visszavonható. Folytatod?');">
                                                                        <?= csrf_field(); ?>
                                                                        <input type="hidden" name="action" value="delete_portal_work_request">
                                                                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                        <input type="hidden" name="return_to_archived" value="<?= $showArchived ? '1' : '0'; ?>">
                                                                        <label for="delete_confirmation_request_<?= $requestId; ?>">Megerősítés: TORLES</label>
                                                                        <input id="delete_confirmation_request_<?= $requestId; ?>" name="delete_confirmation" placeholder="TORLES" autocomplete="off" required>
                                                                        <button class="table-action-button table-action-danger" type="submit">Adatlap törlése</button>
                                                                    </form>
                                                                </section>
                                                            <?php endif; ?>
                                                        </aside>

                                                        <div class="minicrm-work-main">
                                                            <section class="minicrm-document-preview-panel communication-panel">
                                                                <div class="admin-request-section-title">
                                                                    <h3>Kommunikáció</h3>
                                                                    <span><?= count($requestEmailThreads); ?> email szál</span>
                                                                    <span><?= $requestQuoteEmailCount; ?> aj&#225;nlat email</span>
                                                                </div>
                                                                <p class="muted-text">A kiküldött ügyfél és felelős üzenetek tárgyában válaszazonosító van. Ha a központi postafiókra válasz érkezik, a szinkron ehhez az adatlaphoz kapcsolja.</p>

                                                                <?php if ($requestQuotes !== []): ?>
                                                                    <div class="quote-communication-list">
                                                                        <?php foreach ($requestQuotes as $quoteCommunication): ?>
                                                                            <?php
                                                                            $quoteCommunicationId = (int) ($quoteCommunication['id'] ?? 0);
                                                                            $quoteCommunicationEmailLog = $quoteCommunicationId > 0 ? quote_latest_email_log($quoteCommunicationId, 'sent') : null;
                                                                            $quoteCommunicationSentAt = trim((string) ($quoteCommunicationEmailLog['created_at'] ?? '')) ?: trim((string) ($quoteCommunication['sent_at'] ?? ''));
                                                                            $quoteCommunicationOpenedAt = trim((string) ($quoteCommunication['email_opened_at'] ?? ''));
                                                                            $quoteCommunicationViewedAt = trim((string) ($quoteCommunication['viewed_at'] ?? ''));
                                                                            ?>
                                                                            <article class="quote-communication-card">
                                                                                <strong><?= h((string) ($quoteCommunication['quote_number'] ?? ('#' . $quoteCommunicationId))); ?></strong>
                                                                                <span>Email elk&#252;ldve: <?= h($quoteCommunicationSentAt !== '' ? $quoteCommunicationSentAt : 'még nem'); ?></span>
                                                                                <span>Email megnyitva: <?= h($quoteCommunicationOpenedAt !== '' ? $quoteCommunicationOpenedAt : 'még nincs jel'); ?><?= h(quote_engagement_count_label($quoteCommunication['email_open_count'] ?? 0)); ?></span>
                                                                                <span>Aj&#225;nlatoldal megnyitva: <?= h($quoteCommunicationViewedAt !== '' ? $quoteCommunicationViewedAt : 'még nem'); ?><?= h(quote_engagement_count_label($quoteCommunication['view_count'] ?? 0)); ?></span>
                                                                            </article>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>

                                                                <form class="portal-message-form" method="post" action="<?= h($requestDetailUrl); ?>">
                                                                    <?= csrf_field(); ?>
                                                                    <input type="hidden" name="action" value="send_portal_work_message">
                                                                    <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                    <div class="form-grid two compact">
                                                                        <div>
                                                                            <label for="message_recipient_<?= $requestId; ?>">Címzett</label>
                                                                            <select id="message_recipient_<?= $requestId; ?>" name="message_recipient" required>
                                                                                <option value="responsible">Adatlap felelőse<?= !empty($request['electrician_name']) ? ' - ' . h((string) $request['electrician_name']) : ''; ?></option>
                                                                                <option value="customer">Ügyfél<?= $displayEmail !== '' ? ' - ' . h($displayEmail) : ''; ?></option>
                                                                            </select>
                                                                        </div>
                                                                        <div>
                                                                            <label for="message_subject_<?= $requestId; ?>">Tárgy</label>
                                                                            <input id="message_subject_<?= $requestId; ?>" name="message_subject" value="<?= h(APP_NAME . ' üzenet - ' . $requestTitle); ?>">
                                                                        </div>
                                                                    </div>
                                                                    <div class="form-grid two compact">
                                                                        <div>
                                                                            <label for="customer_recipient_email_<?= $requestId; ?>">Ügyfél email címzett</label>
                                                                            <input id="customer_recipient_email_<?= $requestId; ?>" name="customer_recipient_email" type="email" inputmode="email" autocomplete="email" value="<?= h($displayEmail); ?>">
                                                                        </div>
                                                                        <div>
                                                                            <label for="customer_recipient_name_<?= $requestId; ?>">Ügyfél címzett neve</label>
                                                                            <input id="customer_recipient_name_<?= $requestId; ?>" name="customer_recipient_name" value="<?= h($requestCustomerName); ?>">
                                                                        </div>
                                                                    </div>
                                                                    <label for="message_body_<?= $requestId; ?>">Üzenet</label>
                                                                    <textarea id="message_body_<?= $requestId; ?>" name="message_body" rows="4" required></textarea>
                                                                    <div class="form-actions">
                                                                        <button class="button" type="submit">Üzenet küldése</button>
                                                                    </div>
                                                                </form>

                                                                <div class="portal-mail-auto-sync-note">
                                                                    <strong>Válaszok automatikusan</strong>
                                                                    <span>Az ügyfél válasza a <?= h(mvm_mail_reply_address()); ?> postafiókra érkezik, és az emailben lévő azonosító alapján erre az adatlapra kerül.</span>
                                                                </div>

                                                                <form class="inline-form portal-mail-sync-form" method="post" action="<?= h($requestDetailUrl); ?>">
                                                                    <?= csrf_field(); ?>
                                                                    <input type="hidden" name="action" value="sync_portal_work_mailbox">
                                                                    <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                    <button class="button button-secondary" type="submit">Válaszok frissítése most</button>
                                                                </form>

                                                                <?php if (!mvm_mailbox_sync_can_run()): ?>
                                                                    <div class="alert alert-info"><p><?= h(mvm_mailbox_sync_setup_message()); ?></p></div>
                                                                <?php endif; ?>

                                                                <?php if ($requestEmailThreads !== []): ?>
                                                                    <div class="mvm-mail-thread-list mvm-mail-thread-list-compact">
                                                                        <?php foreach ($requestEmailThreads as $thread): ?>
                                                                            <article class="mvm-mail-thread">
                                                                                <div class="mvm-mail-thread-head">
                                                                                    <div>
                                                                                        <span class="portal-kicker"><?= h((string) $thread['token']); ?></span>
                                                                                        <strong><?= h((string) $thread['document_label']); ?></strong>
                                                                                        <p><?= h((string) $thread['subject']); ?></p>
                                                                                    </div>
                                                                                    <span class="status-badge status-badge-<?= h((string) $thread['status']); ?>"><?= h($mvmThreadStatusLabels[$thread['status']] ?? (string) $thread['status']); ?></span>
                                                                                </div>
                                                                                <p><?= h(latest_mvm_email_message_preview($thread)); ?></p>
                                                                            </article>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </section>

                                                            <section class="minicrm-timeline-panel">
                                                                <div class="admin-request-section-title">
                                                                    <h3>Adatlap idővonal</h3>
                                                                    <span><?= count($requestTimelineEvents); ?> esemény</span>
                                                                </div>
                                                                <?php if ($requestTimelineEvents === []): ?>
                                                                    <p class="request-admin-empty">Ehhez az adatlaphoz még nincs naplózott esemény.</p>
                                                                <?php else: ?>
                                                                    <ol class="minicrm-timeline">
                                                                        <?php foreach ($requestTimelineEvents as $event): ?>
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
                                                                <?php endif; ?>
                                                            </section>

                                                            <section class="minicrm-document-preview-panel minicrm-quote-panel">
                                                                <div class="admin-request-section-title">
                                                                    <h3>Aj&#225;nlatok</h3>
                                                                    <span><?= count($requestQuotes); ?> aj&#225;nlat</span>
                                                                </div>
                                                                <div class="form-actions">
                                                                    <a class="button" href="<?= h(url_path('/quick-quote') . '?request_id=' . $requestId); ?>">&#218;j aj&#225;nlat k&#233;sz&#237;t&#233;se</a>
                                                                    <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests/edit') . '?id=' . $requestId); ?>"><?= $requestInitialDataEditable ? 'Adatok szerkeszt&#233;se' : 'Adatok megtekint&#233;se'; ?></a>
                                                                </div>
                                                                <?php if ($requestQuotes === []): ?>
                                                                    <p class="request-admin-empty">Ehhez a munk&#225;hoz m&#233;g nincs aj&#225;nlat.</p>
                                                                <?php else: ?>
                                                                    <div class="quote-mini-list">
                                                                        <?php foreach ($requestQuotes as $quote): ?>
                                                                            <?php
                                                                            $quoteId = (int) $quote['id'];
                                                                            $quoteStatus = (string) ($quote['status'] ?? 'draft');
                                                                            $latestQuoteEmailLog = quote_latest_email_log($quoteId, 'sent');
                                                                            $quoteSentAt = trim((string) ($latestQuoteEmailLog['created_at'] ?? '')) ?: trim((string) ($quote['sent_at'] ?? ''));
                                                                            $quoteEmailOpenedAt = trim((string) ($quote['email_opened_at'] ?? ''));
                                                                            $quoteEmailLastOpenedAt = trim((string) ($quote['email_last_opened_at'] ?? ''));
                                                                            $quoteViewedAt = trim((string) ($quote['viewed_at'] ?? ''));
                                                                            $quoteLastViewedAt = trim((string) ($quote['last_viewed_at'] ?? ''));
                                                                            ?>
                                                                            <article class="quote-mini-card quote-mini-card-with-engagement">
                                                                                <div>
                                                                                    <strong><?= h((string) ($quote['quote_number'] ?? ('#' . $quoteId))); ?></strong>
                                                                                    <span><?= h((string) ($quote['subject'] ?? 'Ajánlat')); ?></span>
                                                                                </div>
                                                                                <div>
                                                                                    <span class="status-badge status-badge-<?= h($quoteStatus); ?>"><?= h($quoteStatusLabels[$quoteStatus] ?? $quoteStatus); ?></span>
                                                                                    <strong><?= h(quote_display_total($quote)); ?></strong>
                                                                                </div>
                                                                                <div class="quote-engagement-list">
                                                                                    <span>Email elküldve: <strong><?= h($quoteSentAt !== '' ? $quoteSentAt : 'még nem'); ?></strong></span>
                                                                                    <span>Email megnyitva: <strong><?= h($quoteEmailOpenedAt !== '' ? $quoteEmailOpenedAt : 'még nincs jel'); ?></strong><?= h(quote_engagement_count_label($quote['email_open_count'] ?? 0)); ?></span>
                                                                                    <?php if ($quoteEmailLastOpenedAt !== '' && $quoteEmailLastOpenedAt !== $quoteEmailOpenedAt): ?>
                                                                                        <span>Utolsó email megnyitás: <strong><?= h($quoteEmailLastOpenedAt); ?></strong></span>
                                                                                    <?php endif; ?>
                                                                                    <span>Ajánlatoldal megnyitva: <strong><?= h($quoteViewedAt !== '' ? $quoteViewedAt : 'még nem'); ?></strong><?= h(quote_engagement_count_label($quote['view_count'] ?? 0)); ?></span>
                                                                                    <?php if ($quoteLastViewedAt !== '' && $quoteLastViewedAt !== $quoteViewedAt): ?>
                                                                                        <span>Utolsó ajánlatoldal megnyitás: <strong><?= h($quoteLastViewedAt); ?></strong></span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                                <div class="inline-link-list">
                                                                                    <a href="<?= h(url_path('/quick-quote') . '?quote_id=' . $quoteId); ?>">Szerkeszt&#233;s</a>
                                                                                    <a href="<?= h(url_path('/quick-quote') . '?quote_id=' . $quoteId); ?>">PDF / email</a>
                                                                                    <?php if (quote_file_is_available($quote)): ?>
                                                                                        <a href="<?= h(url_path('/admin/quotes/file') . '?id=' . $quoteId); ?>" target="_blank">PDF megnyit&#225;sa</a>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            </article>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </section>

                                                            <section class="minicrm-document-preview-panel">
                                                                <div class="admin-request-section-title">
                                                                    <h3>Dokumentumok &#233;s fot&#243;k</h3>
                                                                    <span><?= count($requestFiles) + count($requestWorkFiles) + count($requestDocuments); ?> f&#225;jl</span>
                                                                </div>
                                                                <details class="minicrm-manual-upload-form portal-work-upload-form">
                                                                    <summary>&#218;j fot&#243; vagy dokumentum felt&#246;lt&#233;se</summary>
                                                                    <form class="form" method="post" enctype="multipart/form-data" action="<?= h($requestDetailUrl); ?>">
                                                                        <?= csrf_field(); ?>
                                                                        <input type="hidden" name="action" value="upload_portal_work_files">
                                                                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                        <div class="file-upload-grid">
                                                                            <?php foreach (connection_request_upload_definitions() as $key => $definition): ?>
                                                                                <?php
                                                                                $isImage = ($definition['kind'] ?? '') === 'image';
                                                                                $isHTariffOnly = !empty($definition['h_tariff_required']);
                                                                                $accept = connection_request_upload_accept($definition);

                                                                                if ($isHTariffOnly && (string) ($request['request_type'] ?? '') !== 'h_tariff') {
                                                                                    continue;
                                                                                }
                                                                                ?>
                                                                                <label class="file-upload-item">
                                                                                    <span><?= h((string) $definition['label']); ?></span>
                                                                                    <small><?= ($isHTariffOnly ? connection_request_has_package_file_type($requestId, (string) $key) : connection_request_has_file_type($requestId, (string) $key)) ? 'M&#225;r van ilyen felt&#246;lt&#233;s, de &#250;j f&#225;jlt is hozz&#225;adhatsz.' : ($isHTariffOnly ? 'H tarifa eset&#233;n k&#246;telez&#337;, PDF vagy k&#233;p form&#225;tumban.' : 'Opcion&#225;lis, t&#246;bb f&#225;jl is felt&#246;lthet&#337;.'); ?></small>
                                                                                    <input name="file_<?= h((string) $key); ?>[]" type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?>>
                                                                                </label>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                        <div class="form-actions">
                                                                            <button class="button button-secondary" type="submit">F&#225;jlok ment&#233;se</button>
                                                                        </div>
                                                                    </form>
                                                                </details>
                                                                <?php if ($requestFiles === [] && $requestWorkFiles === [] && $requestDocuments === []): ?>
                                                                    <p class="request-admin-empty">Ehhez a munk&#225;hoz m&#233;g nincs felt&#246;lt&#246;tt f&#225;jl vagy gener&#225;lt MVM dokumentum.</p>
                                                                <?php else: ?>
                                                                    <div class="admin-request-doc-grid">
                                                                        <?php foreach ($requestFiles as $file): ?>
                                                                            <?php
                                                                            $fileUrl = url_path('/admin/connection-requests/file') . '?id=' . (int) $file['id'];
                                                                            $previewKind = portal_file_preview_kind($file);
                                                                            ?>
                                                                            <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
                                                                                <div class="admin-request-doc-thumb">
                                                                                    <?php if ($previewKind === 'image'): ?>
                                                                                        <a href="<?= h($fileUrl); ?>" target="_blank" aria-label="<?= h((string) ($file['label'] ?? 'Fájl')); ?> megnyitása">
                                                                                            <img src="<?= h($fileUrl); ?>" alt="<?= h((string) ($file['label'] ?? 'Fájl')); ?>" width="92" height="92" loading="lazy">
                                                                                        </a>
                                                                                    <?php elseif ($previewKind === 'pdf'): ?>
                                                                                        <iframe src="<?= h($fileUrl); ?>#toolbar=0&navpanes=0" title="<?= h((string) ($file['label'] ?? 'Fájl')); ?>" width="92" height="92" loading="lazy"></iframe>
                                                                                    <?php else: ?>
                                                                                        <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($file)); ?></span></div>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                                <div class="admin-request-doc-meta">
                                                                                    <strong><?= h((string) ($file['label'] ?? 'Fájl')); ?></strong>
                                                                                    <span><?= h((string) ($file['original_name'] ?? '-')); ?></span>
                                                                                    <span>Feltöltő: <?= h(portal_file_uploader_label($file)); ?></span>
                                                                                    <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                                                                    <form method="post" action="<?= h($requestDetailUrl); ?>" onsubmit="return confirm('Biztosan törlöd ezt a fájlt? Ez nem visszavonható.');">
                                                                                        <?= csrf_field(); ?>
                                                                                        <input type="hidden" name="action" value="delete_portal_work_file">
                                                                                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                                        <input type="hidden" name="file_source" value="request_file">
                                                                                        <input type="hidden" name="file_id" value="<?= (int) $file['id']; ?>">
                                                                                        <button class="table-action-button table-action-danger" type="submit">Törlés</button>
                                                                                    </form>
                                                                                </div>
                                                                            </article>
                                                                        <?php endforeach; ?>

                                                                        <?php foreach ($requestWorkFiles as $file): ?>
                                                                            <?php
                                                                            $fileUrl = url_path('/admin/connection-requests/work-file') . '?id=' . (int) $file['id'];
                                                                            $previewKind = portal_file_preview_kind($file);
                                                                            ?>
                                                                            <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
                                                                                <div class="admin-request-doc-thumb">
                                                                                    <?php if ($previewKind === 'image'): ?>
                                                                                        <a href="<?= h($fileUrl); ?>" target="_blank" aria-label="<?= h((string) ($file['label'] ?? 'Munka fájl')); ?> megnyitása">
                                                                                            <img src="<?= h($fileUrl); ?>" alt="<?= h((string) ($file['label'] ?? 'Munka fájl')); ?>" width="92" height="92" loading="lazy">
                                                                                        </a>
                                                                                    <?php elseif ($previewKind === 'pdf'): ?>
                                                                                        <iframe src="<?= h($fileUrl); ?>#toolbar=0&navpanes=0" title="<?= h((string) ($file['label'] ?? 'Munka fájl')); ?>" width="92" height="92" loading="lazy"></iframe>
                                                                                    <?php else: ?>
                                                                                        <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($file)); ?></span></div>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                                <div class="admin-request-doc-meta">
                                                                                    <strong><?= h((string) ($file['label'] ?? 'Munka fájl')); ?></strong>
                                                                                    <span><?= h((string) ($file['original_name'] ?? '-')); ?></span>
                                                                                    <span>Feltöltő: <?= h(portal_file_uploader_label($file)); ?></span>
                                                                                    <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                                                                    <form method="post" action="<?= h($requestDetailUrl); ?>" onsubmit="return confirm('Biztosan törlöd ezt a munka fájlt? Ez nem visszavonható.');">
                                                                                        <?= csrf_field(); ?>
                                                                                        <input type="hidden" name="action" value="delete_portal_work_file">
                                                                                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                                        <input type="hidden" name="file_source" value="work_file">
                                                                                        <input type="hidden" name="file_id" value="<?= (int) $file['id']; ?>">
                                                                                        <button class="table-action-button table-action-danger" type="submit">Törlés</button>
                                                                                    </form>
                                                                                </div>
                                                                            </article>
                                                                        <?php endforeach; ?>

                                                                        <?php foreach ($requestDocuments as $document): ?>
                                                                            <?php
                                                                            $documentUrl = url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $document['id'];
                                                                            $previewKind = portal_file_preview_kind($document);
                                                                            ?>
                                                                            <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
                                                                                <div class="admin-request-doc-thumb">
                                                                                    <?php if ($previewKind === 'image'): ?>
                                                                                        <a href="<?= h($documentUrl); ?>" target="_blank" aria-label="<?= h((string) ($document['title'] ?? 'MVM dokumentum')); ?> megnyitása">
                                                                                            <img src="<?= h($documentUrl); ?>" alt="<?= h((string) ($document['title'] ?? 'MVM dokumentum')); ?>" width="92" height="92" loading="lazy">
                                                                                        </a>
                                                                                    <?php elseif ($previewKind === 'pdf'): ?>
                                                                                        <iframe src="<?= h($documentUrl); ?>#toolbar=0&navpanes=0" title="<?= h((string) ($document['title'] ?? 'MVM dokumentum')); ?>" width="92" height="92" loading="lazy"></iframe>
                                                                                    <?php else: ?>
                                                                                        <div class="admin-request-doc-fallback"><span><?= h(portal_file_preview_extension($document)); ?></span></div>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                                <div class="admin-request-doc-meta">
                                                                                    <strong><?= h((string) ($document['title'] ?? 'MVM dokumentum')); ?></strong>
                                                                                    <span><?= h((string) ($document['original_name'] ?? '-')); ?></span>
                                                                                    <span>Létrehozó: <?= h(portal_file_uploader_label($document, 'Létrehozó ismeretlen')); ?></span>
                                                                                    <a href="<?= h($documentUrl); ?>" target="_blank">Megnyitás</a>
                                                                                    <form method="post" action="<?= h($requestDetailUrl); ?>" onsubmit="return confirm('Biztosan törlöd ezt az MVM dokumentumot? Ez nem visszavonható.');">
                                                                                        <?= csrf_field(); ?>
                                                                                        <input type="hidden" name="action" value="delete_portal_work_file">
                                                                                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">
                                                                                        <input type="hidden" name="file_source" value="mvm_document">
                                                                                        <input type="hidden" name="file_id" value="<?= (int) $document['id']; ?>">
                                                                                        <button class="table-action-button table-action-danger" type="submit">Törlés</button>
                                                                                    </form>
                                                                                </div>
                                                                            </article>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </section>
                                                        </div>
                                                    </div>
                                                </article>
                                            <?php endif; ?>
                                        </details>
                                    <?php endforeach; ?>
                                </div>
                        </section>
                            <?php $portalGroupIndex++; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const panels = Array.from(document.querySelectorAll('[data-minicrm-panel]'));
    const importTools = document.querySelector('.minicrm-import-tools');
    const input = document.querySelector('[data-minicrm-search]');
    const count = document.querySelector('[data-minicrm-count]');
    const items = Array.from(document.querySelectorAll('[data-minicrm-item]'));
    const groups = Array.from(document.querySelectorAll('[data-minicrm-status-group]'));

    items.forEach((item) => {
        item.addEventListener('toggle', () => {
            if (!item.open || item.dataset.minicrmLoaded === '1' || !item.dataset.minicrmDetailUrl) {
                return;
            }

            window.location.href = item.dataset.minicrmDetailUrl;
        });
    });

    panels.forEach((panel) => {
        panel.hidden = panel.dataset.minicrmPanel !== 'works';
    });

    if (importTools) {
        importTools.hidden = true;
    }

    if (!input || !count || items.length === 0) {
        return;
    }

    const searchable = items.map((item) => ({
        item,
        text: `${item.textContent} ${item.dataset.minicrmSearchText || ''}`.toLocaleLowerCase('hu-HU'),
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
            const groupItems = Array.from(group.querySelectorAll('[data-minicrm-item]'));
            const groupVisible = groupItems.filter((item) => !item.hidden).length;
            const groupCount = group.querySelector('[data-minicrm-status-count]');

            group.hidden = groupVisible === 0;

            if (groupCount) {
                groupCount.textContent = `${groupVisible} látható`;
            }
        });

        count.textContent = `${visible} db`;
    });
});
</script>
