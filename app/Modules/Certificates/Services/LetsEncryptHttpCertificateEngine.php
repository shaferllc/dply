<?php

namespace App\Modules\Certificates\Services;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\Servers\OpenLiteSpeedTlsConfigurator;
use App\Services\Sites\SiteWebserverConfigApplier;
use App\Services\SshConnection;
use App\Support\Sites\CertbotOutputParser;
use App\Support\Sites\LetsEncryptCertbotCommandBuilder;

class LetsEncryptHttpCertificateEngine implements CertificateEngine
{
    use PrivilegedRemoteFileWrites;

    public function supports(SiteCertificate $certificate): bool
    {
        return $certificate->provider_type === SiteCertificate::PROVIDER_LETSENCRYPT
            && $certificate->challenge_type === SiteCertificate::CHALLENGE_HTTP;
    }

    public function execute(SiteCertificate $certificate): SiteCertificate
    {
        $site = $certificate->site()->with(['server', 'domains', 'previewDomains', 'user', 'organization'])->firstOrFail();
        $server = $site->server;

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $domains = $certificate->domainHostnames();
        if ($domains === []) {
            throw new \InvalidArgumentException('Add at least one domain before requesting SSL.');
        }

        $email = config('sites.certbot_email')
            ?: $site->user?->email
            ?: $site->organization?->email;

        if (! is_string($email) || $email === '') {
            throw new \InvalidArgumentException('Set DPLY_CERTBOT_EMAIL, organization email, or user email for Let\'s Encrypt.');
        }

        $certificate->forceFill([
            'status' => SiteCertificate::STATUS_PENDING,
            'last_requested_at' => now(),
            'requested_settings' => array_merge($certificate->requested_settings ?? [], [
                'email' => $email,
            ]),
        ])->save();

        if (LetsEncryptCertbotCommandBuilder::usesWebrootChallenge($site)) {
            app(SiteWebserverConfigApplier::class)->apply($site);
        }

        $cmd = LetsEncryptCertbotCommandBuilder::build($site, $domains, $email);

        $ssh = new SshConnection($server);
        $output = $ssh->exec(
            $this->privilegedCommand($server, $cmd.'; printf "\nDPLY_EXIT:%s" "$?"'),
            600,
        );
        $exitCode = preg_match('/DPLY_EXIT:(\d+)/', $output, $matches) ? (int) $matches[1] : 1;
        $ok = $exitCode === 0;

        $certificate->forceFill([
            'status' => $ok ? SiteCertificate::STATUS_ACTIVE : SiteCertificate::STATUS_FAILED,
            'last_output' => $output,
            'last_installed_at' => $ok ? now() : $certificate->last_installed_at,
            'applied_settings' => array_merge($certificate->applied_settings ?? [], [
                'domains' => $domains,
                'http3_requested' => (bool) $certificate->enable_http3,
                'http3_applied' => false,
            ]),
        ])->save();

        $site->update([
            'ssl_status' => $ok ? Site::SSL_ACTIVE : Site::SSL_FAILED,
            'ssl_installed_at' => $ok ? now() : $site->ssl_installed_at,
            'meta' => array_merge($site->meta ?? [], [
                'ssl_last_output' => $output,
                'ssl_last_attempt_at' => now()->toIso8601String(),
                'ssl_last_requested_domains' => $domains,
            ]),
        ]);

        if ($certificate->scope_type === SiteCertificate::SCOPE_PREVIEW && $certificate->previewDomain) {
            $certificate->previewDomain->update([
                'ssl_status' => $ok ? 'active' : 'failed',
                'last_ssl_checked_at' => now(),
            ]);
        }

        if (! $ok) {
            $summary = CertbotOutputParser::failureSummary($output);
            throw new \RuntimeException(
                $summary !== ''
                    ? 'Certbot exited with code '.$exitCode.': '.$summary
                    : 'Certbot exited with code '.$exitCode.'. Check certificate output for details.',
            );
        }

        if ($site->webserver() === 'openlitespeed') {
            app(OpenLiteSpeedTlsConfigurator::class)->syncServer($server->fresh());
        }

        if (LetsEncryptCertbotCommandBuilder::usesWebrootChallenge($site)) {
            ApplySiteWebserverConfigJob::dispatch((string) $site->id);
        }

        return $certificate->fresh();
    }
}
