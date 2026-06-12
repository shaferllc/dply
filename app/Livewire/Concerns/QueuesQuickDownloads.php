<?php

namespace App\Livewire\Concerns;

use App\Jobs\BuildQuickDownloadJob;
use App\Models\QuickDownload;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Services\Backups\BackupStagingS3ClientFactory;
use App\Services\Servers\QuickDownloadNotifier;
use App\Services\Servers\QuickDownloadStreamer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Shared "queue a quick download, then notify + auto-grab" flow for the backup
 * surfaces. A click no longer streams over a held-open SSH connection: it creates
 * a {@see QuickDownload}, dispatches {@see BuildQuickDownloadJob} (build on the
 * box → upload to the download bucket), and the view polls {@see pollQuickDownload()}
 * until the artifact is ready, then redirects the browser to the signed,
 * single-use proxy route. The requester is also notified in-app + by email, so
 * they can walk away and still grab it within the 4h window.
 *
 * Self-contained authorization: every entry point re-resolves the target by id
 * and gates on `update` for its server, so a crafted id can't queue another org's
 * data.
 *
 * @phpstan-require-extends \Livewire\Component
 */
trait QueuesQuickDownloads
{
    /** The quick-download row currently being prepared/polled, if any. */
    public ?string $qdId = null;

    /** Stable per-button key (e.g. "site:<id>:files") so the view spins the right row. */
    public ?string $qdTargetKey = null;

    /** Friendly label of the in-flight artifact, for the spinner / toasts. */
    public ?string $qdLabel = null;

    /** target key => error message, surfaced inline next to the button. */
    public array $qdErrors = [];

    public function requestSiteQuickDownload(string $siteId, string $artifact): mixed
    {
        if (! in_array($artifact, QuickDownloadStreamer::SITE_ARTIFACTS, true)) {
            return null;
        }

        $site = Site::query()->with('server')->whereKey($siteId)->first();
        if ($site === null || ! $site->server instanceof Server) {
            $this->toastError(__('Site not found.'));

            return null;
        }
        $this->authorizeQuickDownloadServer($site->server);

        return $this->queueQuickDownload([
            'organization_id' => $site->server->organization_id,
            'server_id' => $site->server_id,
            'site_id' => $site->id,
            'kind' => QuickDownload::KIND_SITE,
            'artifact' => $artifact,
        ], 'site:'.$site->id.':'.$artifact);
    }

    public function requestDatabaseQuickDownload(string $databaseId): mixed
    {
        $database = ServerDatabase::query()->with('server')->whereKey($databaseId)->first();
        if ($database === null || ! $database->server instanceof Server) {
            $this->toastError(__('Database not found.'));

            return null;
        }
        $this->authorizeQuickDownloadServer($database->server);

        return $this->queueQuickDownload([
            'organization_id' => $database->server->organization_id,
            'server_id' => $database->server_id,
            'site_id' => $database->site_id,
            'server_database_id' => $database->id,
            'kind' => QuickDownload::KIND_DATABASE,
            'artifact' => 'dump',
        ], 'db:'.$database->id);
    }

    public function requestAdhocQuickDownload(string $serverId, string $engine, string $name): mixed
    {
        $server = Server::query()->whereKey($serverId)->first();
        if ($server === null) {
            $this->toastError(__('Server not found.'));

            return null;
        }
        $this->authorizeQuickDownloadServer($server);

        $name = trim($name);
        if ($name === '' || ! in_array($engine, ['mysql', 'mariadb', 'postgres'], true)) {
            $this->toastError(__('Unsupported database for quick download.'));

            return null;
        }

        return $this->queueQuickDownload([
            'organization_id' => $server->organization_id,
            'server_id' => $server->id,
            'kind' => QuickDownload::KIND_ADHOC_DATABASE,
            'artifact' => 'dump',
            'meta' => ['engine' => $engine, 'name' => $name],
        ], 'adhoc:'.$engine.':'.$name);
    }

    public function pollQuickDownload(): mixed
    {
        if ($this->qdId === null) {
            return null;
        }

        $row = QuickDownload::find($this->qdId);
        if ($row === null) {
            $this->resetQuickDownloadState();

            return null;
        }

        if ($row->isDownloadable()) {
            // Large artifacts were notified in-app + by email; don't auto-yank a
            // big file — stop polling and let the user grab it from the link.
            if ($row->isLarge()) {
                $label = $this->qdLabel ?? QuickDownloadNotifier::label($row);
                $this->resetQuickDownloadState();
                $this->toastSuccess(__('Your :label is ready — we’ve emailed you a link and added it to your notifications.', ['label' => $label]));

                return null;
            }

            return $this->triggerQuickDownload($row);
        }

        if ($row->status === QuickDownload::STATUS_FAILED) {
            $message = $row->error_message ?: __('The download could not be prepared.');
            if ($this->qdTargetKey !== null) {
                $this->qdErrors[$this->qdTargetKey] = $message;
            }
            $this->toastError($message);
            $this->resetQuickDownloadState();

            return null;
        }

        // Already consumed/expired (e.g. grabbed from the email link first).
        if (in_array($row->status, [QuickDownload::STATUS_CONSUMED, QuickDownload::STATUS_EXPIRED], true)) {
            $this->resetQuickDownloadState();
        }

        return null; // still pending/building — keep polling
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function queueQuickDownload(array $attrs, string $targetKey): mixed
    {
        if (! app(BackupStagingS3ClientFactory::class)->enabled()) {
            $this->toastError(__('Downloads aren’t configured yet — the staging bucket is missing.'));

            return null;
        }

        unset($this->qdErrors[$targetKey]);

        // Dedup: reuse an active request for the same (server, target, user) rather
        // than starting a second build on the box.
        $existing = $this->activeQuickDownloadQuery($attrs)->latest()->first();
        if ($existing instanceof QuickDownload && $existing->isActive()) {
            $this->qdId = (string) $existing->id;
            $this->qdTargetKey = $targetKey;
            $this->qdLabel = QuickDownloadNotifier::label($existing);

            return $existing->isDownloadable() ? $this->triggerQuickDownload($existing) : null;
        }

        $row = QuickDownload::create($attrs + [
            'requested_by_user_id' => auth()->id(),
            'status' => QuickDownload::STATUS_PENDING,
        ]);

        $this->qdId = (string) $row->id;
        $this->qdTargetKey = $targetKey;
        $this->qdLabel = QuickDownloadNotifier::label($row);

        BuildQuickDownloadJob::dispatch((string) $row->id, (string) $row->server_id);

        // Size is unknown until the build lands: small artifacts auto-download
        // here in a moment; large ones notify in-app + email when ready.
        $this->toastSuccess(__('Preparing your :label download — it’ll start automatically, or we’ll notify you if it’s large.', ['label' => $this->qdLabel]));

        return null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function activeQuickDownloadQuery(array $attrs)
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
                ->where('meta->engine', $attrs['meta']['engine'])
                ->where('meta->name', $attrs['meta']['name']),
            default => $query,
        };
    }

    private function triggerQuickDownload(QuickDownload $row): mixed
    {
        $url = URL::temporarySignedRoute(
            'quick-download.fetch',
            $row->expires_at ?? now()->addMinutes((int) config('backup_staging.ttl_minutes', 240)),
            ['quickDownload' => $row->id],
        );

        $this->resetQuickDownloadState();

        // Same-origin attachment route: the browser downloads without leaving the page.
        return $this->redirect($url);
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
