<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Livewire\Servers\Concerns\ServerCreateActions;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Services\Servers\ServerCreatePresetCatalog;
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

    /**
     * Slug of the preset that drove the current form values, when one
     * was applied. Empty when the user is hand-rolling the stack.
     */
    public string $selectedPreset = '';

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

    /**
     * Apply a preset from {@see ServerCreatePresetCatalog} to the form.
     *
     * Each preset bundles role / webserver / PHP version / database /
     * cache so the user gets a Forge-style "I'm a Laravel app" tile
     * rather than picking 6 fields one by one. Per the strategy memo:
     * "Preset row at the top pre-fills runtimes + role + db + cache +
     * web; users can override anything below."
     *
     * Custom is intentionally a no-op (clears selection without
     * changing form state) so it acts as the "I'll pick myself"
     * escape hatch from a previous preset choice.
     */
    public function applyPreset(string $presetId, ServerCreatePresetCatalog $catalog): void
    {
        $preset = $catalog->find($presetId);
        if ($preset === null) {
            return;
        }

        $this->selectedPreset = $presetId;

        if ($presetId === ServerCreatePresetCatalog::ID_CUSTOM) {
            return;
        }

        // Preset → form field mapping. Empty / null preset values stay
        // empty so the user can fill them manually for the static-host
        // and database-node tiles (which intentionally clear PHP).
        $this->form->server_role = $preset['role'];
        if ($preset['webserver'] !== null) {
            $this->form->webserver = $preset['webserver'];
        }
        if ($preset['php_version'] !== null) {
            $this->form->php_version = $preset['php_version'];
        }
        if ($preset['database'] !== null) {
            $this->form->database = $preset['database'];
        }
        if ($preset['cache'] !== null) {
            $this->form->cache_service = $preset['cache'];
        }

        // Persist the preset choice on the draft so re-entering the step
        // remembers the tile the user clicked. Stored under the existing
        // form->install_profile slot for now since the wizard's draft
        // schema already round-trips that field.
        $this->form->install_profile = match ($presetId) {
            ServerCreatePresetCatalog::ID_LARAVEL => 'laravel_app',
            ServerCreatePresetCatalog::ID_RAILS,
            ServerCreatePresetCatalog::ID_NEXTJS,
            ServerCreatePresetCatalog::ID_DJANGO => 'plain',
            ServerCreatePresetCatalog::ID_POLYGLOT => 'plain',
            ServerCreatePresetCatalog::ID_STATIC => 'static_app_host',
            ServerCreatePresetCatalog::ID_DATABASE => 'plain',
            default => $this->form->install_profile,
        };
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
            'serverPresets' => app(ServerCreatePresetCatalog::class)->all(),
            'selectedPreset' => $this->selectedPreset,
        ]);
    }
}
