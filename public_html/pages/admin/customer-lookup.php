<?php
declare(strict_types=1);

require_role(['admin']);

$flash = get_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$results = [];
$lookupErrors = [];

function admin_customer_lookup_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function admin_customer_lookup_date(?string $value): string
{
    $timestamp = $value !== null && trim($value) !== '' ? strtotime($value) : false;

    return $timestamp !== false ? date('Y.m.d. H:i', $timestamp) : '-';
}

function admin_customer_lookup_status_label(array $row): string
{
    if (trim((string) ($row['email_verified_at'] ?? '')) !== '') {
        return 'Megerősítve';
    }

    if (trim((string) ($row['last_verification_code_created_at'] ?? '')) !== '') {
        return 'Kód kiküldve';
    }

    return 'Nincs megerősítve';
}

function admin_customer_lookup_link_status(array $row): string
{
    $customerId = (int) ($row['customer_id'] ?? 0);
    $userId = (int) ($row['user_id'] ?? 0);
    $customerUserId = (int) ($row['customer_user_id'] ?? 0);
    $userCustomerId = (int) ($row['user_customer_id'] ?? 0);

    if ($userId <= 0) {
        return 'Nincs user kapcsolat';
    }

    if ($customerUserId === $userId && $userCustomerId === $customerId) {
        return 'Kétirányú kapcsolat';
    }

    if ($customerUserId === $userId || $userCustomerId === $customerId) {
        return 'Részleges kapcsolat';
    }

    return 'Eltérő kapcsolat';
}

function admin_customer_lookup_rows(string $search, int $limit = 50): array
{
    if (!db_table_exists('customers')) {
        throw new RuntimeException('A customers tábla nem érhető el.');
    }

    $limit = max(1, min(100, $limit));
    $hasUsers = users_table_exists();
    $hasEmailVerification = $hasUsers && user_email_verification_column_exists();
    $hasVerificationCodes = email_verification_table_exists();
    $hasRequests = db_table_exists('connection_requests');
    $hasAddresses = db_table_exists('customer_addresses');
    $select = 'SELECT c.`id` AS `customer_id`, c.`user_id` AS `customer_user_id`, c.`requester_name`, c.`company_name`,
                      c.`email` AS `customer_email`, c.`phone` AS `customer_phone`, c.`status` AS `customer_status`,
                      c.`source` AS `customer_source`, c.`created_at` AS `customer_created_at`,
                      c.`updated_at` AS `customer_updated_at`';
    $joins = '';

    if ($hasUsers) {
        $select .= ', COALESCE(user_by_id.`id`, user_by_customer.`id`) AS `user_id`,
                       COALESCE(user_by_id.`name`, user_by_customer.`name`) AS `user_name`,
                       COALESCE(user_by_id.`email`, user_by_customer.`email`) AS `user_email`,
                       COALESCE(user_by_id.`role`, user_by_customer.`role`) AS `user_role`,
                       COALESCE(user_by_id.`customer_id`, user_by_customer.`customer_id`) AS `user_customer_id`,
                       COALESCE(user_by_id.`created_at`, user_by_customer.`created_at`) AS `user_created_at`';
        $select .= $hasEmailVerification
            ? ', COALESCE(user_by_id.`email_verified_at`, user_by_customer.`email_verified_at`) AS `email_verified_at`'
            : ', NULL AS `email_verified_at`';
        $joins .= ' LEFT JOIN `users` user_by_id ON user_by_id.`id` = c.`user_id`
                    LEFT JOIN (
                        SELECT `customer_id`, MIN(`id`) AS `user_id`
                        FROM `users`
                        WHERE `role` = \'customer\' AND `customer_id` IS NOT NULL
                        GROUP BY `customer_id`
                    ) customer_user ON customer_user.`customer_id` = c.`id`
                    LEFT JOIN `users` user_by_customer ON user_by_customer.`id` = customer_user.`user_id`';
    } else {
        $select .= ', NULL AS `user_id`, NULL AS `user_name`, NULL AS `user_email`, NULL AS `user_role`,
                       NULL AS `user_customer_id`, NULL AS `user_created_at`, NULL AS `email_verified_at`';
    }

    if ($hasVerificationCodes && $hasUsers) {
        $select .= ', verification_codes.`last_code_created_at` AS `last_verification_code_created_at`,
                       verification_codes.`last_code_used_at` AS `last_verification_code_used_at`';
        $joins .= ' LEFT JOIN (
                        SELECT `user_id`, MAX(`created_at`) AS `last_code_created_at`, MAX(`used_at`) AS `last_code_used_at`
                        FROM `email_verification_codes`
                        GROUP BY `user_id`
                    ) verification_codes ON verification_codes.`user_id` = COALESCE(user_by_id.`id`, user_by_customer.`id`)';
    } else {
        $select .= ', NULL AS `last_verification_code_created_at`, NULL AS `last_verification_code_used_at`';
    }

    if ($hasRequests) {
        $select .= ', COALESCE(request_totals.`request_count`, 0) AS `request_count`,
                       request_totals.`latest_request_id`, request_totals.`latest_request_at`';
        $joins .= ' LEFT JOIN (
                        SELECT `customer_id`, COUNT(*) AS `request_count`, MAX(`id`) AS `latest_request_id`, MAX(`created_at`) AS `latest_request_at`
                        FROM `connection_requests`
                        GROUP BY `customer_id`
                    ) request_totals ON request_totals.`customer_id` = c.`id`';
    } else {
        $select .= ', 0 AS `request_count`, NULL AS `latest_request_id`, NULL AS `latest_request_at`';
    }

    if ($hasAddresses) {
        $select .= ', COALESCE(address_totals.`address_count`, 0) AS `address_count`';
        $joins .= ' LEFT JOIN (
                        SELECT `customer_id`, COUNT(*) AS `address_count`
                        FROM `customer_addresses`
                        GROUP BY `customer_id`
                    ) address_totals ON address_totals.`customer_id` = c.`id`';
    } else {
        $select .= ', 0 AS `address_count`';
    }

    $where = [];
    $params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(c.`requester_name` LIKE ? OR c.`company_name` LIKE ? OR c.`email` LIKE ? OR c.`phone` LIKE ? OR c.`status` LIKE ?';
        array_push($params, $like, $like, $like, $like, $like);

        if ($hasUsers) {
            $where[0] .= ' OR COALESCE(user_by_id.`name`, user_by_customer.`name`, \'\') LIKE ?
                           OR COALESCE(user_by_id.`email`, user_by_customer.`email`, \'\') LIKE ?';
            array_push($params, $like, $like);
        }

        if ($hasAddresses) {
            $where[0] .= ' OR EXISTS (
                               SELECT 1
                               FROM `customer_addresses` address_search
                               WHERE address_search.`customer_id` = c.`id`
                                 AND (address_search.`postal_code` LIKE ? OR address_search.`city` LIKE ? OR address_search.`address_line` LIKE ?)
                           )';
            array_push($params, $like, $like, $like);
        }

        $digits = admin_customer_lookup_digits($search);

        if ($digits !== '') {
            $where[0] .= ' OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.`phone`, \'\'), \' \', \'\'), \'-\', \'\'), \'/\', \'\'), \'(\', \'\'), \')\', \'\') LIKE ?';
            $params[] = '%' . $digits . '%';
        }

        $where[0] .= ')';
    }

    $sql = $select . '
            FROM `customers` c'
        . $joins
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY COALESCE(c.`created_at`, c.`updated_at`) DESC, c.`id` DESC
            LIMIT ' . $limit;

    return db_query($sql, $params)->fetchAll();
}

try {
    $results = admin_customer_lookup_rows($search, 50);
} catch (Throwable $exception) {
    $lookupErrors[] = APP_DEBUG ? $exception->getMessage() : 'Az ügyfélkereső betöltése sikertelen.';
}
?>
<section class="admin-section customer-lookup-page">
    <div class="container admin-requests-container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin ügyfélkereső</p>
                <h1>Regisztrált ügyfelek keresése</h1>
                <p>Név, email vagy telefonszám alapján kereshető, read-only áttekintő a frissen regisztrált ügyfélfiókokhoz.</p>
            </div>
            <div class="form-actions">
                <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
                <a class="button button-secondary" href="<?= h(url_path('/admin/customers')); ?>">Ügyfél CRM</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($lookupErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($lookupErrors as $lookupError): ?><p><?= h($lookupError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form class="auth-panel" method="get" action="<?= h(url_path('/admin/customer-lookup')); ?>">
            <div class="form-grid compact">
                <label for="customer_lookup_search">Keresés név, email vagy telefon alapján</label>
                <input id="customer_lookup_search" name="search" type="search" value="<?= h($search); ?>" placeholder="pl. név, email, +36...">
            </div>
            <div class="form-actions">
                <button class="button" type="submit">Keresés</button>
                <a class="button button-secondary" href="<?= h(url_path('/admin/customer-lookup')); ?>">Legutóbbi 50</a>
            </div>
        </form>

        <div class="admin-grid summary-grid">
            <article>
                <span>Találatok</span>
                <strong><?= count($results); ?></strong>
                <p><?= $search !== '' ? 'A megadott keresés első 50 találata.' : 'A legutóbb létrejött 50 ügyfél.'; ?></p>
            </article>
            <article>
                <span>Keresési mezők</span>
                <strong>Név / email / telefon</strong>
                <p>A customer és a kapcsolt user rekordot is figyeli.</p>
            </article>
            <article>
                <span>Biztonság</span>
                <strong>Read-only</strong>
                <p>Ez az oldal nem hoz létre, nem módosít és nem töröl adatot.</p>
            </article>
        </div>

        <?php if ($results === [] && $lookupErrors === []): ?>
            <div class="empty-state"><h2>Nincs találat</h2><p>Próbálj email részletre, telefonszám részletre vagy névre keresni.</p></div>
        <?php elseif ($results !== []): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ügyfél</th>
                            <th>Azonosítók</th>
                            <th>Email státusz</th>
                            <th>Regisztráció</th>
                            <th>Kapcsolatok</th>
                            <th>Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row): ?>
                            <?php
                            $customerId = (int) ($row['customer_id'] ?? 0);
                            $userId = (int) ($row['user_id'] ?? 0);
                            $requestCount = (int) ($row['request_count'] ?? 0);
                            $latestRequestId = (int) ($row['latest_request_id'] ?? 0);
                            $customerUrl = url_path('/admin/customers') . '?customer=' . $customerId . '#customer-' . $customerId;
                            $latestRequestUrl = $latestRequestId > 0
                                ? url_path('/admin/minicrm-import') . '?request=' . $latestRequestId . '#portal-work-' . $latestRequestId
                                : '';
                            ?>
                            <tr>
                                <td>
                                    <strong><?= h((string) ($row['requester_name'] ?: $row['user_name'] ?: '-')); ?></strong>
                                    <span><?= h((string) ($row['customer_email'] ?: $row['user_email'] ?: '-')); ?></span>
                                    <span><?= h((string) ($row['customer_phone'] ?: '-')); ?></span>
                                </td>
                                <td>
                                    <span>customer_id: #<?= $customerId; ?></span>
                                    <span>user_id: <?= $userId > 0 ? '#' . $userId : '-'; ?></span>
                                    <span><?= h(admin_customer_lookup_link_status($row)); ?></span>
                                </td>
                                <td>
                                    <strong><?= h(admin_customer_lookup_status_label($row)); ?></strong>
                                    <span><?= h(admin_customer_lookup_date($row['email_verified_at'] ?? null)); ?></span>
                                    <span>Utolsó kód: <?= h(admin_customer_lookup_date($row['last_verification_code_created_at'] ?? null)); ?></span>
                                </td>
                                <td>
                                    <span><?= h(admin_customer_lookup_date($row['customer_created_at'] ?? null)); ?></span>
                                    <span><?= h((string) ($row['customer_source'] ?: '-')); ?></span>
                                    <span><?= h((string) ($row['customer_status'] ?: '-')); ?></span>
                                </td>
                                <td>
                                    <span><?= $requestCount; ?> portálmunka</span>
                                    <span><?= (int) ($row['address_count'] ?? 0); ?> címrekord</span>
                                    <?php if ($latestRequestId > 0): ?><span>utolsó munka: #<?= $latestRequestId; ?></span><?php endif; ?>
                                </td>
                                <td class="table-actions stacked">
                                    <a href="<?= h($customerUrl); ?>">Ügyfél adatlap</a>
                                    <?php if ($latestRequestUrl !== ''): ?>
                                        <a href="<?= h($latestRequestUrl); ?>">Utolsó munka</a>
                                    <?php else: ?>
                                        <span>Nincs munkaigény</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
