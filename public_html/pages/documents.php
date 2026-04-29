<?php
declare(strict_types=1);

$documents = download_documents(true);
$uploadUrl = '/customer/work-requests';
$uploadPortalLabel = 'Ügyfélportál megnyitása';

if (is_logged_in() && is_staff_user()) {
    $uploadUrl = '/admin/minicrm-import#portal-works';
    $uploadPortalLabel = 'Admin munkák megnyitása';
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

        <section class="download-panel">
            <div>
                <h2>Kitöltés és visszatöltés</h2>
                <p>Töltsd le a szükséges dokumentumokat, töltsd ki őket, majd az ügyfél- vagy generálkivitelezői portálon az adott igényhez töltsd fel a kész fájlokat. A meghatalmazás később pótolható, és az adott igény oldaláról online is aláírható.</p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path($uploadUrl)); ?>"><?= h($uploadPortalLabel); ?></a>
        </section>

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
