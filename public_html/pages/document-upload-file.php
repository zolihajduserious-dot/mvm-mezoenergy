<?php
declare(strict_types=1);

$fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$requestId = filter_input(INPUT_GET, 'request', FILTER_VALIDATE_INT);
$token = trim((string) ($_GET['token'] ?? ''));
$request = $requestId ? find_connection_request($requestId) : null;
$file = $fileId ? find_connection_request_file($fileId) : null;

if (
    $request === null
    || !customer_document_upload_token_is_valid($request, $token)
    || $file === null
    || (int) ($file['connection_request_id'] ?? 0) !== (int) $request['id']
    || !is_file((string) ($file['storage_path'] ?? ''))
) {
    http_response_code(404);
    exit('A fájl nem található.');
}

$path = (string) $file['storage_path'];
$mimeType = trim((string) ($file['mime_type'] ?? '')) ?: 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . basename((string) ($file['original_name'] ?? 'dokumentum')) . '"');
readfile($path);
exit;
