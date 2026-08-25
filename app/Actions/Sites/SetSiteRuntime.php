<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Enums\SiteType;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Site;
use App\Services\Servers\MiseInstallScriptBuilder;
use InvalidArgumentException;

/**
 * Change a site's runtime, then make the box agree with the record.
 *
 * Switching runtime is never just a column write. The vhost builder branches on
 * it — {@see \App\Services\Sites\NginxSiteConfigBuilder} emits `fastcgi_pass` to
 * a PHP-FPM socket for php and `proxy_pass` to an internal port for everything
 * else, and {@see \App\Services\Sites\SiteSystemdUnitBuilder} needs a start
 * command and a port to run the process at all. Saving `runtime = node` without
 * re-applying leaves nginx pointing at a socket that will never exist.
 *
 * That was a real, shipped hazard: `dply:site:set-runtime` did
 * `$site->fill($changes)->save()` and nothing else. Both that command and the
 * site Runtime tab now route through here, so the required-field check and the
 * re-apply can't be skipped by picking a different entrance.
 */
class SetSiteRuntime
{
    /** Runtimes that are not mise-managed but are still valid site runtimes. */
    private const NON_MISE_RUNTIMES = ['php', 'static'];

    /**
     * Runtimes served by a long-running process behind a reverse proxy, so they
     * need somewhere to send traffic before the vhost can be written.
     *
     * @return list<string>
     */
    public static function proxiedRuntimes(): array
    {
        return MiseInstallScriptBuilder::SUPPORTED_RUNTIMES;
    }

    /** @return list<string> */
    public static function allowedRuntimes(): array
    {
        return array_values(array_merge(self::NON_MISE_RUNTIMES, self::proxiedRuntimes()));
    }

    /**
     * Apply a runtime change and re-apply the site's webserver config.
     *
     * $changes accepts any of: runtime, runtime_version, build_command,
     * start_command, internal_port. Only the keys present are written, so a
     * caller editing just the version doesn't clear the start command.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws InvalidArgumentException when the target runtime is unknown, or
     *                                  when it needs a field the site would
     *                                  still be missing after the change.
     */
    public function handle(Site $site, array $changes, ?string $userId = null): Site
    {
        $targetRuntime = array_key_exists('runtime', $changes)
            ? strtolower(trim((string) $changes['runtime']))
            : (string) ($site->runtime ?? '');

        if ($targetRuntime === '') {
            throw new InvalidArgumentException('A runtime is required.');
        }

        if (! in_array($targetRuntime, self::allowedRuntimes(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown runtime "%s". Allowed: %s',
                $targetRuntime,
                implode(', ', self::allowedRuntimes()),
            ));
        }

        $changes['runtime'] = $targetRuntime;

        // Resolve the post-change values before validating, so the check sees
        // what the site WILL look like rather than what it looks like now — a
        // switch that supplies start_command in the same call must pass.
        $resolved = fn (string $key) => array_key_exists($key, $changes)
            ? $changes[$key]
            : $site->{$key};

        if (in_array($targetRuntime, self::proxiedRuntimes(), true)) {
            $missing = [];

            if (trim((string) $resolved('start_command')) === '') {
                $missing[] = 'a start command';
            }

            $port = (int) ($resolved('internal_port') ?: $resolved('app_port'));
            if ($port <= 0 || $port > 65535) {
                $missing[] = 'an internal port';
            }

            if ($missing !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Switching to %s needs %s — the web server has nothing to proxy to without it.',
                    $targetRuntime,
                    implode(' and ', $missing),
                ));
            }
        }

        $changes['type'] = self::siteTypeFor($targetRuntime);

        $site->fill($changes)->save();

        // Re-apply so the vhost (and any systemd unit) matches the new runtime.
        // Queued, never inline: this is SSH work and must stay out of the
        // request path.
        ApplySiteWebserverConfigJob::dispatch((string) $site->id, $userId);

        return $site;
    }

    /**
     * SiteType only has cases for php / static / node; the other mise runtimes
     * (python, go, bun, deno, java) are all reverse-proxied the same way node
     * is, so they map to Node rather than inventing a case per language. The
     * canonical value stays on `runtime` — `type` is the coarse shape.
     */
    public static function siteTypeFor(string $runtime): SiteType
    {
        return match ($runtime) {
            'php' => SiteType::Php,
            'static' => SiteType::Static,
            default => SiteType::Node,
        };
    }
}
