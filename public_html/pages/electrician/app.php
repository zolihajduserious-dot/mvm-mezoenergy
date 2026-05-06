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
$activeRequests = [];
$completedRequests = [];
$notStartedCount = 0;
$inProgressCount = 0;

foreach ($requests as $request) {
    $beforeDone = !empty($request['before_photos_completed_at']);
    $afterDone = !empty($request['after_photos_completed_at'])
        || (string) ($request['electrician_status'] ?? '') === 'completed';

    if ($afterDone) {
        $completedRequests[] = $request;
        continue;
    }

    $activeRequests[] = $request;

    if ($beforeDone) {
        $inProgressCount++;
    } else {
        $notStartedCount++;
    }
}

$visibleRequests = array_slice($activeRequests, 0, 18);

function electrician_mobile_app_detail_url(int $requestId, ?string $workStage = null): string
{
    $url = url_path('/electrician/work-request') . '?id=' . $requestId;

    if ($workStage !== null) {
        $url .= '&work_stage=' . rawurlencode($workStage);
    }

    return $url;
}

function electrician_mobile_app_customer_name(array $request): string
{
    return trim((string) ($request['requester_name'] ?? '')) ?: ('Munka #' . (int) ($request['id'] ?? 0));
}

function electrician_mobile_app_address(array $request): string
{
    $address = trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));

    return $address !== '' ? $address : '-';
}

function electrician_mobile_app_status_class(array $request): string
{
    if (!empty($request['after_photos_completed_at']) || (string) ($request['electrician_status'] ?? '') === 'completed') {
        return 'completed';
    }

    if (!empty($request['before_photos_completed_at'])) {
        return 'in-progress';
    }

    return 'pending';
}

function electrician_mobile_app_next_action_label(array $request): string
{
    if (!empty($request['after_photos_completed_at']) || (string) ($request['electrician_status'] ?? '') === 'completed') {
        return 'Készre jelentve';
    }

    return !empty($request['before_photos_completed_at'])
        ? 'Befejezem a kivitelezést'
        : 'Megkezdem a kivitelezést';
}
?>
<section class="electrician-mobile-app">
    <div class="container electrician-app-container">
        <header class="electrician-app-header">
            <div>
                <p class="eyebrow">Szerelő app</p>
                <h1><?= h((string) ($electrician['name'] ?? $user['name'] ?? 'Szerelő')); ?></h1>
            </div>
            <span class="electrician-app-count"><?= count($activeRequests); ?> aktív</span>
        </header>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-error">
                <p>Előbb futtasd le phpMyAdminban a <strong>database/electrician_workflow.sql</strong> fájlt.</p>
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="electrician-app-actions" aria-label="Szerelői gyors műveletek">
                <a class="electrician-app-action electrician-app-action-primary" href="<?= h(url_path('/quick-quote')); ?>">
                    <span>Gyors árajánlat</span>
                    <strong>Új ajánlat</strong>
                </a>
                <a class="electrician-app-action" href="<?= h(url_path('/electrician/work-request')); ?>">
                    <span>Új felmérés</span>
                    <strong>Adatlap + fotók</strong>
                </a>
                <a class="electrician-app-action" href="<?= h(url_path('/electrician/work-requests')); ?>">
                    <span>Minden munka</span>
                    <strong>Teljes lista</strong>
                </a>
            </div>

            <div class="electrician-app-stats" aria-label="Munkák összesítése">
                <article>
                    <span>Kezdésre vár</span>
                    <strong><?= $notStartedCount; ?></strong>
                </article>
                <article>
                    <span>Folyamatban</span>
                    <strong><?= $inProgressCount; ?></strong>
                </article>
                <article>
                    <span>Lezárt</span>
                    <strong><?= count($completedRequests); ?></strong>
                </article>
            </div>

            <section class="electrician-app-worklist" aria-labelledby="electrician-app-worklist-title">
                <div class="electrician-app-section-head">
                    <div>
                        <p class="eyebrow">Helyszíni munkák</p>
                        <h2 id="electrician-app-worklist-title">Aktív feladatok</h2>
                    </div>
                    <a href="<?= h(url_path('/electrician/work-requests')); ?>">Lista</a>
                </div>

                <?php if ($visibleRequests === []): ?>
                    <div class="electrician-app-empty">
                        <strong>Nincs aktív kivitelezési munka.</strong>
                        <p>Új árajánlatot vagy felmérést ettől még indíthatsz.</p>
                    </div>
                <?php else: ?>
                    <div class="electrician-app-card-list">
                        <?php foreach ($visibleRequests as $request): ?>
                            <?php
                            $requestId = (int) $request['id'];
                            $beforeDone = !empty($request['before_photos_completed_at']);
                            $afterDone = !empty($request['after_photos_completed_at']) || (string) ($request['electrician_status'] ?? '') === 'completed';
                            $status = (string) ($request['electrician_status'] ?? 'assigned');
                            $statusLabel = $statusLabels[$status] ?? electrician_work_status_label($status);
                            $primaryStage = $beforeDone ? 'after' : 'before';
                            $primaryUrl = electrician_mobile_app_detail_url($requestId, $primaryStage);
                            $quoteUrl = url_path('/quick-quote') . '?request_id=' . $requestId;
                            $filesUrl = electrician_mobile_app_detail_url($requestId) . '#electrician-request-files';
                            $detailUrl = electrician_mobile_app_detail_url($requestId);
                            ?>
                            <article class="electrician-app-work-card electrician-app-work-card-<?= h(electrician_mobile_app_status_class($request)); ?>">
                                <div class="electrician-app-work-main">
                                    <span class="electrician-app-status"><?= h($statusLabel); ?></span>
                                    <h3><?= h(electrician_mobile_app_customer_name($request)); ?></h3>
                                    <p><?= h(electrician_mobile_app_address($request)); ?></p>
                                    <small><?= h((string) ($request['project_name'] ?? connection_request_type_label($request['request_type'] ?? null))); ?></small>
                                </div>

                                <div class="electrician-app-work-progress" aria-label="Fotózási állapot">
                                    <span class="<?= $beforeDone ? 'is-done' : ''; ?>">Előtte</span>
                                    <span class="<?= $afterDone ? 'is-done' : ''; ?>">Utána</span>
                                </div>

                                <div class="electrician-app-work-actions">
                                    <a class="button" href="<?= h($primaryUrl); ?>"><?= h(electrician_mobile_app_next_action_label($request)); ?></a>
                                    <a class="button button-secondary" href="<?= h($quoteUrl); ?>">Gyors ajánlat</a>
                                    <a class="button button-secondary" href="<?= h($filesUrl); ?>">Dokumentum befotózás</a>
                                    <a class="electrician-app-inline-link" href="<?= h($detailUrl); ?>">Adatlap megnyitása</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if (count($activeRequests) > count($visibleRequests)): ?>
                        <a class="electrician-app-more" href="<?= h(url_path('/electrician/work-requests')); ?>">
                            További <?= count($activeRequests) - count($visibleRequests); ?> aktív munka megnyitása
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</section>
