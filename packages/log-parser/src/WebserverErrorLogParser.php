<?php

declare(strict_types=1);

namespace Dply\LogParser;

/**
 * Parses NGINX and Apache error-log text into structured entries.
 *
 * NGINX:
 *   2026/06/10 14:23:01 [error] 1234#5678: *90 FastCGI sent in stderr:
 *   "PHP message: ...", client: 1.2.3.4, server: example.com,
 *   request: "GET /x HTTP/1.1", upstream: "fastcgi://unix:/run/php/x.sock",
 *   host: "example.com"
 *
 * Apache (2.4):
 *   [Wed Jun 10 14:23:01.123456 2026] [php:error] [pid 1234] [client 1.2.3.4:55] message
 *
 * The format is auto-detected per line. Continuation lines (a PHP stack trace
 * that the webserver logged across several lines) are grouped onto the entry
 * they belong to as `trace`. Tolerant: never throws; an unrecognized leading
 * line comes back as `['parsed' => false, 'raw' => ...]`.
 */
final class WebserverErrorLogParser
{
    public const TYPE_NGINX = 'nginx';

    public const TYPE_APACHE = 'apache';

    private const NGINX = '#^(?<date>\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}) \[(?<level>\w+)\] (?<pid>\d+)\#(?<tid>\d+): (?:\*(?<cid>\d+) )?(?<rest>.*)$#s';

    private const APACHE = '#^\[(?<date>[A-Za-z]{3} [A-Za-z]{3} \d{1,2} \d{2}:\d{2}:\d{2}(?:\.\d+)? \d{4})\] \[(?<level>[^\]]+)\](?: \[pid (?<pid>\d+)(?::tid \d+)?\])?(?: \[client (?<client>[^\]]+)\])?\s?(?<message>.*)$#s';

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $raw): array
    {
        $entries = [];
        /** @var array<string, mixed>|null $current */
        $current = null;
        $rawLines = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $parsed = $this->parseLine($line);

            if ($parsed['parsed'] === true) {
                if ($current !== null) {
                    $current['raw'] = implode("\n", $rawLines);
                    $entries[] = $current;
                }
                $current = $parsed;
                $rawLines = [$line];

                continue;
            }

            if ($current === null) {
                if (trim($line) !== '') {
                    $entries[] = ['parsed' => false, 'raw' => $line];
                }

                continue;
            }

            $current['trace'][] = rtrim($line);
            $rawLines[] = $line;
        }

        if ($current !== null) {
            $current['raw'] = implode("\n", $rawLines);
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * Parse a single error-log line. Returns `['parsed' => false, 'raw' => ...]`
     * when the line is neither a recognized NGINX nor Apache header.
     *
     * @return array<string, mixed>
     */
    public function parseLine(string $line): array
    {
        if (preg_match(self::NGINX, $line, $m)) {
            return $this->nginx($m, $line);
        }

        if (preg_match(self::APACHE, $line, $m)) {
            return $this->apache($m, $line);
        }

        return ['parsed' => false, 'raw' => $line];
    }

    /**
     * @param  array<string, string>  $m
     * @return array<string, mixed>
     */
    private function nginx(array $m, string $line): array
    {
        $rest = $m['rest'] ?? '';

        // The structured tail always begins at `, client:`; everything before it
        // is the (possibly comma-containing) message.
        $message = $rest;
        $pos = strpos($rest, ', client: ');
        if ($pos !== false) {
            $message = substr($rest, 0, $pos);
        }

        return [
            'parsed' => true,
            'type' => self::TYPE_NGINX,
            'datetime' => $this->date($m['date'] ?? '', ['Y/m/d H:i:s']),
            'level' => strtolower($m['level'] ?? ''),
            'pid' => ($m['pid'] ?? '') !== '' ? (int) $m['pid'] : null,
            'connection' => ($m['cid'] ?? '') !== '' ? (int) $m['cid'] : null,
            'message' => trim($message),
            'client' => $this->field($rest, 'client'),
            'server' => $this->field($rest, 'server'),
            'request' => $this->quotedField($rest, 'request'),
            'upstream' => $this->quotedField($rest, 'upstream'),
            'host' => $this->quotedField($rest, 'host'),
            'referrer' => $this->quotedField($rest, 'referrer'),
            'trace' => [],
            'raw' => $line,
        ];
    }

    /**
     * @param  array<string, string>  $m
     * @return array<string, mixed>
     */
    private function apache(array $m, string $line): array
    {
        $level = $m['level'] ?? '';
        // Apache 2.4 levels look like `php:error` / `core:notice`; keep the
        // severity, expose the module separately.
        $module = null;
        if (str_contains($level, ':')) {
            [$module, $level] = explode(':', $level, 2);
        }

        return [
            'parsed' => true,
            'type' => self::TYPE_APACHE,
            'datetime' => $this->date($m['date'] ?? '', ['D M j H:i:s.u Y', 'D M j H:i:s Y']),
            'level' => strtolower($level),
            'module' => $module,
            'pid' => ($m['pid'] ?? '') !== '' ? (int) $m['pid'] : null,
            'client' => ($m['client'] ?? '') !== '' ? $m['client'] : null,
            'message' => trim($m['message'] ?? ''),
            'trace' => [],
            'raw' => $line,
        ];
    }

    /** Extract an unquoted `key: value` field from the structured tail. */
    private function field(string $rest, string $key): ?string
    {
        if (preg_match('/(?:^|,)\s*'.preg_quote($key, '/').':\s*([^,]+)/', $rest, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /** Extract a quoted `key: "value"` field from the structured tail. */
    private function quotedField(string $rest, string $key): ?string
    {
        if (preg_match('/(?:^|,)\s*'.preg_quote($key, '/').':\s*"([^"]*)"/', $rest, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $formats
     */
    private function date(string $value, array $formats): ?\DateTimeImmutable
    {
        $value = trim($value);
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        return null;
    }
}
