<?php
declare(strict_types=1);

require_role(['electrician']);

$schemaErrors = electrician_schema_errors();
$user = current_user();
$electrician = current_electrician();

if (!is_array($user) || ($schemaErrors === [] && $electrician === null)) {
    set_flash('error', 'A szereloi adatok nem talalhatok.');
    redirect('/login');
}

$requests = $schemaErrors === [] ? connection_requests_for_electrician((int) $user['id']) : [];
$flash = get_flash();
$statusLabels = electrician_work_status_labels();
$quoteStatusLabels = quote_status_labels();
$assignedCount = 0;
$inProgressCount = 0;
$completedCount = 0;
$requestsByStatus = [];

foreach ($statusLabels as $statusKey => $statusLabel) {
    $requestsByStatus[$statusKey] = [
        'label' => $statusLabel,
        'items' => [],
    ];
}

foreach ($requests as $requestSummary) {
    $status = (string) ($requestSummary['electrician_status'] ?? 'assigned');

    if ($status === 'completed') {
        $completedCount++;
    } elseif ($status === 'in_progress') {
        $inProgressCount++;
    } else {
        $assignedCount++;
    }

    if (!isset($requestsByStatus[$status])) {
        $requestsByStatus[$status] = [
            'label' => $statusLabels[$status] ?? $status,
            'items' => [],
        ];
    }

    $requestsByStatus[$status]['items'][] = $requestSummary;
}

$requestsByStatus = array_filter($requestsByStatus, static fn (array $group): bool => $group['items'] !== []);
$totalRequests = count($requests);

function electrician_work_dom_id(string $value): string
{
    $id = preg_replace('/[^a-z0-9]+/', '-', minicrm_import_key($value)) ?: '';
    $id = trim($id, '-');

    return $id !== '' ? $id : 'statusz';
}

function electrician_work_status_class(string $status): string
{
    return match ($status) {
        'completed' => 'completed',
        'in_progress' => 'in_progress',
        'assigned' => 'pending',
        default => 'draft',
    };
}
?>
<section class="admin-section minicrm-import-page electrician-work-page">
    <div class="container admin-requests-container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Szerel&#337;i port&#225;l</p>
                <h1>Munk&#225;im</h1>
                <p><?= h((string) ($electrician['name'] ?? $user['name'] ?? 'Szerelo')); ?> kivitelez&#233;si munk&#225;i, MiniCRM-b&#337;l kiadott feladatok &#233;s saj&#225;t felm&#233;r&#233;sek egys&#233;ges munkalist&#225;ban.</p>
            </div>
            <div class="form-actions">
                <a class="button" href="<?= h(url_path('/quick-quote')); ?>">Gyors &#225;raj&#225;nlat</a>
                <a class="button button-secondary" href="<?= h(url_path('/electrician/work-request')); ?>">&#218;j &#252;gyf&#233;l felm&#233;r&#233;se</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <nav class="minicrm-module-tabs" aria-label="Szereloi menu">
            <a class="is-active" href="#electrician-works" data-electrician-tab="works">Munk&#225;k</a>
            <a href="<?= h(url_path('/electrician/work-request')); ?>">&#218;j felm&#233;r&#233;s</a>
            <a href="<?= h(url_path('/quick-quote')); ?>">Gyors &#225;raj&#225;nlat</a>
            <a href="<?= h(url_path('/electrician/profile')); ?>">Profil</a>
        </nav>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-error">
                <p>El&#337;bb futtasd le phpMyAdminban a <strong>database/electrician_workflow.sql</strong> f&#225;jlt.</p>
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="admin-grid summary-grid request-summary-grid">
                <article class="metric-card metric-card-primary">
                    <span class="metric-label">Kiadott munk&#225;k</span>
                    <strong><?= $assignedCount; ?></strong>
                    <p>El&#337;k&#233;sz&#237;tett munk&#225;k, amelyek m&#233;g ind&#237;t&#225;sra v&#225;rnak.</p>
                </article>
                <article class="metric-card metric-card-accent">
                    <span class="metric-label">Folyamatban</span>
                    <strong><?= $inProgressCount; ?></strong>
                    <p>El&#337;tte fot&#243;k m&#225;r felt&#246;ltve, kivitelez&#233;s folyamatban.</p>
                </article>
                <article class="metric-card metric-card-system">
                    <span class="metric-label">K&#233;szre jelentve</span>
                    <strong><?= $completedCount; ?></strong>
                    <p>Ut&#225;na fot&#243;k &#233;s elk&#233;sz&#252;lt beavatkoz&#225;si lap felt&#246;ltve.</p>
                </article>
                <article class="metric-card metric-card-alert">
                    <span class="metric-label">&#214;sszes munka</span>
                    <strong><?= $totalRequests; ?></strong>
                    <p>Aktu&#225;lisan a neveden l&#233;v&#337; munk&#225;k &#233;s felm&#233;r&#233;sek.</p>
                </article>
            </div>

            <?php if ($requests === []): ?>
                <div class="empty-state" id="electrician-works">
                    <h2>M&#233;g nincs kiadott munka</h2>
                    <p>Az admin itt fogja kiadni a kivitelez&#233;seket, de &#250;j felm&#233;r&#233;st m&#225;r most is r&#246;gz&#237;thetsz.</p>
                    <div class="form-actions">
                        <a class="button" href="<?= h(url_path('/quick-quote')); ?>">Gyors &#225;raj&#225;nlat</a>
                        <a class="button button-secondary" href="<?= h(url_path('/electrician/work-request')); ?>">&#218;j &#252;gyf&#233;l felm&#233;r&#233;se</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="admin-workflow-list minicrm-workspace" id="electrician-works" data-electrician-panel="works">
                    <section class="admin-workflow-stage">
                        <div class="admin-workflow-stage-head minicrm-workspace-head">
                            <div>
                                <span class="eyebrow">Munkalista</span>
                                <h2>Szerel&#337;i feladatok</h2>
                                <p>MiniCRM importb&#243;l kiadott munk&#225;k &#233;s saj&#225;t felm&#233;r&#233;sek egy kereshet&#337; list&#225;ban.</p>
                            </div>
                            <span class="status-badge status-badge-finalized"><?= $totalRequests; ?> munka</span>
                        </div>

                        <div class="minicrm-list-tools">
                            <label for="electrician_work_search">Keres&#233;s</label>
                            <input id="electrician_work_search" type="search" placeholder="&#220;gyf&#233;l, c&#237;m, munka, email, telefon vagy st&#225;tusz" data-electrician-search>
                            <span data-electrician-count><?= $totalRequests; ?> db</span>
                        </div>

                        <nav class="minicrm-status-nav" aria-label="Szereloi munka statuszok">
                            <?php foreach ($requestsByStatus as $statusKey => $statusGroup): ?>
                                <a href="#electrician-status-<?= h(electrician_work_dom_id((string) $statusKey)); ?>">
                                    <span><?= h((string) $statusGroup['label']); ?></span>
                                    <strong><?= count($statusGroup['items']); ?></strong>
                                </a>
                            <?php endforeach; ?>
                        </nav>

                        <div class="minicrm-status-groups" data-electrician-list>
                            <?php foreach ($requestsByStatus as $statusKey => $statusGroup): ?>
                                <?php $statusClass = electrician_work_status_class((string) $statusKey); ?>
                                <section class="minicrm-status-group" id="electrician-status-<?= h(electrician_work_dom_id((string) $statusKey)); ?>" data-electrician-status-group>
                                    <header class="minicrm-status-group-head">
                                        <div>
                                            <span class="status-badge status-badge-<?= h($statusClass); ?>"><?= h((string) $statusGroup['label']); ?></span>
                                            <strong><?= count($statusGroup['items']); ?> munka</strong>
                                        </div>
                                        <span data-electrician-status-count><?= count($statusGroup['items']); ?> l&#225;that&#243;</span>
                                    </header>

                                    <div class="minicrm-work-table" role="table" aria-label="<?= h((string) $statusGroup['label']); ?> munkak">
                                        <div class="minicrm-work-table-head" role="row">
                                            <span>Munka</span>
                                            <span>&#220;gyf&#233;l</span>
                                            <span>&#193;llapot</span>
                                            <span>Anyag</span>
                                        </div>

                                        <?php foreach ($statusGroup['items'] as $request): ?>
                                            <?php
                                            $requestId = (int) $request['id'];
                                            $status = (string) ($request['electrician_status'] ?? 'assigned');
                                            $statusLabel = $statusLabels[$status] ?? $status;
                                            $beforeFiles = connection_request_work_files($requestId, 'before');
                                            $afterFiles = connection_request_work_files($requestId, 'after');
                                            $quotes = quotes_for_connection_request($requestId);
                                            $acceptedQuote = accepted_quote_for_connection_request($requestId);
                                            $latestQuote = $quotes[0] ?? null;
                                            $quoteState = quote_state_summary($latestQuote, $acceptedQuote, connection_request_quote_missing_reason($request));
                                            $siteAddress = trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));
                                            $detailUrl = url_path('/electrician/work-request') . '?id=' . $requestId;
                                            $createdAt = trim((string) ($request['created_at'] ?? ''));
                                            $searchText = implode(' ', [
                                                (string) ($request['project_name'] ?? ''),
                                                (string) ($request['requester_name'] ?? ''),
                                                (string) ($request['email'] ?? ''),
                                                (string) ($request['phone'] ?? ''),
                                                $siteAddress,
                                                $statusLabel,
                                                connection_request_type_label($request['request_type'] ?? null),
                                                (string) ($request['meter_serial'] ?? ''),
                                            ]);
                                            ?>
                                            <details class="admin-workflow-request minicrm-work-row electrician-work-row" data-electrician-item data-electrician-search-text="<?= h($searchText); ?>">
                                                <summary class="admin-workflow-request-summary minicrm-work-row-summary">
                                                    <span class="admin-workflow-request-main">
                                                        <strong><?= h((string) $request['project_name']); ?></strong>
                                                        <small><?= h($siteAddress !== '' ? $siteAddress : ('#' . $requestId)); ?></small>
                                                    </span>
                                                    <span class="admin-workflow-request-meta">
                                                        <span><?= h((string) ($request['requester_name'] ?: '-')); ?></span>
                                                        <strong><?= h(connection_request_type_label($request['request_type'] ?? null)); ?></strong>
                                                    </span>
                                                    <span class="minicrm-work-date">
                                                        <?= h($createdAt !== '' ? $createdAt : '-'); ?>
                                                    </span>
                                                    <span class="admin-workflow-request-badges">
                                                        <strong><?= count($beforeFiles); ?> / <?= count($afterFiles); ?> f&#225;jl</strong>
                                                        <small><?= h((string) $quoteState['status']); ?> - <?= h((string) $quoteState['amount']); ?></small>
                                                    </span>
                                                </summary>

                                                <article class="request-admin-card minicrm-work-card">
                                                    <div class="request-admin-card-head">
                                                        <div>
                                                            <span class="portal-kicker">#<?= $requestId; ?> - <?= h($statusLabel); ?></span>
                                                            <h2><?= h((string) $request['project_name']); ?></h2>
                                                            <p><?= h((string) ($request['requester_name'] ?: '-')); ?> - <?= h($siteAddress !== '' ? $siteAddress : '-'); ?></p>
                                                        </div>
                                                        <div class="request-admin-status">
                                                            <span class="status-badge status-badge-<?= h($status); ?>"><?= h($statusLabel); ?></span>
                                                            <?php if ($acceptedQuote !== null): ?>
                                                                <span class="status-badge status-badge-accepted">Aj&#225;nlat elfogadva</span>
                                                            <?php elseif ($latestQuote !== null): ?>
                                                                <span class="status-badge status-badge-<?= h((string) ($latestQuote['status'] ?? 'draft')); ?>"><?= h($quoteStatusLabels[(string) ($latestQuote['status'] ?? 'draft')] ?? (string) ($latestQuote['status'] ?? 'draft')); ?></span>
                                                            <?php endif; ?>
                                                            <a class="button" href="<?= h($detailUrl); ?>">Munka megnyit&#225;sa</a>
                                                        </div>
                                                    </div>

                                                    <div class="minicrm-work-detail-layout">
                                                        <aside class="minicrm-work-facts">
                                                            <dl>
                                                                <div><dt>&#220;gyf&#233;l</dt><dd><?= h((string) ($request['requester_name'] ?: '-')); ?></dd></div>
                                                                <div><dt>Telefon</dt><dd><?= h((string) ($request['phone'] ?: '-')); ?></dd></div>
                                                                <div><dt>Email</dt><dd><?= h((string) ($request['email'] ?: '-')); ?></dd></div>
                                                                <div><dt>C&#237;m</dt><dd><?= h($siteAddress !== '' ? $siteAddress : '-'); ?></dd></div>
                                                                <div><dt>M&#233;r&#337;</dt><dd><?= h((string) ($request['meter_serial'] ?: '-')); ?></dd></div>
                                                                <div><dt>El&#337;tte / ut&#225;na</dt><dd><?= count($beforeFiles); ?> / <?= count($afterFiles); ?> f&#225;jl</dd></div>
                                                            </dl>
                                                        </aside>

                                                        <div class="minicrm-work-main">
                                                            <section class="minicrm-document-preview-panel">
                                                                <div class="admin-request-section-title">
                                                                    <h3>Aj&#225;nlat &#225;llapota</h3>
                                                                    <span><?= h((string) $quoteState['status']); ?></span>
                                                                </div>
                                                                <div class="quote-state-card quote-state-card-<?= h((string) $quoteState['class']); ?>">
                                                                    <div>
                                                                        <span class="portal-kicker">Aj&#225;nlat</span>
                                                                        <strong><?= h((string) $quoteState['title']); ?></strong>
                                                                        <p><?= h((string) $quoteState['description']); ?></p>
                                                                    </div>
                                                                    <strong><?= h((string) $quoteState['amount']); ?></strong>
                                                                </div>
                                                            </section>

                                                            <section class="minicrm-document-preview-panel">
                                                                <div class="admin-request-section-title">
                                                                    <h3>Munkafolyamat</h3>
                                                                    <span><?= h($statusLabel); ?></span>
                                                                </div>
                                                                <div class="minicrm-readable-grid">
                                                                    <article class="minicrm-readable-row">
                                                                        <span>Indul&#243; fot&#243;k</span>
                                                                        <strong><?= count($beforeFiles); ?> felt&#246;lt&#246;tt f&#225;jl</strong>
                                                                        <small><?= empty($request['before_photos_completed_at']) ? 'M&#233;g nincs lez&#225;rva' : h((string) $request['before_photos_completed_at']); ?></small>
                                                                    </article>
                                                                    <article class="minicrm-readable-row">
                                                                        <span>K&#233;sz munka</span>
                                                                        <strong><?= count($afterFiles); ?> felt&#246;lt&#246;tt f&#225;jl</strong>
                                                                        <small><?= empty($request['after_photos_completed_at']) ? 'M&#233;g nincs lez&#225;rva' : h((string) $request['after_photos_completed_at']); ?></small>
                                                                    </article>
                                                                    <article class="minicrm-readable-row">
                                                                        <span>M&#369;velet</span>
                                                                        <a href="<?= h($detailUrl); ?>">Munka megnyit&#225;sa</a>
                                                                        <small>Fot&#243;k, dokumentumok &#233;s r&#233;szletek kezel&#233;se</small>
                                                                    </article>
                                                                </div>
                                                            </section>
                                                        </div>
                                                    </div>
                                                </article>
                                            </details>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const input = document.querySelector('[data-electrician-search]');
    const count = document.querySelector('[data-electrician-count]');
    const items = Array.from(document.querySelectorAll('[data-electrician-item]'));
    const groups = Array.from(document.querySelectorAll('[data-electrician-status-group]'));

    if (!input || !count || items.length === 0) {
        return;
    }

    const searchable = items.map((item) => ({
        item,
        text: `${item.textContent} ${item.dataset.electricianSearchText || ''}`.toLocaleLowerCase('hu-HU'),
    }));

    input.addEventListener('input', () => {
        const query = input.value.trim().toLocaleLowerCase('hu-HU');
        let visible = 0;

        searchable.forEach(({ item, text }) => {
            const show = query === '' || text.includes(query);
            item.hidden = !show;
            visible += show ? 1 : 0;
        });

        groups.forEach((group) => {
            const groupItems = Array.from(group.querySelectorAll('[data-electrician-item]'));
            const groupVisible = groupItems.filter((item) => !item.hidden).length;
            const groupCount = group.querySelector('[data-electrician-status-count]');

            group.hidden = groupVisible === 0;

            if (groupCount) {
                groupCount.textContent = `${groupVisible} l\u00e1that\u00f3`;
            }
        });

        count.textContent = `${visible} db`;
    });
});
</script>
