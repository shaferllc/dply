<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Flags;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Admin\Concerns\ManagesAdminFlagToggles;
use App\Support\Admin\AdminFeatureFlags;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class GlobalFlags extends Component
{
    use AuthorizesPlatformAdmin;
    use ManagesAdminFlagToggles;

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        $groups = [];
        foreach (AdminFeatureFlags::globalGroups() as $title => $flags) {
            $groups[] = [
                'title' => $title,
                'flags' => $this->globalFlagEntries($flags),
            ];
        }

        return view('livewire.admin.flags.global-flags', [
            'groups' => $groups,
        ]);
    }
}
