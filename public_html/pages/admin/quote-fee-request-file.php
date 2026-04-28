<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$quote = $id ? find_quote($id) : null;

if ($quote === null || !quote_fee_request_file_is_available($quote)) {
    http_response_code(404);
    exit('A díjbekérő fájl nem található.');
}

$path = quote_fee_request_pdf_path($quote);
$mimeType = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/pdf') : 'application/pdf';
$fileName = basename($path);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . $fileName . '"');
readfile($path);
exit;
