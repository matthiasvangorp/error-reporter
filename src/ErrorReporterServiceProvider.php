<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use MatthiasVanGorp\ErrorReporter\Log\ErrorReporterLogChannel;
use MatthiasVanGorp\ErrorReporter\Support\PayloadBuilder;
use MatthiasVanGorp\ErrorReporter\Support\PayloadScrubber;
use MatthiasVanGorp\ErrorReporter\Support\Signer;
use Throwable;

final class ErrorReporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/error-reporter.php', 'error-reporter');

        $this->app->singleton(PayloadScrubber::class, function ($app) {
            return new PayloadScrubber((array) $app['config']->get('error-reporter.scrub_keys', []));
        });

        $this->app->singleton(PayloadBuilder::class, function ($app) {
            return new PayloadBuilder(
                $app->make(PayloadScrubber::class),
                (int) $app['config']->get('error-reporter.max_payload_bytes', 256 * 1024),
            );
        });

        $this->app->singleton(Signer::class, function ($app) {
            return new Signer((string) $app['config']->get('error-reporter.secret', ''));
        });

        $this->app->singleton(ErrorReporter::class, function ($app) {
            return new ErrorReporter(
                $app['config']->get('error-reporter'),
                $app->make(PayloadBuilder::class),
                $app->make('log'),
                $app->make('request', []),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/error-reporter.php' => $this->app->configPath('error-reporter.php'),
        ], 'error-reporter-config');

        $this->registerLogDriver();
        $this->registerExceptionReporter();
    }

    private function registerLogDriver(): void
    {
        $log = $this->app->make('log');
        if ($log instanceof LogManager) {
            $log->extend('error-reporter', function ($app, array $config) {
                return (new ErrorReporterLogChannel())($app, $config);
            });
        }
    }

    private function registerExceptionReporter(): void
    {
        if (! $this->app->bound(ExceptionHandler::class)) {
            return;
        }

        try {
            $handler = $this->app->make(ExceptionHandler::class);

            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $e) {
                $this->app->make(ErrorReporter::class)->captureException($e);
            });
        } catch (Throwable) {
            // Never prevent host app boot because of reporter wiring.
        }
    }
}
