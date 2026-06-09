<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Servers\WorkspaceSchedule;
use App\Livewire\Sites\Schedule;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportPageComponents\PageComponentConfig;
use Livewire\Features\SupportPageComponents\SupportPageComponents;
use Livewire\Livewire;

/**
 * One canonical site-scoped schedule URL that dispatches by site kind, the same
 * way {@see SiteWorkspaceController} picks Settings vs EdgeSettings:
 *
 *   - VM sites               → {@see WorkspaceSchedule} (framework scheduler /
 *                              cron heartbeat tracking over SSH)
 *   - container / serverless → {@see Schedule} (engine-level minute tick)
 *
 * Both are full-page Livewire components with their own #[Layout]; we mount the
 * chosen one and render it into that layout. WorkspaceSchedule self-gates on the
 * `workspace.schedule` flag via its RequiresFeature trait, so no route-level
 * feature middleware is needed here (which would also wrongly gate container
 * sites that don't use that flag).
 */
class SiteScheduleController
{
    public function __invoke(Server $server, Site $site): mixed
    {
        abort_unless($site->server_id === $server->id, 404);
        Gate::authorize('view', $site);

        $component = $site->runtimeTargetMode() === 'vm'
            ? WorkspaceSchedule::class
            : Schedule::class;

        $params = ['server' => $server, 'site' => $site];

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
