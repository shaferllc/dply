<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns\PhpStanHarness;

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
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;

/** @internal PHPStan harness for InteractsWithServerWorkspace */
final class InteractsWithServerWorkspaceHarness extends Component
{
    use DispatchesToastNotifications;
    use InteractsWithServerWorkspace;
    public ?Server $server = null;
    public ?Site $site = null;
    public ?Organization $organization = null;
    public ?User $user = null;
    public ?Team $team = null;
    public EdgeBuildSettingsForm $buildForm;
    public ?EdgeDeployment $deployment = null;
}
