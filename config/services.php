<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_sheets' => [
        'webhook_url' => env('GOOGLE_SHEET_WEBHOOK_URL', ''),
        'spreadsheet_id' => env('GOOGLE_SPREADSHEET_ID', '1oBtqSEj0IZKzZAMQKquuyve242uKPEzShn4-uI92cCE'),
        'rates_csv_url' => env('GOOGLE_SHEET_RATES_CSV_URL', 'https://docs.google.com/spreadsheets/d/1fd-3VqrxFxU_1icUOh2AaKlNulOQKF-32A3WJ2UeLn4/export?format=csv&gid=1319753844'),
        'rates_cache_db' => storage_path('app/freight_rates.sqlite'),
    ],

    'whatsapp' => [
        'otp_expires_in' => (int) env('WHATSAPP_OTP_EXPIRES_IN', 300),
    ],

    'whatsapp_gateway' => [
        'url' => env('WAG_URL'),
        'token' => env('WAG_TOKEN'),
        'timeout' => (float) env('WAG_TIMEOUT', 15),
        'connect_timeout' => (float) env('WAG_CONNECT_TIMEOUT', 5),
    ],

];
