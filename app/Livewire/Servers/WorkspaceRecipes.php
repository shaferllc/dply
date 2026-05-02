<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Script;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\SshConnection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceRecipes extends Component
{
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use StreamsRemoteSshLivewire;

    /** Editor / form state. */
    public string $new_recipe_name = '';

    public string $new_recipe_script = '';

    public ?string $editing_recipe_id = null;

    public bool $showEditor = false;

    /** Library-browse modal state. */
    public bool $browseLibraryOpen = false;

    /** marketplace | organization */
    public string $libraryTab = 'marketplace';

    public string $librarySearch = '';

    /** Currently selected marketplace tag (empty string = "All"). */
    public string $libraryTagFilter = '';

    /**
     * Identifier of the entry currently shown in the preview pane.
     *  - marketplace items use the preset key
     *  - org scripts use the Script ULID
     */
    public ?string $libraryPreviewId = null;

    /** Run output state. */
    public ?string $command_output = null;

    public ?string $command_error = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    /* ------------------------------------------------------------------
     * Saved-command CRUD
     * ------------------------------------------------------------------ */

    public function startNewRecipe(): void
    {
        $this->resetRecipeEditor();
        $this->showEditor = true;
    }

    public function addRecipe(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_recipe_name' => 'required|string|max:160',
            'new_recipe_script' => 'required|string|max:32000',
        ]);

        if ($this->editing_recipe_id) {
            ServerRecipe::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->editing_recipe_id)
                ->firstOrFail()
                ->update([
                    'name' => $this->new_recipe_name,
                    'script' => $this->new_recipe_script,
                ]);

            $this->toastSuccess('Saved command updated.');
        } else {
            ServerRecipe::query()->create([
                'server_id' => $this->server->id,
                'user_id' => auth()->id(),
                'name' => $this->new_recipe_name,
                'script' => $this->new_recipe_script,
            ]);

            $this->toastSuccess('Saved command added.');
        }

        $this->resetRecipeEditor();
        $this->showEditor = false;
    }

    public function editRecipe(string $id): void
    {
        $this->authorize('update', $this->server);

        $recipe = ServerRecipe::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        $this->editing_recipe_id = (string) $recipe->id;
        $this->new_recipe_name = $recipe->name;
        $this->new_recipe_script = $recipe->script;
        $this->showEditor = true;
    }

    public function cancelEditingRecipe(): void
    {
        $this->resetRecipeEditor();
        $this->showEditor = false;
    }

    public function deleteRecipe(string $id): void
    {
        $this->authorize('update', $this->server);
        ServerRecipe::query()->where('server_id', $this->server->id)->whereKey($id)->delete();
        if ($this->editing_recipe_id === $id) {
            $this->resetRecipeEditor();
            $this->showEditor = false;
        }
        $this->toastSuccess('Saved command removed.');
    }

    public function useRecipeAsDeployCommand(string $id): void
    {
        $this->authorize('update', $this->server);

        $recipe = ServerRecipe::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        $this->server->update([
            'deploy_command' => trim($recipe->script) ?: null,
        ]);

        $this->toastSuccess('Saved command copied to deploy.');
    }

    public function runRecipe(string $id): void
    {
        $this->authorize('update', $this->server);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->command_error = 'Deployers cannot run server saved commands or arbitrary shell commands.';

            return;
        }
        $recipe = ServerRecipe::query()->where('server_id', $this->server->id)->findOrFail($id);
        $this->command_output = null;
        $this->command_error = null;
        try {
            $ssh = new SshConnection($this->server);
            $b64 = base64_encode($recipe->script);
            $remoteCmd = 'echo '.escapeshellarg($b64).' | base64 -d | /usr/bin/env bash 2>&1';
            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('Saved command').': '.$recipe->name,
                $this->server->ssh_user.'@'.$this->server->ip_address.'  '.$remoteCmd
            );
            $this->command_output = $ssh->execWithCallback(
                $remoteCmd,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk),
                900
            );
            $this->toastSuccess('Saved command ran. See command output below if shown.');
        } catch (\Throwable $e) {
            $this->command_error = $e->getMessage();
        }
    }

    /* ------------------------------------------------------------------
     * Library: browse / search / preview / import
     * ------------------------------------------------------------------ */

    public function openLibrary(): void
    {
        $this->authorize('update', $this->server);
        $this->browseLibraryOpen = true;
        $this->libraryPreviewId = null;
        $this->librarySearch = '';
        $this->libraryTagFilter = '';
        $this->dispatch('open-modal', 'browse-library-modal');
    }

    public function closeLibrary(): void
    {
        $this->browseLibraryOpen = false;
        $this->libraryPreviewId = null;
        $this->dispatch('close-modal', 'browse-library-modal');
    }

    public function setLibraryTab(string $tab): void
    {
        $this->libraryTab = $tab === 'organization' ? 'organization' : 'marketplace';
        $this->libraryPreviewId = null;
        $this->libraryTagFilter = '';
    }

    public function setLibraryTagFilter(string $tag): void
    {
        $this->libraryTagFilter = $this->libraryTagFilter === $tag ? '' : $tag;
        $this->libraryPreviewId = null;
    }

    public function previewLibraryItem(string $id): void
    {
        $this->libraryPreviewId = $id;
    }

    public function clearLibraryPreview(): void
    {
        $this->libraryPreviewId = null;
    }

    public function saveMarketplacePresetToServer(string $key): void
    {
        $this->authorize('update', $this->server);

        $presets = config('script_marketplace', []);
        $preset = $presets[$key] ?? null;
        if (! is_array($preset) || empty($preset['name']) || ! isset($preset['content'])) {
            $this->toastError(__('That marketplace preset is no longer available.'));

            return;
        }

        ServerRecipe::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'name' => (string) $preset['name'],
            'script' => (string) $preset['content'],
        ]);

        $this->toastSuccess(__('Saved “:name” to this server.', ['name' => $preset['name']]));
    }

    public function saveOrganizationScriptToServer(string $id): void
    {
        $this->authorize('update', $this->server);

        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            $this->toastError(__('Select an organization first.'));

            return;
        }

        $script = Script::query()
            ->where('organization_id', $organization->id)
            ->whereKey($id)
            ->first();

        if (! $script) {
            $this->toastError(__('That organization script could not be found.'));

            return;
        }

        ServerRecipe::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'name' => $script->name,
            'script' => $script->content,
        ]);

        $this->toastSuccess(__('Saved “:name” to this server.', ['name' => $script->name]));
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    protected function resetRecipeEditor(): void
    {
        $this->editing_recipe_id = null;
        $this->new_recipe_name = '';
        $this->new_recipe_script = '';
    }

    /**
     * @return array<int, array{id: string, name: string, run_as_user: ?string, content: string, summary: string, tags: array<int, string>}>
     */
    protected function marketplaceCatalog(): array
    {
        $tagMap = config('script_marketplace_tags', []);
        $items = [];
        foreach (config('script_marketplace', []) as $key => $preset) {
            if (! is_array($preset) || ! isset($preset['name'], $preset['content'])) {
                continue;
            }
            $tags = $tagMap[$key] ?? [];
            $items[] = [
                'id' => (string) $key,
                'name' => (string) $preset['name'],
                'run_as_user' => isset($preset['run_as_user']) && $preset['run_as_user'] !== ''
                    ? (string) $preset['run_as_user']
                    : null,
                'content' => (string) $preset['content'],
                'summary' => $this->summariseScript((string) $preset['content']),
                'tags' => is_array($tags) ? array_values(array_filter(array_map('strval', $tags))) : [],
            ];
        }

        return $items;
    }

    protected function summariseScript(string $content): string
    {
        $clean = trim(preg_replace('/^\s*#!.+\n?/m', '', $content) ?? $content);
        $firstLine = strtok($clean, "\n");

        return Str::limit(trim((string) $firstLine), 110, '…');
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['recipes']);
        $organization = auth()->user()?->currentOrganization();

        $marketplace = $this->marketplaceCatalog();
        $orgScripts = $organization
            ? Script::query()
                ->where('organization_id', $organization->id)
                ->orderBy('name')
                ->get(['id', 'name', 'content', 'run_as_user', 'source'])
                ->map(fn (Script $s) => [
                    'id' => (string) $s->id,
                    'name' => $s->name,
                    'run_as_user' => $s->run_as_user,
                    'content' => (string) $s->content,
                    'summary' => $this->summariseScript((string) $s->content),
                    'source' => $s->source,
                ])
                ->all()
            : [];

        $needle = Str::lower(trim($this->librarySearch));
        $tag = trim($this->libraryTagFilter);

        $matchesSearch = static function (array $row) use ($needle): bool {
            if ($needle === '') {
                return true;
            }

            return str_contains(Str::lower($row['name']), $needle)
                || str_contains(Str::lower($row['content']), $needle);
        };

        $marketplaceFiltered = array_values(array_filter(
            $marketplace,
            fn (array $row) => $matchesSearch($row)
                && ($tag === '' || in_array($tag, $row['tags'] ?? [], true))
        ));
        $orgFiltered = array_values(array_filter($orgScripts, $matchesSearch));

        $tagCounts = [];
        foreach ($marketplace as $row) {
            foreach ($row['tags'] ?? [] as $t) {
                $tagCounts[$t] = ($tagCounts[$t] ?? 0) + 1;
            }
        }
        ksort($tagCounts);
        $availableTags = [];
        foreach ($tagCounts as $name => $count) {
            $availableTags[] = ['name' => $name, 'count' => $count];
        }
        usort($availableTags, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        $previewItem = null;
        if ($this->libraryPreviewId !== null) {
            $haystack = $this->libraryTab === 'organization' ? $orgScripts : $marketplace;
            foreach ($haystack as $row) {
                if ($row['id'] === $this->libraryPreviewId) {
                    $previewItem = $row;
                    break;
                }
            }
        }

        return view('livewire.servers.workspace-recipes', [
            'marketplaceItems' => $marketplaceFiltered,
            'orgScriptItems' => $orgFiltered,
            'libraryTotals' => [
                'marketplace' => count($marketplace),
                'organization' => count($orgScripts),
            ],
            'libraryAvailableTags' => $availableTags,
            'libraryPreview' => $previewItem,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
