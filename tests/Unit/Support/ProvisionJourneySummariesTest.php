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
}
