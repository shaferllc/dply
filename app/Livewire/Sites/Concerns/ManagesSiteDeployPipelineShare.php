<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineJsonExporter;
use App\Support\Sites\DeployPipelineJsonImporter;
use App\Support\Sites\DeployPipelineScriptExporter;
use App\Support\Sites\DeployPipelineStarterApplier;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesSiteDeployPipelineShare
{
    use WithFileUploads;

    public string $quick_commands_text = '';

    public string $quick_commands_phase = SiteDeployStep::PHASE_BUILD;

    /** @var TemporaryUploadedFile|null */
    public $pipeline_import_file = null;

    public bool $show_import_pipeline_modal = false;

    public string $pending_import_json = '';

    public bool $import_apply_rollout = false;

    public bool $import_create_new_pipeline = false;

    public string $import_new_pipeline_name = '';

    /** @var list<string> */
    public array $import_preview_lines = [];

    public function appendQuickCommands(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'quick_commands_phase' => ['required', 'in:'.SiteDeployStep::PHASE_BUILD.','.SiteDeployStep::PHASE_RELEASE],
            'quick_commands_text' => 'required|string|max:200000',
        ]);

        try {
            $lines = $this->parseQuickCommandLines($this->quick_commands_text);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if ($lines === []) {
            $this->toastError(__('Enter at least one command.'));

            return;
        }

        $pipeline = $this->editingDeployPipeline();
        $manager = app(SiteDeployPipelineManager::class);
        $added = 0;

        foreach ($lines as $command) {
            $manager->addStep(
                $pipeline,
                SiteDeployStep::TYPE_CUSTOM,
                $command,
                900,
                null,
                $this->quick_commands_phase,
            );
            $added++;
        }

        $this->quick_commands_text = '';
        $this->syncEditingPipelineSnapshot();
        $this->toastSuccess(__(':count command(s) added to :phase.', [
            'count' => $added,
            'phase' => $this->quick_commands_phase === SiteDeployStep::PHASE_RELEASE ? __('Release') : __('Build'),
        ]));
    }

    public function downloadPipelineJson(): StreamedResponse
    {
        $this->authorize('view', $this->site);
        $pipeline = $this->editingDeployPipeline();
        $json = app(DeployPipelineJsonExporter::class)->export($this->site, $pipeline);
        $filename = sprintf(
            '%s-pipeline-%s.json',
            Str::slug($this->site->name),
            now()->format('Ymd-His'),
        );

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    public function downloadPipelineBashFull(): StreamedResponse
    {
        $this->authorize('view', $this->site);
        $bash = app(DeployPipelineScriptExporter::class)->toFullBash($this->editingDeployPipeline());
        $filename = sprintf('%s-pipeline-full-%s.sh', Str::slug($this->site->name), now()->format('Ymd-His'));

        return response()->streamDownload(
            static function () use ($bash): void {
                echo $bash;
            },
            $filename,
            ['Content-Type' => 'text/x-shellscript'],
        );
    }

    public function downloadPipelineBashCommands(): StreamedResponse
    {
        $this->authorize('view', $this->site);
        $bash = app(DeployPipelineScriptExporter::class)->toCommandsOnly($this->editingDeployPipeline());
        $filename = sprintf('%s-pipeline-commands-%s.sh', Str::slug($this->site->name), now()->format('Ymd-His'));

        return response()->streamDownload(
            static function () use ($bash): void {
                echo $bash;
            },
            $filename,
            ['Content-Type' => 'text/x-shellscript'],
        );
    }

    public function updatedPipelineImportFile(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'pipeline_import_file' => 'required|file|max:512',
        ]);

        try {
            $json = file_get_contents($this->pipeline_import_file->getRealPath());
            if ($json === false) {
                throw new \RuntimeException(__('Could not read the file.'));
            }
            app(DeployPipelineJsonImporter::class)->parse($json);
            $this->pending_import_json = $json;
        } catch (\Throwable $e) {
            $this->reset('pipeline_import_file');
            $this->toastError($e instanceof \InvalidArgumentException ? $e->getMessage() : __('Invalid pipeline JSON file.'));

            return;
        }

        $pipeline = $this->editingDeployPipeline();
        $applier = app(DeployPipelineStarterApplier::class);

        if ($applier->pipelineIsEmpty($pipeline)) {
            $this->confirmImportPipelineJson();

            return;
        }

        $this->import_preview_lines = app(DeployPipelineJsonImporter::class)->previewLines(
            $this->pending_import_json,
            $this->import_apply_rollout,
        );
        $this->import_new_pipeline_name = __('Imported pipeline');
        $this->show_import_pipeline_modal = true;
    }

    public function updatedImportApplyRollout(): void
    {
        if ($this->pending_import_json === '') {
            return;
        }

        $this->import_preview_lines = app(DeployPipelineJsonImporter::class)->previewLines(
            $this->pending_import_json,
            $this->import_apply_rollout,
        );
    }

    public function closeImportPipelineModal(): void
    {
        $this->show_import_pipeline_modal = false;
        $this->pending_import_json = '';
        $this->import_preview_lines = [];
        $this->import_apply_rollout = false;
        $this->import_create_new_pipeline = false;
        $this->import_new_pipeline_name = '';
        $this->reset('pipeline_import_file');
    }

    public function confirmImportPipelineJson(): void
    {
        $this->authorize('update', $this->site);
        if ($this->pending_import_json === '') {
            return;
        }

        if ($this->import_create_new_pipeline) {
            $this->validate([
                'import_new_pipeline_name' => 'required|string|max:120',
            ]);
        }

        try {
            $manager = app(SiteDeployPipelineManager::class);
            $activate = $this->import_create_new_pipeline;

            if ($this->import_create_new_pipeline) {
                $pipeline = $manager->createPipeline($this->site, $this->import_new_pipeline_name);
                $this->editingPipelineId = (string) $pipeline->id;
            } else {
                $pipeline = $this->editingDeployPipeline();
            }

            $result = app(DeployPipelineJsonImporter::class)->apply(
                $this->site,
                $pipeline,
                $this->pending_import_json,
                $this->import_apply_rollout,
            );

            if ($activate) {
                $manager->activatePipeline($this->site, $pipeline->fresh());
            }
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site->refresh();
        $this->syncFormFromSite();
        $this->syncEditingPipelineBranches();
        $this->closeImportPipelineModal();

        $this->toastSuccess(__(
            'Pipeline imported (:steps steps, :hooks hooks).',
            ['steps' => $result['steps'], 'hooks' => $result['hooks']],
        ));
    }

    /**
     * @return list<string>
     */
    protected function parseQuickCommandLines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (strlen($line) > 4000) {
                throw new \InvalidArgumentException(__('Each command must be 4000 characters or fewer.'));
            }
            $lines[] = $line;
            if (count($lines) >= 50) {
                break;
            }
        }

        return $lines;
    }
}
