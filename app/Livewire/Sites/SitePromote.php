<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\CloneSiteJob;
use App\Livewire\Concerns\RequiresFeature;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\Clone\SiteCloneDestinationValidator;
use App\Services\Sites\Promote\SitePromoteHostnameResolver;
use App\Services\Sites\Promote\SitePromotePlanner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Preview-first site promote — clone a VM site to another server on a
 * managed preview hostname, then follow the cutover playbook.
 */
#[Layout('layouts.app')]
class SitePromote extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.site_promote';

    public Server $server;

    public Site $site;

    public string $promote_site_name = '';

    public string $destination_server_id = '';

    /** preview | custom */
    public string $hostname_mode = 'preview';

    public string $custom_hostname = '';

    /**
     * @var Collection<int, Server>
     */
    public $destinationServers;

    public function mount(Server $server, Site $site): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }

        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);
        abort_if((string) $server->organization_id !== (string) $org->id, 404);

        $this->authorize('clone', $site);

        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);
        abort_if($site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime(), 404);

        $this->server = $server;
        $this->site = $site;
        $this->site->load(['domains', 'server']);

        $this->promote_site_name = $site->name.' (standby)';
        $this->destinationServers = SiteCloneDestinationValidator::destinationServersForUser($org)
            ->reject(fn (Server $candidate): bool => (string) $candidate->id === (string) $server->id)
            ->values();
    }

    public function startPromote(SitePromoteHostnameResolver $hostnameResolver): mixed
    {
        $this->authorize('clone', $this->site);

        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $rules = [
            'promote_site_name' => ['required', 'string', 'max:120'],
            'destination_server_id' => ['required', 'string', Rule::exists('servers', 'id')],
            'hostname_mode' => ['required', Rule::in(['preview', 'custom'])],
        ];

        if ($this->hostname_mode === 'custom') {
            $rules['custom_hostname'] = ['required', 'string', 'max:255'];
        }

        $this->validate($rules);

        $dest = Server::query()->where('organization_id', $org->id)->findOrFail($this->destination_server_id);
        $source = $this->site->fresh(['server', 'domains']);

        $sourceProduction = (string) ($source->primaryDomain()?->hostname ?? '');

        $primaryHostname = $this->hostname_mode === 'preview'
            ? $hostnameResolver->resolve($source, $dest)
            : strtolower(trim($this->custom_hostname));

        try {
            SiteCloneDestinationValidator::validateOrFail(auth()->user(), $source, $dest, $primaryHostname);
        } catch (\RuntimeException $e) {
            $this->addError('destination_server_id', $e->getMessage());

            return null;
        }

        CloneSiteJob::dispatch(
            (string) $source->id,
            (string) $dest->id,
            $primaryHostname,
            trim($this->promote_site_name),
            (string) auth()->id(),
            previewFirstPromote: $this->hostname_mode === 'preview',
            sourceProductionHostname: $sourceProduction !== '' ? $sourceProduction : null,
        );

        audit_log($org, auth()->user(), 'site.promote_started', $source, null, [
            'source_site_id' => (string) $source->id,
            'destination_server_id' => (string) $dest->id,
            'preview_hostname' => $primaryHostname,
            'source_production_hostname' => $sourceProduction,
            'hostname_mode' => $this->hostname_mode,
        ]);

        session()->flash('success', __('Standby site promote started on :server. Smoke-test the preview hostname, then follow the cutover playbook on the new site.', [
            'server' => $dest->name,
        ]));

        return redirect()->route('servers.sites', $dest);
    }

    public function render(SitePromoteHostnameResolver $hostnameResolver, SitePromotePlanner $planner): View
    {
        $previewHostname = null;
        $dest = $this->destination_server_id !== ''
            ? $this->destinationServers->firstWhere('id', $this->destination_server_id)
            : null;

        if ($dest instanceof Server && $this->hostname_mode === 'preview') {
            $previewHostname = $hostnameResolver->resolve($this->site, $dest);
        }

        $cutoverPreview = $planner->previewSteps($this->site);

        return view('livewire.sites.promote', [
            'previewHostname' => $previewHostname,
            'sourceProductionHostname' => (string) ($this->site->primaryDomain()?->hostname ?? ''),
            'cutoverPreview' => $cutoverPreview,
        ]);
    }
}
