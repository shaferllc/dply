<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesSiteBindings;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Livewire\Sites\Concerns\ManagesSiteEnvRequirements;
use App\Livewire\Sites\Concerns\ManagesSiteReleaseHealth;
use App\Livewire\Sites\Concerns\SurfacesDeploymentRemediation;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * The Resources-tab graph extracted into its own (nested) Livewire component.
 *
 * It lived as a partial inside the big {@see Settings} component, which meant
 * every Settings round-trip (modal keystrokes, polls, unrelated state) re-ran
 * this fairly heavy view (binding catalog + reachability + the icon graph) even
 * though none of that changed. As a child component Livewire only re-renders it
 * when ITS own state changes (a binding attach/detach/verify or modal field),
 * not when the parent re-renders.
 *
 * Trait set mirrors {@see SiteEnvironment} (the other standalone host of the
 * binding modal): everything the graph + binding modal call resolves through
 * {@see ManagesSiteBindings} (actions/credentials/mail/storage/verify), the
 * confirm modal, console-action banners, and toasts. The one difference vs the
 * Settings host: it does NOT pull the env-vars trait, so attaching a binding
 * toasts "Connected" rather than eagerly pushing the .env to the box — the
 * binding's injected vars still apply on the next deploy (where they take
 * effect for PHP anyway).
 */
class ResourceMap extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesSiteBindings;
    // The binding Test/Validate/Fix actions seed + watch a queued console action
    // (SSH probe) and surface its banner; these traits supply seedQueuedConsoleAction,
    // consoleActionSubject, and the dismiss/remediation plumbing (same recipe as
    // SiteEnvironment). No mount hooks, so they don't load env state here.
    use ManagesSiteEnvRequirements;
    // Release-health card: detects php-fpm serving a stale release after a
    // deploy (OPcache symlink pin) and offers a one-click flush/re-sync.
    use ManagesSiteReleaseHealth;
    use SurfacesDeploymentRemediation;
    use WatchesConsoleActionOutcomes;

    public Server $server;

    public Site $site;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
    }

    public function render(): View
    {
        return view('livewire.sites.settings.partials.resource-map');
    }
}
