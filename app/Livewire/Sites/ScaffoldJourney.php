<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\RunLaravelScaffoldJob;
use App\Jobs\RunWordPressScaffoldJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\Scaffold\ScaffoldStep;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Live progress view for an in-flight scaffold pipeline.
 *
 * Renders meta.scaffold.steps[] from the Site row, polled at 2s
 * intervals while the pipeline is running. Reveals the generated
 * admin password once on success (Q7); offers a reset-and-retry
 * button on failure (Q9, three-attempt cap).
 */
#[Layout('layouts.app')]
class ScaffoldJourney extends Component
{
    public Server $server;

    public Site $site;

    public bool $passwordRevealed = false;

    public function mount(Server $server, Site $site): void
    {
        $this->authorize('view', $site);
        $this->server = $server;
        $this->site = $site;

        if (! $this->isScaffoldedSite()) {
            abort(404);
        }
    }

    public function render(): View
    {
        $this->site->refresh();
        $steps = $this->steps();

        return view('livewire.sites.scaffold-journey', [
            'steps' => $steps,
            'isRunning' => $this->isRunning(),
            'isFailed' => $this->isFailed(),
            'isCompleted' => $this->isCompleted(),
            'attemptCount' => (int) ($this->site->meta['scaffold']['attempt_count'] ?? 1),
            'canRetry' => $this->canRetry(),
            'failedStep' => collect($steps)->firstWhere('state', ScaffoldStep::STATE_FAILED),
        ]);
    }

    public function revealPassword(): void
    {
        // PR 17 (re-auth-required reveal) hardens this; v1 just gates
        // by org admin so non-admins can't read the secret from the UI.
        $user = Auth::user();
        $org = $this->site->organization;
        if ($org === null || ! $org->hasAdminAccess($user)) {
            $this->addError('reveal', __('Only org owners or admins can reveal the admin password.'));

            return;
        }

        $this->passwordRevealed = true;
    }

    /**
     * Reset-and-retry per Q9: destroys server-side artifacts (TBD —
     * a ScaffoldCleanup action lands in PR 8) and re-dispatches the
     * pipeline from step 1. v1 just clears the steps + re-flips status
     * + re-dispatches; cleanup of files / DB lands alongside the DNS
     * teardown work.
     */
    public function retry(): void
    {
        if (! $this->canRetry()) {
            return;
        }

        $framework = $this->site->meta['scaffold']['framework'] ?? null;
        if (! in_array($framework, ['laravel', 'wordpress'], true)) {
            return;
        }

        $meta = $this->site->meta;
        $meta['scaffold']['attempt_count'] = ((int) ($meta['scaffold']['attempt_count'] ?? 1)) + 1;
        $meta['scaffold']['steps'] = []; // Pipeline re-initialises on run.
        unset($meta['scaffold']['admin_password']); // Generated fresh.
        $this->site->meta = $meta;
        $this->site->status = Site::STATUS_SCAFFOLDING;
        $this->site->save();

        if ($framework === 'laravel') {
            RunLaravelScaffoldJob::dispatch($this->site->id);
        } else {
            RunWordPressScaffoldJob::dispatch($this->site->id);
        }
    }

    private function isScaffoldedSite(): bool
    {
        return is_array($this->site->meta['scaffold'] ?? null);
    }

    private function isRunning(): bool
    {
        return $this->site->status === Site::STATUS_SCAFFOLDING;
    }

    private function isFailed(): bool
    {
        return $this->site->status === Site::STATUS_SCAFFOLD_FAILED;
    }

    private function isCompleted(): bool
    {
        return $this->site->status === Site::STATUS_PENDING
            && collect($this->steps())->every(fn ($s) => $s['state'] === ScaffoldStep::STATE_COMPLETED);
    }

    /**
     * Q9: three-attempt cap. After three failed attempts, replace the
     * retry button with "Delete site and start fresh" (UI surfacing
     * lives in the view; this method gates the retry action server-side).
     */
    private function canRetry(): bool
    {
        return $this->isFailed()
            && (int) ($this->site->meta['scaffold']['attempt_count'] ?? 1) < 3;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function steps(): array
    {
        return $this->site->meta['scaffold']['steps'] ?? [];
    }
}
