<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Enums\SiteType;
use App\Jobs\RunLaravelScaffoldJob;
use App\Jobs\RunWordPressScaffoldJob;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteCreateScaffold
{
    public function updatedFormType(string $value): void
    {
        $this->form->applyDefaultsForType($value);
    }

    /**
     * Switch the wizard to "Import an existing repo" mode (default).
     */
    public function chooseImportMode(): void
    {
        $this->form->mode = 'import';
        $this->form->scaffold_framework = '';
        $this->form->scaffold_admin_email = '';
    }

    /**
     * Switch the wizard to "Scaffold a new app" mode (Q3 branch).
     * Hides the import form; reveals the tile picker + admin email field.
     */
    public function chooseScaffoldMode(): void
    {
        $this->form->mode = 'scaffold';
    }

    /**
     * Pick a scaffold tile (laravel | wordpress).
     */
    public function chooseScaffoldFramework(string $framework): void
    {
        if (! in_array($framework, ['laravel', 'wordpress'], true)) {
            return;
        }
        $this->form->scaffold_framework = $framework;
    }

    /**
     * Submit handler for scaffold mode.
     *
     * Validates the three scaffold fields (slug + framework + admin email),
     * verifies the feature flag, creates a Site row in STATUS_SCAFFOLDING,
     * then redirects to the (still-WIP) scaffold journey.
     *
     * Pipeline execution lands in PR 5 (Laravel) / PR 6 (WordPress);
     * for now this only persists the Site row + flashes a placeholder
     * message so the wizard surface can be exercised end-to-end.
     */
    public function storeScaffold(): mixed
    {
        $this->authorize('update', $this->server);

        if (! config('dply.scaffold_v1_enabled')) {
            $this->addError('form.mode', __('Scaffolding is not enabled on this dply install yet.'));

            return null;
        }

        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);
        abort_if($this->server->organization_id !== $org->id, 403);

        if ($this->siteQuotaReached($org)) {
            return null;
        }

        $this->authorize('create', Site::class);

        // Database-engine compat per Q5: WordPress requires MySQL/MariaDB.
        // We don't auto-block here because the server's engine list may
        // include MariaDB even on a Postgres-default server; the pipeline
        // (PR 6) will pick a compatible engine and surface a wizard error
        // before this if none exists. v1 keeps the gate light at submit
        // time and defers strict checks to the journey's preflight step.

        $this->form->validate([
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['required', 'in:scaffold'],
            'scaffold_framework' => ['required', 'in:laravel,wordpress'],
            'scaffold_admin_email' => ['required', 'email', 'max:255'],
            'primary_hostname' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'scaffold_framework' => __('starter template'),
            'scaffold_admin_email' => __('admin email'),
        ]);

        $slug = Str::slug($this->form->name) ?: 'site';

        $site = Site::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'organization_id' => $this->server->organization_id,
            'name' => $this->form->name,
            'slug' => $slug,
            // Both Laravel and WordPress are PHP-shaped sites. Locking
            // type=php keeps existing site listings + filters consistent;
            // the framework-specific tabs render off meta.scaffold.framework.
            'type' => SiteType::Php,
            'runtime' => 'php',
            'status' => Site::STATUS_SCAFFOLDING,
            'meta' => [
                'scaffold' => [
                    'framework' => $this->form->scaffold_framework,
                    'admin_email' => $this->form->scaffold_admin_email,
                    'requested_hostname' => trim($this->form->primary_hostname) !== ''
                        ? trim($this->form->primary_hostname)
                        : null,
                    'requested_at' => now()->toISOString(),
                    'requested_by_user_id' => auth()->id(),
                ],
            ],
        ]);

        // Dispatch the framework-specific pipeline. The Site is already
        // in STATUS_SCAFFOLDING; the worker walks it through the steps
        // recorded under meta.scaffold.steps[] for the journey UI (PR 7).
        if ($this->form->scaffold_framework === 'laravel') {
            RunLaravelScaffoldJob::dispatch($site->id);
            session()->flash('info', __('Laravel site queued for scaffolding. The pipeline runs in the background.'));
        } else {
            RunWordPressScaffoldJob::dispatch($site->id);
            session()->flash('info', __('WordPress site queued for scaffolding. The pipeline runs in the background.'));
        }

        if ($this->server->organization) {
            audit_log(
                $this->server->organization,
                auth()->user(),
                'site.created',
                $site,
                null,
                [
                    'name' => $site->name,
                    'slug' => $site->slug,
                    'server_id' => (string) $this->server->id,
                    'mode' => 'scaffold',
                    'scaffold_framework' => $this->form->scaffold_framework,
                ],
            );
        }

        // Land on the site workspace — the scaffold-install flow renders inside
        // the shell (Show) while STATUS_SCAFFOLDING keeps it pre-workspace.
        return $this->redirect(route('sites.show', [
            'server' => $this->server,
            'site' => $site,
        ]), navigate: true);
    }
}
