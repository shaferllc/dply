<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Concerns;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Support\Admin\AdminFeatureFlags;
use Laravel\Pennant\Feature;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 */
trait ManagesAdminFlagToggles
{
    use DispatchesToastNotifications;

    public function toggleOrgFeatureFlag(string $flag, ?Organization $organization = null): void
    {
        $this->authorizePlatformAdmin();

        if (! in_array($flag, AdminFeatureFlags::orgFlagKeys(), true)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $org = $organization ?? $this->resolveFlagOrganization();
        if (! $org instanceof Organization) {
            $this->toastError(__('Organization not found.'));

            return;
        }

        $label = AdminFeatureFlags::orgFlagLabel($flag) ?? $flag;
        $previous = Feature::for($org)->value($flag);

        if (Feature::for($org)->active($flag)) {
            Feature::for($org)->deactivate($flag);
            $this->toastSuccess(__(':flag disabled for :org.', [
                'flag' => $label,
                'org' => $org->name,
            ]));
        } else {
            Feature::for($org)->activate($flag);
            $this->toastSuccess(__(':flag enabled for :org.', [
                'flag' => $label,
                'org' => $org->name,
            ]));
        }

        AuditLog::log(
            organization: $org,
            user: auth()->user(),
            action: 'feature.override',
            subject: null,
            oldValues: ['flag' => $flag, 'value' => $previous],
            newValues: [
                'flag' => $flag,
                'value' => Feature::for($org)->value($flag),
                'reason' => 'platform admin',
            ],
        );
    }

    public function applyPlatformDefaultFeatureFlag(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! in_array($flag, AdminFeatureFlags::platformDefaultFlagKeys(), true)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $label = AdminFeatureFlags::orgFlagLabel($flag) ?? $flag;

        if (Feature::for(null)->active($flag)) {
            Feature::for(null)->deactivate($flag);
            $purged = AdminFeatureFlags::purgeOrgScopedOverrides($flag);
            Feature::flushCache();
            $this->toastSuccess(__(':flag disabled platform-wide.', [
                'flag' => $label,
            ]));
            if ($purged > 0) {
                $this->toastSuccess(__(':count org override(s) cleared.', ['count' => $purged]));
            }
        } else {
            Feature::for(null)->activate($flag);
            if (AdminFeatureFlags::isPlatformOnlyOrgFlag($flag)) {
                AdminFeatureFlags::purgeOrgScopedOverridesRaw($flag);
            }
            Feature::flushCache();
            $this->toastSuccess(AdminFeatureFlags::isPlatformOnlyOrgFlag($flag)
                ? __(':flag enabled platform-wide for every org (when the full feature above is off).', ['flag' => $label])
                : __(':flag platform default enabled. Orgs with explicit overrides keep their setting.', ['flag' => $label]));
        }

        $this->toastWarning(__('Env/config default: :state.', [
            'state' => AdminFeatureFlags::configDefault($flag) ? __('on') : __('off'),
        ]));
    }

    public function applyGlobalFeatureFlag(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! in_array($flag, AdminFeatureFlags::globalFlagKeys(), true)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $label = AdminFeatureFlags::globalFlagLabel($flag) ?? $flag;

        if (Feature::for(null)->active($flag)) {
            Feature::for(null)->deactivate($flag);
            $purged = AdminFeatureFlags::purgeInvalidOrgScopedGlobalOverrides($flag);
            Feature::flushCache();
            $this->toastSuccess(__(':flag disabled app-wide.', ['flag' => $label]));
            if ($purged > 0) {
                $this->toastSuccess(__(':count invalid org override(s) removed.', ['count' => $purged]));
            }
        } else {
            Feature::for(null)->activate($flag);
            AdminFeatureFlags::purgeInvalidOrgScopedGlobalOverrides($flag);
            Feature::flushCache();
            $this->toastSuccess(__(':flag enabled app-wide.', ['flag' => $label]));
        }

        $this->toastWarning(__('Default from config: :state.', [
            'state' => AdminFeatureFlags::configDefault($flag) ? __('on') : __('off'),
        ]));
    }

    /**
     * @return list<array{key: string, label: string, active: bool, default: bool, configDefault: bool}>
     */
    protected function orgFlagEntries(?Organization $org, array $flags): array
    {
        $entries = [];
        foreach ($flags as $key => $label) {
            $entries[] = [
                'key' => $key,
                'label' => $label,
                'active' => $org instanceof Organization && Feature::for($org)->active($key),
                'default' => AdminFeatureFlags::platformDefault($key),
                'configDefault' => AdminFeatureFlags::configDefault($key),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{key: string, label: string, active: bool, default: bool, configDefault: bool}>
     */
    protected function platformDefaultFlagEntries(array $flags): array
    {
        $entries = [];
        foreach ($flags as $key => $label) {
            $entries[] = [
                'key' => $key,
                'label' => $label,
                'active' => Feature::for(null)->active($key),
                'default' => AdminFeatureFlags::configDefault($key),
                'configDefault' => AdminFeatureFlags::configDefault($key),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{key: string, label: string, active: bool, default: bool, configDefault: bool, preview: array{key: string, label: string, active: bool, default: bool, configDefault: bool}|null}>
     */
    protected function groupedPlatformFlagEntries(array $parentFlags, array $allFlagsInGroup): array
    {
        $entries = [];
        foreach ($parentFlags as $key => $label) {
            $previewKey = AdminFeatureFlags::previewFlagFor($key);
            $previewEntry = null;

            if ($previewKey !== null && isset($allFlagsInGroup[$previewKey])) {
                $previewEntry = [
                    'key' => $previewKey,
                    'label' => $allFlagsInGroup[$previewKey],
                    'active' => Feature::for(null)->active($previewKey),
                    'default' => AdminFeatureFlags::configDefault($previewKey),
                    'configDefault' => AdminFeatureFlags::configDefault($previewKey),
                ];
            }

            $entries[] = [
                'key' => $key,
                'label' => $label,
                'active' => Feature::for(null)->active($key),
                'default' => AdminFeatureFlags::configDefault($key),
                'configDefault' => AdminFeatureFlags::configDefault($key),
                'preview' => $previewEntry,
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{key: string, label: string, active: bool, default: bool}>
     */
    protected function globalFlagEntries(array $flags): array
    {
        $entries = [];
        foreach ($flags as $key => $label) {
            $entries[] = [
                'key' => $key,
                'label' => $label,
                'active' => Feature::for(null)->active($key),
                'default' => AdminFeatureFlags::configDefault($key),
            ];
        }

        return $entries;
    }

    protected function resolveFlagOrganization(): ?Organization
    {
        return null;
    }
}
