<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Actions\Servers\StoreManagedServer;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Services\Billing\ServerResourceCostCalculator;
use App\Support\Servers\ServerHostingPlatformContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * One-shot "Create a dply-managed server" flow — the VM counterpart to
 * Serverless\Create and Cloud\Create. dply provisions the Hetzner VM on its own
 * platform account and bills it all-in cost-plus, so there is no provider-credential
 * step. Hands off to {@see StoreManagedServer}, which creates the server and
 * dispatches the standard Hetzner provision job (managed branch).
 */
#[Layout('layouts.app')]
class CreateManaged extends Component
{
    use DispatchesToastNotifications;

    public string $name = '';

    public string $region = '';

    public string $size = '';

    public string $install_profile = 'laravel_app';

    public function mount(): void
    {
        abort_unless($this->managedAvailable(), 404);

        $this->region = (string) (array_key_first((array) config('managed_servers.regions', [])) ?? 'fsn1');
        $this->size = (string) (collect((array) config('managed_servers.sizes', []))->first()['slug'] ?? 'cx22');

        // Beta's free box is pinned to CX22 — preselect it; the view hides the
        // size picker and shows "Free during beta".
        if ($this->isBetaOrg()) {
            $this->size = (string) config('subscription.standard.beta.managed_size', 'cx22');
        }
    }

    private function isBetaOrg(): bool
    {
        return (bool) auth()->user()?->currentOrganization()?->isBeta();
    }

    public function managedAvailable(): bool
    {
        return Feature::active('surface.managed_servers')
            && ServerHostingPlatformContext::fromConfig()->configured();
    }

    public function create(StoreManagedServer $store)
    {
        $user = auth()->user();
        $org = $user?->currentOrganization();

        if ($org === null) {
            $this->toastError(__('Select an organization before creating a server.'));

            return null;
        }

        try {
            $server = $store->handle($user, $org, [
                'name' => $this->name,
                'region' => $this->region,
                'size' => $this->size,
                'install_profile' => $this->install_profile,
            ]);
        } catch (ValidationException $e) {
            // Surface the actionable message (verify email / grant used / not
            // configured) rather than swallowing it as a generic error.
            $this->toastError($e->validator->errors()->first());

            return null;
        } catch (Throwable $e) {
            report($e);
            $this->toastError(__('We could not start that server. Please try again.'));

            return null;
        }

        $this->toastSuccess(__('Provisioning your managed server…'));

        return $this->redirect(route('servers.journey', $server), navigate: true);
    }

    public function render(): View
    {
        $calculator = app(ServerResourceCostCalculator::class);

        $sizes = collect((array) config('managed_servers.sizes', []))
            ->map(fn (array $s) => [
                ...$s,
                'monthly_cents' => $calculator->monthlyCentsForSize((string) $s['slug']),
            ])
            ->values()
            ->all();

        $selectedMonthlyCents = $calculator->monthlyCentsForSize($this->size);

        $org = auth()->user()?->currentOrganization();
        $isBeta = (bool) $org?->isBeta();

        return view('livewire.servers.create-managed', [
            'regions' => (array) config('managed_servers.regions', []),
            'sizes' => $sizes,
            'profiles' => (array) config('server_provision_options.install_profiles', []),
            'selectedMonthlyCents' => $selectedMonthlyCents,
            // Beta context: the box is free (comped until cutover) and pinned to
            // CX22; once the single grant is used the create flow is disabled.
            'isBeta' => $isBeta,
            'betaGrantUsed' => $isBeta && $org !== null && ! $org->canCreateManagedServer(),
            'emailVerified' => (bool) auth()->user()?->hasVerifiedEmail(),
        ]);
    }
}
