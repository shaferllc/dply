<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Support\Servers\ServerNameGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StepType extends Component
{
    use InteractsWithServerCreateDraft;

    public ServerCreateForm $form;

    public ?string $launchSource = null;

    /** Used to show the "Create the remote Docker host first" framing on Step 1. */
    public bool $dockerHostHinted = false;

    public function mount(): mixed
    {
        $this->authorize('create', Server::class);

        $draft = $this->currentDraft();

        // If the user has a draft already in progress past step 1, jump them to where
        // they left off so they don't have to walk forward through completed steps.
        // They can still edit Step 1 by clicking back from any later step (the stepper
        // pills are clickable for any reached step).
        if ($draft !== null && $draft->step > 1 && request()->query('edit') !== '1') {
            return $this->redirect(route(self::routeNameForStep($draft->step)), navigate: true);
        }

        $this->hydrateFormFromDraft($this->form, $draft);

        if ($draft === null) {
            // Defaults for a brand-new draft.
            if ($this->form->name === '') {
                $this->form->name = ServerNameGenerator::generate();
            }
            if ($this->form->mode === '' || ! in_array($this->form->mode, ['provider', 'custom'], true)) {
                $this->form->mode = 'provider';
            }

            $requestedSource = request()->query('source');
            if (is_string($requestedSource) && $requestedSource !== '') {
                $this->launchSource = $requestedSource;
            }

            $requestedHostTarget = request()->query('host_target');
            if ($requestedHostTarget === 'docker') {
                $this->dockerHostHinted = true;
                $this->form->mode = 'custom';
                $this->form->custom_host_kind = 'docker';
            }
        }

        return null;
    }

    public function regenerateName(): void
    {
        $this->form->name = ServerNameGenerator::generate();
    }

    public function chooseProviderMode(): void
    {
        $this->form->mode = 'provider';
        // Provider tile is selected on Step 2; clear any stale 'custom' marker on type.
        if ($this->form->type === 'custom') {
            $this->form->type = '';
        }
    }

    public function chooseCustomMode(): void
    {
        $this->form->mode = 'custom';
        $this->form->type = 'custom';
    }

    public function next(): mixed
    {
        $this->authorize('create', Server::class);

        $this->validate([
            'form.mode' => ['required', Rule::in(['provider', 'custom'])],
            'form.name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ], attributes: [
            'form.mode' => __('server type'),
            'form.name' => __('server name'),
        ]);

        if ($this->form->mode === 'custom' && $this->form->type !== 'custom') {
            $this->form->type = 'custom';
        }

        $this->saveDraftFromForm($this->form, advanceTo: 2);

        return $this->redirect(route(self::routeNameForStep(2)), navigate: true);
    }

    protected function stepNumber(): int
    {
        return 1;
    }

    public function render(): View
    {
        return view('livewire.servers.create.step-type', [
            'totalSteps' => ServerCreateDraft::TOTAL_STEPS,
        ]);
    }
}
