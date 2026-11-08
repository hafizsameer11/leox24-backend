<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bulk email sender identities
    |--------------------------------------------------------------------------
    |
    | Each entry appears in the CRM "Mittente" dropdown. The optional `mailer`
    | key selects a transport from config/mail.php (Aruba SMTP accounts use
    | their own credentials; Gmail senders share the default smtp mailer).
    |
    | Never put SMTP passwords here — only public From identity fields.
    |
    */
    'senders' => [
        [
            'id' => 'mypetplus',
            'label' => 'MyPet Plus',
            'address' => env('MAIL_FROM_MYPETPLUS_ADDRESS', 'info@mypetplus.it'),
            'name' => env('MAIL_FROM_MYPETPLUS_NAME', 'MyPet Plus'),
            'mailer' => 'aruba_mypetplus',
        ],
        [
            'id' => 'vistaexpress',
            'label' => 'Vista Express',
            'address' => env('MAIL_FROM_VISTAEXPRESS_ADDRESS', 'info@vistaexpress.it'),
            'name' => env('MAIL_FROM_VISTAEXPRESS_NAME', 'Vista Express'),
            'mailer' => 'aruba_vistaexpress',
        ],
        [
            'id' => 'mydoctorplus',
            'label' => 'MyDoctor+',
            'address' => env('MAIL_FROM_MYDOCTORPLUS_ADDRESS', 'info@mydoctorplus.it'),
            'name' => env('MAIL_FROM_MYDOCTORPLUS_NAME', 'MyDoctor+'),
            'mailer' => 'aruba_mydoctorplus',
        ],
        [
            'id' => 'mybeauty',
            'label' => 'My Beauty',
            'address' => env('MAIL_FROM_MYBEAUTY_ADDRESS', 'info@mybeutyplus.com'),
            'name' => env('MAIL_FROM_MYBEAUTY_NAME', 'My Beauty'),
            'mailer' => 'aruba_mybeauty',
        ],
        [
            'id' => 'tg_calabria',
            'label' => 'TG Calabria Report',
            'address' => env('MAIL_FROM_TG_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            // Always use the Mittente title as the visible From name.
            'name' => env('MAIL_FROM_TG_NAME', 'TG Calabria Report'),
            'mailer' => 'smtp',
        ],
        [
            'id' => 'mydoc_plus',
            'label' => 'MyDoc+',
            'address' => env('MAIL_FROM_MYDOC_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            'name' => env('MAIL_FROM_MYDOC_NAME', 'MyDoc+'),
            'mailer' => 'smtp',
        ],
        [
            'id' => 'default',
            'label' => 'LEO24 CRM',
            'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
            'name' => env('MAIL_FROM_NAME', 'LEO24 CRM'),
            'mailer' => 'smtp',
        ],
    ],
];
