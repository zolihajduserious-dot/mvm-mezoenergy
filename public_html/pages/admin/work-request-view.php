<?php
declare(strict_types=1);

require_role(['admin']);

$flash = get_flash();

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$requestId = isset($_GET['request']) ? max(0, (int) $_GET['request']) : 0;
$request = null;
$requestFiles = [];
$requestWorkFiles = [];
$requestDocuments = [];
$minicrmFiles = [];
$minicrmDocumentLinks = [];
$errors = [];

function admin_work_request_view_date(mixed $value): string
{
    $timestamp = $value !== null && trim((string) $value) !== '' ? strtotime((string) $value) : false;

    return $timestamp !== false ? date('Y.m.d. H:i', $timestamp) : '-';
}

function admin_work_request_view_value(mixed $value): string
{
    $value = trim((string) $value);

    return $value !== '' ? $value : '-';
}

function admin_work_request_view_column_select(string $column, string $alias): string
{
    return db_column_exists('connection_requests', $column)
        ? 'cr.`' . $column . '` AS `' . $alias . '`'
        : 'NULL AS `' . $alias . '`';
}

function admin_work_request_view_load(int $requestId): ?array
{
    if ($requestId <= 0 || !db_table_exists('connection_requests')) {
        return null;
    }

    $optionalColumns = [
        admin_work_request_view_column_select('hrsz', 'hrsz'),
        admin_work_request_view_column_select('meter_serial', 'meter_serial'),
        admin_work_request_view_column_select('consumption_place_id', 'consumption_place_id'),
        admin_work_request_view_column_select('existing_general_power', 'existing_general_power'),
        admin_work_request_view_column_select('requested_general_power', 'requested_general_power'),
        admin_work_request_view_column_select('existing_h_tariff_power', 'existing_h_tariff_power'),
        admin_work_request_view_column_select('requested_h_tariff_power', 'requested_h_tariff_power'),
        admin_work_request_view_column_select('existing_controlled_power', 'existing_controlled_power'),
        admin_work_request_view_column_select('requested_controlled_power', 'requested_controlled_power'),
        admin_work_request_view_column_select('notes', 'notes'),
        admin_work_request_view_column_select('submitted_at', 'submitted_at'),
        admin_work_request_view_column_select('closed_at', 'closed_at'),
        admin_work_request_view_column_select('mvm_uk_number', 'mvm_uk_number'),
        admin_work_request_view_column_select('work_note', 'work_note'),
        admin_work_request_view_column_select('source', 'request_source'),
        admin_work_request_view_column_select('admin_workflow_stage', 'admin_workflow_stage'),
    ];

    $customerSelect = db_table_exists('customers')
        ? 'c.`id` AS `customer_row_id`, c.`requester_name` AS `customer_name`, c.`email` AS `customer_email`, c.`phone` AS `customer_phone`, c.`source` AS `customer_source`, c.`status` AS `customer_status`'
        : 'NULL AS `customer_row_id`, NULL AS `customer_name`, NULL AS `customer_email`, NULL AS `customer_phone`, NULL AS `customer_source`, NULL AS `customer_status`';
    $customerJoin = db_table_exists('customers')
        ? 'LEFT JOIN `customers` c ON c.`id` = cr.`customer_id`'
        : '';

    $sql = 'SELECT cr.`id`, cr.`customer_id`, cr.`project_name`, cr.`request_type`, cr.`request_status`,
                   cr.`site_postal_code`, cr.`site_address`, cr.`created_at`, cr.`updated_at`,
                   ' . implode(', ', $optionalColumns) . ',
                   ' . $customerSelect . '
            FROM `connection_requests` cr
            ' . $customerJoin . '
            WHERE cr.`id` = ?
            LIMIT 1';

    $row = db_query($sql, [$requestId])->fetch();

    return is_array($row) ? $row : null;
}

function admin_work_request_view_connection_files(int $requestId): array
{
    if ($requestId <= 0 || !db_table_exists('connection_request_files')) {
        return [];
    }

    return db_query(
        'SELECT *
         FROM `connection_request_files`
         WHERE `connection_request_id` = ?
         ORDER BY `id` ASC',
        [$requestId]
    )->fetchAll();
}

function admin_work_request_view_dedupe_minicrm_files(array $minicrmFiles, array ...$existingGroups): array
{
    $knownStoragePaths = [];

    foreach ($existingGroups as $group) {
        foreach ($group as $file) {
            $storagePath = trim((string) ($file['storage_path'] ?? ''));

            if ($storagePath !== '') {
                $knownStoragePaths[$storagePath] = true;
            }
        }
    }

    return array_values(array_filter($minicrmFiles, static function (array $file) use ($knownStoragePaths): bool {
        $storagePath = trim((string) ($file['storage_path'] ?? ''));

        return $storagePath === '' || !isset($knownStoragePaths[$storagePath]);
    }));
}

function admin_work_request_view_minicrm_document_links(int $requestId): array
{
    if (
        $requestId <= 0
        || !db_table_exists('minicrm_work_items')
        || !function_exists('minicrm_work_item_ids_for_connection_request')
        || !function_exists('minicrm_work_item_document_links')
    ) {
        return [];
    }

    $workItemIds = minicrm_work_item_ids_for_connection_request($requestId);

    if ($workItemIds === []) {
        return [];
    }

    $rows = db_query(
        'SELECT `id`, `card_name`, `document_links_json`
         FROM `minicrm_work_items`
         WHERE `id` IN (' . db_in_placeholders($workItemIds) . ')
         ORDER BY `id` DESC',
        $workItemIds
    )->fetchAll();
    $links = [];
    $seen = [];

    foreach ($rows as $row) {
        foreach (minicrm_work_item_document_links($row) as $link) {
            $url = trim((string) ($link['value'] ?? ''));

            if ($url === '' || !preg_match('#^https?://#i', $url) || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $links[] = [
                'label' => trim((string) ($link['label'] ?? '')) ?: 'MiniCRM dokumentum',
                'url' => $url,
                'work_item_id' => (int) ($row['id'] ?? 0),
                'work_item_name' => trim((string) ($row['card_name'] ?? '')),
            ];
        }
    }

    return $links;
}

function admin_work_request_view_format_bytes(mixed $value): string
{
    if (function_exists('format_bytes')) {
        return format_bytes((int) $value);
    }

    $bytes = max(0, (int) $value);

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' KB';
    }

    return $bytes . ' B';
}

function admin_work_request_view_file_card(array $file, string $url, string $title, string $uploaderLabel): void
{
    $previewKind = function_exists('portal_file_preview_kind') ? portal_file_preview_kind($file) : 'file';
    $fileExists = is_file((string) ($file['storage_path'] ?? ''));
    $originalName = admin_work_request_view_value($file['original_name'] ?? '');
    $extension = function_exists('portal_file_preview_extension') ? portal_file_preview_extension($file) : strtoupper((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $sizeLabel = !empty($file['file_size']) ? admin_work_request_view_format_bytes($file['file_size']) : '';
    ?>
    <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
        <div class="admin-request-doc-thumb">
            <?php if ($fileExists && $previewKind === 'image'): ?>
                <a href="<?= h($url); ?>" target="_blank" rel="noopener" aria-label="<?= h($title); ?> megnyitása">
                    <img src="<?= h($url); ?>" alt="<?= h($title); ?>" width="92" height="92" loading="lazy">
                </a>
            <?php else: ?>
                <div class="admin-request-doc-fallback"><span><?= h($extension !== '' ? $extension : 'FÁJL'); ?></span></div>
            <?php endif; ?>
        </div>
        <div class="admin-request-doc-meta">
            <strong><?= h($title); ?></strong>
            <span><?= h($originalName); ?></span>
            <?php if ($sizeLabel !== ''): ?><span><?= h($sizeLabel); ?></span><?php endif; ?>
            <span><?= h($uploaderLabel); ?></span>
            <?php if ($fileExists): ?>
                <a href="<?= h($url); ?>" target="_blank" rel="noopener">Megnyitás</a>
            <?php else: ?>
                <span>A fájl nem található a tárhelyen</span>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

function admin_work_request_view_status_label(array $request): string
{
    $status = (string) ($request['request_status'] ?? '');

    return function_exists('connection_request_status_label') ? connection_request_status_label($status) : admin_work_request_view_value($status);
}

function admin_work_request_view_type_label(array $request): string
{
    $type = (string) ($request['request_type'] ?? '');

    return function_exists('connection_request_type_label') ? connection_request_type_label($type) : admin_work_request_view_value($type);
}

try {
    if ($requestId <= 0) {
        $errors[] = 'Hiányzó vagy érvénytelen request_id.';
    } elseif (!db_table_exists('connection_requests')) {
        $errors[] = 'A connection_requests tábla nem érhető el.';
    } else {
        $request = admin_work_request_view_load($requestId);

        if ($request !== null) {
            $requestFiles = admin_work_request_view_connection_files($requestId);
            $requestWorkFiles = function_exists('connection_request_work_files') ? connection_request_work_files($requestId) : [];
            $requestDocuments = function_exists('connection_request_documents') ? connection_request_documents($requestId) : [];
            $rawMinicrmFiles = function_exists('minicrm_connection_request_files') ? minicrm_connection_request_files($requestId) : [];
            $minicrmFiles = admin_work_request_view_dedupe_minicrm_files($rawMinicrmFiles, $requestFiles, $requestDocuments);
            $minicrmDocumentLinks = admin_work_request_view_minicrm_document_links($requestId);
        }

        if ($request === null) {
            $errors[] = 'A megadott munka nem található.';
        }
    }
} catch (Throwable $exception) {
    $errors[] = APP_DEBUG ? $exception->getMessage() : 'A munka adatlap betöltése sikertelen.';
}

$customerId = $request !== null ? (int) ($request['customer_id'] ?? 0) : 0;
$customerUrl = $customerId > 0 ? url_path('/admin/customer-view') . '?customer=' . $customerId : '';
$lookupSearch = $request !== null
    ? trim((string) (($request['customer_email'] ?? '') ?: ($request['customer_name'] ?? '')))
    : '';
$lookupUrl = url_path('/admin/customer-lookup') . ($lookupSearch !== '' ? '?search=' . rawurlencode($lookupSearch) : '');
$siteAddress = $request !== null
    ? trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))
    : '';
$sourceLabel = $request !== null
    ? (string) (($request['request_source'] ?? '') ?: ($request['customer_source'] ?? ''))
    : '';
$fileCount = count($requestFiles) + count($requestWorkFiles) + count($requestDocuments) + count($minicrmFiles);
$assetCount = $fileCount + count($minicrmDocumentLinks);
?>
<section class="admin-section customer-lookup-page customer-view-page">
    <div class="container admin-requests-container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin munka adatlap</p>
                <h1><?= h($request !== null ? admin_work_request_view_value($request['project_name'] ?? '') : 'Munka #' . $requestId); ?></h1>
                <p>Read-only nézet egyetlen munka gyors, stabil megnyitásához.</p>
            </div>
            <div class="form-actions">
                <a class="button button-secondary" href="<?= h($lookupUrl); ?>">Vissza az ügyfélkeresőhöz</a>
                <?php if ($customerUrl !== ''): ?>
                    <a class="button button-secondary" href="<?= h($customerUrl); ?>">Ügyfél adatlap</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php elseif ($request !== null): ?>
            <div class="admin-grid summary-grid">
                <article>
                    <span>Munka ID</span>
                    <strong>#<?= (int) $request['id']; ?></strong>
                    <p><?= h(admin_work_request_view_type_label($request)); ?></p>
                </article>
                <article>
                    <span>Customer ID</span>
                    <strong><?= $customerId > 0 ? '#' . $customerId : '-'; ?></strong>
                    <p><?= $customerId > 0 ? 'Kapcsolt ügyfél' : 'Ehhez a munkához nem található ügyfélkapcsolat.'; ?></p>
                </article>
                <article>
                    <span>Státusz</span>
                    <strong><?= h(admin_work_request_view_status_label($request)); ?></strong>
                    <p><?= h(admin_work_request_view_value($request['admin_workflow_stage'] ?? '')); ?></p>
                </article>
                <article>
                    <span>Létrehozva</span>
                    <strong><?= h(admin_work_request_view_date($request['created_at'] ?? null)); ?></strong>
                    <p><?= h(admin_work_request_view_value($sourceLabel)); ?></p>
                </article>
            </div>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Munka alapadatok</h2>
                        <p>Ez a nézet nem módosít adatot és nem indít importot.</p>
                    </div>
                </div>
                <dl class="admin-request-data-list">
                    <div><dt>Munka ID</dt><dd>#<?= (int) $request['id']; ?></dd></div>
                    <div><dt>Customer ID</dt><dd><?= $customerId > 0 ? '#' . $customerId : '-'; ?></dd></div>
                    <div><dt>Megnevezés</dt><dd><?= h(admin_work_request_view_value($request['project_name'] ?? '')); ?></dd></div>
                    <div><dt>Igénytípus</dt><dd><?= h(admin_work_request_view_type_label($request)); ?></dd></div>
                    <div><dt>Státusz</dt><dd><?= h(admin_work_request_view_status_label($request)); ?></dd></div>
                    <div><dt>Forrás</dt><dd><?= h(admin_work_request_view_value($sourceLabel)); ?></dd></div>
                    <div><dt>Helyszín</dt><dd><?= h(admin_work_request_view_value($siteAddress)); ?></dd></div>
                    <div><dt>HRSZ</dt><dd><?= h(admin_work_request_view_value($request['hrsz'] ?? '')); ?></dd></div>
                    <div><dt>Mérő</dt><dd><?= h(admin_work_request_view_value($request['meter_serial'] ?? '')); ?></dd></div>
                    <div><dt>Fogyasztási hely</dt><dd><?= h(admin_work_request_view_value($request['consumption_place_id'] ?? '')); ?></dd></div>
                    <div><dt>MVM ÜK szám</dt><dd><?= h(admin_work_request_view_value($request['mvm_uk_number'] ?? '')); ?></dd></div>
                    <div><dt>Beküldve</dt><dd><?= h(admin_work_request_view_date($request['submitted_at'] ?? null)); ?></dd></div>
                    <div><dt>Lezárva</dt><dd><?= h(admin_work_request_view_date($request['closed_at'] ?? null)); ?></dd></div>
                    <div><dt>Frissítve</dt><dd><?= h(admin_work_request_view_date($request['updated_at'] ?? null)); ?></dd></div>
                </dl>
            </section>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Kapcsolódó ügyfél</h2>
                        <p>Rövid ügyfélkapcsolati adatok.</p>
                    </div>
                </div>
                <?php if ($customerId <= 0): ?>
                    <p class="request-admin-empty">Ehhez a munkához nem található ügyfélkapcsolat.</p>
                <?php else: ?>
                    <dl class="admin-request-data-list">
                        <div><dt>Customer ID</dt><dd>#<?= $customerId; ?></dd></div>
                        <div><dt>Név</dt><dd><?= h(admin_work_request_view_value($request['customer_name'] ?? '')); ?></dd></div>
                        <div><dt>Email</dt><dd><?= h(admin_work_request_view_value($request['customer_email'] ?? '')); ?></dd></div>
                        <div><dt>Telefon</dt><dd><?= h(admin_work_request_view_value($request['customer_phone'] ?? '')); ?></dd></div>
                        <div><dt>Ügyfél státusz</dt><dd><?= h(admin_work_request_view_value($request['customer_status'] ?? '')); ?></dd></div>
                    </dl>
                <?php endif; ?>
            </section>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Teljesítmény és azonosítók</h2>
                        <p>Munkaadatok gyors áttekintése.</p>
                    </div>
                </div>
                <dl class="admin-request-data-list">
                    <div><dt>Meglévő általános</dt><dd><?= h(admin_work_request_view_value($request['existing_general_power'] ?? '')); ?></dd></div>
                    <div><dt>Igényelt általános</dt><dd><?= h(admin_work_request_view_value($request['requested_general_power'] ?? '')); ?></dd></div>
                    <div><dt>Meglévő H tarifa</dt><dd><?= h(admin_work_request_view_value($request['existing_h_tariff_power'] ?? '')); ?></dd></div>
                    <div><dt>Igényelt H tarifa</dt><dd><?= h(admin_work_request_view_value($request['requested_h_tariff_power'] ?? '')); ?></dd></div>
                    <div><dt>Meglévő vezérelt</dt><dd><?= h(admin_work_request_view_value($request['existing_controlled_power'] ?? '')); ?></dd></div>
                    <div><dt>Igényelt vezérelt</dt><dd><?= h(admin_work_request_view_value($request['requested_controlled_power'] ?? '')); ?></dd></div>
                </dl>
            </section>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Megjegyzések</h2>
                        <p>Ügyfél pontosítás és belső munka megjegyzés.</p>
                    </div>
                </div>
                <dl class="admin-request-data-list">
                    <div><dt>Ügyfél pontosítás / notes</dt><dd><?= nl2br(h(admin_work_request_view_value($request['notes'] ?? ''))); ?></dd></div>
                    <div><dt>Admin audit note / work_note</dt><dd><?= nl2br(h(admin_work_request_view_value($request['work_note'] ?? ''))); ?></dd></div>
                </dl>
            </section>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Fotók és dokumentumok</h2>
                        <p>Read-only megnyitó linkek ehhez a munkához. Feltöltés, törlés és import innen nem indítható.</p>
                    </div>
                    <strong><?= $fileCount; ?> fájl / <?= count($minicrmDocumentLinks); ?> link</strong>
                </div>

                <?php if ($assetCount === 0): ?>
                    <p class="request-admin-empty">Ehhez a munkához még nincs feltöltött fotó vagy dokumentum.</p>
                <?php else: ?>
                    <?php if ($requestFiles !== []): ?>
                        <div class="admin-request-section-title">
                            <h3>Portál fájlok</h3>
                            <span><?= count($requestFiles); ?> fájl</span>
                        </div>
                        <div class="admin-request-doc-grid">
                            <?php foreach ($requestFiles as $file): ?>
                                <?php
                                admin_work_request_view_file_card(
                                    $file,
                                    url_path('/admin/connection-requests/file') . '?id=' . (int) $file['id'],
                                    (string) (($file['label'] ?? '') ?: 'Portál fájl'),
                                    'Feltöltő: ' . (function_exists('portal_file_uploader_label') ? portal_file_uploader_label($file) : 'ismeretlen')
                                );
                                ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($requestWorkFiles !== []): ?>
                        <div class="admin-request-section-title">
                            <h3>Szerelői munkafotók</h3>
                            <span><?= count($requestWorkFiles); ?> fájl</span>
                        </div>
                        <div class="admin-request-doc-grid">
                            <?php foreach ($requestWorkFiles as $file): ?>
                                <?php
                                $stage = trim((string) ($file['stage'] ?? ''));
                                $stageLabel = $stage !== '' ? strtoupper($stage) . ' - ' : '';
                                admin_work_request_view_file_card(
                                    $file,
                                    url_path('/admin/connection-requests/work-file') . '?id=' . (int) $file['id'],
                                    $stageLabel . (string) (($file['label'] ?? '') ?: 'Munka fájl'),
                                    'Feltöltő: ' . (function_exists('portal_file_uploader_label') ? portal_file_uploader_label($file) : 'ismeretlen')
                                );
                                ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($requestDocuments !== []): ?>
                        <div class="admin-request-section-title">
                            <h3>MVM dokumentumok</h3>
                            <span><?= count($requestDocuments); ?> fájl</span>
                        </div>
                        <div class="admin-request-doc-grid">
                            <?php foreach ($requestDocuments as $document): ?>
                                <?php
                                admin_work_request_view_file_card(
                                    $document,
                                    url_path('/admin/connection-requests/mvm-file') . '?id=' . (int) $document['id'],
                                    (string) (($document['title'] ?? '') ?: 'MVM dokumentum'),
                                    'Létrehozó: ' . (function_exists('portal_file_uploader_label') ? portal_file_uploader_label($document, 'ismeretlen') : 'ismeretlen')
                                );
                                ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($minicrmFiles !== []): ?>
                        <div class="admin-request-section-title">
                            <h3>MiniCRM importált fájlok</h3>
                            <span><?= count($minicrmFiles); ?> fájl</span>
                        </div>
                        <div class="admin-request-doc-grid">
                            <?php foreach ($minicrmFiles as $file): ?>
                                <?php
                                admin_work_request_view_file_card(
                                    $file,
                                    url_path('/admin/minicrm-import/file') . '?id=' . (int) $file['id'],
                                    (string) (($file['label'] ?? '') ?: 'MiniCRM fájl'),
                                    'Forrás: MiniCRM import'
                                );
                                ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($minicrmDocumentLinks !== []): ?>
                        <div class="admin-request-section-title">
                            <h3>MiniCRM dokumentum linkek</h3>
                            <span><?= count($minicrmDocumentLinks); ?> link</span>
                        </div>
                        <div class="admin-request-doc-grid">
                            <?php foreach ($minicrmDocumentLinks as $link): ?>
                                <article class="admin-request-doc-card admin-request-doc-card-document">
                                    <div class="admin-request-doc-thumb">
                                        <div class="admin-request-doc-fallback"><span>LINK</span></div>
                                    </div>
                                    <div class="admin-request-doc-meta">
                                        <strong><?= h((string) $link['label']); ?></strong>
                                        <?php if ((string) $link['work_item_name'] !== ''): ?><span><?= h((string) $link['work_item_name']); ?></span><?php endif; ?>
                                        <?php if ((int) $link['work_item_id'] > 0): ?><span>MiniCRM munka #<?= (int) $link['work_item_id']; ?></span><?php endif; ?>
                                        <a href="<?= h((string) $link['url']); ?>" target="_blank" rel="noopener">Megnyitás</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</section>
