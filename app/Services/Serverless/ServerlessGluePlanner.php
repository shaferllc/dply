<?php

declare(strict_types=1);

namespace App\Services\Serverless;

use App\Models\Organization;
use App\Support\Serverless\ServerlessGlueRecipe;

/**
 * Merges serverless glue recipe templates with org inventory — Edge hooks,
 * OpenWhisk actions/sequences, Cloud redeploy URLs, and BYO crons.
 */
final class ServerlessGluePlanner
{
    public function __construct(
        private readonly ServerlessGlueInventory $inventory,
    ) {}

    /**
     * @return list<array{key: string, title: string, summary: string, available: bool, unavailable_reason: string|null}>
     */
    public function catalog(Organization $organization): array
    {
        $snapshot = $this->inventory->forOrganization($organization);
        $catalog = [];

        foreach (config('serverless_glue', []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            [$available, $reason] = $this->availability((string) $key, $snapshot);

            $catalog[] = [
                'key' => (string) $key,
                'title' => (string) ($definition['title'] ?? $key),
                'summary' => (string) ($definition['summary'] ?? ''),
                'available' => $available,
                'unavailable_reason' => $reason,
            ];
        }

        return $catalog;
    }

    public function recipe(Organization $organization, string $key): ?ServerlessGlueRecipe
    {
        $definition = config("serverless_glue.{$key}");
        if (! is_array($definition)) {
            return null;
        }

        $snapshot = $this->inventory->forOrganization($organization);
        [$available, $unavailableReason] = $this->availability($key, $snapshot);

        return new ServerlessGlueRecipe(
            key: $key,
            title: (string) ($definition['title'] ?? $key),
            summary: (string) ($definition['summary'] ?? ''),
            docSlug: is_string($definition['doc_slug'] ?? null) ? $definition['doc_slug'] : null,
            available: $available,
            unavailableReason: $unavailableReason,
            steps: $this->buildSteps($key, $definition, $snapshot),
            resources: $this->resources($key, $snapshot),
            gaps: $this->gaps($key, $snapshot),
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{0: bool, 1: string|null}
     */
    private function availability(string $key, array $snapshot): array
    {
        $requires = (string) (config("serverless_glue.{$key}.requires") ?? '');

        $hasHosts = count($snapshot['functions_hosts'] ?? []) > 0;
        $codeCount = count($snapshot['code_actions'] ?? []);
        $hookCount = count($snapshot['edge_hooks'] ?? []);
        $cloudCount = count($snapshot['cloud_sites'] ?? []);
        $cronCount = count($snapshot['byo_crons'] ?? []);

        return match ($requires) {
            'edge_hooks_and_actions' => $this->gate(
                $hasHosts && $codeCount >= 2 && $hookCount > 0,
                $this->missingReason(
                    [
                        ! $hasHosts => __('Add a DigitalOcean Functions host (serverless function).'),
                        $codeCount < 2 => __('Define at least two code actions on the functions namespace.'),
                        $hookCount === 0 => __('Mint an Edge deploy hook on at least one Edge site.'),
                    ],
                ),
            ),
            'cloud_and_actions' => $this->gate(
                $hasHosts && $codeCount >= 2 && $cloudCount > 0,
                $this->missingReason(
                    [
                        ! $hasHosts => __('Add a DigitalOcean Functions host.'),
                        $codeCount < 2 => __('Define at least two code actions for a sequence.'),
                        $cloudCount === 0 => __('Provision a Cloud app to target for redeploy.'),
                    ],
                ),
            ),
            'byo_cron_and_actions' => $this->gate(
                $hasHosts && $codeCount >= 2 && $cronCount > 0,
                $this->missingReason(
                    [
                        ! $hasHosts => __('Add a DigitalOcean Functions host.'),
                        $codeCount < 2 => __('Define at least two code actions for a sequence.'),
                        $cronCount === 0 => __('Add a BYO server cron job as the callback target.'),
                    ],
                ),
            ),
            'full_stack' => $this->gate(
                $hasHosts && $codeCount >= 3 && $hookCount > 0 && $cloudCount > 0 && $cronCount > 0,
                $this->missingReason(
                    [
                        ! $hasHosts => __('Add a DigitalOcean Functions host.'),
                        $codeCount < 3 => __('Define at least three code actions for a multi-step pipeline.'),
                        $hookCount === 0 => __('Mint an Edge deploy hook.'),
                        $cloudCount === 0 => __('Provision a Cloud app.'),
                        $cronCount === 0 => __('Add a BYO cron callback target.'),
                    ],
                ),
            ),
            default => [false, __('Unknown recipe.')],
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function gaps(string $key, array $snapshot): array
    {
        $gaps = [];
        $codeCount = count($snapshot['code_actions'] ?? []);
        $seqCount = count($snapshot['sequences'] ?? []);

        if ($codeCount > 0 && $seqCount === 0) {
            $gaps[] = __('No OpenWhisk sequences defined yet — use the Sequences tab to create one.');
        }

        if ($key === 'edge_webhook_pipeline' && count($snapshot['edge_sites'] ?? []) > 0 && count($snapshot['edge_hooks'] ?? []) === 0) {
            $gaps[] = __('Edge sites exist but no deploy hooks are minted yet.');
        }

        if ($key === 'multi_engine_orchestration' && $codeCount >= 2 && $codeCount < 3) {
            $gaps[] = __('Consider a third code action for Cloud + BYO fan-out in one sequence.');
        }

        return $gaps;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $snapshot
     * @return list<array{text: string, href: string|null, link_label: string|null}>
     */
    private function buildSteps(string $key, array $definition, array $snapshot): array
    {
        $rawSteps = is_array($definition['steps'] ?? null) ? $definition['steps'] : [];
        $steps = [];

        foreach (array_values($rawSteps) as $index => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            [$href, $linkLabel] = $this->stepLink($key, $index, $snapshot);

            $steps[] = [
                'text' => $text,
                'href' => $href,
                'link_label' => $linkLabel,
            ];
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{kind: string, label: string, href: string|null, meta: string|null}>
     */
    private function resources(string $key, array $snapshot): array
    {
        return match ($key) {
            'edge_webhook_pipeline' => [
                ...$this->resourceRows(__('Edge hook'), $snapshot['edge_hooks'] ?? [], 'site_name', 'hook_name'),
                ...$this->resourceRows(__('Code action'), $snapshot['code_actions'] ?? [], 'site_name', 'name'),
            ],
            'cloud_redeploy_chain' => [
                ...$this->resourceRows(__('Cloud app'), $snapshot['cloud_sites'] ?? [], null, 'name', 'live_url'),
                ...$this->resourceRows(__('Code action'), $snapshot['code_actions'] ?? [], 'site_name', 'name'),
            ],
            'byo_cron_callback' => [
                ...$this->resourceRows(__('BYO cron'), $snapshot['byo_crons'] ?? [], 'server_name', 'command', 'cron_expression'),
                ...$this->resourceRows(__('Code action'), $snapshot['code_actions'] ?? [], 'site_name', 'name'),
            ],
            'multi_engine_orchestration' => [
                ...$this->resourceRows(__('Edge hook'), $snapshot['edge_hooks'] ?? [], 'site_name', 'hook_name'),
                ...$this->resourceRows(__('Code action'), $snapshot['code_actions'] ?? [], 'site_name', 'name'),
                ...$this->resourceRows(__('Cloud app'), $snapshot['cloud_sites'] ?? [], null, 'name'),
                ...$this->resourceRows(__('BYO cron'), $snapshot['byo_crons'] ?? [], 'server_name', 'command'),
            ],
            default => [],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{kind: string, label: string, href: string|null, meta: string|null}>
     */
    private function resourceRows(
        string $kind,
        array $rows,
        ?string $prefixKey,
        string $labelKey,
        ?string $metaKey = null,
    ): array {
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = (string) ($row[$labelKey] ?? '');
            if ($prefixKey !== null && ($row[$prefixKey] ?? '') !== '') {
                $label = (string) $row[$prefixKey].' · '.$label;
            }

            $meta = $metaKey !== null ? ($row[$metaKey] ?? null) : null;

            $out[] = [
                'kind' => $kind,
                'label' => $label,
                'href' => is_string($row['href'] ?? null) ? $row['href'] : null,
                'meta' => is_string($meta) && $meta !== '' ? $meta : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{0: string|null, 1: string|null}
     */
    private function stepLink(string $key, int $index, array $snapshot): array
    {
        return match ($key) {
            'edge_webhook_pipeline' => match ($index) {
                1, 2 => [route('serverless.glue', ['tab' => 'sequences']), __('Open sequence builder')],
                3 => $this->firstHref($snapshot['edge_hooks'] ?? [], __('Open deploy triggers')),
                4 => $this->firstHref($snapshot['code_actions'] ?? [], __('Open Platform tab')),
                default => [null, null],
            },
            'cloud_redeploy_chain' => match ($index) {
                0 => $this->firstHref($snapshot['cloud_sites'] ?? [], __('Open Cloud app')),
                2 => [route('serverless.glue', ['tab' => 'sequences']), __('Open sequence builder')],
                default => [null, null],
            },
            'byo_cron_callback' => match ($index) {
                0 => $this->firstHref($snapshot['byo_crons'] ?? [], __('Open BYO cron')),
                2 => [route('serverless.glue', ['tab' => 'sequences']), __('Open sequence builder')],
                default => [null, null],
            },
            'multi_engine_orchestration' => match ($index) {
                0 => [route('serverless.glue'), __('Review glue inventory')],
                1 => [route('serverless.glue', ['tab' => 'sequences']), __('Open sequence builder')],
                3 => $this->firstHref($snapshot['edge_hooks'] ?? [], __('Open deploy triggers')),
                default => [null, null],
            },
            default => [null, null],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string|null, 1: string|null}
     */
    private function firstHref(array $rows, string $label): array
    {
        $first = $rows[0] ?? null;
        if (! is_array($first)) {
            return [null, null];
        }

        $href = $first['href'] ?? null;

        return [is_string($href) && $href !== '' ? $href : null, $label];
    }

    /**
     * @param  array<bool, string>  $reasons
     */
    private function missingReason(array $reasons): string
    {
        foreach ($reasons as $missing => $message) {
            if ($missing) {
                return $message;
            }
        }

        return __('Prerequisites not met.');
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function gate(bool $available, string $reason): array
    {
        return $available ? [true, null] : [false, $reason];
    }
}
