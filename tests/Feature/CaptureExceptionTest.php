<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use MatthiasVanGorp\ErrorReporter\ErrorReporter;
use MatthiasVanGorp\ErrorReporter\Jobs\SendEventJob;

it('dispatches SendEventJob for an exception', function () {
    Bus::fake();

    app(ErrorReporter::class)->captureException(new RuntimeException('boom'));

    Bus::assertDispatched(SendEventJob::class, function (SendEventJob $job) {
        return $job->payload['type'] === 'exception'
            && $job->payload['exception']['class'] === RuntimeException::class
            && $job->payload['exception']['message'] === 'boom';
    });
});

it('does not dispatch for configured ignore_exceptions', function () {
    config()->set('error-reporter.ignore_exceptions', [AuthenticationException::class]);

    Bus::fake();

    app(ErrorReporter::class)->captureException(new AuthenticationException('nope'));

    Bus::assertNotDispatched(SendEventJob::class);
});

it('does not dispatch when disabled', function () {
    config()->set('error-reporter.enabled', false);
    Bus::fake();

    app(ErrorReporter::class)->captureException(new RuntimeException('boom'));

    Bus::assertNotDispatched(SendEventJob::class);
});

it('does not dispatch when endpoint or token is missing', function () {
    config()->set('error-reporter.endpoint', null);
    Bus::fake();

    app(ErrorReporter::class)->captureException(new RuntimeException('boom'));

    Bus::assertNotDispatched(SendEventJob::class);
});

it('sends an HMAC-signed request to the collector', function () {
    Http::fake([
        'errors.test/api/ingest/*' => Http::response(['issue_id' => 1, 'event_id' => 1], 202),
    ]);

    app(ErrorReporter::class)->captureException(new RuntimeException('boom'));

    Http::assertSent(function ($request) {
        $body = $request->body();
        $expected = 'sha256='.hash_hmac('sha256', $body, 'testsecret');

        return str_ends_with($request->url(), '/api/ingest/testtoken')
            && $request->hasHeader('X-Signature', $expected);
    });
});

it('swallows collector errors silently', function () {
    Http::fake([
        'errors.test/*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(ErrorReporter::class)->captureException(new RuntimeException('x')))
        ->not->toThrow(Throwable::class);
});

it('swallows network failures silently', function () {
    Http::fake(function () {
        throw new RuntimeException('connect refused');
    });

    expect(fn () => app(ErrorReporter::class)->captureException(new RuntimeException('x')))
        ->not->toThrow(Throwable::class);
});

it('does not retry on 4xx from the collector', function () {
    Http::fake([
        'errors.test/*' => Http::response(['message' => 'bad'], 401),
    ]);

    app(ErrorReporter::class)->captureException(new RuntimeException('x'));

    // Sync driver runs once — no retry loop. A single HTTP call is expected.
    Http::assertSentCount(1);
});
