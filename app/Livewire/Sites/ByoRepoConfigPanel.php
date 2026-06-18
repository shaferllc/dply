<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\SiteProcess;
use App\Services\Deploy\Manifest\SiteManifestCodeShapeSync;
use App\Services\Sites\SiteManifestExporter;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * dply.yaml panel for a VM/BYO site: shows the last synced snapshot, the
 * manifest-managed rows (read-only), and the guarded actions surfaced by the
 * manifest sync — revert-to-dashboard (when the file was removed) and apply
 * runtime change (when the file pins a new runtime/version).
 */
class ByoRepoConfigPanel extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    /**
     * @return array{source_path: ?string, synced_at: ?string, warnings: list<string>, counts: array<string, int>}|null
     */
    public function snapshot(): ?array
    {
        $site = Site::findOrFail($this->siteId);
        $meta = is_array($site->meta) ? $site->meta : [];
        $blob = is_array($meta['byo']['repo_config'] ?? null) ? $meta['byo']['repo_config'] : null;
        if ($blob === null) {
            return null;
        }

        $snapshot = is_array($blob['snapshot'] ?? null) ? $blob['snapshot'] : [];

        return [
            'source_path' => is_string($blob['source_path'] ?? null) ? $blob['source_path'] : null,
            'synced_at' => is_string($snapshot['synced_at'] ?? null) ? $snapshot['synced_at'] : null,
            'warnings' => is_array($blob['warnings'] ?? null) ? $blob['warnings'] : [],
            'counts' => [
                'redirects' => count($snapshot['redirects'] ?? []),
                'rewrites' => count($snapshot['rewrites'] ?? []),
                'crons' => count($snapshot['byo_crons'] ?? []),
                'server_crons' => count($snapshot['byo_server_crons'] ?? []),
                'deploy_hooks' => (int) ($snapshot['deploy_hooks'] ?? 0),
                'env_declarations' => count($snapshot['env_declarations'] ?? []),
            ],
        ];
    }

    /**
     * Manifest-managed rows (read-only in the dashboard — the repo owns them).
     *
     * @return array{build: list<string>, release: list<string>, processes: list<array{name: string, command: string, scale: int}>}
     */
    public function managed(): array
    {
        $steps = SiteDeployStep::query()
            ->where('site_id', $this->siteId)
            ->where('managed_by_manifest', true)
            ->orderBy('sort_order')
            ->get();

        $processes = SiteProcess::query()
            ->where('site_id', $this->siteId)
            ->where('managed_by_manifest', true)
            ->get();

        return [
            'build' => $steps->where('phase', SiteDeployStep::PHASE_BUILD)
                ->map(fn (SiteDeployStep $s): string => (string) ($s->commandFor() ?? $s->custom_command))->values()->all(),
            'release' => $steps->where('phase', SiteDeployStep::PHASE_RELEASE)
                ->map(fn (SiteDeployStep $s): string => (string) ($s->commandFor() ?? $s->custom_command))->values()->all(),
            'processes' => $processes->map(fn (SiteProcess $p): array => [
                'name' => (string) $p->name,
                'command' => (string) $p->command,
                'scale' => (int) $p->scale,
            ])->values()->all(),
        ];
    }

    /** @return array{field: string, from: ?string, to: ?string, source: string}|null */
    public function pendingRuntimeChange(): ?array
    {
        return app(SiteManifestCodeShapeSync::class)->pendingRuntimeChange(Site::findOrFail($this->siteId));
    }

    public function removalPending(): bool
    {
        return app(SiteManifestCodeShapeSync::class)->removalPendingConfirm(Site::findOrFail($this->siteId));
    }

    public function revertToDashboard(): void
    {
        $site = Site::findOrFail($this->siteId);
        $this->authorize('update', $site);

        $result = app(SiteManifestCodeShapeSync::class)->revertToDashboard($site);
        $this->toastSuccess(sprintf(
            'Reverted to dashboard control — cleared %d step(s) and %d process(es) managed by dply.yaml.',
            $result['steps'],
            $result['processes'],
        ));
    }

    public function applyRuntimeChange(): void
    {
        $site = Site::findOrFail($this->siteId);
        $this->authorize('update', $site);

        $applied = app(SiteManifestCodeShapeSync::class)->applyPendingRuntimeChange($site);
        if ($applied === null) {
            $this->toastWarning('No pending runtime change to apply.');

            return;
        }

        $this->toastSuccess(sprintf(
            'Applying %s %s → %s — re-provisioning the runtime.',
            $applied['field'],
            $applied['from'] ?? '(unset)',
            $applied['to'] ?? '(unset)',
        ));
    }

    public function exportManifest(): StreamedResponse
    {
        $site = Site::findOrFail($this->siteId);
        $this->authorize('view', $site);

        $yaml = app(SiteManifestExporter::class)->render($site);

        return response()->streamDownload(static function () use ($yaml): void {
            echo $yaml;
        }, 'dply.yaml', ['Content-Type' => 'text/yaml; charset=UTF-8']);
    }

    public function render(): View
    {
        return view('livewire.sites.byo-repo-config-panel', [
            'snapshot' => $this->snapshot(),
            'managed' => $this->managed(),
            'pendingRuntimeChange' => $this->pendingRuntimeChange(),
            'removalPending' => $this->removalPending(),
        ]);
    }
}
