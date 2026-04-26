<?php
declare(strict_types=1);

$errorMonitorPath = dirname(__DIR__) . '/includes/error-monitor.php';

if (is_file($errorMonitorPath)) {
    require_once $errorMonitorPath;
}

require_once __DIR__ . '/includes/config.php';

$vendorAutoload = APP_ROOT . '/vendor/autoload.php';

if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crm.php';

$routes = [
    '' => [
        'title' => 'Mező Energy Kft.',
        'file' => PAGE_PATH . '/home.php',
    ],
    'home' => [
        'title' => 'Mező Energy Kft.',
        'file' => PAGE_PATH . '/home.php',
    ],
    'register' => [
        'title' => 'Regisztracio',
        'file' => PAGE_PATH . '/register.php',
    ],
    'contractor/register' => [
        'title' => 'Generálkivitelező regisztráció',
        'file' => PAGE_PATH . '/contractor/register.php',
    ],
    'electrician/register' => [
        'title' => 'Szerelői regisztráció',
        'file' => PAGE_PATH . '/electrician/register.php',
    ],
    'electrician/login' => [
        'title' => 'Szerelői belépés',
        'file' => PAGE_PATH . '/admin/login.php',
    ],
    'login' => [
        'title' => 'Belépés',
        'file' => PAGE_PATH . '/admin/login.php',
    ],
    'forgot-password' => [
        'title' => 'Elfelejtett jelszó',
        'file' => PAGE_PATH . '/forgot-password.php',
    ],
    'reset-password' => [
        'title' => 'Új jelszó beállítása',
        'file' => PAGE_PATH . '/reset-password.php',
    ],
    'profile' => [
        'title' => 'Profil',
        'file' => PAGE_PATH . '/profile.php',
    ],
    'quick-quote' => [
        'title' => 'Gyors árajánlat',
        'file' => PAGE_PATH . '/quick-quote.php',
    ],
    'documents' => [
        'title' => 'Letölthető dokumentumok',
        'file' => PAGE_PATH . '/documents.php',
    ],
    'documents/file' => [
        'title' => 'Dokumentum letöltése',
        'file' => PAGE_PATH . '/document-file.php',
    ],
    'authorization-sign' => [
        'title' => 'Meghatalmazás elektronikus aláírása',
        'file' => PAGE_PATH . '/authorization-sign.php',
    ],
    'quote' => [
        'title' => 'Árajánlat megtekintése',
        'file' => PAGE_PATH . '/quote-public.php',
    ],
    'quote/file' => [
        'title' => 'Árajánlat letöltése',
        'file' => PAGE_PATH . '/quote-public-file.php',
    ],
    'contractor/work-requests' => [
        'title' => 'Generálkivitelezői igények',
        'file' => PAGE_PATH . '/contractor/work-requests.php',
    ],
    'contractor/work-requests/file' => [
        'title' => 'Generálkivitelezői igényhez feltöltött fájl',
        'file' => PAGE_PATH . '/contractor/work-request-file.php',
    ],
    'contractor/work-request' => [
        'title' => 'Generálkivitelezői igény',
        'file' => PAGE_PATH . '/contractor/work-request.php',
    ],
    'contractor/profile' => [
        'title' => 'Generálkivitelezői profil',
        'file' => PAGE_PATH . '/profile.php',
    ],
    'electrician/work-requests' => [
        'title' => 'Szerelői munkák',
        'file' => PAGE_PATH . '/electrician/work-requests.php',
    ],
    'electrician/work-request' => [
        'title' => 'Szerelői munka',
        'file' => PAGE_PATH . '/electrician/work-request.php',
    ],
    'electrician/work-requests/file' => [
        'title' => 'Szerelői munkafájl',
        'file' => PAGE_PATH . '/electrician/work-file.php',
    ],
    'electrician/work-requests/customer-file' => [
        'title' => 'Ügyfél által feltöltött fájl',
        'file' => PAGE_PATH . '/electrician/customer-request-file.php',
    ],
    'electrician/profile' => [
        'title' => 'Szerelői profil',
        'file' => PAGE_PATH . '/profile.php',
    ],
    'customer/profile' => [
        'title' => 'Ugyfeladatok',
        'file' => PAGE_PATH . '/customer/profile.php',
    ],
    'customer/quotes' => [
        'title' => 'Árajánlataim',
        'file' => PAGE_PATH . '/customer/quotes.php',
    ],
    'customer/work-requests' => [
        'title' => 'Igényeim',
        'file' => PAGE_PATH . '/customer/work-requests.php',
    ],
    'customer/work-requests/file' => [
        'title' => 'Igényhez feltöltött fájl',
        'file' => PAGE_PATH . '/customer/work-request-file.php',
    ],
    'customer/work-request' => [
        'title' => 'Mérőhelyi igény',
        'file' => PAGE_PATH . '/customer/work-request.php',
    ],
    'customer/quotes/view' => [
        'title' => 'Árajánlat megtekintése',
        'file' => PAGE_PATH . '/customer/quote-view.php',
    ],
    'customer/quotes/file' => [
        'title' => 'Árajánlat letöltése',
        'file' => PAGE_PATH . '/customer/quote-file.php',
    ],
    'admin/setup' => [
        'title' => 'Admin letrehozasa',
        'file' => PAGE_PATH . '/admin/setup.php',
    ],
    'admin/login' => [
        'title' => 'Admin belepes',
        'file' => PAGE_PATH . '/admin/login.php',
    ],
    'admin/dashboard' => [
        'title' => 'Admin felulet',
        'file' => PAGE_PATH . '/admin/dashboard.php',
    ],
    'admin/profile' => [
        'title' => 'Admin profil',
        'file' => PAGE_PATH . '/profile.php',
    ],
    'admin/customers' => [
        'title' => 'Ugyfelek',
        'file' => PAGE_PATH . '/admin/customers.php',
    ],
    'admin/customers/edit' => [
        'title' => 'Ügyfél szerkesztése',
        'file' => PAGE_PATH . '/admin/customer-form.php',
    ],
    'admin/quotes' => [
        'title' => 'Árajánlatok',
        'file' => PAGE_PATH . '/admin/quotes.php',
    ],
    'admin/connection-requests' => [
        'title' => 'Mérőhelyi igények',
        'file' => PAGE_PATH . '/admin/connection-requests.php',
    ],
    'admin/connection-requests/file' => [
        'title' => 'Mérőhelyi igény fájl',
        'file' => PAGE_PATH . '/admin/connection-request-file.php',
    ],
    'admin/connection-requests/work-file' => [
        'title' => 'Szerelői munkafájl',
        'file' => PAGE_PATH . '/admin/connection-request-work-file.php',
    ],
    'admin/connection-requests/quote-upload' => [
        'title' => 'Árajánlat feltöltése',
        'file' => PAGE_PATH . '/admin/connection-request-quote-upload.php',
    ],
    'admin/connection-requests/mvm-documents' => [
        'title' => 'MVM dokumentumok',
        'file' => PAGE_PATH . '/admin/connection-request-mvm-documents.php',
    ],
    'admin/connection-requests/mvm-file' => [
        'title' => 'MVM dokumentum',
        'file' => PAGE_PATH . '/admin/connection-request-mvm-file.php',
    ],
    'admin/electricians' => [
        'title' => 'Szerelők',
        'file' => PAGE_PATH . '/admin/electricians.php',
    ],
    'admin/users' => [
        'title' => 'Adminisztrátorok',
        'file' => PAGE_PATH . '/admin/users.php',
    ],
    'admin/quotes/create' => [
        'title' => 'Árajánlat készítése',
        'file' => PAGE_PATH . '/admin/quote-form.php',
    ],
    'admin/quotes/edit' => [
        'title' => 'Árajánlat szerkesztése',
        'file' => PAGE_PATH . '/admin/quote-form.php',
    ],
    'admin/quotes/send' => [
        'title' => 'Árajánlat küldése',
        'file' => PAGE_PATH . '/admin/quote-send.php',
    ],
    'admin/quotes/file' => [
        'title' => 'Árajánlat fájl',
        'file' => PAGE_PATH . '/admin/quote-file.php',
    ],
    'admin/quotes/photo' => [
        'title' => 'Fotó',
        'file' => PAGE_PATH . '/admin/quote-photo.php',
    ],
    'admin/price-items' => [
        'title' => 'Árlista',
        'file' => PAGE_PATH . '/admin/price-items.php',
    ],
    'admin/documents' => [
        'title' => 'Dokumentumtár',
        'file' => PAGE_PATH . '/admin/documents.php',
    ],
    'admin/minicrm-export' => [
        'title' => 'MiniCRM export',
        'file' => PAGE_PATH . '/admin/minicrm-export.php',
    ],
    'admin/logout' => [
        'title' => 'Kilépés',
        'file' => PAGE_PATH . '/admin/logout.php',
    ],
    'logout' => [
        'title' => 'Kilépés',
        'file' => PAGE_PATH . '/admin/logout.php',
    ],
];

$route = current_route();
$page = $routes[$route] ?? null;

if ($page === null) {
    http_response_code(404);
    $page = [
        'title' => 'Az oldal nem talalhato',
        'file' => PAGE_PATH . '/404.php',
    ];
}

ob_start();
require $page['file'];
$pageContent = ob_get_clean();
?>
<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Mező Energy Kft. ügyfélintegrációs és árajánlatkészítő webalkalmazás.">
    <title><?= h($page['title']); ?></title>
    <link rel="stylesheet" href="<?= h(asset_url('css/style.css')); ?>">
</head>
<body>
    <header class="site-header">
        <nav class="container nav" aria-label="Fo navigacio">
            <a class="brand" href="<?= h(url_path('/')); ?>">
                <img src="<?= h(asset_url('img/mezo-energy-logo.png')); ?>" alt="" aria-hidden="true">
            </a>
            <button class="nav-toggle" type="button" aria-controls="primary-navigation" aria-expanded="false" aria-label="Menü megnyitása">
                <span class="nav-toggle-line"></span>
                <span class="nav-toggle-line"></span>
                <span class="nav-toggle-line"></span>
            </button>
            <div class="nav-links" id="primary-navigation">
                <a href="<?= h(url_path('/')); ?>">Kezdőlap</a>
                <a href="<?= h(url_path('/documents')); ?>">Dokumentumok</a>
                <?php if (is_logged_in()): ?>
                    <?php if (is_staff_user()): ?>
                        <a href="<?= h(url_path('/admin/dashboard')); ?>">Admin</a>
                        <a href="<?= h(url_path('/quick-quote')); ?>">Gyors ajánlat</a>
                        <a href="<?= h(url_path('/admin/profile')); ?>">Profil</a>
                    <?php elseif (is_general_contractor_user()): ?>
                        <a href="<?= h(url_path('/contractor/work-requests')); ?>">Igények</a>
                        <a href="<?= h(url_path('/contractor/work-request')); ?>">Új igény</a>
                        <a href="<?= h(url_path('/quick-quote')); ?>">Gyors ajánlat</a>
                        <a href="<?= h(url_path('/contractor/profile')); ?>">Profil</a>
                    <?php elseif (is_electrician_user()): ?>
                        <a href="<?= h(url_path('/electrician/work-requests')); ?>">Munkáim</a>
                        <a href="<?= h(url_path('/electrician/work-request')); ?>">Új felmérés</a>
                        <a href="<?= h(url_path('/quick-quote')); ?>">Gyors ajánlat</a>
                        <a href="<?= h(url_path('/electrician/profile')); ?>">Profil</a>
                    <?php else: ?>
                        <a href="<?= h(url_path('/customer/profile')); ?>">Adataim</a>
                        <a href="<?= h(url_path('/customer/work-requests')); ?>">Igényeim</a>
                        <a href="<?= h(url_path('/customer/quotes')); ?>">Árajánlataim</a>
                    <?php endif; ?>
                    <form class="nav-logout" method="post" action="<?= h(url_path('/logout')); ?>">
                        <?= csrf_field(); ?>
                        <button type="submit">Kilépés</button>
                    </form>
                <?php else: ?>
                    <a href="<?= h(url_path('/index.php?route=register')); ?>">Ügyfél regisztráció</a>
                    <a href="<?= h(url_path('/index.php?route=contractor/register')); ?>">Generálkivitelező regisztráció</a>
                    <a href="<?= h(url_path('/electrician/register')); ?>">Szerelői regisztráció</a>
                    <a href="<?= h(url_path('/electrician/login')); ?>">Szerelői belépés</a>
                    <a href="<?= h(url_path('/login')); ?>">Belépés</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main class="site-main">
        <?= $pageContent; ?>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y'); ?> Mező Energy Kft. Minden jog fenntartva.</p>
        </div>
    </footer>
    <script src="<?= h(asset_url('js/menu.js')); ?>" defer></script>
</body>
</html>
