<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns\PhpStanHarness;

use App\Livewire\Servers\Concerns\ManagesMonitorProductionMirror;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Livewire\Component;

/** @internal PHPStan harness for ManagesMonitorProductionMirror */
final class ManagesMonitorProductionMirrorHarness extends Component
{
    use ManagesMonitorProductionMirror;

    public ?Server $server = null;

    public ?Site $site = null;

    public ?Organization $organization = null;

    public ?User $user = null;

    public ?Team $team = null;

    public bool $wasProbePending = false;

    public bool $editingThresholds = false;
}
