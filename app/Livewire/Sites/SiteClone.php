<?php

namespace App\Livewire\Sites;

use App\Jobs\CloneSiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\Clone\SiteCloneDestinationValidator;
use App\Support\HostnameValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SiteClone extends Component
{
    public Server $server;

    public Site $site;

    public string $clone_hostname = '';

    public string $clone_site_name = '';

    public string $destination_server_id = '';

    /**
     * @var Collection<int, Server>
     */
    public $destinationServers;

    public function mount(Server $server, Site $site): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }

        $org = auth()->user()->currentOrganization();
        abort_if($org === null, 403);
        abort_if((string) $server->organization_id !== (string) $org->id, 404);

        $this->authorize('clone', $site);

        $this->server = $server;
        $this->site = $site;
        $this->site->load('domains');

        $this->clone_hostname = (string) ($this->site->primaryDomain()?->hostname ?? '');
        $this->clone_site_name = $this->site->name.' (clone)';

        $this->destinationServers = SiteCloneDestinationValidator::destinationServersForUser($org);
    }

    public function startClone(): mixed
    {
        $this->authorize('clone', $this->site);

        $org = auth()->user()->currentOrganization();
        abort_if($org === null, 403);

        $this->validate([
            'clone_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_domains', 'hostname'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail(__('Enter a valid domain name like app.example.com.'));
                    }
                },
            ],
            'clone_site_name' => ['required', 'string', 'max:120'],
            'destination_server_id' => ['required', 'string', Rule::exists('servers', 'id')],
        ]);

        $dest = Server::query()->where('organization_id', $org->id)->findOrFail($this->destination_server_id);

        try {
            SiteCloneDestinationValidator::validateOrFail(auth()->user(), $this->site->fresh(['server']), $dest, $this->clone_hostname);
        } catch (\RuntimeException $e) {
            $this->addError('destination_server_id', $e->getMessage());

            return null;
        }

        CloneSiteJob::dispatch(
            $this->site->id,
            $dest->id,
            strtolower(trim($this->clone_hostname)),
            trim($this->clone_site_name),
            (string) auth()->id(),
        );

        session()->flash('success', __('Site clone started. This can take a while for large trees; refresh the destination server’s site list for the new site when provisioning finishes.'));

        return redirect()->route('servers.sites', $dest);
    }

    public function render(): View
    {
        return view('livewire.sites.clone');
    }
}
