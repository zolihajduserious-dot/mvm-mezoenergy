<?php
declare(strict_types=1);

ensure_default_download_documents();

$emailErrors = [];
$emailForm = normalize_download_document_email_data([]);

if (is_post()) {
    require_valid_csrf_token();

    if ((string) ($_POST['action'] ?? '') === 'send_download_document_email') {
        $emailForm = normalize_download_document_email_data($_POST);

        if (!is_logged_in() || !is_staff_user()) {
            $emailErrors[] = 'Dokumentumot emailben csak belsős felhasználó küldhet.';
        } else {
            $emailErrors = validate_download_document_email_data($emailForm);
        }

        if ($emailErrors === []) {
            $result = send_download_document_to_customer($emailForm);
            set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A dokumentum email küldése sikertelen.'));
            redirect('/documents');
        }
    }
}

$documents = download_documents(true);
$flash = get_flash();
$uploadUrl = '/customer/work-requests';
$uploadPortalLabel = 'Ügyfélportál megnyitása';

if (is_logged_in() && is_staff_user()) {
    $uploadUrl = '/admin/customer-lookup';
    $uploadPortalLabel = 'Admin ügyfélkereső megnyitása';
} elseif (is_logged_in() && is_general_contractor_user()) {
    $uploadUrl = '/contractor/work-requests';
    $uploadPortalLabel = 'Generálkivitelezői portál megnyitása';
} elseif (is_logged_in() && is_electrician_user()) {
    $uploadUrl = '/electrician/work-requests';
    $uploadPortalLabel = 'Szerelői portál megnyitása';
} elseif (!is_logged_in()) {
    $uploadUrl = '/login';
    $uploadPortalLabel = 'Belépés a feltöltéshez';
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Dokumentumtár</p>
                <h1>Letölthető dokumentumok</h1>
                <p>Meghatalmazások, hozzájáruló nyilatkozatok és egyéb ügyintézési dokumentumok.</p>
            </div>
            <a class="button" href="<?= h(url_path($uploadUrl)); ?>">Kitöltött dokumentum feltöltése</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($emailErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($emailErrors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="download-panel">
            <div>
                <h2>Kitöltés és visszatöltés</h2>
                <p>Töltsd le a szükséges dokumentumokat, töltsd ki őket, majd az ügyfél- vagy generálkivitelezői portálon az adott igényhez töltsd fel a kész fájlokat. A meghatalmazás később pótolható, és az adott igény oldaláról online is aláírható.</p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path($uploadUrl)); ?>"><?= h($uploadPortalLabel); ?></a>
        </section>

        <?php if (is_logged_in() && is_staff_user()): ?>
            <section class="download-panel document-email-panel">
                <div class="document-email-copy">
                    <h2>Dokumentum küldése emailben</h2>
                    <p>Ha az ügyfél nem tudja letölteni a nyomtatványt, innen elküldhető neki csatolmányként. Az email tartalmaz egy rövid Mező Energy tájékoztatót és regisztrációs linket is.</p>
                </div>

                <form class="form document-email-form" method="post" action="<?= h(url_path('/documents')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="send_download_document_email">

                    <div class="document-email-fields">
                        <div>
                            <label for="document_id">Dokumentum</label>
                            <select id="document_id" name="document_id" required <?= $documents === [] ? 'disabled' : ''; ?>>
                                <option value="">Válassz dokumentumot</option>
                                <?php foreach ($documents as $document): ?>
                                    <option value="<?= (int) $document['id']; ?>" <?= (int) $emailForm['document_id'] === (int) $document['id'] ? 'selected' : ''; ?>>
                                        <?= h($document['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="recipient_name">Ügyfél neve</label>
                            <input id="recipient_name" name="recipient_name" value="<?= h($emailForm['recipient_name']); ?>" placeholder="Példa: Kovács Anna">
                        </div>

                        <div>
                            <label for="recipient_email">Ügyfél email címe</label>
                            <input id="recipient_email" name="recipient_email" type="email" value="<?= h($emailForm['recipient_email']); ?>" placeholder="ugyfel@email.hu" required>
                        </div>
                    </div>

                    <label for="message">Kiegészítő üzenet</label>
                    <textarea id="message" name="message" rows="4" placeholder="Ide kerülhet egy rövid, személyes megjegyzés az ügyfélnek."><?= h($emailForm['message']); ?></textarea>

                    <div class="document-email-actions">
                        <button class="button" type="submit" <?= $documents === [] ? 'disabled' : ''; ?>>Dokumentum elküldése</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($documents === []): ?>
            <div class="empty-state">
                <h2>Jelenleg nincs letölthető dokumentum</h2>
                <p>A dokumentumok feltöltés után ezen az oldalon jelennek meg.</p>
            </div>
        <?php else: ?>
            <div class="document-grid">
                <?php foreach ($documents as $document): ?>
                    <article class="document-card">
                        <span><?= h($document['category']); ?></span>
                        <h2><?= h($document['title']); ?></h2>
                        <?php if (!empty($document['description'])): ?>
                            <p><?= nl2br(h($document['description'])); ?></p>
                        <?php endif; ?>
                        <a class="button button-secondary" href="<?= h(url_path('/documents/file') . '?id=' . (int) $document['id']); ?>">Letöltés</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
