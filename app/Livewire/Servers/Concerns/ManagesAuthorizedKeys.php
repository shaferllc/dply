<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\PreviewDriftJob;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\UserSshKey;
use App\Modules\Notifications\Services\ServerSshKeyNotificationDispatcher;
use App\Services\Servers\ServerAuthorizedKeysAuditLogger;
use App\Services\Servers\SshKeyLabelTemplate;
use App\Services\Servers\SshPublicKeyFingerprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesAuthorizedKeys
{


    /**
     * Submit-time gate for {@see saveAdvancedSettings}. The "Disable authorized_keys sync"
     * toggle is the workspace's break-glass: flipping it on stops every future sync (manual
     * and automated) until turned back off. Worth a confirm beat. We only intercept on the
     * false→true transition; turning it off, or any save where the toggle isn't changing,
     * commits inline.
     */
    public function requestSaveAdvancedSettings(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'advanced_label_template' => ['nullable', 'string', 'max:500'],
        ]);

        $persistedDisable = (bool) data_get($this->server->fresh()->meta ?? [], config('server_ssh_keys.meta_disable_sync_key'));
        $disablingNow = $this->advanced_disable_sync && ! $persistedDisable;

        if ($disablingNow) {
            $this->openConfirmActionModal(
                'saveAdvancedSettings',
                [],
                __('Disable authorized_keys sync?'),
                __('Dply will stop writing :file on this server until you turn this off again. Manual "Sync now" clicks and any automated reconciliation are blocked. Useful as a break-glass when something on the server is misbehaving — turn it back off as soon as you can.', ['file' => 'authorized_keys']),
                __('Disable sync'),
                true,
            );

            return;
        }

        // Method injection only fires when Livewire invokes this as a front-end
        // action (the confirm-modal "Disable sync" path); on this direct call we
        // must resolve the audit logger ourselves.
        $this->saveAdvancedSettings(app(ServerAuthorizedKeysAuditLogger::class));
    }

    public function saveAdvancedSettings(ServerAuthorizedKeysAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'advanced_label_template' => ['nullable', 'string', 'max:500'],
        ]);

        $meta = $this->server->fresh()->meta ?? [];
        $meta[config('server_ssh_keys.meta_disable_sync_key')] = $this->advanced_disable_sync;
        $meta[config('server_ssh_keys.meta_health_check_key')] = $this->advanced_health_check;
        if (trim($this->advanced_label_template) === '') {
            unset($meta[config('server_ssh_keys.meta_label_template_key')]);
        } else {
            $meta[config('server_ssh_keys.meta_label_template_key')] = $this->advanced_label_template;
        }

        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();
        $this->hydrateAdvancedFromServer();

        $audit->record(
            $this->server,
            ServerSshKeyAuditEvent::EVENT_SETTINGS_UPDATED,
            [
                'disable_sync' => (bool) $this->advanced_disable_sync,
                'health_check' => (bool) $this->advanced_health_check,
                'label_template' => trim($this->advanced_label_template) !== '' ? $this->advanced_label_template : null,
            ],
            Auth::user(),
        );

        $this->toastSuccess(__('SSH key settings saved.'));
    }

    /**
     * Dispatch a queued drift preview. Mirrors {@see syncAuthorizedKeys()} — generates a fresh
     * run id, writes pending state to server.meta, and returns immediately so the workspace
     * banner can show the live "running" state while the queued job streams output back via
     * the application cache. The polling loop ({@see pollSyncStatus}) hydrates `$diff_result`
     * once the run completes.
     */
    public function previewDiff(): void
    {
        $this->authorize('view', $this->server);

        if ($this->rejectIfSyncBusy()) {
            return;
        }

        $statusKey = config('server_ssh_keys.meta_drift_status_key');
        $current = (string) data_get($this->server->fresh()->meta ?? [], $statusKey);
        if (in_array($current, ['queued', 'running'], true)) {
            $this->toastError(__('A drift preview is already in flight on this server. Wait for it to finish before starting another.'));

            return;
        }

        $runId = (string) Str::ulid();
        $meta = $this->server->fresh()->meta ?? [];
        $meta[config('server_ssh_keys.meta_drift_run_id_key')] = $runId;
        $meta[$statusKey] = 'queued';
        $meta[config('server_ssh_keys.meta_drift_started_at_key')] = now()->toIso8601String();
        $meta[config('server_ssh_keys.meta_drift_finished_at_key')] = null;
        $meta[config('server_ssh_keys.meta_drift_error_key')] = null;
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        // Clear any prior local state so the banner doesn't briefly render a stale completed
        // transcript before the queued state takes over.
        $this->diff_output = [];
        $this->diff_result = null;
        $this->setSshWorkspaceTab('preview');

        PreviewDriftJob::dispatch($this->server->id, $runId);
    }

    public function addAuthorizedKey(ServerAuthorizedKeysAuditLogger $audit, ServerSshKeyNotificationDispatcher $notifications): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_auth_name' => 'required|string|max:120',
            'new_auth_key' => 'required|string|max:8000',
            'new_target_linux_user' => ['required', 'string', 'max:64', Rule::in($this->system_users)],
            'new_review_after' => ['nullable', 'date'],
            'profile_key_id' => [
                'nullable',
                'string',
                Rule::exists('user_ssh_keys', 'id')->where('user_id', Auth::id()),
            ],
        ]);

        $selected = trim($this->new_target_linux_user);
        $storedTarget = $selected === (string) $this->server->ssh_user ? '' : $selected;

        $tpl = SshKeyLabelTemplate::resolveTemplate($this->server);

        if ($this->profile_key_id) {
            $userKey = UserSshKey::query()
                ->where('user_id', Auth::id())
                ->whereKey($this->profile_key_id)
                ->firstOrFail();

            $finalName = SshKeyLabelTemplate::apply($tpl, $userKey->name, $selected, $this->server);

            $row = ServerAuthorizedKey::query()->updateOrCreate(
                [
                    'server_id' => $this->server->id,
                    'managed_key_type' => UserSshKey::class,
                    'managed_key_id' => $userKey->id,
                    'target_linux_user' => $storedTarget,
                ],
                [
                    'name' => $finalName,
                    'public_key' => trim($userKey->public_key),
                    'review_after' => $this->new_review_after,
                ]
            );
        } else {
            if (! UserSshKey::publicKeyLooksValid($this->new_auth_key)) {
                $this->addError('new_auth_key', __('That does not look like a valid SSH public key.'));

                return;
            }

            $finalName = SshKeyLabelTemplate::apply($tpl, $this->new_auth_name, $selected, $this->server);

            $row = ServerAuthorizedKey::query()->create([
                'server_id' => $this->server->id,
                'managed_key_type' => null,
                'managed_key_id' => null,
                'target_linux_user' => $storedTarget,
                'name' => $finalName,
                'public_key' => trim($this->new_auth_key),
                'review_after' => $this->new_review_after,
            ]);
        }

        $fp = SshPublicKeyFingerprint::forLine((string) $row->public_key);
        $audit->record(
            $this->server->fresh(),
            ServerSshKeyAuditEvent::EVENT_KEY_CREATED,
            [
                'authorized_key_id' => $row->id,
                'name' => $row->name,
                'fingerprints' => $fp,
            ],
            Auth::user(),
            Request::ip()
        );

        $this->new_auth_name = '';
        $this->new_auth_key = '';
        $this->new_review_after = null;
        $this->new_target_linux_user = (string) ($this->server->ssh_user ?: 'root');
        $this->profile_key_id = null;
        $this->loadReviewDateInputs();
        $this->dispatch('close-modal', 'add-ssh-key-modal');

        $this->emitPanelEvent(
            __('Key added — sync to apply'),
            [
                sprintf('> Added "%s" to the panel for user %s.', $row->name, $selected !== '' ? $selected : (string) $this->server->ssh_user),
                sprintf('  fingerprint SHA256:%s', $fp['sha256'] ?? '—'),
                '> Click "Sync now" to write this to the server\'s authorized_keys.',
            ],
        );

        $notifications->notify($this->server->fresh(), 'created', [$row->name], Auth::user(), [
            'authorized_key_id' => $row->id,
            'target_linux_user' => $selected !== '' ? $selected : (string) $this->server->ssh_user,
        ]);

        $this->toastSuccess(__('Key saved. Click “Sync authorized_keys” to apply on the server.'));
    }

    public function updateKeyReviewFromInput(string $id, ServerAuthorizedKeysAuditLogger $audit): void
    {
        $date = $this->reviewDates[$id] ?? '';
        $this->updateKeyReviewAfter($id, $date !== '' ? $date : null, $audit);
    }

    public function updateKeyReviewAfter(string $id, ?string $date, ServerAuthorizedKeysAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $key = ServerAuthorizedKey::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        $key->update([
            'review_after' => $date !== null && $date !== '' ? $date : null,
        ]);

        $audit->record(
            $this->server->fresh(),
            ServerSshKeyAuditEvent::EVENT_KEY_UPDATED,
            ['authorized_key_id' => $key->id, 'review_after' => $key->review_after?->toDateString()],
            Auth::user(),
            Request::ip()
        );

        $this->loadReviewDateInputs();
        $this->toastSuccess(__('Review date updated.'));
    }

    public function deleteAuthorizedKey(string $id, ServerAuthorizedKeysAuditLogger $audit, ServerSshKeyNotificationDispatcher $notifications): void
    {
        $this->authorize('update', $this->server);
        $key = ServerAuthorizedKey::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        // Refuse to delete the last key targeting the login user — that's the key Dply uses to
        // SSH into this server, and dropping it would lock Dply out on the next sync. Operators
        // who really want a no-key state can clear it via uninstall/server removal flows; this
        // workspace stays opinionated.
        if ($this->isLastLoginUserKey($key)) {
            $this->toastError(__('Cannot delete the last key for the login user :user — Dply uses it to reach this server. Add another :user key first, then remove this one.', [
                'user' => (string) ($this->server->ssh_user ?: 'login'),
            ]));

            return;
        }

        $fp = SshPublicKeyFingerprint::forLine((string) $key->public_key);
        $audit->record(
            $this->server->fresh(),
            ServerSshKeyAuditEvent::EVENT_KEY_DELETED,
            [
                'authorized_key_id' => $key->id,
                'name' => $key->name,
                'fingerprints' => $fp,
            ],
            Auth::user(),
            Request::ip()
        );

        $removedName = (string) $key->name;
        $key->delete();
        $this->server->unsetRelation('authorizedKeys');
        $this->loadReviewDateInputs();

        $notifications->notify($this->server->fresh(), 'removed', [$removedName], Auth::user());

        $this->toastSuccess(__('Key removed. Sync again to update the server.'));
    }

    /**
     * True when removing the given key would leave the server's SSH login user with zero keys
     * targeting them — the lockout case Dply itself can never recover from automatically. A row
     * with empty `target_linux_user` is treated as login-user-targeted (that's the convention the
     * synchronizer uses when grouping rows for sync).
     */
    public function isLastLoginUserKey(ServerAuthorizedKey $key): bool
    {
        $loginUser = (string) $this->server->ssh_user;
        if ($loginUser === '') {
            return false;
        }

        $rowTarget = (string) ($key->target_linux_user ?? '');
        $targetsLogin = $rowTarget === '' || $rowTarget === $loginUser;
        if (! $targetsLogin) {
            return false;
        }

        $this->server->loadMissing('authorizedKeys');
        $loginKeyCount = $this->server->authorizedKeys
            ->filter(static function ($k) use ($loginUser): bool {
                $t = (string) ($k->target_linux_user ?? '');

                return $t === '' || $t === $loginUser;
            })
            ->count();

        return $loginKeyCount <= 1;
    }
}
