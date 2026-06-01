<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerFileBrowserAuditLogger;
use App\Services\Servers\ServerFileBrowserRemoteReader;
use App\Support\Servers\FileBrowserListing;
use App\Support\Servers\FileBrowserPathPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Server file browser (read-only + download in v1).
 *
 * Runs as the deploy user by default; org owners/admins can toggle "View as
 * root" — every toggle is recorded in the activity feed. Deployers get a 403
 * on this surface entirely (sysadmin tool, not a deployer one).
 */
#[Layout('layouts.app')]
class WorkspaceFiles extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.files';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    /** Current directory being viewed. */
    #[Url(as: 'path', except: '')]
    public string $path = '';

    /** Server-side glob filter (single segment). */
    #[Url(as: 'q', except: '')]
    public string $filter = '';

    /** View-as-root toggle (owner/admin only). */
    public bool $viewAsRoot = false;

    /** Modal state for inline file view. */
    public bool $showFileModal = false;

    public ?string $viewingPath = null;

    public ?string $viewingContent = null;

    public ?string $viewingMime = null;

    public ?int $viewingSize = null;

    public bool $viewingIsBinary = false;

    public bool $viewingTruncated = false;

    public ?string $viewingError = null;

    public function mount(Server $server): void
    {
        if (! Feature::active('workspace.files')) {
            if (workspace_files_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->comingSoonPreview = false;
        $this->bootWorkspace($server);
        $this->denyDeployer();

        if ($this->path === '' || $this->path[0] !== '/') {
            $this->path = $this->defaultPath();
        }
    }

    public function bootedRequiresFeature(): void
    {
        if ($this->comingSoonPreview) {
            return;
        }

        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }

    public function render(): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-files-preview');
        }

        $listing = $this->serverOpsReady() ? $this->safeList() : null;

        return view('livewire.servers.workspace-files', [
            'listing' => $listing,
            'opsReady' => $this->serverOpsReady(),
            'effectiveLoginUser' => $this->effectiveLoginUser(),
            'quickJumps' => (array) config('server_file_browser.server_quick_jumps', []),
            'canViewAsRoot' => $this->canViewAsRoot(),
            'editMaxBytes' => (int) config('server_file_browser.edit_max_bytes', 1_048_576),
            'downloadMaxBytes' => (int) config('server_file_browser.download_max_bytes', 26_214_400),
        ]);
    }

    /** Navigate into a directory by name (relative entry click). */
    public function openEntry(string $name): void
    {
        try {
            $this->path = FileBrowserPathPolicy::join($this->path, $name);
            $this->filter = '';
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        }
    }

    /** Jump to an absolute path (breadcrumb / quick-jump click). */
    public function jumpTo(string $absolute): void
    {
        try {
            $this->path = FileBrowserPathPolicy::normalize($absolute);
            $this->filter = '';
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        }
    }

    /** Walk one path segment up. */
    public function goUp(): void
    {
        $this->path = FileBrowserPathPolicy::parent($this->path);
        $this->filter = '';
    }

    /** Owner/admin only: flip the View-as-root toggle and record an audit event. */
    public function toggleViewAsRoot(): void
    {
        if (! $this->canViewAsRoot()) {
            $this->toastError(__('Only org owners or admins can view as root.'));

            return;
        }

        $this->viewAsRoot = ! $this->viewAsRoot;
        app(ServerFileBrowserAuditLogger::class)->recordRootToggle(
            $this->server->organization,
            Auth::user(),
            $this->server,
            $this->viewAsRoot,
        );
    }

    /** Open a file in the inline view modal. */
    public function openFile(string $name): void
    {
        try {
            $target = FileBrowserPathPolicy::join($this->path, $name);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->viewingPath = $target;
        $this->viewingContent = null;
        $this->viewingError = null;
        $this->viewingIsBinary = false;
        $this->viewingTruncated = false;
        $this->showFileModal = true;

        $reader = app(ServerFileBrowserRemoteReader::class);
        $editCap = (int) config('server_file_browser.edit_max_bytes', 1_048_576);

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

    public function closeFileModal(): void
    {
        $this->showFileModal = false;
        $this->viewingPath = null;
        $this->viewingContent = null;
        $this->viewingError = null;
    }

    protected function effectiveLoginUser(): string
    {
        if ($this->viewAsRoot && $this->canViewAsRoot()) {
            return 'root';
        }

        $deploy = trim((string) ($this->server->ssh_user ?? ''));

        return $deploy !== '' ? $deploy : 'root';
    }

    protected function canViewAsRoot(): bool
    {
        $user = Auth::user();
        $org = $this->server->organization;

        return $user !== null && $org !== null && $org->hasAdminAccess($user);
    }

    protected function denyDeployer(): void
    {
        if ($this->currentUserIsDeployer()) {
            abort(403, __('Deployer role does not have access to the server file browser.'));
        }
    }

    protected function defaultPath(): string
    {
        $deploy = trim((string) ($this->server->ssh_user ?? ''));
        if ($deploy === '' || $deploy === 'root') {
            return '/root';
        }

        return '/home/'.$deploy;
    }

    /** Catch SSH errors and surface them as a toast rather than killing the page. */
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
            null,
            $path,
            $this->effectiveLoginUser(),
        );
    }
}
