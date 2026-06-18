<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;
use App\Modules\RemoteCli\Services\RiskLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteAuditEvent>
 */
class SiteAuditEventFactory extends Factory
{
    protected $model = SiteAuditEvent::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'action' => 'wp_cli_run',
            'risk' => RiskLevel::MutatingRecoverable,
            'transport' => SiteAuditEvent::TRANSPORT_WEB,
            'summary' => 'Ran wp plugin install woocommerce',
            'payload' => ['command' => 'plugin install', 'args' => ['woocommerce']],
            'result_status' => SiteAuditEvent::RESULT_SUCCESS,
        ];
    }

    public function destructive(): self
    {
        return $this->state(fn () => [
            'risk' => RiskLevel::Destructive,
            'action' => 'snapshot_restored',
            'summary' => 'Restored snapshot snap-2026-05-03-00:00',
        ]);
    }

    public function system(): self
    {
        return $this->state(fn () => [
            'transport' => SiteAuditEvent::TRANSPORT_SYSTEM,
            'user_id' => null,
            'action' => 'scaffold_default_applied',
            'summary' => 'Applied opinionated default: system cron',
        ]);
    }
}
