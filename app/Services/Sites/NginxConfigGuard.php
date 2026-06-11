<?php

declare(strict_types=1);

namespace App\Services\Sites;

use Dply\NginxConfig\ConfigDiff;

/**
 * The "don't silently clobber a hand-edited vhost" gate, run immediately BEFORE
 * dply overwrites a site's nginx config on a box. It is the structural sibling
 * of {@see SiteEnvWriteGuard}: a cheap, in-process check that compares what's on
 * disk against what we're about to write and refuses to let an overwrite quietly
 * destroy manual changes.
 *
 * It parses both configs into directive trees ({@see ConfigDiff}, our fork of
 * crossplane) and reports every directive present in the current file that the
 * incoming file lacks — i.e. the directives the overwrite would delete.
 *
 * NOTE: this is NOT a syntax validator. crossplane's lexer tolerates some
 * malformed input, so `nginx -t` on the server stays the authority on whether a
 * config loads. This guard only concerns itself with *foreign edits*.
 */
final class NginxConfigGuard
{
    /**
     * Marker comment dply stamps on the vhosts it writes. Its absence on a
     * non-empty on-disk file is a strong signal the file was authored by hand.
     */
    public const OWNERSHIP_MARKER = '# dply-managed';

    public const MODE_WARN = 'warn';

    public const MODE_ABORT = 'abort';

    public const MODE_OFF = 'off';

    /**
     * Directive signatures present in $current that the $incoming config would
     * remove. Empty when there is nothing on disk yet, when the incoming config
     * is a structural superset, or when either side fails to parse (we never
     * block on a parse failure — `nginx -t` is the real gate).
     *
     * @return list<string>
     */
    public function foreignDirectives(?string $current, string $incoming): array
    {
        $current = trim((string) $current);
        if ($current === '') {
            return [];
        }

        try {
            return ConfigDiff::lostOnOverwrite($current, $incoming);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Whether a config carries dply's ownership marker. */
    public function isManaged(?string $config): bool
    {
        return $config !== null && str_contains($config, self::OWNERSHIP_MARKER);
    }

    /**
     * Prepend the ownership marker to a generated config if it isn't already
     * present, so future read-backs can tell a dply-written file from a
     * hand-authored one. The marker is stable per site (no timestamp) so it never
     * makes an otherwise-unchanged config look "changed".
     */
    public function stamp(string $config, string $siteLabel): string
    {
        if ($this->isManaged($config)) {
            return $config;
        }

        return self::OWNERSHIP_MARKER.' — generated for '.$siteLabel
            .'; edits here are overwritten on the next deploy.'."\n".$config;
    }

    /** Resolve the configured guard mode, defaulting to warn. */
    public function mode(): string
    {
        $mode = (string) config('dply.nginx_overwrite_guard', self::MODE_WARN);

        return in_array($mode, [self::MODE_WARN, self::MODE_ABORT, self::MODE_OFF], true)
            ? $mode
            : self::MODE_WARN;
    }

    /**
     * Human-readable summary line for the deploy console / exception message.
     *
     * @param  list<string>  $foreign
     */
    public function summarize(array $foreign): string
    {
        $shown = array_slice($foreign, 0, 10);
        $lines = array_map(static fn (string $d): string => '  • '.$d, $shown);
        if (count($foreign) > count($shown)) {
            $lines[] = '  • … and '.(count($foreign) - count($shown)).' more';
        }

        return __('This vhost has :count manual directive(s) not produced by dply; overwriting will remove them:', ['count' => count($foreign)])
            ."\n".implode("\n", $lines);
    }
}
