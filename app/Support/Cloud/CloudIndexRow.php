<?php

declare(strict_types=1);

namespace App\Support\Cloud;

use App\Enums\SiteType;
use App\Models\Site;

/**
 * View-model for the shared Cloud apps index list UI — built from a local
 * {@see Site} or a Production API row so both surfaces reuse the same Blade.
 */
final readonly class CloudIndexRow
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $manageHref,
        public bool $manageEnabled,
        public string $status,
        public string $statusLabel,
        public string $statusBadgeClass,
        public bool $statusPulse,
        public ?string $sourceLabel,
        public bool $isSourceMode,
        public ?string $region,
        public ?string $hostname,
        public ?string $liveUrl,
        public bool $isPreviewChild,
        public ?string $previewBranch,
        public ?int $previewPrNumber,
    ) {}

    public static function fromSite(Site $site, bool $isPreviewChild = false): self
    {
        $containerMeta = is_array($site->meta['container'] ?? null) ? $site->meta['container'] : [];
        $source = is_array($containerMeta['source'] ?? null) ? $containerMeta['source'] : null;
        $isSource = $source !== null;
        $repo = is_string($source['repo'] ?? null) && $source['repo'] !== '' ? $source['repo'] : null;
        $branch = is_string($source['branch'] ?? null) && $source['branch'] !== '' ? $source['branch'] : null;
        $image = is_string($site->container_image) && $site->container_image !== ''
            ? $site->container_image
            : null;
        $sourceLabel = $isSource
            ? ($repo ?? '?').'@'.($branch ?? 'main')
            : $image;
        $liveUrl = $site->containerLiveUrl();
        $hostname = is_string($liveUrl) && $liveUrl !== ''
            ? (parse_url($liveUrl, PHP_URL_HOST) ?: null)
            : null;
        $previewPr = $containerMeta['preview_pr_number'] ?? null;
        $manageHref = $site->server
            ? route('sites.show', ['server' => $site->server, 'site' => $site])
            : null;
        $status = (string) $site->status;

        return new self(
            id: (string) $site->id,
            name: (string) $site->name,
            manageHref: $manageHref,
            manageEnabled: $manageHref !== null,
            status: $status,
            statusLabel: self::statusLabel($status),
            statusBadgeClass: self::statusBadgeClass($status),
            statusPulse: self::statusPulse($status),
            sourceLabel: $sourceLabel,
            isSourceMode: $isSource,
            region: is_string($site->container_region) && $site->container_region !== ''
                ? $site->container_region
                : null,
            hostname: is_string($hostname) && $hostname !== '' ? $hostname : null,
            liveUrl: is_string($liveUrl) && $liveUrl !== '' ? $liveUrl : null,
            isPreviewChild: $isPreviewChild,
            previewBranch: isset($containerMeta['preview_branch']) && is_string($containerMeta['preview_branch'])
                ? $containerMeta['preview_branch']
                : null,
            previewPrNumber: is_numeric($previewPr) ? (int) $previewPr : null,
        );
    }

    /**
     * Whether a Production `/sites` row belongs on the Cloud apps index.
     *
     * @param  array<string, mixed>  $row
     */
    public static function isCloudInventoryRow(array $row): bool
    {
        $type = (string) ($row['type'] ?? '');
        if ($type === SiteType::Container->value) {
            return true;
        }

        $status = (string) ($row['status'] ?? '');
        if (str_starts_with($status, 'container_')) {
            return true;
        }

        return filled($row['container_backend'] ?? null);
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            Site::STATUS_CONTAINER_ACTIVE => __('Live'),
            Site::STATUS_CONTAINER_PROVISIONING => __('Provisioning'),
            Site::STATUS_CONTAINER_FAILED => __('Failed'),
            default => str_replace('_', ' ', $status !== '' ? $status : '—'),
        };
    }

    private static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            Site::STATUS_CONTAINER_ACTIVE => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
            Site::STATUS_CONTAINER_PROVISIONING => 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
            Site::STATUS_CONTAINER_FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
            default => 'bg-brand-sand/60 text-brand-moss',
        };
    }

    private static function statusPulse(string $status): bool
    {
        return in_array($status, [
            Site::STATUS_CONTAINER_ACTIVE,
            Site::STATUS_CONTAINER_PROVISIONING,
        ], true);
    }
}
