<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter\Support;

final class Signer
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $this->secret);
    }
}
