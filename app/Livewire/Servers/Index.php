<?php

namespace App\Livewire\Servers;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Services\AwsEc2Service;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\ScalewayService;
use App\Services\UpCloudService;
use App\Services\VultrService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $search = '';

    public string $sort = 'created_at';

    /** @var string all|pending|provisioning|ready|error|disconnected */
    public string $statusFilter = '';

    /** @var string list|grid */
    public string $viewMode = 'list';

    /** Bumped when Reverb pushes server updates so the list re-queries from the database. */
    public int $serverListEpoch = 0;

    public function resetFilters(): void
    {
        $this->search = '';
        $this->sort = 'created_at';
        $this->statusFilter = '';
        $this->viewMode = 'list';
    }

    #[On('server-state-updated')]
    public function onServerStateUpdated(string $organizationId): void
    {
        $org = auth()->user()->currentOrganization();
        if (! $org || $org->id !== $organizationId) {
            return;
        }

        $this->serverListEpoch++;
    }

    public function destroy(string $serverId): void
    {
        $server = Server::query()->findOrFail($serverId);
        $this->authorize('delete', $server);

        $org = $server->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'server.deleted', $server, ['name' => $server->name], null);
        }

        if ($server->provider === ServerProvider::DigitalOcean && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $do = new DigitalOceanService($credential);
                    $do->destroyDroplet((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy DigitalOcean droplet on server delete.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($server->provider === ServerProvider::Hetzner && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $hetzner = new HetznerService($credential);
                    $hetzner->destroyInstance((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Hetzner instance on server delete.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if (in_array($server->provider, [ServerProvider::Linode, ServerProvider::Akamai], true) && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $linode = new LinodeService($credential);
                    $linode->destroyInstance((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Linode/Akamai instance on server delete.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($server->provider === ServerProvider::Vultr && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $vultr = new VultrService($credential);
                    $vultr->destroyInstance($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Vultr instance on server delete.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($server->provider === ServerProvider::Scaleway && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $scw = new ScalewayService($credential);
                    $scw->destroyServer($server->region, $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Scaleway instance on server delete.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($server->provider === ServerProvider::UpCloud && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $upcloud = new UpCloudService($credential);
                    $upcloud->destroyServer($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy UpCloud server on server delete.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($server->provider === ServerProvider::EquinixMetal && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $metal = new EquinixMetalService($credential);
                    $metal->destroyDevice($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Equinix Metal device on server delete.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($server->provider === ServerProvider::FlyIo && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            $appName = $server->meta['app_name'] ?? null;
            if ($credential && $appName) {
                try {
                    $fly = new FlyIoService($credential);
                    $fly->deleteMachine($appName, $server->provider_id);
                    $fly->deleteApp($appName);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Fly.io machine/app on server delete.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($server->provider === ServerProvider::Aws && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $aws = new AwsEc2Service($credential, $server->region);
                    $aws->terminateInstances($server->provider_id);
                    $keyName = $server->meta['key_name'] ?? null;
                    if ($keyName) {
                        try {
                            $aws->deleteKeyPair($keyName);
                        } catch (\Throwable) {
                            //
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy AWS EC2 instance on server delete.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $server->delete();
    }

    /**
     * @return Collection<string, Collection<int, Server>>
     */
    protected function groupedServers(Collection $servers): Collection
    {
        return $servers
            ->groupBy(function (Server $server): string {
                if ($server->team_id !== null && $server->relationLoaded('team') && $server->team !== null) {
                    return $server->team->name;
                }
                if ($server->organization_id !== null && $server->relationLoaded('organization') && $server->organization !== null) {
                    return $server->organization->name;
                }

                return __('Personal');
            })
            ->sortKeys();
    }

    protected function baseQuery(): ?Builder
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            return null;
        }

        $query = Server::query()
            ->with(['sites', 'organization', 'team'])
            ->withCount('sites')
            ->where(function (Builder $q) use ($org) {
                $q->where('organization_id', $org->id)
                    ->orWhere(fn (Builder $q2) => $q2->whereNull('organization_id')->where('user_id', auth()->id()));
            });

        $team = auth()->user()->currentTeam();
        if ($team) {
            $query->where('team_id', $team->id);
        }

        return $query;
    }

    protected function applyFilters(Builder $query): Builder
    {
        $term = trim($this->search);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->orWhere('provider', 'like', $like);
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return match ($this->sort) {
            'name' => $query->orderBy('name'),
            'status' => $query->orderBy('status')->orderBy('name'),
            default => $query->orderByDesc('created_at'),
        };
    }

    public function render(): View
    {
        $base = $this->baseQuery();
        $hasServersInScope = $base !== null && (clone $base)->exists();
        $servers = $base
            ? $this->applyFilters(clone $base)->get()
            : collect();

        $groupedServers = $this->groupedServers($servers);

        return view('livewire.servers.index', [
            'hasServersInScope' => $hasServersInScope,
            'servers' => $servers,
            'groupedServers' => $groupedServers,
            'sortOptions' => config('user_preferences.server_sort_options', []),
            'statusOptions' => [
                '' => __('All statuses'),
                Server::STATUS_PENDING => __('Pending'),
                Server::STATUS_PROVISIONING => __('Provisioning'),
                Server::STATUS_READY => __('Ready'),
                Server::STATUS_ERROR => __('Error'),
                Server::STATUS_DISCONNECTED => __('Disconnected'),
            ],
        ]);
    }
}
