<?php
declare(strict_types=1);

require_role(['admin']);

$flash = get_flash();
$periodDays = 7;
$periodStart = date('Y-m-d H:i:s', strtotime('-' . $periodDays . ' days') ?: time());
$periodEnd = date('Y-m-d H:i:s');
$mvmSubmissions = [];
$quoteAcceptances = [];
$feeRequests = [];
$statusChanges = [];
$recentActivities = [];
$submissionActorCounts = [];

function super_overview_actor_label(array $row): string
{
    $role = !empty($row['actor_is_admin']) ? 'admin' : (string) ($row['actor_role'] ?? '');

    return actor_display_label_from_parts(
        $role,
        (string) ($row['actor_name'] ?? ''),
        (string) ($row['actor_email'] ?? ''),
        isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null
    );
}

function super_overview_safe_rows(string $sql, array $params): array
{
    try {
        return db_query($sql, $params)->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

if (db_table_exists('mvm_email_threads')) {
    $mvmSubmissions = super_overview_safe_rows(
        'SELECT t.*, cr.project_name, c.requester_name,
                u.id AS actor_user_id, u.name AS actor_name, u.email AS actor_email, u.role AS actor_role, u.is_admin AS actor_is_admin
         FROM `mvm_email_threads` t
         LEFT JOIN `connection_requests` cr ON cr.id = t.connection_request_id
         LEFT JOIN `customers` c ON c.id = cr.customer_id
         LEFT JOIN `users` u ON u.id = t.created_by_user_id
         WHERE t.`created_at` >= ? AND t.`status` IN (?, ?)
         ORDER BY t.`created_at` DESC, t.`id` DESC
         LIMIT 100',
        [$periodStart, 'sent', 'replied']
    );

    foreach ($mvmSubmissions as $submission) {
        $actorLabel = super_overview_actor_label($submission);
        $submissionActorCounts[$actorLabel] = ($submissionActorCounts[$actorLabel] ?? 0) + 1;
    }
}

if (db_table_exists('quotes')) {
    $quoteAcceptances = super_overview_safe_rows(
        'SELECT q.id, q.quote_number, q.subject, q.total_gross, q.accepted_at,
                c.requester_name, cr.id AS request_id, cr.project_name
         FROM `quotes` q
         LEFT JOIN `customers` c ON c.id = q.customer_id
         LEFT JOIN `connection_requests` cr ON cr.id = q.connection_request_id
         WHERE q.`status` = ? AND q.`accepted_at` IS NOT NULL AND q.`accepted_at` >= ?
         ORDER BY q.`accepted_at` DESC, q.`id` DESC
         LIMIT 100',
        ['accepted', $periodStart]
    );
}

if (db_table_exists('email_logs')) {
    $feeRequests = super_overview_safe_rows(
        'SELECT el.*, q.quote_number, c.requester_name
         FROM `email_logs` el
         LEFT JOIN `quotes` q ON q.id = el.quote_id
         LEFT JOIN `customers` c ON c.id = q.customer_id
         WHERE el.`created_at` >= ?
           AND el.`status` = ?
           AND (
                LOWER(el.`subject`) LIKE ?
                OR LOWER(el.`subject`) LIKE ?
                OR LOWER(el.`subject`) LIKE ?
                OR LOWER(el.`subject`) LIKE ?
                OR LOWER(el.`subject`) LIKE ?
           )
         ORDER BY el.`created_at` DESC, el.`id` DESC
         LIMIT 100',
        [$periodStart, 'sent', '%díjbekérő%', '%dijbekero%', '%díjbekero%', '%dijbekérő%', '%ugydij%']
    );
}

if (connection_request_activity_is_ready()) {
    $statusChanges = super_overview_safe_rows(
        'SELECT l.*, cr.project_name, c.requester_name,
                u.id AS actor_user_id, u.name AS actor_name, u.email AS actor_email, u.role AS actor_role, u.is_admin AS actor_is_admin
         FROM `connection_request_activity_logs` l
         LEFT JOIN `connection_requests` cr ON cr.id = l.connection_request_id
         LEFT JOIN `customers` c ON c.id = cr.customer_id
         LEFT JOIN `users` u ON u.id = l.actor_user_id
         WHERE l.`event_type` = ? AND l.`created_at` >= ?
         ORDER BY l.`created_at` DESC, l.`id` DESC
         LIMIT 100',
        ['workflow', $periodStart]
    );
    $recentActivities = super_overview_safe_rows(
        'SELECT l.*, cr.project_name, c.requester_name,
                u.id AS actor_user_id, u.name AS actor_name, u.email AS actor_email, u.role AS actor_role, u.is_admin AS actor_is_admin
         FROM `connection_request_activity_logs` l
         LEFT JOIN `connection_requests` cr ON cr.id = l.connection_request_id
         LEFT JOIN `customers` c ON c.id = cr.customer_id
         LEFT JOIN `users` u ON u.id = l.actor_user_id
         WHERE l.`created_at` >= ?
         ORDER BY l.`created_at` DESC, l.`id` DESC
         LIMIT 100',
        [$periodStart]
    );
}

$metricCards = [
    [
        'label' => 'MVM ügyindítás',
        'value' => count($mvmSubmissions),
        'description' => 'MVM felé sikeresen elküldött dokumentumcsomagok.',
        'variant' => 'primary',
    ],
    [
        'label' => 'Elfogadott ajánlat',
        'value' => count($quoteAcceptances),
        'description' => 'Ügyfél által elfogadott árajánlatok.',
        'variant' => 'accent',
    ],
    [
        'label' => 'Díjbekérő',
        'value' => count($feeRequests),
        'description' => 'Emailben kiküldött ügykezelési díjbekérők.',
        'variant' => 'system',
    ],
    [
        'label' => 'Státuszváltás',
        'value' => count($statusChanges),
        'description' => 'Admin munkafolyamatban rögzített státuszlépések.',
        'variant' => 'accent',
    ],
];
?>
<section class="admin-section super-overview-page">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Szuper admin</p>
                <h1>7 napos összesítő</h1>
                <p><?= h($periodStart); ?> - <?= h($periodEnd); ?></p>
            </div>
            <div class="admin-actions">
                <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
                <a class="button button-secondary" href="<?= h(url_path('/admin/customer-lookup')); ?>">Ügyfélkereső</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <div class="admin-grid dashboard-grid">
            <?php foreach ($metricCards as $card): ?>
                <article class="metric-card metric-card-<?= h((string) $card['variant']); ?>">
                    <span class="metric-label"><?= h((string) $card['label']); ?></span>
                    <strong><?= h((string) $card['value']); ?></strong>
                    <p><?= h((string) $card['description']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>

        <section class="auth-panel form-block">
            <div class="admin-header compact">
                <div>
                    <p class="eyebrow">MVM ügyindítás</p>
                    <h2>Ki indította az MVM beküldéseket?</h2>
                </div>
            </div>

            <?php if ($submissionActorCounts === []): ?>
                <p class="muted-text">Az elmúlt <?= $periodDays; ?> napban nem volt MVM felé elküldött dokumentumcsomag.</p>
            <?php else: ?>
                <div class="status-list">
                    <?php foreach ($submissionActorCounts as $actorLabel => $count): ?>
                        <li>
                            <span class="status-label"><?= h($actorLabel); ?></span>
                            <span class="status-value"><?= (int) $count; ?> ügyindítás</span>
                        </li>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="auth-panel form-block">
            <div class="admin-header compact">
                <div>
                    <p class="eyebrow">MVM</p>
                    <h2>Legutóbbi ügyindítások</h2>
                </div>
            </div>

            <?php if ($mvmSubmissions === []): ?>
                <p class="muted-text">Nincs megjeleníthető MVM ügyindítás.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Időpont</th>
                                <th>Munka</th>
                                <th>Dokumentum</th>
                                <th>Indító</th>
                                <th>Címzett</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mvmSubmissions as $submission): ?>
                                <tr>
                                    <td><?= h((string) $submission['created_at']); ?></td>
                                    <td><strong><?= h((string) ($submission['project_name'] ?: ('Munka #' . (int) $submission['connection_request_id']))); ?></strong><span><?= h((string) ($submission['requester_name'] ?? '-')); ?></span></td>
                                    <td><?= h((string) ($submission['document_label'] ?? '-')); ?></td>
                                    <td><?= h(super_overview_actor_label($submission)); ?></td>
                                    <td><?= h((string) ($submission['mvm_recipient'] ?? '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="auth-panel form-block">
            <div class="admin-header compact">
                <div>
                    <p class="eyebrow">Árajánlatok</p>
                    <h2>Elfogadások és díjbekérők</h2>
                </div>
            </div>

            <div class="form-grid two">
                <div>
                    <h3>Elfogadott árajánlatok</h3>
                    <?php if ($quoteAcceptances === []): ?>
                        <p class="muted-text">Nem volt elfogadás az időszakban.</p>
                    <?php else: ?>
                        <div class="status-list">
                            <?php foreach ($quoteAcceptances as $quote): ?>
                                <li>
                                    <span class="status-label"><?= h((string) ($quote['quote_number'] ?? ('#' . (int) $quote['id']))); ?></span>
                                    <span class="status-value"><?= h((string) $quote['accepted_at']); ?> · <?= h((string) ($quote['requester_name'] ?? '-')); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <h3>Kiküldött díjbekérők</h3>
                    <?php if ($feeRequests === []): ?>
                        <p class="muted-text">Nem ment ki díjbekérő az időszakban.</p>
                    <?php else: ?>
                        <div class="status-list">
                            <?php foreach ($feeRequests as $feeRequest): ?>
                                <li>
                                    <span class="status-label"><?= h((string) ($feeRequest['subject'] ?? '-')); ?></span>
                                    <span class="status-value"><?= h((string) $feeRequest['created_at']); ?> · <?= h((string) ($feeRequest['recipient_email'] ?? '-')); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="auth-panel form-block">
            <div class="admin-header compact">
                <div>
                    <p class="eyebrow">Státuszok</p>
                    <h2>Státuszváltozások</h2>
                </div>
            </div>

            <?php if ($statusChanges === []): ?>
                <p class="muted-text">Nem volt státuszváltás az időszakban.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Időpont</th>
                                <th>Munka</th>
                                <th>Változás</th>
                                <th>Rögzítő</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($statusChanges as $change): ?>
                                <tr>
                                    <td><?= h((string) $change['created_at']); ?></td>
                                    <td><strong><?= h((string) ($change['project_name'] ?: ('Munka #' . (int) $change['connection_request_id']))); ?></strong><span><?= h((string) ($change['requester_name'] ?? '-')); ?></span></td>
                                    <td><?= h((string) ($change['body'] ?: $change['title'])); ?></td>
                                    <td><?= h(super_overview_actor_label($change)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="auth-panel form-block">
            <div class="admin-header compact">
                <div>
                    <p class="eyebrow">Idővonal</p>
                    <h2>Legutóbbi történések</h2>
                </div>
            </div>

            <?php if ($recentActivities === []): ?>
                <p class="muted-text">Nincs naplózott esemény az időszakban.</p>
            <?php else: ?>
                <ol class="minicrm-timeline">
                    <?php foreach ($recentActivities as $activity): ?>
                        <li class="minicrm-timeline-event minicrm-timeline-<?= h((string) $activity['event_type']); ?>">
                            <time><?= h((string) $activity['created_at']); ?></time>
                            <strong><?= h((string) $activity['title']); ?></strong>
                            <span><?= h((string) ($activity['project_name'] ?: ('Munka #' . (int) $activity['connection_request_id']))); ?> · <?= h(super_overview_actor_label($activity)); ?></span>
                            <?php if (trim((string) ($activity['body'] ?? '')) !== ''): ?>
                                <p><?= h((string) $activity['body']); ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>
</section>
