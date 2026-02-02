<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | https://developers.google.com/identity/protocols/googlescopes
    |
    */
    'scopes' => [
        \Google\Service\Sheets::DRIVE,
        \Google\Service\Sheets::SPREADSHEETS,
    ],

    /*
    |--------------------------------------------------------------------------
    | Access Type
    |--------------------------------------------------------------------------
    */
    'access_type' => 'offline',

    /*
    |--------------------------------------------------------------------------
    | Approval Prompt
    |--------------------------------------------------------------------------
    */
    'approval_prompt' => 'auto',

    /*
    |--------------------------------------------------------------------------
    | Service Account
    |--------------------------------------------------------------------------
    */
    'service' => [
        'enable' => true,
        'file' => null, // We use 'config' array below
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Client Config
    |--------------------------------------------------------------------------
    */
    'config' => [
        'type' => 'service_account',
        'project_id' => 'les-innovations-factory-485418',
        'private_key_id' => 'dd05719590bb4e9f85c3426136c792f5151f6e63',
        'private_key' => str_replace('\\n', "\n", config('services.google_sheets.private_key')),
        'client_email' => config('services.google_sheets.service_account_email'),
        'client_id' => '108073882259484905091',
        'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/' . urlencode(config('services.google_sheets.service_account_email')),
    ],
];
