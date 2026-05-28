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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.admin')]
class ProductLineFlags extends Component
{
    use AuthorizesPlatformAdmin;
    use ConfirmsActionWithModal;
    use ManagesAdminFlagToggles;

    public string $line = 'vm-servers';

    public function mount(string $line = 'vm-servers'): void
    {
        $this->mountAuthorizesPlatformAdmin();

        if (AdminFeatureFlags::productLineTitle($line) === null) {
            throw new NotFoundHttpException;
        }

        $this->line = $line;
    }

    public function requestPlatformDefaultFeatureFlagToggle(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! in_array($flag, AdminFeatureFlags::platformDefaultFlagKeys(), true)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $label = AdminFeatureFlags::orgFlagLabel($flag) ?? $flag;
        $active = Feature::for(null)->active($flag);
        $overrideCount = AdminFeatureFlags::orgOverrideCountForFlag($flag);

        $message = $active
            ? __('Turn :flag off platform-wide? This clears :count org override(s) so every org follows the disabled default.', [
                'flag' => $label,
                'count' => $overrideCount,
            ])
            : __('Turn :flag on as the platform default? Orgs with an explicit override keep their current setting.', [
                'flag' => $label,
            ]);

        $this->openConfirmActionModal(
            method: 'applyPlatformDefaultFeatureFlag',
            arguments: [$flag],
            title: __('Change platform default'),
            message: $message,
            confirmLabel: $active ? __('Disable platform-wide') : __('Update default'),
            destructive: $active,
            details: [
                ['label' => __('Flag key'), 'value' => $flag, 'mono' => true],
                ['label' => __('Current default'), 'value' => $active ? __('On') : __('Off')],
                ['label' => __('Org overrides'), 'value' => (string) $overrideCount],
                ['label' => __('Env default'), 'value' => AdminFeatureFlags::configDefault($flag) ? __('On') : __('Off')],
            ],
        );
    }

    public function requestClearOrgOverridesForFlag(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! in_array($flag, AdminFeatureFlags::platformDefaultFlagKeys(), true)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $label = AdminFeatureFlags::orgFlagLabel($flag) ?? $flag;
        $overrideCount = AdminFeatureFlags::orgOverrideCountForFlag($flag);

        if ($overrideCount === 0) {
            $this->toastSuccess(__('No org overrides to clear for :flag.', ['flag' => $label]));

            return;
        }

        $this->openConfirmActionModal(
            method: 'clearOrgOverridesForFlag',
            arguments: [$flag],
            title: __('Clear org overrides'),
            message: __('Remove :count org override(s) for :flag? Each org will inherit the current platform default (:state).', [
                'count' => $overrideCount,
                'flag' => $label,
                'state' => Feature::for(null)->active($flag) ? __('on') : __('off'),
            ]),
            confirmLabel: __('Clear overrides'),
            destructive: true,
            details: [
                ['label' => __('Flag key'), 'value' => $flag, 'mono' => true],
                ['label' => __('Platform default'), 'value' => Feature::for(null)->active($flag) ? __('On') : __('Off')],
                ['label' => __('Org overrides'), 'value' => (string) $overrideCount],
            ],
        );
    }

    public function clearOrgOverridesForFlag(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! in_array($flag, AdminFeatureFlags::platformDefaultFlagKeys(), true)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $label = AdminFeatureFlags::orgFlagLabel($flag) ?? $flag;
        $purged = AdminFeatureFlags::purgeOrgScopedOverrides($flag);
        Feature::flushCache();

        $this->toastSuccess(__(':count org override(s) cleared for :flag.', [
            'count' => $purged,
            'flag' => $label,
        ]));
    }

    public function requestLineEmergencyToggle(string $flag): void
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
            title: __('Change emergency kill switch'),
            message: __('Turn :flag :state app-wide? This overrides normal product access until re-enabled.', [
                'flag' => $label,
                'state' => $next,
            ]),
            confirmLabel: __('Update kill switch'),
            destructive: $active,
            details: [
                ['label' => __('Flag key'), 'value' => $flag, 'mono' => true],
                ['label' => __('Current'), 'value' => $active ? __('On') : __('Off')],
            ],
        );
    }

    public function requestProductLineGlobalToggle(string $flag): void
    {
        $this->requestLineEmergencyToggle($flag);
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        $groups = [];
        foreach (AdminFeatureFlags::groupsForProductLine($this->line) as $title => $flags) {
            $orgScoped = [];
            $globalScoped = [];
            foreach ($flags as $key => $label) {
                if (AdminFeatureFlags::isGlobalNamespace($key)) {
                    $globalScoped[$key] = $label;
                } elseif (! AdminFeatureFlags::isPreviewFlag($key)) {
                    $orgScoped[$key] = $label;
                }
            }

            if ($orgScoped !== []) {
                $groups[] = [
                    'title' => $title,
                    'flags' => $this->groupedPlatformFlagEntries($orgScoped, $flags),
                    'mode' => 'platform',
                ];
            }

            if ($globalScoped !== []) {
                $groups[] = [
                    'title' => $title,
                    'flags' => $this->globalFlagEntries($globalScoped),
                    'mode' => 'global',
                ];
            }
        }

        $emergency = $this->globalFlagEntries(AdminFeatureFlags::emergencyFlagsForProductLine($this->line));

        $orgOverrideCounts = [];
        foreach (AdminFeatureFlags::flagsForProductLine($this->line) as $key => $_label) {
            if (! AdminFeatureFlags::isGlobalNamespace($key) && ! AdminFeatureFlags::isPlatformOnlyOrgFlag($key)) {
                $orgOverrideCounts[$key] = AdminFeatureFlags::orgOverrideCountForFlag($key);
            }
        }

        return view('livewire.admin.flags.product-line', [
            'line' => $this->line,
            'lineTitle' => AdminFeatureFlags::productLineTitle($this->line),
            'lineDescription' => AdminFeatureFlags::productLineDescription($this->line),
            'emergencyFlags' => $emergency,
            'groups' => $groups,
            'orgOverrideCounts' => $orgOverrideCounts,
        ]);
    }
}
