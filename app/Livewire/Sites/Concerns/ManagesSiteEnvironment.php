<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\PushSiteEnvJob;
use App\Jobs\RunSiteFixerJob;
use App\Jobs\ScanSiteEnvRequirementsJob;
use App\Jobs\SyncEnvFromServerJob;
use App\Jobs\TestSiteHealthJob;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Livewire\Sites\Show;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteEnvPushScheduler;
use App\Support\Sites\SiteFixers;
use Livewire\Component;

/**
 * The site .env editor: viewing/editing the encrypted env cache, syncing it
 * from / pushing it to the server's live .env, the detected-requirement
 * "missing variables" prompt, and the file-path relocation controls.
 *
 * Lifted out of {@see Show} so the same editor can be
 * embedded as the Deploy hub's Environment tab. The host component must also
 * use {@see ConfirmsActionWithModal}, {@see DispatchesToastNotifications} and
 * {@see WatchesConsoleActionOutcomes}, and expose
 * `$this->site` and `$this->server`.
 *
 * @phpstan-require-extends Component
 *
 * @property Server $server
 * @property Site $site
 */
trait ManagesSiteEnvironment
{
    public string $new_env_key = '';

    public string $new_env_value = '';

    public string $new_env_comment = '';

    public string $bulk_env_input = '';

    /**
     * Keys ticked for bulk removal. Bound to the per-row checkboxes; cleared
     * after a bulk remove. Bulk delete writes the cache once and pushes once.
     *
     * @var list<string>
     */
    public array $selected_env_keys = [];

    public ?string $env_import_key = null;

    public ?string $editing_env_key = null;

    public string $editing_env_value = '';

    public string $editing_env_comment = '';

    /** Key currently open in the single-variable "Fix" modal ('' = closed). */
    public ?string $fixing_env_key = null;

    public string $fixing_env_value = '';

    /** @var list<string> */
    public array $revealed_env_keys = [];

    public string $env_file_path_override = '';

    /** Live filter for the variables list (matches key names, case-insensitive). */
    public string $env_search = '';

    /** Selected prefix group to filter the variables list ('' = all). */
    public string $env_group = '';

    /** 1-based page for the (in-memory) variables list. */
    public int $env_page = 1;

    public function updatedEnvSearch(): void
    {
        $this->env_page = 1;
    }

    public function updatedEnvGroup(): void
    {
        $this->env_page = 1;
    }

    /** Editable buffer for the "Edit all" modal (the full .env as text). */
    public string $edit_all_env = '';

    /** @var array<string, string> */
    public array $missing_env_values = [];

    /**
     * Manual push of the cache to the server's .env (console banner).
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
     * Lazy first-visit sync (wire:init): fires the env-sync job only when the
     * cache has never been touched. Read uses 'view' priv.
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
     * Manual "re-read the live .env from the server and replace the cache".
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
     * One-click "move .env outside docroot" → /etc/dply/<slug>.env + push.
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
     * Save a custom absolute .env path on the Site row (empty = default).
     */
    public function saveEnvFilePath(): void
    {
        $this->authorize('update', $this->site);
        if ($this->blockedForDerivedWorker()) {
            return;
        }
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
     * auto-pushes to the server's .env file.
     */
    /**
     * A derived worker has no environment of its own — it inherits the parent
     * app's, overriding only a handful of role-specific keys. Block edits here
     * and point the operator at the parent. Returns true when blocked so the
     * caller can early-return.
     */
    protected function blockedForDerivedWorker(): bool
    {
        if ($this->site->isDerivedWorker()) {
            $this->toastError(__('This is a worker — its environment is inherited from its parent app. Manage it on the parent app.'));

            return true;
        }

        return false;
    }

    public function addEnvVar(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        if ($this->blockedForDerivedWorker()) {
            return;
        }
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
     * Bulk paste — additive merge of a multi-line .env block.
     */
    public function bulkImportEnvVars(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        if ($this->blockedForDerivedWorker()) {
            return;
        }
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
     * Seed this site's .env from another site. $verbatim distinguishes the two
     * intents:
     *  - verbatim (a worker / replica of THE SAME app — shares APP_KEY, DB, Redis):
     *    copy as-is so the boxes stay in lockstep. Auto-defaulted for pool workers.
     *  - sanitized (a DIFFERENT app used as a template): blank secret / host-bound
     *    values for the operator to fill and regenerate APP_KEY (see EnvImportSources).
     * Keys this site already has set always win, so an import never clobbers work.
     */
    public function importEnvFromSite(string $sourceSiteId, bool $verbatim, DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        if ($this->blockedForDerivedWorker()) {
            return;
        }

        $source = $this->resolveImportSource($sourceSiteId);
        if (! $source instanceof Site) {
            return;
        }

        $incoming = $parser->parse((string) $source->env_file_content);
        $vars = $verbatim
            ? $incoming['variables']
            : \App\Support\Sites\EnvImportSources::sanitize($incoming['variables']);

        $existing = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $merged = array_merge($vars, $existing['variables']);
        $comments = array_merge($incoming['comments'], $existing['comments']);

        $this->site->forceFill([
            'env_file_content' => $writer->render($merged, $comments),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.imported_from_site', $this->site, null, [
                'source_site_id' => $source->id,
                'verbatim' => $verbatim,
                'imported_keys' => array_keys($vars),
            ]);
        }

        $this->dispatch('close-modal', name: 'env-import-modal');
        $message = $verbatim
            ? __('Imported .env from :name verbatim (same APP_KEY + backend — they stay in lockstep).', ['name' => $source->name])
            : __('Imported .env from :name — :n secret(s) blanked to fill in, APP_KEY regenerated.', ['name' => $source->name, 'n' => count(array_filter($vars, fn ($v) => $v === ''))]);
        $this->autoPushAfterCacheMutation($message);
    }

    /**
     * Import a SINGLE variable's value from another site/server (e.g. pull
     * REVERB_APP_KEY from a worker so the app and worker match). Copies the value
     * verbatim — a single-key pull is always an explicit, intentional copy.
     */
    public function importEnvKeyFromSite(string $key, string $sourceSiteId, DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);

        $source = $this->resolveImportSource($sourceSiteId);
        if (! $source instanceof Site) {
            return;
        }

        $value = (string) ($parser->parse((string) $source->env_file_content)['variables'][$key] ?? '');
        if (trim($value) === '') {
            $this->toastError(__(':name has no :key value to import.', ['name' => $source->name, 'key' => $key]));

            return;
        }

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $parsed['variables'][$key] = $value;

        $this->site->forceFill([
            'env_file_content' => $writer->render($parsed['variables'], $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $this->env_import_key = null;
        $this->autoPushAfterCacheMutation(__(':key imported from :name.', ['key' => $key, 'name' => $source->name]));
    }

    /**
     * Sites (in the operator's org, other than this one) that have a NON-EMPTY
     * value for $key — the candidate sources for a per-variable import. Grouped
     * like the whole-env picker (workers / same-repo / org).
     *
     * @return array<int, array<string, mixed>>
     */
    public function envKeySources(string $key): array
    {
        if (trim($key) === '') {
            return [];
        }

        $parser = app(DotEnvFileParser::class);
        $groups = \App\Support\Sites\EnvImportSources::candidatesFor($this->site);
        $all = collect($groups['workers'])->merge($groups['same_repo'])->merge($groups['org'])
            ->unique('id')->values();

        return $all->map(function (array $c) use ($key, $parser): ?array {
            $src = Site::query()->whereKey($c['id'])->value('env_file_content');
            $val = trim((string) ($parser->parse((string) $src)['variables'][$key] ?? ''));
            if ($val === '') {
                return null;
            }

            return $c + ['masked' => \App\Support\Sites\EnvImportSources::isSecretKey($key) ? str_repeat('•', 6) : \Illuminate\Support\Str::limit($val, 40)];
        })->filter()->values()->all();
    }

    /**
     * Look up an import source within the operator's org with a usable .env.
     */
    private function resolveImportSource(string $sourceSiteId): ?Site
    {
        $source = Site::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($sourceSiteId)
            ->first();

        if (! $source instanceof Site || trim((string) $source->env_file_content) === '') {
            $this->toastError(__('That site has no .env to import.'));

            return null;
        }

        return $source;
    }

    /**
     * Candidate sites this site can seed its .env from, grouped (pool workers /
     * same-repo / org). Drives the import picker.
     *
     * @return array{workers: array<int, array<string, mixed>>, same_repo: array<int, array<string, mixed>>, org: array<int, array<string, mixed>>}
     */
    public function envImportCandidates(): array
    {
        return \App\Support\Sites\EnvImportSources::candidatesFor($this->site);
    }

    /**
     * True when this site has no .env yet AND has never deployed — the moment to
     * prompt "set up your .env" (with import options) before the first deploy.
     */
    public function needsFirstEnv(): bool
    {
        return trim((string) ($this->site->env_file_content ?? '')) === ''
            && $this->site->env_cache_origin === null
            && $this->site->latestDeployment() === null;
    }

    /**
     * Open the "Edit all" modal, pre-filled with the full current .env as
     * editable text.
     */
    public function openEditAllEnv(): void
    {
        $this->authorize('update', $this->site);
        $this->edit_all_env = (string) ($this->site->env_file_content ?? '');
        $this->dispatch('open-modal', 'edit-all-env-modal');
    }

    /**
     * Replace the entire env cache with the edited text. Unlike bulk import
     * (which merges), this is a full rewrite — keys removed from the textarea
     * are dropped. Parse errors are surfaced and nothing is written.
     */
    public function saveAllEnv(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['edit_all_env' => 'nullable|string|max:200000']);

        $parsed = $parser->parse((string) $this->edit_all_env);
        if ($parsed['errors'] !== []) {
            foreach ($parsed['errors'] as $err) {
                $this->addError('edit_all_env', $err);
            }

            return;
        }

        $this->site->forceFill([
            'env_file_content' => $writer->render($parsed['variables'], $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.bulk_imported', $this->site, null, [
                'imported_count' => count($parsed['variables']),
                'imported_keys' => array_keys($parsed['variables']),
            ]);
        }

        $this->dispatch('close-modal', 'edit-all-env-modal');
        $this->autoPushAfterCacheMutation(__('Environment replaced — :count variable(s).', ['count' => count($parsed['variables'])]));
    }

    /**
     * Open the inline editor for a single key.
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

    /**
     * Open the inline editor for a connection variable currently provided by a
     * resource binding (e.g. DB_HOST from a database binding). The key isn't in
     * the site .env yet, so seed the buffer from the binding's value; saving
     * writes a real .env key, which the deploy layering lets beat the binding —
     * i.e. a manual override the operator owns from here on.
     */
    public function overrideManagedEnvVar(string $key): void
    {
        $this->authorize('update', $this->site);

        $value = '';
        $this->site->loadMissing('bindings');
        foreach ($this->site->bindings as $binding) {
            $env = $binding->connectionEnv();
            if (array_key_exists($key, $env)) {
                $value = (string) $env[$key];
                break;
            }
        }

        $this->editing_env_key = $key;
        $this->editing_env_value = $value;
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
     * Open the single-variable "Fix" modal for a key flagged by the config
     * check. Pre-fills the input with the current value (creating the row if
     * it doesn't exist yet) so the operator can correct it in place — works
     * for ANY key, not just the ones with a known suggested fix.
     */
    public function openFixEnvVar(string $key, DotEnvFileParser $parser): void
    {
        $this->authorize('update', $this->site);

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));

        $this->fixing_env_key = $key;
        $this->fixing_env_value = (string) ($parsed['variables'][$key] ?? '');
        $this->resetErrorBag('fixing_env_value');

        $this->dispatch('open-modal', 'fix-env-var-modal');
    }

    public function cancelFixEnvVar(): void
    {
        $this->fixing_env_key = null;
        $this->fixing_env_value = '';
    }

    /**
     * Drop the suggested fix into the input (the modal's "Use suggested"
     * button). For APP_KEY this mints a fresh key; for the boolean/enum keys
     * it's the safe-in-production value.
     */
    public function applySuggestedEnvFix(): void
    {
        $key = strtoupper(trim((string) $this->fixing_env_key));
        $this->fixing_env_value = match ($key) {
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
            'SESSION_SECURE_COOKIE' => 'true',
            'APP_KEY' => $this->freshAppKey(),
            'APP_URL' => str_starts_with(strtolower($this->fixing_env_value), 'http://')
                ? 'https://'.substr($this->fixing_env_value, 7)
                : $this->fixing_env_value,
            default => $this->fixing_env_value,
        };
    }

    /**
     * Human-readable label for the "Use suggested" button, or null when we
     * have no opinion on the right value (e.g. DB_PASSWORD — only the operator
     * knows it). Deterministic so it's safe to call on every render.
     */
    public function envFixSuggestionLabel(string $key, string $current): ?string
    {
        return match (strtoupper(trim($key))) {
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
            'SESSION_SECURE_COOKIE' => 'true',
            'APP_KEY' => __('Generate a fresh key'),
            'APP_URL' => str_starts_with(strtolower($current), 'http://')
                ? 'https://'.substr($current, 7)
                : null,
            default => null,
        };
    }

    /**
     * Write the single fixed key back into the cache and auto-push. Mirrors
     * {@see saveEditedEnvVar()} but scoped to the modal's one key.
     */
    public function saveFixedEnvVar(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);

        $key = trim((string) $this->fixing_env_key);
        if ($key === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            return;
        }

        $this->validate([
            'fixing_env_value' => 'nullable|string|max:20000',
        ]);

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $map = $parsed['variables'];
        $map[$key] = (string) $this->fixing_env_value;

        $this->site->forceFill([
            'env_file_content' => $writer->render($map, $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.var_updated', $this->site, null, [
                'key' => $key,
            ]);
        }

        $this->cancelFixEnvVar();
        $this->dispatch('close-modal', 'fix-env-var-modal');
        $this->autoPushAfterCacheMutation(__(':key updated.', ['key' => $key]));
    }

    /**
     * Trash button → opens the shared confirm-action modal pointing at
     * {@see removeEnvVar()}.
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
        if ($this->blockedForDerivedWorker()) {
            return;
        }
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

    /**
     * Confirm bulk removal of the ticked variables. The actual delete +
     * single push runs in {@see removeSelectedEnvVars} after confirmation.
     */
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
     * Bulk-remove the ticked variables in ONE cache write and ONE server push —
     * the whole point of bulk delete: N removals don't fan out to N SSH pushes.
     */
    public function removeSelectedEnvVars(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        if ($this->blockedForDerivedWorker()) {
            return;
        }
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
     * Dispatch the push job after a successful cache mutation. No-op on hosts
     * without a server-side .env (Docker/K8s/Serverless inject at deploy time).
     *
     * Routes through {@see SiteEnvPushScheduler}, which coalesces a burst of
     * mutations into a single SSH push (debounce + ride-the-pending-push) so
     * editing several variables in a row doesn't open one SSH session per edit.
     */
    protected function autoPushAfterCacheMutation(string $savedMessage): void
    {
        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastSuccess($savedMessage.' '.__('Saved.'));

            return;
        }

        $scheduled = app(SiteEnvPushScheduler::class)->schedule($this->site, (string) (auth()->id() ?? ''));

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
     * "Test site" — end-to-end check that the app actually loads with the
     * current environment (HTTP request + server-log tail on failure). Per-key
     * checks can pass while the app still 500s, so this exercises the real URL.
     */
    public function testSiteLoads(): void
    {
        $this->authorize('view', $this->site);

        $run = $this->seedQueuedConsoleAction('site_test', __('Testing the site'));

        TestSiteHealthJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('The site loaded successfully.'),
            __('The site did not load — see the error below.'),
        );
        $this->toastConsoleActionQueued();
    }

    /**
     * Run a whitelisted artisan remediation (Run migrations, Clear config
     * cache, …) on the server — surfaced as one-click buttons when "Test site"
     * recognises a known failure (e.g. a missing table → migrate).
     */
    public function runRemediation(string $key): void
    {
        $this->authorize('update', $this->site);

        $spec = SiteFixers::spec($key);
        if ($spec === null) {
            return;
        }

        $run = $this->seedQueuedConsoleAction('site_remediate', (string) $spec['label']);
        RunSiteFixerJob::dispatch((string) $run->id, (string) $this->site->id, $key);

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            $spec['label'].' completed.',
            $spec['label'].' did not finish — see the output.',
        );
        $this->toastConsoleActionQueued();
    }

    /**
     * Re-scan the deployed code for required env vars (backgrounded).
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

    /** Suppress a SiteEnvValidator warning by its env key. */
    public function ignoreEnvWarning(string $key): void
    {
        $this->authorize('update', $this->site);
        $key = trim($key);
        if ($key === '') {
            return;
        }
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $suppressed = array_values(array_unique([...((array) ($meta['suppressed_env_warnings'] ?? [])), $key]));
        $meta['suppressed_env_warnings'] = $suppressed;
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__(':key warning suppressed.', ['key' => $key]));
    }

    /** Re-enable a previously suppressed SiteEnvValidator warning. */
    public function unignoreEnvWarning(string $key): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['suppressed_env_warnings'] = array_values(array_filter(
            (array) ($meta['suppressed_env_warnings'] ?? []),
            static fn ($k): bool => (string) $k !== trim($key),
        ));
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__(':key warning re-enabled.', ['key' => $key]));
    }

    /**
     * @return list<string>
     */
    public function suppressedEnvWarningKeys(): array
    {
        return array_values(array_map('strval', (array) ($this->site->meta['suppressed_env_warnings'] ?? [])));
    }

    /**
     * Open the "Add missing variables" modal, seeding each input with the
     * .env.example sample value.
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
     * Bulk-add the filled-in missing required keys. Blank inputs skipped.
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
     * Pre-seed a `queued` console_actions row for the current site so the
     * progress banner appears the moment a job is dispatched. Mirrors the
     * helper on {@see Show} — kept here too so this trait
     * is self-contained for components (e.g. DeploymentsList) that don't carry
     * Show's inline copy. Auto-dismisses stale rows so one run shows at a time.
     */
    protected function seedQueuedConsoleAction(string $kind, ?string $label = null): ConsoleAction
    {
        ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->whereNull('dismissed_at', 'and', false)
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], 'and', false)
            ->update(['dismissed_at' => now()]);

        $staleSeconds = (int) config('console_actions.stale_after_seconds', 600);
        ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->whereNull('dismissed_at', 'and', false)
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING], 'and', false)
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['dismissed_at' => now()]);

        return ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    /**
     * Fill the APP_KEY input in the "Add missing variables" modal with a fresh
     * Laravel-format key, so the operator can generate one inline instead of
     * running `php artisan key:generate` on the box.
     */
    public function generateMissingAppKey(): void
    {
        $this->authorize('update', $this->site);
        $this->missing_env_values['APP_KEY'] = $this->freshAppKey();
    }

    /**
     * A Laravel application key: base64: + 32 random bytes (AES-256-CBC).
     */
    protected function freshAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * Keys already set with a non-empty value in the env cache.
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
}
