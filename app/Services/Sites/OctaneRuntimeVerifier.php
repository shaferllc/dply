<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Confirms that Laravel Octane is not just declared in composer.json but is
 * actually INSTALLED (the app boots far enough to expose `octane:reload`) AND
 * WORKING (its long-running server is the live runtime serving this site) on
 * the box — then records the verdict on `meta.octane_verified` so the
 * render-path advisor can gate the "Reload Octane workers" suggestion on a
 * cached fact instead of a composer guess.
 *
 * The pipeline advisor runs in the Livewire render path and must never SSH
 * (see the no-render-path-SSH rule), so the actual probe runs in a queued job
 * ({@see \App\Jobs\VerifySiteOctaneJob}) — or piggybacks on the already-open
 * SSH connection of the "Optimize pipeline" scan — and writes the verdict
 * here. This class owns the probe script, the parser, and the read helpers so
 * every call site agrees on what "installed and working" means.
 */
final class OctaneRuntimeVerifier
{
    /** Meta key holding the last verification verdict. */
    public const META_KEY = 'octane_verified';

    /** A verdict older than this is treated as stale and re-probed. */
    public const STALE_AFTER_MINUTES = 30;

    /**
     * One bash probe printing KEY=VALUE markers. We trust the markers, not the
     * exit code: `octane:reload` listed in `php artisan list` means the package
     * is installed and the app boots; a listener on the configured Octane port
     * (or a running `octane:start` process) means it's actually serving.
     */
    public static function probeScript(string $dir, ?int $port): string
    {
        $cd = escapeshellarg($dir);
        $portLiteral = $port !== null && $port > 0 ? (string) $port : '';

        return <<<BASH
            INSTALLED=0; RUNNING=0; PORT="{$portLiteral}"
            if cd {$cd} 2>/dev/null && [ -f artisan ]; then
                if php artisan list 2>/dev/null | grep -q 'octane:reload'; then INSTALLED=1; fi
            fi
            if [ -n "\$PORT" ] && command -v ss >/dev/null 2>&1 \\
                && ss -ltn 2>/dev/null | grep -qE "[:.]\$PORT[[:space:]]"; then
                RUNNING=1
            fi
            if [ "\$RUNNING" = "0" ] && pgrep -f 'artisan octane:(start|frankenphp)' >/dev/null 2>&1; then
                RUNNING=1
            fi
            echo "DPLY_OCTANE_INSTALLED=\$INSTALLED"
            echo "DPLY_OCTANE_RUNNING=\$RUNNING"
            BASH;
    }

    /**
     * Turn probe stdout into a verdict. `ok` (the thing the suggestion gates on)
     * requires BOTH installed and running — Octane present in composer but not
     * the live runtime is exactly the false positive we're suppressing.
     *
     * @return array{ok: bool, installed: bool, running: bool, reason: string}
     */
    public static function interpret(string $output): array
    {
        $installed = (bool) preg_match('/DPLY_OCTANE_INSTALLED=1/', $output);
        $running = (bool) preg_match('/DPLY_OCTANE_RUNNING=1/', $output);
        $ok = $installed && $running;

        $reason = match (true) {
            $ok => 'Octane is installed and serving the site.',
            ! $installed => 'octane:reload not available — Octane is not installed / the app does not boot.',
            default => 'Octane is installed but its server is not running for this site.',
        };

        return ['ok' => $ok, 'installed' => $installed, 'running' => $running, 'reason' => $reason];
    }

    /**
     * Persist a verdict onto the site's meta.
     *
     * @param  array{ok: bool, installed: bool, running: bool, reason: string}  $verdict
     */
    public static function persist(Site $site, array $verdict): void
    {
        $meta = ($site->meta );
        $meta[self::META_KEY] = [
            'ok' => $verdict['ok'],
            'installed' => $verdict['installed'],
            'running' => $verdict['running'],
            'reason' => $verdict['reason'],
            'at' => Carbon::now()->toIso8601String(),
        ];
        $site->forceFill(['meta' => $meta])->save();
    }

    /** The verified-working gate the advisor reads — true only on a fresh OK verdict. */
    public static function verifiedWorking(Site $site): bool
    {
        return (data_get($site->meta, self::META_KEY.'.ok') === true) && ! self::isStale($site);
    }

    /**
     * Should we (re)probe? True when there's no verdict yet or the last one has
     * aged past {@see STALE_AFTER_MINUTES} — used to debounce the wire:init
     * trigger so we don't SSH on every render.
     */
    public static function isStale(Site $site): bool
    {
        $at = data_get($site->meta, self::META_KEY.'.at');
        if (! is_string($at) || $at === '') {
            return true;
        }

        try {
            return Carbon::parse($at)->lt(Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));
        } catch (\Throwable) {
            return true;
        }
    }
}
