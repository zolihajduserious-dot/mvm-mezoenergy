<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$flash = get_flash();
$customers = [];
$requestsByCustomer = [];
$requestStatusLabels = connection_request_status_labels();

if (is_post() && ($_POST['action'] ?? '') === 'delete_customer') {
    require_valid_csrf_token();

    if (!is_admin_user()) {
        set_flash('error', 'Ügyfelet törölni csak admin jogosultsággal lehet.');
        redirect('/admin/customers');
    }

    $deleteCustomerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);

    if (!$deleteCustomerId) {
        set_flash('error', 'Az ügyfél nem található.');
        redirect('/admin/customers');
    }

    try {
        $deleteSummary = delete_customer_with_related_data((int) $deleteCustomerId);
        set_flash(
            'success',
            'Az ügyfél törölve: ' . $deleteSummary['customer_name']
                . '. Kapcsolódó adatok: ' . (int) $deleteSummary['requests'] . ' igény, '
                . (int) $deleteSummary['quotes'] . ' árajánlat, '
                . (int) $deleteSummary['users'] . ' felhasználói fiók, '
                . (int) $deleteSummary['files'] . ' fájl.'
        );
    } catch (Throwable $exception) {
        set_flash('error', APP_DEBUG ? $exception->getMessage() : 'Az ügyfél törlése sikertelen.');
    }

    redirect('/admin/customers');
}

try {
    $customers = all_customers();
    $requestsByCustomer = connection_request_summaries_for_customers(array_column($customers, 'id'));
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
                    <thead><tr><th>Ügyfél</th><th>Felelős</th><th>Telefon</th><th>Cím</th><th>Státusz</th><th>Igények</th><th>Műveletek</th></tr></thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <?php $customerRequests = $requestsByCustomer[(int) $customer['id']] ?? []; ?>
                            <tr>
                                <td><strong><?= h($customer['requester_name']); ?></strong><span><?= h($customer['email']); ?></span></td>
                                <td><?= h(customer_owner_label($customer)); ?></td>
                                <td><?= h($customer['phone']); ?></td>
                                <td><?= h($customer['postal_code']); ?> <?= h($customer['city']); ?>, <?= h($customer['postal_address']); ?></td>
                                <td><?= h($customer['status']); ?></td>
                                <td>
                                    <div class="inline-link-list customer-request-links">
                                        <?php foreach ($customerRequests as $customerRequest): ?>
                                            <?php
                                            $requestLabel = trim((string) ($customerRequest['project_name'] ?? ''));
                                            $requestLabel = $requestLabel !== '' ? $requestLabel : '#' . (int) $customerRequest['id'];
                                            $requestStatus = (string) ($customerRequest['request_status'] ?? 'draft');
                                            ?>
                                            <a href="<?= h(url_path('/admin/connection-requests/edit') . '?id=' . (int) $customerRequest['id']); ?>">
                                                <?= h($requestLabel); ?>
                                                <span><?= h($requestStatusLabels[$requestStatus] ?? $requestStatus); ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                        <a href="<?= h(url_path('/admin/connection-requests/edit') . '?customer_id=' . (int) $customer['id']); ?>">Új igény</a>
                                    </div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= h(url_path('/admin/customers/edit') . '?id=' . (int) $customer['id']); ?>">Szerkesztes</a>
                                        <a href="<?= h(url_path('/admin/quotes/create') . '?customer_id=' . (int) $customer['id']); ?>">Ajánlat</a>
                                        <?php if (is_admin_user()): ?>
                                            <form method="post" action="<?= h(url_path('/admin/customers')); ?>" onsubmit="return confirm('Biztosan törlöd ezt az ügyfelet és minden kapcsolódó adatát? Ez nem visszavonható.');">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_customer">
                                                <input type="hidden" name="customer_id" value="<?= (int) $customer['id']; ?>">
                                                <button class="table-action-button table-action-danger" type="submit">Törlés</button>
                                            </form>
                                        <?php endif; ?>
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
