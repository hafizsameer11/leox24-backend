<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bulk email sender identities
    |--------------------------------------------------------------------------
    |
    | Used when sending bulk emails from the CRM. Each sender appears in the
    | send modal so admins can choose TG Calabria vs MyDoc+ (or other brands).
    |
    */
    'senders' => [
        [
            'id' => 'tg_calabria',
            'label' => 'TG Calabria Report',
            'address' => env('MAIL_FROM_TG_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            'name' => env('MAIL_FROM_TG_NAME', 'TG Calabria Report'),
        ],
        [
            'id' => 'mydoc_plus',
            'label' => 'MyDoc+',
            'address' => env('MAIL_FROM_MYDOC_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            'name' => env('MAIL_FROM_MYDOC_NAME', 'MyDoc+'),
        ],
        [
            'id' => 'default',
            'label' => 'LEO24 CRM',
            'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
            'name' => env('MAIL_FROM_NAME', 'LEO24 CRM'),
        ],
    ],
];
