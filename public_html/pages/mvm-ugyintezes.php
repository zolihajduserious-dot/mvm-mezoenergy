<?php
declare(strict_types=1);

$primaryCtaUrl = '/index.php?route=register';
$primaryCtaLabel = 'Ügyfélként indítom';
$electricianCtaUrl = '/electrician/register';
$electricianCtaLabel = 'Villanyszerelőként regisztrálok';
$electricianSecondaryUrl = '/electrician/login';
$electricianSecondaryLabel = 'Szerelői belépés';
$adminPreviewUrl = is_logged_in() && is_staff_user() ? '/admin/customer-work-request-preview' : '';

if (is_logged_in()) {
    if (is_staff_user()) {
        $primaryCtaUrl = '/admin/connection-requests/edit';
        $primaryCtaLabel = 'Admin rögzítés indítása';
    } elseif (is_general_contractor_user()) {
        $primaryCtaUrl = '/contractor/work-request';
        $primaryCtaLabel = 'Ügyfél + igény rögzítése';
    } elseif (is_electrician_user()) {
        $primaryCtaUrl = '/electrician/work-request';
        $primaryCtaLabel = 'Szerelői adatlap indítása';
        $electricianCtaUrl = '/electrician/work-request';
        $electricianCtaLabel = 'MVM adatlap előkészítése';
        $electricianSecondaryUrl = '/electrician/work-requests';
        $electricianSecondaryLabel = 'Szerelői munkáim';
    } else {
        $primaryCtaUrl = '/customer/work-request';
        $primaryCtaLabel = 'Igény előkészítése';
    }
}

$processSteps = [
    [
        'number' => '01',
        'title' => 'Az ügyfél rögzíti, amit biztosan tud',
        'text' => 'Név, elérhetőség, felhasználási hely, számlán látható azonosítók, meglévő fotók és dokumentumok.',
    ],
    [
        'number' => '02',
        'title' => 'A hiányzó adatok külön kezelhetők',
        'text' => 'A műszaki, csatlakozási és dokumentációs kérdések nem akadályozzák az indulást, ezeket a Mező Energy pontosítja.',
    ],
    [
        'number' => '03',
        'title' => 'A szakmai részt a Mező Energy pótolja',
        'text' => 'Teljesítményadatok, csatlakozási mód, fotóellenőrzés, helyszínrajz és MVM-kompatibilis dokumentumcsomag.',
    ],
    [
        'number' => '04',
        'title' => 'Meghatalmazással indul az MVM beadás',
        'text' => 'A végleges adatokat munkatárs vagy regisztrált villanyszerelői partner rögzíti az MVM felületén.',
    ],
];

$documentItems = [
    ['title' => 'Tulajdoni lap', 'text' => 'Ha rendelkezésre áll, feltölthető. Ha nincs, az ügyintéző jelzi a pótlást.'],
    ['title' => 'Térképmásolat', 'text' => 'A helyszínrajz és csatlakozási pont előkészítésének alapja.'],
    ['title' => 'Fényképek', 'text' => 'Mérőhely, utcafront, villanyoszlop, csatlakozási pont, tervezett nyomvonal.'],
    ['title' => 'Helyszínrajz', 'text' => 'Nem az ügyfél készíti el nulláról, hanem a feltöltött anyagokból állítjuk össze vagy ellenőrizzük.'],
    ['title' => 'Meghatalmazás', 'text' => 'A beadáshoz szükséges, előtöltött és később aláírható dokumentum.'],
    ['title' => 'Hozzájáruló nyilatkozat', 'text' => 'Akkor kell, ha az igénylő nem kizárólagos tulajdonos vagy más jogcímen jár el.'],
];

$audiences = [
    [
        'title' => 'Lakossági és céges ügyfelek',
        'text' => 'Egyszerű adatbekérés, fotófeltöltés és dokumentumfeltöltés azoknak, akik nem tudják pontosan, mit kér az MVM felülete.',
    ],
    [
        'title' => 'Regisztrált villanyszerelők',
        'text' => 'Kelet-magyarországi, MVM-es ügyek előkészítésére használható partneri munkafelület. Az előfizetéses jogosultság külön fejlesztési lépés lesz.',
    ],
    [
        'title' => 'Mező Energy ügyintézők',
        'text' => 'Belső ellenőrzés, hiánypótlás, helyszínrajz-előkészítés és MVM-beadás támogatása egységes adatcsomagból.',
    ],
];
?>
<section class="mvm-service-hero">
    <div class="mvm-service-hero-media" aria-hidden="true"></div>
    <div class="container mvm-service-hero-inner">
        <div class="mvm-service-copy">
            <p class="eyebrow">MVM ügyintézés előkészítése</p>
            <h1>Az ügyfél csak azt adja meg, amit biztosan tud.</h1>
            <p>
                Teljesítménybővítés, fázisbővítés, H tarifa, mérőhely-áthelyezés vagy csatlakozási mód váltás előtt
                az MVM sok műszaki adatot és dokumentumot kér. A felület célja, hogy az ügyfél vagy a regisztrált
                villanyszerelő rögzítse az alapadatokat, a szakmai hiányokat pedig a Mező Energy pótolja.
            </p>
            <div class="hero-actions">
                <a class="button" href="<?= h(url_path($primaryCtaUrl)); ?>"><?= h($primaryCtaLabel); ?></a>
                <a class="button button-secondary" href="<?= h(url_path($electricianCtaUrl)); ?>"><?= h($electricianCtaLabel); ?></a>
                <a class="button button-light" href="<?= h(url_path($electricianSecondaryUrl)); ?>"><?= h($electricianSecondaryLabel); ?></a>
                <?php if ($adminPreviewUrl !== ''): ?>
                    <a class="button button-light" href="<?= h(url_path($adminPreviewUrl)); ?>">Ügyfélűrlap előnézet</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<section class="mvm-service-notice">
    <div class="container">
        <p>
            A Mező Energy nem az MVM hivatalos ügyfélkapuja. A szolgáltatás az igénybejelentés előkészítését,
            dokumentálását, helyszínrajzi támogatását és meghatalmazás alapján történő ügyintézését segíti.
        </p>
    </div>
</section>

<section class="mvm-service-section">
    <div class="container mvm-service-split">
        <div class="mvm-service-section-copy">
            <p class="eyebrow">Kelet-Magyarország</p>
            <h2>Kiterjeszthető MVM-es területi ügyintézésre</h2>
            <p>
                Az oldal úgy készül, hogy ne csak egy helyi ügyfélfolyamat legyen. A Magyarország keleti részén dolgozó,
                regisztrált villanyszerelők később előfizetéssel használhatják az adatbekérő és előkészítő rendszert.
                Az előfizetési jogosultságot nem most építjük be, de a kommunikáció és a belépési pont már ezt a bővíthetőséget támogatja.
            </p>
        </div>
        <div class="mvm-service-audience-grid">
            <?php foreach ($audiences as $audience): ?>
                <article class="mvm-service-mini-card">
                    <h3><?= h($audience['title']); ?></h3>
                    <p><?= h($audience['text']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="mvm-service-section mvm-service-section-alt">
    <div class="container">
        <div class="mvm-service-section-head">
            <p class="eyebrow">Működési folyamat</p>
            <h2>Nem új ügyintézési logika, hanem ügyfélbarát előszűrés</h2>
        </div>
        <div class="mvm-process-grid">
            <?php foreach ($processSteps as $step): ?>
                <article class="mvm-process-card">
                    <span><?= h($step['number']); ?></span>
                    <h3><?= h($step['title']); ?></h3>
                    <p><?= h($step['text']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="mvm-service-section">
    <div class="container mvm-service-document-layout">
        <div class="mvm-service-section-copy">
            <p class="eyebrow">Helyszínrajz és dokumentumok</p>
            <h2>A nehéz részeket nem az ügyfélnek kell kitalálnia</h2>
            <p>
                A helyszínrajzhoz és a műszaki kitöltéshez az ügyféltől fotókat és alapadatokat kérünk.
                Amit nem tud biztosan, azt piszkozatként mentheti vagy megjegyzésben jelezheti, a szakmai pontosítást mi végezzük el.
            </p>
            <div class="form-actions">
                <a class="button" href="<?= h(url_path($primaryCtaUrl)); ?>">Adatbekérés indítása</a>
                <a class="button button-secondary" href="<?= h(url_path('/documents')); ?>">Dokumentumtár</a>
            </div>
        </div>
        <div class="mvm-document-grid">
            <?php foreach ($documentItems as $item): ?>
                <article class="mvm-document-item">
                    <h3><?= h($item['title']); ?></h3>
                    <p><?= h($item['text']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="mvm-service-section mvm-electrician-band">
    <div class="container mvm-electrician-panel">
        <div>
            <p class="eyebrow">Villanyszerelői partnereknek</p>
            <h2>Előkészített út az előfizetéses használathoz</h2>
            <p>
                A regisztrált villanyszerelő saját ügyfeleihez tud majd adatlapot indítani, fotókat és dokumentumokat
                feltölteni, a Mező Energy pedig a MVM-beadásra alkalmas szakmai adatcsomagot készíti elő.
            </p>
        </div>
        <div class="mvm-electrician-actions">
            <a class="button" href="<?= h(url_path($electricianCtaUrl)); ?>"><?= h($electricianCtaLabel); ?></a>
            <a class="button button-secondary" href="<?= h(url_path($electricianSecondaryUrl)); ?>"><?= h($electricianSecondaryLabel); ?></a>
        </div>
    </div>
</section>
