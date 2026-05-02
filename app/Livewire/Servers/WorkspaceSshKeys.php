<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\OrganizationSshKey;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\TeamSshKey;
use App\Models\UserSshKey;
use App\Services\Servers\OrganizationTeamSshKeyServerDeployer;
use App\Services\Servers\ServerAuthorizedKeysAuditLogger;
use App\Services\Servers\ServerAuthorizedKeysDiffPreview;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SshKeyLabelTemplate;
use App\Services\Servers\SshPublicKeyFingerprint;
use App\Support\OpenSshEd25519KeyPairGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSshKeys extends Component
{
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public string $ssh_workspace_tab = 'keys';

    public string $new_auth_name = '';

    public string $new_auth_key = '';

    public string $new_target_linux_user = '';

    public ?string $new_review_after = null;

    public ?string $profile_key_id = null;

    /** @var list<string> */
    public array $system_users = [];

    /** @var array<string, array{remote: list<string>, desired: list<string>, added: list<string>, removed: list<string>}>|null */
    public ?array $diff_result = null;

    public bool $advanced_disable_sync = false;

    public bool $advanced_health_check = false;

    public string $advanced_label_template = '';

    public string $deploy_org_key_id = '';

    public string $deploy_team_key_id = '';

    public string $deploy_target_linux_user = '';

    /** @var array<string, string> */
    public array $reviewDates = [];

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->system_users = $this->baselineSystemUsers();
        $this->new_target_linux_user = (string) ($server->ssh_user ?: 'root');
        $this->deploy_target_linux_user = $this->new_target_linux_user;
        $this->hydrateAdvancedFromServer();
        $this->loadReviewDateInputs();
    }

    protected function loadReviewDateInputs(): void
    {
        $this->reviewDates = [];
        $this->server->loadMissing('authorizedKeys');
        foreach ($this->server->authorizedKeys as $ak) {
            $this->reviewDates[$ak->id] = $ak->review_after?->format('Y-m-d') ?? '';
        }
    }

    protected function hydrateAdvancedFromServer(): void
    {
        $m = $this->server->meta ?? [];
        $this->advanced_disable_sync = (bool) data_get($m, config('server_ssh_keys.meta_disable_sync_key'));
        $this->advanced_health_check = (bool) data_get($m, config('server_ssh_keys.meta_health_check_key'));
        $this->advanced_label_template = (string) data_get($m, config('server_ssh_keys.meta_label_template_key'), '');
    }

    /**
     * @return list<string>
     */
    protected function baselineSystemUsers(): array
    {
        $u = (string) $this->server->ssh_user;
        if ($u === '') {
            return [];
        }

        return [$u];
    }

    public function updatedProfileKeyId(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $key = UserSshKey::query()
            ->where('user_id', Auth::id())
            ->whereKey($value)
            ->first();

        if ($key) {
            $this->applyLabelTemplate($key->name, (string) $this->new_target_linux_user);
            $this->new_auth_key = $key->public_key;
        }
    }

    protected function applyLabelTemplate(string $name, string $linuxUser): void
    {
        $tpl = SshKeyLabelTemplate::resolveTemplate($this->server);
        $this->new_auth_name = SshKeyLabelTemplate::apply($tpl, $name, $linuxUser, $this->server);
    }

    public function clearProfileSelection(): void
    {
        $this->profile_key_id = null;
    }

    public function generateNewAuthorizedKeyPair(): void
    {
        $this->authorize('update', $this->server);

        try {
            [$private, $public] = OpenSshEd25519KeyPairGenerator::generate();
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if (! UserSshKey::publicKeyLooksValid($public)) {
            $this->toastError(__('Generated key was invalid. Try again or generate a key locally with ssh-keygen.'));

            return;
        }

        $this->profile_key_id = null;

        if (trim($this->new_auth_name) === '') {
            $this->new_auth_name = __('Generated key');
        }

        $this->new_auth_key = $public;

        $this->dispatch(
            'dply-ssh-keypair-generated',
            privateKey: $private,
            publicKey: $public,
        );

        $this->toastSuccess(__('A new key pair was generated. Copy your private key from the dialog, then use “Add SSH key” and “Sync authorized_keys”.'));
    }

    #[On('personal-ssh-key-created')]
    public function refreshProfileKeysAfterCreate(): void
    {
        $this->toastSuccess(__('SSH key saved. Select it below to attach it to this server, then sync authorized_keys.'));
    }

    public function loadSystemUsers(ServerPasswdUserLister $lister): void
    {
        $this->authorize('update', $this->server);

        try {
            $names = $lister->listUsernames($this->server->fresh());
            $merged = array_values(array_unique([...$this->baselineSystemUsers(), ...$names]));
            sort($merged);
            $this->system_users = $merged;
            $this->toastSuccess(__('Loaded system users from the server.'));
        } catch (\Throwable $e) {
            $this->toastError($this->friendlyWorkspaceError($e, __('Dply could not connect to the server to load system users.')));
        }
    }

    public function saveAdvancedSettings(): void
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
        $this->toastSuccess(__('SSH key settings saved.'));
    }

    public function previewDiff(ServerAuthorizedKeysDiffPreview $diff): void
    {
        $this->authorize('view', $this->server);
        try {
            $this->diff_result = $diff->diffPerUser($this->server->fresh(['authorizedKeys']));
            $this->ssh_workspace_tab = 'preview';
        } catch (\Throwable $e) {
            $this->toastError($this->friendlyWorkspaceError($e, __('Dply could not connect to the server to preview SSH key drift.')));
        }
    }

    public function addAuthorizedKey(ServerAuthorizedKeysAuditLogger $audit): void
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

    public function deleteAuthorizedKey(string $id, ServerAuthorizedKeysAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $key = ServerAuthorizedKey::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

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

        $key->delete();
        $this->loadReviewDateInputs();
        $this->toastSuccess(__('Key removed. Sync again to update the server.'));
    }

    public function syncAuthorizedKeys(ServerAuthorizedKeysSynchronizer $sync): void
    {
        $this->authorize('update', $this->server);
        try {
            $this->server->refresh();
            $sync->sync($this->server->fresh(['authorizedKeys']), Auth::user(), Request::ip());
            $this->loadReviewDateInputs();
            $this->toastSuccess(__('authorized_keys updated on the server.'));
        } catch (\Throwable $e) {
            $this->toastError($this->friendlyWorkspaceError(
                $e,
                __('Dply could not connect to the server to sync authorized_keys. Check that the server SSH login user still accepts Dply\'s provisioned key.')
            ));
        }
    }

    protected function friendlyWorkspaceError(\Throwable $e, string $defaultMessage): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return $defaultMessage;
        }

        if (str_contains($message, 'Permission denied (publickey)')) {
            return $defaultMessage.' '.__('The server rejected the SSH key for :connection.', [
                'connection' => $this->server->getSshConnectionString(),
            ]);
        }

        if (str_contains($message, 'Could not create script directory')) {
            return $defaultMessage.' '.__('The server did not allow Dply to start a remote SSH task for :connection.', [
                'connection' => $this->server->getSshConnectionString(),
            ]);
        }

        if (str_contains($message, 'Failed to execute task:')) {
            return $defaultMessage;
        }

        return $message;
    }

    public function deployOrganizationKey(OrganizationTeamSshKeyServerDeployer $deployer): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'deploy_org_key_id' => ['required', 'string', 'exists:organization_ssh_keys,id'],
            'deploy_target_linux_user' => ['required', 'string', 'max:64', Rule::in($this->system_users)],
        ]);

        $key = OrganizationSshKey::query()->whereKey($this->deploy_org_key_id)->firstOrFail();
        $selected = trim($this->deploy_target_linux_user);
        $stored = $selected === (string) $this->server->ssh_user ? '' : $selected;

        $result = $deployer->deployOrganizationKey(Auth::user(), $key, $this->server->fresh(), $stored);
        if ($result['ok']) {
            $this->toastSuccess($result['message']);
        } else {
            $this->toastError($result['message']);
        }
    }

    public function deployTeamKey(OrganizationTeamSshKeyServerDeployer $deployer): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'deploy_team_key_id' => ['required', 'string', 'exists:team_ssh_keys,id'],
            'deploy_target_linux_user' => ['required', 'string', 'max:64', Rule::in($this->system_users)],
        ]);

        $key = TeamSshKey::query()->whereKey($this->deploy_team_key_id)->firstOrFail();
        $selected = trim($this->deploy_target_linux_user);
        $stored = $selected === (string) $this->server->ssh_user ? '' : $selected;

        $result = $deployer->deployTeamKey(Auth::user(), $key, $this->server->fresh(), $stored);
        if ($result['ok']) {
            $this->toastSuccess($result['message']);
        } else {
            $this->toastError($result['message']);
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['authorizedKeys']);
        $user = Auth::user();

        $profileKeys = UserSshKey::query()
            ->where('user_id', $user?->id)
            ->orderBy('name')
            ->get();

        $orgKeys = $this->server->organization_id
            ? OrganizationSshKey::query()
                ->where('organization_id', $this->server->organization_id)
                ->orderBy('name')
                ->get()
            : collect();

        $teamKeys = $this->server->team_id
            ? TeamSshKey::query()
                ->where('team_id', $this->server->team_id)
                ->orderBy('name')
                ->get()
            : collect();

        $auditEvents = $this->server->sshKeyAuditEvents()->with('user')->limit(100)->get();

        $fingerprints = [];
        foreach ($this->server->authorizedKeys as $ak) {
            $fingerprints[$ak->id] = SshPublicKeyFingerprint::forLine((string) $ak->public_key);
        }

        return view('livewire.servers.workspace-ssh-keys', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'profileKeys' => $profileKeys,
            'serverHasPersonalProfileKey' => $this->server->hasPersonalUserSshKey($user),
            'orgKeys' => $orgKeys,
            'teamKeys' => $teamKeys,
            'auditEvents' => $auditEvents,
            'fingerprints' => $fingerprints,
        ]);
    }
}
