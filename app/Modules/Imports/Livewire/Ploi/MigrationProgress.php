<?php

declare(strict_types=1);

namespace App\Modules\Imports\Livewire\Ploi;

use App\Modules\Imports\Jobs\RunMigrationStepJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Modules\Cloud\Cloudflare\CloudflareDnsService;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Imports\Services\MigrationPlanner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Migration run inspector. Surfaces the declared step plan (per-server +
 * per-site) with status pills so the user always knows where their migration
 * is, what failed, and what to do next.
 *
 * Polling: 3s while any step is `running`, 10s while there are pending
 * stage-tier steps with no failures (so the page picks up the next step
 * landing without burning the database), off entirely when everything is
 * terminal.
 */
#[Layout('layouts.app')]
class MigrationProgress extends Component
{
    use DispatchesToastNotifications;

    public ImportServerMigration $migration;

    public function mount(ImportServerMigration $migration): void
    {
        $this->authorizeView($migration);
        $this->migration = $migration->load(['siteMigrations.steps', 'steps' => fn ($q) => $q->orderBy('sequence')]);
    }

    /**
     * Q18 policy gate: only owners + admins of the migration's org can act on it.
     * View is already gated in mount(); this further-restricts mutating actions.
     */
    protected function authorizeMutate(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->can('operate', $this->migration)) {
            abort(403);
        }
    }

    public function retryFailedStep(string $stepId): void
    {
        $this->authorizeMutate();

        $step = ImportMigrationStep::query()
            ->where('id', $stepId)
            ->where('import_server_migration_id', $this->migration->id)
            ->where('status', ImportMigrationStep::STATUS_FAILED)
            ->first();

        if ($step === null) {
            $this->toastError(__('Step is not in a retryable state.'));

            return;
        }

        $step->status = ImportMigrationStep::STATUS_PENDING;
        $step->error_message = null;
        $step->save();

        RunMigrationStepJob::dispatch($step->id);

        if ($this->migration->organization && auth()->user()) {
            audit_log($this->migration->organization, auth()->user(), 'import.migration.step_retried', $this->migration, null, [
                'step_id' => $step->id,
                'step_key' => $step->step_key,
                'attempt' => $step->attempts,
            ]);
        }

        $this->toastSuccess(__('Retry queued.'));
    }

    /**
     * Q13 skip path: mark a failed non-critical step as SKIPPED and resume
     * the migration. Bounded by ImportMigrationStep::SKIPPABLE_KEYS so the
     * user can't accidentally skip clone_repo or restore_database — those
     * would leave the migrated site empty / data-less.
     */
    public function skipFailedStep(string $stepId): void
    {
        $this->authorizeMutate();

        $step = ImportMigrationStep::query()
            ->where('id', $stepId)
            ->where('import_server_migration_id', $this->migration->id)
            ->where('status', ImportMigrationStep::STATUS_FAILED)
            ->first();

        if ($step === null) {
            $this->toastError(__('Step is not in a skippable state.'));

            return;
        }
        if (! $step->isSkippable()) {
            $this->toastError(__('This step is load-bearing and can\'t be skipped; retry or abort instead.'));

            return;
        }

        $step->status = ImportMigrationStep::STATUS_SKIPPED;
        $step->error_message = ($step->error_message ? $step->error_message."\n" : '').'Skipped by user.';
        $step->finished_at = now();
        $step->save();

        // Dispatch the next pending step so the migration keeps moving.
        $next = ImportMigrationStep::query()
            ->where('import_server_migration_id', $this->migration->id)
            ->where('status', ImportMigrationStep::STATUS_PENDING)
            ->whereNotIn('step_key', MigrationPlanner::CUTOVER_STEPS)
            ->orderBy('sequence')
            ->first();

        if ($next !== null) {
            RunMigrationStepJob::dispatch($next->id);
        }

        if ($this->migration->organization && auth()->user()) {
            audit_log($this->migration->organization, auth()->user(), 'import.migration.step_skipped', $this->migration, null, [
                'step_id' => $step->id,
                'step_key' => $step->step_key,
            ]);
        }

        $this->toastSuccess(__('Step skipped. Migration resumed.'));
    }

    /** Toggles the abort confirmation modal. */
    public bool $confirmingAbort = false;

    public function requestAbort(): void
    {
        $this->authorizeMutate();
        $this->confirmingAbort = true;
    }

    public function cancelAbort(): void
    {
        $this->confirmingAbort = false;
    }

    /**
     * Q13 abort path: user explicitly tears down a non-terminal migration.
     * Marks the parent ABORTED, cascade-marks pending steps as SKIPPED, each
     * in-flight child as ABORTED, and queues the revoke_ssh_key step so the
     * trust window closes immediately rather than waiting for the 168h
     * expire-paused sweep. Does NOT delete the dply target server — that
     * would be a separate confirmation per Q13 (impacts billing, irreversible).
     */
    public function abortMigration(): void
    {
        $this->authorizeMutate();
        $this->confirmingAbort = false;

        $migration = $this->migration->refresh();
        $terminal = [
            ImportServerMigration::STATUS_COMPLETED,
            ImportServerMigration::STATUS_PARTIAL,
            ImportServerMigration::STATUS_ABORTED,
            ImportServerMigration::STATUS_EXPIRED,
        ];
        if (in_array($migration->status, $terminal, true)) {
            $this->toastError(__('Migration is already terminal (status: :status).', ['status' => $migration->status]));

            return;
        }

        // Cascade: pending steps → skipped, in-flight site migrations → aborted.
        ImportMigrationStep::query()
            ->where('import_server_migration_id', $migration->id)
            ->whereIn('status', [ImportMigrationStep::STATUS_PENDING, ImportMigrationStep::STATUS_RUNNING])
            ->whereNotIn('step_key', [ImportMigrationStep::KEY_REVOKE_SSH_KEY])
            ->update([
                'status' => ImportMigrationStep::STATUS_SKIPPED,
                'finished_at' => now(),
                'error_message' => 'Migration aborted by user before this step ran.',
            ]);
        ImportSiteMigration::query()
            ->where('import_server_migration_id', $migration->id)
            ->whereIn('status', [
                ImportSiteMigration::STATUS_PENDING,
                ImportSiteMigration::STATUS_STAGING,
                ImportSiteMigration::STATUS_READY_FOR_CUTOVER,
                ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS,
            ])
            ->update([
                'status' => ImportSiteMigration::STATUS_ABORTED,
            ]);

        $migration->status = ImportServerMigration::STATUS_ABORTED;
        $migration->completed_at = now();
        $migration->failure_summary = ($migration->failure_summary ? $migration->failure_summary."\n" : '').'Aborted by user via UI.';
        $migration->save();

        if ($migration->organization && auth()->user()) {
            audit_log($migration->organization, auth()->user(), 'import.migration.aborted', $migration);
        }

        // Dispatch the revoke step immediately so the ephemeral key is closed
        // without waiting for the next scheduled sweep.
        $revokeStep = ImportMigrationStep::query()
            ->where('import_server_migration_id', $migration->id)
            ->where('step_key', ImportMigrationStep::KEY_REVOKE_SSH_KEY)
            ->where('status', ImportMigrationStep::STATUS_PENDING)
            ->orderBy('sequence')
            ->first();
        if ($revokeStep !== null && $migration->ssh_key_source_id !== null && $migration->ssh_key_revoked_at === null) {
            RunMigrationStepJob::dispatch($revokeStep->id);
        }

        $this->toastSuccess(__('Migration aborted. Ephemeral SSH key revocation queued.'));
    }

    /**
     * Begin cutover for a single child site. Only valid when the site is in
     * READY_FOR_CUTOVER and no cutover step is already running. Dispatches the
     * first cutover step (cutover_maintenance_on); from there the orchestrator
     * walks the cutover sub-plan.
     */
    public function dismissReviewItem(int $index): void
    {
        $this->authorizeMutate();
        $items = $this->migration->manual_review_items ?? [];
        if (! isset($items[$index])) {
            return;
        }
        $items[$index]['dismissed_at'] = now()->toIso8601String();
        $this->migration->manual_review_items = array_values($items);
        $this->migration->save();
        $this->toastSuccess(__('Marked reviewed.'));
    }

    /**
     * Q13 rollback path: site is in cutover_failed → attempt to delete the
     * dply A record we created during cutover_dns_swap so DNS reverts to the
     * source. Best-effort: if the delete fails (record removed, credential
     * gone) the user still gets the manual-instructions panel; status flips
     * to cutover_rolled_back either way so the migration leaves the failed
     * state.
     */
    public function rollbackCutoverDns(string $siteMigrationId): void
    {
        $this->authorizeMutate();

        $child = ImportSiteMigration::query()
            ->where('id', $siteMigrationId)
            ->where('import_server_migration_id', $this->migration->id)
            ->first();

        if ($child === null) {
            $this->toastError(__('Site migration not found.'));

            return;
        }
        if ($child->status !== ImportSiteMigration::STATUS_CUTOVER_FAILED) {
            $this->toastError(__('Site is not in a rolled-back-able state (current: :status).', ['status' => $child->status]));

            return;
        }

        $dnsStep = ImportMigrationStep::query()
            ->where('import_site_migration_id', $child->id)
            ->where('step_key', ImportMigrationStep::KEY_CUTOVER_DNS_SWAP)
            ->where('status', ImportMigrationStep::STATUS_SUCCEEDED)
            ->first();

        $deleted = false;
        if ($dnsStep !== null && ! empty($dnsStep->result_data['record_id'])) {
            $deleted = $this->tryDeleteDnsRecord($dnsStep->result_data ?? []) === null;
        }

        $child->status = ImportSiteMigration::STATUS_CUTOVER_ROLLED_BACK;
        $child->save();

        if ($this->migration->organization && auth()->user()) {
            audit_log($this->migration->organization, auth()->user(), 'import.migration.cutover_rolled_back', $this->migration, null, [
                'site_migration_id' => $child->id,
                'domain' => $child->domain,
                'dns_record_deleted' => $deleted,
            ]);
        }

        $sourceLabel = $this->migration->source === 'forge' ? 'Forge' : 'Ploi';
        if ($deleted) {
            $this->toastSuccess(__('DNS rollback dispatched. Verify your domain now resolves back to :source.', ['source' => $sourceLabel]));
        } else {
            $this->toastError(__('DNS rollback could not be automated; follow the manual steps shown.'));
        }
    }

    /**
     * Q13 escape hatch: user handled the failed cutover outside dply. Mark
     * the site as cutover_rolled_back so the progress page stops flagging
     * action-required.
     */
    public function markCutoverResolvedManually(string $siteMigrationId): void
    {
        $this->authorizeMutate();
        $child = ImportSiteMigration::query()
            ->where('id', $siteMigrationId)
            ->where('import_server_migration_id', $this->migration->id)
            ->first();
        if ($child === null || $child->status !== ImportSiteMigration::STATUS_CUTOVER_FAILED) {
            $this->toastError(__('Site is not in a manually-resolvable state.'));

            return;
        }
        $child->status = ImportSiteMigration::STATUS_CUTOVER_ROLLED_BACK;
        $child->failure_summary = ($child->failure_summary ? $child->failure_summary."\n" : '').'Manually resolved by user.';
        $child->save();

        if ($this->migration->organization && auth()->user()) {
            audit_log($this->migration->organization, auth()->user(), 'import.migration.cutover_marked_resolved', $this->migration, null, [
                'site_migration_id' => $child->id,
                'domain' => $child->domain,
            ]);
        }

        $this->toastSuccess(__('Marked resolved.'));
    }

    /**
     * @param  array<string, mixed>  $resultData  result_data of the cutover_dns_swap step
     * @return string|null null on success, error reason string on failure
     */
    protected function tryDeleteDnsRecord(array $resultData): ?string
    {
        $zone = (string) ($resultData['zone'] ?? '');
        $recordId = $resultData['record_id'] ?? null;
        if ($zone === '' || $recordId === null) {
            return 'malformed_result_data';
        }
        $credential = $this->resolveDnsCredential();
        if ($credential === null) {
            return 'no_dns_credential';
        }
        try {
            match ($credential->provider) {
                'digitalocean' => (new DigitalOceanService($credential->getApiToken() ?? ''))
                    ->deleteDomainRecord($zone, (int) $recordId),
                'cloudflare' => (new CloudflareDnsService($credential))
                    ->deleteDnsRecord($zone, (string) $recordId),
                default => throw new \RuntimeException('no_adapter_for_provider:'.$credential->provider),
            };

            return null;
        } catch (\Throwable $e) {
            Log::warning('cutover DNS rollback delete failed', [
                'migration_id' => $this->migration->id,
                'error' => $e->getMessage(),
            ]);

            return 'delete_failed';
        }
    }

    protected function resolveDnsCredential(): ?ProviderCredential
    {
        $orgId = $this->migration->organization_id;
        if (! is_string($orgId) || $orgId === '') {
            return null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $orgId)
            ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
            ->orderBy('created_at')
            ->first();
    }

    public function beginCutover(string $siteMigrationId): void
    {
        $this->authorizeMutate();
        $child = ImportSiteMigration::query()
            ->where('id', $siteMigrationId)
            ->where('import_server_migration_id', $this->migration->id)
            ->first();

        if ($child === null) {
            $this->toastError(__('Site migration not found.'));

            return;
        }
        if ($child->status !== ImportSiteMigration::STATUS_READY_FOR_CUTOVER) {
            $this->toastError(__('Site is not ready for cutover yet (status: :status).', ['status' => $child->status]));

            return;
        }

        $firstCutoverStep = ImportMigrationStep::query()
            ->where('import_site_migration_id', $child->id)
            ->whereIn('step_key', MigrationPlanner::CUTOVER_STEPS)
            ->where('status', ImportMigrationStep::STATUS_PENDING)
            ->orderBy('sequence')
            ->first();

        if ($firstCutoverStep === null) {
            $this->toastError(__('No pending cutover steps found.'));

            return;
        }

        RunMigrationStepJob::dispatch($firstCutoverStep->id);

        if ($this->migration->organization && auth()->user()) {
            audit_log($this->migration->organization, auth()->user(), 'import.migration.cutover_begun', $this->migration, null, [
                'site_migration_id' => $child->id,
                'domain' => $child->domain,
            ]);
        }

        $this->toastSuccess(__('Cutover started for :domain.', ['domain' => $child->domain]));
    }

    public function render(): View
    {
        $this->migration->refresh();
        $this->migration->load(['siteMigrations.steps', 'steps' => fn ($q) => $q->orderBy('sequence'), 'targetServer']);

        return view('livewire.imports.ploi.migration-progress', [
            'migration' => $this->migration,
            'serverSteps' => $this->migration->steps->whereNull('import_site_migration_id')->values(),
            'shouldPoll' => $this->shouldPoll(),
        ]);
    }

    protected function shouldPoll(): bool
    {
        return $this->migration->steps()
            ->whereIn('status', [ImportMigrationStep::STATUS_PENDING, ImportMigrationStep::STATUS_RUNNING])
            ->exists();
    }

    protected function authorizeView(ImportServerMigration $migration): void
    {
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }
        // Org match + admin/owner role per Q18.
        if (! $user->can('view', $migration)) {
            abort(403);
        }
    }
}
