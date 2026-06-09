<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerBlueprint;
use App\Services\Servers\Blueprint\ServerBlueprintCapture;
use App\Services\Servers\Blueprint\ServerBlueprintSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

/**
 * Capture this VM as an org golden-server blueprint and manage saved snapshots.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceBlueprint extends Component
{
    use RendersWorkspacePlaceholder;
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.server_blueprint';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    public string $blueprint_name = '';

    public string $deleteBlueprintId = '';

    public function mount(Server $server): void
    {
        if (! Feature::active('workspace.server_blueprint')) {
            if (workspace_server_blueprint_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);
                abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);

                return;
            }

            abort(404);
        }

        $this->comingSoonPreview = false;
        $this->bootWorkspace($server);

        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);

        $this->blueprint_name = $server->name.' blueprint';
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

    public function saveBlueprint(ServerBlueprintCapture $capture): void
    {
        $this->authorize('update', $this->server);

        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);
        abort_if((string) $org->id !== (string) $this->server->organization_id, 404);

        $max = (int) config('server_blueprint.ui.max_org_blueprints', 20);
        $currentCount = ServerBlueprint::query()
            ->where('organization_id', $org->id)
            ->count();

        if ($currentCount >= $max) {
            $this->toastError(__('Your organization already has the maximum of :max blueprints. Delete one before saving another.', ['max' => $max]));

            return;
        }

        $validated = $this->validate([
            'blueprint_name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('server_blueprints', 'name')->where('organization_id', $org->id),
            ],
        ]);

        ServerBlueprint::query()->create([
            'organization_id' => $org->id,
            'source_server_id' => $this->server->id,
            'created_by_user_id' => auth()->id(),
            'name' => $validated['blueprint_name'],
            'snapshot' => $capture->fromServer($this->server),
        ]);

        $this->toastSuccess(__('Blueprint saved. New servers can pick it in the create wizard.'));
        $this->blueprint_name = $this->server->name.' blueprint';
    }

    public function openDeleteModal(string $blueprintId): void
    {
        $this->authorize('update', $this->server);

        $this->deleteBlueprintId = $blueprintId;
        $this->dispatch('open-modal', 'delete-blueprint-confirmation');
    }

    public function closeDeleteModal(): void
    {
        $this->deleteBlueprintId = '';
        $this->dispatch('close-modal', 'delete-blueprint-confirmation');
    }

    public function deleteBlueprint(): void
    {
        $this->authorize('update', $this->server);

        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        if ($this->deleteBlueprintId === '') {
            return;
        }

        ServerBlueprint::query()
            ->where('organization_id', $org->id)
            ->whereKey($this->deleteBlueprintId)
            ->delete();

        $this->closeDeleteModal();
        $this->toastSuccess(__('Blueprint deleted.'));
    }

    public function render(
        ServerBlueprintCapture $capture,
        ServerBlueprintSummary $summary,
    ): View {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-blueprint-preview');
        }

        $this->server->refresh();

        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        /** @var Collection<int, ServerBlueprint> $orgBlueprints */
        $orgBlueprints = ServerBlueprint::query()
            ->where('organization_id', $org->id)
            ->with('sourceServer:id,name')
            ->orderByDesc('updated_at')
            ->get();

        $previewSnapshot = $capture->fromServer($this->server);

        return view('livewire.servers.workspace-blueprint', [
            'previewSnapshot' => $previewSnapshot,
            'previewSummary' => $summary->tagline($previewSnapshot),
            'previewExtras' => $summary->extras($previewSnapshot),
            'orgBlueprints' => $orgBlueprints,
            'summary' => $summary,
            'maxBlueprints' => (int) config('server_blueprint.ui.max_org_blueprints', 20),
        ]);
    }
}
