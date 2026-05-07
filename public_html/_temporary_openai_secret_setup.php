<?php
declare(strict_types=1);

$target = dirname(__DIR__) . '/storage/config/local.secret.php';
$config = [];

if (is_file($target)) {
    try {
        $loaded = require $target;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    } catch (Throwable) {
        $config = [];
    }
}

$serverSecret = trim((string) ($config['MVM_IMAP_PASS'] ?? ''));
$expectedAuth = $serverSecret !== ''
    ? hash('sha256', $serverSecret . '|mezoenergy-openai-secret-setup')
    : '';
$postedAuth = trim((string) ($_POST['setup_auth'] ?? ''));

if ($expectedAuth === '' || !hash_equals($expectedAuth, $postedAuth)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

$openAiKey = trim((string) ($_POST['openai_key'] ?? ''));
$model = trim((string) ($_POST['document_prefill_model'] ?? 'gpt-4o-mini'));

if ($openAiKey === '' || !str_starts_with($openAiKey, 'sk-')) {
    http_response_code(422);
    echo "invalid key\n";
    exit;
}

if ($model === '') {
    $model = 'gpt-4o-mini';
}

$config['OPENAI_API_KEY'] = $openAiKey;
$config['DOCUMENT_PREFILL_MODEL'] = $model;

$dir = dirname($target);

if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$contents = "<?php\n"
    . "declare(strict_types=1);\n\n"
    . 'return ' . var_export($config, true) . ";\n";
$tmp = $target . '.tmp';

if (file_put_contents($tmp, $contents, LOCK_EX) === false || !rename($tmp, $target)) {
    @unlink($tmp);
    http_response_code(500);
    echo "write failed\n";
    exit;
}

@unlink(__FILE__);

echo "ok\n";
