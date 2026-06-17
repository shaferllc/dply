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

use App\Livewire\Concerns\BuildsCommandPaletteGroups;
use App\Livewire\Concerns\ManagesCommandPaletteStack;
use App\Livewire\Concerns\ResolvesCommandPaletteItems;
use App\Livewire\Concerns\RunsCommandPaletteActions;

/** @internal PHPStan harness for ResolvesCommandPaletteItems */
final class ResolvesCommandPaletteItemsHarness extends Component
{
    use BuildsCommandPaletteGroups;
    use ManagesCommandPaletteStack;
    use RunsCommandPaletteActions;
    use ResolvesCommandPaletteItems;
    public string $query = '';
    /** @var list<array{type: string, id: ?string, label: string}> */
    public array $stack = [];
    public ?Server $server = null;
    public ?Site $site = null;
    public ?Organization $organization = null;
    public ?User $user = null;
    public ?Team $team = null;
    public EdgeBuildSettingsForm $buildForm;
    public ?EdgeDeployment $deployment = null;
}
