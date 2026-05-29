<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

function admin_calendar_requested_month(): DateTimeImmutable
{
    $month = trim((string) ($_GET['month'] ?? ''));

    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
        try {
            return new DateTimeImmutable($month . '-01');
        } catch (Throwable) {
            // Fall back to the current month below.
        }
    }

    return new DateTimeImmutable('first day of this month');
}

function admin_calendar_month_label(DateTimeImmutable $month): string
{
    $monthNames = [
        1 => 'január',
        2 => 'február',
        3 => 'március',
        4 => 'április',
        5 => 'május',
        6 => 'június',
        7 => 'július',
        8 => 'augusztus',
        9 => 'szeptember',
        10 => 'október',
        11 => 'november',
        12 => 'december',
    ];

    return $month->format('Y') . '. ' . ($monthNames[(int) $month->format('n')] ?? $month->format('m'));
}

function admin_calendar_url(DateTimeImmutable $month, int $electricianUserId, string $status): string
{
    $params = ['month' => $month->format('Y-m')];

    if ($electricianUserId > 0) {
        $params['electrician_user_id'] = $electricianUserId;
    }

    if ($status !== 'booked') {
        $params['status'] = $status;
    }

    return url_path('/admin/calendar') . '?' . http_build_query($params);
}

function admin_calendar_slot_status_label(string $status): string
{
    return match ($status) {
        'booked' => 'Foglalva',
        'open' => 'Szabad',
        'closed' => 'Lezárva',
        default => 'Ismeretlen',
    };
}

function admin_calendar_event_electrician_label(array $event): string
{
    $assignedName = trim((string) ($event['assigned_electrician_name'] ?? ''))
        ?: trim((string) ($event['assigned_user_name'] ?? ''));

    if ($assignedName !== '') {
        return $assignedName;
    }

    if (($event['source'] ?? '') === 'electrician') {
        $actorName = trim((string) ($event['actor_electrician_name'] ?? ''))
            ?: trim((string) ($event['actor_user_name'] ?? ''));

        if ($actorName !== '') {
            return $actorName;
        }
    }

    return 'Nincs szerelő kiosztva';
}

function admin_calendar_event_work_label(array $event): string
{
    $projectName = trim((string) ($event['project_name'] ?? ''));

    if ($projectName !== '') {
        return $projectName;
    }

    $miniCrmName = trim((string) ($event['minicrm_card_name'] ?? ''));

    if ($miniCrmName !== '') {
        return $miniCrmName;
    }

    return 'Munka #' . (int) ($event['request_id'] ?? 0);
}

function admin_calendar_event_customer_label(array $event): string
{
    $companyName = trim((string) ($event['company_name'] ?? ''));
    $customerName = trim((string) ($event['requester_name'] ?? ''));

    return $companyName !== '' ? $companyName : ($customerName !== '' ? $customerName : '-');
}

function admin_calendar_event_source_label(array $event): string
{
    return match ((string) ($event['source'] ?? '')) {
        'customer' => 'Ügyfél foglalta',
        'electrician' => 'Szerelő tette be',
        'admin' => 'Admin tette be',
        default => 'Rendszer',
    };
}

$monthStart = admin_calendar_requested_month();
$monthEnd = $monthStart->modify('last day of this month');
$previousMonth = $monthStart->modify('-1 month');
$nextMonth = $monthStart->modify('+1 month');
$today = new DateTimeImmutable('today');

$electricianInput = filter_input(INPUT_GET, 'electrician_user_id', FILTER_VALIDATE_INT);
$electricianUserId = is_int($electricianInput) && $electricianInput > 0 ? $electricianInput : 0;

$statusOptions = [
    'booked' => 'Lefoglalt munkák',
    'open' => 'Szabadra nyitott napok',
    'closed' => 'Lezárt napok',
    'all' => 'Minden naptárbejegyzés',
];
$statusFilter = trim((string) ($_GET['status'] ?? 'booked'));
$statusFilter = array_key_exists($statusFilter, $statusOptions) ? $statusFilter : 'booked';

$schemaErrors = connection_request_schedule_schema_errors();

if (!db_table_exists('electricians')) {
    $schemaErrors[] = 'Hiányzik az electricians tábla.';
}

if (!db_column_exists('connection_requests', 'assigned_electrician_user_id')) {
    $schemaErrors[] = 'Hiányzik a connection_requests.assigned_electrician_user_id oszlop.';
}

$electricians = $schemaErrors === [] ? electrician_users(false) : [];
$events = [];
$eventsByDate = [];
$distinctElectricians = [];
$distinctDays = [];
$bookedCount = 0;

$gridStart = $monthStart->modify('-' . ((int) $monthStart->format('N') - 1) . ' days');
$gridEnd = $monthEnd->modify('+' . (7 - (int) $monthEnd->format('N')) . ' days');
$calendarDays = [];

for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')) {
    $calendarDays[] = $day;
}

if ($schemaErrors === []) {
    try {
        $hasMiniCrmTables = db_table_exists('minicrm_work_items') && minicrm_connection_request_link_schema_errors() === [];
        $miniCrmSelect = $hasMiniCrmTables
            ? ', mw.card_name AS minicrm_card_name, mw.source_id AS minicrm_source_id, mw.minicrm_status AS minicrm_status'
            : ', NULL AS minicrm_card_name, NULL AS minicrm_source_id, NULL AS minicrm_status';
        $miniCrmJoin = $hasMiniCrmTables
            ? ' LEFT JOIN `minicrm_connection_request_links` ml ON ml.connection_request_id = cr.id
                LEFT JOIN `minicrm_work_items` mw ON mw.id = ml.work_item_id'
            : '';
        $params = [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')];
        $sql = 'SELECT s.id AS slot_id, s.connection_request_id, s.work_date, s.status, s.source, s.note, s.created_by_user_id,
                       cr.id AS request_id, cr.project_name, cr.request_type, cr.request_status,
                       cr.site_address, cr.site_postal_code, cr.assigned_electrician_user_id,
                       c.requester_name, c.company_name, c.email, c.phone,
                       e.name AS assigned_electrician_name, e.email AS assigned_electrician_email, e.phone AS assigned_electrician_phone,
                       u.name AS assigned_user_name, u.email AS assigned_user_email,
                       ae.name AS actor_electrician_name, au.name AS actor_user_name'
                       . $miniCrmSelect . '
                FROM `connection_request_schedule_slots` s
                INNER JOIN `connection_requests` cr ON cr.id = s.connection_request_id
                INNER JOIN `customers` c ON c.id = cr.customer_id
                LEFT JOIN `electricians` e ON e.user_id = cr.assigned_electrician_user_id
                LEFT JOIN `users` u ON u.id = cr.assigned_electrician_user_id
                LEFT JOIN `electricians` ae ON ae.user_id = s.created_by_user_id
                LEFT JOIN `users` au ON au.id = s.created_by_user_id'
                . $miniCrmJoin . '
                WHERE s.work_date BETWEEN ? AND ?';

        if ($electricianUserId > 0) {
            $sql .= ' AND (cr.assigned_electrician_user_id = ? OR (cr.assigned_electrician_user_id IS NULL AND s.created_by_user_id = ?))';
            $params[] = $electricianUserId;
            $params[] = $electricianUserId;
        }

        if ($statusFilter !== 'all') {
            $sql .= ' AND s.status = ?';
            $params[] = $statusFilter;
        }

        $sql .= ' ORDER BY s.work_date ASC, COALESCE(e.name, ae.name, u.name, au.name, \'\') ASC, cr.project_name ASC, cr.id ASC';
        $events = db_query($sql, $params)->fetchAll();
    } catch (Throwable $exception) {
        $schemaErrors[] = APP_DEBUG ? $exception->getMessage() : 'Az admin naptár betöltése sikertelen.';
    }
}

foreach ($events as $event) {
    $date = (string) ($event['work_date'] ?? '');

    if ($date === '') {
        continue;
    }

    $eventsByDate[$date][] = $event;
    $distinctDays[$date] = true;

    if (($event['status'] ?? '') === 'booked') {
        $bookedCount++;
    }

    $electricianKey = (int) ($event['assigned_electrician_user_id'] ?? 0);

    if ($electricianKey <= 0 && ($event['source'] ?? '') === 'electrician') {
        $electricianKey = (int) ($event['created_by_user_id'] ?? 0);
    }

    if ($electricianKey > 0) {
        $distinctElectricians[$electricianKey] = true;
    }
}

$weekdayLabels = [
    1 => 'Hétfő',
    2 => 'Kedd',
    3 => 'Szerda',
    4 => 'Csütörtök',
    5 => 'Péntek',
    6 => 'Szombat',
    7 => 'Vasárnap',
];
?>
<section class="admin-section admin-calendar-page">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin naptár</p>
                <h1>Kivitelezési naptár</h1>
                <p>Összesített havi nézet: melyik nap melyik szerelő melyik munkát végzi el.</p>
            </div>
            <div class="admin-actions">
                <a class="button button-secondary" href="<?= h(url_path('/admin/minicrm-import')); ?>">Munkaközpont</a>
                <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
            </div>
        </div>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php else: ?>
            <form class="auth-panel admin-calendar-filter" method="get" action="<?= h(url_path('/admin/calendar')); ?>">
                <div class="form-grid compact admin-calendar-filter-grid">
                    <div>
                        <label for="month">Hónap</label>
                        <input id="month" name="month" type="month" value="<?= h($monthStart->format('Y-m')); ?>">
                    </div>
                    <div>
                        <label for="electrician_user_id">Szerelő</label>
                        <select id="electrician_user_id" name="electrician_user_id">
                            <option value="0">Összes szerelő</option>
                            <?php foreach ($electricians as $electrician): ?>
                                <?php $optionUserId = (int) ($electrician['user_id'] ?? 0); ?>
                                <option value="<?= $optionUserId; ?>" <?= $electricianUserId === $optionUserId ? 'selected' : ''; ?>>
                                    <?= h((string) ($electrician['name'] ?? $electrician['user_name'] ?? $electrician['email'] ?? 'Szerelő')); ?>
                                    <?= (int) ($electrician['is_active'] ?? 1) === 1 ? '' : ' - inaktív'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="status">Naptárstátusz</label>
                        <select id="status" name="status">
                            <?php foreach ($statusOptions as $statusKey => $statusLabel): ?>
                                <option value="<?= h($statusKey); ?>" <?= $statusFilter === $statusKey ? 'selected' : ''; ?>><?= h($statusLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="admin-calendar-filter-actions">
                        <button class="button" type="submit">Szűrés</button>
                        <a class="button button-secondary" href="<?= h(url_path('/admin/calendar')); ?>">Aktuális hónap</a>
                    </div>
                </div>
            </form>

            <div class="admin-grid summary-grid admin-calendar-stats">
                <article class="metric-card metric-card-accent">
                    <span class="metric-label">Bejegyzés</span>
                    <strong><?= count($events); ?></strong>
                    <p><?= h((string) $statusOptions[$statusFilter]); ?> a kiválasztott hónapban.</p>
                </article>
                <article class="metric-card metric-card-primary">
                    <span class="metric-label">Lefoglalt munka</span>
                    <strong><?= $bookedCount; ?></strong>
                    <p>Tényleges kivitelezési napként berakott munka.</p>
                </article>
                <article class="metric-card metric-card-system">
                    <span class="metric-label">Érintett szerelő</span>
                    <strong><?= count($distinctElectricians); ?></strong>
                    <p>Akinek van naptárbejegyzése ebben a nézetben.</p>
                </article>
                <article class="metric-card metric-card-system">
                    <span class="metric-label">Érintett nap</span>
                    <strong><?= count($distinctDays); ?></strong>
                    <p>Ennyi napon van naptárbejegyzés.</p>
                </article>
            </div>

            <section class="auth-panel admin-calendar-panel">
                <div class="admin-calendar-toolbar">
                    <a class="button button-secondary" href="<?= h(admin_calendar_url($previousMonth, $electricianUserId, $statusFilter)); ?>">Előző hónap</a>
                    <h2><?= h(admin_calendar_month_label($monthStart)); ?></h2>
                    <a class="button button-secondary" href="<?= h(admin_calendar_url($nextMonth, $electricianUserId, $statusFilter)); ?>">Következő hónap</a>
                </div>

                <div class="admin-calendar-weekdays" aria-hidden="true">
                    <?php foreach ($weekdayLabels as $weekdayLabel): ?><span><?= h($weekdayLabel); ?></span><?php endforeach; ?>
                </div>

                <div class="admin-calendar-grid">
                    <?php foreach ($calendarDays as $day): ?>
                        <?php
                        $date = $day->format('Y-m-d');
                        $dayEvents = $eventsByDate[$date] ?? [];
                        $classes = ['admin-calendar-day'];

                        if ($day->format('m') !== $monthStart->format('m')) {
                            $classes[] = 'admin-calendar-day-outside';
                        }

                        if ($date === $today->format('Y-m-d')) {
                            $classes[] = 'admin-calendar-day-today';
                        }
                        ?>
                        <article class="<?= h(implode(' ', $classes)); ?>">
                            <div class="admin-calendar-day-head">
                                <strong><?= h($day->format('j')); ?></strong>
                                <span class="admin-calendar-day-name"><?= h($weekdayLabels[(int) $day->format('N')] ?? ''); ?></span>
                                <?php if ($date === $today->format('Y-m-d')): ?><span>ma</span><?php endif; ?>
                            </div>

                            <?php if ($dayEvents === []): ?>
                                <span class="admin-calendar-empty">Nincs munka</span>
                            <?php else: ?>
                                <div class="admin-calendar-events">
                                    <?php foreach ($dayEvents as $event): ?>
                                        <?php
                                        $status = (string) ($event['status'] ?? '');
                                        $requestId = (int) ($event['request_id'] ?? 0);
                                        $requestUrl = url_path('/admin/work-request-view') . '?request=' . $requestId;
                                        $siteAddress = trim((string) ($event['site_postal_code'] ?? '') . ' ' . (string) ($event['site_address'] ?? ''));
                                        ?>
                                        <a class="admin-calendar-event admin-calendar-event-<?= h($status); ?>" href="<?= h($requestUrl); ?>">
                                            <span class="admin-calendar-event-status"><?= h(admin_calendar_slot_status_label($status)); ?></span>
                                            <strong><?= h(admin_calendar_event_electrician_label($event)); ?></strong>
                                            <em><?= h(admin_calendar_event_work_label($event)); ?></em>
                                            <small><?= h(admin_calendar_event_customer_label($event)); ?><?= $siteAddress !== '' ? ' - ' . h($siteAddress) : ''; ?></small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="auth-panel form-block">
                <h2>Havi munkalista</h2>
                <?php if ($events === []): ?>
                    <div class="empty-state">
                        <h2>Nincs naptárbejegyzés</h2>
                        <p>A kiválasztott hónapra és szűrésre nincs megjeleníthető munka.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Nap</th>
                                    <th>Szerelő</th>
                                    <th>Munka</th>
                                    <th>Ügyfél</th>
                                    <th>Cím</th>
                                    <th>Státusz</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <?php
                                    $requestId = (int) ($event['request_id'] ?? 0);
                                    $requestUrl = url_path('/admin/work-request-view') . '?request=' . $requestId;
                                    $siteAddress = trim((string) ($event['site_postal_code'] ?? '') . ' ' . (string) ($event['site_address'] ?? ''));
                                    $status = (string) ($event['status'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?= h(connection_request_schedule_day_label((string) ($event['work_date'] ?? ''))); ?></td>
                                        <td><strong><?= h(admin_calendar_event_electrician_label($event)); ?></strong><span><?= h(admin_calendar_event_source_label($event)); ?></span></td>
                                        <td><a href="<?= h($requestUrl); ?>"><strong><?= h(admin_calendar_event_work_label($event)); ?></strong></a><span>#<?= $requestId; ?></span></td>
                                        <td><?= h(admin_calendar_event_customer_label($event)); ?></td>
                                        <td><?= h($siteAddress !== '' ? $siteAddress : '-'); ?></td>
                                        <td><span class="admin-calendar-table-status admin-calendar-table-status-<?= h($status); ?>"><?= h(admin_calendar_slot_status_label($status)); ?></span></td>
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
