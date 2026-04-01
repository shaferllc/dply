<?php

namespace App\Livewire\Scripts;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Organization;
use App\Models\Script;
use App\Models\Server;
use App\Services\Scripts\ScriptRemoteRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    use ConfirmsActionWithModal;

    public Script $script;

    public string $name = '';

    public string $content = '';

    public string $run_as_user = '';

    public bool $use_as_default_for_new_sites = false;

    /** @var list<string> */
    public array $selected_server_ids = [];

    public ?string $run_output = null;

    public ?string $flash_success = null;

    public function mount(Script $script): void
    {
        $org = Auth::user()->currentOrganization();
        if (! $org || (string) $script->organization_id !== (string) $org->id) {
            abort(404);
        }

        $this->authorize('update', $script);

        $this->script = $script;
        $this->name = $script->name;
        $this->content = $script->content;
        $this->run_as_user = (string) ($script->run_as_user ?? '');
        $this->use_as_default_for_new_sites = $script->isDefaultForOrganization($org);
    }

    public function save(): void
    {
        $this->authorize('update', $this->script);

        $org = Auth::user()->currentOrganization();
        if (! $org) {
            abort(403);
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:512000'],
            'run_as_user' => ['nullable', 'string', 'max:64'],
        ], [], [
            'name' => __('Label'),
            'content' => __('Content'),
            'run_as_user' => __('Run as user'),
        ]);

        $this->script->update([
            'name' => $this->name,
            'content' => $this->content,
            'run_as_user' => $this->run_as_user !== '' ? $this->run_as_user : null,
        ]);

        $this->syncDefaultForNewSites($org);

        $this->flash_success = __('Script saved.');
    }

    protected function syncDefaultForNewSites(Organization $org): void
    {
        if ($this->use_as_default_for_new_sites) {
            $org->update(['default_site_script_id' => $this->script->id]);
        } elseif ((string) $org->default_site_script_id === (string) $this->script->id) {
            $org->update(['default_site_script_id' => null]);
        }
    }

    public function deleteScript(): mixed
    {
        $this->authorize('delete', $this->script);

        $org = Auth::user()->currentOrganization();
        if ($org && (string) $org->default_site_script_id === (string) $this->script->id) {
            $org->update(['default_site_script_id' => null]);
        }

        $this->script->delete();

        return $this->redirect(route('scripts.index'), navigate: true);
    }

    public function toggleAllServers(): void
    {
        $ids = $this->serversForRun()->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (count($this->selected_server_ids) === count($ids)) {
            $this->selected_server_ids = [];
        } else {
            $this->selected_server_ids = $ids;
        }
    }

    public function runOnServers(ScriptRemoteRunner $runner): void
    {
        $this->authorize('runOnServers', $this->script);

        $this->resetErrorBag();
        $this->run_output = null;

        $servers = $this->serversForRun();
        $allowedIds = $servers->pluck('id')->all();

        $this->validate([
            'selected_server_ids' => ['required', 'array', 'min:1'],
            'selected_server_ids.*' => ['string', Rule::in($allowedIds)],
        ], [], [
            'selected_server_ids' => __('servers'),
        ]);

        $chunks = [];
        foreach ($this->selected_server_ids as $sid) {
            $server = $servers->firstWhere('id', (string) $sid);
            if (! $server instanceof Server) {
                continue;
            }
            $this->authorize('update', $server);

            audit_log($this->script->organization, Auth::user(), 'script.run', $this->script, null, ['server_id' => $server->id]);

            $result = $runner->run($this->script, $server);
            $label = $server->name.($server->ip_address ? ' ('.$server->ip_address.')' : '');
            if ($result['ok']) {
                $chunks[] = "=== {$label} ===\n".($result['output'] !== '' ? $result['output'] : __('(no output)'));
            } else {
                $err = $result['error'] ?? __('Unknown error');
                $chunks[] = "=== {$label} ===\n".__('Error:').' '.$err;
            }
        }

        $this->run_output = implode("\n\n", $chunks);
    }

    /**
     * @return Collection<int, Server>
     */
    protected function serversForRun()
    {
        $org = Auth::user()->currentOrganization();
        if (! $org) {
            return collect();
        }

        return $org->servers()
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $s) => Auth::user()->can('update', $s));
    }

    public function render(): View
    {
        return view('livewire.scripts.edit', [
            'servers' => $this->serversForRun(),
            'organization' => Auth::user()->currentOrganization(),
        ]);
    }
}
