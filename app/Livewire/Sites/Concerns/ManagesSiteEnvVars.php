<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\PushSiteEnvJob;
use App\Jobs\ScanSiteEnvRequirementsJob;
use App\Jobs\SyncEnvFromServerJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteEnvPushScheduler;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteEnvVars
{
    public string $new_env_key = '';

    public string $new_env_value = '';

    /** Optional `# comment` rendered above the KEY=value line on the server. */
    public string $new_env_comment = '';

    /** Multi-line .env block pasted into the bulk-import disclosure inside the Add modal. */
    public string $bulk_env_input = '';

    /** When non-null, the keys list shows an inline edit form for this key. */
    public ?string $editing_env_key = null;

    public string $editing_env_value = '';

    public string $editing_env_comment = '';

    /**
     * Server-side reveal state — keys the operator has clicked Show for, this render.
     * Stored on the component (not Alpine) so reveals survive re-renders triggered
     * by edit/save actions on neighboring rows.
     *
     * @var list<string>
     */
    public array $revealed_env_keys = [];

    /**
     * Keys ticked for bulk removal (per-row checkboxes). Cleared after a bulk
     * remove, which writes the cache once and pushes once.
     *
     * @var list<string>
     */
    public array $selected_env_keys = [];

    /**
     * Operator-overridable absolute path on the host where the .env file is
     * read/written. Empty = use the default ($effectiveEnvDirectory/.env).
     * Stored on the Site row's `env_file_path` column when saved.
     */
    public string $env_file_path_override = '';

    /**
     * Modal inputs for the "Add missing variables" flow, keyed by the missing
     * required env KEY. Pre-seeded from the scanner's .env.example samples when
     * the modal opens; only non-empty entries are written on submit.
     *
     * @var array<string, string>
     */
    public array $missing_env_values = [];

    /**
     * Scratch buffer for the "paste a .env to auto-fill" box in the missing
     * variables modal. Parsed by {@see fillMissingFromPaste()} into the
     * matching {@see $missing_env_values} inputs; never persisted directly.
     */
    public string $missing_env_paste = '';

    /**
     * Dispatches the env-push job (via console banner). The actual SSH write
     * happens in the worker; the banner streams progress to the page top.
     * One-in-flight per site is enforced by PushSiteEnvJob's ShouldBeUnique
     * guard, so back-to-back clicks coalesce into a single push that uses
     * the latest cache state.
     */
    public function pushEnvToServer(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not support pushing a .env file over SSH.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('env_push');

        PushSiteEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction($run, __('Environment file pushed to server.'), __('Environment push did not finish.'));
        $this->toastConsoleActionQueued();
    }

    /**
     * Lazy first-visit sync. Fired by wire:init on the Environment partial:
     * the page renders synchronously, then this runs in a follow-up request,
     * dispatching the env-sync job when (and only when) we've never touched
     * the cache before. Subsequent visits with a populated cache are no-ops.
     *
     * Conditions for firing:
     *   - Runtime supports a server .env file (VM hosts only)
     *   - Cache is empty AND has no origin (truly first visit — never synced
     *     and never edited)
     *   - No env_sync job already in flight (idempotent against navigation
     *     bouncing in/out of the section)
     *
     * Auth uses 'view' rather than 'update' because read is a lower-priv
     * action and the operator hasn't asked to mutate anything yet.
     */
    public function autoSyncIfFirstVisit(): void
    {
        $this->authorize('view', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            return;
        }
        if (filled($this->site->env_file_content) || $this->site->env_cache_origin !== null) {
            return;
        }

        $inFlight = ConsoleAction::query()
            ->forSubject($this->site)
            ->ofKind('env_sync')
            ->notDismissed()
            ->inFlight()
            ->exists();
        if ($inFlight) {
            return;
        }

        $run = $this->seedQueuedConsoleAction('env_sync');
        SyncEnvFromServerJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );
        $this->watchConsoleAction($run, __('Environment synced from server.'), __('Environment sync did not finish.'));
    }

    /**
     * Dispatches a backgrounded job that SSHes into the host, reads the live
     * `.env` file, and writes it into the encrypted env_file_content cache.
     * Mirrors {@see Settings::syncBasicAuthFromServer()}: progress streams to
     * a console_actions row whose banner is mounted on the settings page.
     */
    public function syncEnvFromServer(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not expose a server .env file.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('env_sync');

        SyncEnvFromServerJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction($run, __('Environment synced from server.'), __('Environment sync did not finish.'));
        $this->toastConsoleActionQueued();
    }

    /**
     * One-click "move .env outside docroot" — sets env_file_path to the
     * default convention (/etc/dply/<slug>.env) and dispatches the push job
     * so the file lands at the new location immediately. The doctor finding
     * surfaces the issue; this action resolves it without making the
     * operator type the path.
     */
    public function relocateEnvOutsideDocroot(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not have a server .env to relocate.'));

            return;
        }

        $newPath = '/etc/dply/'.$this->site->slug.'.env';
        $this->site->forceFill(['env_file_path' => $newPath])->save();
        $this->env_file_path_override = $newPath;

        $run = $this->seedQueuedConsoleAction('env_push');
        PushSiteEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Relocated .env to :path.', ['path' => $newPath]),
            __('Relocating .env to :path did not finish.', ['path' => $newPath]),
        );
        $this->toastConsoleActionQueued();
    }

    /**
     * Saves a custom .env file path on the Site row. Empty input clears the
     * override (revert to default $effectiveEnvDirectory/.env). Used by
     * security-conscious operators to relocate the file outside the docroot —
     * e.g. /etc/dply/<slug>.env — so the webserver can never serve it.
     *
     * Path must be absolute. Validation is intentionally strict to avoid
     * accidental relative paths that resolve unpredictably on the host.
     */
    public function saveEnvFilePath(): void
    {
        $this->authorize('update', $this->site);
        $value = trim($this->env_file_path_override);

        if ($value === '') {
            $this->site->forceFill(['env_file_path' => null])->save();
            $this->autoPushAfterCacheMutation(__('Default .env path restored.'));

            return;
        }

        $this->validate([
            'env_file_path_override' => ['required', 'string', 'max:1024', 'regex:/^\/[^\\\\\\0]+$/'],
        ], [
            'env_file_path_override.regex' => __('Path must be absolute (start with /) and not contain backslashes or null bytes.'),
        ]);

        $this->site->forceFill(['env_file_path' => $value])->save();
        $this->autoPushAfterCacheMutation(__('Custom .env path saved.'));
    }

    /**
     * Single-row add: writes one key into the encrypted env cache, then
     * auto-pushes to the server's .env file. The push is synchronous so the
     * operator gets immediate feedback; on push failure the cache update is
     * preserved so they can manually retry via the Push button.
     */
    public function addEnvVar(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_env_key' => 'required|string|max:128|regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
            'new_env_value' => 'nullable|string|max:20000',
            'new_env_comment' => 'nullable|string|max:1000',
        ]);

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $map = $parsed['variables'];
        $comments = $parsed['comments'];
        $map[$this->new_env_key] = (string) $this->new_env_value;
        $trimmedComment = trim($this->new_env_comment);
        if ($trimmedComment !== '') {
            $comments[$this->new_env_key] = $trimmedComment;
        } else {
            unset($comments[$this->new_env_key]);
        }
        $this->site->forceFill([
            'env_file_content' => $writer->render($map, $comments),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.var_added', $this->site, null, [
                'key' => $this->new_env_key,
            ]);
        }

        $this->new_env_key = '';
        $this->new_env_value = '';
        $this->new_env_comment = '';
        $this->dispatch('close-modal', 'add-env-modal');
        $this->autoPushAfterCacheMutation(__('Variable saved.'));
    }

    /**
     * Bulk paste — accepts a multi-line .env block. Existing keys not in the
     * pasted block are preserved (additive merge); pasted keys overwrite
     * matching existing keys (last value wins, matches `.env` semantics).
     */
    public function bulkImportEnvVars(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['bulk_env_input' => 'required|string|max:65535']);

        $incoming = $parser->parse($this->bulk_env_input);
        if ($incoming['errors'] !== []) {
            foreach ($incoming['errors'] as $err) {
                $this->addError('bulk_env_input', $err);
            }

            return;
        }

        $existing = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $mergedVars = array_merge($existing['variables'], $incoming['variables']);
        // Comments merge with incoming taking precedence — pasting `# foo`
        // above an existing KEY in the bulk block REPLACES that KEY's
        // existing comment. Keys not in the paste keep their old comments.
        $mergedComments = array_merge($existing['comments'], $incoming['comments']);

        $this->site->forceFill([
            'env_file_content' => $writer->render($mergedVars, $mergedComments),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $count = count($incoming['variables']);

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.bulk_imported', $this->site, null, [
                'imported_count' => $count,
                'imported_keys' => array_keys($incoming['variables']),
            ]);
        }

        $this->bulk_env_input = '';
        $this->dispatch('close-modal', 'paste-env-modal');
        $this->autoPushAfterCacheMutation(__(':count variable(s) imported.', ['count' => $count]));
    }

    /**
     * Open the inline editor for a single key. Pulls the current value out
     * of the encrypted cache and parks it in editing_env_value for the
     * blade form to bind.
     */
    public function editEnvVar(DotEnvFileParser $parser, string $key): void
    {
        $this->authorize('update', $this->site);
        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        if (! array_key_exists($key, $parsed['variables'])) {
            return;
        }
        $this->editing_env_key = $key;
        $this->editing_env_value = $parsed['variables'][$key];
        $this->editing_env_comment = (string) ($parsed['comments'][$key] ?? '');
    }

    public function cancelEditEnvVar(): void
    {
        $this->editing_env_key = null;
        $this->editing_env_value = '';
        $this->editing_env_comment = '';
    }

    public function saveEditedEnvVar(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'editing_env_key' => 'required|string|max:128|regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
            'editing_env_value' => 'nullable|string|max:20000',
            'editing_env_comment' => 'nullable|string|max:1000',
        ]);

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $map = $parsed['variables'];
        $comments = $parsed['comments'];
        $key = (string) $this->editing_env_key;
        $map[$key] = (string) $this->editing_env_value;
        $trimmedComment = trim($this->editing_env_comment);
        if ($trimmedComment !== '') {
            $comments[$key] = $trimmedComment;
        } else {
            unset($comments[$key]);
        }
        $this->site->forceFill([
            'env_file_content' => $writer->render($map, $comments),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.var_updated', $this->site, null, [
                'key' => $key,
            ]);
        }

        $this->cancelEditEnvVar();
        $this->autoPushAfterCacheMutation(__('Variable updated.'));
    }

    /**
     * Trash button on a key row hits this first; it opens the shared
     * confirm-action modal pointing at {@see removeEnvVar()}. The modal's
     * confirm button dispatches the underlying method via the
     * ConfirmsActionWithModal trait, which container-resolves the
     * DotEnvFileParser / DotEnvFileWriter dependencies.
     */
    public function confirmRemoveEnvVar(string $key): void
    {
        $this->authorize('update', $this->site);
        $this->openConfirmActionModal(
            method: 'removeEnvVar',
            arguments: [$key],
            title: __('Remove :key?', ['key' => $key]),
            message: __('This deletes :key from the cache and auto-pushes the change to the server. The variable will be gone from the live .env immediately.', ['key' => $key]),
            confirmLabel: __('Remove'),
            destructive: true,
        );
    }

    public function removeEnvVar(string $key, DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        if (! array_key_exists($key, $parsed['variables'])) {
            return;
        }
        unset($parsed['variables'][$key], $parsed['comments'][$key]);
        $this->site->forceFill([
            'env_file_content' => $writer->render($parsed['variables'], $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.var_removed', $this->site, ['key' => $key], null);
        }

        $this->revealed_env_keys = array_values(array_diff($this->revealed_env_keys, [$key]));
        $this->autoPushAfterCacheMutation(__('Variable removed.'));
    }

    /** Select every key in the cache, or clear if all are already selected. */
    public function toggleSelectAllEnvVars(DotEnvFileParser $parser): void
    {
        $this->authorize('update', $this->site);
        $all = array_keys($parser->parse((string) ($this->site->env_file_content ?? ''))['variables']);
        $allSelected = $all !== [] && array_diff($all, $this->selected_env_keys) === [];
        $this->selected_env_keys = $allSelected ? [] : array_values($all);
    }

    public function clearEnvSelection(): void
    {
        $this->selected_env_keys = [];
    }

    public function confirmRemoveSelectedEnvVars(): void
    {
        $this->authorize('update', $this->site);
        $keys = $this->normalizedSelectedEnvKeys();
        if ($keys === []) {
            $this->toastError(__('Select at least one variable to remove.'));

            return;
        }

        $preview = implode(', ', array_slice($keys, 0, 8)).(count($keys) > 8 ? ' …' : '');
        $this->openConfirmActionModal(
            'removeSelectedEnvVars',
            [],
            trans_choice('{1} Remove 1 variable?|[2,*] Remove :count variables?', count($keys), ['count' => count($keys)]),
            __('This deletes them from the cache and pushes the change to the server in a single push: :list', ['list' => $preview]),
            trans_choice('{1} Remove 1|[2,*] Remove :count', count($keys), ['count' => count($keys)]),
            true,
        );
    }

    /**
     * Bulk-remove the ticked variables in ONE cache write and ONE server push,
     * so N removals don't fan out to N SSH pushes.
     */
    public function removeSelectedEnvVars(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $keys = $this->normalizedSelectedEnvKeys();
        if ($keys === []) {
            return;
        }

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $removed = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $parsed['variables'])) {
                unset($parsed['variables'][$key], $parsed['comments'][$key]);
                $removed[] = $key;
            }
        }

        $this->selected_env_keys = [];
        if ($removed === []) {
            return;
        }

        $this->site->forceFill([
            'env_file_content' => $writer->render($parsed['variables'], $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.vars_removed', $this->site, ['keys' => $removed, 'count' => count($removed)], null);
        }

        $this->revealed_env_keys = array_values(array_diff($this->revealed_env_keys, $removed));
        $this->autoPushAfterCacheMutation(
            trans_choice('{1} :count variable removed.|[2,*] :count variables removed.', count($removed), ['count' => count($removed)])
        );
    }

    /** @return list<string> */
    protected function normalizedSelectedEnvKeys(): array
    {
        return array_values(array_filter(
            array_unique($this->selected_env_keys),
            static fn ($k): bool => is_string($k) && $k !== ''
        ));
    }

    /**
     * Dispatches the push job after a successful cache mutation. On hosts
     * without a server-side .env (Docker/K8s/Serverless), the push is a
     * no-op — those runtimes inject env at deploy time. PushSiteEnvJob's
     * ShouldBeUnique guard means rapid-fire mutations coalesce into a
     * single push with the latest cache state.
     *
     * Errors will surface in the banner; the cache write is always
     * preserved. There's no manual Push button anymore — Sync from server
     * re-reads if needed, and any subsequent mutation re-fires this method.
     */
    protected function autoPushAfterCacheMutation(string $savedMessage): void
    {
        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastSuccess($savedMessage.' '.__('Saved.'));

            return;
        }

        // Coalesce bursts of edits into a single SSH push (debounce +
        // ride-the-pending-push). See SiteEnvPushScheduler.
        $scheduled = app(SiteEnvPushScheduler::class)
            ->schedule($this->site, (string) (auth()->id() ?? ''));

        $this->watchConsoleAction(
            $scheduled['run'],
            $savedMessage.' '.__('Pushed to server.'),
            __('Push to server did not finish.'),
        );
        $this->toastSuccess($scheduled['coalesced']
            ? $savedMessage.' '.__('Queued with the pending push to the server.')
            : $savedMessage.' '.__('Pushing to server — the console banner will confirm when it finishes.'));
    }

    public function toggleRevealEnvVar(string $key): void
    {
        $this->authorize('update', $this->site);
        if (in_array($key, $this->revealed_env_keys, true)) {
            $this->revealed_env_keys = array_values(array_diff($this->revealed_env_keys, [$key]));

            return;
        }
        $this->revealed_env_keys[] = $key;
    }

    /**
     * Re-scan the deployed code for required env vars. SSH-bound, so it runs
     * as a backgrounded job whose progress streams to the console banner —
     * same shape as Sync from server. Each deploy also refreshes this list
     * automatically; this is the manual escape hatch.
     */
    public function rescanEnvRequirements(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime has no on-disk code to scan.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('env_scan');

        ScanSiteEnvRequirementsJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction($run, __('Environment requirements re-scanned.'), __('Environment scan did not finish.'));
        $this->toastConsoleActionQueued();
    }

    /**
     * Open the "Add missing variables" modal, seeding each input with the
     * .env.example sample value (if any) so the operator can confirm or edit
     * rather than type from scratch.
     */
    public function openMissingEnvModal(): void
    {
        $this->authorize('update', $this->site);

        $present = $this->presentNonEmptyEnvKeys();
        $inherited = $this->site->workspace?->variables->pluck('env_key')->map(fn ($k) => (string) $k)->all() ?? [];

        $seed = [];
        foreach ($this->site->missingRequiredEnvKeys($present, $inherited) as $entry) {
            $seed[$entry['key']] = (string) ($entry['example'] ?? '');
        }
        $this->missing_env_values = $seed;

        $this->dispatch('open-modal', 'add-missing-env-modal');
    }

    /**
     * Bulk-add the still-missing required keys the operator filled in via the
     * modal. Blank inputs are skipped (they can be added later); the rest merge
     * into the env cache and auto-push, reusing the same write path as
     * addEnvVar / bulkImport.
     */
    public function addMissingEnvVars(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);

        $additions = [];
        foreach ($this->missing_env_values as $key => $value) {
            $key = trim((string) $key);
            $value = (string) $value;
            if ($key === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || trim($value) === '') {
                continue;
            }
            $additions[$key] = $value;
        }

        if ($additions === []) {
            $this->toastError(__('Enter a value for at least one variable.'));

            return;
        }

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $map = array_merge($parsed['variables'], $additions);

        $this->site->forceFill([
            'env_file_content' => $writer->render($map, $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.bulk_imported', $this->site, null, [
                'imported_count' => count($additions),
                'imported_keys' => array_keys($additions),
            ]);
        }

        $this->missing_env_values = [];
        $this->dispatch('close-modal', 'add-missing-env-modal');
        $this->autoPushAfterCacheMutation(__(':count variable(s) added.', ['count' => count($additions)]));
    }

    /**
     * Keys already set with a non-empty value in the env cache — used to work
     * out which required keys are still missing.
     *
     * @return list<string>
     */
    private function presentNonEmptyEnvKeys(): array
    {
        $parsed = app(DotEnvFileParser::class)->parse((string) ($this->site->env_file_content ?? ''));

        $keys = [];
        foreach ($parsed['variables'] as $key => $value) {
            if (trim((string) $value) !== '') {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /** Disable the required-env gate for this site (no-deploy variant). */
    public function ignoreMissingEnv(): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['skip_env_gate'] = true;
        unset($meta['deploy_blocked_env']);
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__('Ignoring missing required variables for this site — deploys won\'t be blocked by them.'));
    }

    /** Re-enable the required-env gate. */
    public function enableEnvGate(): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        unset($meta['skip_env_gate']);
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__('Required-env check re-enabled. Deploys stop if required variables are missing.'));
    }

    /** Whether the required-env gate is currently disabled. */
    public function envGateSkipped(): bool
    {
        return ($this->site->meta['skip_env_gate'] ?? false) === true;
    }

    public function confirmIgnoreMissingEnv(): void
    {
        $this->authorize('update', $this->site);
        $this->openConfirmActionModal(
            method: 'ignoreMissingEnv',
            title: __('Ignore missing variables?'),
            message: __('Stop blocking and warning on the missing required variables for this site. Deploys will proceed even if they are unset — it\'s on you if the app errors. You can re-enable this later.'),
            confirmLabel: __('Ignore them'),
        );
    }

    public function confirmIgnoreEnvKey(string $key): void
    {
        $this->authorize('update', $this->site);
        $key = trim($key);
        if ($key === '') {
            return;
        }
        $this->openConfirmActionModal(
            method: 'ignoreEnvKey',
            arguments: [$key],
            title: __('Ignore :key?', ['key' => $key]),
            message: __('Mark :key as intentionally unset for this site. It won\'t count as a missing required variable or block deploys. You can un-ignore it later.', ['key' => $key]),
            confirmLabel: __('Ignore'),
        );
    }

    /** Per-variable ignore: mark one required key as intentionally unset. */
    public function ignoreEnvKey(string $key): void
    {
        $this->authorize('update', $this->site);
        $key = trim($key);
        if ($key === '') {
            return;
        }
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $ignored = array_values(array_unique([...((array) ($meta['ignored_env_keys'] ?? [])), $key]));
        $meta['ignored_env_keys'] = $ignored;
        if (isset($meta['deploy_blocked_env']['keys']) && is_array($meta['deploy_blocked_env']['keys'])) {
            $meta['deploy_blocked_env']['keys'] = array_values(array_filter(
                $meta['deploy_blocked_env']['keys'],
                static fn ($e): bool => is_array($e) && (string) ($e['key'] ?? '') !== $key,
            ));
            if ($meta['deploy_blocked_env']['keys'] === []) {
                unset($meta['deploy_blocked_env']);
            }
        }
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__(':key will be ignored — deploys won\'t require it.', ['key' => $key]));
    }

    /** Undo a per-variable ignore. */
    public function unignoreEnvKey(string $key): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['ignored_env_keys'] = array_values(array_filter(
            (array) ($meta['ignored_env_keys'] ?? []),
            static fn ($k): bool => (string) $k !== trim($key),
        ));
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__(':key is no longer ignored.', ['key' => $key]));
    }

    /**
     * @return list<string>
     */
    public function ignoredEnvKeys(): array
    {
        return array_values(array_map('strval', (array) ($this->site->meta['ignored_env_keys'] ?? [])));
    }
}
