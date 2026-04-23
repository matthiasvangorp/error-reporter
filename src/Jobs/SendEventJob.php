<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MatthiasVanGorp\ErrorReporter\Support\Signer;
use RuntimeException;
use Throwable;

final class SendEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload)
    {
    }

    public function handle(Signer $signer): void
    {
        $config = (array) config('error-reporter');

        $endpoint = (string) ($config['endpoint'] ?? '');
        $token = (string) ($config['token'] ?? '');

        if ($endpoint === '' || $token === '') {
            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            Log::warning('error-reporter: payload encoding failed');

            return;
        }

        $url = rtrim($endpoint, '/').'/api/ingest/'.$token;

        $response = Http::timeout((int) ($config['timeout_seconds'] ?? 5))
            ->acceptJson()
            ->withHeaders([
                'X-Signature' => $signer->sign($body),
                'Content-Type' => 'application/json',
            ])
            ->withBody($body, 'application/json')
            ->post($url);

        // 4xx = the client misconfigured (bad token/signature, rate limited, invalid payload).
        // Retrying won't fix these; log once and give up so we don't flood the queue.
        if ($response->clientError()) {
            Log::warning('error-reporter: collector rejected event', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException('error-reporter: collector returned HTTP '.$response->status());
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('error-reporter: permanent failure after retries', [
            'error' => $e->getMessage(),
        ]);
    }
}
