<?php
declare(strict_types=1);

$errorMonitorPath = dirname(__DIR__, 2) . '/includes/error-monitor.php';

if (is_file($errorMonitorPath)) {
    require_once $errorMonitorPath;
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_role(['admin']);

$logFile = APP_ROOT . '/private_logs/php-error.log';
$errors = [];

if (is_file($logFile) && is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (is_array($lines)) {
        for ($index = count($lines) - 1; $index >= 0 && count($errors) < 100; $index--) {
            $decoded = json_decode($lines[$index], true);

            if (!is_array($decoded)) {
                continue;
            }

            $errors[] = [
                'datetime' => (string) ($decoded['datetime'] ?? ''),
                'type' => strtoupper((string) ($decoded['type'] ?? '')),
                'message' => (string) ($decoded['message'] ?? ''),
                'file' => (string) ($decoded['file'] ?? ''),
                'line' => (string) ($decoded['line'] ?? ''),
                'url' => (string) ($decoded['url'] ?? ''),
            ];
        }
    }
}

function mvm_error_dashboard_type_class(string $type): string
{
    return match ($type) {
        'ERROR', 'FATAL' => 'type-error',
        'WARNING' => 'type-warning',
        default => 'type-default',
    };
}
?>
<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>MVM hibamonitor</title>
    <style>
        :root {
            color-scheme: light;
            --background: #f6f7f9;
            --border: #d9dee7;
            --ink: #182033;
            --muted: #657084;
            --panel: #ffffff;
            --error: #b42318;
            --error-bg: #fee4e2;
            --warning: #b54708;
            --warning-bg: #ffead5;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--ink);
            font-family: Arial, Helvetica, sans-serif;
            font-size: 15px;
            line-height: 1.45;
        }

        .page {
            width: min(1400px, calc(100% - 32px));
            margin: 32px auto;
        }

        .header {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
        }

        h1 {
            margin: 0 0 4px;
            font-size: 28px;
            line-height: 1.2;
        }

        .meta {
            margin: 0;
            color: var(--muted);
        }

        .panel {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel);
            box-shadow: 0 16px 36px rgba(24, 32, 51, 0.08);
        }

        .empty {
            padding: 28px;
            color: var(--muted);
            font-weight: 700;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 980px;
        }

        th,
        td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #eef1f5;
            color: #344054;
            font-size: 12px;
            letter-spacing: 0;
            text-transform: uppercase;
            white-space: nowrap;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .date,
        .line {
            white-space: nowrap;
        }

        .message,
        .file,
        .url {
            max-width: 360px;
            overflow-wrap: anywhere;
        }

        .badge {
            display: inline-block;
            min-width: 82px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-align: center;
        }

        .type-error {
            background: var(--error-bg);
            color: var(--error);
        }

        .type-warning {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .type-default {
            background: #e4e7ec;
            color: #344054;
        }

        @media (max-width: 720px) {
            .page {
                width: min(100% - 20px, 1400px);
                margin: 20px auto;
            }

            .header {
                align-items: start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="header">
            <div>
                <h1>MVM hibamonitor</h1>
                <p class="meta">Legfeljebb 100 legfrissebb bejegyzés a PHP hibanaplóból.</p>
            </div>
            <p class="meta"><?= h(date('Y-m-d H:i:s')); ?></p>
        </header>

        <section class="panel" aria-label="Hibalista">
            <?php if ($errors === []): ?>
                <div class="empty">Nincs hiba</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Dátum</th>
                                <th>Típus</th>
                                <th>Message</th>
                                <th>Fájl</th>
                                <th>Sor</th>
                                <th>URL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($errors as $error): ?>
                                <tr>
                                    <td class="date"><?= h($error['datetime']); ?></td>
                                    <td>
                                        <span class="badge <?= h(mvm_error_dashboard_type_class($error['type'])); ?>">
                                            <?= h($error['type']); ?>
                                        </span>
                                    </td>
                                    <td class="message"><?= h($error['message']); ?></td>
                                    <td class="file"><?= h($error['file']); ?></td>
                                    <td class="line"><?= h($error['line']); ?></td>
                                    <td class="url"><?= h($error['url']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
