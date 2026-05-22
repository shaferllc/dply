<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Servers\SchedulerWrapperScript;
use Tests\TestCase;

class SchedulerWrapperScriptTest extends TestCase
{
    public function test_bundled_sha256_is_64_hex_chars(): void
    {
        $svc = app(SchedulerWrapperScript::class);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $svc->bundledSha256());
    }

    public function test_install_fragment_decodes_and_pins_wrapper_to_system_path(): void
    {
        $svc = app(SchedulerWrapperScript::class);
        $fragment = $svc->installBashFragment('dply');

        // Directory pre-creation owned by the deploy user.
        $this->assertStringContainsString(
            "install -d -m 0755 -o 'dply' -g 'dply' /var/lib/dply/scheduler-heartbeats",
            $fragment,
        );
        $this->assertStringContainsString('/var/lib/dply/scheduler-locks', $fragment);
        $this->assertStringContainsString('/var/lib/dply/scheduler-state', $fragment);

        // Wrapper installs system-wide as root.
        $this->assertStringContainsString('/usr/local/bin/dply-scheduler-tick', $fragment);
        $this->assertStringContainsString("install -m 0755 -o root -g root", $fragment);

        // SHA-256 pinning prevents partial / corrupt deploys.
        $this->assertStringContainsString($svc->bundledSha256(), $fragment);
        $this->assertStringContainsString('sha256sum', $fragment);

        // Base64 + atomic temp-then-mv pattern is preserved.
        $this->assertStringContainsString("cat <<'DPLY_SCHED_B64'", $fragment);
        $this->assertStringContainsString('base64 -d "$SCHED_TMP_B64"', $fragment);
    }

    public function test_install_fragment_uses_escaped_user_argument(): void
    {
        $svc = app(SchedulerWrapperScript::class);

        // escapeshellarg always single-quotes the user — both safe and the
        // value we expect to render.
        $fragment = $svc->installBashFragment('dply');
        $this->assertStringContainsString("-o 'dply' -g 'dply'", $fragment);
    }
}
