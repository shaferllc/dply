<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Models\Site;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Serverless\Services\ServerlessAssetGuardrail;
use App\Modules\Serverless\Services\ServerlessAssetGuardrailStatus;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily evaluator for each managed function's monthly asset allowance.
 *
 *   php artisan dply:serverless:evaluate-asset-guardrails
 *   php artisan dply:serverless:evaluate-asset-guardrails --site=01ks...
 *   php artisan dply:serverless:evaluate-asset-guardrails --dry-run
 *
 * Recomputes state from ServerlessUsageSnapshot totals, persists it onto
 * meta.serverless.assets.guardrail, and fires `serverless.assets.over_budget`
 * only on transitions INTO warn/over.
 *
 * Purely informational: nothing here throttles or blocks delivery. Storage is
 * already bounded at deploy time by the publisher's pre-flight check, and
 * cutting off egress would break a paying customer's site in front of their
 * users over a quota. Mirrors {@see \App\Modules\Edge\Console\EvaluateEdgeGuardrailsCommand}.
 */
class EvaluateServerlessAssetGuardrailsCommand extends Command
{
    protected $signature = 'dply:serverless:evaluate-asset-guardrails
                            {--site= : Evaluate a single site by ID instead of every managed function}
                            {--dry-run : Compute + report without persisting or notifying}';

    protected $description = 'Evaluate per-function asset allowances and notify on warn/over transitions.';

    public function handle(ServerlessAssetGuardrail $guardrail, NotificationPublisher $notifier): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $siteId = $this->option('site');

        $query = Site::query()
            ->where('serverless_backend', Site::SERVERLESS_BACKEND_DPLY)
            ->whereIn('status', [Site::STATUS_FUNCTIONS_ACTIVE, Site::STATUS_FUNCTIONS_CONFIGURED]);

        if (is_string($siteId) && $siteId !== '') {
            $query->whereKey($siteId);
        }

        $count = 0;
        $transitions = 0;

        $query->chunkById(50, function ($sites) use ($guardrail, $notifier, $dryRun, &$count, &$transitions): void {
            foreach ($sites as $site) {
                $count++;

                try {
                    $status = $guardrail->evaluate($site);

                    if ($dryRun) {
                        $this->line(sprintf(
                            '  [dry-run] %s — %s (storage %d%%, egress %d%%)',
                            $site->id,
                            $status->state,
                            $status->storagePercent(),
                            $status->egressPercent(),
                        ));

                        continue;
                    }

                    $previous = $site->updateServerlessAssetGuardrail($status->meta());

                    if ($this->shouldNotify($previous, $status->state)) {
                        $transitions++;
                        $this->dispatchTransition($notifier, $site, $status);
                    }
                } catch (Throwable $e) {
                    $this->warn(sprintf('  ! %s — evaluator failed: %s', $site->id, $e->getMessage()));
                }
            }
        });

        $this->info(sprintf(
            '%s %d function(s)%s',
            $dryRun ? '[dry-run] evaluated' : 'Evaluated',
            $count,
            $dryRun ? '' : ", {$transitions} transition(s) notified",
        ));

        return self::SUCCESS;
    }

    /**
     * Fire only when the state has just moved INTO warn or over. Recoveries
     * stay silent — a "you're back under quota" alert annoys more than it
     * helps, and the workspace banner clears itself.
     */
    private function shouldNotify(?string $previous, string $current): bool
    {
        if (! in_array($current, [
            ServerlessAssetGuardrailStatus::STATE_WARN,
            ServerlessAssetGuardrailStatus::STATE_OVER,
        ], true)) {
            return false;
        }

        return $previous !== $current;
    }

    private function dispatchTransition(
        NotificationPublisher $notifier,
        Site $site,
        ServerlessAssetGuardrailStatus $status,
    ): void {
        $title = $status->isOver()
            ? __('Function assets over the included allowance: :name', ['name' => $site->name])
            : __('Function assets approaching the included allowance: :name', ['name' => $site->name]);

        // Says plainly that the site keeps serving — the whole point of this
        // guardrail being advisory is that nobody should read it as an outage.
        $body = sprintf(
            'Storage %d%% of allowance (%s / %s) · Delivery %d%% of allowance (%s / %s). '
            .'Assets keep serving; usage past the allowance is billed.',
            $status->storagePercent(),
            $this->humanBytes($status->storageBytes),
            $this->humanBytes($status->storageBytesCap),
            $status->egressPercent(),
            $this->humanBytes($status->bytesEgress),
            $this->humanBytes($status->bytesEgressCap),
        );

        try {
            $notifier->publish(
                eventKey: 'serverless.assets.over_budget',
                subject: $site,
                title: $title,
                body: $body,
                url: route('sites.show', [
                    'server' => $site->server_id,
                    'site' => $site->id,
                ]),
                metadata: $status->meta(),
            );
        } catch (Throwable $e) {
            // Best-effort fan-out: one broken channel must not poison the run.
            $this->warn(sprintf('  ! %s — notify failed: %s', $site->id, $e->getMessage()));
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) min(count($units) - 1, floor(log($bytes, 1024)));

        return sprintf('%.1f %s', $bytes / (1024 ** $i), $units[$i]);
    }
}
