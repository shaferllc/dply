<?php

declare(strict_types=1);

use App\Models\ServerWildcardCertificate;
use Illuminate\Support\Carbon;

test('issuance is stale only after the issuer job timeout elapses', function (): void {
    $fresh = new ServerWildcardCertificate([
        'status' => ServerWildcardCertificate::STATUS_ISSUING,
        'last_requested_at' => now()->subMinutes(5),
    ]);
    $stale = new ServerWildcardCertificate([
        'status' => ServerWildcardCertificate::STATUS_ISSUING,
        'last_requested_at' => now()->subMinutes(15),
    ]);
    $failed = new ServerWildcardCertificate([
        'status' => ServerWildcardCertificate::STATUS_FAILED,
        'last_requested_at' => now()->subHour(),
    ]);

    expect($fresh->issuanceIsStale())->toBeFalse()
        ->and($stale->issuanceIsStale())->toBeTrue()
        ->and($failed->issuanceIsStale())->toBeFalse();
});

test('issuing with no start timestamp is treated as stale', function (): void {
    $orphan = new ServerWildcardCertificate([
        'status' => ServerWildcardCertificate::STATUS_ISSUING,
        'last_requested_at' => null,
        'updated_at' => null,
    ]);

    expect($orphan->issuanceIsStale())->toBeTrue();
});

test('issuing falls back to updated_at when last_requested_at is missing', function (): void {
    $recent = new ServerWildcardCertificate([
        'status' => ServerWildcardCertificate::STATUS_ISSUING,
        'last_requested_at' => null,
    ]);
    $recent->updated_at = Carbon::now()->subMinutes(2);

    $old = new ServerWildcardCertificate([
        'status' => ServerWildcardCertificate::STATUS_ISSUING,
        'last_requested_at' => null,
    ]);
    $old->updated_at = Carbon::now()->subMinutes(20);

    expect($recent->issuanceIsStale())->toBeFalse()
        ->and($old->issuanceIsStale())->toBeTrue();
});
