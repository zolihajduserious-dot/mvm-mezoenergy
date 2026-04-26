<?php
declare(strict_types=1);

require_role(['electrician']);

$schemaErrors = electrician_schema_errors();
$user = current_user();
$electrician = current_electrician();

if (!is_array($user) || ($schemaErrors === [] && $electrician === null)) {
    set_flash('error', 'A szerelői adatok nem találhatók.');
    redirect('/login');
}

$requests = $schemaErrors === [] ? connection_requests_for_electrician((int) $user['id']) : [];
$flash = get_flash();
$statusLabels = electrician_work_status_labels();
$assignedCount = 0;
$inProgressCount = 0;
$completedCount = 0;

foreach ($requests as $requestSummary) {
    $status = (string) ($requestSummary['electrician_status'] ?? 'assigned');

    if ($status === 'completed') {
        $completedCount++;
    } elseif ($status === 'in_progress') {
        $inProgressCount++;
    } else {
        $assignedCount++;
    }
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Szerelői portál</p>
                <h1>Munkáim</h1>
                <p><?= h((string) ($electrician['name'] ?? $user['name'] ?? 'Szerelő')); ?> kivitelezési munkái és saját felmérései.</p>
            </div>
            <div class="admin-actions">
                <a class="button" href="<?= h(url_path('/quick-quote')); ?>">Gyors árajánlat</a>
                <a class="button button-secondary" href="<?= h(url_path('/electrician/work-request')); ?>">Új ügyfél felmérése</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-error">
                <p>Előbb futtasd le phpMyAdminban a <strong>database/electrician_workflow.sql</strong> fájlt.</p>
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="admin-grid summary-grid">
                <article class="metric-card metric-card-primary">
                    <span class="metric-label">Kiadott munkák</span>
                    <strong><?= $assignedCount; ?></strong>
                    <p>Előkészített munkák, amelyek még indításra várnak.</p>
                </article>
                <article class="metric-card metric-card-accent">
                    <span class="metric-label">Folyamatban</span>
                    <strong><?= $inProgressCount; ?></strong>
                    <p>Előtte fotók már feltöltve, kivitelezés folyamatban.</p>
                </article>
                <article class="metric-card metric-card-system">
                    <span class="metric-label">Készre jelentve</span>
                    <strong><?= $completedCount; ?></strong>
                    <p>Utána fotók és elkészült beavatkozási lap feltöltve.</p>
                </article>
            </div>

            <?php if ($requests === []): ?>
                <div class="empty-state">
                    <h2>Még nincs kiadott munka</h2>
                    <p>Az admin itt fogja kiadni a kivitelezéseket, de új felmérést már most is rögzíthetsz.</p>
                    <div class="admin-actions">
                        <a class="button" href="<?= h(url_path('/quick-quote')); ?>">Gyors árajánlat</a>
                        <a class="button button-secondary" href="<?= h(url_path('/electrician/work-request')); ?>">Új ügyfél felmérése</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="portal-card-grid">
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $status = (string) ($request['electrician_status'] ?? 'assigned');
                        $beforeFiles = connection_request_work_files((int) $request['id'], 'before');
                        $afterFiles = connection_request_work_files((int) $request['id'], 'after');
                        $quotes = quotes_for_connection_request((int) $request['id']);
                        $acceptedQuote = accepted_quote_for_connection_request((int) $request['id']);
                        $latestQuote = $quotes[0] ?? null;
                        $quoteState = quote_state_summary($latestQuote, $acceptedQuote, connection_request_quote_missing_reason($request));
                        ?>
                        <article class="portal-card">
                            <div class="portal-card-header">
                                <div>
                                    <span class="portal-kicker">#<?= (int) $request['id']; ?> · <?= h($request['created_at'] ?? '-'); ?></span>
                                    <h2><?= h((string) $request['project_name']); ?></h2>
                                    <p><?= h(connection_request_type_label($request['request_type'] ?? null)); ?></p>
                                </div>
                                <span class="status-badge status-badge-<?= h($status); ?>"><?= h($statusLabels[$status] ?? $status); ?></span>
                            </div>

                            <div class="portal-card-details">
                                <div><span>Ügyfél</span><strong><?= h((string) $request['requester_name']); ?></strong></div>
                                <div><span>Telefon</span><strong><?= h((string) $request['phone']); ?></strong></div>
                                <div><span>Email</span><strong><?= h((string) $request['email']); ?></strong></div>
                                <div><span>Cím</span><strong><?= h((string) $request['site_postal_code'] . ' ' . (string) $request['site_address']); ?></strong></div>
                                <div><span>Mérő</span><strong><?= h((string) ($request['meter_serial'] ?: '-')); ?></strong></div>
                                <div><span>Előtte / utána</span><strong><?= count($beforeFiles); ?> / <?= count($afterFiles); ?> fájl</strong></div>
                            </div>

                            <div class="quote-state-card quote-state-card-<?= h((string) $quoteState['class']); ?>">
                                <div>
                                    <span class="portal-kicker">Árajánlat állapota</span>
                                    <strong><?= h((string) $quoteState['title']); ?></strong>
                                    <p><?= h((string) $quoteState['description']); ?></p>
                                </div>
                                <strong><?= h((string) $quoteState['amount']); ?></strong>
                            </div>

                            <div class="portal-card-files">
                                <h3>Műveletek</h3>
                                <div class="inline-link-list">
                                    <a href="<?= h(url_path('/electrician/work-request') . '?id=' . (int) $request['id']); ?>">Munka megnyitása</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
