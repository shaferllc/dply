<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\ServerProvisionArtifact;
use App\Support\Servers\ClassifyProvisionFailure;
use App\Support\Servers\ProvisionVerificationSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionJourneySummariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_summary_uses_metadata_checks(): void
    {
        $artifact = new ServerProvisionArtifact([
            'metadata' => [
                'checks' => [
                    ['key' => 'nginx', 'status' => 'ok', 'detail' => 'Check passed'],
                    ['key' => 'php-fpm', 'status' => 'failed', 'detail' => 'Service inactive'],
                ],
            ],
        ]);

        $summary = ProvisionVerificationSummary::fromArtifact($artifact);

        $this->assertCount(2, $summary);
        $this->assertSame('Nginx config test', $summary[0]['label']);
        $this->assertSame('failed', $summary[1]['status']);
    }

    public function test_failure_classifier_prefers_verification_failure(): void
    {
        $result = ClassifyProvisionFailure::classify(
            'Running verification checks',
            'systemctl status',
            [['key' => 'nginx', 'label' => 'Nginx config test', 'status' => 'failed', 'detail' => 'Check failed']],
            'attempted',
        );

        $this->assertSame('verification_failure', $result['code']);
    }

    public function test_failure_classifier_detects_ppa_unreachable(): void
    {
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

        $this->assertSame('package_repo_unreachable', $result['code']);
        $this->assertStringContainsString('transient', $result['detail']);
    }

    public function test_failure_classifier_detects_apt_failed_to_fetch(): void
    {
        $result = ClassifyProvisionFailure::classify(
            'Installing packages',
            'W: Failed to fetch http://archive.ubuntu.com/ubuntu/dists/noble/InRelease',
            [],
            null,
        );

        $this->assertSame('package_repo_unreachable', $result['code']);
    }
}
