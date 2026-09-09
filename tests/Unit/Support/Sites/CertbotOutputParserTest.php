<?php

declare(strict_types=1);

use App\Support\Sites\CertbotOutputParser;

test('certbot output parser extracts error detail lines', function (): void {
    $output = <<<'TXT'
Saving debug log to /var/log/letsencrypt/letsencrypt.log
Some other noise
Error: urn:ietf:params:acme:error:connection
Detail: Fetching http://testing.example.test/.well-known/acme-challenge/token: Connection refused
DPLY_EXIT:1
TXT;

    expect(CertbotOutputParser::failureSummary($output))
        ->toContain('Error: urn:ietf:params:acme:error:connection')
        ->toContain('Detail: Fetching http://testing.example.test');
});

test('certbot output parser prefers dply preflight hints', function (): void {
    $output = "[dply] ACME preflight failed: http://testing.example.test/.well-known/acme-challenge/ returned HTTP 503 via local port 80.\nDPLY_EXIT:2";

    expect(CertbotOutputParser::failureSummary($output))
        ->toContain('[dply] ACME preflight failed');
});

test('a skipped renewal is recognised from certbot output', function (string $output): void {
    expect(CertbotOutputParser::notYetDueForRenewal($output))->toBeTrue();
})->with([
    'classic phrasing' => [
        "Certificate not yet due for renewal; no action taken.\nDPLY_EXIT:0",
    ],
    'keep-until-expiring phrasing' => [
        "Keeping the existing certificate\nDPLY_EXIT:0",
    ],
    'wrapped across whitespace' => [
        "Certificate is not yet due for renewal\n\nDPLY_EXIT:0",
    ],
]);

test('a real issuance is not mistaken for a skipped renewal', function (string $output): void {
    expect(CertbotOutputParser::notYetDueForRenewal($output))->toBeFalse();
})->with([
    'fresh issue' => [
        "Successfully received certificate.\nCertificate is saved at: /etc/letsencrypt/live/app.example.com/fullchain.pem\nDPLY_EXIT:0",
    ],
    'forced renewal' => [
        "Renewing an existing certificate for app.example.com\nDPLY_EXIT:0",
    ],
    'failure' => [
        "Error: some challenge failed\nDPLY_EXIT:1",
    ],
]);
