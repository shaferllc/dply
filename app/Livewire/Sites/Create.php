<?php

namespace App\Livewire\Sites;

use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Livewire\Forms\SiteCreateForm;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public Server $server;

    public SiteCreateForm $form;

    /**
     * @var list<array{id: string, label: string}>
     */
    public array $phpVersions = [];

    public function mount(Server $server, ServerPhpManager $phpManager): void
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
        $this->form->applyDefaultsForType($this->form->type);
        $phpData = $phpManager->siteCreationPhpData($server);
        $this->phpVersions = $phpData['available_versions'];
        $this->form->php_version = $phpData['preselected_version'];

        $hostname = request()->query('hostname');
        if (is_string($hostname) && $hostname !== '') {
            $hostname = strtolower(trim($hostname));
            if (preg_match('/^[a-zA-Z0-9\.\-]+$/', $hostname)) {
                $this->form->primary_hostname = $hostname;
                if ($this->form->name === '') {
                    $label = explode('.', $hostname, 2)[0];
                    $this->form->name = $label !== '' ? $label : $hostname;
                }
            }
        }

        $this->form->applyPathDefaults();
    }

    public function updatedFormType(string $value): void
    {
        $this->form->applyDefaultsForType($value);
    }

    public function updatedFormPrimaryHostname(string $value): void
    {
        $this->form->primary_hostname = strtolower(trim($value));
        $this->form->applyPathDefaults();
    }

    public function updatedFormCustomizePaths(bool $value): void
    {
        $this->form->customize_paths = $value;

        if (! $value) {
            $this->form->applyPathDefaults();
        }
    }

    public function store(SiteProvisioner $siteProvisioner): mixed
    {
        $this->authorize('update', $this->server);
        $this->authorize('create', Site::class);

        $org = auth()->user()->currentOrganization();
        abort_if($org === null, 403);
        abort_if($this->server->organization_id === null, 403);
        abort_if($this->server->organization_id !== $org->id, 403);

        $phpVersionIds = array_column($this->phpVersions, 'id');

        $rules = [
            'name' => 'required|string|max:120',
            'type' => 'required|in:php,static,node',
            'document_root' => 'required|string|max:500',
            'repository_path' => 'nullable|string|max:500',
            'php_version' => 'nullable|string|max:10',
            'app_port' => 'nullable|integer|min:1|max:65535',
            'primary_hostname' => ['required', 'string', 'max:255', 'unique:site_domains,hostname', 'regex:/^[a-zA-Z0-9\.\-]+$/'],
        ];

        if ($this->form->type === 'php') {
            $rules['php_version'] = ['required', 'string', 'max:10'];

            if ($phpVersionIds !== []) {
                $rules['php_version'][] = 'in:'.implode(',', $phpVersionIds);
            }
        }

        $this->form->validate($rules, [
            'php_version.required' => __('Choose a PHP version for this site.'),
            'php_version.in' => __('Choose a PHP version that is currently installed on this server.'),
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
            'php_version' => $this->form->type === 'php' ? $this->form->php_version : null,
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

        $site->loadMissing(['server', 'domains']);
        $siteProvisioner->markQueued($site);
        ProvisionSiteJob::dispatch($site->id);

        return $this->redirect(route('sites.show', [$this->server, $site]), navigate: true);
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->loadCount('sites');

        return view('livewire.sites.create', [
            'phpVersions' => $this->phpVersions,
        ]);
    }
}
