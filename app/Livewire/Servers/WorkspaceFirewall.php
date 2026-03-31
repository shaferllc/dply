<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceFirewall extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public int $new_fw_port = 80;

    public string $new_fw_protocol = 'tcp';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function addFirewallRule(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_fw_port' => 'required|integer|min:1|max:65535',
            'new_fw_protocol' => 'required|in:tcp,udp',
        ]);
        ServerFirewallRule::query()->create([
            'server_id' => $this->server->id,
            'port' => $this->new_fw_port,
            'protocol' => $this->new_fw_protocol,
            'action' => 'allow',
            'sort_order' => (int) ($this->server->firewallRules()->max('sort_order') ?? 0) + 1,
        ]);
        $this->flash_success = 'Rule saved. Click “Apply UFW rules”. Ensure SSH is allowed before enabling UFW.';
        $this->flash_error = null;
    }

    public function deleteFirewallRule(int $id): void
    {
        $this->authorize('update', $this->server);
        ServerFirewallRule::query()->where('server_id', $this->server->id)->whereKey($id)->delete();
        $this->flash_success = 'Rule removed.';
        $this->flash_error = null;
    }

    public function applyFirewall(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $out = $firewall->apply($this->server);
            $this->flash_success = 'UFW: '.Str::limit(trim($out), 1200);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['firewallRules']);

        return view('livewire.servers.workspace-firewall', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
