<?php

namespace App\Livewire\Servers\Concerns;

use Livewire\Component;

/**
 * Styled confirm modal for install_monitoring_prerequisites (replaces wire:confirm).
 *
 * @phpstan-require-extends Component
 */
trait ConfirmsServerMonitoringInstall
{
    public bool $showInstallMonitoringModal = false;

    /** step1 | redeploy | services */
    public string $installMonitoringModalKind = 'step1';

    public function openInstallMonitoringModal(string $kind = 'step1'): void
    {
        $this->installMonitoringModalKind = in_array($kind, ['step1', 'redeploy', 'services'], true) ? $kind : 'step1';
        $this->showInstallMonitoringModal = true;
    }

    public function closeInstallMonitoringModal(): void
    {
        $this->showInstallMonitoringModal = false;
    }

    public function confirmInstallMonitoring(): void
    {
        $this->closeInstallMonitoringModal();
        $this->runInstallAction('install_monitoring_prerequisites');
    }
}
