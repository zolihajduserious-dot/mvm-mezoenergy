<?php
declare(strict_types=1);

require_role(['admin']);

$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);
$feeType = (string) ($_GET['fee_type'] ?? '');

if (!$requestId || service_fee_request_option($feeType) === null) {
    http_response_code(404);
    exit('A díjbekérő fájl nem található.');
}

$path = connection_request_service_fee_request_pdf_path((int) $requestId, $feeType);

if ($path === null || !is_file($path) || filesize($path) <= 0) {
    http_response_code(404);
    exit('A díjbekérő fájl nem található.');
}

$mimeType = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/pdf') : 'application/pdf';
$fileName = basename($path);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . $fileName . '"');
readfile($path);
exit;
