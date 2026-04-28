<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$schemaErrors = minicrm_import_schema_errors();
$deps = dependency_status();
$flash = get_flash();
$importErrors = [];

if (is_post() && ($_POST['action'] ?? '') === 'import_minicrm_file') {
    require_valid_csrf_token();

    $result = minicrm_import_upload($_FILES['minicrm_file'] ?? []);

    if ($result['ok'] ?? false) {
        set_flash('success', (string) $result['message']);
    } else {
        set_flash('error', (string) ($result['message'] ?? 'A MiniCRM import sikertelen.'));
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
?>
<section class="admin-section">
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

        <div class="form-grid two">
            <section class="auth-panel">
                <h2>Excel import</h2>
                <p class="muted-text">A MiniCRM egyszerre exportált csomagjai egymás után feltölthetők. Ha ugyanaz az azonosító már létezik, az import frissíti a meglévő munkát, nem hoz létre duplikációt.</p>
                <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/admin/minicrm-import')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="import_minicrm_file">
                    <label for="minicrm_file">MiniCRM XLSX/XLS fájl</label>
                    <input id="minicrm_file" name="minicrm_file" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" <?= ($schemaErrors !== [] || !$deps['phpspreadsheet']) ? 'disabled' : 'required'; ?>>
                    <button class="button" type="submit" <?= ($schemaErrors !== [] || !$deps['phpspreadsheet']) ? 'disabled' : ''; ?>>Import indítása</button>
                </form>
            </section>

            <section class="auth-panel">
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
            <div class="admin-grid summary-grid request-summary-grid">
                <article class="metric-card metric-card-primary">
                    <span class="metric-label">Összes MiniCRM munka</span>
                    <strong><?= $totalItems; ?></strong>
                    <p>Az importált, visszakereshető MiniCRM munkaállomány.</p>
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
            <section class="auth-panel form-block">
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
            <div class="empty-state">
                <h2>Még nincs importált MiniCRM munka</h2>
                <p>Tölts fel egy MiniCRM Excel exportot, és a munkák itt jelennek meg.</p>
            </div>
        <?php elseif ($items !== []): ?>
            <div class="admin-workflow-list">
                <section class="admin-workflow-stage">
                    <div class="admin-workflow-stage-head">
                        <div>
                            <span class="portal-kicker">MiniCRM munkaállomány</span>
                            <h2>Importált tételek</h2>
                            <p>A lista a MiniCRM-ből áthozott adatokat a meglévő admin munkakártyákhoz hasonló bontásban mutatja.</p>
                        </div>
                        <strong><?= $totalItems; ?> db</strong>
                    </div>

                    <div class="request-admin-list">
                        <?php foreach ($items as $item): ?>
                            <?php
                            $documentLinks = minicrm_work_item_document_links($item);
                            $siteAddress = trim((string) ($item['postal_code'] ?? '') . ' ' . (string) ($item['site_address'] ?? ''));
                            $statusClass = minicrm_status_class($item['minicrm_status'] ?? null);
                            ?>
                            <details class="admin-workflow-request">
                                <summary class="admin-workflow-request-summary">
                                    <span class="admin-workflow-request-id">#<?= (int) $item['id']; ?></span>
                                    <span class="admin-workflow-request-main">
                                        <strong><?= h((string) $item['card_name']); ?></strong>
                                        <small><?= h((string) ($item['customer_name'] ?: '-')); ?> · <?= h($siteAddress !== '' ? $siteAddress : '-'); ?></small>
                                    </span>
                                    <span class="admin-workflow-request-meta">
                                        <span><?= h((string) ($item['responsible'] ?: 'Nincs felelős')); ?></span>
                                        <strong><?= h(connection_request_type_label($item['request_type'] ?? null)); ?></strong>
                                    </span>
                                    <span class="admin-workflow-request-badges">
                                        <span class="status-badge status-badge-<?= h($statusClass); ?>"><?= h((string) ($item['minicrm_status'] ?: 'Nincs státusz')); ?></span>
                                    </span>
                                </summary>

                                <article class="request-admin-card">
                                    <div class="request-admin-card-head">
                                        <div>
                                            <span class="portal-kicker">MiniCRM azonosító: <?= h((string) $item['source_id']); ?></span>
                                            <h2><?= h((string) $item['card_name']); ?></h2>
                                            <p><?= h(connection_request_type_label($item['request_type'] ?? null)); ?> · <?= h($siteAddress !== '' ? $siteAddress : '-'); ?></p>
                                        </div>
                                        <div class="request-admin-status">
                                            <span class="status-badge status-badge-<?= h($statusClass); ?>"><?= h((string) ($item['minicrm_status'] ?: 'Nincs státusz')); ?></span>
                                            <?php if (!empty($item['submitted_date'])): ?><span class="status-badge status-badge-finalized">Leadva: <?= h((string) $item['submitted_date']); ?></span><?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="admin-request-panel-grid">
                                        <section class="admin-request-panel">
                                            <h3>Ügyfél</h3>
                                            <dl class="admin-request-data-list">
                                                <div><dt>Név</dt><dd><?= h((string) ($item['customer_name'] ?: '-')); ?></dd></div>
                                                <div><dt>Születési név</dt><dd><?= h((string) ($item['birth_name'] ?: '-')); ?></dd></div>
                                                <div><dt>Születési hely/idő</dt><dd><?= h(trim((string) ($item['birth_place'] ?? '') . ' ' . (string) ($item['birth_date'] ?? '')) ?: '-'); ?></dd></div>
                                                <div><dt>Anyja neve</dt><dd><?= h((string) ($item['mother_name'] ?: '-')); ?></dd></div>
                                                <div><dt>Levelezési cím</dt><dd><?= h((string) ($item['mailing_address'] ?: '-')); ?></dd></div>
                                            </dl>
                                        </section>

                                        <section class="admin-request-panel">
                                            <h3>Munka adatai</h3>
                                            <dl class="admin-request-data-list">
                                                <div><dt>MiniCRM felelős</dt><dd><?= h((string) ($item['responsible'] ?: '-')); ?></dd></div>
                                                <div><dt>Munka típusa</dt><dd><?= h((string) ($item['work_type'] ?: '-')); ?></dd></div>
                                                <div><dt>Munka jellege</dt><dd><?= h((string) ($item['work_kind'] ?: '-')); ?></dd></div>
                                                <div><dt>Dátum</dt><dd><?= h((string) ($item['date_value'] ?: '-')); ?></dd></div>
                                                <div><dt>Leadás dátuma</dt><dd><?= h((string) ($item['submitted_date'] ?: '-')); ?></dd></div>
                                            </dl>
                                        </section>

                                        <section class="admin-request-panel admin-request-panel-wide">
                                            <h3>Helyszín és mérő</h3>
                                            <dl class="admin-request-data-list admin-request-data-list-compact">
                                                <div><dt>Cím</dt><dd><?= h($siteAddress !== '' ? $siteAddress : '-'); ?></dd></div>
                                                <div><dt>HRSZ</dt><dd><?= h((string) ($item['hrsz'] ?: '-')); ?></dd></div>
                                                <div><dt>Felhasználási hely</dt><dd><?= h((string) ($item['consumption_place_id'] ?: '-')); ?></dd></div>
                                                <div><dt>Mérő MN</dt><dd><?= h((string) ($item['meter_serial'] ?: '-')); ?></dd></div>
                                                <div><dt>Mérő vezérelt</dt><dd><?= h((string) ($item['controlled_meter_serial'] ?: '-')); ?></dd></div>
                                                <div><dt>Vezeték típusa</dt><dd><?= h((string) ($item['wire_type'] ?: '-')); ?></dd></div>
                                                <div><dt>Mérőszekrény</dt><dd><?= h((string) ($item['meter_cabinet'] ?: '-')); ?></dd></div>
                                                <div><dt>Mérőóra helye</dt><dd><?= h((string) ($item['meter_location'] ?: '-')); ?></dd></div>
                                                <div><dt>Oszlop típusa</dt><dd><?= h((string) ($item['pole_type'] ?: '-')); ?></dd></div>
                                                <?php if (!empty($item['wire_note'])): ?><div class="admin-request-data-wide"><dt>Kockás papír vezeték</dt><dd><?= h((string) $item['wire_note']); ?></dd></div><?php endif; ?>
                                                <?php if (!empty($item['cabinet_note'])): ?><div class="admin-request-data-wide"><dt>Kockás papír szekrény</dt><dd><?= h((string) $item['cabinet_note']); ?></dd></div><?php endif; ?>
                                            </dl>
                                        </section>
                                    </div>

                                    <section class="admin-request-panel admin-request-documents">
                                        <div class="admin-request-section-title">
                                            <h3>MiniCRM dokumentumlinkek</h3>
                                            <span><?= count($documentLinks); ?> db</span>
                                        </div>
                                        <?php if ($documentLinks === []): ?>
                                            <p class="request-admin-empty">Nincs dokumentumlink ehhez a tételhez.</p>
                                        <?php else: ?>
                                            <div class="inline-link-list minicrm-document-links">
                                                <?php foreach ($documentLinks as $documentLink): ?>
                                                    <?php if (!empty($documentLink['is_url'])): ?>
                                                        <a href="<?= h((string) $documentLink['value']); ?>" target="_blank" rel="noopener"><?= h((string) $documentLink['label']); ?></a>
                                                    <?php else: ?>
                                                        <span><?= h((string) $documentLink['label']); ?>: <?= h((string) $documentLink['value']); ?></span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                </article>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>
</section>
