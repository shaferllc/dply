<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RunWebserverConfigOpJob;
use App\Models\ConfigRevision;
use App\Models\ConsoleAction;
use App\Services\ConfigRevisions\Diff\ConfigRevisionDiffRegistry;
use App\Services\ConfigRevisions\Diff\PhpFileDiffRenderer;
use App\Services\Servers\ServerWebserverConfigEditor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/**
 * ConfigRevision diff + rollback for the server webserver config editor.
 */
trait ManagesWebserverConfigRevisions
{
    /** Contents last loaded from disk — used for save diff + baseline capture. */
    public string $config_original_contents = '';

    public ?string $pending_write_console_id = null;

    public ?string $pending_validate_console_id = null;

    public bool $webserverConfigSaveDiffOpen = false;

    public ?string $webserverConfigDiffRevisionId = null;

    public bool $webserverConfigCompareMode = false;

    public ?string $webserverConfigCompareA = null;

    public ?string $webserverConfigCompareB = null;

    public int $webserverConfigRevisionsLimit = 50;

    public bool $webserverConfigDriftDetected = false;

    public ?string $webserverConfigCurrentRevisionId = null;

    public function webserverConfigRevisionsEnabled(): bool
    {
        return Feature::active('workspace.webserver_config_diff');
    }

    public function openWebserverConfigSaveDiff(): void
    {
        if ($this->config_selected_path === null) {
            return;
        }

        $this->webserverConfigSaveDiffOpen = true;
    }

    public function closeWebserverConfigSaveDiff(): void
    {
        $this->webserverConfigSaveDiffOpen = false;
    }

    public function showWebserverConfigRevisionDiff(string $revisionId): void
    {
        $this->authorize('view', $this->server);
        $this->webserverConfigDiffRevisionId = $revisionId;
        $this->webserverConfigSaveDiffOpen = false;
    }

    public function closeWebserverConfigRevisionDiff(): void
    {
        $this->webserverConfigDiffRevisionId = null;
        $this->webserverConfigCompareA = null;
        $this->webserverConfigCompareB = null;
    }

    public function toggleWebserverConfigCompareMode(): void
    {
        $this->webserverConfigCompareMode = ! $this->webserverConfigCompareMode;
        $this->webserverConfigCompareA = null;
        $this->webserverConfigCompareB = null;
        $this->webserverConfigDiffRevisionId = null;
    }

    public function selectWebserverConfigRevisionForCompare(string $revisionId): void
    {
        $this->authorize('view', $this->server);

        if ($this->webserverConfigCompareA === null) {
            $this->webserverConfigCompareA = $revisionId;

            return;
        }

        if ($this->webserverConfigCompareA === $revisionId) {
            $this->webserverConfigCompareA = null;

            return;
        }

        $this->webserverConfigCompareB = $revisionId;
    }

    public function loadWebserverConfigRevision(string $revisionId): void
    {
        $this->authorize('update', $this->server);

        $editor = app(ServerWebserverConfigEditor::class);
        $rev = $this->lookupWebserverConfigRevision($editor, $revisionId);
        if ($rev === null) {
            $this->toastError(__('Revision not found.'));

            return;
        }

        $snapshot = is_array($rev->snapshot) ? $rev->snapshot : [];
        $this->config_contents = is_string($snapshot['content'] ?? null) ? $snapshot['content'] : '';
        $this->webserverConfigDiffRevisionId = null;
        $this->toastSuccess(__('Revision loaded into editor.'));
    }

    public function rollbackWebserverConfigRevision(string $revisionId): void
    {
        $this->authorize('update', $this->server);

        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }

        $editor = app(ServerWebserverConfigEditor::class);
        $rev = $this->lookupWebserverConfigRevision($editor, $revisionId);
        if ($rev === null) {
            $this->toastError(__('Revision not found.'));

            return;
        }

        $snapshot = is_array($rev->snapshot) ? $rev->snapshot : [];
        $content = is_string($snapshot['content'] ?? null) ? $snapshot['content'] : '';

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Rollback webserver config: :path', ['path' => basename((string) $this->config_selected_path)]),
        );

        $this->pending_write_console_id = $consoleId;

        RunWebserverConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'write',
            $this->workspace_tab,
            (string) $this->config_selected_path,
            $content,
            '',
            auth()->id(),
            true,
            __('Rollback to revision :time', [
                'time' => optional($rev->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? $rev->id,
            ]),
        );

        $this->toastSuccess(__('Rollback queued — progress shows in the banner above.'));
    }

    public function showOlderWebserverConfigRevisions(): void
    {
        $this->webserverConfigRevisionsLimit += 50;
    }

    protected function lookupWebserverConfigRevision(ServerWebserverConfigEditor $editor, string $revisionId): ?ConfigRevision
    {
        if ($this->config_selected_path === null) {
            return null;
        }

        return $editor->lookupRevision(
            $this->server,
            $this->workspace_tab,
            $this->config_selected_path,
            $revisionId,
        );
    }

    protected function refreshWebserverConfigRevisionState(): void
    {
        $this->webserverConfigDriftDetected = false;
        $this->webserverConfigCurrentRevisionId = null;

        if (! $this->webserverConfigRevisionsEnabled()
            || $this->config_selected_path === null
        ) {
            return;
        }

        $editor = app(ServerWebserverConfigEditor::class);
        $streamKey = $editor->streamKey($this->server, $this->workspace_tab, $this->config_selected_path);
        $latest = ConfigRevision::query()->forStream($streamKey)->first();

        if ($latest === null) {
            return;
        }

        $liveChecksum = $editor->snapshotChecksumFor(
            $this->workspace_tab,
            $this->config_selected_path,
            $this->config_original_contents,
        );

        if ($latest->checksum === $liveChecksum) {
            $this->webserverConfigCurrentRevisionId = $latest->id;
        } else {
            $this->webserverConfigDriftDetected = true;
        }
    }

    /**
     * @return array{
     *     webserverConfigRevisions: Collection<int, ConfigRevision>,
     *     webserverConfigSaveDiffText: ?string,
     *     webserverConfigDiffText: ?string,
     *     webserverConfigDiffHeader: ?string,
     * }
     */
    protected function webserverConfigRevisionViewData(): array
    {
        $empty = [
            'webserverConfigRevisions' => collect(),
            'webserverConfigSaveDiffText' => null,
            'webserverConfigDiffText' => null,
            'webserverConfigDiffHeader' => null,
        ];

        if (! $this->webserverConfigRevisionsEnabled() || $this->config_selected_path === null) {
            return $empty;
        }

        $editor = app(ServerWebserverConfigEditor::class);
        $streamKey = $editor->streamKey($this->server, $this->workspace_tab, $this->config_selected_path);

        /** @var Collection<int, ConfigRevision> $revisions */
        $revisions = ConfigRevision::query()
            ->forStream($streamKey)
            ->with('user:id,name')
            ->limit($this->webserverConfigRevisionsLimit)
            ->get();

        $saveDiffText = null;
        if ($this->webserverConfigSaveDiffOpen && $this->config_contents !== $this->config_original_contents) {
            $saveDiffText = PhpFileDiffRenderer::renderUnifiedDiff(
                $this->config_original_contents,
                $this->config_contents,
            );
        }

        [$diffText, $diffHeader] = $this->buildWebserverConfigDiffForView($editor, $revisions);

        return [
            'webserverConfigRevisions' => $revisions,
            'webserverConfigSaveDiffText' => $saveDiffText,
            'webserverConfigDiffText' => $diffText,
            'webserverConfigDiffHeader' => $diffHeader,
        ];
    }

    /**
     * @param  Collection<int, ConfigRevision>  $revisions
     * @return array{0: ?string, 1: ?string}
     */
    protected function buildWebserverConfigDiffForView(ServerWebserverConfigEditor $editor, Collection $revisions): array
    {
        $registry = app(ConfigRevisionDiffRegistry::class);

        if ($this->webserverConfigCompareMode
            && $this->webserverConfigCompareA !== null
            && $this->webserverConfigCompareB !== null
        ) {
            $a = $revisions->firstWhere('id', $this->webserverConfigCompareA)
                ?? $editor->lookupRevision($this->server, $this->workspace_tab, (string) $this->config_selected_path, $this->webserverConfigCompareA);
            $b = $revisions->firstWhere('id', $this->webserverConfigCompareB)
                ?? $editor->lookupRevision($this->server, $this->workspace_tab, (string) $this->config_selected_path, $this->webserverConfigCompareB);

            if ($a !== null && $b !== null) {
                return [
                    $registry->rendererFor(ServerWebserverConfigEditor::KIND)->render(
                        is_array($a->snapshot) ? $a->snapshot : [],
                        is_array($b->snapshot) ? $b->snapshot : [],
                    ),
                    (string) __('Comparing revisions :a → :b', [
                        'a' => optional($a->created_at)->format('Y-m-d H:i') ?? $a->id,
                        'b' => optional($b->created_at)->format('Y-m-d H:i') ?? $b->id,
                    ]),
                ];
            }
        }

        if ($this->webserverConfigDiffRevisionId !== null) {
            $rev = $revisions->firstWhere('id', $this->webserverConfigDiffRevisionId)
                ?? $editor->lookupRevision(
                    $this->server,
                    $this->workspace_tab,
                    (string) $this->config_selected_path,
                    $this->webserverConfigDiffRevisionId,
                );

            if ($rev !== null) {
                return [
                    $registry->rendererFor(ServerWebserverConfigEditor::KIND)->render(
                        is_array($rev->snapshot) ? $rev->snapshot : [],
                        $editor->snapshotFor($this->workspace_tab, (string) $this->config_selected_path, $this->config_contents),
                    ),
                    (string) __('Revision :time vs editor buffer', [
                        'time' => optional($rev->created_at)->format('Y-m-d H:i') ?? $rev->id,
                    ]),
                ];
            }
        }

        return [null, null];
    }

    protected function pickupQueuedConfigWrite(): void
    {
        if ($this->pending_write_console_id === null) {
            return;
        }

        $row = ConsoleAction::query()->find($this->pending_write_console_id);
        if ($row === null) {
            $this->pending_write_console_id = null;

            return;
        }

        if (! in_array($row->status, [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], true)) {
            return;
        }

        if ($row->status === ConsoleAction::STATUS_COMPLETED) {
            $cached = Cache::pull(
                RunWebserverConfigOpJob::writeResultCacheKey($this->pending_write_console_id),
            );
            if (is_array($cached)) {
                $this->config_contents = (string) ($cached['contents'] ?? $this->config_contents);
                $this->config_original_contents = $this->config_contents;
                $this->refreshWebserverConfigRevisionState();
                $this->refreshConfigBackups();
            }
        }

        $this->pending_write_console_id = null;
    }

    protected function pickupQueuedConfigValidate(): void
    {
        if ($this->pending_validate_console_id === null) {
            return;
        }

        $row = ConsoleAction::query()->find($this->pending_validate_console_id);
        if ($row === null) {
            $this->pending_validate_console_id = null;

            return;
        }

        if (! in_array($row->status, [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], true)) {
            return;
        }

        $cached = Cache::pull(
            RunWebserverConfigOpJob::validateResultCacheKey($this->pending_validate_console_id),
        );

        if (is_array($cached)) {
            $this->config_validate_output = (string) ($cached['output'] ?? '');
            $this->config_validate_ok = (bool) ($cached['ok'] ?? false);
        } elseif ($row->status === ConsoleAction::STATUS_FAILED) {
            $this->config_validate_output = (string) ($row->error ?? __('Validation failed.'));
            $this->config_validate_ok = false;
        }

        if ($this->config_validate_ok) {
            $this->toastSuccess(__('Config validated.'));
        } elseif ($this->config_validate_output !== null) {
            $this->toastError(__('Config validation reported problems — see output below.'));
        }

        $this->pending_validate_console_id = null;
    }
}
