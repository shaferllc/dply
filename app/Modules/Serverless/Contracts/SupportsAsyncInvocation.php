<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Contracts;

/**
 * A backend that can start an invocation without waiting for its result, and
 * fetch the outcome afterwards by id.
 *
 * The blocking path caps out at the platform's synchronous limit (60s on
 * DigitalOcean Functions, and dply's own PHP request budget below that), so
 * anything long-running has to be started and collected separately.
 *
 * @see https://docs.digitalocean.com/products/functions/how-to/async-functions/
 */
interface SupportsAsyncInvocation
{
    /**
     * Start an invocation and return immediately with its activation id.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, error: ?string, activation_id: ?string}
     */
    public function invokeAsync(string $name, array $payload = [], array $context = []): array;

    /**
     * Fetch a completed activation record. `ok` is false while the activation
     * is still running — the caller polls.
     *
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, error: ?string, pending: bool, activation: ?array<string, mixed>}
     */
    public function fetchActivation(string $activationId, array $context = []): array;
}
