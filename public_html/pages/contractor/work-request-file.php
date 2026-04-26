<?php
declare(strict_types=1);

require_role(['general_contractor']);

$fileId = (int) ($_GET['id'] ?? 0);
$file = $fileId > 0 ? find_connection_request_file($fileId) : null;

if ($file === null || !contractor_can_view_connection_request_file($file)) {
    http_response_code(404);
    exit('A fájl nem található.');
}

$path = (string) $file['storage_path'];

if (!is_file($path)) {
    http_response_code(404);
    exit('A fájl nem található.');
}

header('Content-Type: ' . (string) $file['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . basename((string) $file['original_name']) . '"');
readfile($path);
exit;
