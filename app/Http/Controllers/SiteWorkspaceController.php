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

        $section = ($section === null || $section === '') ? 'general' : $section;

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
