<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Services\Servers\MiseInstallScriptBuilder;
use PHPUnit\Framework\TestCase;

class MiseInstallScriptBuilderTest extends TestCase
{
    public function test_install_lines_are_idempotent_with_command_v_guard(): void
    {
        $lines = (new MiseInstallScriptBuilder)->installLines();
        $script = implode("\n", $lines);

        $this->assertStringContainsString('command -v mise', $script);
        $this->assertStringContainsString('skipping installer', $script);
    }

    public function test_install_lines_use_apt_repository_with_signed_key(): void
    {
        $lines = (new MiseInstallScriptBuilder)->installLines();
        $script = implode("\n", $lines);

        $this->assertStringContainsString('mise.jdx.dev/gpg-key.pub', $script);
        $this->assertStringContainsString('/etc/apt/keyrings/mise-archive-keyring.gpg', $script);
        $this->assertStringContainsString('mise.jdx.dev/deb', $script);
        $this->assertStringContainsString('apt-get install -y --no-install-recommends mise', $script);
    }

    public function test_install_lines_wait_for_apt_locks_before_every_apt_call(): void
    {
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
            $this->assertSame(
                'dply_wait_for_apt_locks',
                $prev,
                "Line '{$line}' must be preceded by dply_wait_for_apt_locks; got '{$prev}'"
            );
        }
    }

    public function test_install_lines_target_dpkg_architecture_dynamically(): void
    {
        // Hardcoding amd64 would break on arm64 servers (DO premium SKUs,
        // AWS Graviton, etc.). The script must read the system arch.
        $lines = (new MiseInstallScriptBuilder)->installLines();
        $script = implode("\n", $lines);

        $this->assertStringContainsString('dpkg --print-architecture', $script);
        $this->assertStringNotContainsString('arch=amd64]', $script);
    }

    public function test_activate_for_user_lines_skip_when_marker_already_present(): void
    {
        $lines = (new MiseInstallScriptBuilder)->activateForUserLines('dply');
        $script = implode("\n", $lines);

        $this->assertStringContainsString('grep -qF', $script);
        $this->assertStringContainsString('# dply: mise activation', $script);
        $this->assertStringContainsString('eval "$(mise activate bash)"', $script);
        $this->assertStringContainsString("'dply'", $script);
    }

    public function test_activate_for_user_chowns_bashrc_back_to_user(): void
    {
        // We append as root (provision script runs as root); chown back to
        // the deploy user so subsequent edits / tooling don't trip on
        // root-owned files in the user's home.
        $lines = (new MiseInstallScriptBuilder)->activateForUserLines('deployer');
        $script = implode("\n", $lines);

        $this->assertStringContainsString("chown 'deployer':'deployer'", $script);
    }

    public function test_install_runtime_emits_mise_use_global_for_supported_runtimes(): void
    {
        $builder = new MiseInstallScriptBuilder;

        foreach (['node' => '22.7.0', 'python' => '3.12', 'ruby' => '3.3.4', 'go' => '1.22'] as $runtime => $version) {
            $lines = $builder->installRuntimeForUserLines('dply', $runtime, $version);
            $script = implode("\n", $lines);

            $this->assertNotEmpty($lines, "{$runtime} should produce install lines");
            $this->assertStringContainsString("mise use --global {$runtime}@{$version}", $script);
            $this->assertStringContainsString("sudo -u 'dply'", $script);
        }
    }

    public function test_install_runtime_skips_php_silently(): void
    {
        // PHP intentionally uses the ondrej/php apt path, not mise (see
        // strategy memo). Asking mise to install PHP is a no-op, not an
        // error, so a caller can pass through whatever runtime list it
        // has without filtering.
        $lines = (new MiseInstallScriptBuilder)->installRuntimeForUserLines('dply', 'php', '8.4');
        $this->assertSame([], $lines);
    }

    public function test_install_runtime_skips_unknown_runtime(): void
    {
        $lines = (new MiseInstallScriptBuilder)->installRuntimeForUserLines('dply', 'erlang', '27');
        $this->assertSame([], $lines);
    }

    public function test_install_runtime_skips_when_version_is_blank(): void
    {
        $lines = (new MiseInstallScriptBuilder)->installRuntimeForUserLines('dply', 'node', '   ');
        $this->assertSame([], $lines);
    }
}
