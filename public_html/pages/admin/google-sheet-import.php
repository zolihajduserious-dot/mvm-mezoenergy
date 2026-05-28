<?php
declare(strict_types=1);

require_role(['admin']);

function admin_google_sheet_import_count(array $summary, string $key): int
{
    return isset($summary[$key]) && is_numeric($summary[$key]) ? (int) $summary[$key] : 0;
}

function admin_google_sheet_import_result_body(?array $result): array
{
    return is_array($result['body'] ?? null) ? $result['body'] : [];
}

$pageAlert = get_flash();
$result = null;
$resultAction = '';
$configured = google_sheet_import_admin_is_configured();

if (is_post()) {
    require_valid_csrf_token();

    $requestedAction = (string) ($_POST['action'] ?? '');
    $allowedActions = ['preview', 'run-approved', 'delete-triggers'];

    if (!in_array($requestedAction, $allowedActions, true)) {
        $pageAlert = ['type' => 'error', 'message' => 'Ismeretlen import művelet.'];
    } elseif (!$configured) {
        $pageAlert = ['type' => 'error', 'message' => 'A Google Sheet manuális import nincs konfigurálva.'];
    } else {
        $payload = [];

        if ($requestedAction === 'delete-triggers') {
            $payload['confirm_delete_triggers'] = true;
        }

        $result = google_sheet_import_admin_call($requestedAction, $payload);
        $resultAction = $requestedAction;
        google_sheet_import_admin_log($requestedAction, $result);

        $pageAlert = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => (string) ($result['message'] ?? 'Google Sheet import művelet lefutott.'),
        ];
    }
}

$body = admin_google_sheet_import_result_body($result);
$summary = is_array($body['summary'] ?? null) ? $body['summary'] : [];
$previewRows = is_array($body['previewRows'] ?? null) ? $body['previewRows'] : [];
$errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Google Sheet lead import</h1>
                <p>A Facebook azonnali űrlapból érkező leadek először a Google Sheetbe kerülnek. Az import csak azokat a sorokat dolgozza fel, amelyeket a táblázatban IMPORTÁLANDÓ státuszra állítottál.</p>
            </div>
            <div class="admin-actions">
                <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
            </div>
        </div>

        <?php if ($pageAlert !== null): ?>
            <div class="alert alert-<?= h((string) $pageAlert['type']); ?>">
                <p><?= h((string) $pageAlert['message']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!$configured): ?>
            <div class="alert alert-error">
                <p>A Google Sheet manuális import nincs konfigurálva. Állítsd be a `GOOGLE_SHEET_IMPORT_WEBAPP_URL` és `GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN` értéket szerver oldali környezeti változóként vagy `storage/config/local.secret.php` fájlban.</p>
            </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <p>Az import nem dolgozza fel az üres, ELUTASÍTVA, NEM_IMPORTÁL, SIKERES vagy DUPLIKÁLT státuszú sorokat. A `ÚJ` és `ELLENŐRZÉSRE_VÁR` sorok csak előnézetben számítanak, importálás előtt kézzel `IMPORTÁLANDÓ` vagy `JÓVÁHAGYVA` státuszra kell állítani őket.</p>
        </div>

        <div class="form-grid two">
            <section class="auth-panel">
                <h2>Állapot lekérdezése</h2>
                <p class="muted-text">Csak összesítést és maszkolt előnézetet kér le a Google Sheetből. Nem importál sort.</p>
                <form class="form" method="post" action="<?= h(url_path('/admin/google-sheet-import')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="preview">
                    <button class="button" type="submit"<?= !$configured ? ' disabled' : ''; ?>>Állapot lekérdezése</button>
                </form>
            </section>

            <section class="auth-panel">
                <h2>Jóváhagyott sorok importálása</h2>
                <p class="muted-text">Csak az IMPORTÁLANDÓ / JÓVÁHAGYVA státuszú sorokat dolgozza fel, legfeljebb a Script Properties-ben beállított limitig.</p>
                <form class="form" method="post" action="<?= h(url_path('/admin/google-sheet-import')); ?>" onsubmit="return confirm('Biztosan importálod az IMPORTÁLANDÓ státuszú sorokat?');">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="run-approved">
                    <button class="button" type="submit"<?= !$configured ? ' disabled' : ''; ?>>Jóváhagyott sorok importálása</button>
                </form>
            </section>
        </div>

        <details class="auth-panel" style="margin-top: 1rem;">
            <summary><strong>Automata triggerek törlése</strong></summary>
            <p class="muted-text">Veszélyes műveletként kezeld: csak akkor használd, ha korábban véletlenül települt időzített Apps Script trigger. A jelenlegi üzleti döntés szerint időzített trigger nincs használatban.</p>
            <form class="form" method="post" action="<?= h(url_path('/admin/google-sheet-import')); ?>" onsubmit="return confirm('Biztosan törlöd az importPendingLeads időzített triggert?');">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="delete-triggers">
                <button class="button button-secondary" type="submit"<?= !$configured ? ' disabled' : ''; ?>>Automata triggerek törlése</button>
            </form>
        </details>

        <?php if ($result !== null): ?>
            <section class="admin-section" style="padding-top: 1.5rem;">
                <div class="section-heading compact-heading">
                    <p class="eyebrow">Eredmény</p>
                    <h2><?= h($resultAction === 'run-approved' ? 'Import eredmény' : ($resultAction === 'delete-triggers' ? 'Trigger törlés' : 'Google Sheet állapot')); ?></h2>
                    <p>HTTP státusz: <?= (int) ($result['http_status'] ?? 0); ?> · Webapp státusz: <?= h((string) ($body['status'] ?? '-')); ?></p>
                </div>

                <?php if ($summary !== []): ?>
                    <div class="admin-grid dashboard-grid">
                        <?php
                        $summaryCards = $resultAction === 'run-approved'
                            ? [
                                ['label' => 'Feldolgozva', 'value' => admin_google_sheet_import_count($summary, 'processed')],
                                ['label' => 'Sikeres', 'value' => admin_google_sheet_import_count($summary, 'imported')],
                                ['label' => 'Duplikált', 'value' => admin_google_sheet_import_count($summary, 'duplicated')],
                                ['label' => 'Hibás', 'value' => admin_google_sheet_import_count($summary, 'failed')],
                                ['label' => 'Kihagyva', 'value' => admin_google_sheet_import_count($summary, 'skipped')],
                                ['label' => 'Limit', 'value' => admin_google_sheet_import_count($summary, 'limit')],
                            ]
                            : [
                                ['label' => 'Összes sor', 'value' => admin_google_sheet_import_count($summary, 'totalRows')],
                                ['label' => 'Importálható', 'value' => admin_google_sheet_import_count($summary, 'importable')],
                                ['label' => 'Üres státusz', 'value' => admin_google_sheet_import_count($summary, 'emptyStatus')],
                                ['label' => 'Ellenőrzésre vár', 'value' => admin_google_sheet_import_count($summary, 'waitingReview')],
                                ['label' => 'Sikeres', 'value' => admin_google_sheet_import_count($summary, 'success')],
                                ['label' => 'Duplikált', 'value' => admin_google_sheet_import_count($summary, 'duplicate')],
                                ['label' => 'Hibás', 'value' => admin_google_sheet_import_count($summary, 'error')],
                                ['label' => 'Nem importálandó', 'value' => admin_google_sheet_import_count($summary, 'notImportedOrRejected')],
                            ];
                        ?>
                        <?php foreach ($summaryCards as $card): ?>
                            <article class="metric-card metric-card-system">
                                <span class="metric-label"><?= h((string) $card['label']); ?></span>
                                <strong><?= h((string) $card['value']); ?></strong>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($previewRows !== []): ?>
                    <section class="auth-panel" style="margin-top: 1rem;">
                        <h2>Importálható sorok előnézete</h2>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Sor</th>
                                        <th>Település</th>
                                        <th>Munka típusa</th>
                                        <th>Létrehozva</th>
                                        <th>Email</th>
                                        <th>Telefon</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($previewRows as $row): ?>
                                        <?php if (!is_array($row)) { continue; } ?>
                                        <tr>
                                            <td><?= (int) ($row['row'] ?? 0); ?></td>
                                            <td><?= h((string) ($row['city'] ?? '')); ?></td>
                                            <td><?= h((string) ($row['work_type'] ?? '')); ?></td>
                                            <td><?= h((string) ($row['created_time'] ?? '')); ?></td>
                                            <td><?= h((string) ($row['email_masked'] ?? '')); ?></td>
                                            <td><?= h((string) ($row['phone_masked'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($errors !== []): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $error): ?>
                            <?php if (!is_array($error)) { continue; } ?>
                            <p>Sor <?= (int) ($error['row'] ?? 0); ?>: <?= h((string) ($error['error'] ?? 'Import hiba')); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</section>
