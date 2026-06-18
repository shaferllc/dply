<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\PhpStanHarness;

use Livewire\Component;
use App\Models\Site;
use App\Models\Server;
use App\Models\User;
use App\Models\Organization;
use App\Models\Team;
use App\Models\ErrorEvent;
use App\Models\EdgeDeployment;
use App\Livewire\Forms\EdgeBuildSettingsForm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\OptimizesPipeline;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;

/** @internal PHPStan harness for OptimizesPipeline */
final class OptimizesPipelineHarness extends Component
{
    use DispatchesToastNotifications;
    use WatchesConsoleActionOutcomes;
    use OptimizesPipeline;
    public Site $site;
    protected function seedQueuedConsoleAction(string $kind, ?string $label = null): \App\Models\ConsoleAction { return new \App\Models\ConsoleAction(); }
    protected function syncEditingPipelineBranches(): void {}
    public ?Server $server = null;
    public ?Organization $organization = null;
    public ?User $user = null;
    public ?Team $team = null;
    public EdgeBuildSettingsForm $buildForm;
    public ?EdgeDeployment $deployment = null;
}
