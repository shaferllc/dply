<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Servers\Concerns\DeploysSharedKeys;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesAuthorizedKeys;
use App\Livewire\Servers\Concerns\ManagesSshKeyNotifications;
use App\Livewire\Servers\Concerns\ManagesSshKeyProfile;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\SyncsAuthorizedKeys;
use App\Models\OrganizationSshKey;
use App\Models\Server;
use App\Models\TeamSshKey;
use App\Models\UserSshKey;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SshPublicKeyFingerprint;
use App\Support\Servers\SshKeysWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceSshKeys extends Component
{
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use DeploysSharedKeys;
    use EmitsPanelEvent;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesAuthorizedKeys;
    use ManagesSshKeyNotifications;
    use ManagesSshKeyProfile;
    use RendersWorkspacePlaceholder;
    use SyncsAuthorizedKeys;

    public const DEFAULT_TAB = 'keys';

    /**
     * The allow-list was duplicated in setSshWorkspaceTab() and render(); with
     * ?tab= now reaching the property directly, both need the same guard.
     *
     * @var list<string>
     */
    public const TABS = ['keys', 'preview', 'advanced', 'activity', 'notifications'];

    public const ACTIVITY_PER_PAGE = 15;

    #[Url(as: 'tab', except: self::DEFAULT_TAB, history: true)]
    public string $ssh_workspace_tab = self::DEFAULT_TAB;

    /** Audit-log page. Not #[Url]-bound: a page number is transient state that
     *  shouldn't outlive the tab in a shared link. */
    public int $activityPage = 1;

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
        $this->ssh_workspace_tab = in_array($tab, self::TABS, true) ? $tab : self::DEFAULT_TAB;
    }

    /**
     * Audit-log paging. render() clamps the upper bound (it's the only place
     * that knows the total), so this just needs to stay >= 1.
     */
    public function setActivityPage(int $page): void
    {
        $this->activityPage = max(1, $page);
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

    /**
     * Merged SSH keys card skeleton (hide-hero) so lazy load matches the page
     * instead of flashing a separate title card + generic pulses.
     */
    public function placeholder(): View
    {
        // Required in every override: #[Lazy] renders this before #[Url] is
        // applied, so without it a deep-linked ?tab=activity paints the Keys
        // skeleton and then jumps.
        $this->seedUrlPropertiesFromRequest();

        if ($this->server === null) {
            return view('livewire.servers.partials.workspace-placeholder-empty');
        }

        // Passed as `skeletonTab`, deliberately NOT as `ssh_workspace_tab`:
        // SupportLazyLoading regenerates this view from the component's own
        // public properties, so a key that collides with one gets clobbered.
        $tab = in_array($this->ssh_workspace_tab, self::TABS, true)
            ? $this->ssh_workspace_tab
            : self::DEFAULT_TAB;

        return view('livewire.servers.partials.workspace-ssh-keys-placeholder', [
            'server' => $this->server,
            'skeletonTab' => $tab,
        ]);
    }

    public function render(): View
    {
        // ?tab= is user input now, so an unknown value lands here rather than
        // being filtered by setSshWorkspaceTab().
        if (! in_array($this->ssh_workspace_tab, self::TABS, true)) {
            $this->ssh_workspace_tab = self::DEFAULT_TAB;
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

        $activityPagination = null;

        if ($needsActivity) {
            // Was a flat limit(100): every switch to Activity rendered up to a
            // hundred rows, which is what made this tab slow to hydrate. Count
            // first so the head shows the real total rather than the capped
            // size of the loaded collection.
            $activityTotal = $this->server->sshKeyAuditEvents()->count();
            $totalPages = max(1, (int) ceil($activityTotal / self::ACTIVITY_PER_PAGE));

            // Clamp: ?activity_page= is user input, and deleting events can
            // strand the operator past the end.
            $page = max(1, min($this->activityPage, $totalPages));
            if ($page !== $this->activityPage) {
                $this->activityPage = $page;
            }

            $auditEvents = $this->server->sshKeyAuditEvents()
                ->with('user')
                ->forPage($page, self::ACTIVITY_PER_PAGE)
                ->get();

            $activityPagination = [
                'page' => $page,
                'total_pages' => $totalPages,
                'total' => $activityTotal,
                'per_page' => self::ACTIVITY_PER_PAGE,
                'from' => $activityTotal === 0 ? 0 : (($page - 1) * self::ACTIVITY_PER_PAGE) + 1,
                'to' => min($page * self::ACTIVITY_PER_PAGE, $activityTotal),
            ];
        }

        $viewData = SshKeysWorkspaceViewData::for(
            $this->server,
            $this,
            includeKeysContext: $needsKeys,
            includePreviewContext: $needsPreview,
            includeActivityContext: $needsActivity,
            auditEvents: $needsActivity ? $auditEvents : null,
            activityTotal: $activityPagination['total'] ?? null,
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
            'activityPagination' => $activityPagination,
            'fingerprints' => $fingerprints,
            'notifChannels' => $needsNotifications ? $this->assignableSshKeyNotificationChannels() : collect(),
            'notifSubscriptions' => $needsNotifications ? $this->sshKeyNotificationSubscriptions() : collect(),
            'notifEventLabels' => $needsNotifications ? $this->sshKeyEventLabels() : [],
        ]));
    }
}
