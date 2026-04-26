<?php
declare(strict_types=1);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$token = trim((string) ($_GET['token'] ?? ''));
$quote = $id ? find_quote_by_public_access($id, $token) : null;

if ($quote === null || !quote_file_is_available($quote)) {
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
