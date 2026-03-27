<?php

namespace App\Livewire\Sites;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public Server $server;

    public string $name = '';

    public string $type = 'php';

    public string $document_root = '/var/www/app/public';

    public string $repository_path = '/var/www/app';

    public string $php_version = '8.3';

    public ?int $app_port = 3000;

    public string $primary_hostname = '';

    public function mount(Server $server): void
    {
        $this->authorize('view', $server);
        $this->authorize('update', $server);
        if ($server->organization_id !== auth()->user()->currentOrganization()?->id) {
            abort(404);
        }
        $this->authorize('create', Site::class);
        $this->server = $server;
    }

    public function store(): mixed
    {
        $this->authorize('update', $this->server);
        $this->authorize('create', Site::class);

        $this->validate([
            'name' => 'required|string|max:120',
            'type' => 'required|in:php,static,node',
            'document_root' => 'required|string|max:500',
            'repository_path' => 'nullable|string|max:500',
            'php_version' => 'nullable|string|max:10',
            'app_port' => 'nullable|integer|min:1|max:65535',
            'primary_hostname' => ['required', 'string', 'max:255', 'unique:site_domains,hostname', 'regex:/^[a-zA-Z0-9\.\-]+$/'],
        ]);

        $site = Site::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'organization_id' => $this->server->organization_id,
            'name' => $this->name,
            'slug' => Str::slug($this->name) ?: 'site',
            'type' => SiteType::from($this->type),
            'document_root' => $this->document_root,
            'repository_path' => $this->repository_path ?: null,
            'php_version' => $this->type === 'php' ? ($this->php_version ?: '8.3') : null,
            'app_port' => $this->type === 'node' ? $this->app_port : null,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'webhook_secret' => Str::random(48),
        ]);

        $site->ensureUniqueSlug();
        $site->save();

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => strtolower(trim($this->primary_hostname)),
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        return $this->redirect(route('sites.show', [$this->server, $site]), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.sites.create');
    }
}
