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

use App\Livewire\Concerns\Edge\ManagesEdgeBuildSettings;

/** @internal PHPStan harness for ManagesEdgeBuildSettings */
final class ManagesEdgeBuildSettingsHarness extends Component
{
    use ManagesEdgeBuildSettings;
    public Site $site;
    public EdgeBuildSettingsForm $buildForm;
    public ?Server $server = null;
    public ?Organization $organization = null;
    public ?User $user = null;
    public ?Team $team = null;
    public ?EdgeDeployment $deployment = null;
}
