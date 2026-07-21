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

    'mercadopago' => [
        'access_token'   => env('MP_ACCESS_TOKEN'),
        'webhook_secret' => env('MP_WEBHOOK_SECRET'),
    ],

    'binance' => [
        'key'    => env('BINANCE_API_KEY'),
        'secret' => env('BINANCE_SECRET_KEY'),
    ],

    'infinitepay' => [
        // Link de pagamento/redirect — lojista identificado pelo HANDLE (sem API key).
        'handle'        => env('INFINITEPAY_HANDLE'),
        'webhook_token' => env('INFINITEPAY_WEBHOOK_TOKEN'),
        // Gateway padrão dos depósitos: 'infinitepay' ou 'mercadopago'.
        'gateway_padrao'=> env('GATEWAY_PADRAO', 'mercadopago'),
    ],

    'whatsapp' => [
        'graph_token'  => env('GRAPH_API_TOKEN'),
        'app_secret'   => env('WHATSAPP_APP_SECRET'),
        'phone_id'     => env('PHONE_NUMBER_ID'),
        'verify_token' => env('WEBHOOK_VERIFY_TOKEN'),
    ],

];
