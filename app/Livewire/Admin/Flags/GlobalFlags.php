<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Flags;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Admin\Concerns\ManagesAdminFlagToggles;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Support\Admin\AdminFeatureFlags;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class GlobalFlags extends Component
{
    use AuthorizesPlatformAdmin;
    use ConfirmsActionWithModal;
    use ManagesAdminFlagToggles;

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function requestGlobalFeatureFlagToggle(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! in_array($flag, AdminFeatureFlags::globalFlagKeys(), true)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $label = AdminFeatureFlags::globalFlagLabel($flag) ?? $flag;
        $active = Feature::for(null)->active($flag);
        $next = $active ? __('off') : __('on');

        $this->openConfirmActionModal(
            method: 'applyGlobalFeatureFlag',
            arguments: [$flag],
            title: __('Change app-wide flag'),
            message: __('Turn :flag :state app-wide? This affects every organization and user.', [
                'flag' => $label,
                'state' => $next,
            ]),
            confirmLabel: __('Update flag'),
            destructive: $active,
            details: [
                ['label' => __('Flag key'), 'value' => $flag, 'mono' => true],
                ['label' => __('Current'), 'value' => $active ? __('On') : __('Off')],
                ['label' => __('Config default'), 'value' => AdminFeatureFlags::configDefault($flag) ? __('On') : __('Off')],
            ],
        );
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
