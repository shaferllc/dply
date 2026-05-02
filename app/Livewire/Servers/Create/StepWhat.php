<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Livewire\Servers\Concerns\ServerCreateActions;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step 3 of the create-server wizard. "What it runs":
 * install profile + server role + stack details (webserver / PHP / DB / cache).
 *
 * Auto-skipped (in mount()) for the custom + Docker host combination.
 */
#[Layout('layouts.app')]
class StepWhat extends Component
{
    use InteractsWithServerCreateDraft;
    use ServerCreateActions;

    public ServerCreateForm $form;

    public function mount(): mixed
    {
        $this->authorize('create', Server::class);

        if ($redirect = $this->enforceDraftGate()) {
            return $redirect;
        }

        $this->hydrateFormFromDraft($this->form, $this->currentDraft());

        if ($this->form->mode === 'custom' && $this->form->custom_host_kind === 'docker') {
            $this->saveDraftFromForm($this->form, advanceTo: 4);

            return $this->redirect(route(self::routeNameForStep(4)), navigate: true);
        }

        return null;
    }

    public function previous(): mixed
    {
        $this->saveDraftFromForm($this->form);

        return $this->redirect(route(self::routeNameForStep(2)), navigate: true);
    }

    public function next(): mixed
    {
        $this->authorize('create', Server::class);

        $this->validate([
            'form.install_profile' => ['required', 'string'],
            'form.server_role' => ['required', 'string'],
            'form.webserver' => ['required', 'string'],
            'form.php_version' => ['required', 'string'],
            'form.database' => ['required', 'string'],
            'form.cache_service' => ['required', 'string'],
        ], attributes: [
            'form.install_profile' => __('install profile'),
            'form.server_role' => __('server role'),
            'form.webserver' => __('web server'),
            'form.php_version' => __('PHP version'),
            'form.database' => __('database'),
            'form.cache_service' => __('cache service'),
        ]);

        $this->saveDraftFromForm($this->form, advanceTo: 4);

        return $this->redirect(route(self::routeNameForStep(4)), navigate: true);
    }

    protected function stepNumber(): int
    {
        return 3;
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $context = $this->buildPreflightContext($org);

        return view('livewire.servers.create.step-what', [
            'totalSteps' => ServerCreateDraft::TOTAL_STEPS,
            'reachedStep' => $this->currentDraft()?->step ?? 3,
            'provisionOptions' => $context['provisionOptions'],
            'installProfiles' => config('server_provision_options.install_profiles', []),
        ]);
    }
}
