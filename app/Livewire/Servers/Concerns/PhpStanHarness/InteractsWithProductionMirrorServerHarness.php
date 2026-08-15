<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns\PhpStanHarness;

use App\Livewire\Servers\Concerns\InteractsWithProductionMirrorServer;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Livewire\Component;

/** @internal PHPStan harness for InteractsWithProductionMirrorServer */
final class InteractsWithProductionMirrorServerHarness extends Component
{
    use InteractsWithProductionMirrorServer;

    public ?Server $server = null;

    public ?Site $site = null;

    public ?Organization $organization = null;

    public ?User $user = null;

    public ?Team $team = null;
}
