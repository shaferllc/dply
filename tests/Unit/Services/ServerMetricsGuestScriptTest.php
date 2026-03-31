<?php

namespace Tests\Unit\Services;

use App\Services\Servers\ServerMetricsGuestScript;
use Tests\TestCase;

class ServerMetricsGuestScriptTest extends TestCase
{
    public function test_install_script_deploys_via_base64_file_and_keeps_apt_block(): void
    {
        $guest = app(ServerMetricsGuestScript::class);
        $script = $guest->monitoringPrerequisitesInstallScript();

        $this->assertStringContainsString('apt-get', $script);
        $this->assertStringContainsString("cat <<'DPLY_B64_FILE'", $script);
        $this->assertStringContainsString('base64 -d "$TMP_B64"', $script);
        $this->assertStringContainsString('.dply/bin/server-metrics-snapshot.py', $script);
        $this->assertStringContainsString('chmod 755', $script);
    }

    public function test_python_body_matches_file_without_shebang(): void
    {
        $guest = app(ServerMetricsGuestScript::class);
        $body = $guest->pythonBodyForInlineFallback();

        $this->assertStringNotContainsString('#!', $body);
        $this->assertStringContainsString('def main', $body);
        $this->assertStringContainsString('json.dumps', $body);
    }

    public function test_bundled_sha256_is_64_hex_chars(): void
    {
        $guest = app(ServerMetricsGuestScript::class);
        $sha = $guest->bundledSha256();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sha);
    }

    public function test_deploy_only_script_has_no_apt_block(): void
    {
        $guest = app(ServerMetricsGuestScript::class);
        $script = $guest->guestScriptDeployOnlyScript();

        $this->assertStringNotContainsString('apt-get', $script);
        $this->assertStringContainsString('base64 -d', $script);
    }
}
