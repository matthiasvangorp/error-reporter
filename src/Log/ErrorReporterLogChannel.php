<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter\Log;

use Illuminate\Contracts\Container\Container;
use MatthiasVanGorp\ErrorReporter\ErrorReporter;
use Monolog\Level;
use Monolog\Logger;

final class ErrorReporterLogChannel
{
    /**
     * Factory invoked by Laravel's `Log::extend('error-reporter', ...)` resolver.
     *
     * @param  array<string, mixed>  $config
     */
    public function __invoke(Container $app, array $config): Logger
    {
        $reporter = $app->make(ErrorReporter::class);
        $level = $this->resolveLevel($config['level'] ?? 'error');

        return new Logger('error-reporter', [
            new ErrorReporterLogHandler($reporter, $level),
        ]);
    }

    private function resolveLevel(mixed $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        if (is_int($level)) {
            return Level::from($level);
        }

        $name = strtolower((string) $level);

        return match ($name) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning', 'warn' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Error,
        };
    }
}
