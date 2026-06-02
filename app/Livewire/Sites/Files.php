<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ServerFileBrowserAtomicWriter;
use App\Services\Servers\ServerFileBrowserAuditLogger;
use App\Services\Servers\ServerFileBrowserRemoteReader;
use App\Support\Servers\FileBrowserListing;
use App\Support\Servers\FileBrowserPathPolicy;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Site file browser (read + edit text files ≤1 MB + download ≤25 MB).
 *
 * Runs as the site's effectiveSystemUser. Edits use the atomic writer with an
 * optimistic mtime+sha256 precondition; saves into a path resolving under
 * `releases/<…>/` warn before committing (the next deploy will wipe them).
 */
#[Layout('layouts.app')]
class Files extends Component
{
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    #[Url(as: 'path', except: '')]
    public string $path = '';

    #[Url(as: 'q', except: '')]
    public string $filter = '';

    /** View modal state (used for both binary preview + small-file view). */
    public bool $showViewModal = false;

    public ?string $viewingPath = null;

    public ?string $viewingMime = null;

    public ?int $viewingSize = null;

    public ?string $viewingContent = null;

    public bool $viewingIsBinary = false;

    public bool $viewingTruncated = false;

    public ?string $viewingError = null;

    /** Edit modal state. */
    public bool $showEditModal = false;

    public ?string $editingPath = null;

    public ?string $editingContent = null;

    public ?string $editingMime = null;

    public ?int $editingSize = null;

    public ?int $editingMtime = null;

    public ?string $editingSha256 = null;

    public bool $editingInsideReleases = false;

    /** Set when a save is rejected for sha/mtime drift. */
    public bool $showConflictModal = false;

    public ?string $conflictMessage = null;

    /** Set when a save lands inside `releases/` — operator must confirm. */
    public bool $pendingReleaseWarning = false;

    public function mount(Server $server, Site $site): void
    {
        $this->authorize('view', $site);
        $this->server = $server;
        $this->site = $site;

        // Locked to the site directory: an out-of-root ?path (manual URL or a
        // stale link) snaps back to the repository root rather than exposing
        // the wider box like the server-wide browser does.
        $root = $this->siteRoot();
        $this->path = ($this->path !== '' && $this->path[0] === '/' && FileBrowserPathPolicy::isInside($this->path, $root))
            ? FileBrowserPathPolicy::normalize($this->path)
            : $root;
    }

    public function render(): View
    {
        $listing = $this->safeList();
        $runtimeMode = $this->site->runtimeTargetMode();
        $runtimeTarget = $this->site->runtimeTarget();

        return view('livewire.sites.files', [
            'listing' => $listing,
            'effectiveLoginUser' => $this->effectiveLoginUser(),
            'siteRoot' => $this->siteRoot(),
            'isAtomic' => $this->site->isAtomicDeploys(),
            'editMaxBytes' => (int) config('server_file_browser.edit_max_bytes', 1_048_576),
            'downloadMaxBytes' => (int) config('server_file_browser.download_max_bytes', 26_214_400),
            // Settings shell (sidebar + breadcrumb) — same vars the sibling
            // workspace pages (Monitor, Web server config) feed the partial.
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'files',
            'runtimeMode' => $runtimeMode,
            'runtimePublication' => is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [],
        ]);
    }

    /** Normalized repository root — the hard boundary for all navigation. */
    private function siteRoot(): string
    {
        return FileBrowserPathPolicy::normalize($this->site->effectiveRepositoryPath());
    }

    public function openEntry(string $name): void
    {
        try {
            $this->path = FileBrowserPathPolicy::join($this->path, $name);
            $this->filter = '';
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function jumpTo(string $absolute): void
    {
        try {
            $target = FileBrowserPathPolicy::normalize($absolute);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if (! FileBrowserPathPolicy::isInside($target, $this->siteRoot())) {
            $this->toastError(__('That path is outside this site\'s directory.'));

            return;
        }

        $this->path = $target;
        $this->filter = '';
    }

    public function goUp(): void
    {
        // Never climb above the site root — clamp there instead.
        $root = $this->siteRoot();
        $parent = FileBrowserPathPolicy::parent($this->path);
        $this->path = FileBrowserPathPolicy::isInside($parent, $root) ? $parent : $root;
        $this->filter = '';
    }

    public function openFile(string $name): void
    {
        try {
            $target = FileBrowserPathPolicy::join($this->path, $name);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $reader = app(ServerFileBrowserRemoteReader::class);
        $editCap = (int) config('server_file_browser.edit_max_bytes', 1_048_576);

        $this->viewingPath = $target;
        $this->viewingContent = null;
        $this->viewingError = null;
        $this->viewingIsBinary = false;
        $this->viewingTruncated = false;
        $this->showViewModal = true;

        try {
            $read = $reader->read($this->server, $target, $editCap, $this->effectiveLoginUser());
        } catch (\Throwable $e) {
            $this->viewingError = $e->getMessage();

            return;
        }

        $this->viewingMime = $read->mime;
        $this->viewingSize = $read->size;
        $this->viewingIsBinary = $read->isBinary;
        $this->viewingTruncated = $read->contentTruncated;
        $this->viewingContent = $read->isBinary ? null : $read->content;

        $this->logSensitiveOpenIfNeeded($target);
    }

    public function startEdit(string $name): void
    {
        try {
            $target = FileBrowserPathPolicy::join($this->path, $name);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $reader = app(ServerFileBrowserRemoteReader::class);
        $editCap = (int) config('server_file_browser.edit_max_bytes', 1_048_576);

        try {
            $read = $reader->read($this->server, $target, $editCap, $this->effectiveLoginUser());
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if ($read->contentTruncated) {
            $this->toastError(__('File is larger than the inline edit cap.'));

            return;
        }

        if ($read->isBinary) {
            $this->toastError(__('Binary files cannot be edited inline.'));

            return;
        }

        $this->editingPath = $target;
        $this->editingContent = $read->content ?? '';
        $this->editingMime = $read->mime;
        $this->editingSize = $read->size;
        $this->editingMtime = $read->mtime;
        $this->editingSha256 = $read->sha256;
        $this->editingInsideReleases = FileBrowserPathPolicy::isInsideReleases(
            $target,
            $this->site->effectiveRepositoryPath(),
        );
        $this->pendingReleaseWarning = false;
        $this->showEditModal = true;

        $this->logSensitiveOpenIfNeeded($target);
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewingPath = null;
        $this->viewingContent = null;
        $this->viewingError = null;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingPath = null;
        $this->editingContent = null;
        $this->editingSha256 = null;
        $this->editingMtime = null;
        $this->pendingReleaseWarning = false;
    }

    public function saveEdit(bool $confirmReleases = false): void
    {
        if ($this->editingPath === null || $this->editingSha256 === null || $this->editingMtime === null) {
            return;
        }

        if ($this->editingInsideReleases && ! $confirmReleases) {
            $this->pendingReleaseWarning = true;

            return;
        }

        $this->pendingReleaseWarning = false;
        $writer = app(ServerFileBrowserAtomicWriter::class);

        $result = $writer->write(
            $this->server,
            $this->editingPath,
            $this->editingSha256,
            $this->editingMtime,
            $this->editingContent ?? '',
            $this->effectiveLoginUser(),
        );

        if ($result->conflict()) {
            $this->showConflictModal = true;
            $this->conflictMessage = __('The file changed on disk since you opened it. Reload to see the latest version.');

            return;
        }

        if ($result->missing()) {
            $this->toastError(__('File no longer exists on the server.'));
            $this->closeEditModal();

            return;
        }

        if (! $result->ok) {
            $this->toastError(__('Save failed (:reason).', ['reason' => $result->conflictReason ?? 'UNKNOWN']));

            return;
        }

        $newBytes = strlen($this->editingContent ?? '');
        app(ServerFileBrowserAuditLogger::class)->recordWrite(
            $this->server->organization,
            Auth::user(),
            $this->server,
            $this->site,
            $this->editingPath,
            $this->editingSha256,
            $result->newSha256,
            (int) $this->editingSize,
            $newBytes,
            $this->effectiveLoginUser(),
            $this->editingInsideReleases,
        );

        $this->editingSha256 = $result->newSha256;
        $this->editingMtime = $result->newMtime;
        $this->editingSize = $newBytes;

        $this->toastSuccess(__('Saved.'));
        $this->closeEditModal();
    }

    public function closeConflictModal(): void
    {
        $this->showConflictModal = false;
        $this->conflictMessage = null;
    }

    protected function effectiveLoginUser(): string
    {
        return $this->site->effectiveSystemUser($this->server);
    }

    protected function safeList(): ?FileBrowserListing
    {
        try {
            return app(ServerFileBrowserRemoteReader::class)->list(
                $this->server,
                $this->path,
                $this->effectiveLoginUser(),
                $this->filter !== '' ? $this->filter : null,
            );
        } catch (\Throwable $e) {
            $this->toastError(__('Could not list :path: :msg', ['path' => $this->path, 'msg' => $e->getMessage()]));

            return null;
        }
    }

    protected function logSensitiveOpenIfNeeded(string $path): void
    {
        $patterns = (array) config('server_file_browser.sensitive_path_globs', []);
        if (! FileBrowserPathPolicy::matchesSensitiveGlob($path, $patterns)) {
            return;
        }

        app(ServerFileBrowserAuditLogger::class)->recordOpen(
            $this->server->organization,
            Auth::user(),
            $this->server,
            $this->site,
            $path,
            $this->effectiveLoginUser(),
        );
    }
}
