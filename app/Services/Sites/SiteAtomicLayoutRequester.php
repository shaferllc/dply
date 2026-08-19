<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Enums\DeploymentMethod;
use App\Jobs\ConvertSiteToAtomicLayoutJob;
use App\Models\Site;

/**
 * Single write path for simple↔atomic layout transitions. No caller may stamp
 * deploy_strategy=atomic before convert finishes.
 */
class SiteAtomicLayoutRequester
{
    public function __construct(
        private SiteDeployCoordinator $coordinator,
    ) {}

    public function requestAtomic(Site $site, ?string $userId, bool $confirmed): AtomicLayoutRequestResult
    {
        $site->refresh();

        if ($site->isAtomicDeploys() && ! $site->isConvertingAtomicLayout() && ! $site->isDisablingAtomicLayout()) {
            return AtomicLayoutRequestResult::noop(__('Zero-downtime is already enabled.'));
        }

        if ($site->isDisablingAtomicLayout()) {
            $this->clearAtomicLayoutMeta($site);

            return AtomicLayoutRequestResult::noop(__('Cancelled the pending disable. Zero-downtime stays on.'));
        }

        if ($site->isConvertingAtomicLayout()) {
            return AtomicLayoutRequestResult::refused(__('Layout conversion is already running.'));
        }

        if ($this->coordinator->inProgress($site) || $site->hasRunningDeployment()) {
            return AtomicLayoutRequestResult::refused(__('A deploy is already running. Wait for it to finish, then convert.'));
        }

        if (! $confirmed) {
            return AtomicLayoutRequestResult::needsConfirm(__('Confirm converting this site to a releases layout.'));
        }

        $this->markStatus($site, 'converting');

        $job = new ConvertSiteToAtomicLayoutJob($site->id, $userId);
        $runId = $job->seed();
        $this->storeConsoleActionId($site, $runId);
        ConvertSiteToAtomicLayoutJob::dispatch($site->id, $userId, $runId);

        return AtomicLayoutRequestResult::queued(__('Converting to zero-downtime. Deploys are locked until this finishes.'));
    }

    public function requestFlat(Site $site): AtomicLayoutRequestResult
    {
        $site->refresh();

        if ($site->isConvertingAtomicLayout()) {
            return AtomicLayoutRequestResult::refused(__('Wait for the layout conversion to finish before disabling zero-downtime.'));
        }

        if (! $site->isAtomicDeploys() && ! $site->isDisablingAtomicLayout()) {
            return AtomicLayoutRequestResult::noop(__('Zero-downtime is already off.'));
        }

        if ($site->isDisablingAtomicLayout()) {
            return AtomicLayoutRequestResult::noop(__('Zero-downtime will turn off on the next successful deploy.'));
        }

        $this->armFlat($site);
        foreach ($site->workerReplicaSites() as $replica) {
            $this->armFlat($replica);
        }

        return AtomicLayoutRequestResult::armed(__('Disabling on next deploy. The next deploy uses the simple engine; the site stays atomic until that deploy succeeds.'));
    }

    public function markFailed(Site $site, string $error): void
    {
        $this->markStatus($site, 'failed', $error);
    }

    public function markConverted(Site $site): void
    {
        $envPath = trim((string) ($site->env_file_path ?? ''));
        if ($envPath === '') {
            $envPath = rtrim($site->effectiveRepositoryPath(), '/').'/shared/.env';
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['shared_storage'] = true;
        unset($meta['atomic_layout'], $meta['deploy_layout_migration']);

        $updates = [
            'deploy_strategy' => 'atomic',
            'env_file_path' => $envPath,
            'meta' => $meta,
        ];

        $method = DeploymentMethod::forSite($site);
        if ($method->placement() === 'flat') {
            $updates['deploy_method'] = DeploymentMethod::Atomic->value;
        }

        $site->forceFill($updates)->save();
    }

    public function markFlattened(Site $site): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        unset($meta['atomic_layout'], $meta['deploy_layout_migration']);

        $site->forceFill([
            'deploy_strategy' => 'simple',
            'deploy_method' => DeploymentMethod::Flat->value,
            'meta' => $meta,
        ])->save();
    }

    private function armFlat(Site $site): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['atomic_layout'] = [
            'status' => 'disabling',
            'started_at' => now()->toIso8601String(),
        ];
        $meta['deploy_layout_migration'] = [
            'from' => 'atomic',
            'to' => 'flat',
            'armed_at' => now()->toIso8601String(),
        ];
        $site->forceFill(['meta' => $meta])->save();
    }

    private function markStatus(Site $site, string $status, ?string $error = null): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $current = is_array($meta['atomic_layout'] ?? null) ? $meta['atomic_layout'] : [];
        $current['status'] = $status;
        $current['started_at'] = $current['started_at'] ?? now()->toIso8601String();
        if ($error !== null) {
            $current['error'] = mb_substr($error, 0, 2000);
        } else {
            unset($current['error']);
        }
        $meta['atomic_layout'] = $current;
        $site->forceFill(['meta' => $meta])->save();
    }

    private function storeConsoleActionId(Site $site, string $runId): void
    {
        $site->refresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        $current = is_array($meta['atomic_layout'] ?? null) ? $meta['atomic_layout'] : [];
        $current['console_action_id'] = $runId;
        $meta['atomic_layout'] = $current;
        $site->forceFill(['meta' => $meta])->save();
    }

    private function clearAtomicLayoutMeta(Site $site): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        unset($meta['atomic_layout'], $meta['deploy_layout_migration']);
        $site->forceFill(['meta' => $meta])->save();
    }
}
