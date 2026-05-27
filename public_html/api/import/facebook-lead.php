<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $publicRoot = dirname(__DIR__, 2);
    $projectRoot = dirname($publicRoot);
    $errorMonitorPath = $projectRoot . '/includes/error-monitor.php';

    if (is_file($errorMonitorPath)) {
        require_once $errorMonitorPath;
    }

    require_once $publicRoot . '/includes/config.php';

    $vendorAutoload = APP_ROOT . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
    }

    require_once PUBLIC_ROOT . '/includes/functions.php';
    require_once PUBLIC_ROOT . '/includes/db.php';
    require_once PUBLIC_ROOT . '/includes/auth.php';
    require_once PUBLIC_ROOT . '/includes/crm.php';
}

require_once PUBLIC_ROOT . '/includes/lead-import.php';

lead_import_handle_facebook_lead_request();
