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
     * @param  array<string, mixed>  $row  Production `/sites` (or cloud) inventory row
     */
    public static function fromProductionApi(array $row, bool $isPreviewChild = false): self
    {
        $id = (string) ($row['id'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $liveUrl = null;
        foreach (['live_url', 'visit_url'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                $liveUrl = $row[$key];
                break;
            }
        }
        $hostname = isset($row['hostname']) && is_string($row['hostname']) && $row['hostname'] !== ''
            ? $row['hostname']
            : (isset($row['primary_hostname']) && is_string($row['primary_hostname']) && $row['primary_hostname'] !== ''
                ? $row['primary_hostname']
                : null);
        if ($hostname === null && $liveUrl !== null) {
            $parsed = parse_url($liveUrl, PHP_URL_HOST);
            $hostname = is_string($parsed) && $parsed !== '' ? $parsed : null;
        }

        $repo = isset($row['repository']) && is_string($row['repository']) && $row['repository'] !== ''
            ? $row['repository']
            : (isset($row['git_repo_label']) && is_string($row['git_repo_label']) && $row['git_repo_label'] !== ''
                ? $row['git_repo_label']
                : null);
        $branch = isset($row['branch']) && is_string($row['branch']) && $row['branch'] !== ''
            ? $row['branch']
            : (isset($row['git_branch']) && is_string($row['git_branch']) && $row['git_branch'] !== ''
                ? $row['git_branch']
                : null);
        $image = isset($row['container_image']) && is_string($row['container_image']) && $row['container_image'] !== ''
            ? $row['container_image']
            : null;
        $isSource = $repo !== null || (bool) ($row['is_source'] ?? false);
        $sourceLabel = $isSource
            ? ($repo ?? '?').($branch !== null ? '@'.$branch : '')
            : $image;
        $isPreview = (bool) ($row['is_preview'] ?? false)
            || filled($row['preview_parent_site_id'] ?? null)
            || filled($row['preview_branch'] ?? null);

        return new self(
            id: $id,
            name: (string) ($row['name'] ?? $row['slug'] ?? '—'),
            manageHref: $id !== '' ? route('live.sites.show', $id) : null,
            manageEnabled: $id !== '',
            status: $status,
            statusLabel: self::statusLabel($status),
            statusBadgeClass: self::statusBadgeClass($status),
            statusPulse: self::statusPulse($status),
            sourceLabel: $sourceLabel,
            isSourceMode: $isSource,
            region: isset($row['region']) && is_string($row['region']) && $row['region'] !== ''
                ? $row['region']
                : (isset($row['container_region']) && is_string($row['container_region']) && $row['container_region'] !== ''
                    ? $row['container_region']
                    : null),
            hostname: $hostname,
            liveUrl: $liveUrl,
            isPreviewChild: $isPreviewChild || $isPreview,
            previewBranch: isset($row['preview_branch']) && is_string($row['preview_branch'])
                ? $row['preview_branch']
                : ($isPreview ? ($branch ?? __('Preview')) : null),
            previewPrNumber: isset($row['preview_pr_number']) && is_numeric($row['preview_pr_number'])
                ? (int) $row['preview_pr_number']
                : null,
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
