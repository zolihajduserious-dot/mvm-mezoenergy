<?php
declare(strict_types=1);

require_login();

if (is_post()) {
    require_valid_csrf_token();
    logout_user();
    set_flash('success', 'Sikeresen kijelentkeztel.');
redirect('/login');
}
?>
<section class="auth-section">
    <div class="container auth-layout">
        <div class="auth-copy">
            <p class="eyebrow">Admin</p>
            <h1>Kilépés</h1>
            <p>Biztonsagi okbol a kilepes csak gombnyomassal tortenik.</p>
        </div>

        <div class="auth-panel">
            <h2>Biztosan kilepsz?</h2>
            <form class="form" method="post" action="<?= h(url_path('/admin/logout')); ?>">
                <?= csrf_field(); ?>
                <button class="button" type="submit">Kilépés</button>
            </form>
        </div>
    </div>
</section>
