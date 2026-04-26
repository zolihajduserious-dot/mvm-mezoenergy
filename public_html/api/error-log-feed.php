<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$projectRoot = dirname(__DIR__, 2);
$errorMonitorPath = $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'error-monitor.php';
if (is_file($errorMonitorPath)) {
    require_once $errorMonitorPath;
}

$logFile = $projectRoot . DIRECTORY_SEPARATOR . 'private_logs' . DIRECTORY_SEPARATOR . 'php-error.log';
$tokenFile = $projectRoot . DIRECTORY_SEPARATOR . 'private_logs' . DIRECTORY_SEPARATOR . 'dashboard-feed-token.txt';

$expectedToken = read_feed_token($tokenFile);
$requestToken = read_request_token();

if ($expectedToken === '' || $requestToken === '' || !hash_equals($expectedToken, $requestToken)) {
    json_response(403, [
        'ok' => false,
        'error' => 'Forbidden',
    ]);
}

$lines = read_last_log_lines($logFile, 100);
$entries = [];

foreach ($lines as $line) {
    $entry = json_decode($line, true);
    if (is_array($entry)) {
        $entries[] = $entry;
        continue;
    }

    $entries[] = [
        'raw' => $line,
    ];
}

json_response(200, [
    'ok' => true,
    'source' => 'MVM',
    'count' => count($entries),
    'entries' => $entries,
]);

function read_feed_token(string $tokenFile): string
{
    if (!is_file($tokenFile) || !is_readable($tokenFile)) {
        return '';
    }

    $token = file_get_contents($tokenFile);
    if ($token === false) {
        return '';
    }

    return normalize_token($token);
}

function read_request_token(): string
{
    $token = $_GET['token'] ?? '';
    if (!is_string($token)) {
        return '';
    }

    return normalize_token($token);
}

function normalize_token(string $token): string
{
    return trim(preg_replace('/^\xEF\xBB\xBF/', '', $token) ?? $token);
}

function read_last_log_lines(string $logFile, int $limit): array
{
    if ($limit < 1 || !is_file($logFile) || !is_readable($logFile)) {
        return [];
    }

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    return array_slice($lines, -$limit);
}

function json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
