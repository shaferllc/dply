<?php

declare(strict_types=1);

namespace App\Modules\Scaffold\Services;

use App\Models\Server;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;

/**
 * Verifies that the binaries each scaffold pipeline relies on are
 * present on the target server, and self-heals when they are not.
 *
 * Per Q10, scaffolding never dead-ends: if a server happens to be
 * missing wp-cli (because it was provisioned with the Laravel-app
 * preset and the operator now wants to scaffold WordPress on it),
 * scaffolding installs the binary as step 0 of the pipeline rather
 * than refusing.
 *
 * This service is intentionally idempotent and side-effect-light:
 * if a binary is already present, ensure*() is a no-op (one ssh check).
 * The new "WordPress" preset and the existing "Polyglot host" preset
 * already pre-install these as part of normal server provisioning;
 * this service is the safety net for "wrong-preset" servers.
 */
class ScaffoldPrerequisites
{
    /** Where the wp-cli phar lives once installed. */
    public const WP_CLI_PATH = '/usr/local/bin/wp';

    public const COMPOSER_PATH = '/usr/local/bin/composer';

    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * Ensure wp-cli is present and executable. Installs if missing.
     */
    public function ensureWpCli(Server $server): PrerequisiteResult
    {
        if ($this->binaryExists($server, self::WP_CLI_PATH)) {
            return PrerequisiteResult::alreadyPresent('wp-cli');
        }

        $script = <<<'BASH'
        # Install wp-cli — the latest official phar from wp-cli.org.
        # Mirrors the install snippet from https://wp-cli.org/#installing
        # but routes via the system curl + sudo install for global access.
        set -euo pipefail
        curl --silent --show-error --location --output /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
        chmod +x /tmp/wp-cli.phar
        sudo mv /tmp/wp-cli.phar /usr/local/bin/wp
        wp --info --allow-root
        BASH;

        try {
            $out = $this->executor->runInlineBash(
                server: $server,
                name: 'scaffold-prerequisites:install-wp-cli',
                inlineBash: $script,
                timeoutSeconds: 60,
            );
        } catch (\Throwable $e) {
            Log::warning('wp-cli install failed', [
                'server_id' => $server->getKey(),
                'error' => $e->getMessage(),
            ]);

            return PrerequisiteResult::failed('wp-cli', $e->getMessage());
        }

        if ($out->getExitCode() !== 0) {
            return PrerequisiteResult::failed(
                'wp-cli',
                'wp-cli install exited '.var_export($out->getExitCode(), true).': '.$out->getBuffer(),
            );
        }

        return PrerequisiteResult::installed('wp-cli');
    }

    /**
     * Ensure composer is present. The Laravel-app and Polyglot presets
     * already install composer; this is the safety net.
     */
    public function ensureComposer(Server $server): PrerequisiteResult
    {
        if ($this->binaryExists($server, self::COMPOSER_PATH)) {
            return PrerequisiteResult::alreadyPresent('composer');
        }

        $script = <<<'BASH'
        # Install Composer using the official installer + signature check.
        # Mirrors getcomposer.org's recommended one-liner.
        set -euo pipefail
        EXPECTED_CHECKSUM="$(curl --silent --show-error https://composer.github.io/installer.sig)"
        curl --silent --show-error -o /tmp/composer-setup.php https://getcomposer.org/installer
        ACTUAL_CHECKSUM="$(sha384sum /tmp/composer-setup.php | awk '{print $1}')"
        if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
            echo "Composer installer checksum mismatch — refusing to run."
            rm -f /tmp/composer-setup.php
            exit 1
        fi
        php /tmp/composer-setup.php --install-dir=/tmp --filename=composer --quiet
        sudo mv /tmp/composer /usr/local/bin/composer
        rm -f /tmp/composer-setup.php
        composer --version
        BASH;

        try {
            $out = $this->executor->runInlineBash(
                server: $server,
                name: 'scaffold-prerequisites:install-composer',
                inlineBash: $script,
                timeoutSeconds: 90,
            );
        } catch (\Throwable $e) {
            Log::warning('composer install failed', [
                'server_id' => $server->getKey(),
                'error' => $e->getMessage(),
            ]);

            return PrerequisiteResult::failed('composer', $e->getMessage());
        }

        if ($out->getExitCode() !== 0) {
            return PrerequisiteResult::failed(
                'composer',
                'composer install exited '.var_export($out->getExitCode(), true).': '.$out->getBuffer(),
            );
        }

        return PrerequisiteResult::installed('composer');
    }

    /**
     * Single check used by tile-rendering code in the wizard (PR 4) so
     * the operator sees "ready" / "we'll install wp-cli first" / "blocked"
     * before they click. Returns null when the binary is present, the
     * binary name otherwise.
     */
    public function missingFor(Server $server, string $framework): ?string
    {
        return match ($framework) {
            'wordpress' => $this->binaryExists($server, self::WP_CLI_PATH) ? null : 'wp-cli',
            'laravel' => $this->binaryExists($server, self::COMPOSER_PATH) ? null : 'composer',
            default => null,
        };
    }

    private function binaryExists(Server $server, string $absolutePath): bool
    {
        try {
            $out = $this->executor->runInlineBash(
                server: $server,
                name: 'scaffold-prerequisites:check',
                inlineBash: 'test -x '.escapeshellarg($absolutePath),
                timeoutSeconds: 15,
            );
        } catch (\Throwable) {
            return false;
        }

        return $out instanceof ProcessOutput && $out->getExitCode() === 0;
    }
}
