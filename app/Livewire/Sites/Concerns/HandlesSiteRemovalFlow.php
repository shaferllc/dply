<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\Site;
use Carbon\Carbon;

/**
 * Mirrors {@see \App\Livewire\Servers\Concerns\HandlesServerRemovalFlow} for
 * sites. Three timing modes (Now / In 30 min / Schedule), type-to-confirm
 * guard, and an audit + persist path. The 30-minute and scheduled modes
 * both stamp {@see Site::$scheduled_deletion_at}; the every-minute
 * ProcessScheduledSiteDeletionsCommand picks them up when due.
 *
 * @property Site $site
 */
trait HandlesSiteRemovalFlow
{
    public bool $showRemoveSiteModal = false;

    public string $deleteSiteConfirmName = '';

    /** @var 'now'|'in_30'|'scheduled' */
    public string $removeSiteMode = 'now';

    public string $scheduledSiteRemovalDate = '';

    public string $siteDeletionReason = '';

    public function openRemoveSiteModal(): void
    {
        $this->authorize('delete', $this->site);
        $this->deleteSiteConfirmName = '';
        $this->removeSiteMode = 'now';
        $defaultDays = (int) config('dply.site_scheduled_deletion_default_days', 7);
        $this->scheduledSiteRemovalDate = now()->addDays($defaultDays)->toDateString();
        $this->siteDeletionReason = '';
        $this->resetValidation();
        $this->showRemoveSiteModal = true;
    }

    public function closeRemoveSiteModal(): void
    {
        $this->showRemoveSiteModal = false;
        $this->deleteSiteConfirmName = '';
        $this->removeSiteMode = 'now';
        $this->scheduledSiteRemovalDate = '';
        $this->siteDeletionReason = '';
        $this->resetValidation();
    }

    public function applySiteRemovalDatePreset(string $preset): void
    {
        $this->scheduledSiteRemovalDate = match ($preset) {
            'tomorrow' => now()->addDay()->toDateString(),
            'week' => now()->addDays(7)->toDateString(),
            'month' => now()->addDays(30)->toDateString(),
            default => $this->scheduledSiteRemovalDate,
        };
    }

    public function cancelScheduledSiteRemoval(): void
    {
        $this->authorize('delete', $this->site);
        $site = $this->site->fresh();
        if ($site === null || $site->scheduled_deletion_at === null) {
            return;
        }

        $meta = $site->meta ?? [];
        unset($meta['scheduled_deletion_reason']);

        $site->update([
            'scheduled_deletion_at' => null,
            'meta' => $meta,
        ]);

        if ($site->organization) {
            audit_log($site->organization, auth()->user(), 'site.deletion_unscheduled', $site, null, null);
        }

        $this->site = $site->fresh();
        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Scheduled site removal cancelled.'));
        }
    }

    public function submitRemoveSite(): mixed
    {
        $this->authorize('delete', $this->site);
        $site = $this->site->fresh();

        if (trim($this->deleteSiteConfirmName) !== $site->name) {
            $this->addError('deleteSiteConfirmName', __('Type the site name exactly to confirm.'));

            return null;
        }

        if ($this->removeSiteMode === 'in_30') {
            $reason = trim($this->siteDeletionReason);
            $at = now()->addMinutes(30);
            $this->persistScheduledSiteRemoval($site, $at, $reason !== '' ? $reason : null);
            $this->site = $site->fresh();
            $this->closeRemoveSiteModal();
            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('This site will be removed in 30 minutes. Cancel from here anytime before that.'));
            }

            return null;
        }

        if ($this->removeSiteMode === 'scheduled') {
            $this->validate([
                'scheduledSiteRemovalDate' => ['required', 'date'],
                'siteDeletionReason' => ['nullable', 'string', 'max:2000'],
            ]);
            $at = Carbon::parse($this->scheduledSiteRemovalDate, config('app.timezone'))->endOfDay();
            if ($at->lte(now())) {
                $this->addError('scheduledSiteRemovalDate', __('Pick a date whose end is still in the future (app timezone).'));

                return null;
            }

            $reason = trim($this->siteDeletionReason);
            $this->persistScheduledSiteRemoval($site, $at, $reason !== '' ? $reason : null);
            $this->site = $site->fresh();
            $this->closeRemoveSiteModal();
            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('This site is scheduled for removal at the end of :date.', [
                    'date' => $at->toFormattedDateString(),
                ]));
            }

            return null;
        }

        // Immediate removal: defer to the host component's deleteSite()
        // implementation so the existing audit + redirect logic stays in
        // one place and we don't fork the side-effects.
        $this->closeRemoveSiteModal();

        return $this->deleteSite();
    }

    private function persistScheduledSiteRemoval(Site $site, Carbon $at, ?string $reason): void
    {
        $meta = $site->meta ?? [];
        if ($reason !== null && $reason !== '') {
            $meta['scheduled_deletion_reason'] = $reason;
        } else {
            unset($meta['scheduled_deletion_reason']);
        }

        if ($site->organization) {
            $auditNew = ['scheduled_deletion_at' => $at->toIso8601String()];
            if ($reason !== null && $reason !== '') {
                $auditNew['reason'] = $reason;
            }
            audit_log($site->organization, auth()->user(), 'site.deletion_scheduled', $site, null, $auditNew);
        }

        $site->update([
            'scheduled_deletion_at' => $at,
            'meta' => $meta,
        ]);
    }
}
