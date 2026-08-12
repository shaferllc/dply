<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns\PhpStanHarness;

use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Livewire\Servers\Concerns\ManagesServerNoteExports;
use App\Livewire\Servers\Concerns\ManagesServerNotes;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Livewire\Component;

/** @internal PHPStan harness for ManagesServerNoteExports */
final class ManagesServerNoteExportsHarness extends Component
{
    // The export reuses the notes trait's filtered query so a download matches
    // the on-screen list; both traits belong in the harness.
    use ManagesServerNoteExports;
    use ManagesServerNotes;

    public ?Server $server = null;

    public ?Site $site = null;

    public ?Organization $organization = null;

    public ?User $user = null;

    public ?Team $team = null;

    public EdgeBuildSettingsForm $buildForm;

    public ?EdgeDeployment $deployment = null;
}
