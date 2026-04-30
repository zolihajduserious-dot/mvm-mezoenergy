<?php
declare(strict_types=1);

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$token = trim((string) ($_GET['token'] ?? ''));
$request = $requestId ? find_connection_request($requestId) : null;

if ($request === null || !authorization_signature_token_is_valid($request, $token)) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

$errors = [];
$flash = get_flash();

if (is_post()) {
    require_valid_csrf_token();

    $uploadedFiles = array_values(array_filter(
        uploaded_files_for_key($_FILES, 'file_authorization'),
        static fn (?array $file): bool => uploaded_file_is_present($file)
    ));

    if ($uploadedFiles === []) {
        $errors[] = 'Válassz feltöltendő aláírt meghatalmazást fotóként vagy PDF-ként.';
    }

    foreach ($uploadedFiles as $file) {
        $errors = array_merge($errors, validate_portal_file_upload($file, 'Aláírt meghatalmazás'));
    }

    if ($errors === []) {
        try {
            $messages = handle_connection_request_uploads((int) $request['id'], $_FILES, true);

            if ($messages !== []) {
                set_flash('error', 'Néhány fájl nem lett mentve: ' . implode(' ', $messages));
            } else {
                set_flash('success', 'Köszönjük, az aláírt meghatalmazást elmentettük az adatlapodra.');
            }

            redirect('/authorization-upload?id=' . (int) $request['id'] . '&token=' . rawurlencode($token));
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'A meghatalmazás feltöltése sikertelen.';
        }
    }
}

$authorizationDone = connection_request_has_file_type((int) $request['id'], 'authorization');
?>
<section class="content-section authorization-sign-section">
    <div class="container">
        <div class="section-heading">
            <p class="eyebrow">Meghatalmazás</p>
            <h1>Aláírt meghatalmazás feltöltése</h1>
            <p>A kinyomtatott, aláírt meghatalmazást itt tudod visszatölteni fotóként vagy beszkennelt PDF-ként. A fájl közvetlenül a saját ügyfél adatlapodra kerül.</p>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($authorizationDone): ?>
            <div class="alert alert-success"><p>Ehhez az igényhez már van rögzített meghatalmazás. Új feltöltéssel új verzió kerül az adatlapra.</p></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="auth-panel form-block">
            <h2>Igény adatai</h2>
            <dl class="admin-request-data-list">
                <div>
                    <dt>Ügyfél</dt>
                    <dd><?= h((string) ($request['requester_name'] ?? '-')); ?></dd>
                </div>
                <div>
                    <dt>Email</dt>
                    <dd><?= h((string) ($request['email'] ?? '-')); ?></dd>
                </div>
                <div>
                    <dt>Telefonszám</dt>
                    <dd><?= h((string) ($request['phone'] ?? '-')); ?></dd>
                </div>
                <div>
                    <dt>Felhasználási hely</dt>
                    <dd><?= h(trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? '')) ?: '-'); ?></dd>
                </div>
            </dl>
        </section>

        <form class="form" method="post" enctype="multipart/form-data" action="<?= h(url_path('/authorization-upload') . '?id=' . (int) $request['id'] . '&token=' . rawurlencode($token)); ?>">
            <?= csrf_field(); ?>

            <section class="auth-panel form-block">
                <h2>Feltöltés</h2>
                <label for="file_authorization">Aláírt meghatalmazás fotó vagy PDF</label>
                <input id="file_authorization" name="file_authorization[]" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" multiple required>
                <p class="muted-text">Használható fájltípusok: PDF, JPG, PNG vagy WEBP. Több oldal esetén több fotót is kiválaszthatsz.</p>
            </section>

            <div class="form-actions">
                <button class="button" type="submit">Meghatalmazás feltöltése</button>
            </div>
        </form>
    </div>
</section>
