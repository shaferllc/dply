<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Sites\Settings;
use App\Models\Server;
use App\Models\Site;
use App\Support\Livewire\RendersLivewirePage;
use Illuminate\Support\Facades\Gate;

class SiteWorkspaceController
{
    public function __invoke(Server $server, Site $site, ?string $section = null): mixed
    {
        abort_unless($site->server_id === $server->id, 404);
        Gate::authorize('view', $site);

        // Choose-app flow: a site without an application installed must pick
        // one before its workspace is usable — both freshly-created bare
        // sites and existing repo-less web sites. Funnel it to the picker.
        // Sites the user explicitly skipped render normally. VM hosts only.
        if ($server->isVmHost() && $site->needsAppChoice()) {
            return redirect()->route('sites.choose-app', ['server' => $server, 'site' => $site]);
        }

        $section = ($section === null || $section === '') ? 'general' : $section;

        if (
            $section === 'deploy'
            && $server->isVmHost()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesEdgeRuntime()
        ) {
            return redirect()->route('sites.deployments.index', [
                'server' => $server,
                'site' => $site,
                ...request()->query(),
            ]);
        }

        // Environment now lives exclusively on the Deploy hub's Environment
        // tab — the same component that owns the variables editor and the
        // resource bindings. The old Settings → Environment section is gone for
        // VM sites; funnel every deep-link (preflight fixes, env-diff "back",
        // legacy /settings/environment) straight there. Mirrors the `deploy`
        // redirect's host guard so container/serverless/edge sites, which keep
        // their own environment surface, are left untouched.
        if (
            $section === 'environment'
            && $server->isVmHost()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesEdgeRuntime()
        ) {
            return redirect()->route('sites.environment', [
                'server' => $server,
                'site' => $site,
                ...request()->query(),
            ]);
        }

        if ($section === 'pipeline') {
            return redirect()->route('sites.pipeline', [
                'server' => $server,
                'site' => $site,
                ...request()->query(),
            ]);
        }

        if ($section === 'dns') {
            return redirect()->route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'routing',
                'tab' => 'dns',
                ...request()->query(),
            ]);
        }

        // Edge sites rendered Livewire\Sites\EdgeSettings here; that component
        // went with the surface (remove-cloud-edge), so every site
        // gets the standard settings workspace.
        return RendersLivewirePage::render(Settings::class, [
            'server' => $server,
            'site' => $site,
            'section' => $section,
        ]);
    }
}
