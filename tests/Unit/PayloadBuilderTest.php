<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use MatthiasVanGorp\ErrorReporter\Support\PayloadBuilder;
use MatthiasVanGorp\ErrorReporter\Support\PayloadScrubber;

function makeBuilder(int $maxBytes = 256 * 1024): PayloadBuilder
{
    return new PayloadBuilder(new PayloadScrubber(['password', 'token']), $maxBytes);
}

it('builds a correctly-shaped exception payload', function () {
    $builder = makeBuilder();
    $e = new RuntimeException('boom');

    $payload = $builder->forException($e, null, 'production', 'abc123');

    expect($payload)->toHaveKeys(['type', 'timestamp', 'exception', 'context', 'breadcrumbs']);
    expect($payload['type'])->toBe('exception');
    expect($payload['exception']['class'])->toBe(RuntimeException::class);
    expect($payload['exception']['message'])->toBe('boom');
    expect($payload['exception']['file'])->toBeString();
    expect($payload['exception']['line'])->toBeInt();
    expect($payload['exception']['trace'])->toBeArray();
    expect($payload['context']['environment'])->toBe('production');
    expect($payload['context']['release'])->toBe('abc123');
    expect($payload['breadcrumbs'])->toBe([]);
});

it('does not include args in trace frames', function () {
    $builder = makeBuilder();

    $payload = $builder->forException(new RuntimeException('x'), null, 'local', null);

    foreach ($payload['exception']['trace'] as $frame) {
        expect($frame)->not->toHaveKey('args');
    }
});

it('includes a previous chain when present', function () {
    $builder = makeBuilder();
    $inner = new RuntimeException('inner');
    $outer = new RuntimeException('outer', 0, $inner);

    $payload = $builder->forException($outer, null, 'local', null);

    expect($payload['exception']['previous'])->not->toBeNull();
    expect($payload['exception']['previous']['message'])->toBe('inner');
});

it('truncates the trace to fit within max bytes', function () {
    $builder = makeBuilder(maxBytes: 1024);

    // Deeply nested calls to manufacture a long stack.
    $deep = function (int $n) use (&$deep) {
        if ($n <= 0) {
            throw new RuntimeException(str_repeat('x', 200));
        }
        $deep($n - 1);
    };

    try {
        $deep(40);
        $this->fail('expected throw');
    } catch (RuntimeException $e) {
        $payload = $builder->forException($e, null, 'local', null);
        $encoded = json_encode($payload);
        expect(strlen($encoded))->toBeLessThanOrEqual(1024);
    }
});

it('scrubs request data when building context', function () {
    $builder = makeBuilder();
    $request = Request::create('https://app.test/foo', 'POST', [
        'email' => 'a@b.test',
        'password' => 'hunter2',
    ]);

    $payload = $builder->forException(new RuntimeException('x'), $request, 'local', null);

    expect($payload['context']['request_data']['email'])->toBe('a@b.test');
    expect($payload['context']['request_data']['password'])->toBe('[REDACTED]');
    expect($payload['context']['method'])->toBe('POST');
    expect($payload['context']['url'])->toContain('app.test/foo');
});

it('omits request-scoped fields when no request is given', function () {
    $builder = makeBuilder();

    $payload = $builder->forException(new RuntimeException('x'), null, 'local', null);

    expect($payload['context'])->not->toHaveKey('url');
    expect($payload['context'])->not->toHaveKey('request_data');
});

it('builds a log payload with channel/level/message/context', function () {
    $builder = makeBuilder();

    $payload = $builder->forLog('warning', 'Something happened', ['user_id' => 9], null, 'local', null, 'single');

    expect($payload['type'])->toBe('log');
    expect($payload['log']['channel'])->toBe('single');
    expect($payload['log']['level'])->toBe('warning');
    expect($payload['log']['message'])->toBe('Something happened');
    expect($payload['log']['context'])->toBe(['user_id' => 9]);
});
