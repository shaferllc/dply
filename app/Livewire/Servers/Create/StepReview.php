<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Actions\Servers\StoreServerFromCreateForm;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Livewire\Servers\Concerns\ServerCreateActions;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step 4 of the create-server wizard. Read-only summary, advanced options collapsed,
 * preflight + cost preview, final create button.
 */
#[Layout('layouts.app')]
class StepReview extends Component
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

        return null;
    }

    public function previous(): mixed
    {
        $this->saveDraftFromForm($this->form);

        // VM-shaped servers go back to "What it runs"; Docker hosts skip step 3.
        $back = ($this->form->mode === 'custom' && $this->form->custom_host_kind === 'docker') ? 2 : 3;

        return $this->redirect(route(self::routeNameForStep($back)), navigate: true);
    }

    public function store(): mixed
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }
        if (! $user->hasVerifiedEmail()) {
            return $this->redirect(route('verification.notice'), navigate: true)
                ->with('error', __('Please verify your email address before creating a server.'));
        }

        $this->authorize('create', Server::class);

        $org = $user->currentOrganization();
        if (! $org) {
            $this->addError('org', __('Select or create an organization first.'));

            return null;
        }

        if (! in_array($this->form->type, ['custom', 'digitalocean_functions', 'digitalocean_kubernetes', 'aws_lambda'], true) && ! $user->sshKeys()->exists()) {
            return $this->redirectRoute('profile.ssh-keys', [
                'source' => 'servers.create',
                'return_to' => 'servers.create',
            ], navigate: true);
        }

        if (! $org->canCreateServer()) {
            $this->addError('org', __('Server limit reached for your plan. Upgrade to add more.'));

            return null;
        }

        // Persist the latest field state into the draft before running preflight,
        // so a soft failure (validation errors) leaves the draft unsurprised.
        $this->saveDraftFromForm($this->form);

        $preflight = $this->buildPreflightContext($org);
        if (! $preflight['preflight']['can_submit']) {
            foreach ($preflight['preflight']['blocking_fields'] as $field => $message) {
                $this->addError($field, $message);
            }
            if ($preflight['preflight']['blocking_fields'] === []) {
                $this->addError('org', $preflight['preflight']['summary']);
            }

            return null;
        }

        try {
            $server = StoreServerFromCreateForm::run($user, $org, $this->form);
        } catch (ValidationException $e) {
            $this->mergeValidationException($e);

            return null;
        }

        $this->deleteCurrentDraft();
        $this->flashSuccessForServerType($this->form->type);

        return $this->redirect(route('servers.show', $server), navigate: true);
    }

    protected function flashSuccessForServerType(string $type): void
    {
        Session::flash('success', match ($type) {
            'digitalocean_functions' => __('DigitalOcean Functions host added. Create a site to wire its runtime and deploy settings.'),
            'aws_lambda' => __('AWS Lambda target added. Create a site to wire its runtime and Bref deploy settings.'),
            'digitalocean_kubernetes' => __('DigitalOcean Kubernetes target added. Create a site to prepare manifests and cluster runtime settings.'),
            'equinix_metal' => __('Bare metal can take 5–10 minutes.'),
            'fly_io' => __('Fly.io machine is being created.'),
            'aws' => __('AWS EC2 instance is being created. This usually takes 1–2 minutes.'),
            'custom' => __('Server added.'),
            default => __('Server is being created. This usually takes 1–2 minutes.'),
        });
    }

    protected function stepNumber(): int
    {
        return 4;
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $context = $this->buildPreflightContext($org);

        $isVmShaped = ! ($this->form->mode === 'custom' && $this->form->custom_host_kind === 'docker');

        return view('livewire.servers.create.step-review', [
            'totalSteps' => ServerCreateDraft::TOTAL_STEPS,
            'reachedStep' => $this->currentDraft()?->step ?? 4,
            'catalog' => $context['catalog'],
            'preflight' => $context['preflight'],
            'isVmShaped' => $isVmShaped,
        ]);
    }
}
