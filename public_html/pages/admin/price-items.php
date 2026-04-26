<?php
declare(strict_types=1);

require_role(['admin']);

$editId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$editItem = $editId ? find_price_item($editId) : null;
$errors = [];
$flash = get_flash();
$quoteSections = quote_price_sections();
$defaultCategory = array_key_first($quoteSections);

if ($editId && $editItem === null) {
    set_flash('error', 'Az árlista tétel nem található.');
    redirect('/admin/price-items');
}

$form = $editItem ?: [
    'category' => $defaultCategory,
    'name' => '',
    'unit' => 'db',
    'unit_price' => '0',
    'vat_rate' => '27',
    'sort_order' => '0',
    'is_active' => 1,
];
$form['category'] = quote_normalize_category((string) ($form['category'] ?? $defaultCategory));

if (is_post()) {
    require_valid_csrf_token();
    $form = $_POST;
    $form['category'] = quote_normalize_category((string) ($form['category'] ?? $defaultCategory));

    if (!array_key_exists((string) $form['category'], $quoteSections)) {
        $errors[] = 'A kategória érvénytelen.';
    }
    if (trim((string) ($form['name'] ?? '')) === '') {
        $errors[] = 'A tétel neve kötelező.';
    }

    if ($errors === []) {
        save_price_item($form, $editId ?: null);
        set_flash('success', $editId ? 'Árlista tétel frissült.' : 'Árlista tétel létrejött.');
        redirect('/admin/price-items');
    }
}

$items = all_price_items();
$itemsBySection = array_fill_keys(array_keys($quoteSections), []);

foreach ($items as $item) {
    $category = quote_normalize_category((string) $item['category']);
    $itemsBySection[$category][] = $item;
}

$activeCount = count(array_filter($items, static fn (array $item): bool => (int) $item['is_active'] === 1));
$hiddenCount = count($items) - $activeCount;
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Árlista</h1>
                <p>Az ajánlatkészítő innen veszi az előre rögzített bruttó tételeket.</p>
            </div>
            <div class="admin-actions">
                <?php if ($editId): ?>
                    <a class="button button-secondary" href="<?= h(url_path('/admin/price-items')); ?>">Új tétel</a>
                <?php endif; ?>
                <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <div class="price-summary-grid">
            <div>
                <span>Összes tétel</span>
                <strong><?= count($items); ?></strong>
            </div>
            <div>
                <span>Aktív</span>
                <strong><?= $activeCount; ?></strong>
            </div>
            <div>
                <span>Rejtett</span>
                <strong><?= $hiddenCount; ?></strong>
            </div>
        </div>

        <div class="price-manager-layout">
            <div class="price-catalog">
                <?php foreach ($quoteSections as $category => $section): ?>
                    <?php
                    $sectionItems = $itemsBySection[$category];
                    $sectionActiveCount = count(array_filter($sectionItems, static fn (array $item): bool => (int) $item['is_active'] === 1));
                    ?>
                    <section class="price-section">
                        <div class="price-section-header">
                            <div>
                                <span class="portal-kicker">Árlista szekció</span>
                                <h2><?= h((string) $section['title']); ?></h2>
                            </div>
                            <span><?= count($sectionItems); ?> tétel, <?= $sectionActiveCount; ?> aktív</span>
                        </div>

                        <?php if ($sectionItems === []): ?>
                            <div class="empty-state compact-empty">
                                <h2>Nincs még tétel ebben a szekcióban</h2>
                                <p>Az új tétel űrlapon válaszd ezt a kategóriát, majd mentsd el.</p>
                            </div>
                        <?php else: ?>
                            <div class="price-item-list">
                                <?php foreach ($sectionItems as $item): ?>
                                    <?php
                                    $isActive = (int) $item['is_active'] === 1;
                                    $isEditing = $editId && (int) $item['id'] === (int) $editId;
                                    ?>
                                    <article class="price-item-card <?= $isActive ? '' : 'is-muted'; ?> <?= $isEditing ? 'is-editing' : ''; ?>">
                                        <div class="price-item-main">
                                            <div class="price-item-title-row">
                                                <span class="price-item-order">#<?= (int) $item['sort_order']; ?></span>
                                                <h3><?= h($item['name']); ?></h3>
                                            </div>
                                            <dl class="price-item-meta">
                                                <div>
                                                    <dt>Bruttó egységár</dt>
                                                    <dd><?= h(format_money($item['unit_price'])); ?></dd>
                                                </div>
                                                <div>
                                                    <dt>Egység</dt>
                                                    <dd><?= h($item['unit']); ?></dd>
                                                </div>
                                                <div>
                                                    <dt>ÁFA</dt>
                                                    <dd><?= h(number_format((float) $item['vat_rate'], 2, ',', ' ')); ?>%</dd>
                                                </div>
                                            </dl>
                                        </div>
                                        <div class="price-item-side">
                                            <span class="status-badge <?= $isActive ? 'status-badge-active' : 'status-badge-hidden'; ?>">
                                                <?= $isActive ? 'Aktív' : 'Rejtett'; ?>
                                            </span>
                                            <a class="button button-secondary price-edit-button" href="<?= h(url_path('/admin/price-items') . '?id=' . (int) $item['id']); ?>">
                                                <?= $isEditing ? 'Szerkesztés alatt' : 'Szerkesztés'; ?>
                                            </a>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <aside class="auth-panel price-editor-panel">
                <h2><?= $editId ? 'Tétel szerkesztése' : 'Új árlista tétel'; ?></h2>
                <?php if ($errors !== []): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $error): ?>
                            <p><?= h($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form class="form" method="post" action="<?= h($editId ? url_path('/admin/price-items') . '?id=' . $editId : url_path('/admin/price-items')); ?>">
                    <?= csrf_field(); ?>

                    <label for="category">Kategória</label>
                    <select id="category" name="category" required>
                        <?php foreach ($quoteSections as $category => $section): ?>
                            <option value="<?= h($category); ?>" <?= (string) $form['category'] === $category ? 'selected' : ''; ?>>
                                <?= h($category); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="name">Tétel neve</label>
                    <textarea id="name" name="name" rows="3" required><?= h($form['name'] ?? ''); ?></textarea>

                    <div class="form-grid two compact">
                        <div>
                            <label for="unit">Egység</label>
                            <input id="unit" name="unit" value="<?= h($form['unit'] ?? 'db'); ?>">
                        </div>
                        <div>
                            <label for="sort_order">Sorrend</label>
                            <input id="sort_order" name="sort_order" type="number" step="1" value="<?= h($form['sort_order'] ?? '0'); ?>">
                        </div>
                    </div>

                    <label for="unit_price">Bruttó egységár</label>
                    <input id="unit_price" name="unit_price" type="number" step="1" min="0" value="<?= h($form['unit_price'] ?? '0'); ?>">

                    <label for="vat_rate">ÁFA %</label>
                    <input id="vat_rate" name="vat_rate" type="number" step="0.01" min="0" value="<?= h($form['vat_rate'] ?? '27'); ?>">

                    <label class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" <?= (int) ($form['is_active'] ?? 0) === 1 ? 'checked' : ''; ?>>
                        <span>Aktív tétel</span>
                    </label>

                    <div class="form-actions">
                        <button class="button" type="submit">Mentés</button>
                        <?php if ($editId): ?>
                            <a class="button button-secondary" href="<?= h(url_path('/admin/price-items')); ?>">Mégsem</a>
                        <?php endif; ?>
                    </div>
                </form>
            </aside>
        </div>
    </div>
</section>
