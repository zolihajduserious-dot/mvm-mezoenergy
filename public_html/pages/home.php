<?php
declare(strict_types=1);

$primaryDashboardUrl = '/login';
$primaryDashboardLabel = 'Belépés';

if (is_logged_in()) {
    if (is_staff_user()) {
        $primaryDashboardUrl = '/admin/dashboard';
        $primaryDashboardLabel = 'Admin felület';
    } elseif (is_general_contractor_user()) {
        $primaryDashboardUrl = '/contractor/work-requests';
        $primaryDashboardLabel = 'Munkák megnyitása';
    } elseif (is_electrician_user()) {
        $primaryDashboardUrl = '/electrician/work-requests';
        $primaryDashboardLabel = 'Szerelői munkák';
    } else {
        $primaryDashboardUrl = '/customer/work-requests';
        $primaryDashboardLabel = 'Igényeim megnyitása';
    }
}
?>
<section class="home-hero">
    <div class="home-hero-media" aria-hidden="true"></div>
    <div class="container home-hero-inner">
        <div class="home-hero-copy">
            <img class="hero-logo" src="<?= h(asset_url('img/mezo-energy-logo.png')); ?>" alt="Mező Energy Kft. logó">
            <p class="eyebrow">Mérőhelyi ügyintézés</p>
            <p>
                Ügyféladatok, mérőhelyi munkák, dokumentumok és árajánlatok egy helyen,
                átlátható online folyamatban.
            </p>
            <div class="hero-actions">
                <a class="button" href="<?= h(url_path($primaryDashboardUrl)); ?>"><?= h($primaryDashboardLabel); ?></a>
                <?php if (!is_logged_in()): ?>
                    <a class="button button-secondary" href="<?= h(url_path('/electrician/register')); ?>">Szerelői regisztráció</a>
                    <a class="button button-secondary" href="<?= h(url_path('/electrician/login')); ?>">Szerelői belépés</a>
                    <a class="button button-secondary" href="<?= h(url_path('/index.php?route=register')); ?>">Ügyfél regisztráció</a>
                    <a class="button button-secondary" href="<?= h(url_path('/index.php?route=contractor/register')); ?>">Generálkivitelező regisztráció</a>
                <?php endif; ?>
                <a class="button button-light" href="<?= h(url_path('/documents')); ?>">Dokumentumtár</a>
            </div>
        </div>
    </div>
</section>

<section class="quick-services">
    <div class="container service-grid">
        <article class="service-card service-card-primary">
            <span>01</span>
            <h2>Munkaigény beküldése</h2>
            <p>Az ügyfél vagy a generálkivitelező rögzíti az adatokat, a helyszínt, a teljesítményigényt és a szükséges fájlokat.</p>
            <a href="<?= h(url_path(is_logged_in() && is_general_contractor_user() ? '/contractor/work-request' : (is_logged_in() && is_electrician_user() ? '/electrician/work-request' : '/customer/work-request'))); ?>">Igény indítása</a>
        </article>

        <article class="service-card">
            <span>02</span>
            <h2>Dokumentumok</h2>
            <p>Meghatalmazások, hozzájáruló nyilatkozatok és MVM ügyintézési dokumentumok letöltése.</p>
            <a href="<?= h(url_path('/documents')); ?>">Dokumentumtár</a>
        </article>

        <article class="service-card">
            <span>03</span>
            <h2>Árajánlatok</h2>
            <p>A Mező Energy Kft. által elkészített árajánlatok megtekintése és elfogadása az ügyfélportálon.</p>
            <a href="<?= h(url_path('/customer/quotes')); ?>">Ajánlataim</a>
        </article>
    </div>
</section>

<section class="feature-band">
    <div class="container feature-layout">
        <div class="feature-copy">
            <p class="eyebrow">Online műszaki ügyintézés</p>
            <h2>Mérőhelyi munkákhoz tervezett adatbeküldés</h2>
            <p>
                A rendszer a helyszíni fotókat, tulajdoni lapot, térképmásolatot, meghatalmazást
                és a hozzájáruló nyilatkozatot is a megfelelő munkához kapcsolja.
            </p>
            <div class="feature-actions">
                <a class="button" href="<?= h(url_path('/documents')); ?>">Letölthető dokumentumok</a>
                <a class="button button-secondary" href="<?= h(url_path('/login')); ?>">Ügyfélportál</a>
            </div>
        </div>
        <img src="<?= h(asset_url('img/document-workflow.png')); ?>" alt="Dokumentumfeltöltés mérőhelyi ügyintézéshez">
    </div>
</section>

<section class="feature-band feature-band-alt">
    <div class="container feature-layout reverse">
        <div class="feature-copy">
            <p class="eyebrow">Generálkivitelezőknek</p>
            <h2>Külön ügyfél, külön munka, külön dokumentumcsomag</h2>
            <p>
                A generálkivitelező saját ügyfeleihez új munkákat tud felvinni, külön ügyféladatokkal,
                munkaadatokkal, fotókkal és dokumentumokkal.
            </p>
            <div class="feature-actions">
                <a class="button" href="<?= h(url_path('/index.php?route=contractor/register')); ?>">Generálkivitelező regisztráció</a>
                <a class="button button-secondary" href="<?= h(url_path('/login')); ?>">Belépés</a>
            </div>
        </div>
        <img src="<?= h(asset_url('img/contractor-workflow.png')); ?>" alt="Generálkivitelező munkaigény rögzítése">
    </div>
</section>
