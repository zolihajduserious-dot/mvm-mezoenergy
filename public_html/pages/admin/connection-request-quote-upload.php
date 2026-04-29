<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$request = $requestId ? find_connection_request($requestId) : null;

if ($request === null) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

$schemaErrors = quote_upload_schema_errors();
$errors = [];
$form = normalize_uploaded_quote_data([], $request);

if (is_post()) {
    require_valid_csrf_token();

    $form = normalize_uploaded_quote_data($_POST, $request);
    $file = $_FILES['quote_file'] ?? null;

    if ($schemaErrors !== []) {
        $errors = array_merge($errors, $schemaErrors);
    } else {
        $errors = validate_uploaded_quote_data($form, is_array($file) ? $file : null);
    }

    if ($errors === []) {
        try {
            $quoteId = create_uploaded_quote_for_request((int) $request['id'], $form, $file);
            $mailResult = send_uploaded_quote_notification($quoteId);

            if ($mailResult['ok']) {
                set_flash('success', 'Az árajánlat feltöltve, és az ügyfél értesítése elküldve.');
            } else {
                set_flash('error', 'Az árajánlat feltöltve, de az értesítő e-mail nem ment ki: ' . $mailResult['message']);
            }

            redirect('/admin/minicrm-import?request=' . (int) $request['id'] . '#portal-work-' . (int) $request['id']);
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az árajánlat feltöltése sikertelen.';
        }
    }
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Árajánlat feltöltése</h1>
                <p><?= h($request['requester_name']); ?> - <?= h($request['project_name']); ?></p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path('/admin/minicrm-import') . '?request=' . (int) $request['id'] . '#portal-work-' . (int) $request['id']); ?>">Vissza a munkához</a>
        </div>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-info">
                <p>Az árajánlat-feltöltéshez futtasd le phpMyAdminban a <strong>database/upgrade_connection_requests.sql</strong> fájlt.</p>
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="form-grid two">
            <section class="auth-panel">
                <h2>Ügyfél és munka</h2>
                <p><strong><?= h($request['requester_name']); ?></strong></p>
                <p><?= h($request['email']); ?> | <?= h($request['phone']); ?></p>
                <p><?= h($request['site_postal_code'] . ' ' . $request['site_address']); ?></p>
                <p>HRSZ: <?= h($request['hrsz'] ?: '-'); ?></p>
            </section>

            <section class="auth-panel">
                <h2>Kész árajánlat feltöltése</h2>
                <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/admin/connection-requests/quote-upload') . '?id=' . (int) $request['id']); ?>">
                    <?= csrf_field(); ?>

                    <label for="subject">Árajánlat tárgya</label>
                    <input id="subject" name="subject" value="<?= h($form['subject']); ?>" required>

                    <label for="customer_message">Üzenet az ügyfélnek</label>
                    <textarea id="customer_message" name="customer_message" rows="4"><?= h($form['customer_message']); ?></textarea>

                    <label for="quote_file">Árajánlat fájl</label>
                    <input id="quote_file" name="quote_file" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required>
                    <p class="muted-text">A feltöltött árajánlat megjelenik az ügyfél Ajánlataim oldalán, és erről e-mail értesítést kap.</p>

                    <button class="button" type="submit">Árajánlat feltöltése és értesítés küldése</button>
                </form>
            </section>
        </div>
    </div>
</section>
