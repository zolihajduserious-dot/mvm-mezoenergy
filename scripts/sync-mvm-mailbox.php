<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$publicRoot = $projectRoot . '/public_html';

require_once $publicRoot . '/includes/config.php';

$vendorAutoload = APP_ROOT . '/vendor/autoload.php';

if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

require_once $publicRoot . '/includes/functions.php';
require_once $publicRoot . '/includes/db.php';
require_once $publicRoot . '/includes/crm.php';

$limit = isset($argv[1]) ? max(1, min(500, (int) $argv[1])) : 200;
$result = sync_mvm_mailbox_replies($limit);
$status = ($result['ok'] ?? false) ? 'OK' : 'ERROR';

echo '[' . date('Y-m-d H:i:s') . '] ' . $status . ' - ' . (string) ($result['message'] ?? '') . PHP_EOL;

exit(($result['ok'] ?? false) ? 0 : 1);
