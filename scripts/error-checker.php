<?php
declare(strict_types=1);

const MVM_ERROR_CHECKER_REPO_ISSUES_URL = 'https://api.github.com/repos/zolihajduserious-dot/mvm-mezoenergy/issues';
const MVM_ERROR_CHECKER_LABELS = ['bug', 'auto-error-monitor'];
const MVM_ERROR_CHECKER_ADMIN_EMAIL = 'admin@example.com';
const MVM_ERROR_CHECKER_MAX_HASHES = 500;

$projectRoot = dirname(__DIR__);
$privateLogDir = $projectRoot . DIRECTORY_SEPARATOR . 'private_logs';
$errorLogFile = $privateLogDir . DIRECTORY_SEPARATOR . 'php-error.log';
$checkerLogFile = $privateLogDir . DIRECTORY_SEPARATOR . 'error-checker.log';
$stateFile = $privateLogDir . DIRECTORY_SEPARATOR . 'error-checker.state';
$tokenFile = $privateLogDir . DIRECTORY_SEPARATOR . 'github-issue-token.txt';

if (!is_dir($privateLogDir)) {
    @mkdir($privateLogDir, 0750, true);
}

if (!is_file($errorLogFile)) {
    mvm_error_checker_log($checkerLogFile, 'No php-error.log found.');
    exit(0);
}

$state = mvm_error_checker_read_state($stateFile);
$entries = mvm_error_checker_read_new_entries($errorLogFile, $state);
$token = mvm_error_checker_github_token($tokenFile, $checkerLogFile);
$adminEmail = mvm_error_checker_admin_email();

foreach ($entries as $item) {
    $entry = $item['entry'];
    $rawLine = $item['raw'];
    $type = strtoupper((string) ($entry['type'] ?? ''));

    if (!in_array($type, ['ERROR', 'WARNING', 'FATAL'], true)) {
        continue;
    }

    $hash = mvm_error_checker_hash($entry);

    if (isset($state['hashes'][$hash])) {
        continue;
    }

    $title = mvm_error_checker_issue_title($entry);
    $body = mvm_error_checker_issue_body($entry, $rawLine);

    mvm_error_checker_send_email($checkerLogFile, $adminEmail, $title, $body);

    if ($token === '') {
        mvm_error_checker_log($checkerLogFile, 'GitHub issue skipped: missing token');
    } else {
        mvm_error_checker_create_github_issue($checkerLogFile, $token, $title, $body);
    }

    $state['hashes'][$hash] = gmdate(DATE_ATOM);
    $state['hashes'] = mvm_error_checker_trim_hashes($state['hashes']);
}

mvm_error_checker_write_state($stateFile, $state);

function mvm_error_checker_log(string $logFile, string $message): void
{
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function mvm_error_checker_read_state(string $stateFile): array
{
    $default = [
        'offset' => 0,
        'hashes' => [],
    ];

    if (!is_file($stateFile)) {
        return $default;
    }

    $contents = @file_get_contents($stateFile);
    $decoded = is_string($contents) ? json_decode($contents, true) : null;

    if (!is_array($decoded)) {
        return $default;
    }

    return [
        'offset' => max(0, (int) ($decoded['offset'] ?? 0)),
        'hashes' => is_array($decoded['hashes'] ?? null) ? $decoded['hashes'] : [],
    ];
}

function mvm_error_checker_write_state(string $stateFile, array $state): void
{
    $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return;
    }

    $temporaryFile = $stateFile . '.tmp';
    @file_put_contents($temporaryFile, $payload . PHP_EOL, LOCK_EX);
    @rename($temporaryFile, $stateFile);
}

function mvm_error_checker_read_new_entries(string $errorLogFile, array &$state): array
{
    $size = (int) (filesize($errorLogFile) ?: 0);

    if ($state['offset'] > $size) {
        $state['offset'] = 0;
    }

    $handle = @fopen($errorLogFile, 'rb');

    if ($handle === false) {
        return [];
    }

    if ($state['offset'] > 0) {
        fseek($handle, $state['offset']);
    }

    $entries = [];

    while (($line = fgets($handle)) !== false) {
        $rawLine = trim($line);

        if ($rawLine === '') {
            continue;
        }

        $decoded = json_decode($rawLine, true);

        if (!is_array($decoded)) {
            continue;
        }

        $entries[] = [
            'entry' => $decoded,
            'raw' => $rawLine,
        ];
    }

    $position = ftell($handle);
    $state['offset'] = is_int($position) ? $position : $size;
    fclose($handle);

    return $entries;
}

function mvm_error_checker_hash(array $entry): string
{
    $parts = [
        (string) ($entry['type'] ?? ''),
        (string) ($entry['message'] ?? ''),
        (string) ($entry['file'] ?? ''),
        (string) ($entry['line'] ?? ''),
        (string) ($entry['url'] ?? ''),
    ];

    return hash('sha256', implode('|', $parts));
}

function mvm_error_checker_trim_hashes(array $hashes): array
{
    if (count($hashes) <= MVM_ERROR_CHECKER_MAX_HASHES) {
        return $hashes;
    }

    return array_slice($hashes, -MVM_ERROR_CHECKER_MAX_HASHES, null, true);
}

function mvm_error_checker_admin_email(): string
{
    $email = getenv('ERROR_CHECKER_ADMIN_EMAIL');

    if (is_string($email) && trim($email) !== '') {
        return trim($email);
    }

    return MVM_ERROR_CHECKER_ADMIN_EMAIL;
}

function mvm_error_checker_github_token(string $tokenFile, string $checkerLogFile): string
{
    $token = getenv('GITHUB_ISSUE_TOKEN');

    if (is_string($token) && trim($token) !== '') {
        return trim($token);
    }

    if (!is_file($tokenFile)) {
        return '';
    }

    if (!is_readable($tokenFile)) {
        mvm_error_checker_log($checkerLogFile, 'GitHub issue token file skipped: not readable');
        return '';
    }

    $fileToken = @file_get_contents($tokenFile);

    return is_string($fileToken) ? trim($fileToken) : '';
}

function mvm_error_checker_issue_title(array $entry): string
{
    $type = strtoupper((string) ($entry['type'] ?? 'ERROR'));
    $file = (string) ($entry['file'] ?? '');
    $line = (string) ($entry['line'] ?? '0');

    return '[MVM hiba] ' . $type . ' - ' . $file . ':' . $line;
}

function mvm_error_checker_issue_body(array $entry, string $rawLine): string
{
    $lines = [
        '## Hiba adatai',
        '- Dátum/idő: ' . (string) ($entry['datetime'] ?? ''),
        '- Hibatípus: ' . strtoupper((string) ($entry['type'] ?? '')),
        '- Üzenet: ' . (string) ($entry['message'] ?? ''),
        '- Fájl: ' . (string) ($entry['file'] ?? ''),
        '- Sor: ' . (string) ($entry['line'] ?? ''),
        '- URL: ' . (string) ($entry['url'] ?? ''),
        '- IP: ' . (string) ($entry['ip'] ?? ''),
        '',
        '## Kapcsolódó log részlet',
        '```json',
        $rawLine,
        '```',
        '',
        '## Javaslat',
        'Codex ellenőrizze a fájlt és készítsen javítási javaslatot.',
    ];

    return implode(PHP_EOL, $lines);
}

function mvm_error_checker_send_email(string $checkerLogFile, string $adminEmail, string $subject, string $body): void
{
    $headers = implode("\r\n", [
        'From: MVM Error Monitor <no-reply@mezoenergy.local>',
        'Content-Type: text/plain; charset=UTF-8',
    ]);

    $sent = @mail($adminEmail, $subject, $body, $headers);

    if (!$sent) {
        mvm_error_checker_log($checkerLogFile, 'Email send failed for ' . $adminEmail);
    }
}

function mvm_error_checker_create_github_issue(string $checkerLogFile, string $token, string $title, string $body): void
{
    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'labels' => MVM_ERROR_CHECKER_LABELS,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        mvm_error_checker_log($checkerLogFile, 'GitHub issue skipped: payload encoding failed');
        return;
    }

    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'User-Agent: MVM-Mezoenergy-Error-Checker',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init(MVM_ERROR_CHECKER_REPO_ISSUES_URL);

        if ($ch === false) {
            mvm_error_checker_log($checkerLogFile, 'GitHub issue skipped: cURL init failed');
            return;
        }

        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ];

        $caBundle = mvm_error_checker_find_ca_bundle($checkerLogFile);

        if ($caBundle !== '') {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            mvm_error_checker_log(
                $checkerLogFile,
                mvm_error_checker_github_failure_message(
                    $statusCode,
                    $error,
                    is_string($response) ? $response : null
                )
            );
            return;
        }

        mvm_error_checker_log_github_success($checkerLogFile, is_string($response) ? $response : '');
        return;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $response = @file_get_contents(MVM_ERROR_CHECKER_REPO_ISSUES_URL, false, $context);
    $statusCode = 0;

    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches) === 1) {
        $statusCode = (int) $matches[1];
    }

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        mvm_error_checker_log(
            $checkerLogFile,
            mvm_error_checker_github_failure_message(
                $statusCode,
                '',
                is_string($response) ? $response : null
            )
        );
        return;
    }

    mvm_error_checker_log_github_success($checkerLogFile, is_string($response) ? $response : '');
}

function mvm_error_checker_github_failure_message(int $statusCode, string $transportError, ?string $responseBody): string
{
    $message = 'GitHub issue create failed: HTTP ' . $statusCode;

    if ($transportError !== '') {
        $message .= ' - ' . $transportError;
    }

    $message .= '; response: ';
    $message .= $responseBody !== null && $responseBody !== '' ? $responseBody : '(empty)';

    return $message;
}

function mvm_error_checker_log_github_success(string $checkerLogFile, string $responseBody): void
{
    $decoded = json_decode($responseBody, true);
    $issueNumber = is_array($decoded) && isset($decoded['number']) ? (string) $decoded['number'] : '-';
    $issueUrl = is_array($decoded) && isset($decoded['html_url']) ? (string) $decoded['html_url'] : '-';

    mvm_error_checker_log($checkerLogFile, 'GitHub issue created: #' . $issueNumber . ' ' . $issueUrl);
}

function mvm_error_checker_find_ca_bundle(string $checkerLogFile): string
{
    $candidates = [
        getenv('GITHUB_ISSUE_CAINFO'),
        getenv('CURL_CA_BUNDLE'),
        getenv('SSL_CERT_FILE'),
        dirname($checkerLogFile) . DIRECTORY_SEPARATOR . 'cacert.pem',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cacert.pem',
        'C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt',
        'C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt',
        'C:\Program Files\Git\usr\ssl\cert.pem',
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $candidate = trim($candidate);

        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    mvm_error_checker_log($checkerLogFile, 'GitHub issue CA bundle not found; using PHP cURL defaults');

    return '';
}
