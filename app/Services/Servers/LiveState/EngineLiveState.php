<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use Carbon\CarbonImmutable;

/**
 * Typed carrier for the live-state snapshot of one webserver / edge-proxy
 * engine on a server. All engines normalize their native data into this
 * shape; the per-engine UI sub-tabs read it and render bespoke columns.
 *
 * The `$units` array is keyed by sub-tab name; each value is a list of
 * engine-specific row dicts. Example for OLS:
 *
 *   $units = [
 *     'vhosts'    => [ ['name' => 'site1', 'domain' => 'a.com', ...], ... ],
 *     'listeners' => [ ['name' => 'Default', 'address' => '*:80', ...], ... ],
 *     'extapps'   => [ ['name' => 'lsapi8.3', 'path' => '/usr/local/...', ...], ... ],
 *     'cache'     => [ ['vhost' => 'site1', 'public_hits' => 100, ...], ... ],
 *   ];
 *
 * Engine-specific extras (counters, settings) live under `$engineSpecific`
 * so they don't pollute the structured `$units` shape.
 */
final class EngineLiveState
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $units
     * @param  array<string, mixed> $engineSpecific
     */
    public function __construct(
        public readonly string $engine,
        public readonly CarbonImmutable $capturedAt,
        public readonly bool $isFresh,
        public readonly array $units = [],
        public readonly array $engineSpecific = [],
    ) {}

    /**
     * Serialize for storage on `Server.meta.webserver_live_state[engine]`.
     *
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'engine' => $this->engine,
            'captured_at' => $this->capturedAt->toIso8601String(),
            'is_fresh' => $this->isFresh,
            'units' => $this->units,
            'engine_specific' => $this->engineSpecific,
        ];
    }

    /**
     * Restore from `Server.meta.webserver_live_state[engine]`. Tolerant
     * to missing/garbage values — returns null when the payload doesn't
     * look like a serialized state. Callers treat null as "no cached
     * state, run a fresh probe."
     *
     * @param  mixed  $payload
     */
    public static function fromArray($payload): ?self
    {
        if (! is_array($payload) || ! is_string($payload['engine'] ?? null)) {
            return null;
        }
        try {
            $capturedAt = CarbonImmutable::parse((string) ($payload['captured_at'] ?? 'now'));
        } catch (\Throwable) {
            return null;
        }
        $units = is_array($payload['units'] ?? null) ? $payload['units'] : [];
        $engineSpecific = is_array($payload['engine_specific'] ?? null) ? $payload['engine_specific'] : [];

        return new self(
            engine: (string) $payload['engine'],
            capturedAt: $capturedAt,
            isFresh: (bool) ($payload['is_fresh'] ?? false),
            units: $units,
            engineSpecific: $engineSpecific,
        );
    }

    /**
     * Convenience getter — number of rows under a sub-tab. Returns 0 if
     * the sub-tab key isn't present (which is normal for engines whose
     * probe hasn't run yet or for engines that don't expose that unit).
     */
    public function unitCount(string $key): int
    {
        return is_array($this->units[$key] ?? null) ? count($this->units[$key]) : 0;
    }

    /**
     * Normalize engineSpecific['errors'] for UI display. Probes store a
     * list of strings, but cached payloads may contain nested arrays
     * (e.g. API-style {"message": "…"} objects).
     *
     * @return list<string>
     */
    public static function probeErrorLines(mixed $errors): array
    {
        if ($errors === null || $errors === '' || $errors === []) {
            return [];
        }

        if (! is_array($errors)) {
            $line = self::formatProbeErrorLine($errors);

            return $line !== '' ? [$line] : [];
        }

        $lines = [];
        foreach ($errors as $error) {
            $line = self::formatProbeErrorLine($error);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function formatProbeErrorLine(mixed $error): string
    {
        if ($error === null || $error === '' || $error === []) {
            return '';
        }

        if (is_scalar($error)) {
            return trim((string) $error);
        }

        if (is_array($error)) {
            if (isset($error['message']) && is_scalar($error['message'])) {
                return trim((string) $error['message']);
            }

            $encoded = json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : '';
        }

        if ($error instanceof \Throwable) {
            return trim($error->getMessage());
        }

        return trim((string) $error);
    }
}
