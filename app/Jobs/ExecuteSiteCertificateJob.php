<?php

namespace App\Jobs;

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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $certificateId,
    ) {}

    public function handle(CertificateRequestService $certificateRequestService): void
    {
        $certificate = SiteCertificate::query()->find($this->certificateId);
        if (! $certificate) {
            return;
        }

        try {
            $certificateRequestService->execute($certificate);
        } catch (\Throwable $e) {
            Log::warning('ExecuteSiteCertificateJob failed', [
                'certificate_id' => $this->certificateId,
                'site_id' => $certificate->site_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
