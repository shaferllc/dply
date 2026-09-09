<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Services;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use App\Services\SshConnectionFactory;
use Illuminate\Support\Carbon;

/**
 * Reads a certificate's notAfter date from the certificate itself.
 *
 * Issuance never persisted an expiry, so `site_certificates.expires_at` was
 * null for every certbot-issued cert. That blanked the Expires column and, worse,
 * made the renew button dead: renewability is derived from days-until-expiry, and
 * a null expiry is never "near enough" to renew.
 */
class CertificateExpiryReader
{
    use PrivilegedRemoteFileWrites;

    public function __construct(
        private readonly SshConnectionFactory $connections,
    ) {}

    /**
     * Read the expiry of a certificate file on a server.
     *
     * Pass an existing connection when one is already open — issuance has just
     * finished an SSH round trip and there is no reason to pay for another.
     */
    public function readFromPath(Server $server, string $path, ?SshConnection $ssh = null): ?Carbon
    {
        if (trim($path) === '') {
            return null;
        }

        try {
            $ssh ??= $this->connections->forServer($server);
            $cmd = 'openssl x509 -enddate -noout -in '.escapeshellarg($path).' 2>/dev/null';
            $out = $ssh->exec($this->privilegedCommand($server, $cmd), 30);
        } catch (\Throwable) {
            // Expiry is metadata: never fail an otherwise-successful issuance
            // because the follow-up read did not land.
            return null;
        }

        return self::parseNotAfter($out);
    }

    /**
     * Read the expiry straight out of PEM material dply already holds — no SSH,
     * so imported and ZeroSSL certs get an expiry at install time for free.
     */
    public function readFromPem(?string $pem): ?Carbon
    {
        if (! is_string($pem) || trim($pem) === '') {
            return null;
        }

        $parsed = @openssl_x509_parse($pem);
        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            return null;
        }

        $timestamp = (int) $parsed['validTo_time_t'];

        return $timestamp > 0 ? Carbon::createFromTimestampUTC($timestamp) : null;
    }

    /** Parse the `notAfter=...` line emitted by `openssl x509 -enddate`. */
    public static function parseNotAfter(string $output): ?Carbon
    {
        if (preg_match('/notAfter=(.+)/', $output, $matches) !== 1) {
            return null;
        }

        try {
            return Carbon::parse(trim($matches[1]));
        } catch (\Throwable) {
            return null;
        }
    }
}
