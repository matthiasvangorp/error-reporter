<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter\Concerns;

use MatthiasVanGorp\ErrorReporter\ErrorReporter;
use Throwable;

/**
 * Laravel 10 users: add this trait to `App\Exceptions\Handler`. Not needed on
 * Laravel 11/12 — the service provider hooks the exception handler directly.
 */
trait ReportsToErrorReporter
{
    public function report(Throwable $e): void
    {
        try {
            app(ErrorReporter::class)->captureException($e);
        } catch (Throwable) {
            // Never let the reporter interfere with the host app's report chain.
        }

        parent::report($e);
    }
}
