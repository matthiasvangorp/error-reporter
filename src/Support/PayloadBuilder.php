<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Throwable;

final class PayloadBuilder
{
    private const PREVIOUS_DEPTH_LIMIT = 5;

    private const MIN_MESSAGE_LENGTH = 200;

    public function __construct(
        private readonly PayloadScrubber $scrubber,
        private readonly int $maxPayloadBytes,
    ) {
    }

    /**
     * @param  array<string, mixed>  $extraContext
     * @return array<string, mixed>
     */
    public function forException(
        Throwable $e,
        ?Request $request,
        string $environment,
        ?string $release,
        array $extraContext = [],
    ): array {
        $payload = [
            'type' => 'exception',
            'timestamp' => $this->timestamp(),
            'exception' => $this->buildException($e, depth: 0),
            'context' => $this->buildContext($request, $environment, $release, $extraContext),
            'breadcrumbs' => [],
        ];

        return $this->fitWithinBytes($payload);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function forLog(
        string $level,
        string $message,
        array $context,
        ?Request $request,
        string $environment,
        ?string $release,
        ?string $channel = null,
    ): array {
        $payload = [
            'type' => 'log',
            'timestamp' => $this->timestamp(),
            'log' => [
                'channel' => $channel ?? 'default',
                'level' => strtolower($level),
                'message' => $message,
                'context' => $this->scrubber->scrub($context),
            ],
            'context' => $this->buildContext($request, $environment, $release, []),
            'breadcrumbs' => [],
        ];

        return $this->fitWithinBytes($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildException(Throwable $e, int $depth): array
    {
        $data = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->buildTrace($e),
            'previous' => null,
        ];

        $previous = $e->getPrevious();
        if ($previous !== null && $depth < self::PREVIOUS_DEPTH_LIMIT) {
            $data['previous'] = $this->buildException($previous, $depth + 1);
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTrace(Throwable $e): array
    {
        $frames = [];
        foreach ($e->getTrace() as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
            ];
        }

        return $frames;
    }

    /**
     * @param  array<string, mixed>  $extraContext
     * @return array<string, mixed>
     */
    private function buildContext(?Request $request, string $environment, ?string $release, array $extraContext): array
    {
        $context = [
            'environment' => $environment,
            'release' => $release,
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->laravelVersion(),
        ];

        if ($request instanceof Request) {
            $context = array_merge($context, [
                'url' => $this->safeString(fn () => $request->fullUrl()),
                'method' => $this->safeString(fn () => $request->method()),
                'user_id' => $this->safeCall(fn () => optional($request->user())->getAuthIdentifier()),
                'ip' => $this->safeString(fn () => $request->ip()),
                'user_agent' => $this->safeString(fn () => $request->userAgent()),
                'request_data' => $this->safeCall(fn () => $this->scrubber->scrub($request->all())) ?? [],
                'headers' => $this->safeCall(fn () => $this->scrubber->scrub($this->headers($request))) ?? [],
                'session_id' => $this->safeCall(fn () => $request->hasSession() ? $request->session()->getId() : null),
                'route' => $this->safeCall(fn () => optional($request->route())->getName()),
            ]);
        }

        return array_merge($context, $extraContext);
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $name => $values) {
            // Collapse single-value arrays into a bare string.
            $out[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $out;
    }

    /**
     * Serialize and, if oversized, progressively shrink until it fits the byte budget.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fitWithinBytes(array $payload): array
    {
        if ($this->byteLength($payload) <= $this->maxPayloadBytes) {
            return $payload;
        }

        // 1) Strip previous chain.
        if (isset($payload['exception']['previous'])) {
            $payload['exception']['previous'] = null;
            if ($this->byteLength($payload) <= $this->maxPayloadBytes) {
                return $payload;
            }
        }

        // 2) Drop trace frames from the tail.
        while (isset($payload['exception']['trace']) && count($payload['exception']['trace']) > 0) {
            array_pop($payload['exception']['trace']);
            if ($this->byteLength($payload) <= $this->maxPayloadBytes) {
                return $payload;
            }
        }

        // 3) Trim request data (often the largest field after trace).
        if (isset($payload['context']['request_data'])) {
            $payload['context']['request_data'] = ['_truncated' => true];
            if ($this->byteLength($payload) <= $this->maxPayloadBytes) {
                return $payload;
            }
        }

        if (isset($payload['context']['headers'])) {
            $payload['context']['headers'] = ['_truncated' => true];
            if ($this->byteLength($payload) <= $this->maxPayloadBytes) {
                return $payload;
            }
        }

        // 4) Trim the exception / log message as a last resort.
        if (isset($payload['exception']['message']) && is_string($payload['exception']['message'])) {
            $payload['exception']['message'] = mb_substr($payload['exception']['message'], 0, self::MIN_MESSAGE_LENGTH).'…[truncated]';
        }

        if (isset($payload['log']['message']) && is_string($payload['log']['message'])) {
            $payload['log']['message'] = mb_substr($payload['log']['message'], 0, self::MIN_MESSAGE_LENGTH).'…[truncated]';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function byteLength(array $payload): int
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($encoded) ? strlen($encoded) : PHP_INT_MAX;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }

    private function laravelVersion(): ?string
    {
        try {
            $app = function_exists('app') ? app() : null;

            return $app instanceof Application ? $app->version() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function safeString(callable $fn): ?string
    {
        $v = $this->safeCall($fn);

        return $v === null ? null : (string) $v;
    }

    /**
     * @return mixed
     */
    private function safeCall(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }
}
