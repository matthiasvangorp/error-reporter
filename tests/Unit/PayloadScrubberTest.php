<?php

declare(strict_types=1);

use MatthiasVanGorp\ErrorReporter\Support\PayloadScrubber;

it('redacts configured keys at any depth', function () {
    $scrubber = new PayloadScrubber(['password', 'token']);

    $result = $scrubber->scrub([
        'user' => [
            'email' => 'a@b.test',
            'password' => 'hunter2',
            'meta' => ['token' => 'abc'],
        ],
        'safe' => 'value',
    ]);

    expect($result['user']['email'])->toBe('a@b.test');
    expect($result['user']['password'])->toBe('[REDACTED]');
    expect($result['user']['meta']['token'])->toBe('[REDACTED]');
    expect($result['safe'])->toBe('value');
});

it('is case-insensitive on keys', function () {
    $scrubber = new PayloadScrubber(['password']);

    $result = $scrubber->scrub(['Password' => 'hunter2', 'PASSWORD' => 'hunter2']);

    expect($result['Password'])->toBe('[REDACTED]');
    expect($result['PASSWORD'])->toBe('[REDACTED]');
});

it('always strips the Authorization key even when not in config', function () {
    $scrubber = new PayloadScrubber([]);

    $result = $scrubber->scrub([
        'headers' => ['Authorization' => 'Bearer eyJ...', 'X-Trace-Id' => 'xyz'],
    ]);

    expect($result['headers']['Authorization'])->toBe('[REDACTED]');
    expect($result['headers']['X-Trace-Id'])->toBe('xyz');
});

it('leaves scalars alone', function () {
    $scrubber = new PayloadScrubber(['password']);

    expect($scrubber->scrub('a string'))->toBe('a string');
    expect($scrubber->scrub(42))->toBe(42);
    expect($scrubber->scrub(null))->toBeNull();
});
