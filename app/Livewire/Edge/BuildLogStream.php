<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use App\Models\EdgeDeployment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Nested Livewire component that polls a build's local log file every 2s
 * and appends new bytes to an in-memory buffer. Renders inside the
 * deployment journey card so the operator sees what's actually
 * happening — pnpm install, vite build, etc. — without leaving the
 * workspace.
 *
 * Stops polling automatically once the deployment leaves
 * BUILDING / PUBLISHING, and renders nothing once the local log is gone
 * (publish path persists to the remote disk + cleanup removes the temp
 * file). The static build-log tab on the deployment-detail page is the
 * permanent home for finished logs.
 */
class BuildLogStream extends Component
{
    /** Soft cap on the buffer so a chatty install doesn't blow Livewire payload. */
    private const BUFFER_MAX_CHARS = 64_000;

    #[Locked]
    public string $deploymentId = '';

    public string $buffer = '';

    public int $offset = 0;

    /** Flips to false once the deployment is no longer in flight. */
    public bool $polling = true;

    /** Surfaced to the view so we can hide the panel cleanly when nothing's there yet. */
    public bool $logExists = false;

    public function mount(string $deploymentId): void
    {
        $this->deploymentId = $deploymentId;
        $this->tail();
    }

    public function tail(): void
    {
        $deployment = EdgeDeployment::query()
            ->with('site')
            ->find($this->deploymentId);

        if ($deployment === null) {
            $this->polling = false;

            return;
        }

        if ($deployment->site !== null) {
            Gate::authorize('view', $deployment->site);
        }

        $isInProgress = in_array($deployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true);

        $chunk = $deployment->readLocalBuildLogSince($this->offset, 16_000);
        $this->logExists = $chunk['exists'];

        if ($chunk['body'] !== '') {
            $this->buffer .= $chunk['body'];
            $this->offset = $chunk['offset'];

            if (strlen($this->buffer) > self::BUFFER_MAX_CHARS) {
                $this->buffer = "… (older lines trimmed) …\n".substr($this->buffer, -self::BUFFER_MAX_CHARS);
            }
        }

        // Once the build moves past in-flight we want one final tail to
        // catch any tail-end bytes, then stop polling so the page can
        // settle.
        if (! $isInProgress) {
            $this->polling = false;
        }
    }

    public function render(): View
    {
        return view('livewire.edge.build-log-stream');
    }
}
