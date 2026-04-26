<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$photo = $id ? find_quote_photo($id) : null;

if ($photo === null || !is_file((string) $photo['storage_path'])) {
    http_response_code(404);
    exit('A foto nem talalhato.');
}

header('Content-Type: ' . $photo['mime_type']);
header('Content-Length: ' . (string) filesize((string) $photo['storage_path']));
header('Content-Disposition: inline; filename="' . basename((string) $photo['original_name']) . '"');
readfile((string) $photo['storage_path']);
exit;
