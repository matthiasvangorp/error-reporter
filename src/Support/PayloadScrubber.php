<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter\Support;

final class PayloadScrubber
{
    public const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private readonly array $keys;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(array $keys)
    {
        $normalized = array_map('strtolower', $keys);

        // Always scrub Authorization regardless of config.
        if (! in_array('authorization', $normalized, true)) {
            $normalized[] = 'authorization';
        }

        $this->keys = array_values(array_unique($normalized));
    }

    /**
     * Recursively redact values for any key matching the scrub list (case-insensitive).
     */
    public function scrub(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isScrubbable($key)) {
                $out[$key] = self::REDACTED;
                continue;
            }

            $out[$key] = $this->scrub($item);
        }

        return $out;
    }

    private function isScrubbable(string $key): bool
    {
        return in_array(strtolower($key), $this->keys, true);
    }
}
