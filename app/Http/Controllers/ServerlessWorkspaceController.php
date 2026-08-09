<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Sites\DeploymentDetail;
use App\Livewire\Sites\DeploymentsList;
use App\Livewire\Sites\SiteEnvironment;
use App\Livewire\Sites\Errors as SitesErrors;
use App\Livewire\Sites\Logs as SitesLogs;
use App\Livewire\Sites\Monitor as SitesMonitor;
use App\Livewire\Sites\Repository;
use App\Livewire\Sites\ServerlessRouting;
use App\Livewire\Sites\Workers;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Serverless\Livewire\Journey as ServerlessJourney;
use App\Support\Livewire\RendersLivewirePage;
use App\Support\Serverless\ServerlessWorkspaceUrl;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Site-centric serverless workspace entrypoints under `/serverless/{site}/…`.
 *
 * Resolves the synthetic Server row for Livewire mounts that still expect
 * `(Server $server, Site $site)`, while keeping the user-facing URL off `/servers`.
 */
final class ServerlessWorkspaceController
{
    public function show(Site $site, ?string $section = null): mixed
    {
        $this->guardFunctionSite($site);

        return app(SiteWorkspaceController::class)(
            $site->server,
            $site,
            $section,
        );
    }

    public function journey(Site $site): Response
    {
        $this->guardFunctionSite($site);

        return RendersLivewirePage::render(ServerlessJourney::class, [
            'server' => $site->server,
            'site' => $site,
        ]);
    }

    public function routing(Site $site): Response
    {
        return $this->mountLeaf($site, ServerlessRouting::class);
    }

    public function deployments(Site $site): Response
    {
        return $this->mountLeaf($site, DeploymentsList::class);
    }

    public function deploymentShow(Site $site, SiteDeployment $deployment): Response
    {
        $this->guardFunctionSite($site);
        abort_unless($deployment->site_id === $site->id, 404);

        return RendersLivewirePage::render(DeploymentDetail::class, [
            'server' => $site->server,
            'site' => $site,
            'deployment' => $deployment,
        ]);
    }

    public function repository(Site $site): Response
    {
        return $this->mountLeaf($site, Repository::class);
    }

    public function resources(Site $site): mixed
    {
        $this->guardFunctionSite($site);

        return app(SiteWorkspaceController::class)($site->server, $site, 'resources');
    }

    public function schedule(Site $site): mixed
    {
        $this->guardFunctionSite($site);

        return app(SiteScheduleController::class)($site->server, $site);
    }

    public function workers(Site $site): Response
    {
        return $this->mountLeaf($site, Workers::class);
    }

    public function environment(Site $site): Response
    {
        return $this->mountLeaf($site, SiteEnvironment::class);
    }

    public function monitor(Site $site): Response
    {
        return $this->mountLeaf($site, SitesMonitor::class);
    }

    public function errors(Site $site): Response
    {
        return $this->mountLeaf($site, SitesErrors::class);
    }

    public function logs(Site $site): Response
    {
        return $this->mountLeaf($site, SitesLogs::class);
    }

    /**
     * @param  class-string  $component
     */
    private function mountLeaf(Site $site, string $component): Response
    {
        $this->guardFunctionSite($site);

        return RendersLivewirePage::render($component, [
            'server' => $site->server,
            'site' => $site,
        ]);
    }

    private function guardFunctionSite(Site $site): void
    {
        abort_unless($site->server_id !== null && $site->server !== null, 404);
        abort_unless(
            $site->usesFunctionsRuntime() || $site->server->isServerlessHost(),
            404,
        );
        Gate::authorize('view', $site);
    }
}
