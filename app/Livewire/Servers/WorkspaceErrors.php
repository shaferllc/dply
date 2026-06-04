<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\SurfacesErrorStream;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\ErrorEvent;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The server's "Errors" view — a chronological stream of every failure on the
 * box (server infra + roll-up of its hosted sites), backed by the dedicated
 * {@see ErrorEvent} table. Stream behaviour lives in {@see SurfacesErrorStream}.
 */
#[Layout('layouts.app')]
class WorkspaceErrors extends Component
{
    use ConfirmsActionWithModal;
    use InteractsWithServerWorkspace;
    use SurfacesErrorStream;
    use WithPagination;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    /** Everything on the box: server-owned errors + hosted sites' errors. */
    protected function scopedErrors(): Builder
    {
        return ErrorEvent::query()->forServer((string) $this->server->id);
    }

    protected function authorizeErrorAccess(): void
    {
        $this->authorize('update', $this->server);
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-errors');
    }
}
