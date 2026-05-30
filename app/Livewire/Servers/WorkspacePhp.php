<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerConsoleActions;
use App\Models\ConfigRevision;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConfigRevisions\Diff\ConfigRevisionDiffRegistry;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ServerPhpConfigEditor;
use App\Services\Servers\ServerPhpConfigValidationException;
use App\Services\Servers\ServerPhpManager;
use App\Support\Servers\ServerPhpMutationLock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspacePhp extends Component
{
    use DismissesServerConsoleActionRun;
    use InteractsWithServerWorkspace;
    use RunsServerConsoleActions;

    public ?string $remote_output = null;

    public ?string $remote_error = null;

    public bool $phpConfigEditorOpen = false;

    public ?string $phpConfigEditorVersion = null;

    public ?string $phpConfigEditorTarget = null;

    public ?string $phpConfigEditorTargetLabel = null;

    public ?string $phpConfigEditorPath = null;

    public string $phpConfigEditorContent = '';

    /** Snapshot of the content loaded from the server, used by "Discard changes" to revert. */
    public string $phpConfigEditorOriginalContent = '';

    public ?string $phpConfigEditorReloadGuidance = null;

    public ?string $phpConfigEditorValidationOutput = null;

    /** Line numbers extracted from the last validation failure, used to highlight offending lines in the editor snippet. */
    public array $phpConfigEditorErrorLines = [];

    /** Optional user-supplied note attached to the next successful save's revision. */
    public string $phpConfigEditorSummary = '';

    /** Whether the live file on disk differs from the latest stored revision. Surfaces the drift banner. */
    public bool $phpConfigEditorDriftDetected = false;

    /** ULID of the revision that matches the current live file (if any). Drives the "Current" badge. */
    public ?string $phpConfigEditorCurrentRevisionId = null;

    /** Page size for the revisions sidebar; user can grow via "Show older". */
    public int $phpConfigEditorRevisionsLimit = 50;

    /** Diff view state. When a revision id is set, the right pane swaps to a diff view. */
    public ?string $phpConfigEditorDiffRevisionId = null;

    /** Compare-mode lets users pick two revisions for an A↔B diff instead of revision-vs-editor. */
    public bool $phpConfigEditorCompareMode = false;

    public ?string $phpConfigEditorCompareA = null;

    public ?string $phpConfigEditorCompareB = null;

    public function runPhpPackageAction(string $action, string $version): void
    {
        $this->authorize('update', $this->server);

        $this->remote_error = null;
        $this->remote_output = null;

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before managing PHP packages.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        $actionVerb = str_replace('_', ' ', $action);

        if (ServerPhpMutationLock::isHeld($this->server)) {
            $msg = __('Another PHP action is already running for this server. Wait for it to finish.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        try {
            $result = $this->runConsoleAction(
                $this->server,
                'php_'.$action,
                __(':verb PHP :version on :host', [
                    'verb' => ucfirst($actionVerb), 'version' => $version, 'host' => $this->server->name,
                ]),
                function (ConsoleEmitter $emit) use ($action, $version, $actionVerb): array {
                    $result = app(ServerPhpManager::class)->applyPackageAction(
                        $this->server,
                        $action,
                        $version,
                        function () use ($emit, $actionVerb, $version): void {
                            $emit->step('php', sprintf('apt %s php%s', $actionVerb, $version));
                        },
                    );
                    foreach (preg_split("/\r?\n/", (string) ($result['output'] ?? '')) ?: [] as $line) {
                        if ($line !== '') {
                            $emit($line, ConsoleAction::LEVEL_INFO, 'php');
                        }
                    }
                    if (($result['status'] ?? null) === 'stale') {
                        throw new \RuntimeException((string) ($result['message'] ?? __('PHP inventory may be stale.')));
                    }
                    $emit->success('php', (string) ($result['message'] ?? __('PHP action completed.')));

                    return $result;
                },
            );

            $this->server->refresh();
            $this->toastSuccess($result['message'] ?? __('PHP action completed.'));
        } catch (\Throwable $e) {
            $this->server->refresh();
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    public function refreshPhpInventory(): void
    {
        $this->authorize('update', $this->server);

        $this->remote_error = null;
        $this->remote_output = null;

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before refreshing PHP inventory.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        try {
            $result = $this->runConsoleAction(
                $this->server,
                'php_refresh_inventory',
                __('Refresh PHP inventory on :host', ['host' => $this->server->name]),
                function (ConsoleEmitter $emit): array {
                    $emit->step('php', 'Probing installed PHP versions');
                    $result = app(ServerPhpManager::class)->refreshInventory($this->server);
                    foreach (preg_split("/\r?\n/", (string) ($result['output'] ?? '')) ?: [] as $line) {
                        if ($line !== '') {
                            $emit($line, ConsoleAction::LEVEL_INFO, 'php');
                        }
                    }
                    if (($result['status'] ?? null) === 'stale') {
                        throw new \RuntimeException((string) ($result['message'] ?? __('PHP inventory may be stale.')));
                    }
                    $emit->success('php', (string) ($result['message'] ?? __('PHP inventory refreshed.')));

                    return $result;
                },
            );

            $this->server->refresh();
            $this->toastSuccess($result['message'] ?? __('PHP inventory refreshed.'));
        } catch (\Throwable $e) {
            $this->server->refresh();
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    public function openPhpConfigEditor(string $version, string $target): void
    {
        $this->authorize('update', $this->server);

        $this->phpConfigEditorValidationOutput = null;
        $this->phpConfigEditorErrorLines = [];
        $this->remote_error = null;

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before editing PHP config.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        try {
            $result = $this->runConsoleAction(
                $this->server,
                'php_load_config',
                __('Load PHP :target for :version on :host', [
                    'target' => $target,
                    'version' => $version,
                    'host' => $this->server->name,
                ]),
                function (ConsoleEmitter $emit) use ($version, $target): array {
                    $emit->step('php', 'Reading config from the server');
                    $result = app(ServerPhpConfigEditor::class)->openTarget($this->server, $version, $target);
                    $emit($result['path'], ConsoleAction::LEVEL_INFO, 'php');
                    $emit->success('php', __('Loaded :label.', ['label' => $result['label']]));

                    return $result;
                },
            );

            $this->phpConfigEditorOpen = true;
            $this->phpConfigEditorVersion = $result['version'];
            $this->phpConfigEditorTarget = $result['target'];
            $this->phpConfigEditorTargetLabel = $result['label'];
            $this->phpConfigEditorPath = $result['path'];
            $this->phpConfigEditorContent = $result['content'];
            $this->phpConfigEditorOriginalContent = $result['content'];
            $this->phpConfigEditorReloadGuidance = $result['reload_guidance'] ?? null;
            $this->phpConfigEditorSummary = '';
            $this->phpConfigEditorDiffRevisionId = null;
            $this->phpConfigEditorCompareMode = false;
            $this->phpConfigEditorCompareA = null;
            $this->phpConfigEditorCompareB = null;
            $this->phpConfigEditorRevisionsLimit = 50;

            $this->refreshRevisionState(app(ServerPhpConfigEditor::class));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    public function loadRevision(string $revisionId): void
    {
        $this->authorize('update', $this->server);

        $editor = app(ServerPhpConfigEditor::class);
        $rev = $this->lookupCurrentStreamRevision($editor, $revisionId);
        if ($rev === null) {
            $this->toastError(__('Revision not found.'));

            return;
        }

        $snapshot = is_array($rev->snapshot) ? $rev->snapshot : [];
        $content = is_string($snapshot['content'] ?? null) ? $snapshot['content'] : '';

        $this->phpConfigEditorContent = $content;
        $this->phpConfigEditorErrorLines = [];
        $this->phpConfigEditorValidationOutput = null;
        $this->phpConfigEditorDiffRevisionId = null;
        $this->toastSuccess(__('Revision loaded into editor.'));
    }

    public function showRevisionDiff(string $revisionId): void
    {
        $this->authorize('view', $this->server);
        $this->phpConfigEditorDiffRevisionId = $revisionId;
    }

    public function closeRevisionDiff(): void
    {
        $this->phpConfigEditorDiffRevisionId = null;
        $this->phpConfigEditorCompareA = null;
        $this->phpConfigEditorCompareB = null;
    }

    public function toggleCompareMode(): void
    {
        $this->phpConfigEditorCompareMode = ! $this->phpConfigEditorCompareMode;
        $this->phpConfigEditorCompareA = null;
        $this->phpConfigEditorCompareB = null;
        $this->phpConfigEditorDiffRevisionId = null;
    }

    public function selectForCompare(string $revisionId): void
    {
        $this->authorize('view', $this->server);

        if ($this->phpConfigEditorCompareA === null) {
            $this->phpConfigEditorCompareA = $revisionId;

            return;
        }

        if ($this->phpConfigEditorCompareA === $revisionId) {
            $this->phpConfigEditorCompareA = null;

            return;
        }

        $this->phpConfigEditorCompareB = $revisionId;
    }

    public function captureLiveAsRevision(): void
    {
        $this->authorize('update', $this->server);

        if ($this->phpConfigEditorVersion === null || $this->phpConfigEditorTarget === null) {
            return;
        }

        try {
            $editor = app(ServerPhpConfigEditor::class);
            $editor->captureLiveAsRevision(
                $this->server,
                $this->phpConfigEditorVersion,
                $this->phpConfigEditorTarget,
                auth()->user(),
            );

            $this->refreshRevisionState($editor);
            $this->toastSuccess(__('Live file captured as a revision.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function showOlderRevisions(): void
    {
        $this->phpConfigEditorRevisionsLimit += 50;
    }

    protected function lookupCurrentStreamRevision(ServerPhpConfigEditor $editor, string $revisionId): ?ConfigRevision
    {
        if ($this->phpConfigEditorVersion === null || $this->phpConfigEditorTarget === null) {
            return null;
        }

        $streamKey = $editor->streamKey($this->server, $this->phpConfigEditorVersion, $this->phpConfigEditorTarget);

        return ConfigRevision::query()
            ->whereKey($revisionId)
            ->where('stream_key', $streamKey)
            ->first();
    }

    /**
     * Recompute drift + current-revision pointers using the editor's
     * loaded snapshot of the live file content. Cheap (one hash + one
     * row read). Called on open and after a successful save.
     */
    protected function refreshRevisionState(ServerPhpConfigEditor $editor): void
    {
        $this->phpConfigEditorDriftDetected = false;
        $this->phpConfigEditorCurrentRevisionId = null;

        if ($this->phpConfigEditorVersion === null
            || $this->phpConfigEditorTarget === null
            || $this->phpConfigEditorPath === null
        ) {
            return;
        }

        $streamKey = $editor->streamKey(
            $this->server,
            $this->phpConfigEditorVersion,
            $this->phpConfigEditorTarget,
        );

        $latest = ConfigRevision::query()->forStream($streamKey)->first();
        if ($latest === null) {
            // No revisions yet — a baseline will be captured on the first save.
            return;
        }

        $liveChecksum = $editor->snapshotChecksumFor(
            $this->phpConfigEditorPath,
            $this->phpConfigEditorOriginalContent,
        );

        if ($latest->checksum === $liveChecksum) {
            $this->phpConfigEditorCurrentRevisionId = $latest->id;
        } else {
            $this->phpConfigEditorDriftDetected = true;
        }
    }

    public function closePhpConfigEditor(): void
    {
        // Refuse to close while the user has unfixed validation errors so they
        // don't accidentally lose the failed-edit context. They can either fix
        // and re-save, or use "Discard changes" to revert and close explicitly.
        if (! empty($this->phpConfigEditorErrorLines)) {
            $this->toastError(__('Fix the validation errors first, or click "Discard changes" to revert.'));

            return;
        }

        $this->resetPhpConfigEditor();
    }

    public function discardPhpConfigEditor(): void
    {
        $this->resetPhpConfigEditor();
        $this->toastSuccess(__('Changes discarded.'));
    }

    protected function resetPhpConfigEditor(): void
    {
        $this->phpConfigEditorOpen = false;
        $this->phpConfigEditorVersion = null;
        $this->phpConfigEditorTarget = null;
        $this->phpConfigEditorTargetLabel = null;
        $this->phpConfigEditorPath = null;
        $this->phpConfigEditorContent = '';
        $this->phpConfigEditorOriginalContent = '';
        $this->phpConfigEditorReloadGuidance = null;
        $this->phpConfigEditorValidationOutput = null;
        $this->phpConfigEditorErrorLines = [];
        $this->phpConfigEditorSummary = '';
        $this->phpConfigEditorDriftDetected = false;
        $this->phpConfigEditorCurrentRevisionId = null;
        $this->phpConfigEditorRevisionsLimit = 50;
        $this->phpConfigEditorDiffRevisionId = null;
        $this->phpConfigEditorCompareMode = false;
        $this->phpConfigEditorCompareA = null;
        $this->phpConfigEditorCompareB = null;
    }

    public function savePhpConfigEditor(): void
    {
        $this->authorize('update', $this->server);

        $this->phpConfigEditorValidationOutput = null;
        $this->phpConfigEditorErrorLines = [];
        $this->remote_error = null;

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before editing PHP config.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        if ($this->phpConfigEditorVersion === null || $this->phpConfigEditorTarget === null) {
            $msg = __('Choose a PHP config target before saving.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        $version = $this->phpConfigEditorVersion;
        $target = $this->phpConfigEditorTarget;
        $content = $this->phpConfigEditorContent;
        $summary = trim($this->phpConfigEditorSummary) !== '' ? trim($this->phpConfigEditorSummary) : null;

        if (ServerPhpMutationLock::isHeld($this->server)) {
            $msg = __('Another PHP action is already running for this server. Wait for it to finish.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        try {
            $result = $this->runConsoleAction(
                $this->server,
                'php_save_config',
                __('Save PHP :target for :version on :host', [
                    'target' => $target,
                    'version' => $version,
                    'host' => $this->server->name,
                ]),
                function (ConsoleEmitter $emit) use ($version, $target, $content, $summary): array {
                    $result = app(ServerPhpConfigEditor::class)->saveTarget(
                        $this->server,
                        $version,
                        $target,
                        $content,
                        auth()->user(),
                        $summary,
                        function () use ($emit): void {
                            $emit->step('php', 'Validating and saving config');
                        },
                    );
                    $output = trim((string) ($result['output'] ?? $result['verification_output'] ?? ''));
                    foreach (preg_split("/\r?\n/", $output) ?: [] as $line) {
                        if ($line !== '') {
                            $emit($line, ConsoleAction::LEVEL_INFO, 'php');
                        }
                    }
                    $emit->success('php', (string) ($result['message'] ?? __('PHP config saved.')));

                    return $result;
                },
            );

            $editor = app(ServerPhpConfigEditor::class);
            $this->phpConfigEditorOriginalContent = $this->phpConfigEditorContent;
            $this->phpConfigEditorSummary = '';
            $this->refreshRevisionState($editor);

            $this->toastSuccess($result['message'] ?? __('PHP config saved.'));
            $this->phpConfigEditorReloadGuidance = $result['reload_guidance'] ?? null;
            $this->phpConfigEditorValidationOutput = $result['verification_output'] ?? null;
        } catch (ServerPhpConfigValidationException $e) {
            $this->phpConfigEditorValidationOutput = $e->validationOutput();
            $this->phpConfigEditorErrorLines = $this->parseValidationErrorLines($e->validationOutput());
            $this->remote_error = $e->getMessage();
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    /**
     * Extract line numbers that the validator complained about so the view can
     * highlight them in the editor snippet. Matches PHP's "on line N" and the
     * "(line N)" form php-fpm -t uses for INI/pool parse errors.
     *
     * @return list<int>
     */
    protected function parseValidationErrorLines(string $output): array
    {
        if ($output === '' || ! preg_match_all('/(?:on line|\(line)\s+(\d+)/i', $output, $matches)) {
            return [];
        }

        $lines = [];
        foreach ($matches[1] as $raw) {
            $n = (int) $raw;
            if ($n > 0) {
                $lines[$n] = true;
            }
        }

        $unique = array_keys($lines);
        sort($unique);

        return $unique;
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        // No $this->server->refresh() here: route binding (first load) and Livewire's
        // Eloquent synthesizer (subsequent requests) already provide a current row.
        // Action handlers that mutate the server refresh it themselves.
        $phpData = app(ServerPhpManager::class)->workspaceData($this->server);
        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $refreshMeta = is_array($meta['php_inventory_refresh'] ?? null) ? $meta['php_inventory_refresh'] : [];
        $inventoryMeta = is_array($meta['php_inventory'] ?? null) ? $meta['php_inventory'] : [];
        $sshUnavailable = $this->server->isReady() && blank($this->server->ssh_private_key);
        $opsReady = $this->serverOpsReady();

        $revisions = collect();
        $diffText = null;
        $diffHeader = null;

        if ($this->phpConfigEditorOpen
            && $this->phpConfigEditorVersion !== null
            && $this->phpConfigEditorTarget !== null
        ) {
            $editor = app(ServerPhpConfigEditor::class);
            $streamKey = $editor->streamKey($this->server, $this->phpConfigEditorVersion, $this->phpConfigEditorTarget);
            $revisions = ConfigRevision::query()
                ->forStream($streamKey)
                ->with('user:id,name')
                ->limit($this->phpConfigEditorRevisionsLimit)
                ->get();

            [$diffText, $diffHeader] = $this->buildDiffForView($editor, $revisions);
        }

        return view('livewire.servers.workspace-php', [
            'opsReady' => $opsReady,
            'phpRun' => $this->latestConsoleActionFor($this->server, 'php_'),
            'phpSummary' => $phpData['summary'],
            'phpVersionRows' => $phpData['version_rows'],
            'sshUnavailable' => $sshUnavailable,
            'phpInventoryRefreshRunning' => ($refreshMeta['status'] ?? null) === 'running',
            'phpInventoryRefreshFailed' => ($refreshMeta['status'] ?? null) === 'failed',
            'phpInventoryStale' => ($refreshMeta['status'] ?? null) === 'stale',
            'phpInventoryRefreshError' => is_string($refreshMeta['error'] ?? null) ? $refreshMeta['error'] : null,
            'phpEnvironmentUnsupported' => array_key_exists('supported', $inventoryMeta) && ($inventoryMeta['supported'] === false),
            'phpInventoryNeverRun' => $opsReady
                && $refreshMeta === []
                && $inventoryMeta === []
                && ((int) ($phpData['summary']['installed_count'] ?? 0) === 0),
            'phpConfigRevisions' => $revisions,
            'phpConfigDiffText' => $diffText,
            'phpConfigDiffHeader' => $diffHeader,
        ]);
    }

    /**
     * @param  Collection<int, ConfigRevision>  $revisions
     * @return array{0: ?string, 1: ?string} [diff text, header label]
     */
    protected function buildDiffForView(ServerPhpConfigEditor $editor, $revisions): array
    {
        // Compare-mode wins if both revisions are picked. Otherwise, fall back
        // to "revision vs current editor content" when a single diff is open.
        if ($this->phpConfigEditorCompareMode
            && $this->phpConfigEditorCompareA !== null
            && $this->phpConfigEditorCompareB !== null
        ) {
            $a = $revisions->firstWhere('id', $this->phpConfigEditorCompareA)
                ?? $this->lookupCurrentStreamRevision($editor, $this->phpConfigEditorCompareA);
            $b = $revisions->firstWhere('id', $this->phpConfigEditorCompareB)
                ?? $this->lookupCurrentStreamRevision($editor, $this->phpConfigEditorCompareB);
            if ($a === null || $b === null) {
                return [null, null];
            }

            return [
                app(ConfigRevisionDiffRegistry::class)
                    ->rendererFor($a->kind)
                    ->render(is_array($a->snapshot) ? $a->snapshot : [], is_array($b->snapshot) ? $b->snapshot : []),
                __('Comparing revisions :a → :b', [
                    'a' => optional($a->created_at)->format('Y-m-d H:i'),
                    'b' => optional($b->created_at)->format('Y-m-d H:i'),
                ]),
            ];
        }

        if ($this->phpConfigEditorDiffRevisionId !== null) {
            $rev = $revisions->firstWhere('id', $this->phpConfigEditorDiffRevisionId)
                ?? $this->lookupCurrentStreamRevision($editor, $this->phpConfigEditorDiffRevisionId);
            if ($rev === null) {
                return [null, null];
            }

            $current = ['path' => $this->phpConfigEditorPath, 'content' => $this->phpConfigEditorContent];

            return [
                app(ConfigRevisionDiffRegistry::class)
                    ->rendererFor($rev->kind)
                    ->render(is_array($rev->snapshot) ? $rev->snapshot : [], $current),
                __('Revision :ts → current editor', [
                    'ts' => optional($rev->created_at)->format('Y-m-d H:i'),
                ]),
            ];
        }

        return [null, null];
    }
}
