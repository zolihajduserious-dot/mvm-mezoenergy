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

        <div class="form-grid two">
            <section class="auth-panel">
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

            <section class="auth-panel">
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
                            <p>A lista az összefésült MiniCRM mezőket csoportosítva mutatja, hogy az adatlapok megnyitásakor minden beolvasott érték olvasható legyen.</p>
                        </div>
                        <strong><?= $totalItems; ?> db</strong>
                    </div>

                    <div class="minicrm-list-tools">
                        <label for="minicrm_search">Keresés</label>
                        <input id="minicrm_search" type="search" placeholder="Név, azonosító, cím, felelős vagy mezőérték" data-minicrm-search>
                        <span data-minicrm-count><?= $totalItems; ?> db</span>
                    </div>

                    <div class="request-admin-list" data-minicrm-list>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $documentLinks = minicrm_work_item_document_links($item);
                            $localFiles = minicrm_work_item_files((int) $item['id']);
                            $rawFields = minicrm_work_item_raw_fields($item);
                            $fieldGroups = minicrm_work_item_field_groups($item);
                            $siteAddress = trim((string) ($item['postal_code'] ?? '') . ' ' . (string) ($item['site_address'] ?? ''));
                            $statusClass = minicrm_status_class($item['minicrm_status'] ?? null);
                            ?>
                            <details class="admin-workflow-request" data-minicrm-item>
                                <summary class="admin-workflow-request-summary">
                                    <span class="admin-workflow-request-id">#<?= (int) $item['id']; ?></span>
                                    <span class="admin-workflow-request-main">
                                        <strong><?= h((string) $item['card_name']); ?></strong>
                                        <small><?= count($rawFields); ?> MiniCRM mezo · <?= count($documentLinks); ?> link · <?= count($localFiles); ?> sajat fajl</small>
                                    </span>
                                    <span class="admin-workflow-request-meta">
                                        <span><?= h((string) ($item['responsible'] ?: 'Nincs felelős')); ?></span>
                                        <strong><?= count($localFiles); ?> fajl</strong>
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
                                            <p><?= count($rawFields); ?> importalt MiniCRM mezo, <?= count($localFiles); ?> sajat tarhelyes fajl.</p>
                                        </div>
                                        <div class="request-admin-status">
                                            <span class="status-badge status-badge-<?= h($statusClass); ?>"><?= h((string) ($item['minicrm_status'] ?: 'Nincs státusz')); ?></span>
                                            <?php if (!empty($item['responsible'])): ?><span class="status-badge status-badge-finalized"><?= h((string) $item['responsible']); ?></span><?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($fieldGroups === []): ?>
                                        <section class="minicrm-readable-panel">
                                            <p class="request-admin-empty">Ehhez a tetelhez nincs reszletes MiniCRM mezo eltarolva.</p>
                                        </section>
                                    <?php else: ?>
                                        <div class="minicrm-readable-groups">
                                            <?php foreach ($fieldGroups as $group): ?>
                                                <section class="minicrm-readable-panel">
                                                    <div class="admin-request-section-title">
                                                        <h3><?= h((string) $group['title']); ?></h3>
                                                        <span><?= count($group['fields']); ?> mezo</span>
                                                    </div>
                                                    <div class="minicrm-readable-grid">
                                                        <?php foreach ($group['fields'] as $rawField): ?>
                                                            <?php
                                                            $rawValue = (string) $rawField['value'];
                                                            $rawIsUrl = str_starts_with($rawValue, 'http://') || str_starts_with($rawValue, 'https://');
                                                            ?>
                                                            <article class="minicrm-readable-row">
                                                                <span><?= h((string) $rawField['label']); ?></span>
                                                                <?php if ($rawIsUrl): ?>
                                                                    <a href="<?= h($rawValue); ?>" target="_blank" rel="noopener">Megnyitas</a>
                                                                    <small><?= h($rawValue); ?></small>
                                                                <?php else: ?>
                                                                    <strong><?= h($rawValue); ?></strong>
                                                                <?php endif; ?>
                                                            </article>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </section>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($localFiles !== []): ?>
                                        <div class="minicrm-readable-groups">
                                            <section class="minicrm-readable-panel minicrm-local-documents">
                                                <div class="admin-request-section-title">
                                                    <h3>Sajat tarhelyes MiniCRM dokumentumok</h3>
                                                    <span><?= count($localFiles); ?> fajl</span>
                                                </div>
                                                <div class="admin-request-doc-grid">
                                                    <?php foreach ($localFiles as $localFile): ?>
                                                        <?php
                                                        $localFileUrl = url_path('/admin/minicrm-import/file') . '?id=' . (int) $localFile['id'];
                                                        $previewKind = portal_file_preview_kind($localFile);
                                                        ?>
                                                        <article class="admin-request-doc-card admin-request-doc-card-<?= h($previewKind); ?>">
                                                            <div class="admin-request-doc-thumb">
                                                                <?php if ($previewKind === 'image'): ?>
                                                                    <a href="<?= h($localFileUrl); ?>" target="_blank" aria-label="<?= h((string) $localFile['label']); ?> megnyitasa">
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
                                                                <a href="<?= h($localFileUrl); ?>" target="_blank">Megnyitas</a>
                                                            </div>
                                                        </article>
                                                    <?php endforeach; ?>
                                                </div>
                                            </section>
                                        </div>
                                    <?php endif; ?>

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

                                    <section class="admin-request-panel admin-request-documents">
                                        <div class="admin-request-section-title">
                                            <h3>Osszes MiniCRM mezo</h3>
                                            <span><?= count($rawFields); ?> db</span>
                                        </div>
                                        <?php if ($rawFields === []): ?>
                                            <p class="request-admin-empty">Ehhez a tetelhez nincs reszletes MiniCRM mezo eltarolva.</p>
                                        <?php else: ?>
                                            <dl class="admin-request-data-list admin-request-data-list-compact">
                                                <?php foreach ($rawFields as $rawField): ?>
                                                    <div><dt><?= h((string) $rawField['label']); ?></dt><dd><?= h((string) $rawField['value']); ?></dd></div>
                                                <?php endforeach; ?>
                                            </dl>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const input = document.querySelector('[data-minicrm-search]');
    const count = document.querySelector('[data-minicrm-count]');
    const items = Array.from(document.querySelectorAll('[data-minicrm-item]'));

    if (!input || !count || items.length === 0) {
        return;
    }

    const searchable = items.map((item) => ({
        item,
        text: item.textContent.toLocaleLowerCase('hu-HU'),
    }));

    input.addEventListener('input', () => {
        const query = input.value.trim().toLocaleLowerCase('hu-HU');
        let visible = 0;

        searchable.forEach(({ item, text }) => {
            const show = query === '' || text.includes(query);
            item.hidden = !show;
            visible += show ? 1 : 0;
        });

        count.textContent = `${visible} db`;
    });
});
</script>
