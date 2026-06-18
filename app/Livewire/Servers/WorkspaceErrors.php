<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\SurfacesErrorStream;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesErrorsNotifications;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\ErrorEvent;
use App\Models\Server;
use App\Services\Logs\ServerLogCorrelator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The server's "Errors" view — a chronological stream of every failure on the
 * box (server infra + roll-up of its hosted sites), backed by the dedicated
 * {@see ErrorEvent} table. Stream behaviour lives in {@see SurfacesErrorStream};
 * the Notifications sub-tab (routing channels to server.errors.* events) lives in
 * {@see ManagesErrorsNotifications}.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceErrors extends Component
{
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
    use ManagesErrorsNotifications;
    use RendersWorkspacePlaceholder;
    use SurfacesErrorStream;
    use WithPagination;

    /** @var list<string> */
    public const ERRORS_TABS = ['stream', 'notifications'];

    #[Url(as: 'tab', except: 'stream')]
    public string $errorsTab = 'stream';

    /** True when this server ships logs — gates the per-error "Logs" correlation jump. */
    public bool $showLogCorrelation = false;

    /** Drawer state for the "logs around this error" slice (Tier-1 correlation). */
    public bool $errorLogsOpen = false;

    public ?string $errorLogsLabel = null;

    /** @var array{instant:string,from:string,to:string,logs:list<array<string,mixed>>}|null */
    public ?array $errorLogsResult = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->showLogCorrelation = $server->logAgent()->exists();
    }

    /**
     * Open the "logs around this error" drawer: the host log slice surrounding
     * when the error occurred, on this server. A ClickHouse READ (like the Logs
     * explorer), not SSH — safe to run inline. Errors not on this server, or with
     * no shipped logs in the window, simply show an empty drawer.
     */
    public function openLogsForError(string $errorId): void
    {
        $this->authorize('update', $this->server);

        $error = ErrorEvent::query()->forServer((string) $this->server->id)->find($errorId);
        if ($error === null) {
            return;
        }

        $instant = $error->occurred_at ?? $error->created_at;
        $this->errorLogsLabel = $instant?->toDayDateTimeString();
        $this->errorLogsResult = app(ServerLogCorrelator::class)->forErrorEvent($error);
        $this->errorLogsOpen = true;
    }

    public function closeLogsForError(): void
    {
        $this->errorLogsOpen = false;
        $this->errorLogsResult = null;
        $this->errorLogsLabel = null;
    }

    public function setErrorsWorkspaceTab(string $tab): void
    {
        $this->errorsTab = in_array($tab, self::ERRORS_TABS, true) ? $tab : 'stream';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->errorsTab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    /** Everything on the box: server-owned errors + hosted sites' errors. */
    protected function scopedErrors(): Builder
    {
        return ErrorEvent::query()->forServer((string) $this->server->id);
    }

    /**
     * The unfiltered stream total equals the server's undismissed error count —
     * prime the shared memo so the sidebar "Errors" badge reuses it instead of
     * running the same count() again.
     */
    protected function shareStreamTotal(int $total): void
    {
        ErrorEvent::primeUndismissedCountForServer((string) $this->server->id, $total);
    }

    protected function authorizeErrorAccess(): void
    {
        $this->authorize('update', $this->server);
    }

    public function render(): View
    {
        if (in_array('errors', config('server_workspace.coming_soon_keys', []), true)) {
            return view('livewire.servers.workspace-errors-preview', ['server' => $this->server]);
        }

        $onNotificationsTab = $this->errorsTab === 'notifications';

        return view('livewire.servers.workspace-errors', [
            'notifChannels' => $onNotificationsTab ? $this->assignableErrorsNotificationChannels() : collect(),
            'notifSubscriptions' => $onNotificationsTab ? $this->errorsNotificationSubscriptions() : collect(),
            'notifEventLabels' => $onNotificationsTab ? $this->errorsEventLabels() : [],
        ]);
    }
}
