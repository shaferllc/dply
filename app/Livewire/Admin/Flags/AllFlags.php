<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Flags;

use App\Livewire\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\AuditLog;
use App\Support\Admin\AdminFeatureFlags;
use App\Support\Admin\PlatformFeatureDefaults;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Manage every Pennant feature flag from one place.
 *
 * Toggling here writes a platform-wide override (feature_platform_overrides)
 * that beats the config/env default for all scopes but is still beaten by an
 * explicit per-org row. An override is only kept while it differs from the
 * config default — toggling a flag back to its config value clears it.
 */
#[Layout('layouts.admin')]
class AllFlags extends Component
{
    use AuthorizesPlatformAdmin;
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $namespace = '';

    /** Show only flags with a platform override or per-org overrides. */
    #[Url]
    public bool $onlyOverridden = false;

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function togglePlatformFlag(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! $this->isKnownFlag($flag)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $previous = AdminFeatureFlags::platformState($flag);
        $configDefault = AdminFeatureFlags::configDefault($flag);
        $next = ! $previous;

        // Keep the table clean: an override only exists while it diverges from
        // the config default. Toggling back to the config value clears it.
        if ($next === $configDefault) {
            PlatformFeatureDefaults::clear($flag);
        } else {
            PlatformFeatureDefaults::set($flag, $next);
        }

        $this->recordAudit($flag, $previous, $next);

        $this->toastSuccess($next
            ? __(':flag enabled platform-wide.', ['flag' => AdminFeatureFlags::flagLabel($flag)])
            : __(':flag disabled platform-wide.', ['flag' => AdminFeatureFlags::flagLabel($flag)]));
    }

    public function resetPlatformFlag(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! $this->isKnownFlag($flag)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        if (! AdminFeatureFlags::isPlatformOverridden($flag)) {
            $this->toastSuccess(__(':flag already follows its config default.', [
                'flag' => AdminFeatureFlags::flagLabel($flag),
            ]));

            return;
        }

        $previous = AdminFeatureFlags::platformState($flag);
        PlatformFeatureDefaults::clear($flag);
        $this->recordAudit($flag, $previous, AdminFeatureFlags::configDefault($flag));

        $this->toastSuccess(__(':flag reset to its config default (:state).', [
            'flag' => AdminFeatureFlags::flagLabel($flag),
            'state' => AdminFeatureFlags::configDefault($flag) ? __('on') : __('off'),
        ]));
    }

    public function requestClearOrgOverrides(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! $this->isKnownFlag($flag)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $count = AdminFeatureFlags::orgOverrideCountsByFlag()[$flag] ?? 0;
        if ($count === 0) {
            $this->toastSuccess(__('No org overrides to clear.'));

            return;
        }

        $this->openConfirmActionModal(
            method: 'clearOrgOverrides',
            arguments: [$flag],
            title: __('Clear org overrides'),
            message: __('Remove :count org override(s) for :flag? Each org will inherit the platform default (:state).', [
                'count' => $count,
                'flag' => AdminFeatureFlags::flagLabel($flag),
                'state' => AdminFeatureFlags::platformState($flag) ? __('on') : __('off'),
            ]),
            confirmLabel: __('Clear overrides'),
            destructive: true,
            details: [
                ['label' => __('Flag key'), 'value' => $flag, 'mono' => true],
                ['label' => __('Platform default'), 'value' => AdminFeatureFlags::platformState($flag) ? __('On') : __('Off')],
                ['label' => __('Org overrides'), 'value' => (string) $count],
            ],
        );
    }

    public function clearOrgOverrides(string $flag): void
    {
        $this->authorizePlatformAdmin();

        if (! $this->isKnownFlag($flag)) {
            $this->toastError(__('Unknown feature flag.'));

            return;
        }

        $purged = AdminFeatureFlags::purgeOrgScopedOverridesForAnyFlag($flag);
        Feature::flushCache();

        $this->toastSuccess(__(':count org override(s) cleared for :flag.', [
            'count' => $purged,
            'flag' => AdminFeatureFlags::flagLabel($flag),
        ]));
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'namespace', 'onlyOverridden']);
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        $all = AdminFeatureFlags::allRegisteredFlags();
        $orgCounts = AdminFeatureFlags::orgOverrideCountsByFlag();
        $needle = trim(mb_strtolower($this->search));

        $groups = [];
        $totalShown = 0;
        $overriddenCount = 0;

        foreach ($all as $namespace => $flags) {
            foreach ($flags as $key => $label) {
                $override = AdminFeatureFlags::platformOverride($key);
                if ($override !== null) {
                    $overriddenCount++;
                }
            }

            if ($this->namespace !== '' && $this->namespace !== $namespace) {
                continue;
            }

            $entries = [];
            foreach ($flags as $key => $label) {
                $override = AdminFeatureFlags::platformOverride($key);
                $configDefault = AdminFeatureFlags::configDefault($key);
                $orgOverrides = $orgCounts[$key] ?? 0;

                if ($this->onlyOverridden && $override === null && $orgOverrides === 0) {
                    continue;
                }

                if ($needle !== '' && ! str_contains(mb_strtolower($key.' '.$label), $needle)) {
                    continue;
                }

                $entries[] = [
                    'key' => $key,
                    'label' => $label,
                    'active' => $override ?? $configDefault,
                    'configDefault' => $configDefault,
                    'overridden' => $override !== null,
                    'orgOverrides' => $orgOverrides,
                ];
            }

            if ($entries === []) {
                continue;
            }

            $totalShown += count($entries);
            $groups[] = [
                'namespace' => $namespace,
                'flags' => $entries,
            ];
        }

        return view('livewire.admin.flags.all-flags', [
            'groups' => $groups,
            'namespaces' => array_keys($all),
            'totalFlags' => array_sum(array_map('count', $all)),
            'totalShown' => $totalShown,
            'overriddenCount' => $overriddenCount,
        ]);
    }

    private function isKnownFlag(string $flag): bool
    {
        foreach (AdminFeatureFlags::allRegisteredFlags() as $flags) {
            if (array_key_exists($flag, $flags)) {
                return true;
            }
        }

        return false;
    }

    private function recordAudit(string $flag, bool $previous, bool $next): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        AuditLog::log(
            organization: $org,
            user: auth()->user(),
            action: 'feature.platform_override',
            subject: null,
            oldValues: ['flag' => $flag, 'value' => $previous],
            newValues: [
                'flag' => $flag,
                'value' => $next,
                'reason' => 'platform admin (all-flags)',
            ],
        );
    }
}
