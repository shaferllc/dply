<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns\PhpStanHarness;

use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Livewire\Servers\Concerns\ManagesServerNoteComments;
use App\Livewire\Servers\Concerns\ManagesServerNotes;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Livewire\Component;

/** @internal PHPStan harness for ManagesServerNoteComments */
final class ManagesServerNoteCommentsHarness extends Component
{
    // Comments lean on the notes trait for the guard, the server-scoped note
    // lookup and the computed-cache reset, so the harness composes both.
    use ManagesServerNoteComments;
    use ManagesServerNotes;

    public ?Server $server = null;

    public ?Site $site = null;

    public ?Organization $organization = null;

    public ?User $user = null;

    public ?Team $team = null;

    public EdgeBuildSettingsForm $buildForm;

    public ?EdgeDeployment $deployment = null;
}
