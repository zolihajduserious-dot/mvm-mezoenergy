<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$requestId = filter_input(INPUT_GET, 'request', FILTER_VALIDATE_INT);

if ($requestId) {
    redirect('/admin/minicrm-import?request=' . (int) $requestId . '#portal-work-' . (int) $requestId);
}

redirect('/admin/minicrm-import#portal-works');
