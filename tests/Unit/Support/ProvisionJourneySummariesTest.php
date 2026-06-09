<?php

declare(strict_types=1);

namespace Tests\Unit\Support\ProvisionJourneySummariesTest;

use App\Models\ServerProvisionArtifact;
use App\Support\Servers\ClassifyProvisionFailure;
use App\Support\Servers\ProvisionVerificationSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('verification summary uses metadata checks', function () {
    $artifact = new ServerProvisionArtifact([
        'metadata' => [
            'checks' => [
                ['key' => 'nginx', 'status' => 'ok', 'detail' => 'Check passed'],
                ['key' => 'php-fpm', 'status' => 'failed', 'detail' => 'Service inactive'],
            ],
        ],
    ]);

    $summary = ProvisionVerificationSummary::fromArtifact($artifact);

    expect($summary)->toHaveCount(2);
    expect($summary[0]['label'])->toBe('Nginx config test');
    expect($summary[1]['status'])->toBe('failed');
});
test('failure classifier prefers verification failure', function () {
    $result = ClassifyProvisionFailure::classify(
        'Running verification checks',
        'systemctl status',
        [['key' => 'nginx', 'label' => 'Nginx config test', 'status' => 'failed', 'detail' => 'Check failed']],
        'attempted',
    );

    expect($result['code'])->toBe('verification_failure');
});
test('failure classifier detects ppa unreachable', function () {
    $tail = "Err:5 https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble InRelease\n"
        ."Could not connect to ppa.launchpadcontent.net:443 (185.125.190.80), connection timed out\n"
        ."W: Failed to fetch https://ppa.launchpadcontent.net/...\n"
        ."E: Couldn't find any package by regex 'php8.4-mysql'";

    $result = ClassifyProvisionFailure::classify(
        'Installing PHP 8.4 packages',
        $tail,
        [],
        null,
    );

    expect($result['code'])->toBe('package_repo_unreachable');
    $this->assertStringContainsString('transient', $result['detail']);
});
test('failure classifier detects apt failed to fetch', function () {
    $result = ClassifyProvisionFailure::classify(
        'Installing packages',
        'W: Failed to fetch http://archive.ubuntu.com/ubuntu/dists/noble/InRelease',
        [],
        null,
    );

    expect($result['code'])->toBe('package_repo_unreachable');
});
