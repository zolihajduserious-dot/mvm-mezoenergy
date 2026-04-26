<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$flash = get_flash();
$customers = [];

try {
    $customers = all_customers();
} catch (Throwable $exception) {
    $flash = ['type' => 'error', 'message' => APP_DEBUG ? $exception->getMessage() : 'Az ügyfelek betöltése sikertelen.'];
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Ugyfelek</h1>
                <p>Ügyféladatok kezelése és új helyszíni ajánlat indítása.</p>
            </div>
            <a class="button" href="<?= h(url_path('/admin/customers/edit')); ?>">Új ügyfél</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($customers === []): ?>
            <div class="empty-state"><h2>Még nincs ügyfél</h2><p>Hozd létre az első ügyfelet, vagy várj ügyfélregisztrációra.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Ügyfél</th><th>Felelős</th><th>Telefon</th><th>Cím</th><th>Státusz</th><th>Műveletek</th></tr></thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><strong><?= h($customer['requester_name']); ?></strong><span><?= h($customer['email']); ?></span></td>
                                <td><?= h(customer_owner_label($customer)); ?></td>
                                <td><?= h($customer['phone']); ?></td>
                                <td><?= h($customer['postal_code']); ?> <?= h($customer['city']); ?>, <?= h($customer['postal_address']); ?></td>
                                <td><?= h($customer['status']); ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= h(url_path('/admin/customers/edit') . '?id=' . (int) $customer['id']); ?>">Szerkesztes</a>
                                        <a href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . (int) $customer['id']); ?>">Ajánlat</a>
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
