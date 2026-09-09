<?php

namespace App\Livewire\Organizations;

use App\Actions\Organizations\DeleteOrganizationAction;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ApiToken;
use App\Models\Concerns\ManagesOrganizationEmailRecipients;
use App\Models\Organization;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Every organization-level setting an admin can change.
 *
 * The old "Automation & API" tab (email defaults, API tokens) folded in here
 * in 2026-08: none of it was
 * automation the org *ran*, it was all settings that happened to be about
 * automated things, and splitting them across two admin pages meant no single
 * place answered "what is configured for this org".
 */
#[Layout('layouts.app')]
class Settings extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use WithFileUploads;

    public Organization $organization;

    public string $name = '';

    public string $slug = '';

    public string $email = '';

    public string $description = '';

    public string $timezone = '';

    public mixed $org_icon_upload = null;

    public string $delete_confirm = '';

    public bool $deploy_email_notifications_enabled = true;

    public bool $email_server_credentials_enabled = false;

    public bool $email_database_credentials_enabled = false;

    /**
     * Recipient mode per email key — see
     * {@see ManagesOrganizationEmailRecipients}.
     *
     * @var array<string, string>
     */
    public array $email_recipient_modes = [];

    /**
     * Hand-picked member ids per email key, used in `custom` mode.
     *
     * @var array<string, list<string>>
     */
    public array $email_recipient_user_ids = [];

    /** Comma- or newline-separated emails for the destinations textarea. */
    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        abort_unless($organization->hasAdminAccess(auth()->user()), 403);

        // The route-bound model is already fresh — hydrate directly off it.
        $this->organization = $organization;
        $this->name = (string) $organization->name;
        $this->slug = (string) $organization->slug;
        $this->email = (string) ($organization->email ?? '');
        $this->description = (string) ($organization->description ?? '');
        $this->timezone = (string) ($organization->timezone ?? '');
        $this->loadOrganizationRelations();
    }

    /**
     * Post-mutation reload: re-fetch from the DB to pick up the change.
     *
     * NB: do not rename loadOrganizationRelations() to hydrateOrganization().
     * Livewire treats `hydrate{Property}` as a lifecycle hook for the public
     * $organization property and invokes it from outside the class — which
     * lands in __call for a protected method and throws BadMethodCallException.
     */
    protected function refreshOrganization(): void
    {
        $this->organization = $this->organization->fresh();
        $this->loadOrganizationRelations();
    }

    protected function loadOrganizationRelations(): void
    {
        $this->organization->load(['apiTokens']);

        $this->deploy_email_notifications_enabled = (bool) $this->organization->deploy_email_notifications_enabled;
        $this->email_server_credentials_enabled = (bool) $this->organization->email_server_credentials_enabled;
        $this->email_database_credentials_enabled = (bool) $this->organization->email_database_credentials_enabled;

        foreach (Organization::emailRecipientKeys() as $key) {
            $this->email_recipient_modes[$key] = $this->organization->emailRecipientMode($key);
            $this->email_recipient_user_ids[$key] = $this->organization->emailRecipientUserIds($key);
        }
    }

    public function saveGeneral(): void
    {
        $this->authorize('update', $this->organization);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique('organizations', 'slug')->ignore($this->organization->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL))],
        ]);

        $before = $this->organization->only(['name', 'slug', 'email', 'description', 'timezone']);

        $this->organization->update([
            'name' => $validated['name'],
            'slug' => Str::lower($validated['slug']),
            'email' => $validated['email'] ?: null,
            'description' => $validated['description'] ?: null,
            'timezone' => $validated['timezone'] ?: null,
        ]);

        $this->slug = (string) $this->organization->slug;

        audit_log(
            $this->organization,
            auth()->user(),
            'organization.updated',
            $this->organization,
            $before,
            $this->organization->only(['name', 'slug', 'email', 'description', 'timezone']),
        );

        $this->toastSuccess(__('Organization settings saved.'));
    }

    /** Fires when an icon file is chosen — validate, store, set it immediately. */
    public function updatedOrgIconUpload(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'org_icon_upload' => [
                'required',
                'file',
                'mimetypes:image/png,image/jpeg,image/webp,image/gif,image/x-icon,image/vnd.microsoft.icon',
                'max:1024', // KB
            ],
        ], attributes: ['org_icon_upload' => __('icon')]);

        $old = $this->organization->icon_path;
        $ext = $this->extensionFor($this->org_icon_upload->getMimeType());
        $path = 'org-logos/'.$this->organization->id.'-'.Str::lower(Str::random(8)).'.'.$ext;

        Storage::disk('site_assets')->put($path, file_get_contents($this->org_icon_upload->getRealPath()));
        $this->organization->forceFill(['icon_path' => $path])->save();

        if (is_string($old) && $old !== '' && $old !== $path) {
            Storage::disk('site_assets')->delete($old);
        }

        $this->reset('org_icon_upload');
        $this->recordIconChange($old, $path);
        $this->toastSuccess(__('Icon updated.'));
    }

    public function removeOrgIcon(): void
    {
        $this->authorize('update', $this->organization);

        $old = $this->organization->icon_path;
        if (is_string($old) && $old !== '') {
            Storage::disk('site_assets')->delete($old);
            $this->organization->forceFill(['icon_path' => null])->save();
            $this->recordIconChange($old, null);
        }

        $this->toastSuccess(__('Icon removed.'));
    }

    /**
     * Persist who receives one of the org's email defaults.
     *
     * Hand-picked ids are intersected with current membership before saving:
     * two of these emails carry secrets, so a non-member id must never be
     * storable, not merely ignored at send time.
     */
    public function saveEmailRecipients(string $key): void
    {
        $this->authorize('update', $this->organization);

        if (! in_array($key, Organization::emailRecipientKeys(), true)) {
            return;
        }

        $mode = $this->email_recipient_modes[$key] ?? null;
        if (! in_array($mode, Organization::emailRecipientModes(), true)) {
            $mode = Organization::EMAIL_RECIPIENT_DEFAULTS[$key];
            $this->email_recipient_modes[$key] = $mode;
        }

        $memberIds = $this->organization->users()->pluck('users.id')->map(fn ($id): string => (string) $id);
        $picked = collect($this->email_recipient_user_ids[$key] ?? [])
            ->map(fn ($id): string => (string) $id)
            ->intersect($memberIds)
            ->unique()
            ->values();

        $this->email_recipient_user_ids[$key] = $picked->all();

        $prefs = $this->organization->email_recipient_prefs ?? [];
        $prefs[$key] = [
            'mode' => $mode,
            'user_ids' => $mode === Organization::RECIPIENTS_CUSTOM ? $picked->all() : [],
        ];

        $this->organization->update(['email_recipient_prefs' => $prefs]);
        audit_log($this->organization, auth()->user(), 'organization.email_recipients_updated', null, null, [
            'email' => $key,
            'mode' => $mode,
            'recipient_count' => $picked->count(),
        ]);
        $this->refreshOrganization();
        $this->toastSuccess(__('Email recipients updated.'));
    }

    public function updatedDeployEmailNotificationsEnabled(): void
    {
        $this->authorize('update', $this->organization);

        $this->organization->update([
            'deploy_email_notifications_enabled' => $this->deploy_email_notifications_enabled,
        ]);
        audit_log($this->organization, auth()->user(), 'organization.deploy_email_notifications_updated', null, null, [
            'enabled' => $this->deploy_email_notifications_enabled,
        ]);
        $this->refreshOrganization();
        $this->toastSuccess(__('Deploy email preferences updated.'));
    }

    public function updatedEmailServerCredentialsEnabled(): void
    {
        $this->authorize('update', $this->organization);

        $this->organization->update([
            'email_server_credentials_enabled' => $this->email_server_credentials_enabled,
        ]);
        audit_log($this->organization, auth()->user(), 'organization.email_server_credentials_updated', null, null, [
            'enabled' => $this->email_server_credentials_enabled,
        ]);
        $this->refreshOrganization();
        $this->toastSuccess(__('Server credentials email preference updated.'));
    }

    public function updatedEmailDatabaseCredentialsEnabled(): void
    {
        $this->authorize('update', $this->organization);

        $this->organization->update([
            'email_database_credentials_enabled' => $this->email_database_credentials_enabled,
        ]);
        audit_log($this->organization, auth()->user(), 'organization.email_database_credentials_updated', null, null, [
            'enabled' => $this->email_database_credentials_enabled,
        ]);
        $this->refreshOrganization();
        $this->toastSuccess(__('Database credentials email preference updated.'));
    }

    /**
     * Opens the confirm modal without embedding JSON in wire:click (which breaks HTML attributes).
     */
    public function promptRevokeApiToken(string $apiTokenId): void
    {
        $this->authorize('update', $this->organization);

        $apiToken = ApiToken::query()
            ->where('organization_id', $this->organization->id)
            ->whereKey($apiTokenId)
            ->first();

        if ($apiToken === null) {
            return;
        }

        $this->openConfirmActionModal(
            'revokeApiToken',
            [$apiToken->id],
            __('Revoke API token'),
            __('Revoke :name? Integrations using this token will stop working immediately. This cannot be undone.', ['name' => $apiToken->name]),
            __('Revoke token'),
            true
        );
    }

    public function revokeApiToken(int|string $apiTokenId): void
    {
        $this->authorize('update', $this->organization);

        $apiToken = ApiToken::where('organization_id', $this->organization->id)->findOrFail($apiTokenId);

        // Same audit shape the API keys settings page writes — an admin
        // revoking another member's token is exactly the event you want a
        // trail for, and this path had none.
        $snapshot = [
            'token_id' => (string) $apiToken->id,
            'token_name' => $apiToken->name,
            'token_prefix' => $apiToken->token_prefix,
            'abilities' => $apiToken->abilities,
            'expires_at' => $apiToken->expires_at?->toIso8601String(),
        ];
        $apiToken->delete();

        audit_log($this->organization, auth()->user(), 'api_token.revoked', null, $snapshot, null);

        $this->refreshOrganization();
        $this->toastSuccess(__('API token revoked.'));
    }

    public function deleteOrganization(DeleteOrganizationAction $action): mixed
    {
        $this->authorize('delete', $this->organization);

        $this->validate([
            'delete_confirm' => ['required', 'same:name'],
        ], [
            'delete_confirm.same' => __('Type the organization name exactly to confirm.'),
        ]);

        $action->handle($this->organization, auth()->user());

        // Land the user on another organization they belong to.
        $next = auth()->user()->organizations()->first();
        if ($next) {
            session(['current_organization_id' => $next->id]);
        } else {
            Session::forget('current_organization_id');
        }
        Session::forget('current_team_id');

        Session::flash('success', __('Organization deleted.'));

        return $this->redirect(route('organizations.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.organizations.settings', [
            'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::ALL),
            'orgMembers' => $this->organization->users()
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email']),
        ]);
    }

    private function extensionFor(?string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => 'png',
        };
    }

    private function recordIconChange(?string $old, ?string $new): void
    {
        audit_log(
            $this->organization,
            auth()->user(),
            'organization.icon.updated',
            $this->organization,
            ['icon_path' => $old],
            ['icon_path' => $new],
        );
    }
}
