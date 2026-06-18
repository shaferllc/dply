<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ScanSiteEnvRequirementsJob;
use App\Livewire\Sites\Show;
use App\Models\ConsoleAction;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteEnvRequirements
{


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
