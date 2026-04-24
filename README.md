# matthiasvangorp/error-reporter

Laravel client library that ships exceptions and optionally log entries to a self-hosted collector over HMAC-signed HTTPS (`POST /api/ingest/{token}`).

- Async via a queued job — capture is non-blocking.
- Fails silently — a broken collector must never break the host app.
- PII scrubbing of `password`, tokens, cookies, `Authorization`, and any custom keys you add.
- HTTPS-only. No direct Guzzle dep — uses Laravel's `Http` facade.
- Laravel 10, 11, 12. PHP 8.2+.

## Install

```bash
composer require matthiasvangorp/error-reporter
php artisan vendor:publish --tag=error-reporter-config
```

## Env

```dotenv
ERROR_REPORTER_ENABLED=true
ERROR_REPORTER_ENDPOINT=https://errors.example.com
ERROR_REPORTER_TOKEN=your-project-token
ERROR_REPORTER_SECRET=your-project-secret
ERROR_REPORTER_RELEASE=  # commit SHA — see "Release tagging" below

# Optional: route the queued job to a specific connection/queue
ERROR_REPORTER_QUEUE_CONNECTION=
ERROR_REPORTER_QUEUE=default

# Optional: also capture Log::error()/warning() etc. (off by default)
ERROR_REPORTER_LOG_ENABLED=false
ERROR_REPORTER_LOG_LEVEL=error
```

## How exceptions get captured

**Laravel 11 and 12** — zero configuration. The service provider hooks the host app's `ExceptionHandler::reportable()` in `boot()`, so every exception Laravel reports flows through this package automatically.

If you prefer explicit wiring (e.g. you inspect `reportable` ordering in `bootstrap/app.php`), you can add it by hand:

```php
// bootstrap/app.php
use MatthiasVanGorp\ErrorReporter\ErrorReporter;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(function (Throwable $e) {
        app(ErrorReporter::class)->captureException($e);
    });
})
```

**Laravel 10** — same auto-hook works if you use the default framework handler. If you override `App\Exceptions\Handler::report()`, add the supplied trait so the reporter still runs alongside your custom logic:

```php
// app/Exceptions/Handler.php
use MatthiasVanGorp\ErrorReporter\Concerns\ReportsToErrorReporter;

class Handler extends ExceptionHandler
{
    use ReportsToErrorReporter;

    // ... your existing code
}
```

## Log channel integration

Off by default. To also ship `Log::error()` / `Log::warning()` etc.:

1. Add the driver to `config/logging.php`:

    ```php
    'channels' => [
        // ...

        'error-reporter' => [
            'driver' => 'error-reporter',
            'level' => 'error',
        ],
    ],
    ```

2. Stack it onto your default channel so log calls fan out to both the local log and the collector:

    ```php
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'error-reporter'],
    ],
    ```

3. Set `ERROR_REPORTER_LOG_ENABLED=true` and `ERROR_REPORTER_LOG_LEVEL=error` (or `warning`).

Log messages with numeric IDs (`"User 1234 not found"`) collapse into the same issue on the collector — fingerprinting is done on a templatized form of the message, not the literal text.

## Ignoring exceptions

Edit `config/error-reporter.php`:

```php
'ignore_exceptions' => [
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Validation\ValidationException::class,
    \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
    // add your own
],
```

Matching uses `instanceof`, so parent classes catch subclasses.

## PII scrubbing

Out of the box: `password`, `password_confirmation`, `token`, `api_token`, `access_token`, `refresh_token`, `authorization`, `cookie`, `x-api-key`, `credit_card`, `card_number`, `cvv`, `secret`.

The `Authorization` header is _always_ scrubbed regardless of the config list.

Add more in `config/error-reporter.php`:

```php
'scrub_keys' => [
    // ... defaults
    'ssn', 'tax_id', 'phone',
],
```

Matching is case-insensitive and recursive — the scrubber walks `request_data`, headers, cookies, session bags, and any custom `$extraContext` you pass to `captureException`.

## Release tagging

Set `ERROR_REPORTER_RELEASE` to the commit SHA during your deploy so the collector can attribute events to a release. Host-agnostic — adapt to your deploy pipeline:

```bash
# Bash, anywhere
ERROR_REPORTER_RELEASE=$(git rev-parse --short HEAD) php artisan config:cache
```

Or write it into `.env` during deploy (RunCloud / Laravel Forge / bare `git pull` / CI).

## Manual use

```php
app(\MatthiasVanGorp\ErrorReporter\ErrorReporter::class)
    ->captureException($e, ['custom_context' => 'value']);

app(\MatthiasVanGorp\ErrorReporter\ErrorReporter::class)
    ->captureLog('warning', 'User reconciliation drift', ['user_id' => 123]);
```

Both methods swallow any internal error. They never throw.

## Queue worker

The package dispatches `SendEventJob` to whatever queue connection Laravel is configured for (or the one you specify via `ERROR_REPORTER_QUEUE_CONNECTION`). It honors `$tries = 3` with backoff `[5, 30, 120]`:

- `5xx` / network errors → retried automatically.
- `4xx` → logged once and dropped (retrying won't fix a bad signature or wrong token).
- Final failure → logged via `failed()`.

With `QUEUE_CONNECTION=sync` the job runs inline — useful for early local testing. Any sync-driver exception is caught by the reporter's outer `try/catch`, so the host app is still safe.

## Testing the package itself

```bash
composer install
vendor/bin/pest
```

22 tests cover: Signer HMAC, scrubber (keys + depth + Authorization), payload builder (shape, trace sanitization, size-bound truncation, request scrubbing, log shape), capture orchestration (ignored exceptions, disabled config, dispatch, HMAC header, silent swallow on 5xx / network failure, no-retry on 4xx).

## Non-goals

- No breadcrumbs capture yet (the field is in the payload but always `[]`).
- No user feedback widget.
- No performance tracing.
- No source-map / release artifact uploading.
- HTTPS only — no alternative transports.
