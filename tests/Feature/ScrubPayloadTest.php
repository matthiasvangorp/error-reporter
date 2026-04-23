<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use MatthiasVanGorp\ErrorReporter\ErrorReporter;
use MatthiasVanGorp\ErrorReporter\Jobs\SendEventJob;

it('scrubs PII from request_data and headers before dispatch', function () {
    Bus::fake();

    // Simulate being inside a request cycle by binding a fresh Request to the container.
    $request = Illuminate\Http\Request::create('https://app.test/login', 'POST', [
        'email' => 'user@example.test',
        'password' => 'hunter2',
    ], cookies: [], files: [], server: [
        'HTTP_AUTHORIZATION' => 'Bearer secret-token',
        'HTTP_X_API_KEY' => 'api-key-value',
    ]);

    app()->instance('request', $request);

    // Rebuild the reporter so it picks up the rebound request.
    app()->forgetInstance(ErrorReporter::class);
    app(ErrorReporter::class)->captureException(new RuntimeException('boom'));

    Bus::assertDispatched(SendEventJob::class, function (SendEventJob $job) {
        $ctx = $job->payload['context'];

        return $ctx['request_data']['email'] === 'user@example.test'
            && $ctx['request_data']['password'] === '[REDACTED]'
            && strtolower(array_change_key_case($ctx['headers'] ?? [], CASE_LOWER)['authorization'] ?? '') === '[redacted]'
            && strtolower(array_change_key_case($ctx['headers'] ?? [], CASE_LOWER)['x-api-key'] ?? '') === '[redacted]';
    });
});
