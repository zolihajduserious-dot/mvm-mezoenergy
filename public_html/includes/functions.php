<?php
declare(strict_types=1);

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function phone_href(string|int|float|null $value): string
{
    $phone = trim((string) $value);

    if ($phone === '') {
        return '';
    }

    $phone = preg_replace('/[^\d+]+/', '', $phone) ?? '';

    if ($phone === '') {
        return '';
    }

    if (str_starts_with($phone, '00')) {
        $phone = '+' . substr($phone, 2);
    }

    if (str_starts_with($phone, '+')) {
        $phone = '+' . preg_replace('/\D+/', '', substr($phone, 1));
    } else {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';
    }

    return $phone !== '' && $phone !== '+' ? 'tel:' . $phone : '';
}

function phone_link_html(string|int|float|null $value, string $emptyLabel = '-'): string
{
    $label = trim((string) $value);

    if ($label === '') {
        return h($emptyLabel);
    }

    $href = phone_href($label);

    if ($href === '') {
        return h($label);
    }

    return '<a class="phone-link" href="' . h($href) . '">' . h($label) . '</a>';
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', ' ') . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' KB';
    }

    return $bytes . ' B';
}

function current_route(): string
{
    $queryRoute = $_GET['route'] ?? null;

    if (is_string($queryRoute) && trim($queryRoute, '/') !== '') {
        return strtolower(trim($queryRoute, '/'));
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($path, $scriptDir)) {
        $path = substr($path, strlen($scriptDir));
    }

    $path = trim($path, '/');

    if ($path === 'index.php') {
        return '';
    }

    if (str_starts_with($path, 'index.php/')) {
        $path = substr($path, strlen('index.php/'));
    }

    return strtolower($path);
}

function url_path(string $path = '/'): string
{
    $path = '/' . ltrim($path, '/');

    return $path === '//' ? '/' : $path;
}

function asset_url(string $path): string
{
    $assetPath = ltrim($path, '/');
    $url = url_path('/assets/' . $assetPath);
    $fullPath = PUBLIC_ROOT . '/assets/' . $assetPath;

    if (is_file($fullPath)) {
        $url .= '?v=' . filemtime($fullPath);
    }

    return $url;
}

function absolute_url(string $path = '/'): string
{
    $scheme = 'http';

    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ) {
        $scheme = 'https';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'mezoenergy24.nhely.hu');

    return $scheme . '://' . $host . url_path($path);
}

function redirect(string $path): never
{
    header('Location: ' . url_path($path), true, 302);
    exit;
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function app_config(string $key, mixed $default = null): mixed
{
    $config = [
        'name' => APP_NAME,
        'env' => APP_ENV,
        'debug' => APP_DEBUG,
    ];

    return $config[$key] ?? $default;
}
