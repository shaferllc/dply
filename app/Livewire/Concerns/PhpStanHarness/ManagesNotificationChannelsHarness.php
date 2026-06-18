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

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesNotificationChannels;

/** @internal PHPStan harness for ManagesNotificationChannels */
final class ManagesNotificationChannelsHarness extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesNotificationChannels;
    protected function owner(): User|Organization|Team { return match (random_int(0, 2)) { 0 => new User(), 1 => new Organization(), default => new Team(), }; }
    /** @return array<string, mixed> */
    protected function notificationChannelsViewData(): array { return []; }
    public ?Server $server = null;
    public ?Site $site = null;
    public ?Organization $organization = null;
    public ?User $user = null;
    public ?Team $team = null;
    public EdgeBuildSettingsForm $buildForm;
    public ?EdgeDeployment $deployment = null;
}
