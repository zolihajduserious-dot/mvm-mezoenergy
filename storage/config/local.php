<?php
declare(strict_types=1);

return [
    // Shared hosting often cannot set environment variables. Copy this file to local.php
    // on the server and fill the values there. Do not place real secrets in public_html.
    'MVM_DOCX_CONVERTER' => 'convertapi',
    'CONVERTAPI_SECRET' => 'ygwuymkAU54Axo2MDlwTyS4qW2xSxpqT',
    'CONVERTAPI_ENDPOINT' => 'https://v2.convertapi.com',
    'SZAMLAZZ_AGENT_KEY' => 'fxhcc5im7yni5zrmngesyr7b2spqe49cduy6fx7d7g',
    'MVM_REPLY_EMAIL' => 'csatlakozo@mvm-mezoenergy.hu',
    'MVM_IMAP_HOST' => 'mail.nethely.hu',
    'MVM_IMAP_PORT' => '993',
    'MVM_IMAP_ENCRYPTION' => 'ssl',
    'MVM_IMAP_FOLDER' => 'INBOX',
    'MVM_IMAP_USER' => 'csatlakozo@mvm-mezoenergy.hu',
];
