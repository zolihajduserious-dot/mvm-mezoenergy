<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$user = current_user();
$flash = get_flash();
$canManageMvmDocuments = can_manage_mvm_documents();
$canManagePriceItems = can_manage_price_items();
$canManageAdminUsers = can_manage_admin_users();
$canManageUiModules = can_manage_ui_modules();
$customerCount = null;
$connectionRequestCount = null;
$priceItemCount = null;
$documentCount = null;
$electricianCount = null;
$contractorCount = null;
$staffUserCount = null;
$minicrmImportCount = null;
$standaloneConnectionRequestCount = null;
$calendarBookedCount = null;
$developmentSuggestionCounts = null;
$workflowStages = admin_workflow_stage_definitions();
$workflowStageCounts = array_fill_keys(array_keys($workflowStages), 0);
$showDashboardWorkflow = false;
$dashboardUserOverview = ['users' => [], 'errors' => []];

function dashboard_user_role_key(array $userRow): string
{
    $role = trim((string) ($userRow['role'] ?? ''));

    if ((int) ($userRow['is_admin'] ?? 0) === 1 && ($role === '' || $role === 'customer' || $role === 'guest')) {
        return 'admin';
    }

    return $role !== '' ? $role : 'customer';
}

function dashboard_user_display_name(array $userRow): string
{
    $name = trim((string) ($userRow['name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    $email = trim((string) ($userRow['email'] ?? ''));

    return $email !== '' ? $email : ('Felhasználó #' . (int) ($userRow['id'] ?? 0));
}

function dashboard_request_admin_url(array $request): string
{
    $requestId = (int) ($request['id'] ?? 0);

    return url_path('/admin/minicrm-import') . '?request=' . $requestId . '#portal-work-' . $requestId;
}

function dashboard_request_display_name(array $request): string
{
    $projectName = trim((string) ($request['project_name'] ?? ''));

    return $projectName !== '' ? $projectName : ('Adatlap #' . (int) ($request['id'] ?? 0));
}

function dashboard_request_location(array $request): string
{
    return trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));
}

function dashboard_user_work_overview(): array
{
    $overview = ['users' => [], 'errors' => []];

    try {
        if (!users_table_exists()) {
            $overview['errors'][] = 'A felhasználói tábla nem érhető el.';

            return $overview;
        }

        $userRows = db_query(
            'SELECT `id`, `name`, `email`, `role`, `is_admin`, `customer_id`
             FROM `users`
             WHERE `role` IN (\'admin\', \'specialist\', \'electrician\', \'general_contractor\') OR `is_admin` = 1
             ORDER BY
                CASE `role`
                    WHEN \'admin\' THEN 1
                    WHEN \'specialist\' THEN 2
                    WHEN \'electrician\' THEN 3
                    WHEN \'general_contractor\' THEN 4
                    ELSE 5
                END,
                `name` ASC,
                `email` ASC,
                `id` ASC'
        )->fetchAll();

        $usersById = [];

        foreach ($userRows as $userRow) {
            $userId = (int) ($userRow['id'] ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $usersById[$userId] = [
                'id' => $userId,
                'name' => dashboard_user_display_name($userRow),
                'email' => trim((string) ($userRow['email'] ?? '')),
                'role' => dashboard_user_role_key($userRow),
                'submitted' => [],
                'assigned' => [],
                'total' => 0,
            ];
        }

        if (!db_table_exists('connection_requests')) {
            $overview['users'] = array_values($usersById);

            return $overview;
        }

        $hasSubmittedColumn = db_column_exists('connection_requests', 'submitted_by_user_id');
        $hasAssignedColumn = db_column_exists('connection_requests', 'assigned_electrician_user_id');

        if (!$hasSubmittedColumn && !$hasAssignedColumn) {
            $overview['users'] = array_values($usersById);
            $overview['errors'][] = 'A munkákhoz nincs felhasználói hozzárendelés rögzítve.';

            return $overview;
        }

        $hasCustomerTable = db_table_exists('customers');
        $hasUpdatedAtColumn = db_column_exists('connection_requests', 'updated_at');
        $customerSelect = $hasCustomerTable
            ? ', c.requester_name, c.email AS customer_email'
            : ', NULL AS requester_name, NULL AS customer_email';
        $customerJoin = $hasCustomerTable
            ? ' LEFT JOIN `customers` c ON c.id = cr.customer_id'
            : '';
        $submittedSelect = $hasSubmittedColumn
            ? ', cr.submitted_by_user_id'
            : ', NULL AS submitted_by_user_id';
        $assignedSelect = $hasAssignedColumn
            ? ', cr.assigned_electrician_user_id'
            : ', NULL AS assigned_electrician_user_id';
        $electricianStatusSelect = db_column_exists('connection_requests', 'electrician_status')
            ? ', cr.electrician_status'
            : ', \'unassigned\' AS electrician_status';
        $requestStatusSelect = db_column_exists('connection_requests', 'request_status')
            ? ', cr.request_status'
            : ', \'draft\' AS request_status';
        $updatedSelect = $hasUpdatedAtColumn
            ? ', cr.updated_at'
            : ', NULL AS updated_at';
        $archiveWhere = connection_request_archive_columns_ready()
            ? ' WHERE cr.archived_at IS NULL'
            : '';
        $orderBy = $hasUpdatedAtColumn
            ? 'COALESCE(cr.updated_at, cr.created_at) DESC, cr.id DESC'
            : 'cr.created_at DESC, cr.id DESC';

        $requests = db_query(
            'SELECT cr.id, cr.project_name, cr.site_postal_code, cr.site_address, cr.created_at'
            . $updatedSelect
            . $requestStatusSelect
            . $submittedSelect
            . $assignedSelect
            . $electricianStatusSelect
            . $customerSelect . '
             FROM `connection_requests` cr'
            . $customerJoin
            . $archiveWhere . '
             ORDER BY ' . $orderBy
        )->fetchAll();

        foreach ($requests as $request) {
            $submittedByUserId = (int) ($request['submitted_by_user_id'] ?? 0);
            $assignedElectricianUserId = (int) ($request['assigned_electrician_user_id'] ?? 0);

            if ($submittedByUserId > 0 && isset($usersById[$submittedByUserId])) {
                $usersById[$submittedByUserId]['submitted'][] = $request;
            }

            if ($assignedElectricianUserId > 0 && isset($usersById[$assignedElectricianUserId])) {
                $usersById[$assignedElectricianUserId]['assigned'][] = $request;
            }
        }

        foreach ($usersById as &$overviewUser) {
            $overviewUser['total'] = count($overviewUser['submitted']) + count($overviewUser['assigned']);
        }
        unset($overviewUser);

        $overviewUsers = array_values($usersById);
        $roleOrder = [
            'admin' => 1,
            'specialist' => 2,
            'electrician' => 3,
            'general_contractor' => 4,
        ];

        usort(
            $overviewUsers,
            static function (array $first, array $second) use ($roleOrder): int {
                $workCountCompare = (int) $second['total'] <=> (int) $first['total'];

                if ($workCountCompare !== 0) {
                    return $workCountCompare;
                }

                $roleCompare = ($roleOrder[(string) $first['role']] ?? 99) <=> ($roleOrder[(string) $second['role']] ?? 99);

                if ($roleCompare !== 0) {
                    return $roleCompare;
                }

                return strcasecmp((string) $first['name'], (string) $second['name']);
            }
        );

        $overview['users'] = $overviewUsers;
    } catch (Throwable $exception) {
        $overview['errors'][] = APP_DEBUG ? $exception->getMessage() : 'A felhasználói munkalista betöltése sikertelen.';
    }

    return $overview;
}

try {
    $customerCount = db_table_exists('customers') ? (int) db_query('SELECT COUNT(*) FROM `customers`')->fetchColumn() : 0;
    $connectionRequestCount = db_table_exists('connection_requests') ? (int) db_query('SELECT COUNT(*) FROM `connection_requests`')->fetchColumn() : 0;
    $priceItemCount = db_table_exists('quote_price_items') ? (int) db_query('SELECT COUNT(*) FROM `quote_price_items`')->fetchColumn() : 0;
    $documentCount = db_table_exists('download_documents') ? (int) db_query('SELECT COUNT(*) FROM `download_documents`')->fetchColumn() : 0;
    $electricianCount = db_table_exists('electricians') ? (int) db_query('SELECT COUNT(*) FROM `electricians`')->fetchColumn() : 0;
    $contractorCount = db_table_exists('contractors') ? (int) db_query('SELECT COUNT(*) FROM `contractors`')->fetchColumn() : 0;
    $staffUserCount = users_table_exists() ? (int) db_query('SELECT COUNT(*) FROM `users` WHERE `role` IN (?, ?) OR `is_admin` = ?', ['admin', 'specialist', 1])->fetchColumn() : 0;
    $minicrmImportCount = db_table_exists('minicrm_work_items') ? (int) db_query('SELECT COUNT(*) FROM `minicrm_work_items`')->fetchColumn() : 0;
    $standaloneConnectionRequestCount = $connectionRequestCount;
    $calendarBookedCount = connection_request_schedule_is_ready()
        ? (int) db_query(
            'SELECT COUNT(*)
             FROM `connection_request_schedule_slots`
             WHERE `status` = ? AND `work_date` >= CURDATE() AND `work_date` < DATE_ADD(CURDATE(), INTERVAL 31 DAY)',
            ['booked']
        )->fetchColumn()
        : null;
    $developmentSuggestionCounts = development_suggestion_counts();
    $dashboardUserOverview = dashboard_user_work_overview();

    if (db_table_exists('connection_requests') && db_table_exists('minicrm_connection_request_links')) {
        $standaloneConnectionRequestCount = (int) db_query(
            'SELECT COUNT(*)
             FROM `connection_requests` cr
             LEFT JOIN `minicrm_connection_request_links` l ON l.connection_request_id = cr.id
             WHERE l.id IS NULL'
        )->fetchColumn();
    }

    if ($showDashboardWorkflow && db_table_exists('connection_requests')) {
        foreach (all_connection_requests() as $workflowRequest) {
            $requestId = (int) $workflowRequest['id'];
            $quotes = quotes_for_connection_request($requestId);
            $acceptedQuote = accepted_quote_for_connection_request($requestId)
                ?? accepted_quote_for_registration_duplicate_request($requestId);
            $stage = connection_request_admin_workflow_stage(
                $workflowRequest,
                $quotes[0] ?? $acceptedQuote,
                $acceptedQuote,
                connection_request_documents($requestId)
            );
            $workflowStageCounts[$stage] = ($workflowStageCounts[$stage] ?? 0) + 1;
        }
    }
} catch (Throwable $exception) {
    $flash = [
        'type' => 'error',
        'message' => APP_DEBUG ? $exception->getMessage() : 'Az admin adatok betöltése sikertelen.',
    ];
}

$dashboardUsers = $dashboardUserOverview['users'] ?? [];
$dashboardUserErrors = $dashboardUserOverview['errors'] ?? [];

$dashboardCards = [
    [
        'label' => 'Ügyfelek',
        'value' => $customerCount ?? '-',
        'description' => 'Ügyféladatok megtekintése, javítása és új ügyfelek rögzítése.',
        'href' => '/admin/minicrm-import',
        'variant' => 'primary',
    ],
    [
        'label' => 'Munkák',
        'value' => $connectionRequestCount ?? '-',
        'description' => $canManageMvmDocuments
            ? 'Beküldött munkaigények, fájlok, ajánlatfeltöltés és MVM dokumentumok.'
            : 'Beküldött munkaigények, fájlok és ajánlatfeltöltés.',
        'href' => '/admin/minicrm-import',
        'variant' => 'accent',
    ],
    [
        'label' => 'Szerelők',
        'value' => $electricianCount ?? '-',
        'description' => 'Szerelői fiókok kezelése és kivitelezési munkák kiadása.',
        'href' => '/admin/electricians',
        'variant' => 'system',
    ],
    [
        'label' => 'Generálkivitelezők',
        'value' => $contractorCount ?? '-',
        'description' => 'Generálkivitelezői fiókok áttekintése és felhasználók törlése.',
        'href' => '/admin/contractors',
        'variant' => 'system',
    ],
    [
        'label' => 'Dokumentumtár',
        'value' => $documentCount ?? '-',
        'description' => 'Letölthető meghatalmazások, nyilatkozatok és ügyintézési dokumentumok.',
        'href' => '/admin/documents',
        'variant' => 'accent',
    ],
    [
        'label' => 'MiniCRM munkák',
        'value' => $minicrmImportCount ?? '-',
        'description' => 'Excel exportból áthozott munkaállomány és MiniCRM dokumentumlinkek.',
        'href' => '/admin/minicrm-import',
        'variant' => 'system',
    ],
    [
        'label' => 'Árlista tételek',
        'value' => $priceItemCount ?? '-',
        'description' => 'Díjtétel nevek, egységárak, ÁFA és aktív árlista elemek kezelése.',
        'href' => '/admin/price-items',
        'variant' => 'accent',
    ],
];

$dashboardCards = [
    [
        'label' => 'Munkaközpont',
        'value' => ($standaloneConnectionRequestCount ?? 0) + ($minicrmImportCount ?? 0),
        'description' => 'MiniCRM importok, portálos munkák, ügyféladatok, ajánlatok és dokumentumok egy helyen.',
        'href' => '/admin/minicrm-import',
        'variant' => 'accent',
    ],
    [
        'label' => 'Szerelők',
        'value' => $electricianCount ?? '-',
        'description' => 'Szerelői fiókok kezelése és munkák kiadása.',
        'href' => '/admin/electricians',
        'variant' => 'system',
    ],
    [
        'label' => 'Generálkivitelezők',
        'value' => $contractorCount ?? '-',
        'description' => 'Generálkivitelezői fiókok áttekintése és felhasználók törlése.',
        'href' => '/admin/contractors',
        'variant' => 'system',
    ],
    [
        'label' => 'Naptár',
        'value' => $calendarBookedCount ?? '-',
        'description' => 'A következő 31 nap lefoglalt kivitelezési munkái szerelők szerint.',
        'href' => '/admin/calendar',
        'variant' => 'primary',
    ],
    [
        'label' => 'Dokumentumtár',
        'value' => $documentCount ?? '-',
        'description' => 'Letölthető meghatalmazások, nyilatkozatok és ügyintézési dokumentumok.',
        'href' => '/admin/documents',
        'variant' => 'accent',
    ],
    [
        'label' => 'Javaslatok',
        'value' => is_array($developmentSuggestionCounts) ? (int) ($developmentSuggestionCounts['new'] ?? 0) : '-',
        'description' => 'Beérkezett fejlesztési ötletek és hibajelzések áttekintése.',
        'href' => '/feedback',
        'variant' => 'system',
    ],
    [
        'label' => 'Árlista tételek',
        'value' => $priceItemCount ?? '-',
        'description' => 'Díjtétel nevek, egységárak, ÁFA és aktív árlista elemek kezelése.',
        'href' => '/admin/price-items',
        'variant' => 'accent',
    ],
];

if ($canManageAdminUsers) {
    $dashboardCards[] = [
        'label' => 'Ügyfélkereső',
        'value' => 'read-only',
        'description' => 'Regisztrált ügyfelek keresése név, email vagy telefonszám alapján.',
        'href' => '/admin/customer-lookup',
        'variant' => 'primary',
    ];

    $dashboardCards[] = [
        'label' => 'Szuper riport',
        'value' => '7 nap',
        'description' => 'Ügyindítások, elfogadott árajánlatok, díjbekérők és státuszváltozások egy helyen.',
        'href' => '/admin/super-overview',
        'variant' => 'primary',
    ];

    if ($canManageUiModules) {
        $dashboardCards[] = [
            'label' => 'CRM testreszabás',
            'value' => 'Admin',
            'description' => 'Modulok, mezőfeliratok és egyedi CRM elemek kezelése szuperadmin jogosultsággal.',
            'href' => '/admin/crm-customization',
            'variant' => 'system',
        ];
    }

    $dashboardCards[] = [
        'label' => 'Adminisztrátorok',
        'value' => $staffUserCount ?? '-',
        'description' => 'Adminisztrátori profilok létrehozása és jogosultsági szintek áttekintése.',
        'href' => '/admin/users',
        'variant' => 'system',
    ];

    $dashboardCards[] = [
        'label' => 'Google Sheet import',
        'value' => 'kézi',
        'description' => 'Facebook leadek előnézete és jóváhagyott Google Sheet sorok kézi importja.',
        'href' => '/admin/google-sheet-import',
        'variant' => 'accent',
    ];
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow"><?= h(user_role_label()); ?></p>
                <h1>Vezérlőpult</h1>
                <p>Bejelentkezve: <?= h($user['name'] ?? 'Admin'); ?> (<?= h(user_role_label()); ?>)</p>
            </div>

            <div class="admin-actions">
                <a class="button" href="<?= h(url_path('/quick-quote')); ?>">Gyors árajánlat</a>
                <form method="post" action="<?= h(url_path('/admin/logout')); ?>">
                    <?= csrf_field(); ?>
                    <button class="button button-secondary" type="submit">Kilépés</button>
                </form>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>">
                <p><?= h((string) $flash['message']); ?></p>
            </div>
        <?php endif; ?>

        <div class="admin-grid dashboard-grid">
            <?php foreach ($dashboardCards as $card): ?>
                <?php
                if (($card['href'] ?? null) === '/admin/price-items' && !$canManagePriceItems) {
                    continue;
                }

                $cardClasses = 'metric-card metric-card-' . $card['variant'];
                $tagName = $card['href'] === null ? 'article' : 'a';

                if ($card['href'] !== null) {
                    $cardClasses .= ' metric-card-link';
                }
                ?>

                <<?= $tagName; ?> class="<?= h($cardClasses); ?>"<?php if ($card['href'] !== null): ?> href="<?= h(url_path((string) $card['href'])); ?>"<?php endif; ?>>
                    <span class="metric-label"><?= h((string) $card['label']); ?></span>
                    <strong><?= h((string) $card['value']); ?></strong>
                    <p><?= h((string) $card['description']); ?></p>
                    <?php if ($card['href'] !== null): ?>
                        <span class="metric-action" aria-hidden="true">&rarr;</span>
                    <?php endif; ?>
                </<?= $tagName; ?>>
            <?php endforeach; ?>
        </div>

        <section class="dashboard-users-section" aria-labelledby="dashboard-users-title">
            <div class="section-heading compact-heading">
                <p class="eyebrow">Felhasználók</p>
                <h2 id="dashboard-users-title">Felhasználók és munkák</h2>
                <p>Gyors áttekintés arról, hogy ki melyik munkát rögzítette, illetve szerelőként melyik munka van neki kiadva.</p>
            </div>

            <?php if ($dashboardUserErrors !== []): ?>
                <div class="alert alert-info">
                    <?php foreach ($dashboardUserErrors as $dashboardUserError): ?>
                        <p><?= h((string) $dashboardUserError); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($dashboardUsers === []): ?>
                <div class="empty-state">
                    <h2>Nincs megjeleníthető felhasználó</h2>
                    <p>Amint létrejön admin, adminisztrátor, szerelő vagy generálkivitelező fiók, itt megjelenik a hozzá tartozó munkákkal együtt.</p>
                </div>
            <?php else: ?>
                <div class="dashboard-user-grid">
                    <?php foreach ($dashboardUsers as $dashboardUser): ?>
                        <?php
                        $submittedRequests = is_array($dashboardUser['submitted'] ?? null) ? $dashboardUser['submitted'] : [];
                        $assignedRequests = is_array($dashboardUser['assigned'] ?? null) ? $dashboardUser['assigned'] : [];
                        $submittedCount = count($submittedRequests);
                        $assignedCount = count($assignedRequests);
                        $totalWorkCount = $submittedCount + $assignedCount;
                        $dashboardUserId = (int) ($dashboardUser['id'] ?? 0);
                        $dashboardUserRole = (string) ($dashboardUser['role'] ?? 'customer');
                        ?>

                        <details class="dashboard-user-card" id="dashboard-user-<?= $dashboardUserId; ?>">
                            <summary class="dashboard-user-summary">
                                <span class="dashboard-user-head">
                                    <span class="dashboard-user-title">
                                        <strong><?= h((string) ($dashboardUser['name'] ?? 'Felhasználó')); ?></strong>
                                        <small>
                                            <?= h(user_role_label($dashboardUserRole)); ?>
                                            <?php if (!empty($dashboardUser['email'])): ?>
                                                · <?= h((string) $dashboardUser['email']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </span>
                                    <span class="dashboard-user-role"><?= h(user_role_label($dashboardUserRole)); ?></span>
                                </span>

                                <span class="dashboard-user-meta" aria-label="Felhasználói munkaszámok">
                                    <span class="dashboard-user-pill">Rögzített: <?= $submittedCount; ?></span>
                                    <?php if ($dashboardUserRole === 'electrician' || $assignedCount > 0): ?>
                                        <span class="dashboard-user-pill">Kiadva: <?= $assignedCount; ?></span>
                                    <?php endif; ?>
                                </span>

                                <span class="dashboard-user-button"><?= $totalWorkCount > 0 ? 'Munkák megtekintése' : 'Nincs munka'; ?></span>
                            </summary>

                            <div class="dashboard-user-details">
                                <?php if ($totalWorkCount === 0): ?>
                                    <p class="dashboard-user-empty">Ehhez a felhasználóhoz még nincs rögzített vagy kiadott munka.</p>
                                <?php else: ?>
                                    <?php if ($submittedRequests !== []): ?>
                                        <div class="dashboard-user-work-group">
                                            <h3>Rögzített munkák</h3>
                                            <ul class="dashboard-user-work-list">
                                                <?php foreach (array_slice($submittedRequests, 0, 8) as $request): ?>
                                                    <?php
                                                    $location = dashboard_request_location($request);
                                                    $requesterName = trim((string) ($request['requester_name'] ?? ''));
                                                    ?>
                                                    <li>
                                                        <a href="<?= h(dashboard_request_admin_url($request)); ?>">
                                                            <strong><?= h(dashboard_request_display_name($request)); ?></strong>
                                                            <span>
                                                                <?= h($requesterName !== '' ? $requesterName : 'Nincs ügyfélnév'); ?>
                                                                <?php if ($location !== ''): ?>
                                                                    · <?= h($location); ?>
                                                                <?php endif; ?>
                                                            </span>
                                                            <small><?= h(connection_request_status_label((string) ($request['request_status'] ?? 'draft'))); ?></small>
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <?php if ($submittedCount > 8): ?>
                                                <p class="dashboard-user-more">+<?= $submittedCount - 8; ?> további rögzített munka.</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($assignedRequests !== []): ?>
                                        <div class="dashboard-user-work-group">
                                            <h3>Kiadott kivitelezési munkák</h3>
                                            <ul class="dashboard-user-work-list">
                                                <?php foreach (array_slice($assignedRequests, 0, 8) as $request): ?>
                                                    <?php
                                                    $location = dashboard_request_location($request);
                                                    $requesterName = trim((string) ($request['requester_name'] ?? ''));
                                                    ?>
                                                    <li>
                                                        <a href="<?= h(dashboard_request_admin_url($request)); ?>">
                                                            <strong><?= h(dashboard_request_display_name($request)); ?></strong>
                                                            <span>
                                                                <?= h($requesterName !== '' ? $requesterName : 'Nincs ügyfélnév'); ?>
                                                                <?php if ($location !== ''): ?>
                                                                    · <?= h($location); ?>
                                                                <?php endif; ?>
                                                            </span>
                                                            <small><?= h(electrician_work_status_label((string) ($request['electrician_status'] ?? 'unassigned'))); ?></small>
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <?php if ($assignedCount > 8): ?>
                                                <p class="dashboard-user-more">+<?= $assignedCount - 8; ?> további kiadott munka.</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($showDashboardWorkflow && ($connectionRequestCount ?? 0) > 0): ?>
            <section class="dashboard-workflow-section">
                <div class="section-heading compact-heading">
                    <p class="eyebrow">Igényfolyamat</p>
                    <h2>Admin munkafolyamat</h2>
                    <p>A mérőhelyi igények folyamatlépések szerint csoportosítva.</p>
                </div>

                <div class="admin-workflow-board dashboard-workflow-board">
                    <?php foreach ($workflowStages as $stageKey => $stage): ?>
                        <a class="admin-workflow-card admin-workflow-card-<?= h((string) $stage['variant']); ?>" href="<?= h(url_path('/admin/minicrm-import') . '#portal-works'); ?>">
                            <span><?= (int) $stage['number']; ?></span>
                            <strong><?= h((string) $stage['title']); ?></strong>
                            <em><?= (int) ($workflowStageCounts[$stageKey] ?? 0); ?> igény</em>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</section>
