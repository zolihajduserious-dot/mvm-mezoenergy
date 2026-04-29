<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$schemaErrors = minicrm_import_schema_errors();
$electricianSchemaErrors = electrician_schema_errors();
$deps = dependency_status();
$flash = get_flash();
$importErrors = [];

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

if (is_post() && ($_POST['action'] ?? '') === 'send_minicrm_quote_fee_request') {
    require_valid_csrf_token();

    $workItemId = max(0, (int) ($_POST['work_item_id'] ?? 0));
    $quoteId = max(0, (int) ($_POST['quote_id'] ?? 0));
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

    $result = send_quote_fee_request_email($quoteId);
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A díjbekérő küldése sikertelen.'));
    redirect('/admin/minicrm-import?item=' . $workItemId . '#minicrm-work-' . $workItemId);
}

if (is_post() && ($_POST['action'] ?? '') === 'install_minicrm_schema') {
    require_valid_csrf_token();

    $result = minicrm_import_install_schema();
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) $result['message']);
    redirect('/admin/minicrm-import');
}

$items = $schemaErrors === [] ? minicrm_work_items(1000) : [];
$standaloneRequests = $schemaErrors === [] ? admin_standalone_connection_request_items(1000) : [];
$electricians = $electricianSchemaErrors === [] ? electrician_users(true) : [];
$batches = $schemaErrors === [] ? minicrm_import_batches(8) : [];
$statusCounts = $schemaErrors === [] ? minicrm_work_item_status_counts() : [];
$customerProfilesBySource = $schemaErrors === [] ? minicrm_customer_profiles_by_source_ids(array_column($items, 'source_id')) : [];
$customerProfilesByRequest = $schemaErrors === [] ? minicrm_customer_profiles_by_connection_request_ids(array_column($standaloneRequests, 'id')) : [];
$quoteStatusLabels = $schemaErrors === [] ? quote_status_labels() : [];
$requestStatusLabels = $schemaErrors === [] ? connection_request_status_labels() : [];
$electricianStatusLabels = $schemaErrors === [] ? electrician_work_status_labels() : [];
$totalItems = count($items);
$totalUnifiedItems = $totalItems + count($standaloneRequests);
$localDocumentFileCount = $schemaErrors === [] ? minicrm_work_item_file_count() : 0;
$localDocumentSizeTotal = $schemaErrors === [] ? minicrm_work_item_file_size_total() : 0;
$documentZipCandidates = minicrm_document_zip_candidates();
$itemsByStatus = [];
$selectedItemId = isset($_GET['item']) ? max(0, (int) $_GET['item']) : 0;
$selectedRequestId = isset($_GET['request']) ? max(0, (int) $_GET['request']) : 0;
$standaloneRequestsByStatus = [];

foreach ($items as $item) {
    if ($selectedItemId === 0 && $selectedRequestId === 0) {
        $selectedItemId = (int) ($item['id'] ?? 0);
    }

    $statusName = trim((string) ($item['minicrm_status'] ?? '')) ?: 'Nincs státusz';
    $itemsByStatus[$statusName][] = $item;
}

uasort($itemsByStatus, static fn (array $a, array $b): int => count($b) <=> count($a));

foreach ($standaloneRequests as $request) {
    if ($selectedItemId === 0 && $selectedRequestId === 0) {
        $selectedRequestId = (int) ($request['id'] ?? 0);
    }

    $statusName = connection_request_type_label($request['request_type'] ?? null);
    $standaloneRequestsByStatus[$statusName][] = $request;
}

uasort($standaloneRequestsByStatus, static fn (array $a, array $b): int => count($b) <=> count($a));

function minicrm_import_dom_id(string $value): string
{
    $id = preg_replace('/[^a-z0-9]+/', '-', minicrm_import_lower($value)) ?: '';
    $id = trim($id, '-');

    return $id !== '' ? $id : 'nincs-statusz';
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

        <nav class="minicrm-module-tabs" aria-label="MiniCRM menü">
            <a class="is-active" href="#minicrm-works" data-minicrm-tab="works">Munkák</a>
            <a href="#minicrm-import-tools" data-minicrm-tab="import">Importálás</a>
            <a href="#minicrm-documents" data-minicrm-tab="documents">Dokumentumok</a>
            <a href="#minicrm-latest-imports" data-minicrm-tab="log">Import napló</a>
        </nav>

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
                <h2>Még nincs importált MiniCRM munka</h2>
                <p>Tölts fel egy MiniCRM Excel exportot, és a munkák itt jelennek meg.</p>
            </div>
        <?php elseif ($items !== [] || $standaloneRequests !== []): ?>
            <div class="admin-workflow-list minicrm-workspace" id="minicrm-works" data-minicrm-panel="works">
                <section class="admin-workflow-stage">
                    <div class="admin-workflow-stage-head minicrm-workspace-head">
                        <div>
                            <span class="portal-kicker">Munkák</span>
                            <h2>MiniCRM munkaállomány</h2>
                            <p>Státuszonként csoportosított, kompakt lista kereséssel és munkán belüli idővonallal.</p>
                        </div>
                        <strong><?= $totalUnifiedItems; ?> db</strong>
                    </div>

                    <div class="minicrm-list-tools">
                        <label for="minicrm_search">Keresés a munkák között</label>
                        <input id="minicrm_search" type="search" placeholder="Név, azonosító, cím, felelős, státusz vagy mezőérték" data-minicrm-search>
                        <span data-minicrm-count><?= $totalUnifiedItems; ?> db</span>
                    </div>

                    <nav class="minicrm-status-nav" aria-label="MiniCRM státuszok">
                        <?php foreach ($itemsByStatus as $statusName => $statusItems): ?>
                            <a href="#minicrm-status-<?= h(minicrm_import_dom_id((string) $statusName)); ?>">
                                <span><?= h((string) $statusName); ?></span>
                                <strong><?= count($statusItems); ?></strong>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($standaloneRequests !== []): ?>
                            <a href="#portal-works">
                                <span>Port&#225;los munk&#225;k</span>
                                <strong><?= count($standaloneRequests); ?></strong>
                            </a>
                        <?php endif; ?>
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

                                <div class="minicrm-work-table" role="table" aria-label="<?= h((string) $statusName); ?> munkák">
                                    <div class="minicrm-work-table-head" role="row">
                                        <span>Munka</span>
                                        <span>Felelős</span>
                                        <span>Dátum</span>
                                        <span>Anyag</span>
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
                            $linkedMvmDocuments = $linkedMvmRequestId !== null ? connection_request_documents($linkedMvmRequestId) : [];
                            $mvmGeneratorUrl = url_path('/admin/minicrm-import/mvm-documents') . '?minicrm_item=' . $itemId;
                            $linkedMiniCrmQuotes = $linkedMvmRequestId !== null ? quotes_for_connection_request($linkedMvmRequestId) : [];
                            $assignedElectricianName = minicrm_work_item_electrician_assignment_name($item);
                            $linkedAssignedElectricianUserId = is_array($linkedMvmRequest) ? (int) ($linkedMvmRequest['assigned_electrician_user_id'] ?? 0) : 0;
                            $quoteCreateUrl = url_path('/admin/quotes/create') . '?minicrm_item=' . $itemId;
                            $customerProfile = $customerProfilesBySource[minicrm_source_id_key((string) ($item['source_id'] ?? ''))] ?? null;
                            if ($customerProfile === null && $isSelectedItem) {
                                $customerProfile = minicrm_customer_profile_for_work_item($item);
                            }
                            $profileName = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_name', ['Szemely1 Nev', 'Személy1: Név', 'Nev', 'Név']) : '';
                            $profileEmail = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_email', ['Szemely1 Email', 'Személy1: Email', 'Ceg Email', 'Cég: Email', 'Email']) : '';
                            $profilePhone = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_phone', ['Szemely1 Telefon', 'Személy1: Telefon', 'Ceg Telefon', 'Cég: Telefon', 'Telefon']) : '';
                            $profileConsent = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_consent', ['Szemely1 Adatkezelesi hozzajarulas', 'Személy1: Adatkezelési hozzájárulás']) : '';
                            $profilePosition = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_position', ['Szemely1 Beosztas', 'Személy1: Beosztás']) : '';
                            $profileWebsite = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_website', ['Szemely1 Weboldal', 'Személy1: Weboldal']) : '';
                            $profileSummary = is_array($customerProfile) ? minicrm_customer_profile_display_value($customerProfile, 'person_summary', ['Szemely1 Osszefoglalo', 'Személy1: Összefoglaló']) : '';
                            $profileContactLine = trim(implode(' · ', array_filter([$profileEmail, $profilePhone], static fn (string $value): bool => $value !== '')));
                            $profileHasContact = $profileEmail !== '' || $profilePhone !== '';
                            $searchText = implode(' ', [
                                (string) ($item['card_name'] ?? ''),
                                (string) ($item['source_id'] ?? ''),
                                (string) ($item['responsible'] ?? ''),
                                (string) ($item['minicrm_status'] ?? ''),
                                $assignedElectricianName,
                                $profileName,
                                $profileEmail,
                                $profilePhone,
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
                                            <span class="status-badge status-badge-<?= h($statusClass); ?>"><?= h((string) ($item['minicrm_status'] ?: 'Nincs státusz')); ?></span>
                                            <?php if (!empty($item['responsible'])): ?><span class="status-badge status-badge-finalized"><?= h((string) $item['responsible']); ?></span><?php endif; ?>
                                            <a class="button" href="<?= h($quoteCreateUrl); ?>">Árajánlat</a>
                                            <a class="button button-secondary" href="<?= h($mvmGeneratorUrl); ?>">MVM dokumentumok</a>
                                        </div>
                                    </div>

                                    <div class="minicrm-work-detail-layout">
                                        <aside class="minicrm-work-facts">
                                            <dl>
                                                <div><dt>Ügyfél</dt><dd><?= h((string) ($item['customer_name'] ?: $item['card_name'] ?: '-')); ?></dd></div>
                                                <div><dt>Email</dt><dd><?= h($profileEmail !== '' ? $profileEmail : 'Nincs importalt email'); ?></dd></div>
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
                                                        <div class="minicrm-readable-row"><span>Email</span><strong><?= h($profileEmail !== '' ? $profileEmail : '-'); ?></strong></div>
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

                                            <section class="minicrm-document-preview-panel minicrm-quote-panel">
                                                <div class="admin-request-section-title">
                                                    <h3>Árajánlatkészítő</h3>
                                                    <span><?= count($linkedMiniCrmQuotes); ?> ajánlat</span>
                                                </div>
                                                <p class="muted-text">A MiniCRM munkából ugyanaz az árajánlatkészítő nyílik meg, mint a normál igényeknél. Az elkészült ajánlat itt visszakereshető, PDF-ként küldhető, és elfogadás után díjbekérő is indítható.</p>
                                                <div class="form-actions">
                                                    <a class="button" href="<?= h($quoteCreateUrl); ?>">Új árajánlat készítése</a>
                                                </div>
                                                <?php if ($linkedMiniCrmQuotes === []): ?>
                                                    <p class="request-admin-empty">Ehhez a MiniCRM munkához még nincs árajánlat.</p>
                                                <?php else: ?>
                                                    <div class="quote-mini-list">
                                                        <?php foreach ($linkedMiniCrmQuotes as $quote): ?>
                                                            <?php
                                                            $quoteId = (int) $quote['id'];
                                                            $quoteStatus = (string) ($quote['status'] ?? 'draft');
                                                            $quoteEditUrl = url_path('/admin/quotes/edit') . '?id=' . $quoteId . '&minicrm_item=' . $itemId;
                                                            $quoteSendUrl = url_path('/admin/quotes/send') . '?id=' . $quoteId . '&minicrm_item=' . $itemId;
                                                            $quoteFileUrl = quote_file_is_available($quote) ? url_path('/admin/quotes/file') . '?id=' . $quoteId : null;
                                                            $feeRequestSelection = quote_fee_request_selection($quoteId);
                                                            $feeRequestLine = is_array($feeRequestSelection['line'] ?? null) ? $feeRequestSelection['line'] : null;
                                                            $feeRequestFileUrl = quote_fee_request_file_is_available($quote) ? url_path('/admin/quotes/fee-request-file') . '?id=' . $quoteId : null;
                                                            $feeRequestBlockedMessage = null;

                                                            if ($quoteStatus !== 'accepted') {
                                                                $feeRequestBlockedMessage = 'Díjbekérő csak elfogadott árajánlatból küldhető.';
                                                            } elseif (!$feeRequestSelection['ok']) {
                                                                $feeRequestBlockedMessage = (string) $feeRequestSelection['message'];
                                                            } elseif ($feeRequestFileUrl !== null) {
                                                                $feeRequestBlockedMessage = 'A díjbekérő már elkészült.';
                                                            } elseif (szamlazz_config_value('SZAMLAZZ_AGENT_KEY') === '') {
                                                                $feeRequestBlockedMessage = 'Nincs beállítva a Számlázz.hu Agent kulcs.';
                                                            }
                                                            ?>
                                                            <article class="quote-mini-card">
                                                                <div>
                                                                    <strong><?= h((string) ($quote['quote_number'] ?? ('#' . $quoteId))); ?></strong>
                                                                    <span><?= h((string) ($quote['subject'] ?? 'Árajánlat')); ?></span>
                                                                    <?php if ($feeRequestLine !== null): ?>
                                                                        <span>Díjbekérő tétel: <?= h((string) $feeRequestLine['name']); ?> · <?= h(format_money($feeRequestLine['line_gross'])); ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div>
                                                                    <span class="status-badge status-badge-<?= h($quoteStatus); ?>"><?= h($quoteStatusLabels[$quoteStatus] ?? $quoteStatus); ?></span>
                                                                    <strong><?= h(quote_display_total($quote)); ?></strong>
                                                                </div>
                                                                <div class="inline-link-list">
                                                                    <a href="<?= h($quoteEditUrl); ?>">Szerkesztés</a>
                                                                    <a href="<?= h($quoteSendUrl); ?>">PDF / email</a>
                                                                    <?php if ($quoteFileUrl !== null): ?>
                                                                        <a href="<?= h($quoteFileUrl); ?>" target="_blank">PDF megnyitása</a>
                                                                    <?php endif; ?>
                                                                    <?php if ($feeRequestFileUrl !== null): ?>
                                                                        <a href="<?= h($feeRequestFileUrl); ?>" target="_blank">Díjbekérő PDF</a>
                                                                    <?php elseif ($feeRequestBlockedMessage === null): ?>
                                                                        <form class="inline-form" method="post" action="<?= h($detailUrl); ?>">
                                                                            <?= csrf_field(); ?>
                                                                            <input type="hidden" name="action" value="send_minicrm_quote_fee_request">
                                                                            <input type="hidden" name="work_item_id" value="<?= $itemId; ?>">
                                                                            <input type="hidden" name="quote_id" value="<?= $quoteId; ?>">
                                                                            <button class="text-button" type="submit">Díjbekérő küldése</button>
                                                                        </form>
                                                                    <?php else: ?>
                                                                        <a href="<?= h($quoteSendUrl); ?>">Díjbekérő</a>
                                                                        <small><?= h($feeRequestBlockedMessage); ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </article>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </section>

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
                    </div>

                    <?php if ($standaloneRequestsByStatus !== []): ?>
                        <section class="minicrm-status-group" id="portal-works" data-minicrm-status-group>
                            <header class="minicrm-status-group-head">
                                <div>
                                    <span class="status-badge status-badge-finalized">Port&#225;lon r&#246;gz&#237;tett munk&#225;k</span>
                                    <strong><?= count($standaloneRequests); ?> munka</strong>
                                </div>
                                <span data-minicrm-status-count><?= count($standaloneRequests); ?> l&#225;that&#243;</span>
                            </header>

                            <?php foreach ($standaloneRequestsByStatus as $requestGroupName => $requestItems): ?>
                                <div class="minicrm-work-table" role="table" aria-label="<?= h((string) $requestGroupName); ?> port&#225;los munk&#225;k">
                                    <div class="minicrm-work-table-head" role="row">
                                        <span>Ügyfél / cím / munka</span>
                                        <span>Szerel&#337;</span>
                                        <span>D&#225;tum</span>
                                        <span>Állapot</span>
                                    </div>
                                    <?php foreach ($requestItems as $request): ?>
                                        <?php
                                        $requestId = (int) ($request['id'] ?? 0);
                                        $isSelectedRequest = $requestId === $selectedRequestId;
                                        $requestStatus = (string) ($request['request_status'] ?? 'finalized');
                                        $electricianStatus = (string) ($request['electrician_status'] ?? 'unassigned');
                                        $requestTitle = trim((string) ($request['project_name'] ?? '')) ?: connection_request_type_label($request['request_type'] ?? null);
                                        $requestCustomerName = trim((string) ($request['requester_name'] ?? '')) ?: '-';
                                        $requestSiteAddress = trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));
                                        $requestDetailUrl = url_path('/admin/minicrm-import') . '?request=' . $requestId . '#portal-work-' . $requestId;
                                        $requestQuotes = $isSelectedRequest ? quotes_for_connection_request($requestId) : [];
                                        $requestFiles = $isSelectedRequest ? connection_request_files($requestId) : [];
                                        $requestWorkFiles = $isSelectedRequest ? connection_request_work_files($requestId) : [];
                                        $requestDocuments = $isSelectedRequest ? connection_request_documents($requestId) : [];
                                        $requestProfile = $customerProfilesByRequest[$requestId] ?? null;
                                        $profileEmail = is_array($requestProfile) ? minicrm_customer_profile_display_value($requestProfile, 'person_email', ['Szemely1 Email', 'Személy1: Email']) : '';
                                        $profilePhone = is_array($requestProfile) ? minicrm_customer_profile_display_value($requestProfile, 'person_phone', ['Szemely1 Telefon', 'Személy1: Telefon']) : '';
                                        $displayEmail = $profileEmail !== '' ? $profileEmail : trim((string) ($request['email'] ?? ''));
                                        $displayPhone = $profilePhone !== '' ? $profilePhone : trim((string) ($request['phone'] ?? ''));
                                        $requestContactLine = trim(implode(' · ', array_filter([$displayEmail, $displayPhone], static fn (string $value): bool => $value !== '')));
                                        $requestSearchText = implode(' ', [
                                            $requestTitle,
                                            $requestCustomerName,
                                            $displayEmail,
                                            $displayPhone,
                                            $requestSiteAddress,
                                            (string) ($request['electrician_name'] ?? ''),
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
                                                    <strong><?= h($requestStatusLabels[$requestStatus] ?? $requestStatus); ?></strong>
                                                    <small><?= h($electricianStatusLabels[$electricianStatus] ?? $electricianStatus); ?></small>
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
                                                            <span class="status-badge status-badge-<?= h($requestStatus); ?>"><?= h($requestStatusLabels[$requestStatus] ?? $requestStatus); ?></span>
                                                            <span class="status-badge status-badge-<?= h($electricianStatus); ?>"><?= h($electricianStatusLabels[$electricianStatus] ?? $electricianStatus); ?></span>
                                                            <a class="button" href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . (int) $request['customer_id'] . '&request_id=' . $requestId); ?>">Aj&#225;nlat</a>
                                                            <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests/mvm-documents') . '?id=' . $requestId); ?>">MVM dokumentumok</a>
                                                        </div>
                                                    </div>

                                                    <div class="minicrm-work-detail-layout">
                                                        <aside class="minicrm-work-facts">
                                                            <dl>
                                                                <div><dt>&#220;gyf&#233;l</dt><dd><?= h($requestCustomerName); ?></dd></div>
                                                                <div><dt>Email</dt><dd><?= h($displayEmail !== '' ? $displayEmail : '-'); ?></dd></div>
                                                                <div><dt>Telefon</dt><dd><?= h($displayPhone !== '' ? $displayPhone : '-'); ?></dd></div>
                                                                <div><dt>Szerel&#337;</dt><dd><?= h((string) ($request['electrician_name'] ?? '-')); ?></dd></div>
                                                                <div><dt>C&#237;m</dt><dd><?= h($requestSiteAddress !== '' ? $requestSiteAddress : '-'); ?></dd></div>
                                                                <div><dt>HRSZ</dt><dd><?= h((string) ($request['lot_number'] ?? '-')); ?></dd></div>
                                                                <div><dt>Munka t&#237;pusa</dt><dd><?= h(connection_request_type_label($request['request_type'] ?? null)); ?></dd></div>
                                                                <div><dt>M&#233;r&#337;</dt><dd><?= h((string) ($request['meter_serial'] ?? '-')); ?></dd></div>
                                                                <div><dt>R&#246;gz&#237;tve</dt><dd><?= h((string) ($request['created_at'] ?? '-')); ?></dd></div>
                                                            </dl>

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
                                                        </aside>

                                                        <div class="minicrm-work-main">
                                                            <section class="minicrm-document-preview-panel minicrm-quote-panel">
                                                                <div class="admin-request-section-title">
                                                                    <h3>Aj&#225;nlatok</h3>
                                                                    <span><?= count($requestQuotes); ?> aj&#225;nlat</span>
                                                                </div>
                                                                <div class="form-actions">
                                                                    <a class="button" href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . (int) $request['customer_id'] . '&request_id=' . $requestId); ?>">&#218;j aj&#225;nlat k&#233;sz&#237;t&#233;se</a>
                                                                    <a class="button button-secondary" href="<?= h(url_path('/admin/connection-requests/edit') . '?id=' . $requestId); ?>">Adatok szerkeszt&#233;se</a>
                                                                </div>
                                                                <?php if ($requestQuotes === []): ?>
                                                                    <p class="request-admin-empty">Ehhez a munk&#225;hoz m&#233;g nincs aj&#225;nlat.</p>
                                                                <?php else: ?>
                                                                    <div class="quote-mini-list">
                                                                        <?php foreach ($requestQuotes as $quote): ?>
                                                                            <?php
                                                                            $quoteId = (int) $quote['id'];
                                                                            $quoteStatus = (string) ($quote['status'] ?? 'draft');
                                                                            ?>
                                                                            <article class="quote-mini-card">
                                                                                <div>
                                                                                    <strong><?= h((string) ($quote['quote_number'] ?? ('#' . $quoteId))); ?></strong>
                                                                                    <span><?= h((string) ($quote['subject'] ?? 'Ajánlat')); ?></span>
                                                                                </div>
                                                                                <div>
                                                                                    <span class="status-badge status-badge-<?= h($quoteStatus); ?>"><?= h($quoteStatusLabels[$quoteStatus] ?? $quoteStatus); ?></span>
                                                                                    <strong><?= h(quote_display_total($quote)); ?></strong>
                                                                                </div>
                                                                                <div class="inline-link-list">
                                                                                    <a href="<?= h(url_path('/admin/quotes/edit') . '?id=' . $quoteId . '&request_id=' . $requestId); ?>">Szerkeszt&#233;s</a>
                                                                                    <a href="<?= h(url_path('/admin/quotes/send') . '?id=' . $quoteId . '&request_id=' . $requestId); ?>">PDF / email</a>
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
                            <?php endforeach; ?>
                        </section>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const tabs = Array.from(document.querySelectorAll('[data-minicrm-tab]'));
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

    const activateTab = (tabName) => {
        tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.minicrmTab === tabName);
        });

        panels.forEach((panel) => {
            panel.hidden = panel.dataset.minicrmPanel !== tabName;
        });

        if (importTools) {
            importTools.hidden = !['import', 'documents'].includes(tabName);
        }
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', (event) => {
            event.preventDefault();
            activateTab(tab.dataset.minicrmTab || 'works');
        });
    });

    activateTab('works');

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
