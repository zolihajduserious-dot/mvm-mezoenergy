<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$flash = get_flash();
$deps = dependency_status();

if (is_post()) {
    require_valid_csrf_token();
    $result = generate_minicrm_export();

    if ($result['ok'] && !empty($result['path']) && is_file((string) $result['path'])) {
        $extension = strtolower(pathinfo((string) $result['path'], PATHINFO_EXTENSION));
        $contentType = $extension === 'csv'
            ? 'text/csv; charset=UTF-8'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . basename((string) $result['path']) . '"');
        header('Content-Length: ' . (string) filesize((string) $result['path']));
        readfile((string) $result['path']);
        exit;
    }

    set_flash('error', $result['message']);
    redirect('/admin/minicrm-export');
}
?>
<section class="admin-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">MiniCRM</p>
            <h1>Excel export</h1>
            <p>Az export a mellekelt MiniCRM importminta 34 oszlopos fejlecehez igazodik.</p>
        </div>
        <div class="auth-panel">
            <h2>Export generalasa</h2>
            <?php if ($flash !== null): ?><div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div><?php endif; ?>
            <?php if (!$deps['phpspreadsheet']): ?>
                <div class="alert alert-error">
                    <p>A PhpSpreadsheet nincs telepítve, ezért most CSV export készül. XLSX exporthoz töltsd fel a Composer vendor mappát.</p>
                </div>
            <?php endif; ?>
            <div class="status-list">
                <li><span class="status-label">PhpSpreadsheet</span><span class="status-value"><?= $deps['phpspreadsheet'] ? 'OK - XLSX export' : 'Hianyzik - CSV fallback'; ?></span></li>
            </div>
            <form class="form" method="post" action="<?= h(url_path('/admin/minicrm-export')); ?>">
                <?= csrf_field(); ?>
                <button class="button" type="submit"><?= $deps['phpspreadsheet'] ? 'MiniCRM XLSX export' : 'MiniCRM CSV export'; ?></button>
            </form>
        </div>
    </div>
</section>
