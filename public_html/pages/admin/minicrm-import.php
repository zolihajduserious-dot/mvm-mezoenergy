<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$schemaErrors = minicrm_import_schema_errors();
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

if (is_post() && ($_POST['action'] ?? '') === 'install_minicrm_schema') {
    require_valid_csrf_token();

    $result = minicrm_import_install_schema();
    set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) $result['message']);
    redirect('/admin/minicrm-import');
}

$items = $schemaErrors === [] ? minicrm_work_items(1000) : [];
$batches = $schemaErrors === [] ? minicrm_import_batches(8) : [];
$statusCounts = $schemaErrors === [] ? minicrm_work_item_status_counts() : [];
$totalItems = count($items);
$localDocumentFileCount = $schemaErrors === [] ? minicrm_work_item_file_count() : 0;
$localDocumentSizeTotal = $schemaErrors === [] ? minicrm_work_item_file_size_total() : 0;
$documentZipCandidates = minicrm_document_zip_candidates();
$itemsByStatus = [];
$selectedItemId = isset($_GET['item']) ? max(0, (int) $_GET['item']) : 0;

foreach ($items as $item) {
    if ($selectedItemId === 0) {
        $selectedItemId = (int) ($item['id'] ?? 0);
    }

    $statusName = trim((string) ($item['minicrm_status'] ?? '')) ?: 'Nincs státusz';
    $itemsByStatus[$statusName][] = $item;
}

uasort($itemsByStatus, static fn (array $a, array $b): int => count($b) <=> count($a));

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
            'title' => 'Dokumentumok összefűzve',
            'actor' => 'MiniCRM ZIP import',
            'body' => count($localFiles) . ' saját tárhelyes fájl kapcsolódik ehhez a munkához.',
            'kind' => 'document',
        ];
    }

    return array_slice($events, 0, 14);
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

        <?php if ($schemaErrors === [] && $items === []): ?>
            <div class="empty-state" data-minicrm-panel="works">
                <h2>Még nincs importált MiniCRM munka</h2>
                <p>Tölts fel egy MiniCRM Excel exportot, és a munkák itt jelennek meg.</p>
            </div>
        <?php elseif ($items !== []): ?>
            <div class="admin-workflow-list minicrm-workspace" id="minicrm-works" data-minicrm-panel="works">
                <section class="admin-workflow-stage">
                    <div class="admin-workflow-stage-head minicrm-workspace-head">
                        <div>
                            <span class="portal-kicker">Munkák</span>
                            <h2>MiniCRM munkaállomány</h2>
                            <p>Státuszonként csoportosított, kompakt lista kereséssel és munkán belüli idővonallal.</p>
                        </div>
                        <strong><?= $totalItems; ?> db</strong>
                    </div>

                    <div class="minicrm-list-tools">
                        <label for="minicrm_search">Keresés a munkák között</label>
                        <input id="minicrm_search" type="search" placeholder="Név, azonosító, cím, felelős, státusz vagy mezőérték" data-minicrm-search>
                        <span data-minicrm-count><?= $totalItems; ?> db</span>
                    </div>

                    <nav class="minicrm-status-nav" aria-label="MiniCRM státuszok">
                        <?php foreach ($itemsByStatus as $statusName => $statusItems): ?>
                            <a href="#minicrm-status-<?= h(minicrm_import_dom_id((string) $statusName)); ?>">
                                <span><?= h((string) $statusName); ?></span>
                                <strong><?= count($statusItems); ?></strong>
                            </a>
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
                            $fieldGroups = $isSelectedItem ? minicrm_work_item_field_groups($item) : [];
                            $timelineEvents = $isSelectedItem ? minicrm_import_timeline_events($item, $rawFields, $localFiles) : [];
                            $summaryNote = $isSelectedItem ? minicrm_import_first_matching_field($rawFields, ['/megjegyzes/', '/uzenet/', '/munka rovid leirasa/', '/szoveg/']) : '';
                            $documentLinkCount = $isSelectedItem ? count($actualDocumentLinks) : minicrm_import_document_link_count($item);
                            $searchText = implode(' ', [
                                (string) ($item['card_name'] ?? ''),
                                (string) ($item['source_id'] ?? ''),
                                (string) ($item['responsible'] ?? ''),
                                (string) ($item['minicrm_status'] ?? ''),
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
                                        </div>
                                    </div>

                                    <div class="minicrm-work-detail-layout">
                                        <aside class="minicrm-work-facts">
                                            <dl>
                                                <div><dt>Ügyfél</dt><dd><?= h((string) ($item['customer_name'] ?: $item['card_name'] ?: '-')); ?></dd></div>
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
                                                    <h3>Saját tárhelyes dokumentumok</h3>
                                                    <span><?= count($localFiles); ?> fájl</span>
                                                </div>
                                                <?php if ($localFiles === []): ?>
                                                    <p class="request-admin-empty">Ehhez a munkához még nincs saját tárhelyes dokumentum kapcsolva. A hiányzó fájl valószínűleg egy további MiniCRM dokumentum ZIP-ben lesz.</p>
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
                                                <div class="minicrm-readable-groups minicrm-field-groups">
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
                                                                    $rawValue = (string) $rawField['value'];
                                                                    $rawIsUrl = str_starts_with($rawValue, 'http://') || str_starts_with($rawValue, 'https://');
                                                                    ?>
                                                                    <article class="minicrm-readable-row">
                                                                        <span><?= h((string) $rawField['label']); ?></span>
                                                                        <?php if ($rawIsUrl): ?>
                                                                            <a href="<?= h($rawValue); ?>" target="_blank" rel="noopener">Megnyitás</a>
                                                                        <?php else: ?>
                                                                            <strong><?= h($rawValue); ?></strong>
                                                                        <?php endif; ?>
                                                                    </article>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </details>
                                                        <?php $groupIndex++; ?>
                                                    <?php endforeach; ?>
                                                </div>
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
