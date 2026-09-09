<?php

declare(strict_types=1);

use App\Modules\Certificates\Services\CertificateExpiryReader;

test('an openssl enddate line is parsed', function (): void {
    $expiry = CertificateExpiryReader::parseNotAfter("notAfter=Nov 15 12:34:56 2026 GMT\n");

    expect($expiry)->not->toBeNull()
        ->and($expiry->year)->toBe(2026)
        ->and($expiry->month)->toBe(11)
        ->and($expiry->day)->toBe(15);
});

test('output without an enddate line yields null', function (string $output): void {
    expect(CertificateExpiryReader::parseNotAfter($output))->toBeNull();
})->with([
    'empty' => [''],
    'openssl error' => ["unable to load certificate\n"],
    'unrelated output' => ["notBefore=Nov 15 12:34:56 2026 GMT\n"],
]);

test('expiry is read straight from PEM material', function (): void {
    // A throwaway self-signed cert generated in-process, so the test carries no
    // fixture that silently expires.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'expiry-reader.test'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
    openssl_x509_export($cert, $pem);

    $expiry = app(CertificateExpiryReader::class)->readFromPem($pem);

    expect($expiry)->not->toBeNull()
        ->and($expiry->isFuture())->toBeTrue()
        ->and($expiry->diffInDays(now()))->toBeLessThanOrEqual(31);
})->skip(! extension_loaded('openssl'), 'openssl extension required');

test('unusable pem material yields null rather than throwing', function (?string $pem): void {
    expect(app(CertificateExpiryReader::class)->readFromPem($pem))->toBeNull();
})->with([
    'null' => [null],
    'blank' => ['   '],
    'not a certificate' => ['-----BEGIN CERTIFICATE-----nonsense-----END CERTIFICATE-----'],
]);
