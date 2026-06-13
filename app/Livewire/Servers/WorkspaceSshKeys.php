<?php

namespace App\Livewire\Servers;

use App\Jobs\PreviewDriftJob;
use App\Jobs\SyncAuthorizedKeysJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\DeploysSharedKeys;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesAuthorizedKeys;
use App\Livewire\Servers\Concerns\ManagesSshKeyNotifications;
use App\Livewire\Servers\Concerns\ManagesSshKeyProfile;
use App\Livewire\Servers\Concerns\SyncsAuthorizedKeys;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\OrganizationSshKey;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\TeamSshKey;
use App\Models\UserSshKey;
use App\Services\Notifications\ServerSshKeyNotificationDispatcher;
use App\Services\Servers\OrganizationTeamSshKeyServerDeployer;
use App\Services\Servers\ServerAuthorizedKeysAuditLogger;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SshKeyLabelTemplate;
use App\Services\Servers\SshPublicKeyFingerprint;
use App\Support\OpenSshEd25519KeyPairGenerator;
use App\Support\Servers\SshKeysWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceSshKeys extends Component
{
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use EmitsPanelEvent;
    use HandlesServerRemovalFlow;
    use DeploysSharedKeys;
    use InteractsWithServerWorkspace;
    use ManagesAuthorizedKeys;
    use ManagesSshKeyNotifications;
    use ManagesSshKeyProfile;
    use SyncsAuthorizedKeys;
    use RendersWorkspacePlaceholder;

    /** @var 'keys'|'preview'|'advanced'|'activity'|'notifications' */
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

    /**
     * Console transcript captured while {@see self::previewDiff()} runs — surfaced as the
     * Drift tab's "View output" panel so the operator can see which targets were read and
     * whether anything errored without parsing the diff structure.
     *
     * @var list<string>
     */
    public array $diff_output = [];

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

    public function setSshWorkspaceTab(string $tab): void
    {
        $allowed = ['keys', 'preview', 'advanced', 'activity', 'notifications'];
        $this->ssh_workspace_tab = in_array($tab, $allowed, true) ? $tab : 'keys';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->ssh_workspace_tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }


    /**
     * Conditional gate for {@see syncAuthorizedKeys}. The explainer banner promises that the
     * workspace warns before a sync that would lock people out — this is where the warning
     * lives. Two trigger conditions:
     *
     *   1. The tracked set is empty. Syncing would write an empty authorized_keys; everyone
     *      using SSH against the box (including Dply) loses access until restored manually.
     *   2. The set has no key targeting Dply's login user. Other system users may still have
     *      keys, but Dply itself loses the ability to drive the server from this dashboard.
     *
     * Safe path runs sync inline. Risky path opens the existing confirm-action modal pre-bound
     * to call {@see syncAuthorizedKeys} on confirmation, so the operator gets a single explicit
     * "yes, lock me out" beat.
     */
    /**
     * Server-side guard mirrored on the Blade side. Returns true (and surfaces a toast) when a
     * sync run is queued or actively running on this server, so any caller that would conflict
     * with the in-flight job — Sync now, deploy-from-org/team, drift refresh — can short-circuit
     * before it touches state. Prevents the foot-gun where the operator's mid-sync click would
     * either silently no-op (deploys writing rows that the running sync won't include) or queue
     * a competing SSH op against the same authorized_keys file.
     */
    /** A sync that's been "queued"/"running" longer than this is treated as stuck and
     *  unblocks new dispatches — covers the cases where the queue worker isn't running,
     *  the job died mid-flight without writing meta, or a deploy interrupted the run. */
    public const SYNC_STALE_THRESHOLD_SECONDS = 300;


    public function render(): View
    {
        $allowedTabs = ['keys', 'preview', 'advanced', 'activity', 'notifications'];
        if (! in_array($this->ssh_workspace_tab, $allowedTabs, true)) {
            $this->ssh_workspace_tab = 'keys';
        }

        $tab = $this->ssh_workspace_tab;
        $needsKeys = $tab === 'keys';
        $needsPreview = $tab === 'preview';
        $needsActivity = $tab === 'activity';
        $needsNotifications = $tab === 'notifications';

        if ($needsKeys || $needsPreview) {
            $this->server->loadMissing('authorizedKeys');
        }

        $user = Auth::user();

        $profileKeys = collect();
        $orgKeys = collect();
        $teamKeys = collect();
        $auditEvents = collect();
        $fingerprints = [];
        $serverHasPersonalProfileKey = false;

        if ($needsKeys) {
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

            $serverHasPersonalProfileKey = $this->server->hasPersonalUserSshKey($user);

            foreach ($this->server->authorizedKeys as $ak) {
                $fingerprints[$ak->id] = SshPublicKeyFingerprint::forLine((string) $ak->public_key);
            }
        }

        if ($needsActivity) {
            $auditEvents = $this->server->sshKeyAuditEvents()->with('user')->limit(100)->get();
        }

        $viewData = SshKeysWorkspaceViewData::for(
            $this->server,
            $this,
            includeKeysContext: $needsKeys,
            includePreviewContext: $needsPreview,
            includeActivityContext: $needsActivity,
            auditEvents: $needsActivity ? $auditEvents : null,
        );

        return view('livewire.servers.workspace-ssh-keys', array_merge($viewData, [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'profileKeys' => $profileKeys,
            'serverHasPersonalProfileKey' => $serverHasPersonalProfileKey,
            'orgKeys' => $orgKeys,
            'teamKeys' => $teamKeys,
            'auditEvents' => $auditEvents,
            'fingerprints' => $fingerprints,
            'notifChannels' => $needsNotifications ? $this->assignableSshKeyNotificationChannels() : collect(),
            'notifSubscriptions' => $needsNotifications ? $this->sshKeyNotificationSubscriptions() : collect(),
            'notifEventLabels' => $needsNotifications ? $this->sshKeyEventLabels() : [],
        ]));
    }
}
