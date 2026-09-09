<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings page shell. The card itself — panel head, category strip, and every
 * section's state — lives in {@see SettingsCard}, which is #[Lazy].
 *
 * This component stays deliberately thin. Its view is the whole workspace page
 * (sidebar, breadcrumbs, banner, command palette), so anything that re-renders
 * it re-renders all of that. Keeping the category out of here is what makes a
 * tab switch a small child update instead of a page-sized one; see the docblock
 * on SettingsCard for the measurements behind the split.
 *
 * Not #[Lazy]: the shell is cheap and the child serves the skeleton, so a lazy
 * parent would only add a round trip.
 */
#[Layout('layouts.app')]
class WorkspaceSettings extends Component
{
    use InteractsWithServerWorkspace;

    public function mount(Server $server): void
    {
        // Legacy ?tab= destinations that are no longer sections here. Handled on
        // the document request — reading the query directly rather than via
        // #[Url], since the category itself belongs to the child now.
        $tab = request()->query('tab');

        if ($tab === 'inventory' && Feature::active('workspace.patch_advisor')) {
            $this->redirect(route('servers.patches', $server), navigate: true);

            return;
        }

        // The per-server outbound webhook moved to the Notifications page so all
        // of a server's event delivery lives in one place.
        if ($tab === 'webhook') {
            $this->redirect(route('servers.notifications', ['server' => $server, 'tab' => 'webhooks']), navigate: true);

            return;
        }

        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-settings');
    }
}
