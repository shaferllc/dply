<?php

declare(strict_types=1);

namespace App\Support\Sites;

/**
 * A `queue:work` command line, taken apart and put back together.
 *
 * dply's worker form models about ten flags. Real commands carry more — a
 * `--name` for tagging, an `--env`, a `nice -n 10` in front, a php binary
 * pinned to a version. Rebuilding the line from form fields alone would
 * silently drop every one of them, so editing `--tries` on a hand-tuned worker
 * would quietly cost you the hand-tuning.
 *
 * So this keeps three things separate: the PREFIX (everything up to and
 * including `queue:work`), the flags dply understands, and EXTRAS — tokens it
 * does not, preserved verbatim and in order. An edit rewrites the middle and
 * leaves the other two alone.
 *
 * Pausing uses the same seam from the other side: {@see withQueues()} rewrites
 * only the `--queue` list, so a worker draining `high,default` can lose
 * `default` and keep serving `high`.
 */
final class QueueWorkerCommand
{
    /** Flags that take a value and that dply's form owns. */
    private const VALUE_FLAGS = [
        'queue', 'tries', 'timeout', 'sleep', 'memory',
        'backoff', 'max-jobs', 'rest', 'max-time', 'name',
    ];

    /** Value-less flags dply's form owns. */
    private const BOOL_FLAGS = ['stop-when-empty'];

    /**
     * @param  array<string, string>  $flags  known value flags, unquoted
     * @param  list<string>  $bools  known boolean flags that are present
     * @param  list<string>  $extras  tokens dply does not model, verbatim
     */
    private function __construct(
        public readonly string $prefix,
        public readonly ?string $connection,
        public readonly array $flags,
        public readonly array $bools,
        public readonly array $extras,
    ) {}

    public static function parse(string $command): self
    {
        $tokens = self::tokenize(trim($command));

        // The prefix is everything through the artisan command itself: `php`,
        // `/usr/bin/php8.3 -d memory_limit=512M`, `artisan`, `queue:work`. It
        // varies per box and is never dply's to rewrite.
        $prefixEnd = null;

        foreach ($tokens as $i => $token) {
            if (in_array(self::unquote($token), ['queue:work', 'queue:listen'], true)) {
                $prefixEnd = $i;

                break;
            }
        }

        if ($prefixEnd === null) {
            // Not a shape this understands. Everything becomes prefix, so
            // render() returns exactly what came in.
            return new self(implode(' ', $tokens), null, [], [], []);
        }

        $prefix = implode(' ', array_slice($tokens, 0, $prefixEnd + 1));
        $rest = array_slice($tokens, $prefixEnd + 1);

        $connection = null;
        $flags = [];
        $bools = [];
        $extras = [];

        for ($i = 0; $i < count($rest); $i++) {
            $token = $rest[$i];

            if (! str_starts_with($token, '-')) {
                // The first bare word after the command is the connection; a
                // second one has no meaning in this shape, so it survives as an
                // extra rather than being dropped.
                if ($connection === null) {
                    $connection = self::unquote($token);
                } else {
                    $extras[] = $token;
                }

                continue;
            }

            $name = ltrim(explode('=', $token, 2)[0], '-');

            if (in_array($name, self::BOOL_FLAGS, true)) {
                $bools[] = $name;

                continue;
            }

            if (! in_array($name, self::VALUE_FLAGS, true)) {
                $extras[] = $token;

                continue;
            }

            if (str_contains($token, '=')) {
                $flags[$name] = self::unquote(explode('=', $token, 2)[1]);

                continue;
            }

            // `--queue default` is as valid as `--queue=default`; the value is
            // the next token, and consuming it here keeps it from being read as
            // the connection.
            $flags[$name] = isset($rest[$i + 1]) ? self::unquote($rest[$i + 1]) : '';
            $i++;
        }

        return new self($prefix, $connection, $flags, array_unique($bools), $extras);
    }

    /** @return list<string> */
    public function queues(): array
    {
        $declared = $this->flags['queue'] ?? '';

        return array_values(array_filter(array_map('trim', explode(',', $declared)), static fn (string $q): bool => $q !== ''));
    }

    /**
     * The same command draining a different set of queues.
     *
     * @param  list<string>  $queues
     */
    public function withQueues(array $queues): self
    {
        $flags = $this->flags;
        $queues = array_values(array_filter(array_map('trim', $queues), static fn (string $q): bool => $q !== ''));

        if ($queues === []) {
            unset($flags['queue']);
        } else {
            $flags['queue'] = implode(',', $queues);
        }

        return new self($this->prefix, $this->connection, $flags, $this->bools, $this->extras);
    }

    /**
     * Apply edited values. A null or '' value REMOVES the flag, which is how
     * "no backoff" is expressed — `--backoff=0` does not mean the same thing to
     * every driver.
     *
     * @param  array<string, string|int|null>  $flags
     * @param  array<string, bool>  $bools
     */
    public function with(array $flags, array $bools = [], ?string $connection = null): self
    {
        $next = $this->flags;

        foreach ($flags as $name => $value) {
            if ($value === null || trim((string) $value) === '') {
                unset($next[$name]);

                continue;
            }

            $next[$name] = trim((string) $value);
        }

        $nextBools = $this->bools;

        foreach ($bools as $name => $on) {
            $nextBools = array_values(array_filter($nextBools, static fn (string $b): bool => $b !== $name));

            if ($on) {
                $nextBools[] = $name;
            }
        }

        return new self($this->prefix, $connection ?? $this->connection, $next, $nextBools, $this->extras);
    }

    public function render(): string
    {
        $parts = [$this->prefix];

        // Connection is positional and must precede the flags.
        if ($this->connection !== null && $this->connection !== '') {
            $parts[] = self::quote($this->connection);
        }

        // A stable order, so two workers with the same options render the same
        // line and a diff on the page means a real change.
        foreach (self::VALUE_FLAGS as $name) {
            if (($this->flags[$name] ?? '') !== '') {
                $parts[] = '--'.$name.'='.self::quote((string) $this->flags[$name]);
            }
        }

        foreach (self::BOOL_FLAGS as $name) {
            if (in_array($name, $this->bools, true)) {
                $parts[] = '--'.$name;
            }
        }

        foreach ($this->extras as $extra) {
            $parts[] = $extra;
        }

        return implode(' ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * Split on whitespace, keeping quoted runs together.
     *
     * @return list<string>
     */
    private static function tokenize(string $command): array
    {
        // Quoted runs glue to whatever they are attached to, so
        // `--queue='needs space'` is ONE token. Alternating on `\S+` alone
        // splits it at the space, because the quote is not at the token start.
        preg_match_all('/(?:"[^"]*"|\'[^\']*\'|[^\s"\']+)+/', $command, $m);

        return array_map('strval', $m[0]);
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];

            if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    /** Quote only what needs it — a bare `default` reads better than `'default'`. */
    private static function quote(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_\-:.,\/]+$/', $value) === 1 ? $value : "'".str_replace("'", "'\\''", $value)."'";
    }
}
