<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteEnvPushScheduler;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteEnvCrud
{


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
}
