<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Policy that classifies a redis-cli-style command into one of three buckets:
 *   - read-only (always allowed in the workspace REPL)
 *   - mutating (allowed only when the operator has flipped the unlock toggle)
 *   - blocked (disallowed regardless of unlock — disruptive verbs that should
 *     go through the dedicated buttons or never run from a UI)
 *
 * The policy operates on the first one or two whitespace-separated tokens. We
 * deliberately don't parse arguments (quoting, escaping, key contents) — that
 * is the engine's job. Values are normalized to upper-case for matching.
 */
class CacheServiceCommandPolicy
{
    /**
     * Two-token read-only forms. The first token alone is NOT enough — e.g.
     * `MEMORY` by itself isn't on the read-only list, only `MEMORY USAGE`
     * and `MEMORY STATS` are.
     *
     * @var list<string>
     */
    private const READ_ONLY_PAIRS = [
        'MEMORY USAGE',
        'MEMORY STATS',
        'CONFIG GET',
        'CLIENT LIST',
        'CLIENT ID',
        'CLIENT GETNAME',
        'CLUSTER INFO',
        'CLUSTER NODES',
        'LATENCY HISTORY',
        'LATENCY LATEST',
        'LATENCY GRAPH',
        'SLOWLOG GET',
        'SLOWLOG LEN',
    ];

    /**
     * Single-token read-only commands. These are fine standalone (with
     * whatever arguments the operator passes).
     *
     * @var list<string>
     */
    private const READ_ONLY_SINGLES = [
        'INFO',
        'PING',
        'DBSIZE',
        'EXISTS',
        'TYPE',
        'TTL',
        'PTTL',
        'OBJECT',
        'KEYS',
        'SCAN',
        'RANDOMKEY',
        'GET',
        'MGET',
        'STRLEN',
        'HGET',
        'HGETALL',
        'HKEYS',
        'HVALS',
        'HLEN',
        'HEXISTS',
        'LRANGE',
        'LLEN',
        'LINDEX',
        'SMEMBERS',
        'SCARD',
        'SISMEMBER',
        'ZRANGE',
        'ZREVRANGE',
        'ZRANGEBYSCORE',
        'ZCARD',
        'ZSCORE',
        'BITCOUNT',
        'GETBIT',
        'XLEN',
        'XRANGE',
        'XINFO',
        'XPENDING',
    ];

    /**
     * Hard-blocked verbs. These cannot run from the REPL even with the
     * unlock toggle on — they're disruptive enough that the workspace
     * should never be the entry point.
     *
     * @var list<string>
     */
    private const BLOCKED_PAIRS = [
        'DEBUG SLEEP',
        'DEBUG RESTART',
        'DEBUG SEGFAULT',
        'CLUSTER RESET',
        'CLUSTER FAILOVER',
        'SCRIPT KILL',
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_SINGLES = [
        'SHUTDOWN',
        'MIGRATE',
        'REPLICAOF',
        'SLAVEOF',
        'WAIT',
        'FAILOVER',
        'BGREWRITEAOF',
        'BGSAVE',
        'SAVE',
    ];

    public function isReadOnly(string $command): bool
    {
        $tokens = $this->tokens($command);
        if ($tokens === []) {
            return false;
        }

        if (count($tokens) >= 2) {
            $pair = $tokens[0].' '.$tokens[1];
            if (in_array($pair, self::READ_ONLY_PAIRS, true)) {
                return true;
            }
        }

        // Don't classify a single-token "MEMORY" / "CONFIG" / "CLIENT" / "CLUSTER"
        // / "LATENCY" / "SLOWLOG" as read-only — they only belong here in their
        // two-token forms (e.g. CONFIG GET vs CONFIG SET).
        if ($this->isPrefixOnly($tokens[0])) {
            return false;
        }

        return in_array($tokens[0], self::READ_ONLY_SINGLES, true);
    }

    public function isBlocked(string $command): bool
    {
        $tokens = $this->tokens($command);
        if ($tokens === []) {
            return false;
        }

        if (count($tokens) >= 2) {
            $pair = $tokens[0].' '.$tokens[1];
            if (in_array($pair, self::BLOCKED_PAIRS, true)) {
                return true;
            }
        }

        return in_array($tokens[0], self::BLOCKED_SINGLES, true);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $command): array
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];

        return array_map(static fn (string $t): string => strtoupper($t), $parts);
    }

    private function isPrefixOnly(string $firstToken): bool
    {
        return in_array($firstToken, ['MEMORY', 'CONFIG', 'CLIENT', 'CLUSTER', 'LATENCY', 'SLOWLOG', 'DEBUG', 'SCRIPT'], true);
    }
}
