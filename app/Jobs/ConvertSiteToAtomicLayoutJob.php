<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Sites\SiteAtomicLayoutConverter;
use App\Services\Sites\SiteAtomicLayoutRequester;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\SiteWebserverConfigApplier;
use App\Services\SshConnectionFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ConvertSiteToAtomicLayoutJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'atomic-layout-convert:'.$this->siteId;
    }

    public function seed(): string
    {
        return $this->seedQueuedConsoleAction();
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'atomic_layout_convert';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        SiteAtomicLayoutConverter $converter,
        SiteAtomicLayoutRequester $requester,
        SshConnectionFactory $sshFactory,
        SiteWebserverConfigApplier $webserver,
        SiteSystemdProvisioner $systemd,
    ): void {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $converted = [];
            $readyHosts = 0;
            $primaryEmpty = false;

            foreach ($site->atomicLayoutConvertHosts() as $host) {
                $host->refresh();
                $server = $host->server;
                if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
                    $emit("[dply] skip {$host->name}: server not ready", 'info', 'layout');

                    continue;
                }

                $readyHosts++;
                $emit("[dply] convert host {$host->name} (#{$host->id})", 'info', 'layout');
                $ssh = $sshFactory->forServer($server);
                $result = $converter->convert($host, $ssh);
                $emit($result['log'], 'info', 'layout');

                if ($result['skipped'] && $host->is($site) && $result['layout'] === SiteAtomicLayoutConverter::LAYOUT_EMPTY) {
                    $primaryEmpty = true;
                    $emit('[dply] primary has no checkout — will flip strategy so the first deploy is atomic', 'info', 'layout');
                }

                if (! $result['skipped']) {
                    $converted[] = $host;
                }
            }

            if ($readyHosts === 0) {
                throw new \RuntimeException('No ready hosts to convert. Provisioning and SSH must be ready first.');
            }

            foreach ($converted as $host) {
                $requester->markConverted($host);
            }

            if ($primaryEmpty && $site->fresh()?->isAtomicDeploys() !== true) {
                $requester->markConverted($site->fresh() ?? $site);
            }

            $site->refresh();

            foreach ($site->atomicLayoutConvertHosts() as $host) {
                $host->refresh();
                $server = $host->server;
                if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
                    continue;
                }

                $ssh = $sshFactory->forServer($server);
                $this->reapplyRuntime($host, $webserver, $systemd, $sshFactory, $emit);

                $inspect = $converter->inspect($ssh, rtrim($host->effectiveRepositoryPath(), '/'));
                if (in_array($inspect['layout'], [SiteAtomicLayoutConverter::LAYOUT_HYBRID, SiteAtomicLayoutConverter::LAYOUT_FLAT], true)
                    && $inspect['layout'] !== SiteAtomicLayoutConverter::LAYOUT_EMPTY) {
                    $emit($converter->archiveLeftoverRoot($ssh, rtrim($host->effectiveRepositoryPath(), '/'), gmdate('YmdHis')), 'info', 'layout');
                }
            }

            $this->completeConsoleAction();
        } catch (Throwable $e) {
            $requester->markFailed($site->fresh() ?? $site, $e->getMessage());
            $this->failConsoleAction($e->getMessage());

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site !== null) {
            app(SiteAtomicLayoutRequester::class)->markFailed($site, $e?->getMessage() ?? 'Layout conversion failed.');
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $this->failConsoleAction($e?->getMessage() ?? 'Layout conversion failed.');
    }

    private function reapplyRuntime(
        Site $site,
        SiteWebserverConfigApplier $webserver,
        SiteSystemdProvisioner $systemd,
        SshConnectionFactory $sshFactory,
        ConsoleEmitter $emit,
    ): void {
        $server = $site->server;
        if ($server === null) {
            return;
        }

        if (! $site->isWorkerSite() && $server->hostCapabilities()->supportsSsh()) {
            $log = $webserver->apply($site);
            $emit("[dply] webserver reapplied\n".$log, 'info', 'layout');
        }

        try {
            $units = $systemd->provision($site, fn ($s) => $sshFactory->forServer($s));
            if ($units !== []) {
                $emit('[dply] systemd units: '.implode(', ', $units), 'info', 'layout');
            }
        } catch (Throwable $e) {
            $emit('[dply] systemd reapply skipped: '.$e->getMessage(), 'info', 'layout');
        }
    }
}
