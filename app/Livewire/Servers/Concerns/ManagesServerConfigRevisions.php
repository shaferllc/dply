<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RunServerConfigOpJob;
use App\Models\ConfigRevision;
use App\Models\ConsoleAction;
use App\Services\ConfigRevisions\Diff\ConfigRevisionDiffRegistry;
use App\Services\ConfigRevisions\Diff\PhpFileDiffRenderer;
use App\Services\Servers\ServerConfigFileCatalog;
use App\Services\Servers\ServerConfigFileEditor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/**
 * ConfigRevision diff + rollback for the unified server configuration editor.
 */
trait ManagesServerConfigRevisions
{
    /** Contents last loaded from disk — used for save diff + baseline capture. */
    public string $config_original_contents = '';

    public ?string $pending_write_console_id = null;

    public ?string $pending_validate_console_id = null;

    public bool $configSaveDiffOpen = false;

    public bool $configSaveConfirmOpen = false;

    public ?string $configDiffRevisionId = null;

    public bool $configCompareMode = false;

    public ?string $configCompareA = null;

    public ?string $configCompareB = null;

    public int $configRevisionsLimit = 50;

    public bool $configDriftDetected = false;

    public ?string $configCurrentRevisionId = null;

    public function configRevisionsEnabled(): bool
    {
        return Feature::active('workspace.webserver_config_diff');
    }

    public function openConfigSaveDiff(): void
    {
        if ($this->config_selected_path === null) {
            return;
        }

        $this->configSaveDiffOpen = true;
    }

    public function closeConfigSaveDiff(): void
    {
        $this->configSaveDiffOpen = false;
        $this->configSaveConfirmOpen = false;
    }

    public function openConfigSaveConfirm(): void
    {
        if ($this->config_selected_path === null) {
            return;
        }

        if ($this->config_contents === $this->config_original_contents) {
            $this->toastError(__('No changes to save.'));

            return;
        }

        $this->configSaveDiffOpen = true;
        $this->configSaveConfirmOpen = true;
    }

    public function showConfigRevisionDiff(string $revisionId): void
    {
        $this->authorize('view', $this->server);
        $this->configDiffRevisionId = $revisionId;
        $this->configSaveDiffOpen = false;
        $this->configSaveConfirmOpen = false;
    }

    public function closeConfigRevisionDiff(): void
    {
        $this->configDiffRevisionId = null;
        $this->configCompareA = null;
        $this->configCompareB = null;
    }

    public function toggleConfigCompareMode(): void
    {
        $this->configCompareMode = ! $this->configCompareMode;
        $this->configCompareA = null;
        $this->configCompareB = null;
        $this->configDiffRevisionId = null;
    }

    public function selectConfigRevisionForCompare(string $revisionId): void
    {
        $this->authorize('view', $this->server);

        if ($this->configCompareA === null) {
            $this->configCompareA = $revisionId;

            return;
        }

        if ($this->configCompareA === $revisionId) {
            $this->configCompareA = null;

            return;
        }

        $this->configCompareB = $revisionId;
    }

    public function loadConfigRevision(string $revisionId): void
    {
        $this->authorize('update', $this->server);

        $editor = app(ServerConfigFileEditor::class);
        $rev = $this->lookupConfigRevision($editor, $revisionId);
        if ($rev === null) {
            $this->toastError(__('Revision not found.'));

            return;
        }

        $snapshot = is_array($rev->snapshot) ? $rev->snapshot : [];
        $this->config_contents = is_string($snapshot['content'] ?? null) ? $snapshot['content'] : '';
        $this->stashConfigDraft((string) $this->config_selected_path, $this->config_contents);
        $this->configDiffRevisionId = null;
        $this->toastSuccess(__('Revision loaded into editor.'));
    }

    public function rollbackConfigRevision(string $revisionId): void
    {
        $this->authorize('update', $this->server);

        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }

        $editor = app(ServerConfigFileEditor::class);
        $rev = $this->lookupConfigRevision($editor, $revisionId);
        if ($rev === null) {
            $this->toastError(__('Revision not found.'));

            return;
        }

        $snapshot = is_array($rev->snapshot) ? $rev->snapshot : [];
        $content = is_string($snapshot['content'] ?? null) ? $snapshot['content'] : '';
        $engine = $this->resolvedConfigEngine();

        $consoleId = $this->seedConfigurationConsoleAction(
            (string) __('Rollback config: :path', ['path' => basename((string) $this->config_selected_path)]),
        );

        $this->pending_write_console_id = $consoleId;

        RunServerConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'write',
            (string) $this->config_selected_path,
            $content,
            '',
            auth()->id(),
            true,
            __('Rollback to revision :time', [
                'time' => optional($rev->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? $rev->id,
            ]),
            $engine,
        );

        $this->toastSuccess(__('Rollback queued — progress shows in the banner above.'));
    }

    public function showOlderConfigRevisions(): void
    {
        $this->configRevisionsLimit += 50;
    }

    protected function lookupConfigRevision(ServerConfigFileEditor $editor, string $revisionId): ?ConfigRevision
    {
        if ($this->config_selected_path === null) {
            return null;
        }

        return $editor->lookupRevision(
            $this->server,
            (string) $this->config_selected_path,
            $revisionId,
        );
    }

    protected function refreshConfigRevisionState(): void
    {
        $this->configDriftDetected = false;
        $this->configCurrentRevisionId = null;

        if (! $this->configRevisionsEnabled() || $this->config_selected_path === null) {
            return;
        }

        $editor = app(ServerConfigFileEditor::class);
        $streamKey = $editor->streamKey($this->server, (string) $this->config_selected_path);
        $latest = ConfigRevision::query()->forStream($streamKey)->first();

        if ($latest === null) {
            return;
        }

        $engine = $this->resolvedConfigEngine();
        $liveChecksum = $editor->snapshotChecksumFor(
            (string) $this->config_selected_path,
            $this->config_original_contents,
            $engine,
        );

        if ($latest->checksum === $liveChecksum) {
            $this->configCurrentRevisionId = $latest->id;
        } else {
            $this->configDriftDetected = true;
        }
    }

    /**
     * @return array{
     *     configRevisions: Collection<int, ConfigRevision>,
     *     configSaveDiffText: ?string,
     *     configDiffText: ?string,
     *     configDiffHeader: ?string,
     * }
     */
    protected function configRevisionViewData(): array
    {
        $empty = [
            'configRevisions' => collect(),
            'configSaveDiffText' => null,
            'configDiffText' => null,
            'configDiffHeader' => null,
        ];

        if (! $this->configRevisionsEnabled() || $this->config_selected_path === null) {
            return $empty;
        }

        $editor = app(ServerConfigFileEditor::class);
        $streamKey = $editor->streamKey($this->server, (string) $this->config_selected_path);
        $engine = $this->resolvedConfigEngine();

        /** @var Collection<int, ConfigRevision> $revisions */
        $revisions = ConfigRevision::query()
            ->forStream($streamKey)
            ->with('user:id,name')
            ->limit($this->configRevisionsLimit)
            ->get();

        $saveDiffText = null;
        if (($this->configSaveDiffOpen || $this->configSaveConfirmOpen)
            && $this->config_contents !== $this->config_original_contents
        ) {
            $saveDiffText = PhpFileDiffRenderer::renderUnifiedDiff(
                $this->config_original_contents,
                $this->config_contents,
            );
        }

        [$diffText, $diffHeader] = $this->buildConfigDiffForView($editor, $revisions, $engine);

        return [
            'configRevisions' => $revisions,
            'configSaveDiffText' => $saveDiffText,
            'configDiffText' => $diffText,
            'configDiffHeader' => $diffHeader,
        ];
    }

    /**
     * @param  Collection<int, ConfigRevision>  $revisions
     * @return array{0: ?string, 1: ?string}
     */
    protected function buildConfigDiffForView(
        ServerConfigFileEditor $editor,
        Collection $revisions,
        ?string $engine,
    ): array {
        $registry = app(ConfigRevisionDiffRegistry::class);
        $path = (string) $this->config_selected_path;

        if ($this->configCompareMode
            && $this->configCompareA !== null
            && $this->configCompareB !== null
        ) {
            $a = $revisions->firstWhere('id', $this->configCompareA)
                ?? $editor->lookupRevision($this->server, $path, $this->configCompareA);
            $b = $revisions->firstWhere('id', $this->configCompareB)
                ?? $editor->lookupRevision($this->server, $path, $this->configCompareB);

            if ($a !== null && $b !== null) {
                return [
                    $registry->rendererFor(ServerConfigFileEditor::KIND)->render(
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

        if ($this->configDiffRevisionId !== null) {
            $rev = $revisions->firstWhere('id', $this->configDiffRevisionId)
                ?? $editor->lookupRevision($this->server, $path, $this->configDiffRevisionId);

            if ($rev !== null) {
                return [
                    $registry->rendererFor(ServerConfigFileEditor::KIND)->render(
                        is_array($rev->snapshot) ? $rev->snapshot : [],
                        $editor->snapshotFor($path, $this->config_contents, $engine),
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
                RunServerConfigOpJob::writeResultCacheKey($this->pending_write_console_id),
            );
            if (is_array($cached)) {
                $this->config_contents = (string) ($cached['contents'] ?? $this->config_contents);
                $this->config_original_contents = $this->config_contents;
                $this->stashConfigDraft((string) $this->config_selected_path, $this->config_contents);
                $this->refreshConfigRevisionState();
                $this->refreshRemoteConfigBackups();
                $this->closeConfigSaveDiff();
            }
        } elseif ($row->status === ConsoleAction::STATUS_FAILED) {
            $this->toastError(__('Save failed — see banner output.'));
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
            RunServerConfigOpJob::validateResultCacheKey($this->pending_validate_console_id),
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

    protected function resolvedConfigEngine(): ?string
    {
        if ($this->config_selected_path === null) {
            return null;
        }

        return app(ServerConfigFileCatalog::class)->webserverEngineForPath((string) $this->config_selected_path);
    }
}
