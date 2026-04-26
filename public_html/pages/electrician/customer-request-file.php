<?php
declare(strict_types=1);

require_role(['electrician']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$file = $id ? find_connection_request_file($id) : null;

if ($file === null || !electrician_can_view_connection_request_file($file) || !is_file((string) $file['storage_path'])) {
    http_response_code(404);
    exit('A fájl nem található.');
}

$path = (string) $file['storage_path'];

header('Content-Type: ' . (string) $file['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . basename((string) $file['original_name']) . '"');
readfile($path);
exit;
