<?php
declare(strict_types=1);

$publicRoot = dirname(__DIR__);

require_once $publicRoot . '/includes/config.php';

$vendorAutoload = APP_ROOT . '/vendor/autoload.php';

if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

require_once $publicRoot . '/includes/functions.php';
require_once $publicRoot . '/includes/db.php';
require_once $publicRoot . '/includes/crm.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    $expectedToken = trim(mvm_config_value('MVM_CRON_TOKEN', ''));
    $providedToken = trim((string) ($_GET['token'] ?? ''));

    if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit(1);
    }
}

$limit = $isCli
    ? (isset($argv[1]) ? (int) $argv[1] : 200)
    : (int) ($_GET['limit'] ?? 200);
$limit = max(1, min(500, $limit));
$result = sync_mvm_mailbox_replies($limit);
$status = ($result['ok'] ?? false) ? 'OK' : 'ERROR';

echo '[' . date('Y-m-d H:i:s') . '] ' . $status . ' - ' . (string) ($result['message'] ?? '') . PHP_EOL;

exit(($result['ok'] ?? false) ? 0 : 1);
