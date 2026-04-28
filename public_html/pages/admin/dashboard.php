<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$user = current_user();
$flash = get_flash();
$canManageMvmDocuments = can_manage_mvm_documents();
$canManagePriceItems = can_manage_price_items();
$canManageAdminUsers = can_manage_admin_users();
$customerCount = null;
$quoteCount = null;
$connectionRequestCount = null;
$priceItemCount = null;
$documentCount = null;
$electricianCount = null;
$staffUserCount = null;
$minicrmImportCount = null;
$workflowStages = admin_workflow_stage_definitions();
$workflowStageCounts = array_fill_keys(array_keys($workflowStages), 0);

try {
    $customerCount = db_table_exists('customers') ? (int) db_query('SELECT COUNT(*) FROM `customers`')->fetchColumn() : 0;
    $quoteCount = db_table_exists('quotes') ? (int) db_query('SELECT COUNT(*) FROM `quotes`')->fetchColumn() : 0;
    $connectionRequestCount = db_table_exists('connection_requests') ? (int) db_query('SELECT COUNT(*) FROM `connection_requests`')->fetchColumn() : 0;
    $priceItemCount = db_table_exists('quote_price_items') ? (int) db_query('SELECT COUNT(*) FROM `quote_price_items`')->fetchColumn() : 0;
    $documentCount = db_table_exists('download_documents') ? (int) db_query('SELECT COUNT(*) FROM `download_documents`')->fetchColumn() : 0;
    $electricianCount = db_table_exists('electricians') ? (int) db_query('SELECT COUNT(*) FROM `electricians`')->fetchColumn() : 0;
    $staffUserCount = users_table_exists() ? (int) db_query('SELECT COUNT(*) FROM `users` WHERE `role` IN (?, ?) OR `is_admin` = ?', ['admin', 'specialist', 1])->fetchColumn() : 0;
    $minicrmImportCount = db_table_exists('minicrm_work_items') ? (int) db_query('SELECT COUNT(*) FROM `minicrm_work_items`')->fetchColumn() : 0;

    if (db_table_exists('connection_requests')) {
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

$dashboardCards = [
    [
        'label' => 'Ügyfelek',
        'value' => $customerCount ?? '-',
        'description' => 'Ügyféladatok megtekintése, javítása és új ügyfelek rögzítése.',
        'href' => '/admin/customers',
        'variant' => 'primary',
    ],
    [
        'label' => 'Mérőhelyi igények',
        'value' => $connectionRequestCount ?? '-',
        'description' => $canManageMvmDocuments
            ? 'Beküldött munkaigények, fájlok, ajánlatfeltöltés és MVM dokumentumok.'
            : 'Beküldött munkaigények, fájlok és ajánlatfeltöltés.',
        'href' => '/admin/connection-requests',
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
        'label' => 'Ajánlatok',
        'value' => $quoteCount ?? '-',
        'description' => 'Ajánlatok készítése, szerkesztése, PDF generálása és kiküldése.',
        'href' => '/admin/quotes',
        'variant' => 'primary',
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
        'label' => 'Adminisztrátorok',
        'value' => $staffUserCount ?? '-',
        'description' => 'Adminisztrátori profilok létrehozása és jogosultsági szintek áttekintése.',
        'href' => '/admin/users',
        'variant' => 'system',
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

        <?php if (($connectionRequestCount ?? 0) > 0): ?>
            <section class="dashboard-workflow-section">
                <div class="section-heading compact-heading">
                    <p class="eyebrow">Igényfolyamat</p>
                    <h2>Admin munkafolyamat</h2>
                    <p>A mérőhelyi igények folyamatlépések szerint csoportosítva.</p>
                </div>

                <div class="admin-workflow-board dashboard-workflow-board">
                    <?php foreach ($workflowStages as $stageKey => $stage): ?>
                        <a class="admin-workflow-card admin-workflow-card-<?= h((string) $stage['variant']); ?>" href="<?= h(url_path('/admin/connection-requests') . '#workflow-stage-' . $stageKey); ?>">
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
