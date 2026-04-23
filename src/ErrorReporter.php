<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter;

use Illuminate\Http\Request;
use MatthiasVanGorp\ErrorReporter\Jobs\SendEventJob;
use MatthiasVanGorp\ErrorReporter\Support\PayloadBuilder;
use Psr\Log\LoggerInterface;
use Throwable;

final class ErrorReporter
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly PayloadBuilder $builder,
        private readonly LoggerInterface $log,
        private readonly ?Request $request = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $extraContext
     */
    public function captureException(Throwable $e, array $extraContext = []): void
    {
        try {
            if (! $this->isEnabled()) {
                return;
            }

            if ($this->isIgnored($e)) {
                return;
            }

            $payload = $this->builder->forException(
                $e,
                $this->request,
                (string) ($this->config['environment'] ?? 'production'),
                $this->optionalString($this->config['release'] ?? null),
                $extraContext,
            );

            $this->dispatch($payload);
        } catch (Throwable $reportingError) {
            $this->log->warning('error-reporter: captureException failed', [
                'error' => $reportingError->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function captureLog(string $level, string $message, array $context = [], ?string $channel = null): void
    {
        try {
            if (! $this->isEnabled()) {
                return;
            }

            $payload = $this->builder->forLog(
                $level,
                $message,
                $context,
                $this->request,
                (string) ($this->config['environment'] ?? 'production'),
                $this->optionalString($this->config['release'] ?? null),
                $channel,
            );

            $this->dispatch($payload);
        } catch (Throwable $reportingError) {
            $this->log->warning('error-reporter: captureLog failed', [
                'error' => $reportingError->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(array $payload): void
    {
        $queue = (array) ($this->config['queue'] ?? []);

        $job = SendEventJob::dispatch($payload);

        if (! empty($queue['connection'])) {
            $job->onConnection((string) $queue['connection']);
        }

        if (! empty($queue['queue'])) {
            $job->onQueue((string) $queue['queue']);
        }
    }

    private function isEnabled(): bool
    {
        if (! ($this->config['enabled'] ?? true)) {
            return false;
        }

        return ! empty($this->config['endpoint']) && ! empty($this->config['token']) && ! empty($this->config['secret']);
    }

    private function isIgnored(Throwable $e): bool
    {
        foreach ((array) ($this->config['ignore_exceptions'] ?? []) as $class) {
            if (is_string($class) && $e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
