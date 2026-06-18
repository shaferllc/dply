<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Services\Servers\SupervisorDaemonAudit;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDaemonImport
{


    public function copyProgramToServer(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'copy_source_program_id' => 'required|ulid',
            'copy_target_server_id' => [
                'required',
                'ulid',
                Rule::exists('servers', 'id')->where(
                    fn ($q) => $q->where('organization_id', $this->server->organization_id)
                ),
            ],
            'copy_new_slug' => 'required|string|max:64|regex:/^[a-z0-9\-]+$/',
        ]);

        if ($this->copy_target_server_id === $this->server->id) {
            $this->toastError(__('Choose a different server than the current one.'));

            return;
        }

        $source = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->copy_source_program_id)
            ->first();
        if (! $source) {
            $this->toastError(__('Source program not found.'));

            return;
        }

        $target = Server::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereKey($this->copy_target_server_id)
            ->first();
        if (! $target) {
            $this->toastError(__('Target server not found.'));

            return;
        }

        if (SupervisorProgram::query()->where('server_id', $target->id)->where('slug', $this->copy_new_slug)->exists()) {
            $this->toastError(__('A program with that slug already exists on the target server.'));

            return;
        }

        SupervisorProgram::query()->create([
            'server_id' => $target->id,
            'site_id' => null,
            'slug' => $this->copy_new_slug,
            'program_type' => $source->program_type,
            'command' => $source->command,
            'directory' => $source->directory,
            'user' => $source->user,
            'numprocs' => $source->numprocs,
            'is_active' => $source->is_active,
            'env_vars' => $source->env_vars,
            'stdout_logfile' => $source->stdout_logfile,
            'stderr_logfile' => $source->stderr_logfile,
            'priority' => $source->priority,
            'startsecs' => $source->startsecs,
            'stopwaitsecs' => $source->stopwaitsecs,
            'autorestart' => $source->autorestart,
            'redirect_stderr' => $source->redirect_stderr ?? true,
        ]);

        SupervisorDaemonAudit::log($this->server->fresh(), $source, 'program_copied_to_server', [
            'target_server_id' => $target->id,
            'new_slug' => $this->copy_new_slug,
        ]);

        $this->copy_source_program_id = '';
        $this->copy_target_server_id = '';
        $this->copy_new_slug = '';
        $this->toastSuccess(__('Program copied to the target server. Open that server’s Daemons page and sync Supervisor.'));
    }

    public function importProgramFromSite(string $programId): void
    {
        $this->authorize('update', $this->server);

        $targetSite = $this->resolveImportTargetSite();
        if ($targetSite === null) {
            $this->toastError(__('Choose a destination site for the import.'));

            return;
        }

        $this->validate([
            'import_from_site_id' => [
                'required',
                'ulid',
                Rule::exists('sites', 'id')->where(fn ($q) => $q->where('server_id', $this->server->id)),
            ],
        ]);

        if ($this->import_from_site_id === $targetSite->id) {
            $this->toastError(__('Choose a different site than the import destination.'));

            return;
        }

        $sourceSite = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->import_from_site_id)
            ->first();

        if ($sourceSite === null) {
            $this->toastError(__('Source site not found.'));

            return;
        }

        $source = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $sourceSite->id)
            ->whereKey($programId)
            ->first();

        if ($source === null) {
            $this->toastError(__('Source program not found.'));

            return;
        }

        $created = $this->duplicateProgramForSite($source, $sourceSite, $targetSite);

        SupervisorDaemonAudit::log($this->server->fresh(), $created, 'program_imported_from_site', [
            'source_site_id' => $sourceSite->id,
            'target_site_id' => $targetSite->id,
            'source_program_id' => $source->id,
        ]);

        $this->toastSuccess(__('Imported :slug for :site. Sync Supervisor to apply on the server.', [
            'slug' => $created->slug,
            'site' => $targetSite->name,
        ]));
    }

    public function importAllProgramsFromSite(): void
    {
        $this->authorize('update', $this->server);

        $targetSite = $this->resolveImportTargetSite();
        if ($targetSite === null) {
            $this->toastError(__('Choose a destination site for the import.'));

            return;
        }

        $this->validate([
            'import_from_site_id' => [
                'required',
                'ulid',
                Rule::exists('sites', 'id')->where(fn ($q) => $q->where('server_id', $this->server->id)),
            ],
        ]);

        if ($this->import_from_site_id === $targetSite->id) {
            $this->toastError(__('Choose a different site than the import destination.'));

            return;
        }

        $sourceSite = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->import_from_site_id)
            ->first();

        if ($sourceSite === null) {
            $this->toastError(__('Source site not found.'));

            return;
        }

        $sources = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $sourceSite->id)
            ->orderBy('slug')
            ->get();

        if ($sources->isEmpty()) {
            $this->toastError(__('No programs are linked to the source site.'));

            return;
        }

        $imported = 0;
        foreach ($sources as $source) {
            $created = $this->duplicateProgramForSite($source, $sourceSite, $targetSite);
            SupervisorDaemonAudit::log($this->server->fresh(), $created, 'program_imported_from_site', [
                'source_site_id' => $sourceSite->id,
                'target_site_id' => $targetSite->id,
                'source_program_id' => $source->id,
            ]);
            $imported++;
        }

        $this->toastSuccess(trans_choice(
            'Imported :count program into :site. Sync Supervisor to apply on the server.|Imported :count programs into :site. Sync Supervisor to apply on the server.',
            $imported,
            ['count' => $imported, 'site' => $targetSite->name],
        ));
    }

    protected function resolveImportTargetSite(): ?Site
    {
        $targetSiteId = $this->context_site_id ?: ($this->import_to_site_id !== '' ? $this->import_to_site_id : null);
        if ($targetSiteId === null) {
            return null;
        }

        return Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($targetSiteId)
            ->first();
    }

    protected function duplicateProgramForSite(
        SupervisorProgram $source,
        Site $sourceSite,
        Site $targetSite,
    ): SupervisorProgram {
        $slug = $this->resolveUniqueProgramSlug($source->slug, $targetSite);

        return SupervisorProgram::query()->create([
            'server_id' => $this->server->id,
            'site_id' => $targetSite->id,
            'slug' => $slug,
            'program_type' => $source->program_type,
            'command' => $this->remapSiteScopedValue($source->command, $sourceSite, $targetSite),
            'directory' => $this->remapSiteScopedValue($source->directory, $sourceSite, $targetSite),
            'user' => $targetSite->effectiveSystemUser($this->server),
            'numprocs' => $source->numprocs,
            'is_active' => $source->is_active,
            'env_vars' => $source->env_vars,
            'stdout_logfile' => $source->stdout_logfile !== null && $source->stdout_logfile !== ''
                ? $this->remapSiteScopedValue($source->stdout_logfile, $sourceSite, $targetSite)
                : null,
            'stderr_logfile' => $source->stderr_logfile !== null && $source->stderr_logfile !== ''
                ? $this->remapSiteScopedValue($source->stderr_logfile, $sourceSite, $targetSite)
                : null,
            'priority' => $source->priority,
            'startsecs' => $source->startsecs,
            'stopwaitsecs' => $source->stopwaitsecs,
            'autorestart' => $source->autorestart,
            'redirect_stderr' => $source->redirect_stderr ?? true,
        ]);
    }

    protected function resolveUniqueProgramSlug(string $baseSlug, Site $targetSite): string
    {
        $slug = Str::limit($baseSlug, 64, '');
        if (! SupervisorProgram::query()->where('server_id', $this->server->id)->where('slug', $slug)->exists()) {
            return $slug;
        }

        $suffix = Str::slug($targetSite->slug ?: $targetSite->name) ?: 'site';
        $candidate = Str::limit(rtrim($baseSlug, '-').'-'.$suffix, 64, '');
        if (! SupervisorProgram::query()->where('server_id', $this->server->id)->where('slug', $candidate)->exists()) {
            return $candidate;
        }

        $i = 2;
        do {
            $candidate = Str::limit(rtrim($baseSlug, '-').'-'.$suffix.'-'.$i, 64, '');
            $i++;
        } while (SupervisorProgram::query()->where('server_id', $this->server->id)->where('slug', $candidate)->exists());

        return $candidate;
    }

    protected function remapSiteScopedValue(string $value, Site $fromSite, Site $toSite): string
    {
        $fromBase = rtrim($fromSite->effectiveRepositoryPath(), '/');
        $toBase = rtrim($toSite->effectiveRepositoryPath(), '/');

        if ($fromBase === '' || $fromBase === $toBase) {
            return $value;
        }

        if (str_contains($value, $fromBase)) {
            return str_replace($fromBase, $toBase, $value);
        }

        if ($value === $fromBase.'/current' || $value === $fromBase) {
            return $toBase.'/current';
        }

        return $value;
    }
}
