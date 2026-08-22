<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use Illuminate\Support\Str;

/**
 * The named worker definitions kept at `site.meta.serverless.workers`.
 * (The queue-engine toggle beside them is {@see ServerlessBackgroundTasks}.)
 *
 * The workspace page ({@see \App\Livewire\Sites\Workers}) and the public API
 * both go through here so the two can't drift on the parts that are easy to
 * get subtly wrong — the legacy `background_enabled` mirror, and the shape of
 * a worker row.
 */
final class SiteWorkerRegistry
{
    /** Restart policies a worker definition may declare. */
    public const RESTART_POLICIES = ['always', 'on-failure', 'never'];

    /**
     * Every worker defined for the site, cleaned up: malformed entries are
     * dropped and missing keys back-filled.
     *
     * @return list<array<string, mixed>>
     */
    public function all(Site $site): array
    {
        $workers = [];

        foreach ((array) ($this->config($site)['workers'] ?? []) as $entry) {
            if (! is_array($entry) || trim((string) ($entry['id'] ?? '')) === '') {
                continue;
            }

            $policy = (string) ($entry['restart_policy'] ?? 'on-failure');

            $workers[] = [
                'id' => (string) $entry['id'],
                'name' => (string) ($entry['name'] ?? 'worker'),
                'command' => (string) ($entry['command'] ?? ''),
                'concurrency' => max(1, (int) ($entry['concurrency'] ?? 1)),
                'restart_policy' => in_array($policy, self::RESTART_POLICIES, true) ? $policy : 'on-failure',
                'enabled' => (bool) ($entry['enabled'] ?? false),
            ];
        }

        return $workers;
    }

    /** Resolve one worker by id, or — for the CLI — by exact name. */
    public function find(Site $site, string $idOrName): ?array
    {
        $needle = trim($idOrName);

        foreach ($this->all($site) as $worker) {
            if ($worker['id'] === $needle || strcasecmp($worker['name'], $needle) === 0) {
                return $worker;
            }
        }

        return null;
    }

    /**
     * Append a worker definition, enabled by default. Turning the engine on is
     * the operator's job — the toggle stays independent.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> the stored worker
     */
    public function add(Site $site, array $attributes): array
    {
        $worker = $this->shape($attributes, [
            'id' => (string) Str::ulid(),
            'name' => 'worker',
            'command' => '',
            'concurrency' => 1,
            'restart_policy' => 'on-failure',
            'enabled' => true,
        ]);

        $this->persist($site, [...$this->all($site), $worker]);

        return $worker;
    }

    /**
     * Patch an existing worker — only the keys present in `$attributes` move.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null the updated worker, or null if unknown
     */
    public function update(Site $site, string $id, array $attributes): ?array
    {
        $existing = $this->find($site, $id);

        if ($existing === null) {
            return null;
        }

        $updated = $this->shape($attributes, $existing);

        $this->persist($site, array_map(
            fn (array $worker): array => $worker['id'] === $existing['id'] ? $updated : $worker,
            $this->all($site),
        ));

        return $updated;
    }

    public function remove(Site $site, string $id): bool
    {
        $existing = $this->find($site, $id);

        if ($existing === null) {
            return false;
        }

        $this->persist($site, array_values(array_filter(
            $this->all($site),
            fn (array $worker): bool => $worker['id'] !== $existing['id'],
        )));

        return true;
    }

    /**
     * A worker's live status. v1 has no per-worker process, so it is derived:
     * a disabled worker is Stopped; an enabled worker with the engine off is
     * idle; otherwise it mirrors the most recent queue tick.
     *
     * @param  array<string, mixed>  $worker
     * @return array{0: string, 1: string} machine state, human label
     */
    public function status(array $worker, bool $engineOn, ?string $lastTickStatus): array
    {
        if (! ($worker['enabled'] ?? false)) {
            return ['stopped', __('Stopped')];
        }

        if (! $engineOn) {
            return ['idle', __('Engine off')];
        }

        return match ($lastTickStatus) {
            'ok' => ['running', __('Running')],
            'failed' => ['erroring', __('Erroring')],
            default => ['pending', __('Pending')],
        };
    }

    /**
     * Overwrite the whole list — the page edits its own in-memory copy and
     * writes it back in one go.
     *
     * @param  array<array-key, array<string, mixed>>  $workers
     */
    public function persist(Site $site, array $workers): void
    {
        $config = $this->config($site);
        $config['workers'] = array_values($workers);

        $this->write($site, $config);
    }

    /**
     * Merge caller-supplied values over a base row, coercing each field the
     * same way however it arrived (form, API, CLI).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function shape(array $attributes, array $base): array
    {
        $policy = (string) ($attributes['restart_policy'] ?? $base['restart_policy']);

        return [
            'id' => (string) $base['id'],
            'name' => trim((string) ($attributes['name'] ?? $base['name'])),
            'command' => trim((string) ($attributes['command'] ?? $base['command'])),
            'concurrency' => max(1, (int) ($attributes['concurrency'] ?? $base['concurrency'])),
            'restart_policy' => in_array($policy, self::RESTART_POLICIES, true) ? $policy : 'on-failure',
            'enabled' => (bool) ($attributes['enabled'] ?? $base['enabled']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Site $site): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];

        return is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function write(Site $site, array $config): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['serverless'] = $config;

        $site->update(['meta' => $meta]);
        $site->refresh();
    }
}
