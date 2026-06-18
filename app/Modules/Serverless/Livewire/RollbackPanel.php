<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Modules\Serverless\Jobs\RollbackServerlessFunctionJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Lists a serverless function's recent artifacts and rolls back to one.
 *
 * Each successful deploy records its artifact in serverless.artifact_history;
 * picking an older entry re-deploys that exact zip — no rebuild — so a bad
 * deploy can be reverted in one click.
 */
class RollbackPanel extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    private function site(): Site
    {
        return Site::findOrFail($this->siteId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(Site $site): array
    {
        $history = $site->serverlessConfig()['artifact_history'] ?? [];

        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }

    public function rollback(int $index): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $history = $this->history($site);
        $entry = $history[$index] ?? null;
        $artifactPath = is_array($entry) ? (string) ($entry['artifact_path'] ?? '') : '';

        // Index 0 is the live deploy — rolling back to it is a no-op.
        if ($index < 1 || $artifactPath === '') {
            $this->toastError(__('Pick an earlier deploy to roll back to.'));

            return;
        }

        if (! is_file($artifactPath)) {
            $this->toastError(__('That artifact is no longer available on disk.'));

            return;
        }

        RollbackServerlessFunctionJob::dispatch($site->id, $artifactPath);
        $this->toastSuccess(__('Rolling back — re-deploying the selected artifact.'));
    }

    public function render(): View
    {
        $history = $this->history($this->site());

        return view('livewire.serverless.rollback-panel', [
            'history' => $history,
        ]);
    }
}
