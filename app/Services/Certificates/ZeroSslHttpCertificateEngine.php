<?php

namespace App\Services\Certificates;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\SshConnection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class ZeroSslHttpCertificateEngine implements CertificateEngine
{
    public function __construct(
        private readonly ImportedCertificateInstaller $importedCertificateInstaller,
    ) {}

    public function supports(SiteCertificate $certificate): bool
    {
        return $certificate->provider_type === SiteCertificate::PROVIDER_ZEROSSL
            && $certificate->challenge_type === SiteCertificate::CHALLENGE_HTTP;
    }

    public function execute(SiteCertificate $certificate): SiteCertificate
    {
        $site = $certificate->site()->with(['server', 'user', 'organization'])->firstOrFail();
        $server = $site->server;

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $accessKey = trim((string) config('services.zerossl.access_key'));
        if ($accessKey === '') {
            throw new \RuntimeException('Set ZEROSSL_ACCESS_KEY before requesting ZeroSSL certificates.');
        }

        $domains = $certificate->domainHostnames();
        if ($domains === []) {
            throw new \InvalidArgumentException('Add at least one domain before requesting SSL.');
        }

        [$privateKeyPem, $csrPem] = $this->ensureSigningMaterial($certificate, $domains);

        $certificate->forceFill([
            'status' => SiteCertificate::STATUS_PENDING,
            'private_key_pem' => $privateKeyPem,
            'csr_pem' => $csrPem,
            'last_requested_at' => now(),
        ])->save();

        $this->validateCsr($accessKey, $csrPem);

        $remoteCertificate = $this->createRemoteCertificate($accessKey, $domains, $csrPem);
        $certificateId = (string) Arr::get($remoteCertificate, 'id', '');

        if ($certificateId === '') {
            throw new \RuntimeException('ZeroSSL did not return a certificate ID.');
        }

        $validationFiles = $this->extractValidationFiles($remoteCertificate, $domains);
        $this->publishValidationFiles($server, $site, $validationFiles);
        $this->verifyRemoteCertificate($accessKey, $certificateId);

        $issuedCertificate = $this->waitForIssuedCertificate($accessKey, $certificateId);
        $downloadedCertificate = $this->downloadRemoteCertificate($accessKey, $certificateId);

        $certificatePem = trim((string) Arr::get($downloadedCertificate, 'certificate.crt', ''));
        $chainPem = trim((string) Arr::get($downloadedCertificate, 'ca_bundle.crt', ''));

        if ($certificatePem === '') {
            throw new \RuntimeException('ZeroSSL did not return a certificate PEM.');
        }

        $certificate->forceFill([
            'credential_reference' => $certificateId,
            'certificate_pem' => $certificatePem,
            'chain_pem' => $chainPem !== '' ? $chainPem : null,
            'requested_settings' => array_merge($certificate->requested_settings ?? [], [
                'validation_method' => 'HTTP_CSR_HASH',
                'strict_domains' => true,
            ]),
            'applied_settings' => array_merge($certificate->applied_settings ?? [], [
                'zerossl_certificate_id' => $certificateId,
                'zerossl_status' => Arr::get($issuedCertificate, 'status'),
                'zerossl_validation_type' => Arr::get($issuedCertificate, 'validation_type'),
            ]),
            'meta' => array_merge($certificate->meta ?? [], [
                'zerossl' => [
                    'certificate_id' => $certificateId,
                    'validation' => Arr::get($remoteCertificate, 'validation.other_methods', []),
                    'issued_status' => Arr::get($issuedCertificate, 'status'),
                    'issued_expires' => Arr::get($issuedCertificate, 'expires'),
                ],
            ]),
        ])->save();

        $installed = $this->importedCertificateInstaller->execute($certificate);

        $installed->forceFill([
            'last_output' => trim(implode("\n\n", array_filter([
                'ZeroSSL certificate issued and installed.',
                'Certificate ID: '.$certificateId,
                (string) Arr::get($issuedCertificate, 'expires', ''),
            ]))),
        ])->save();

        return $installed->fresh();
    }

    /**
     * @param  list<string>  $domains
     * @return array{0: string, 1: string}
     */
    protected function ensureSigningMaterial(SiteCertificate $certificate, array $domains): array
    {
        $privateKeyPem = trim((string) $certificate->private_key_pem);
        $csrPem = trim((string) $certificate->csr_pem);

        if ($privateKeyPem !== '' && $csrPem !== '') {
            return [$privateKeyPem, $csrPem];
        }

        $dn = ['commonName' => $domains[0]];
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($privateKey === false) {
            throw new \RuntimeException('Failed to generate a private key.');
        }

        $csr = openssl_csr_new($dn, $privateKey, [
            'digest_alg' => 'sha256',
        ]);

        if ($csr === false) {
            throw new \RuntimeException('Failed to generate a certificate signing request.');
        }

        openssl_pkey_export($privateKey, $privateKeyPem);
        openssl_csr_export($csr, $csrPem);

        return [trim((string) $privateKeyPem), trim((string) $csrPem)];
    }

    protected function validateCsr(string $accessKey, string $csrPem): void
    {
        $payload = $this->postForm('https://api.zerossl.com/validation/csr', $accessKey, [
            'csr' => $csrPem,
        ]);

        if (! (bool) Arr::get($payload, 'valid', false)) {
            throw new \RuntimeException('ZeroSSL rejected the generated CSR.');
        }
    }

    /**
     * @param  list<string>  $domains
     * @return array<string, mixed>
     */
    protected function createRemoteCertificate(string $accessKey, array $domains, string $csrPem): array
    {
        return $this->postForm('https://api.zerossl.com/certificates', $accessKey, [
            'certificate_domains' => implode(',', $domains),
            'certificate_csr' => $csrPem,
            'strict_domains' => '1',
        ]);
    }

    protected function verifyRemoteCertificate(string $accessKey, string $certificateId): void
    {
        $this->postForm(sprintf('https://api.zerossl.com/certificates/%s/challenges', $certificateId), $accessKey, [
            'validation_method' => 'HTTP_CSR_HASH',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function waitForIssuedCertificate(string $accessKey, string $certificateId): array
    {
        $attempts = max(1, (int) config('services.zerossl.poll_attempts', 10));
        $sleepMs = max(0, (int) config('services.zerossl.poll_sleep_ms', 2000));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $payload = $this->getJson(sprintf('https://api.zerossl.com/certificates/%s', $certificateId), $accessKey);
            $status = strtolower((string) Arr::get($payload, 'status', ''));

            if ($status === 'issued') {
                return $payload;
            }

            if (in_array($status, ['cancelled', 'revoked', 'expired'], true)) {
                throw new \RuntimeException('ZeroSSL certificate request entered terminal state: '.$status.'.');
            }

            if ($attempt < $attempts) {
                $this->sleepMilliseconds($sleepMs);
            }
        }

        throw new \RuntimeException('ZeroSSL did not issue the certificate before the poll timeout.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function downloadRemoteCertificate(string $accessKey, string $certificateId): array
    {
        return $this->getJson(sprintf('https://api.zerossl.com/certificates/%s/download/json', $certificateId), $accessKey);
    }

    /**
     * @param  array<string, mixed>  $remoteCertificate
     * @param  list<string>  $domains
     * @return array<int, array{domain: string, filename: string, content: string}>
     */
    protected function extractValidationFiles(array $remoteCertificate, array $domains): array
    {
        $otherMethods = Arr::get($remoteCertificate, 'validation.other_methods', []);
        $files = [];

        foreach ($domains as $domain) {
            $domainMethods = is_array($otherMethods[$domain] ?? null) ? $otherMethods[$domain] : [];
            $url = (string) ($domainMethods['file_validation_url_http'] ?? '');
            $contentLines = $domainMethods['file_validation_content'] ?? [];
            $filename = basename(parse_url($url, PHP_URL_PATH) ?: '');

            if ($filename === '' || ! is_array($contentLines) || $contentLines === []) {
                throw new \RuntimeException('ZeroSSL did not return HTTP validation instructions for '.$domain.'.');
            }

            $files[] = [
                'domain' => $domain,
                'filename' => $filename,
                'content' => implode("\n", array_map(
                    static fn (mixed $line): string => trim((string) $line),
                    $contentLines
                )),
            ];
        }

        return $files;
    }

    /**
     * @param  array<int, array{domain: string, filename: string, content: string}>  $files
     */
    protected function publishValidationFiles(Server $server, Site $site, array $files): void
    {
        $validationDir = rtrim($site->effectiveDocumentRoot(), '/').'/.well-known/pki-validation';
        $this->runRemoteCommand(
            $server,
            sprintf('mkdir -p %s', escapeshellarg($validationDir)),
            60
        );

        foreach ($files as $file) {
            $this->writeRemoteFile(
                $server,
                $validationDir.'/'.$file['filename'],
                $file['content']."\n"
            );
        }
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    protected function postForm(string $url, string $accessKey, array $data): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post($url.'?access_key='.urlencode($accessKey), $data);

        return $this->decodeResponse($response->status(), $response->json(), $response->body());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getJson(string $url, string $accessKey): array
    {
        $response = Http::acceptJson()
            ->timeout(20)
            ->get($url, ['access_key' => $accessKey]);

        return $this->decodeResponse($response->status(), $response->json(), $response->body());
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeResponse(int $status, mixed $decoded, string $rawBody): array
    {
        if ($status < 200 || $status >= 300 || ! is_array($decoded)) {
            throw new \RuntimeException($this->apiErrorMessage(is_array($decoded) ? $decoded : [], $rawBody));
        }

        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            throw new \RuntimeException($this->apiErrorMessage($decoded, $rawBody));
        }

        if (array_key_exists('error', $decoded) && $decoded['error'] && ! Arr::get($decoded, 'valid', true)) {
            throw new \RuntimeException($this->apiErrorMessage($decoded, $rawBody));
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function apiErrorMessage(array $payload, string $rawBody): string
    {
        $error = Arr::get($payload, 'error');

        if (is_string($error) && $error !== '') {
            return $error;
        }

        if (is_array($error)) {
            $code = Arr::get($error, 'code');
            $type = Arr::get($error, 'type');
            $details = Arr::get($error, 'details');

            return trim(implode(' ', array_filter([
                'ZeroSSL API request failed.',
                is_string($type) ? $type : null,
                is_string($code) ? 'Code: '.$code : null,
                is_string($details) ? $details : null,
            ])));
        }

        return $rawBody !== '' ? $rawBody : 'ZeroSSL API request failed.';
    }

    protected function runRemoteCommand(Server $server, string $command, int $timeout): string
    {
        return (new SshConnection($server))->exec($command, $timeout);
    }

    protected function writeRemoteFile(Server $server, string $path, string $contents): void
    {
        (new SshConnection($server))->putFile($path, $contents);
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
