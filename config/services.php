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

    // The IMIS portal that signs staff in (ImisSsoController). Unset = off.
    'imis' => [
        'sso_url' => env('IMIS_SSO_URL'),
        // This app's own host[:port], as registered in IMIS (SSO_HUB_HOSTS).
        'audience' => env('IMIS_SSO_AUDIENCE', parse_url((string) env('APP_URL'), PHP_URL_HOST).(parse_url((string) env('APP_URL'), PHP_URL_PORT) ? ':'.parse_url((string) env('APP_URL'), PHP_URL_PORT) : '')),
        // Origin that serves IMIS photos; the only host a staff photo is copied from.
        'asset_origin' => env('IMIS_ASSET_ORIGIN'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    /*
     * GA4 measurement id, opt-in per environment. When unset (the default) the
     * landing page ships no analytics code at all; when set, the client still
     * waits for the privacy-notice consent flag before loading gtag.
     */
    'ga4' => [
        'measurement_id' => env('GA_MEASUREMENT_ID'),
    ],

];
