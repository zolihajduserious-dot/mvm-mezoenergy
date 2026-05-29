<?php
declare(strict_types=1);

require_role(['admin']);

$flash = get_flash();
$customerId = isset($_GET['customer']) ? max(0, (int) $_GET['customer']) : 0;
$customer = null;
$account = null;
$requests = [];
$addresses = [];
$verificationRows = [];
$errors = [];

function admin_customer_view_date(?string $value): string
{
    $timestamp = $value !== null && trim($value) !== '' ? strtotime($value) : false;

    return $timestamp !== false ? date('Y.m.d. H:i', $timestamp) : '-';
}

function admin_customer_view_value(mixed $value): string
{
    $value = trim((string) $value);

    return $value !== '' ? $value : '-';
}

function admin_customer_view_account_for_customer(array $customer): ?array
{
    if (!users_table_exists()) {
        return null;
    }

    $customerId = (int) ($customer['id'] ?? 0);
    $customerUserId = (int) ($customer['user_id'] ?? 0);
    $verifiedSelect = user_email_verification_column_exists()
        ? ', `email_verified_at`'
        : ', NULL AS `email_verified_at`';

    if ($customerUserId > 0) {
        $user = db_query(
            'SELECT `id`, `name`, `email`, `role`, `customer_id`, `created_at`' . $verifiedSelect . '
             FROM `users`
             WHERE `id` = ?
             LIMIT 1',
            [$customerUserId]
        )->fetch();

        if (is_array($user)) {
            return $user;
        }
    }

    if ($customerId <= 0) {
        return null;
    }

    $user = db_query(
        'SELECT `id`, `name`, `email`, `role`, `customer_id`, `created_at`' . $verifiedSelect . '
         FROM `users`
         WHERE `role` = ? AND `customer_id` = ?
         ORDER BY `id` ASC
         LIMIT 1',
        ['customer', $customerId]
    )->fetch();

    return is_array($user) ? $user : null;
}

function admin_customer_view_requests(int $customerId): array
{
    if ($customerId <= 0 || !db_table_exists('connection_requests')) {
        return [];
    }

    return db_query(
        'SELECT `id`, `project_name`, `request_type`, `request_status`, `site_postal_code`, `site_address`, `submitted_at`, `closed_at`, `created_at`, `updated_at`
         FROM `connection_requests`
         WHERE `customer_id` = ?
         ORDER BY `created_at` DESC, `id` DESC
         LIMIT 100',
        [$customerId]
    )->fetchAll();
}

function admin_customer_view_addresses(int $customerId): array
{
    if ($customerId <= 0 || !db_table_exists('customer_addresses')) {
        return [];
    }

    return db_query(
        'SELECT `id`, `address_type`, `country`, `postal_code`, `city`, `address_line`, `created_at`
         FROM `customer_addresses`
         WHERE `customer_id` = ?
         ORDER BY `created_at` DESC, `id` DESC',
        [$customerId]
    )->fetchAll();
}

function admin_customer_view_verification_rows(?array $account): array
{
    if ($account === null || !email_verification_table_exists()) {
        return [];
    }

    $userId = (int) ($account['id'] ?? 0);

    if ($userId <= 0) {
        return [];
    }

    return db_query(
        'SELECT `id`, `expires_at`, `used_at`, `attempts`, `created_at`
         FROM `email_verification_codes`
         WHERE `user_id` = ?
         ORDER BY `created_at` DESC, `id` DESC
         LIMIT 5',
        [$userId]
    )->fetchAll();
}

try {
    if ($customerId <= 0) {
        $errors[] = 'Hiányzó vagy érvénytelen customer_id.';
    } elseif (!db_table_exists('customers')) {
        $errors[] = 'A customers tábla nem érhető el.';
    } else {
        $customer = find_customer($customerId);

        if ($customer === null) {
            $errors[] = 'Az ügyfél nem található.';
        } else {
            $account = admin_customer_view_account_for_customer($customer);
            $requests = admin_customer_view_requests($customerId);
            $addresses = admin_customer_view_addresses($customerId);
            $verificationRows = admin_customer_view_verification_rows($account);
        }
    }
} catch (Throwable $exception) {
    $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az ügyfél adatlap betöltése sikertelen.';
}

$customerName = (string) ($customer['requester_name'] ?? '');
$customerEmail = (string) ($customer['email'] ?? '');
$searchBack = $customerEmail !== '' ? $customerEmail : $customerName;
$lookupUrl = url_path('/admin/customer-lookup') . ($searchBack !== '' ? '?search=' . rawurlencode($searchBack) : '');
$legacyCrmUrl = $customerId > 0 ? url_path('/admin/customers') . '?customer=' . $customerId . '#customer-' . $customerId : url_path('/admin/customers');
$emailVerifiedAt = $account !== null ? trim((string) ($account['email_verified_at'] ?? '')) : '';
$emailStatus = $emailVerifiedAt !== '' ? 'Megerősítve' : 'Nincs megerősítve';
$primaryAddress = trim((string) ($customer['postal_code'] ?? '') . ' ' . (string) ($customer['city'] ?? '') . ', ' . (string) ($customer['postal_address'] ?? ''));
$mailingAddress = trim((string) ($customer['mailing_address'] ?? ''));
?>
<section class="admin-section customer-lookup-page customer-view-page">
    <div class="container admin-requests-container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin ügyfél adatlap</p>
                <h1><?= h($customerName !== '' ? $customerName : 'Ügyfél #' . $customerId); ?></h1>
                <p>Read-only nézet egyetlen ügyfél gyors, stabil megnyitásához.</p>
            </div>
            <div class="form-actions">
                <a class="button button-secondary" href="<?= h($lookupUrl); ?>">Vissza a keresőhöz</a>
                <a class="button button-secondary" href="<?= h($legacyCrmUrl); ?>">Régi CRM nézet</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php elseif ($customer !== null): ?>
            <div class="admin-grid summary-grid">
                <article>
                    <span>Customer ID</span>
                    <strong>#<?= (int) $customer['id']; ?></strong>
                    <p><?= h(admin_customer_view_value($customer['source'] ?? '')); ?></p>
                </article>
                <article>
                    <span>User ID</span>
                    <strong><?= $account !== null ? '#' . (int) $account['id'] : '-'; ?></strong>
                    <p><?= h($account !== null ? admin_customer_view_value($account['role'] ?? '') : 'Nincs kapcsolt user'); ?></p>
                </article>
                <article>
                    <span>Email státusz</span>
                    <strong><?= h($emailStatus); ?></strong>
                    <p><?= h(admin_customer_view_date($emailVerifiedAt)); ?></p>
                </article>
                <article>
                    <span>Portálmunkák</span>
                    <strong><?= count($requests); ?> db</strong>
                    <p><?= count($requests) > 0 ? 'Kapcsolódó munkaigények listázva lent.' : 'Ehhez az ügyfélhez még nem tartozik munkaigény.'; ?></p>
                </article>
            </div>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Ügyféladatok</h2>
                        <p>Ez a nézet nem módosít adatot.</p>
                    </div>
                </div>
                <dl class="admin-request-data-list">
                    <div><dt>Név</dt><dd><?= h(admin_customer_view_value($customer['requester_name'] ?? '')); ?></dd></div>
                    <div><dt>Email</dt><dd><?= h(admin_customer_view_value($customer['email'] ?? '')); ?></dd></div>
                    <div><dt>Telefon</dt><dd><?= h(admin_customer_view_value($customer['phone'] ?? '')); ?></dd></div>
                    <div><dt>Cím</dt><dd><?= h(admin_customer_view_value($primaryAddress)); ?></dd></div>
                    <div><dt>Levelezési cím</dt><dd><?= h(admin_customer_view_value($mailingAddress)); ?></dd></div>
                    <div><dt>Státusz</dt><dd><?= h(admin_customer_view_value($customer['status'] ?? '')); ?></dd></div>
                    <div><dt>Forrás</dt><dd><?= h(admin_customer_view_value($customer['source'] ?? '')); ?></dd></div>
                    <div><dt>Létrehozva</dt><dd><?= h(admin_customer_view_date($customer['created_at'] ?? null)); ?></dd></div>
                    <div><dt>Frissítve</dt><dd><?= h(admin_customer_view_date($customer['updated_at'] ?? null)); ?></dd></div>
                </dl>
            </section>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Kapcsolt felhasználói fiók</h2>
                        <p>User és email megerősítés állapota.</p>
                    </div>
                </div>
                <?php if ($account === null): ?>
                    <p class="request-admin-empty">Ehhez az ügyfélhez nem található kapcsolt felhasználói fiók.</p>
                <?php else: ?>
                    <dl class="admin-request-data-list">
                        <div><dt>User ID</dt><dd>#<?= (int) $account['id']; ?></dd></div>
                        <div><dt>Név</dt><dd><?= h(admin_customer_view_value($account['name'] ?? '')); ?></dd></div>
                        <div><dt>Email</dt><dd><?= h(admin_customer_view_value($account['email'] ?? '')); ?></dd></div>
                        <div><dt>Szerepkör</dt><dd><?= h(admin_customer_view_value($account['role'] ?? '')); ?></dd></div>
                        <div><dt>User customer_id</dt><dd><?= !empty($account['customer_id']) ? '#' . (int) $account['customer_id'] : '-'; ?></dd></div>
                        <div><dt>Email megerősítve</dt><dd><?= h($emailStatus); ?></dd></div>
                        <div><dt>Megerősítés ideje</dt><dd><?= h(admin_customer_view_date($account['email_verified_at'] ?? null)); ?></dd></div>
                        <div><dt>User létrehozva</dt><dd><?= h(admin_customer_view_date($account['created_at'] ?? null)); ?></dd></div>
                    </dl>
                <?php endif; ?>
            </section>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Kapcsolódó munkaigények</h2>
                        <p><?= count($requests); ?> db portálmunka.</p>
                    </div>
                </div>
                <?php if ($requests === []): ?>
                    <p class="request-admin-empty">Ehhez az ügyfélhez még nem tartozik munkaigény.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Munka</th>
                                    <th>Státusz</th>
                                    <th>Helyszín</th>
                                    <th>Dátum</th>
                                    <th>Művelet</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <?php
                                    $requestId = (int) ($request['id'] ?? 0);
                                    $requestUrl = url_path('/admin/minicrm-import') . '?request=' . $requestId . '#portal-work-' . $requestId;
                                    $siteAddress = trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));
                                    ?>
                                    <tr>
                                        <td><strong>#<?= $requestId; ?></strong><span><?= h(admin_customer_view_value($request['project_name'] ?? '')); ?></span></td>
                                        <td><span><?= h(admin_customer_view_value($request['request_status'] ?? '')); ?></span><span><?= h(admin_customer_view_value($request['request_type'] ?? '')); ?></span></td>
                                        <td><?= h(admin_customer_view_value($siteAddress)); ?></td>
                                        <td><span>Létrehozva: <?= h(admin_customer_view_date($request['created_at'] ?? null)); ?></span><span>Lezárva: <?= h(admin_customer_view_date($request['closed_at'] ?? null)); ?></span></td>
                                        <td class="table-actions stacked"><a href="<?= h($requestUrl); ?>">Munka megnyitása</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Címrekordok</h2>
                        <p><?= count($addresses); ?> db külön címrekord.</p>
                    </div>
                </div>
                <?php if ($addresses === []): ?>
                    <p class="request-admin-empty">Nincs külön customer_addresses rekord. Normál regisztrációnál az elsődleges cím a customer rekord mezőiben is lehet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Típus</th>
                                    <th>Cím</th>
                                    <th>Létrehozva</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($addresses as $address): ?>
                                    <?php $addressLine = trim((string) ($address['postal_code'] ?? '') . ' ' . (string) ($address['city'] ?? '') . ', ' . (string) ($address['address_line'] ?? '')); ?>
                                    <tr>
                                        <td><?= h(admin_customer_view_value($address['address_type'] ?? '')); ?></td>
                                        <td><?= h(admin_customer_view_value($addressLine)); ?></td>
                                        <td><?= h(admin_customer_view_date($address['created_at'] ?? null)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="auth-panel">
                <div class="admin-header compact">
                    <div>
                        <h2>Email megerősítés</h2>
                        <p>Legutóbbi verifikációs rekordok kódhash nélkül.</p>
                    </div>
                </div>
                <?php if ($verificationRows === []): ?>
                    <p class="request-admin-empty">Nincs megjeleníthető email verifikációs rekord.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Létrehozva</th>
                                    <th>Lejárat</th>
                                    <th>Felhasználva</th>
                                    <th>Próbálkozások</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($verificationRows as $verificationRow): ?>
                                    <tr>
                                        <td>#<?= (int) ($verificationRow['id'] ?? 0); ?></td>
                                        <td><?= h(admin_customer_view_date($verificationRow['created_at'] ?? null)); ?></td>
                                        <td><?= h(admin_customer_view_date($verificationRow['expires_at'] ?? null)); ?></td>
                                        <td><?= h(admin_customer_view_date($verificationRow['used_at'] ?? null)); ?></td>
                                        <td><?= (int) ($verificationRow['attempts'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</section>
