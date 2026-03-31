<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupervisorProvisionerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function build_ini_includes_expert_fields_when_set(): void
    {
        $p = new SupervisorProgram([
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'command' => 'php artisan horizon',
            'directory' => '/var/www/app/current',
            'user' => 'www-data',
            'numprocs' => 1,
            'priority' => 100,
            'startsecs' => 2,
            'stopwaitsecs' => 120,
            'autorestart' => 'unexpected',
            'redirect_stderr' => false,
            'stderr_logfile' => '/tmp/e.log',
        ]);
        $p->exists = true;

        $ini = (new SupervisorProvisioner)->buildIni($p);

        $this->assertStringContainsString('priority=100', $ini);
        $this->assertStringContainsString('startsecs=2', $ini);
        $this->assertStringContainsString('stopwaitsecs=120', $ini);
        $this->assertStringContainsString('autorestart=unexpected', $ini);
        $this->assertStringContainsString('redirect_stderr=false', $ini);
        $this->assertStringContainsString('stderr_logfile=/tmp/e.log', $ini);
    }

    #[Test]
    public function analyze_status_detects_burst_lines(): void
    {
        $server = Server::factory()->create();
        $prog = SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'slug' => 'worker',
            'program_type' => 'queue',
            'command' => 'php artisan queue:work',
            'directory' => '/var/www',
            'user' => 'www-data',
            'numprocs' => 1,
            'is_active' => true,
        ]);

        $out = 'dply-sv-'.$prog->id.':dply-sv-'.$prog->id.'   BACKOFF  Exited too quickly (process log may have details)';

        $analysis = (new SupervisorProvisioner)->analyzeStatusForManagedPrograms($server->fresh(), $out);

        $this->assertFalse($analysis['ok']);
        $this->assertNotEmpty($analysis['burst_lines']);
    }

    #[Test]
    public function manage_supervisor_service_rejects_invalid_action(): void
    {
        $server = Server::factory()->make();
        $this->expectException(\InvalidArgumentException::class);
        (new SupervisorProvisioner)->manageSupervisorService($server, 'invalid');
    }
}
