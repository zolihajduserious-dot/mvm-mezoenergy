<?php
declare(strict_types=1);

if (defined('MVM_ERROR_MONITOR_LOADED')) {
    return;
}

define('MVM_ERROR_MONITOR_LOADED', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function mvm_error_monitor_log_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_logs' . DIRECTORY_SEPARATOR . 'php-error.log';
}

function mvm_error_monitor_current_url(): string
{
    if (PHP_SAPI === 'cli') {
        return 'cli';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

    if ($host === '') {
        return $uri;
    }

    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $scheme = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host . $uri;
}

function mvm_error_monitor_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function mvm_error_monitor_error_type(int $severity, bool $fatalContext = false): ?string
{
    if ($fatalContext && in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return 'FATAL';
    }

    if (in_array($severity, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true)) {
        return 'WARNING';
    }

    if (in_array($severity, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        return 'ERROR';
    }

    return null;
}

function mvm_error_monitor_write(string $type, string $message, string $file, int $line): void
{
    $logFile = mvm_error_monitor_log_path();
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }

    $entry = [
        'datetime' => date('c'),
        'type' => $type,
        'message' => $message,
        'file' => $file,
        'line' => $line,
        'url' => mvm_error_monitor_current_url(),
        'ip' => mvm_error_monitor_client_ip(),
    ];

    $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    if ($json === false) {
        $json = '{"datetime":"' . date('c') . '","type":"ERROR","message":"Failed to encode error log entry","file":"","line":0,"url":"","ip":""}';
    }

    @file_put_contents($logFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    $type = mvm_error_monitor_error_type($severity);

    if ($type !== null) {
        mvm_error_monitor_write($type, $message, $file, $line);
    }

    return false;
});

set_exception_handler(static function (Throwable $exception): void {
    mvm_error_monitor_write(
        'ERROR',
        'Uncaught ' . get_class($exception) . ': ' . $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
    }
});

register_shutdown_function(static function (): void {
    $lastError = error_get_last();

    if (!is_array($lastError)) {
        return;
    }

    $type = mvm_error_monitor_error_type((int) ($lastError['type'] ?? 0), true);

    if ($type === null) {
        return;
    }

    mvm_error_monitor_write(
        $type,
        (string) ($lastError['message'] ?? ''),
        (string) ($lastError['file'] ?? ''),
        (int) ($lastError['line'] ?? 0)
    );
});
