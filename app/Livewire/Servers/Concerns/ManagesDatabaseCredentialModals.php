<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseCredentialShare;
use App\Services\Servers\ServerDatabaseAuditLogger;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDatabaseCredentialModals
{
    public ?string $credentials_modal_db_id = null;

    public ?string $connection_url_modal_db_id = null;

    public ?string $share_target_db_id = null;

    public int $share_expires_hours;

    public int $share_max_views;

    public ?string $share_link_modal_url = null;

    public ?string $share_link_modal_db_name = null;

    public function openCredentialsModal(string $databaseId): void
    {
        $this->authorize('update', $this->server);
        $exists = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($databaseId)
            ->exists();
        $this->credentials_modal_db_id = $exists ? $databaseId : null;
    }

    public function closeCredentialsModal(): void
    {
        $this->credentials_modal_db_id = null;
    }

    public function openConnectionUrlModal(string $databaseId): void
    {
        $this->authorize('update', $this->server);
        $exists = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($databaseId)
            ->exists();
        $this->connection_url_modal_db_id = $exists ? $databaseId : null;
    }

    public function closeConnectionUrlModal(): void
    {
        $this->connection_url_modal_db_id = null;
    }

    public function dismissGeneratedDatabaseCredentials(): void
    {
        $this->generated_database_credentials = null;
    }

    public function hideGeneratedDatabasePassword(): void
    {
        if ($this->generated_database_credentials === null) {
            return;
        }

        unset($this->generated_database_credentials['password']);
        $this->generated_database_credentials['password_hidden'] = true;
    }

    public function closeShareLinkModal(): void
    {
        $this->share_link_modal_url = null;
        $this->share_link_modal_db_name = null;
    }

    public function createCredentialShare(ServerDatabaseAuditLogger $auditLogger): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org && ! $org->allowsDatabaseCredentialShares()) {
            $this->addError('share_target_db_id', __('This organization has disabled public credential share links.'));

            return;
        }

        $this->validate([
            'share_target_db_id' => 'required|ulid|exists:server_databases,id',
            'share_expires_hours' => 'required|integer|min:1|max:720',
            'share_max_views' => 'required|integer|min:1|max:50',
        ]);

        $db = ServerDatabase::query()->where('server_id', $this->server->id)->whereKey($this->share_target_db_id)->firstOrFail();
        $token = Str::random(48);
        ServerDatabaseCredentialShare::query()->create([
            'server_database_id' => $db->id,
            'user_id' => auth()->id(),
            'token' => $token,
            'expires_at' => now()->addHours($this->share_expires_hours),
            'views_remaining' => $this->share_max_views,
            'max_views' => $this->share_max_views,
        ]);

        $url = route('database-credential-shares.show', ['token' => $token]);
        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_CREDENTIAL_SHARE_CREATED, [
            'server_database_id' => $db->id,
        ], auth()->user());

        $this->dispatchDatabaseNotification('credential_shared', [
            __('Database: :name', ['name' => $db->name]),
            __('A one-time credential link was generated.'),
        ], ['database_id' => $db->id, 'database_name' => $db->name]);

        $this->share_link_modal_url = $url;
        $this->share_link_modal_db_name = $db->name;
        $this->toastSuccess(__('Share link created.'));
    }
}
