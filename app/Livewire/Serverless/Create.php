<?php

declare(strict_types=1);

namespace App\Livewire\Serverless;

use App\Actions\Serverless\CreateServerlessFunction;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ProviderCredential;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * One-shot "Create a serverless app" flow — the FaaS counterpart to
 * Edge\Create. Collects a DO credential + region + repo + runtime, hands
 * off to {@see CreateServerlessFunction}, which stands up the host
 * namespace + first function and kicks a deploy.
 */
#[Layout('layouts.app')]
class Create extends Component
{
    use DispatchesToastNotifications;

    public string $provider_credential_id = '';

    /**
     * Preselect a DigitalOcean credential. A serverless host with no
     * credential cannot provision its namespace — the deploy dies at
     * `serverless.namespace.no_credential` — so the form must never submit
     * without one.
     */
    public function mount(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $first = ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->value('id');

        if (is_string($first)) {
            $this->provider_credential_id = $first;
        }
    }

    public string $region = 'nyc1';

    public string $name = '';

    public string $repo = '';

    public string $branch = 'main';

    public string $runtime = 'nodejs:18';

    /** The shaferllc demo repo behind the one-click PHP demo. */
    public const PHP_DEMO_REPO = 'shaferllc/dply-demo-php-function';

    public const PHP_DEMO_BRANCH = 'master';

    /** The shaferllc demo repo behind the one-click Laravel demo. */
    public const LARAVEL_DEMO_REPO = 'shaferllc/dply-demo-laravel-function';

    public const LARAVEL_DEMO_BRANCH = 'master';

    /**
     * Prefill the form with the one-click PHP demo — a minimal native-PHP
     * DigitalOcean Functions web action. The operator just picks a region
     * and credential, then hits Create.
     */
    public function loadPhpDemo(): void
    {
        $this->name = 'PHP demo';
        $this->repo = self::PHP_DEMO_REPO;
        $this->branch = self::PHP_DEMO_BRANCH;
        $this->runtime = 'php:8.3';
    }

    /**
     * Prefill the form with the one-click Laravel demo — a real Laravel app
     * that runs on DigitalOcean Functions' native PHP runtime. dply injects
     * the OpenWhisk↔Laravel adapter at deploy time, so the repo itself is
     * plain Laravel.
     */
    public function loadLaravelDemo(): void
    {
        $this->name = 'Laravel demo';
        $this->repo = self::LARAVEL_DEMO_REPO;
        $this->branch = self::LARAVEL_DEMO_BRANCH;
        // Laravel 13 requires PHP >= 8.4 — running it on php:8.3 trips
        // composer's platform check before the app can even autoload.
        $this->runtime = 'php:8.4';
    }

    public function create(CreateServerlessFunction $action): mixed
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return null;
        }
        $this->authorize('update', $org);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'repo' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:255'],
            'runtime' => ['required', 'string', 'max:64'],
            'region' => ['required', 'string', 'max:32'],
            'provider_credential_id' => ['required', 'string'],
        ], [
            'provider_credential_id.required' => __('Choose a DigitalOcean credential — the namespace cannot be provisioned without one.'),
        ]);

        try {
            $site = $action->handle(auth()->user(), $org, [
                'name' => $this->name,
                'repo' => $this->repo,
                'branch' => $this->branch,
                'runtime' => $this->runtime,
                'region' => $this->region,
                'provider_credential_id' => $this->provider_credential_id,
            ]);
        } catch (Throwable $e) {
            $this->toastError(__('Could not create the function: :msg', ['msg' => $e->getMessage()]));

            return null;
        }

        $this->toastSuccess(__('Serverless function created — deploying now.'));

        return $this->redirect(route('serverless.journey', [$site->server_id, $site->id]), navigate: true);
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();

        $credentials = $org === null ? collect() : ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->get(['id', 'name']);

        return view('livewire.serverless.create', [
            'credentials' => $credentials,
            'functionFee' => app(\App\Services\Serverless\ServerlessCostEstimator::class)->functionFee(),
            'runtimes' => [
                'nodejs:18' => 'Node.js 18',
                'nodejs:20' => 'Node.js 20',
                'php:8.3' => 'PHP 8.3',
                'php:8.4' => 'PHP 8.4',
                'php:8.5' => 'PHP 8.5',
                'python:3.11' => 'Python 3.11',
                'go:1.22' => 'Go 1.22',
            ],
            'regions' => [
                'nyc1' => 'New York',
                'sfo3' => 'San Francisco',
                'ams3' => 'Amsterdam',
                'fra1' => 'Frankfurt',
                'syd1' => 'Sydney',
            ],
        ]);
    }
}
