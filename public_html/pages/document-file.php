<?php
declare(strict_types=1);

$documentId = (int) ($_GET['id'] ?? 0);
$document = $documentId > 0 ? find_download_document($documentId, true) : null;

if ($document === null || !is_file((string) $document['storage_path'])) {
    http_response_code(404);
    exit('A dokumentum nem található.');
}

$path = (string) $document['storage_path'];

header('Content-Type: ' . (string) $document['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . basename((string) $document['original_name']) . '"');
readfile($path);
exit;
