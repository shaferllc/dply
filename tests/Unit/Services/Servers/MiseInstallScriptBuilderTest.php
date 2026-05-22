<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\MiseInstallScriptBuilderTest;

use App\Services\Servers\MiseInstallScriptBuilder;

test('install lines are idempotent with command v guard', function () {
    $lines = (new MiseInstallScriptBuilder)->installLines();
    $script = implode("\n", $lines);

    $this->assertStringContainsString('command -v mise', $script);
    $this->assertStringContainsString('skipping installer', $script);
});
test('install lines use apt repository with signed key', function () {
    $lines = (new MiseInstallScriptBuilder)->installLines();
    $script = implode("\n", $lines);

    $this->assertStringContainsString('mise.jdx.dev/gpg-key.pub', $script);
    $this->assertStringContainsString('/etc/apt/keyrings/mise-archive-keyring.gpg', $script);
    $this->assertStringContainsString('mise.jdx.dev/deb', $script);
    $this->assertStringContainsString('apt-get install -y --no-install-recommends mise', $script);
});
test('install lines wait for apt locks before every apt call', function () {
    // Regression: needrestart's DPkg::Post-Invoke hook spawns its own
    // apt-get after every package install, racing the next step's
    // /var/lib/apt/lists/lock and returning exit 100. Every apt-get
    // in installLines() must be immediately preceded by
    // dply_wait_for_apt_locks (provisioner preamble function).
    $lines = (new MiseInstallScriptBuilder)->installLines();

    $trimmed = array_values(array_map('trim', $lines));
    foreach ($trimmed as $i => $line) {
        if (! preg_match('/^apt-get (update|install)\b/', $line)) {
            continue;
        }
        $prev = $trimmed[$i - 1] ?? '';
        expect($prev)->toBe('dply_wait_for_apt_locks', "Line '{$line}' must be preceded by dply_wait_for_apt_locks; got '{$prev}'");
    }
});
test('install lines target dpkg architecture dynamically', function () {
    // Hardcoding amd64 would break on arm64 servers (DO premium SKUs,
    // AWS Graviton, etc.). The script must read the system arch.
    $lines = (new MiseInstallScriptBuilder)->installLines();
    $script = implode("\n", $lines);

    $this->assertStringContainsString('dpkg --print-architecture', $script);
    $this->assertStringNotContainsString('arch=amd64]', $script);
});
test('activate for user lines skip when marker already present', function () {
    $lines = (new MiseInstallScriptBuilder)->activateForUserLines('dply');
    $script = implode("\n", $lines);

    $this->assertStringContainsString('grep -qF', $script);
    $this->assertStringContainsString('# dply: mise activation', $script);
    $this->assertStringContainsString('eval "$(mise activate bash)"', $script);
    $this->assertStringContainsString("'dply'", $script);
});
test('activate for user chowns bashrc back to user', function () {
    // We append as root (provision script runs as root); chown back to
    // the deploy user so subsequent edits / tooling don't trip on
    // root-owned files in the user's home.
    $lines = (new MiseInstallScriptBuilder)->activateForUserLines('deployer');
    $script = implode("\n", $lines);

    $this->assertStringContainsString("chown 'deployer':'deployer'", $script);
});
test('install runtime emits mise use global for supported runtimes', function () {
    $builder = new MiseInstallScriptBuilder;

    foreach (['node' => '22.7.0', 'python' => '3.12', 'ruby' => '3.3.4', 'go' => '1.22'] as $runtime => $version) {
        $lines = $builder->installRuntimeForUserLines('dply', $runtime, $version);
        $script = implode("\n", $lines);

        expect($lines)->not->toBeEmpty("{$runtime} should produce install lines");
        $this->assertStringContainsString("mise use --global {$runtime}@{$version}", $script);
        $this->assertStringContainsString("sudo -u 'dply'", $script);
    }
});
test('install runtime skips php silently', function () {
    // PHP intentionally uses the ondrej/php apt path, not mise (see
    // strategy memo). Asking mise to install PHP is a no-op, not an
    // error, so a caller can pass through whatever runtime list it
    // has without filtering.
    $lines = (new MiseInstallScriptBuilder)->installRuntimeForUserLines('dply', 'php', '8.4');
    expect($lines)->toBe([]);
});
test('install runtime skips unknown runtime', function () {
    $lines = (new MiseInstallScriptBuilder)->installRuntimeForUserLines('dply', 'erlang', '27');
    expect($lines)->toBe([]);
});
test('install runtime skips when version is blank', function () {
    $lines = (new MiseInstallScriptBuilder)->installRuntimeForUserLines('dply', 'node', '   ');
    expect($lines)->toBe([]);
});
