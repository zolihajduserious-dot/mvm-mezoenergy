<?php
declare(strict_types=1);

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$token = trim((string) ($_GET['token'] ?? ''));
$request = $requestId ? find_connection_request($requestId) : null;

if ($request === null || !customer_document_upload_token_is_valid($request, $token)) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

$definitions = customer_document_upload_definitions();
$requestedTypes = customer_document_upload_requested_types_from_source($_GET['types'] ?? '');

if ($requestedTypes === []) {
    $requestedTypes = customer_document_upload_default_types((int) $request['id']);
}

if ($requestedTypes === []) {
    $requestedTypes = array_keys($definitions);
}

$errors = [];
$flash = get_flash();

if (is_post()) {
    require_valid_csrf_token();

    $postedTypes = customer_document_upload_requested_types_from_source($_POST['requested_document_types'] ?? []);

    if ($postedTypes !== []) {
        $requestedTypes = $postedTypes;
    }

    $filteredFiles = [];
    $hasAnyUpload = false;

    foreach ($requestedTypes as $fileType) {
        $definition = $definitions[$fileType] ?? null;

        if ($definition === null) {
            continue;
        }

        $fieldName = 'file_' . $fileType;
        $uploadedFiles = array_values(array_filter(
            uploaded_files_for_key($_FILES, $fieldName),
            static fn (?array $file): bool => uploaded_file_is_present($file)
        ));

        if (isset($_FILES[$fieldName])) {
            $filteredFiles[$fieldName] = $_FILES[$fieldName];
        }

        if ($uploadedFiles === []) {
            continue;
        }

        $hasAnyUpload = true;

        foreach ($uploadedFiles as $file) {
            $errors = array_merge($errors, customer_document_upload_validate_file($fileType, $definition, $file));
        }
    }

    if (!$hasAnyUpload) {
        $errors[] = 'Válassz legalább egy feltöltendő dokumentumot vagy fotót.';
    }

    if ($errors === []) {
        try {
            $messages = handle_connection_request_uploads(
                (int) $request['id'],
                $filteredFiles,
                true,
                'Nyilvános dokumentumfeltöltő link'
            );

            if ($messages !== []) {
                set_flash('error', 'Néhány fájl nem lett mentve: ' . implode(' ', $messages));
            } else {
                set_flash('success', 'Köszönjük, a feltöltött fájlokat elmentettük az adatlapra.');
            }

            redirect(customer_document_upload_path($request, $token, $requestedTypes));
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'A dokumentum feltöltése sikertelen.';
        }
    }
}

$filesByType = [];

foreach (connection_request_files((int) $request['id']) as $file) {
    $fileType = (string) ($file['file_type'] ?? '');

    if (!isset($definitions[$fileType]) || !is_file((string) ($file['storage_path'] ?? ''))) {
        continue;
    }

    $filesByType[$fileType][] = $file;
}

$photoExamples = customer_document_upload_photo_examples();
$siteAddress = trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));
?>
<section class="content-section authorization-sign-section customer-document-upload-section">
    <div class="container">
        <div class="section-heading">
            <p class="eyebrow">Dokumentumfeltöltés</p>
            <h1>Hiányzó dokumentumok feltöltése</h1>
            <p>Itt regisztráció nélkül tudod feltölteni a bekért dokumentumokat és fotókat. A fájlok közvetlenül a saját ügy adatlapjára kerülnek.</p>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
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
                    <dd><?= h($siteAddress !== '' ? $siteAddress : '-'); ?></dd>
                </div>
            </dl>
        </section>

        <section class="auth-panel form-block">
            <div class="customer-upload-section-head">
                <div>
                    <h2>Már feltöltött fájlok</h2>
                    <p class="muted-text">Ha egy dokumentum már szerepel itt, új feltöltéssel új verziót tudsz küldeni.</p>
                </div>
            </div>

            <?php
            $hasVisibleFiles = false;
            foreach ($requestedTypes as $fileType) {
                if (($filesByType[$fileType] ?? []) !== []) {
                    $hasVisibleFiles = true;
                    break;
                }
            }
            ?>

            <?php if ($hasVisibleFiles): ?>
                <div class="customer-upload-existing-list">
                    <?php foreach ($requestedTypes as $fileType): ?>
                        <?php if (($filesByType[$fileType] ?? []) === [] || !isset($definitions[$fileType])) { continue; } ?>
                        <div class="customer-upload-existing-group">
                            <strong><?= h((string) $definitions[$fileType]['label']); ?></strong>
                            <div class="inline-link-list">
                                <?php foreach ($filesByType[$fileType] as $file): ?>
                                    <a href="<?= h(url_path('/document-upload/file') . '?id=' . (int) $file['id'] . '&request=' . (int) $request['id'] . '&token=' . rawurlencode($token)); ?>" target="_blank">
                                        <?= h((string) ($file['original_name'] ?? $file['label'] ?? 'Fájl')); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted-text">Ehhez a bekéréshez még nincs feltöltött fájl.</p>
            <?php endif; ?>
        </section>

        <form class="form" method="post" enctype="multipart/form-data" action="<?= h(customer_document_upload_path($request, $token, $requestedTypes)); ?>">
            <?= csrf_field(); ?>

            <section class="auth-panel form-block">
                <div class="customer-upload-section-head">
                    <div>
                        <h2>Bekért dokumentumok és fotók</h2>
                        <p class="muted-text">Több fájlt is kiválaszthatsz egyszerre. PDF, JPG, PNG vagy WEBP biztosan használható; fotóknál a telefon kamerája is megnyitható.</p>
                    </div>
                </div>

                <div class="customer-upload-grid">
                    <?php foreach ($requestedTypes as $fileType): ?>
                        <?php
                        $definition = $definitions[$fileType] ?? null;
                        if ($definition === null) {
                            continue;
                        }
                        $inputId = 'file_' . $fileType;
                        $isImage = ($definition['kind'] ?? '') === 'image';
                        $hasFile = customer_document_upload_type_has_file((int) $request['id'], $fileType);
                        $example = $photoExamples[$fileType] ?? null;
                        ?>
                        <article class="customer-upload-card">
                            <input type="hidden" name="requested_document_types[]" value="<?= h($fileType); ?>">
                            <div class="customer-upload-card-head">
                                <div>
                                    <h3><?= h((string) $definition['label']); ?></h3>
                                    <p><?= h(customer_document_upload_type_help_text($fileType)); ?></p>
                                </div>
                                <span class="status-badge <?= $hasFile ? 'status-badge-sent' : 'status-badge-pending'; ?>">
                                    <?= $hasFile ? 'Már feltöltve' : 'Hiányzik'; ?>
                                </span>
                            </div>

                            <?php if ($example !== null): ?>
                                <div class="customer-upload-example">
                                    <div class="customer-upload-example-visual customer-upload-example-<?= h((string) $example['variant']); ?>" aria-hidden="true">
                                        <span></span><span></span><span></span><span></span>
                                    </div>
                                    <div>
                                        <strong><?= h((string) $example['title']); ?></strong>
                                        <p><?= h((string) $example['body']); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <label for="<?= h($inputId); ?>">Fájl kiválasztása</label>
                            <input
                                id="<?= h($inputId); ?>"
                                name="<?= h($inputId); ?>[]"
                                type="file"
                                accept="<?= h(customer_document_upload_accept($fileType, $definition)); ?>"
                                <?= $isImage ? 'capture="environment"' : ''; ?>
                                multiple
                            >
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="form-actions">
                <button class="button" type="submit">Dokumentumok feltöltése</button>
            </div>
        </form>
    </div>
</section>
