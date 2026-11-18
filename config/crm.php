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

    /*
    | AVG-bewaartermijnen. Worden dagelijks toegepast door crm:retention
    | (via crm:tick). Zet een waarde op 0 om die regel uit te schakelen.
    */
    'retention' => [
        // Afgeronde leads (niet geïnteresseerd, geconverteerd, gesloten ...) na laatste wijziging
        'leads_months' => (int) env('CRM_RETENTION_LEADS_MONTHS', 12),
        // Afgesloten pre-incassodossiers na sluiting
        'collection_months' => (int) env('CRM_RETENTION_COLLECTION_MONTHS', 24),
        // Geüploade importbestanden (Excel/CSV met persoonsgegevens)
        'import_files_days' => (int) env('CRM_RETENTION_IMPORT_FILES_DAYS', 90),
        // Gemaakte rapport-exports
        'report_files_days' => (int) env('CRM_RETENTION_REPORT_FILES_DAYS', 30),
        // Centrale audit-log
        'audit_log_months' => (int) env('CRM_RETENTION_AUDIT_MONTHS', 24),
    ],
];
