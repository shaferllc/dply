<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\SiteDeployment;
use Livewire\Attributes\Computed;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteSettingsView
{
    /**
     * Trigger a (re)deploy of a serverless function from the General-section
     * dashboard, then send the operator to the journey page to watch it run.
     */
    public function redeployServerlessFunction(): void
    {
        $this->authorize('update', $this->site);

        if (! ($this->site->server?->isDigitalOceanFunctionsHost() ?? false)) {
            $this->toastError(__('This site is not a serverless function.'));

            return;
        }

        RunSiteDeploymentJob::dispatch($this->site, SiteDeployment::TRIGGER_MANUAL);

        $this->redirect(route('serverless.journey', [
            'server' => $this->server,
            'site' => $this->site,
        ]), navigate: true);
    }

    /**
     * Recent SiteDeployment rows that carry structured phase_results
     * (i.e. went through the new DeployPhaseRunner). Used by the
     * settings.blade.php "Recent deployments" panel so the view stays
     * free of inline @php(…) blocks that fight Blade's lexer when the
     * expression has nested parens / method chains.
     */
    public function getRecentDeploymentsWithPhasesProperty()
    {
        return $this->site->deployments()
            ->whereNotNull('phase_results')
            ->orderByDesc('started_at')
            ->limit(5)
            ->get();
    }

    /**
     * Most recent SiteDeployment for the general tab "Last deploy" badge.
     *
     * @property-read SiteDeployment|null $latestDeployment
     */
    #[Computed]
    public function latestDeployment(): ?SiteDeployment
    {
        return $this->site->latestDeployment();
    }

    /**
     * @return list<string>
     */
    private function relationsForSettingsSection(string $section): array
    {
        $shared = ['certificates', 'certificates.previewDomain'];

        $sectionRelations = match ($section) {
            'general' => ['domains', 'domainAliases', 'deployments', 'previewDomains', 'workspace'],
            'settings' => ['workspace', 'workspace.variables'],
            'routing' => ['domains', 'domainAliases', 'redirects', 'tenantDomains', 'previewDomains', 'dnsProviderCredential'],
            'certificates' => ['previewDomains'],
            'repository' => ['deployments'],
            'pipeline' => ['deployHooks', 'deploySteps'],
            'deploy' => ['deployHooks', 'deploySteps', 'deployments'],
            'environment' => ['workspace', 'workspace.variables'],
            'logs' => ['deployments', 'webhookDeliveryLogs'],
            'notifications' => ['notificationSubscriptions'],
            'basic-auth' => ['basicAuthUsers', 'accessGate', 'accessGatePasswords'],
            'laravel-stack', 'rails-stack' => ['workspace', 'workspace.variables'],
            default => [],
        };

        return array_values(array_unique(array_merge($shared, $sectionRelations)));
    }

    private function sectionNeedsDeploymentSurface(string $section): bool
    {
        return in_array($section, [
            'general',
            'deploy',
            'repository',
            'pipeline',
            'runtime',
            'environment',
            'laravel-stack',
            'rails-stack',
        ], true);
    }
}
