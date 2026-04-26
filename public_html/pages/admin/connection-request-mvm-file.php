<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$document = $id ? find_connection_request_document($id) : null;

if ($document === null || !is_file((string) $document['storage_path'])) {
    http_response_code(404);
    exit('A dokumentum nem található.');
}

$path = (string) $document['storage_path'];

header('Content-Type: ' . (string) $document['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . basename((string) $document['original_name']) . '"');
readfile($path);
exit;
