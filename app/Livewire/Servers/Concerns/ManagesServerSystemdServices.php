<?php

namespace App\Livewire\Servers\Concerns;

use App\Events\Servers\ServerSystemdActionCompletedBroadcast;
use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Models\ServerSystemdServiceAuditEvent;
use App\Models\ServerSystemdServiceState;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerSystemdServicesCatalog;
use App\Support\Servers\SystemdServiceStandbyReasonResolver;
use App\Support\ServerSystemdServiceNotificationKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Systemd inventory from DB (filled by {@see SyncServerSystemdServicesJob}) and allowlisted start/stop/restart.
 *
 * @phpstan-require-extends Component
 */
trait ManagesServerSystemdServices
{
    use ManagesSystemdActionBanner;
    use ManagesSystemdActions;
    use ManagesSystemdInventory;
    use ManagesSystemdModals;

    public ?string $remote_error = null;


    /**
     * When a queued systemd SSH task finishes, whether to dispatch {@see SyncServerSystemdServicesJob} (false for read-only status).
     * Public so it survives wire:poll round-trips — protected Livewire properties are reset on every request.
     */
    public ?bool $systemdQueueInventoryAfterRemoteTask = null;

    /**
     * @var list<array{unit: string, label: string, active: string, sub: string, ts: string, version: string, custom: bool, can_manage: bool}>
     */
    public array $systemdInventory = [];

    public ?string $systemdInventoryFetchedAt = null;

    /**
     * @var list<array{at: string, kind: string, unit: string, label: string, detail: ?string}>
     */
    public array $systemdServiceActivity = [];

    public ?string $systemdRemoteTaskId = null;

    /**
     * Checked unit names (normalized, e.g. nginx.service). Array-of-values checkboxes; do not use
     * associative keys — unit names contain dots.
     *
     * @var list<string>
     */
    public array $systemdSelectedList = [];

    public bool $systemdSelectAll = false;

    public string $newCustomSystemdUnit = '';

    public bool $showCustomSystemdModal = false;

    public bool $showSystemdStatusModal = false;

    public string $systemdStatusModalUnit = '';

    protected ?string $systemdStatusModalUnitNormalized = null;

    public string $systemdStatusModalOutput = '';

    public bool $systemdStatusModalLoading = false;

    public ?string $systemdStatusModalError = null;

    /** Which body the status modal is showing: 'status' (systemctl) or 'logs' (journalctl). */
    public string $systemdStatusModalView = 'status';

    public bool $showSystemdActionConfirm = false;

    /**
     * Kind drives icon, tone, copy. One of: 'start', 'restart', 'stop', 'reload', 'enable',
     * 'disable', 'bulk-restart', 'bulk-stop', 'remove-custom'.
     */
    public string $systemdActionConfirmKind = '';

    public string $systemdActionConfirmUnit = '';


    public bool $showSystemdNotifyModal = false;

    public string $systemdNotifyUnit = '';

    protected ?string $systemdNotifyUnitNormalized = null;

    /**
     * Channel id => which inventory-driven events notify that channel for the open unit.
     *
     * @var array<string, array{stopped: bool, started: bool, restarted: bool, state_changed: bool}>
     */
    public array $systemdNotifyMatrix = [];

    /**
     * @var list<array{id: string, label: string}>
     */
    public array $systemdNotifyChannelRows = [];

    /**
     * @var null|'action'|'status_modal'
     *
     * Public so it survives wire:poll — the cache-poll completion handler reads this to decide
     * whether to fire {@see finishSystemdActionBanner}. Protected would reset to null on every
     * Livewire request, leaving the action banner stuck on `queued`.
     */
    public ?string $systemdPendingKind = null;

    public ?string $systemdPendingActionUnit = null;

    public ?string $systemdRowBusyUnit = null;

    /** @var 'start'|'restart'|'stop'|'reload'|'enable'|'disable'|null */
    public ?string $systemdRowBusyAction = null;

    /** Normalized unit for the inventory row loading overlay (confirm modal + SSH). */
    public ?string $systemdActiveRowUnit = null;

    /** @var 'start'|'restart'|'stop'|'reload'|'enable'|'disable'|null */
    public ?string $systemdActiveRowAction = null;

    public bool $systemdBulkBusy = false;

    public string $systemdFilterSearch = '';

    public string $systemdFilterActive = 'all';

    public string $systemdFilterCustom = 'all';

    /**
     * When false, the services table hides background units the operator can't manage
     * (getty@tty1, ModemManager, multipathd, dbus, …). Failed units always render.
     * Toggled by the table footer "Show all services" button.
     */
    public bool $systemdShowSystem = false;


    public string $services_workspace_tab = 'inventory';

    /**
     * Latest sync.at timestamp the operator dismissed; the inventory-sync banner stays hidden
     * for that exact run and re-arms automatically on the next sync (different `at`). Persisted
     * in the session so dismissal survives a full page reload — Livewire properties otherwise
     * reset on remount.
     */
    public ?string $systemdSyncBannerDismissedAt = null;


    /**
     * Action banner: surfaces the most recent (or in-flight) systemctl action over SSH so the
     * operator can see queued/running/completed/failed status and the SSH transcript without
     * waiting for an inventory poll.
     */
    public string $systemdActionBannerKind = '';

    public string $systemdActionBannerUnit = '';

    public string $systemdActionBannerStatus = '';

    /**
     * @var list<string>
     */
    public array $systemdActionBannerLines = [];

    public ?string $systemdActionBannerError = null;

    public ?string $systemdActionBannerStartedAt = null;

    public ?string $systemdActionBannerFinishedAt = null;


}
