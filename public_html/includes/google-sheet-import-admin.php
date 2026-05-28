<?php
declare(strict_types=1);

function google_sheet_import_admin_webapp_url(): string
{
    return google_sheet_import_admin_config_value('GOOGLE_SHEET_IMPORT_WEBAPP_URL');
}

function google_sheet_import_admin_webapp_token(): string
{
    return google_sheet_import_admin_config_value('GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN');
}

function google_sheet_import_admin_config_value(string $key): string
{
    $environmentValue = getenv($key);

    if ($environmentValue !== false && trim((string) $environmentValue) !== '') {
        return trim((string) $environmentValue);
    }

    foreach (google_sheet_import_admin_local_config_paths() as $path) {
        if (!is_file($path)) {
            continue;
        }

        try {
            $config = require $path;
        } catch (Throwable) {
            continue;
        }

        if (is_array($config) && array_key_exists($key, $config)) {
            return trim((string) $config[$key]);
        }
    }

    return '';
}

function google_sheet_import_admin_local_config_paths(): array
{
    if (!defined('STORAGE_PATH')) {
        return [];
    }

    return [
        STORAGE_PATH . '/config/local.php',
        STORAGE_PATH . '/config/local.secret.php',
    ];
}

function google_sheet_import_admin_is_configured(): bool
{
    return google_sheet_import_admin_webapp_url() !== ''
        && google_sheet_import_admin_webapp_token() !== '';
}

function google_sheet_import_admin_call(string $action, array $extraPayload = []): array
{
    $url = google_sheet_import_admin_webapp_url();
    $token = google_sheet_import_admin_webapp_token();

    if ($url === '' || $token === '') {
        return [
            'ok' => false,
            'http_status' => 0,
            'body' => null,
            'message' => 'A Google Sheet manuális import nincs konfigurálva.',
        ];
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [
            'ok' => false,
            'http_status' => 0,
            'body' => null,
            'message' => 'A Google Sheet webapp URL hibás.',
        ];
    }

    $payload = array_replace($extraPayload, [
        'action' => $action,
        'token' => $token,
    ]);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return [
            'ok' => false,
            'http_status' => 0,
            'body' => null,
            'message' => 'A Google Sheet kérés összeállítása sikertelen.',
        ];
    }

    if (!function_exists('curl_init')) {
        return google_sheet_import_admin_call_with_stream($url, $json, $action);
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $action === 'run-approved' ? 120 : 40,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $json,
    ]);

    $rawBody = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($rawBody === false || $curlError !== '') {
        return [
            'ok' => false,
            'http_status' => $statusCode,
            'body' => null,
            'message' => 'A Google Sheet webapp hívása sikertelen.',
        ];
    }

    return google_sheet_import_admin_parse_response((string) $rawBody, $statusCode);
}

function google_sheet_import_admin_call_with_stream(string $url, string $json, string $action): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n",
            'content' => $json,
            'timeout' => $action === 'run-approved' ? 120 : 40,
            'ignore_errors' => true,
        ],
    ]);

    $rawBody = @file_get_contents($url, false, $context);
    $statusCode = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $header, $matches)) {
            $statusCode = (int) $matches[1];
            break;
        }
    }

    if ($rawBody === false) {
        return [
            'ok' => false,
            'http_status' => $statusCode,
            'body' => null,
            'message' => 'A Google Sheet webapp hívása sikertelen.',
        ];
    }

    return google_sheet_import_admin_parse_response((string) $rawBody, $statusCode);
}

function google_sheet_import_admin_parse_response(string $rawBody, int $statusCode): array
{
    $decoded = json_decode($rawBody, true);

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'http_status' => $statusCode,
            'body' => null,
            'message' => 'A Google Sheet webapp nem JSON választ adott.',
        ];
    }

    $bodyOk = !array_key_exists('ok', $decoded) || (bool) $decoded['ok'];
    $httpOk = $statusCode === 0 || ($statusCode >= 200 && $statusCode < 300);

    return [
        'ok' => $httpOk && $bodyOk,
        'http_status' => $statusCode,
        'body' => $decoded,
        'message' => google_sheet_import_admin_response_message($decoded, $statusCode),
    ];
}

function google_sheet_import_admin_response_message(array $body, int $statusCode): string
{
    if (!empty($body['error'])) {
        return (string) $body['error'];
    }

    $action = (string) ($body['action'] ?? '');

    if ($action === 'preview') {
        return 'Google Sheet állapot lekérdezve.';
    }

    if ($action === 'run-approved') {
        return 'Jóváhagyott sorok importja lefutott.';
    }

    if ($action === 'delete-triggers') {
        return 'Automata triggerek törlése lefutott.';
    }

    if ($action === 'health') {
        return 'Google Sheet webapp elérhető.';
    }

    return $statusCode > 0 ? ('HTTP ' . $statusCode) : 'Google Sheet webapp válasz érkezett.';
}

function google_sheet_import_admin_log(string $action, array $result): void
{
    $logDir = APP_ROOT . '/private_logs';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $body = is_array($result['body'] ?? null) ? $result['body'] : [];
    $summary = is_array($body['summary'] ?? null) ? google_sheet_import_admin_compact_summary($body['summary']) : null;
    $user = current_user();

    $entry = [
        'created_at' => date('c'),
        'admin_user_id' => is_array($user) ? (int) ($user['id'] ?? 0) : null,
        'action' => $action,
        'ok' => (bool) ($result['ok'] ?? false),
        'http_status' => (int) ($result['http_status'] ?? 0),
        'response_status' => (string) ($body['status'] ?? ''),
        'summary' => $summary,
        'error' => isset($body['error']) ? (string) $body['error'] : '',
    ];

    @file_put_contents(
        $logDir . '/google-sheet-import-admin.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function google_sheet_import_admin_compact_summary(array $summary): array
{
    $allowedKeys = [
        'totalRows',
        'emptyStatus',
        'importable',
        'success',
        'duplicate',
        'error',
        'notImportedOrRejected',
        'waitingReview',
        'inProgress',
        'otherNotAllowed',
        'limit',
        'processed',
        'imported',
        'duplicated',
        'failed',
        'skipped',
    ];
    $compact = [];

    foreach ($allowedKeys as $key) {
        if (array_key_exists($key, $summary)) {
            $compact[$key] = is_numeric($summary[$key]) ? (int) $summary[$key] : $summary[$key];
        }
    }

    return $compact;
}
