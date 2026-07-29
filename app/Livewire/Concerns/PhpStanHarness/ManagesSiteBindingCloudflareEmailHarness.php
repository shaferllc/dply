<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\PhpStanHarness;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesSiteBindingCloudflareEmail;
use App\Models\Site;
use Livewire\Component;

/** @internal PHPStan harness for ManagesSiteBindingCloudflareEmail */
final class ManagesSiteBindingCloudflareEmailHarness extends Component
{
    use DispatchesToastNotifications;
    use ManagesSiteBindingCloudflareEmail;

    public Site $site;

    /** @var array<string, mixed> */
    public array $bindingForm = [];

    public string $mailTestRecipient = '';
}
