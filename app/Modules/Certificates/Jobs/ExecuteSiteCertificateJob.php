<?php

namespace App\Modules\Certificates\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\User;
use App\Modules\Certificates\Services\CertificateRequestService;
use App\Modules\Notifications\Services\ServerCertInventoryNotificationDispatcher;
use App\Support\Sites\CertbotOutputParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExecuteSiteCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $certificateId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
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

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('ssl', 'requesting certificate');
            $certificateRequestService->execute($certificate);
            $emit->success('certificate request executed', 'ssl');
            $this->completeConsoleAction();
            $this->recordAudit($certificate, 'site.ssl.issued', null);
            $this->notifyCertOutcome($certificate, 'renewed', null);
        } catch (\Throwable $e) {
            $certificate->refresh();
            $summary = CertbotOutputParser::failureSummary((string) $certificate->last_output);
            if ($summary !== '' && ! str_contains($e->getMessage(), $summary)) {
                $emit->error($summary, 'ssl');
            }
            $emit->error($e->getMessage(), 'ssl');
            $this->failConsoleAction($e->getMessage());
            Log::warning('ExecuteSiteCertificateJob failed', [
                'certificate_id' => $this->certificateId,
                'site_id' => $certificate->site_id,
                'error' => $e->getMessage(),
            ]);
            $this->recordAudit($certificate, 'site.ssl.failed', $e->getMessage());
            $this->notifyCertOutcome($certificate, 'renewal_failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Publish a server-scoped certificate notification (issue/renew success or
     * failure). The cert's site resolves the server; the triggering user is the actor.
     */
    private function notifyCertOutcome(SiteCertificate $certificate, string $kind, ?string $error): void
    {
        $certificate->loadMissing('site.server');
        $server = $certificate->site?->server;
        if ($server === null) {
            return;
        }

        $domains = $certificate->domainHostnames();
        $domainLabel = $domains !== []
            ? implode(', ', array_slice($domains, 0, 3))
            : ($certificate->site->name ?? 'certificate');

        $details = [__('Certificate: :domains', ['domains' => $domainLabel])];
        $details[] = __('Site: :name', ['name' => $certificate->site->name]);
        if ($kind === 'renewal_failed' && $error) {
            $details[] = __('Error: :error', ['error' => Str::limit($error, 300)]);
        }

        app(ServerCertInventoryNotificationDispatcher::class)->notify(
            $server,
            $kind,
            $details,
            $this->userId ? User::find($this->userId) : null,
            [
                'certificate_id' => (string) $certificate->id,
                'site_id' => (string) $certificate->site_id,
                'provider_type' => $certificate->provider_type ?? null,
                'expires_at' => $certificate->expires_at?->toIso8601String(),
            ],
        );
    }

    private function recordAudit(SiteCertificate $certificate, string $action, ?string $errorMessage): void
    {
        $site = $certificate->site;
        $org = $site?->organization;
        if ($org === null) {
            return;
        }
        $user = $this->userId ? User::find($this->userId) : null;
        $certificate->refresh();

        audit_log($org, $user, $action, $certificate, null, array_filter([
            'site' => $site->name,
            'site_id' => (string) $site->id,
            'certificate_id' => (string) $certificate->id,
            'domains' => is_array($certificate->domains ?? null) ? $certificate->domains : null,
            'engine' => $certificate->engine ?? null,
            'expires_at' => $certificate->expires_at?->toIso8601String(),
            'error' => $errorMessage,
        ], fn ($v) => $v !== null));
    }
}
