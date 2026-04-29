<?php
declare(strict_types=1);

http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);
$logFile = $root . '/private_logs/php-error.log';
$bootstrapLogFile = $root . '/private_logs/bootstrap-error.log';

echo "Az alkalmazas 500-as hibara futott.\n\n";

foreach ([$bootstrapLogFile, $logFile] as $path) {
    if (!is_file($path)) {
        continue;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (!is_array($lines) || $lines === []) {
        continue;
    }

    echo "Napló: " . basename($path) . "\n";
    echo (string) end($lines) . "\n";
    exit;
}

echo "Nem talaltam friss PHP hibanaplo bejegyzest.\n";
echo "Kerlek nyisd meg a Nethely bal oldali menu: Weboldalak / Forgalmi es hibanaplo reszt.\n";
