<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesSiteApplyState;
use App\Models\SiteCertificate;
use App\Services\Certificates\CertificateRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteSiteCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesSiteApplyState;

    public int $tries = 1;

    public function __construct(
        public string $certificateId,
    ) {}

    protected function applyKind(): string
    {
        return 'ssl';
    }

    public function handle(CertificateRequestService $certificateRequestService): void
    {
        $certificate = SiteCertificate::query()->with('site')->find($this->certificateId);
        if (! $certificate) {
            return;
        }

        $site = $certificate->site;
        $runId = $site ? $this->beginApplyRun($site) : null;

        try {
            $certificateRequestService->execute($certificate);
            if ($site) {
                $this->completeApplyRun($site);
            }
        } catch (\Throwable $e) {
            if ($site) {
                $this->cacheApplyOutput((string) $runId, $e->getMessage());
                $this->failApplyRun($site, $e->getMessage());
            }
            Log::warning('ExecuteSiteCertificateJob failed', [
                'certificate_id' => $this->certificateId,
                'site_id' => $certificate->site_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
