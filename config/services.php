<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'payment' => [
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
        'gateway' => env('PAYMENT_GATEWAY', 'simulated'),
        'checkout_url' => env('PAYMENT_CHECKOUT_URL'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'usd'),
        'checkout_success_url' => env('STRIPE_CHECKOUT_SUCCESS_URL'),
        'checkout_cancel_url' => env('STRIPE_CHECKOUT_CANCEL_URL'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        // 2.5 models are blocked for new API keys; prefer current 3.x Flash.
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        // Max prior chat messages (user+assistant) sent to Gemini for conversational context.
        'chat_history_limit' => (int) env('GEMINI_CHAT_HISTORY_LIMIT', 20),
    ],

];
