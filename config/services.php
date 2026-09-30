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

    /*
     * Consulta al validador del SAT desde la Constancia de Situación Fiscal.
     * La dirección consultada se arma con el idCIF y el RFC del QR; nunca se
     * consulta la dirección tal como viene en el QR.
     */
    'sat' => [
        'dominio' => 'sat.gob.mx',
        'validador_url' => 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf',
        'timeout' => 5,
        'caida_segundos' => 120,
        'cache_horas' => 24,
    ],

    /*
     * PAC para timbrar CFDI. Los dos ambientes usan la misma URL; solo cambia
     * la llave. El emisor es la organización configurada en facturapi.io:
     * estos datos solo alimentan el PDF si la respuesta del timbrado no los trae.
     */
    'facturapi' => [
        'ambiente' => env('FACTURAPI_ENV', 'test'),
        'llave' => env('FACTURAPI_ENV', 'test') === 'live' ? env('FACTURAPI_LIVE_KEY') : env('FACTURAPI_TEST_KEY'),
        'url' => 'https://www.facturapi.io/v2',
        'timeout' => (int) env('FACTURAPI_TIMEOUT', 30),
        'emisor' => [
            'rfc' => env('FACTURAPI_EMISOR_RFC'),
            'razon_social' => env('FACTURAPI_EMISOR_RAZON_SOCIAL'),
            'regimen_fiscal' => env('FACTURAPI_EMISOR_REGIMEN'),
            'codigo_postal' => env('FACTURAPI_EMISOR_CP'),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
