<?php

namespace App\Livewire\Concerns;

use App\Jobs\BuildQuickDownloadJob;
use App\Models\QuickDownload;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Modules\Backups\Services\BackupStagingS3ClientFactory;
use App\Services\Servers\QuickDownloadNotifier;
use App\Services\Servers\QuickDownloadStreamer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Shared "queue a quick download, then notify" flow for the backup surfaces. A
 * click no longer streams over a held-open SSH connection: it creates a
 * {@see QuickDownload}, dispatches {@see BuildQuickDownloadJob} (build on the box
 * → upload to the download bucket), and the view polls {@see pollQuickDownload()}
 * until the artifact is ready. Nothing auto-downloads — the requester is notified
 * in-app (plus email for large artifacts) with a signed link and grabs it from
 * there. The artifact is retained and re-downloadable until its window closes
 * (see config/quick_download.php retention_minutes).
 *
 * Self-contained authorization: every entry point re-resolves the target by id
 * and gates on `update` for its server, so a crafted id can't queue another org's
 * data.
 *
 * @phpstan-require-extends Component
 */
trait QueuesQuickDownloads
{
    use DispatchesToastNotifications;

    /** The quick-download row currently being prepared/polled, if any. */
    public ?string $qdId = null;

    /** Stable per-button key (e.g. "site:<id>:files") so the view spins the right row. */
    public ?string $qdTargetKey = null;

    /** Friendly label of the in-flight artifact, for the spinner / toasts. */
    public ?string $qdLabel = null;

    /** target key => error message, surfaced inline next to the button. */
    /** @var array<string, string> */
    public array $qdErrors = [];

    public function requestSiteQuickDownload(string $siteId, string $artifact): void
    {
        if (! in_array($artifact, QuickDownloadStreamer::SITE_ARTIFACTS, true)) {
            return;
        }

        $site = Site::query()->with('server')->whereKey($siteId)->first();
        if ($site === null || ! $site->server instanceof Server) {
            $this->toastError(__('Site not found.'));

            return;
        }
        $this->authorizeQuickDownloadServer($site->server);

        $this->queueQuickDownload([
            'organization_id' => $site->server->organization_id,
            'server_id' => $site->server_id,
            'site_id' => $site->id,
            'kind' => QuickDownload::KIND_SITE,
            'artifact' => $artifact,
        ], 'site:'.$site->id.':'.$artifact);
    }

    public function requestDatabaseQuickDownload(string $databaseId): void
    {
        $database = ServerDatabase::query()->with('server')->whereKey($databaseId)->first();
        if ($database === null || ! $database->server instanceof Server) {
            $this->toastError(__('Database not found.'));

            return;
        }
        $this->authorizeQuickDownloadServer($database->server);

        $this->queueQuickDownload([
            'organization_id' => $database->server->organization_id,
            'server_id' => $database->server_id,
            'site_id' => $database->site_id,
            'server_database_id' => $database->id,
            'kind' => QuickDownload::KIND_DATABASE,
            'artifact' => 'dump',
        ], 'db:'.$database->id);
    }

    public function requestAdhocQuickDownload(string $serverId, string $engine, string $name): void
    {
        $server = Server::query()->whereKey($serverId)->first();
        if ($server === null) {
            $this->toastError(__('Server not found.'));

            return;
        }
        $this->authorizeQuickDownloadServer($server);

        $name = trim($name);
        if ($name === '' || ! in_array($engine, ['mysql', 'mariadb', 'postgres'], true)) {
            $this->toastError(__('Unsupported database for quick download.'));

            return;
        }

        $this->queueQuickDownload([
            'organization_id' => $server->organization_id,
            'server_id' => $server->id,
            'kind' => QuickDownload::KIND_ADHOC_DATABASE,
            'artifact' => 'dump',
            'meta' => ['engine' => $engine, 'name' => $name],
        ], 'adhoc:'.$engine.':'.$name);
    }

    public function pollQuickDownload(): void
    {
        if ($this->qdId === null) {
            return;
        }

        $row = QuickDownload::find($this->qdId);
        if ($row === null) {
            $this->resetQuickDownloadState();

            return;
        }

        if ($row->isDownloadable()) {
            // Notify-only: nothing auto-downloads. The artifact is staged and
            // retained; the requester grabs it from the in-app inbox (plus email
            // for large ones). The "it's ready" toast is fired app-wide from the
            // notifier ({@see QuickDownloadStatusBroadcast}) so it shows even off
            // this page — we just stop polling and clear the spinner here.
            $this->resetQuickDownloadState();

            return;
        }

        if ($row->status === QuickDownload::STATUS_FAILED) {
            // Inline error next to the button (page-local); the failure toast is
            // broadcast app-wide from the notifier, same as the ready case.
            if ($this->qdTargetKey !== null) {
                $this->qdErrors[$this->qdTargetKey] = $row->error_message ?: __('The download could not be prepared.');
            }
            $this->resetQuickDownloadState();

            return;
        }

        // Already consumed/expired (e.g. grabbed from the email link first).
        if (in_array($row->status, [QuickDownload::STATUS_CONSUMED, QuickDownload::STATUS_EXPIRED], true)) {
            $this->resetQuickDownloadState();
        }

        // still pending/building — keep polling
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function queueQuickDownload(array $attrs, string $targetKey): void
    {
        if (! app(BackupStagingS3ClientFactory::class)->enabled()) {
            $this->toastError(__('Downloads aren’t configured yet — the staging bucket is missing.'));

            return;
        }

        unset($this->qdErrors[$targetKey]);

        // Dedup: collapse a double-click onto an in-flight build for the same
        // (server, target, user). A finished, still-retained artifact does NOT
        // block a new request — each click is a fresh point-in-time copy.
        $existing = $this->activeQuickDownloadQuery($attrs)->latest()->first();
        if ($existing instanceof QuickDownload
            && in_array($existing->status, [QuickDownload::STATUS_PENDING, QuickDownload::STATUS_BUILDING], true)) {
            $this->qdId = (string) $existing->id;
            $this->qdTargetKey = $targetKey;
            $this->qdLabel = QuickDownloadNotifier::label($existing);

            return;
        }

        $row = QuickDownload::create($attrs + [
            'requested_by_user_id' => auth()->id(),
            'status' => QuickDownload::STATUS_PENDING,
        ]);

        $this->qdId = (string) $row->id;
        $this->qdTargetKey = $targetKey;
        $this->qdLabel = QuickDownloadNotifier::label($row);

        BuildQuickDownloadJob::dispatch((string) $row->id, (string) $row->server_id);

        // The build runs on the box; when it lands we drop an in-app notification
        // (plus email for large artifacts) carrying the download link.
        $this->toastSuccess(__('Preparing your :label — we’ll notify you in-app when it’s ready to download.', ['label' => $this->qdLabel]));
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return Builder<QuickDownload>
     */
    private function activeQuickDownloadQuery(array $attrs): Builder
    {
        $query = QuickDownload::query()
            ->where('server_id', $attrs['server_id'])
            ->where('kind', $attrs['kind'])
            ->where('requested_by_user_id', auth()->id())
            ->whereIn('status', [
                QuickDownload::STATUS_PENDING,
                QuickDownload::STATUS_BUILDING,
                QuickDownload::STATUS_READY,
            ]);

        return match ($attrs['kind']) {
            QuickDownload::KIND_SITE => $query
                ->where('site_id', $attrs['site_id'])
                ->where('artifact', $attrs['artifact']),
            QuickDownload::KIND_DATABASE => $query
                ->where('server_database_id', $attrs['server_database_id']),
            QuickDownload::KIND_ADHOC_DATABASE => $query
                ->where('meta->engine', is_array($attrs['meta'] ?? null) ? $attrs['meta']['engine'] : '')
                ->where('meta->name', is_array($attrs['meta'] ?? null) ? $attrs['meta']['name'] : ''),
            default => $query,
        };
    }

    private function resetQuickDownloadState(): void
    {
        $this->qdId = null;
        $this->qdTargetKey = null;
        $this->qdLabel = null;
    }

    private function authorizeQuickDownloadServer(Server $server): void
    {
        abort_unless(
            $server->organization_id === auth()->user()?->currentOrganization()?->id,
            404,
        );
        Gate::authorize('update', $server);
    }
}
