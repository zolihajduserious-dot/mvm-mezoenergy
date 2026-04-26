<?php
declare(strict_types=1);

require_role(['customer']);

$customer = current_customer();
$quotes = $customer !== null ? quotes_for_customer((int) $customer['id']) : [];
$statusLabels = quote_status_labels();
?>
<section class="content-section">
    <div class="container">
        <div class="section-heading">
            <p class="eyebrow">Ügyfélportál</p>
            <h1>Ajánlataim</h1>
            <p>Itt láthatod a Mező Energy Kft. által készített ajánlatokat, PDF fájlokat és elfogadási állapotokat.</p>
        </div>

        <?php if ($customer === null): ?>
            <div class="alert alert-info"><p>Előbb töltsd ki az ügyféladatlapot.</p></div>
            <a class="button" href="<?= h(url_path('/customer/profile')); ?>">Adatlap kitöltése</a>
        <?php elseif ($quotes === []): ?>
            <div class="empty-state"><h2>Még nincs ajánlat</h2><p>Amint elkészül az ajánlatod, itt fog megjelenni.</p></div>
        <?php else: ?>
            <div class="portal-card-grid">
                <?php foreach ($quotes as $quote): ?>
                    <?php $status = (string) ($quote['status'] ?? 'draft'); ?>
                    <article class="portal-card">
                        <div class="portal-card-header">
                            <div>
                                <span class="portal-kicker"><?= h($quote['created_at']); ?></span>
                                <h2><?= h($quote['quote_number']); ?></h2>
                                <p><?= h($quote['subject']); ?></p>
                            </div>
                            <span class="status-badge status-badge-<?= h($status); ?>"><?= h($statusLabels[$status] ?? $status); ?></span>
                        </div>

                        <div class="portal-card-details portal-card-details-compact">
                            <div>
                                <span>Összeg</span>
                                <strong><?= h(quote_display_total($quote)); ?></strong>
                            </div>
                            <div>
                                <span>Státusz</span>
                                <strong><?= h($statusLabels[$status] ?? $status); ?></strong>
                            </div>
                            <?php if ($status === 'consultation_requested' && !empty($quote['consultation_requested_at'])): ?>
                                <div>
                                    <span>Egyeztetés kérve</span>
                                    <strong><?= h($quote['consultation_requested_at']); ?></strong>
                                </div>
                            <?php elseif ($status === 'accepted' && !empty($quote['accepted_at'])): ?>
                                <div>
                                    <span>Elfogadás ideje</span>
                                    <strong><?= h($quote['accepted_at']); ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($quote['customer_response_message'])): ?>
                            <div class="alert alert-info"><p><?= nl2br(h($quote['customer_response_message'])); ?></p></div>
                        <?php endif; ?>

                        <div class="portal-card-files">
                            <h3>Műveletek</h3>
                            <div class="inline-link-list">
                                <a href="<?= h(url_path('/customer/quotes/view') . '?id=' . (int) $quote['id']); ?>">Megnyitás</a>
                                <?php if (quote_file_is_available($quote)): ?>
                                    <a href="<?= h(url_path('/customer/quotes/file') . '?id=' . (int) $quote['id']); ?>" target="_blank">Letöltés</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
