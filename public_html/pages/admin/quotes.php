<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$flash = get_flash();
$quotes = [];
$statusLabels = quote_status_labels();

try {
    $quotes = all_quotes();
} catch (Throwable $exception) {
    $flash = ['type' => 'error', 'message' => APP_DEBUG ? $exception->getMessage() : 'Az ajánlatok betöltése sikertelen.'];
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Ajánlatok</h1>
                <p>Helyszíni felmérések, ügyfelekhez kapcsolt ajánlatok és ügyfél-visszajelzések.</p>
            </div>
            <a class="button" href="<?= h(url_path('/admin/customers')); ?>">Ügyfél kiválasztása</a>
        </div>
        <?php if ($flash !== null): ?><div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div><?php endif; ?>
        <?php if ($quotes === []): ?>
            <div class="empty-state"><h2>Még nincs ajánlat</h2><p>Válassz ki egy ügyfelet, majd indíts ajánlatot.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Ajánlat</th><th>Ügyfél</th><th>Státusz</th><th>Összeg</th><th>Műveletek</th></tr></thead>
                    <tbody>
                        <?php foreach ($quotes as $quote): ?>
                            <?php $status = (string) ($quote['status'] ?? 'draft'); ?>
                            <tr>
                                <td>
                                    <strong><?= h($quote['quote_number']); ?></strong>
                                    <span><?= h($quote['subject']); ?></span>
                                    <?php if (!empty($quote['customer_response_message'])): ?>
                                        <span class="quote-admin-note"><?= nl2br(h($quote['customer_response_message'])); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($quote['requester_name']); ?><span><?= h($quote['email']); ?></span></td>
                                <td>
                                    <span class="status-badge status-badge-<?= h($status); ?>"><?= h($statusLabels[$status] ?? $status); ?></span>
                                    <?php if ($status === 'accepted' && !empty($quote['accepted_at'])): ?>
                                        <span>Elfogadva: <?= h($quote['accepted_at']); ?></span>
                                    <?php elseif ($status === 'consultation_requested' && !empty($quote['consultation_requested_at'])): ?>
                                        <span>Egyeztetés kérve: <?= h($quote['consultation_requested_at']); ?></span>
                                    <?php elseif (!empty($quote['sent_at'])): ?>
                                        <span>Kiküldve: <?= h($quote['sent_at']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h(quote_display_total($quote)); ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= h(url_path('/admin/quotes/edit') . '?id=' . (int) $quote['id']); ?>">Szerkesztés</a>
                                        <a href="<?= h(url_path('/admin/quotes/send') . '?id=' . (int) $quote['id']); ?>">Küldés</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
