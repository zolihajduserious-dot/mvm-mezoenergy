<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$errors = [];
$form = normalize_download_document_data(['is_active' => 1]);
$flash = get_flash();
$tableReady = db_table_exists('download_documents');

if (is_post()) {
    require_valid_csrf_token();

    $form = normalize_download_document_data($_POST);
    $file = $_FILES['document_file'] ?? null;

    if (!$tableReady) {
        $errors[] = 'Előbb futtasd le a database/upgrade_connection_requests.sql fájlt phpMyAdminban.';
    } else {
        $errors = validate_download_document_data($form, is_array($file) ? $file : null);
    }

    if ($errors === []) {
        try {
            create_download_document($form, $file);
            set_flash('success', 'A dokumentum feltöltve.');
            redirect('/admin/documents');
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'A dokumentum feltöltése sikertelen.';
        }
    }
}

$documents = $tableReady ? download_documents(false) : [];
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Dokumentumtár</h1>
                <p>Ide tölthetők fel az ügyfeleknek és generálkivitelezőknek letölthető nyomtatványok.</p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if (!$tableReady): ?>
            <div class="alert alert-info">
                <p>A dokumentumtár adatbázistáblája még hiányzik. Futtasd le phpMyAdminban a <strong>database/upgrade_connection_requests.sql</strong> fájlt.</p>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="form-grid two">
            <section class="auth-panel">
                <h2>Új dokumentum feltöltése</h2>
                <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/admin/documents')); ?>">
                    <?= csrf_field(); ?>

                    <label for="title">Dokumentum neve</label>
                    <input id="title" name="title" value="<?= h($form['title']); ?>" required>

                    <label for="category">Kategória</label>
                    <input id="category" name="category" value="<?= h($form['category']); ?>" placeholder="Példa: MVM nyomtatvány">

                    <label for="description">Rövid leírás</label>
                    <textarea id="description" name="description" rows="3"><?= h($form['description']); ?></textarea>

                    <label for="sort_order">Sorrend</label>
                    <input id="sort_order" name="sort_order" type="number" value="<?= h($form['sort_order']); ?>">

                    <label class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" <?= (int) $form['is_active'] === 1 ? 'checked' : ''; ?>>
                        <span>Aktív, ügyfelek számára letölthető</span>
                    </label>

                    <label for="document_file">Fájl</label>
                    <input id="document_file" name="document_file" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required>

                    <button class="button" type="submit">Dokumentum feltöltése</button>
                </form>
            </section>

            <section class="auth-panel">
                <h2>Letölthető oldal</h2>
                <p class="muted-text">Ezt a linket elküldheted az ügyfeleknek, belépés nélkül is meg tudják nyitni:</p>
                <a class="button button-secondary" href="<?= h(url_path('/documents')); ?>" target="_blank">Dokumentumtár megnyitása</a>
            </section>
        </div>

        <section class="form-block">
            <?php if ($documents === []): ?>
                <div class="empty-state">
                    <h2>Még nincs feltöltött dokumentum</h2>
                    <p>Az első dokumentum feltöltése után itt jelenik meg a lista.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Dokumentum</th>
                                <th>Kategória</th>
                                <th>Státusz</th>
                                <th>Fájl</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $document): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($document['title']); ?></strong>
                                        <?php if (!empty($document['description'])): ?><span><?= h($document['description']); ?></span><?php endif; ?>
                                    </td>
                                    <td><?= h($document['category']); ?></td>
                                    <td><?= (int) $document['is_active'] === 1 ? 'Aktív' : 'Inaktív'; ?></td>
                                    <td>
                                        <a href="<?= h(url_path('/documents/file') . '?id=' . (int) $document['id']); ?>" target="_blank"><?= h($document['original_name']); ?></a>
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
