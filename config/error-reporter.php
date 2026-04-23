<?php

declare(strict_types=1);

return [
    'enabled' => env('ERROR_REPORTER_ENABLED', true),
    'endpoint' => env('ERROR_REPORTER_ENDPOINT'),
    'token' => env('ERROR_REPORTER_TOKEN'),
    'secret' => env('ERROR_REPORTER_SECRET'),
    'environment' => env('APP_ENV'),
    'release' => env('ERROR_REPORTER_RELEASE'),

    'queue' => [
        'connection' => env('ERROR_REPORTER_QUEUE_CONNECTION'),
        'queue' => env('ERROR_REPORTER_QUEUE', 'default'),
    ],

    'log' => [
        'enabled' => env('ERROR_REPORTER_LOG_ENABLED', false),
        'level' => env('ERROR_REPORTER_LOG_LEVEL', 'error'),
    ],

    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
    ],

    'scrub_keys' => [
        'password', 'password_confirmation',
        'token', 'api_token', 'access_token', 'refresh_token',
        'authorization', 'cookie', 'x-api-key',
        'credit_card', 'card_number', 'cvv',
        'secret',
    ],

    'max_payload_bytes' => 256 * 1024,

    'timeout_seconds' => 5,
];
