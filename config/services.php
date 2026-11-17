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

    // Chat's GIF search (App\Services\Giphy). Without a key the GIF button
    // stays hidden. `rating` is the highest content rating offered: g, pg,
    // pg-13 or r.
    'giphy' => [
        'key' => env('GIPHY_API_KEY'),
        'rating' => env('GIPHY_RATING', 'pg'),
    ],

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

    // Bank feeds for expenses (App\Services\PlaidClient). `env` is
    // sandbox (free, test banks), development or production.
    'plaid' => [
        'client_id' => env('PLAID_CLIENT_ID'),
        'secret' => env('PLAID_SECRET'),
        'env' => env('PLAID_ENV', 'sandbox'),
    ],

    // Google Calendar (App\Services\GoogleCalendarClient): each person
    // connects their own madmadmad.com account. The Google Cloud project
    // "Studio PM" has an Internal sign-in client, so only the studio's
    // Workspace accounts can connect, with no Google review.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
