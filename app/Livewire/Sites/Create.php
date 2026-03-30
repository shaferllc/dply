<?php

namespace App\Livewire\Sites;

use App\Enums\SiteType;
use App\Livewire\Forms\SiteCreateForm;
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

    public SiteCreateForm $form;

    public function mount(Server $server): void
    {
        $this->authorize('view', $server);
        $this->authorize('update', $server);

        $org = auth()->user()->currentOrganization();
        abort_if($org === null, 403);
        abort_if($server->organization_id === null, 403);
        if ($server->organization_id !== $org->id) {
            abort(404);
        }

        $this->authorize('create', Site::class);
        $this->server = $server;
    }

    public function store(): mixed
    {
        $this->authorize('update', $this->server);
        $this->authorize('create', Site::class);

        $org = auth()->user()->currentOrganization();
        abort_if($org === null, 403);
        abort_if($this->server->organization_id === null, 403);
        abort_if($this->server->organization_id !== $org->id, 403);

        $this->form->validate([
            'name' => 'required|string|max:120',
            'type' => 'required|in:php,static,node',
            'document_root' => 'required|string|max:500',
            'repository_path' => 'nullable|string|max:500',
            'php_version' => 'nullable|string|max:10',
            'app_port' => 'nullable|integer|min:1|max:65535',
            'primary_hostname' => ['required', 'string', 'max:255', 'unique:site_domains,hostname', 'regex:/^[a-zA-Z0-9\.\-]+$/'],
        ]);

        $org = $this->server->organization;

        $site = Site::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'organization_id' => $this->server->organization_id,
            'deploy_script_id' => $org?->default_site_script_id,
            'name' => $this->form->name,
            'slug' => Str::slug($this->form->name) ?: 'site',
            'type' => SiteType::from($this->form->type),
            'document_root' => $this->form->document_root,
            'repository_path' => $this->form->repository_path ?: null,
            'php_version' => $this->form->type === 'php' ? ($this->form->php_version ?: '8.3') : null,
            'app_port' => $this->form->type === 'node' ? $this->form->app_port : null,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'webhook_secret' => Str::random(48),
        ]);

        $site->ensureUniqueSlug();
        $site->save();

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => strtolower(trim($this->form->primary_hostname)),
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
