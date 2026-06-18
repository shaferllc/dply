<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use App\Modules\Projects\Livewire\Index as ProjectsIndex;
use App\Modules\Projects\Livewire\Show as ProjectsShow;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Projects module wiring (docs/adr/modular-monolith-structure.md).
 *
 * Registers the two full-page route components (projects.index, projects.show)
 * under their original auto-derived names. The Project model stays in app/Models
 * per the model rule; ProjectApiController and RunWorkspaceDeployJob consume the
 * Services\Projects\* classes via repointed references.
 */
class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('projects.index', ProjectsIndex::class);
        Livewire::component('projects.show', ProjectsShow::class);
    }
}
