<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\DeployScriptComposer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The simple TEXT deploy pipeline: three editable shell scripts (Build /
 * Release / Restart), built-in framework presets, and an insert-snippet menu so
 * the operator never has to remember commands. Each script is saved as one
 * TYPE_CUSTOM deploy step per phase (see {@see DeployScriptComposer}). Shown in
 * place of the visual step builder while that's gated as "coming soon".
 */
#[Layout('layouts.app')]
class DeployScript extends Component
{
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    /** Render inside the deploy hub tab without the page chrome. */
    public bool $embedded = false;

    public string $build = '';

    public string $release = '';

    public string $restart = '';

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
        $this->loadScripts();
    }

    private function loadScripts(): void
    {
        $rendered = app(DeployScriptComposer::class)->render($this->site);
        $this->build = $rendered['build'] ?? '';
        $this->release = $rendered['release'] ?? '';
        $this->restart = $rendered['restart'] ?? '';
    }

    /**
     * Built-in presets: key => label + the (runtime, framework) the canonical
     * defaults are generated from.
     *
     * @return array<string, array{label: string, runtime: ?string, framework: ?string}>
     */
    public function presets(): array
    {
        return [
            'laravel' => ['label' => __('Laravel'), 'runtime' => 'php', 'framework' => 'laravel'],
            'php' => ['label' => __('Generic PHP'), 'runtime' => 'php', 'framework' => null],
            'node' => ['label' => __('Node'), 'runtime' => 'node', 'framework' => null],
            'static' => ['label' => __('Static'), 'runtime' => 'static', 'framework' => null],
            'empty' => ['label' => __('Empty'), 'runtime' => null, 'framework' => null],
        ];
    }

    public function applyPreset(string $key): void
    {
        Gate::authorize('update', $this->site);

        $presets = $this->presets();
        if (! isset($presets[$key])) {
            return;
        }

        if ($key === 'empty') {
            $this->build = $this->release = $this->restart = '';
            $this->toastSuccess(__('Cleared — write your own commands, then save.'));

            return;
        }

        $preset = $presets[$key];
        $scripts = app(DeployScriptComposer::class)->preset((string) $preset['runtime'], $preset['framework']);
        $this->build = $scripts['build'] ?? '';
        $this->release = $scripts['release'] ?? '';
        $this->restart = $scripts['restart'] ?? '';
        $this->toastSuccess(__(':preset preset loaded — review and save.', ['preset' => $preset['label']]));
    }

    /**
     * Canonical command snippets per phase for the "Insert command" menu, so
     * the operator can drop in commands without remembering the syntax.
     *
     * @return array<string, list<array{label: string, cmd: string}>>
     */
    public function snippets(): array
    {
        return [
            'build' => [
                ['label' => 'Composer install', 'cmd' => 'composer install --no-dev --optimize-autoloader'],
                ['label' => 'npm ci', 'cmd' => 'npm ci'],
                ['label' => 'npm run build', 'cmd' => 'npm run build'],
                ['label' => 'yarn install', 'cmd' => 'yarn install --frozen-lockfile'],
            ],
            'release' => [
                ['label' => 'Run migrations', 'cmd' => 'php artisan migrate --force'],
                ['label' => 'Optimize (config/route/view cache)', 'cmd' => 'php artisan optimize'],
                ['label' => 'Storage link', 'cmd' => 'php artisan storage:link'],
                ['label' => 'Seed database', 'cmd' => 'php artisan db:seed --force'],
            ],
            'restart' => [
                ['label' => 'Restart queue workers', 'cmd' => 'php artisan queue:restart'],
                ['label' => 'Terminate Horizon', 'cmd' => 'php artisan horizon:terminate'],
                ['label' => 'Reload PHP-FPM', 'cmd' => 'sudo systemctl reload php8.4-fpm'],
            ],
        ];
    }

    public function insert(string $phase, string $command): void
    {
        if (! in_array($phase, DeployScriptComposer::PHASES, true)) {
            return;
        }
        $current = trim((string) $this->{$phase});
        $this->{$phase} = $current === '' ? $command : $current."\n".$command;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->site);

        app(DeployScriptComposer::class)->apply($this->site, [
            'build' => $this->build,
            'release' => $this->release,
            'restart' => $this->restart,
        ]);

        $this->loadScripts();
        $this->toastSuccess(__('Deploy script saved.'));
    }

    public function render(): View
    {
        return view('livewire.sites.deploy-script');
    }
}
