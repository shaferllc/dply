<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerPhpOpcacheProfile;

/**
 * Renders a {@see ServerPhpOpcacheProfile} into an `opcache.ini` body and
 * ships it to the host. Sibling to {@see ServerPhpConfigEditor} — that
 * service edits raw php.ini files; this one owns the structured OPcache
 * surface so operators don't have to remember every knob name.
 *
 * Target file: `/etc/php/{version}/mods-available/opcache.ini`. Debian
 * symlinks this into both `/etc/php/{version}/cli/conf.d/10-opcache.ini`
 * and `/etc/php/{version}/fpm/conf.d/10-opcache.ini`, so editing the
 * mods-available copy reaches both SAPIs in one write.
 */
class ServerOpcacheConfigEditor
{
    /**
     * Render the ini body for a profile. Pure function, easy to test.
     */
    public function renderIni(ServerPhpOpcacheProfile $profile): string
    {
        $enabled = $profile->enabled ? '1' : '0';
        $validate = $profile->validate_timestamps ? '1' : '0';
        $memMb = max(8, (int) $profile->memory_consumption_mb);
        $internedMb = max(0, (int) $profile->interned_strings_buffer_mb);
        $files = max(200, (int) $profile->max_accelerated_files);
        $revalidate = max(0, (int) $profile->revalidate_freq);

        $jit = in_array($profile->jit, ServerPhpOpcacheProfile::JIT_MODES, true) ? $profile->jit : 'off';
        $jitBufMb = max(0, (int) $profile->jit_buffer_size_mb);

        // PHP's opcache.jit value is either `off` or a 4-digit mask. `tracing`
        // and `function` are the two presets the workspace exposes — they map
        // to `tracing` (1255) and `function` (1205) in PHP 8.x. We use the
        // string aliases since PHP 8.0+ accepts them directly and they read
        // cleanly in the ini file.
        $jitDirective = $jit === 'off' ? '0' : $jit;
        $jitBufBytes = $jitBufMb > 0 ? ($jitBufMb.'M') : '0';

        $extra = trim((string) ($profile->extra_ini_raw ?? ''));
        $extraBlock = $extra === ''
            ? ''
            : "\n; --- operator overrides ---\n{$extra}\n";

        return <<<INI
; Managed by Dply — opcache.ini for PHP {$profile->php_version}
; Do not edit by hand; use the OPcache profile in the Caches workspace.
zend_extension=opcache.so

opcache.enable={$enabled}
opcache.enable_cli={$enabled}
opcache.memory_consumption={$memMb}
opcache.interned_strings_buffer={$internedMb}
opcache.max_accelerated_files={$files}
opcache.validate_timestamps={$validate}
opcache.revalidate_freq={$revalidate}
opcache.fast_shutdown=1
opcache.save_comments=1

; JIT — disabled by default. Tracing/function modes carry real-world stability
; risk on long-running PHP-FPM workers; operators opt in explicitly.
opcache.jit={$jitDirective}
opcache.jit_buffer_size={$jitBufBytes}
{$extraBlock}
INI;
    }

    /**
     * Filesystem path of the ini file we write. mods-available is the right
     * target on Debian/Ubuntu — phpenmod links it into both cli/conf.d and
     * fpm/conf.d so one write touches both SAPIs.
     */
    public function targetPath(string $phpVersion): string
    {
        return sprintf('/etc/php/%s/mods-available/opcache.ini', $phpVersion);
    }

    /**
     * Apply a profile to the server: write the ini file, run `phpenmod
     * opcache` (idempotent — succeeds even if already enabled), and reload
     * `php{ver}-fpm`. Returns the trimmed combined stdout/stderr from the
     * remote script so the caller (the apply job) can record it on
     * `last_error` when something goes sideways.
     */
    public function apply(Server $server, ServerPhpOpcacheProfile $profile): string
    {
        $body = $this->renderIni($profile);
        $path = $this->targetPath($profile->php_version);
        $version = $profile->php_version;

        // Heredoc-escaping: ini body is shipped via `cat > path << 'EOF'` so
        // shell expansion is suppressed. The marker uses a `DPLY_` prefix +
        // random-ish suffix to avoid the (very rare) collision with operator
        // override text — extra_ini_raw is operator-supplied.
        $marker = 'DPLY_OPCACHE_'.bin2hex(random_bytes(6));

        $script = <<<BASH
set -euo pipefail
cat > {$path}.dply.tmp <<'{$marker}'
{$body}
{$marker}
mv {$path}.dply.tmp {$path}
chown root:root {$path}
chmod 0644 {$path}

# phpenmod is idempotent — succeeds even if opcache is already enabled.
# Without it a fresh install would have opcache.so loaded but the conf.d
# symlink missing, so the directives never take effect.
phpenmod -v {$version} opcache 2>&1 || true

# Reload PHP-FPM for this version. systemctl reload is the right verb —
# changes to opcache settings only take effect on FPM master reload (worker
# restart), and `reload` triggers the SIGUSR2 dance for php-fpm without
# dropping in-flight requests. The systemctl unit name on Debian/Ubuntu is
# php{version}-fpm.
if systemctl list-unit-files | grep -q "^php{$version}-fpm.service"; then
    systemctl reload php{$version}-fpm 2>&1 || systemctl restart php{$version}-fpm
fi
BASH;

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec($script, 120),
        );
    }
}
