<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\AuthorsBackupDestinations;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\BackupConfiguration;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class BackupConfigurations extends Component
{
    use AuthorsBackupDestinations;
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    /** @var array<string, mixed> */
    public array $createForm = [];

    /** @var array<string, mixed> */
    public array $editForm = [];

    public ?string $editing_id = null;

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', BackupConfiguration::class);
        $this->createForm = $this->emptyDestinationForm();
        $this->editForm = $this->emptyDestinationForm();
    }

    public function createConfiguration(): void
    {
        $this->authorize('create', BackupConfiguration::class);
        $this->resetErrorBag();

        $org = $this->requireCurrentOrganization();

        $this->validate($this->destinationFormRules('createForm', $this->createForm['provider'] ?? ''));
        $this->validateDestinationFormExtras('createForm', $this->createForm);

        $org->backupConfigurations()->create([
            'name' => $this->createForm['name'],
            'provider' => $this->createForm['provider'],
            'config' => $this->extractDestinationConfig($this->createForm),
            'created_by_user_id' => Auth::id(),
        ]);

        $this->createForm = $this->emptyDestinationForm();
        $this->toastSuccess(__('Backup destination saved.'));
    }

    public function startEdit(string $id): void
    {
        $config = BackupConfiguration::query()->findOrFail($id);

        $this->authorize('update', $config);

        $this->editing_id = $config->id;
        $this->editForm = $this->emptyDestinationForm();
        $this->editForm['name'] = $config->name;
        $this->editForm['provider'] = $config->provider;
        $this->hydrateDestinationFormFromConfig($this->editForm, $config->provider, $config->config);
    }

    public function cancelEdit(): void
    {
        $this->editing_id = null;
        $this->editForm = $this->emptyDestinationForm();
    }

    public function updateConfiguration(): void
    {
        if ($this->editing_id === null) {
            return;
        }

        $model = BackupConfiguration::query()->findOrFail($this->editing_id);

        $this->authorize('update', $model);

        $this->resetErrorBag();
        $this->validate($this->destinationFormRules('editForm', $this->editForm['provider'] ?? ''));
        $this->validateDestinationFormExtras('editForm', $this->editForm);

        $model->update([
            'name' => $this->editForm['name'],
            'provider' => $this->editForm['provider'],
            'config' => $this->extractDestinationConfig($this->editForm),
        ]);

        $this->cancelEdit();
        $this->toastSuccess(__('Backup destination updated.'));
    }

    public function deleteConfiguration(string $id): void
    {
        $config = BackupConfiguration::query()->findOrFail($id);

        $this->authorize('delete', $config);

        $config->delete();

        if ($this->editing_id === $id) {
            $this->cancelEdit();
        }

        $this->toastSuccess(__('Backup destination removed.'));
    }

    public function render(): View
    {
        $org = Auth::user()?->currentOrganization();

        $configurations = collect();
        if ($org !== null) {
            $query = $org->backupConfigurations()->orderBy('name');
            $term = trim($this->search);
            if ($term !== '') {
                $query->where('name', 'like', '%'.$term.'%');
            }
            $configurations = $query->get();
        }

        return view('livewire.settings.backup-configurations', [
            'configurations' => $configurations,
            'organization' => $org,
        ]);
    }

    /**
     * Guard against the "no current org" edge case (rare — every authenticated
     * user lands here with an active org via SetCurrentOrganization middleware,
     * but a stale session or a brand-new account between org-create and
     * login-flush could land here without one). Throwing the validation error
     * surfaces the right message in the UI rather than 500ing on a null call.
     */
    private function requireCurrentOrganization(): Organization
    {
        $org = Auth::user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Pick an organization before adding a backup destination.'));
            throw ValidationException::withMessages([
                'createForm.name' => __('No active organization.'),
            ]);
        }

        return $org;
    }
}
