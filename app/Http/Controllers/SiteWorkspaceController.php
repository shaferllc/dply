<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Sites\EdgeSettings;
use App\Livewire\Sites\Settings;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportPageComponents\PageComponentConfig;
use Livewire\Features\SupportPageComponents\SupportPageComponents;
use Livewire\Livewire;

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

        $component = $site->usesEdgeRuntime() ? EdgeSettings::class : Settings::class;

        $params = [
            'server' => $server,
            'site' => $site,
            'section' => $section,
        ];

        $html = null;

        $layoutConfig = SupportPageComponents::interceptTheRenderOfTheComponentAndRetreiveTheLayoutConfiguration(
            function () use (&$html, $component, $params): void {
                $html = Livewire::mount($component, $params);
            },
        );

        $layoutConfig = $layoutConfig ?: new PageComponentConfig;

        $layoutConfig->normalizeViewNameAndParamsForBladeComponents();

        $response = response(SupportPageComponents::renderContentsIntoLayout($html, $layoutConfig));

        if (is_callable($layoutConfig->response)) {
            call_user_func($layoutConfig->response, $response);
        }

        return $response;
    }
}
