<?php

declare(strict_types=1);

use MatthiasVanGorp\ErrorReporter\Support\Signer;

it('produces sha256=<hmac_sha256(body, secret)>', function () {
    $signer = new Signer('topsecret');
    $body = '{"hello":"world"}';

    expect($signer->sign($body))
        ->toBe('sha256='.hash_hmac('sha256', $body, 'topsecret'));
});

it('produces different signatures for different secrets', function () {
    $body = 'same body';

    expect((new Signer('a'))->sign($body))
        ->not->toBe((new Signer('b'))->sign($body));
});
