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
