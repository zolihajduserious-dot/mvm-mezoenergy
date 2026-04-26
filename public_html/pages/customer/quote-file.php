<?php
declare(strict_types=1);

require_role(['customer']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$quote = $id ? find_quote($id) : null;

if ($quote === null || !customer_can_view_quote($quote) || !quote_file_is_available($quote)) {
    http_response_code(404);
    exit('Az ajánlatfájl nem található.');
}

$path = (string) $quote['pdf_path'];
$mimeType = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
$fileName = !empty($quote['uploaded_original_name'])
    ? (string) $quote['uploaded_original_name']
    : (string) $quote['quote_number'] . '.pdf';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
readfile($path);
exit;
