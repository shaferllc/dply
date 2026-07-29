<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Models\FunctionAction;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Backfills `function_actions` for serverless Sites created before the
 * package model existed.
 *
 * Each existing serverless function-Site held exactly one OpenWhisk action,
 * with its config smeared across `Site.meta.serverless`. This command gives
 * every such Site one `kind=code` action row built from that config, then
 * points the Site's historic `function_invocations` at it.
 *
 * The command is idempotent and forward-only — it never deletes or resets
 * anything, and a Site that already has an action row is left untouched, so
 * it is safe to re-run. Use `--dry-run` to preview without writing.
 */
class BackfillFunctionActionsCommand extends Command
{
    protected $signature = 'serverless:backfill-function-actions {--dry-run : Report what would change without writing}';

    protected $description = 'Create one function_actions row per legacy serverless Site and link its invocations.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no rows will be written.');
        }

        $sites = Site::query()
            ->whereHas('server', function ($query): void {
                $query->where('meta->host_kind', Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS)
                    ->orWhere('meta->host_kind', Server::HOST_KIND_AWS_LAMBDA);
            })
            ->get();

        $created = 0;
        $skipped = 0;
        $linkedInvocations = 0;

        foreach ($sites as $site) {
            $action = FunctionAction::query()->where('site_id', $site->id)->first();

            if ($action === null) {
                $attributes = $this->actionAttributesFor($site);
                $this->line(sprintf(
                    '  %s site %s → action "%s" (%s)',
                    $dryRun ? 'would create' : 'create',
                    $site->id,
                    $attributes['name'],
                    $attributes['runtime'] !== '' ? $attributes['runtime'] : 'no runtime',
                ));

                if ($dryRun) {
                    $created++;

                    continue;
                }

                $action = FunctionAction::query()->create($attributes);
                $created++;
            } else {
                $skipped++;
            }

            // Only link invocations when the Site has exactly one action —
            // anything else means the package model has already moved on and
            // a blind link would mis-attribute rows.
            if (FunctionAction::query()->where('site_id', $site->id)->count() !== 1) {
                continue;
            }

            $unlinked = FunctionInvocation::query()
                ->where('site_id', $site->id)
                ->whereNull('function_action_id')
                ->count();

            if ($unlinked === 0) {
                continue;
            }

            $this->line(sprintf(
                '  %s %d invocation(s) for site %s',
                $dryRun ? 'would link' : 'link',
                $unlinked,
                $site->id,
            ));

            if (! $dryRun) {
                FunctionInvocation::query()
                    ->where('site_id', $site->id)
                    ->whereNull('function_action_id')
                    ->update(['function_action_id' => $action->id]);
            }

            $linkedInvocations += $unlinked;
        }

        $this->info(sprintf(
            '%s %d action(s), %d already present, %s %d invocation(s).',
            $dryRun ? 'Would create' : 'Created',
            $created,
            $skipped,
            $dryRun ? 'would link' : 'linked',
            $linkedInvocations,
        ));

        return self::SUCCESS;
    }

    /**
     * Build the `function_actions` attributes for a legacy serverless Site
     * from its `meta.serverless` config and normalised limits.
     *
     * @return array<string, mixed>
     */
    private function actionAttributesFor(Site $site): array
    {
        $config = $site->serverlessConfig();
        $limits = $site->serverlessLimits();

        return [
            'site_id' => $site->id,
            'name' => $this->resolveActionName($site, $config),
            'kind' => FunctionAction::KIND_CODE,
            'runtime' => trim((string) ($config['runtime'] ?? '')),
            'entrypoint' => trim((string) ($config['entrypoint'] ?? '')),
            'memory_mb' => $limits['memory'],
            'timeout_ms' => $limits['timeout'],
            'concurrency' => $limits['concurrency'],
            'url' => trim((string) ($config['action_url'] ?? '')) ?: null,
        ];
    }

    /**
     * Resolve the OpenWhisk action name — mirroring FunctionInvoker's lookup
     * (explicit name, else the trailing URL segment), then falling back to
     * the Site's slug and finally its id so the row always has a name.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveActionName(Site $site, array $config): string
    {
        foreach ([$config['action_name'] ?? '', $config['function_name'] ?? ''] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $url = trim((string) ($config['action_url'] ?? ''));
        if ($url !== '') {
            return basename(rtrim($url, '/'));
        }

        return trim((string) $site->slug) ?: (string) $site->id;
    }
}
