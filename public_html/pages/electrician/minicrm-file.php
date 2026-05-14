<?php
declare(strict_types=1);

require_role(['electrician']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$file = $id ? find_minicrm_work_item_file($id) : null;

if (
    $file === null
    || !electrician_can_view_minicrm_work_item_file($file)
    || !is_file((string) $file['storage_path'])
) {
    http_response_code(404);
    exit('A fájl nem található.');
}

$path = (string) $file['storage_path'];
$mimeType = (string) ($file['mime_type'] ?: 'application/octet-stream');

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . basename((string) $file['original_name']) . '"');
readfile($path);
exit;
