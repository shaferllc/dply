<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Read-only panel: last dply.yaml snapshot synced for a BYO VM site.
 */
class ByoRepoConfigPanel extends Component
{
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

    public function render(): View
    {
        return view('livewire.sites.byo-repo-config-panel', [
            'snapshot' => $this->snapshot(),
        ]);
    }
}
