<?php

return [
    /*
    | De bedrijven in het platform. Elk bedrijf heeft een eigen database
    | (zie config/database.php). Wordt gebruikt door php artisan crm:install.
    */
    'companies' => [
        [
            'name' => 'SV Business Support',
            'slug' => 'sv',
            'connection' => 'company_sv',
            'primary_color' => '#1F4D45',
        ],
        [
            'name' => 'YES Marketing Solutions',
            'slug' => 'yes',
            'connection' => 'company_yes',
            'primary_color' => '#B4532A',
        ],
    ],

    'locales' => [
        'nl' => 'Nederlands',
        'en' => 'English',
    ],

    // Tijdzone als de browser geen tijdzone doorgeeft.
    'fallback_timezone' => 'Europe/Amsterdam',
];
