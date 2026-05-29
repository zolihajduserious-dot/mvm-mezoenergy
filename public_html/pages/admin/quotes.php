<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

set_flash('info', 'Az ajánlatok az ügyfél adatlapján kezelhetők.');
redirect('/admin/customer-lookup');
