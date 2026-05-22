<?php


namespace Tests\Unit\Services\ServerMetricsGuestScriptTest;
use App\Services\Servers\ServerMetricsGuestScript;

test('install script deploys via base64 file and keeps apt block', function () {
    $guest = app(ServerMetricsGuestScript::class);
    $script = $guest->monitoringPrerequisitesInstallScript();

    $this->assertStringContainsString('apt-get', $script);
    $this->assertStringContainsString("cat <<'DPLY_B64_FILE'", $script);
    $this->assertStringContainsString('base64 -d "$TMP_B64"', $script);
    $this->assertStringContainsString('.dply/bin/server-metrics-snapshot.py', $script);
    $this->assertStringContainsString('chmod 755', $script);
});

test('python body matches file without shebang', function () {
    $guest = app(ServerMetricsGuestScript::class);
    $body = $guest->pythonBodyForInlineFallback();

    $this->assertStringNotContainsString('#!', $body);
    $this->assertStringContainsString('def main', $body);
    $this->assertStringContainsString('json.dumps', $body);
});

test('bundled sha256 is 64 hex chars', function () {
    $guest = app(ServerMetricsGuestScript::class);
    $sha = $guest->bundledSha256();

    expect($sha)->toMatch('/^[a-f0-9]{64}$/');
});

test('deploy only script has no apt block', function () {
    $guest = app(ServerMetricsGuestScript::class);
    $script = $guest->guestScriptDeployOnlyScript();

    $this->assertStringNotContainsString('apt-get', $script);
    $this->assertStringContainsString('base64 -d', $script);
});
