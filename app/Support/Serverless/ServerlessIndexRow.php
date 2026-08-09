<?php

declare(strict_types=1);

namespace App\Support\Serverless;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * View-model for the shared Serverless index list UI — built from a local
 * {@see Site} or a Production API row so both surfaces reuse the same Blade.
 */
final readonly class ServerlessIndexRow
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $manageHref,
        public bool $manageEnabled,
        public ?string $journeyHref,
        public string $status,
        public string $statusLabel,
        public string $statusBadgeClass,
        public ?string $repositoryUrl,
        public ?string $runtimeLabel,
        public ?string $deployedLabel,
        public bool $isLive,
    ) {}

    public static function fromSite(Site $site): self
    {
        $cfg = $site->serverlessConfig();
        $isLive = $site->status === Site::STATUS_FUNCTIONS_ACTIVE;
        $runtime = trim((string) ($cfg['runtime'] ?? ''));
        $lastDeployedAt = $cfg['last_deployed_at'] ?? null;
        $deployedLabel = null;
        if (is_string($lastDeployedAt) && $lastDeployedAt !== '') {
            $deployedLabel = __('Deployed :ago', ['ago' => Carbon::parse($lastDeployedAt)->diffForHumans()]);
        } else {
            $deployedLabel = __('Never deployed');
        }

        $journeyHref = $site->server_id
            ? route('serverless.journey', ['server' => $site->server_id, 'site' => $site->id])
            : null;
        // Never-live / failed first deploy → manage opens the journey (same
        // destination SiteWorkspaceController redirects sites.show to).
        $manageHref = null;
        if ($site->server_id) {
            $manageHref = (! $isLive && $site->last_deploy_at === null && $journeyHref !== null)
                ? $journeyHref
                : route('sites.show', ['server' => $site->server_id, 'site' => $site->id]);
        }

        $repo = trim((string) ($site->git_repository_url ?? ''));

        return new self(
            id: (string) $site->id,
            name: (string) $site->name,
            manageHref: $manageHref,
            manageEnabled: $manageHref !== null,
            journeyHref: $journeyHref,
            status: (string) $site->status,
            statusLabel: self::statusLabel((string) $site->status),
            statusBadgeClass: self::statusBadgeClass((string) $site->status),
            repositoryUrl: $repo !== '' ? $repo : null,
            runtimeLabel: $runtime !== '' ? $runtime : null,
            deployedLabel: $deployedLabel,
            isLive: $isLive,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromProductionApi(array $row): self
    {
        $id = (string) ($row['id'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $repo = isset($row['repository']) && is_string($row['repository']) && $row['repository'] !== ''
            ? $row['repository']
            : (isset($row['git_repository_url']) && is_string($row['git_repository_url']) && $row['git_repository_url'] !== ''
                ? $row['git_repository_url']
                : null);
        $runtime = isset($row['runtime']) && is_string($row['runtime']) && $row['runtime'] !== ''
            ? $row['runtime']
            : null;
        $lastDeployedAt = $row['last_deployed_at'] ?? null;
        $deployedLabel = null;
        if (is_string($lastDeployedAt) && $lastDeployedAt !== '') {
            $deployedLabel = __('Deployed :ago', ['ago' => Carbon::parse($lastDeployedAt)->diffForHumans()]);
        }

        return new self(
            id: $id,
            name: (string) ($row['name'] ?? $row['slug'] ?? '—'),
            manageHref: $id !== '' ? route('live.sites.show', $id) : null,
            manageEnabled: $id !== '',
            journeyHref: null,
            status: $status,
            statusLabel: self::statusLabel($status),
            statusBadgeClass: self::statusBadgeClass($status),
            repositoryUrl: $repo,
            runtimeLabel: $runtime,
            deployedLabel: $deployedLabel ?? __('Never deployed'),
            isLive: $status === Site::STATUS_FUNCTIONS_ACTIVE,
        );
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            Site::STATUS_FUNCTIONS_ACTIVE => __('Live'),
            Site::STATUS_FUNCTIONS_CONFIGURED => __('Deploying'),
            Site::STATUS_FUNCTIONS_FAILED => __('Failed'),
            default => $status !== ''
                ? (string) str($status)->replace(['_', '-'], ' ')->title()
                : __('Deploying'),
        };
    }

    private static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            Site::STATUS_FUNCTIONS_ACTIVE => 'bg-brand-forest/15 text-brand-forest',
            Site::STATUS_FUNCTIONS_CONFIGURED => 'bg-brand-sand/60 text-brand-moss',
            Site::STATUS_FUNCTIONS_FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
            default => 'bg-brand-gold/20 text-brand-ink',
        };
    }
}
