<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\Certificates\CertificateRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteSiteCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $certificateId,
        public ?string $userId = null,
    ) {}

    protected function consoleSubject(): Model
    {
        // The console banner lives on the Site page — the certificate row's site
        // is the natural subject. If the certificate has been deleted the early
        // return in handle() short-circuits before we reach beginConsoleAction(),
        // so this only runs when the site is real.
        $cert = SiteCertificate::query()->with('site')->findOrFail($this->certificateId);

        return $cert->site ?? throw new \RuntimeException('Certificate has no site.');
    }

    protected function consoleKind(): string
    {
        return 'ssl';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(CertificateRequestService $certificateRequestService): void
    {
        $certificate = SiteCertificate::query()->with('site')->find($this->certificateId);
        if (! $certificate || ! $certificate->site instanceof Site) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('ssl', 'requesting certificate');
            $certificateRequestService->execute($certificate);
            $emit->success('certificate request executed', 'ssl');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'ssl');
            $this->failConsoleAction($e->getMessage());
            Log::warning('ExecuteSiteCertificateJob failed', [
                'certificate_id' => $this->certificateId,
                'site_id' => $certificate->site_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
