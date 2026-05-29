<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$requestId = filter_input(INPUT_GET, 'request', FILTER_VALIDATE_INT);

if ($requestId) {
    redirect('/admin/work-request-view?request=' . (int) $requestId);
}

redirect('/admin/customer-lookup');
