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
        $this->autoPushAfterCacheMutation(__('Variable saved.'));
    }

    /**
     * Bulk paste — additive merge of a multi-line .env block.
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
        $this->autoPushAfterCacheMutation(__(':count variable(s) imported.', ['count' => $count]));
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

    /**
     * Dispatch the push job after a successful cache mutation. No-op on hosts
     * without a server-side .env (Docker/K8s/Serverless inject at deploy time).
     */
    protected function autoPushAfterCacheMutation(string $savedMessage): void
    {
        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastSuccess($savedMessage.' '.__('Saved.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('env_push');

        PushSiteEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->watchConsoleAction(
            $run,
            $savedMessage.' '.__('Pushed to server.'),
            __('Push to server did not finish.'),
        );
        $this->toastSuccess($savedMessage.' '.__('Pushing to server — the console banner will confirm when it finishes.'));
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
