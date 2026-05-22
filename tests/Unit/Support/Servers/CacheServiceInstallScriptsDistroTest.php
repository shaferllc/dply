<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Support\Servers\CacheServiceInstallScripts;
use Tests\TestCase;

/**
 * Lock in the per-engine distro-codename whitelist and the shape of the auto-bootstrap install
 * scripts for KeyDB + Dragonfly. The UI gate ({@see ServerCacheServiceHostCapabilities::engineUnsupportedReason})
 * reads `supportedDistroCodenames()` to decide whether to disable the Install button, and the
 * install scripts emit the same whitelist inline — these tests pin them together so a future
 * codename change can't drift the UI and the bash apart silently.
 */
class CacheServiceInstallScriptsDistroTest extends TestCase
{
    public function test_supported_distro_codenames_keydb(): void
    {
        $this->assertSame(
            ['focal', 'jammy', 'bullseye', 'bookworm'],
            CacheServiceInstallScripts::supportedDistroCodenames('keydb'),
        );
    }

    public function test_supported_distro_codenames_dragonfly(): void
    {
        $this->assertSame(
            ['jammy', 'noble', 'bookworm'],
            CacheServiceInstallScripts::supportedDistroCodenames('dragonfly'),
        );
    }

    public function test_supported_distro_codenames_universally_available_engines_return_null(): void
    {
        $this->assertNull(CacheServiceInstallScripts::supportedDistroCodenames('redis'));
        $this->assertNull(CacheServiceInstallScripts::supportedDistroCodenames('valkey'));
        $this->assertNull(CacheServiceInstallScripts::supportedDistroCodenames('memcached'));
    }

    public function test_supported_distro_codenames_rejects_unknown_engine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CacheServiceInstallScripts::supportedDistroCodenames('nonexistent');
    }

    public function test_keydb_install_script_bootstraps_upstream_apt_source(): void
    {
        $script = CacheServiceInstallScripts::installPackageScript('keydb');

        // Codename whitelist matches supportedDistroCodenames('keydb'). Order matters inside the
        // case glob; matching the exact pipe-joined shape catches a drift between the PHP and
        // bash sides of the gate.
        $this->assertStringContainsString('focal|jammy|bullseye|bookworm', $script);

        // Modern signed-by= layout, NOT the legacy /etc/apt/trusted.gpg.d/ drop. If you change
        // this, also update the corresponding uninstall script to remove the new path.
        $this->assertStringContainsString('/etc/apt/keyrings/keydb.gpg', $script);
        $this->assertStringContainsString('signed-by=/etc/apt/keyrings/keydb.gpg', $script);

        // Upstream URL — bumping the host is a deliberate change; if KeyDB ever moves their repo,
        // bump this AND the keyring URL together.
        $this->assertStringContainsString('https://download.keydb.dev/open-source-dist', $script);
        $this->assertStringContainsString('https://download.keydb.dev/open-source-dist/keyring.gpg', $script);

        // Operator-actionable error on unsupported distros (e.g. noble). The exact phrasing here
        // is reused by `engineUnsupportedReason()` — keep them in sync.
        $this->assertStringContainsString("KeyDB upstream doesn't ship for", $script);

        // Idempotency short-circuit and final verification gate both required so a partial
        // apt-install run doesn't slip past `set -e`.
        $this->assertStringContainsString('if ! command -v keydb-server >/dev/null 2>&1; then', $script);
        $this->assertStringContainsString('command -v keydb-server >/dev/null 2>&1 ||', $script);
    }

    public function test_dragonfly_install_script_downloads_pinned_deb_from_github(): void
    {
        config()->set('server_cache.dragonfly_version', 'v1.38.1');

        $script = CacheServiceInstallScripts::installPackageScript('dragonfly');

        // Codename whitelist matches supportedDistroCodenames('dragonfly').
        $this->assertStringContainsString('jammy|noble|bookworm', $script);

        // Architecture gate — Dragonfly only publishes amd64/arm64 .debs.
        $this->assertStringContainsString('amd64|arm64', $script);
        $this->assertStringContainsString('dpkg --print-architecture', $script);

        // Pinned-version URL. The arch is interpolated by bash at runtime ($arch), so we only
        // pin the host + tag here.
        $this->assertStringContainsString(
            'https://github.com/dragonflydb/dragonfly/releases/download/${tag}/dragonfly_${arch}.deb',
            $script,
        );
        $this->assertStringContainsString("tag='v1.38.1'", $script);

        // The two-step dpkg/apt-fix pattern that handles missing transitive deps from a raw .deb.
        $this->assertStringContainsString('apt-get install -f -y', $script);

        $this->assertStringContainsString("Dragonfly doesn't ship a .deb that resolves on", $script);
        $this->assertStringContainsString('command -v dragonfly >/dev/null 2>&1 ||', $script);
    }

    public function test_dragonfly_version_pin_is_honored_from_config(): void
    {
        config()->set('server_cache.dragonfly_version', 'v1.40.0');
        $this->assertStringContainsString("tag='v1.40.0'", CacheServiceInstallScripts::installPackageScript('dragonfly'));

        // Bare version (no `v` prefix) is normalized so config can be set either way without
        // breaking the GitHub URL shape.
        config()->set('server_cache.dragonfly_version', '1.41.0');
        $this->assertStringContainsString("tag='v1.41.0'", CacheServiceInstallScripts::installPackageScript('dragonfly'));
    }
}
