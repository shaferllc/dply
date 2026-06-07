<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Heuristic visitor bucket for HTTP access-log lines (nginx/apache/caddy/traefik).
 * Used by the server log viewer to separate humans from crawlers, bots, and AI fetchers.
 */
final class AccessLogVisitorClassifier
{
    public const BUCKET_HUMAN = 'human';

    public const BUCKET_CRAWLER = 'crawler';

    public const BUCKET_BOT = 'bot';

    public const BUCKET_AI = 'ai';

    public const BUCKET_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const FILTERS = ['all', 'humans', 'crawlers', 'bots', 'ai', 'noise'];

    /**
     * @return array{human: int, crawler: int, bot: int, ai: int, unknown: int}
     */
    public static function breakdown(array $lines): array
    {
        $counts = [
            self::BUCKET_HUMAN => 0,
            self::BUCKET_CRAWLER => 0,
            self::BUCKET_BOT => 0,
            self::BUCKET_AI => 0,
            self::BUCKET_UNKNOWN => 0,
        ];

        foreach ($lines as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            // Skip dply's own injected status lines (e.g. "[dply] Read using SSH
            // user …", "[dply] File exists but is not readable …") — they are not
            // access-log traffic and must not be counted as real visitors.
            if (self::isDplyNoiseLine($line)) {
                continue;
            }

            $bucket = self::classifyLine($line);
            $counts[$bucket]++;
        }

        return $counts;
    }

    public static function classifyLine(string $line): string
    {
        $userAgent = self::extractUserAgent($line);
        if ($userAgent === null || $userAgent === '' || $userAgent === '-') {
            return self::BUCKET_UNKNOWN;
        }

        return self::classifyUserAgent($userAgent);
    }

    /** dply's own injected status lines, not access-log traffic. */
    public static function isDplyNoiseLine(string $line): bool
    {
        return str_starts_with(ltrim($line), '[dply]');
    }

    public static function lineMatchesFilter(string $line, string $filter): bool
    {
        $filter = self::normalizeFilter($filter);
        if ($filter === 'all') {
            return true;
        }

        // dply status lines are never visitor traffic — hide them under any
        // specific traffic filter (they still show under "all").
        if (self::isDplyNoiseLine($line)) {
            return false;
        }

        $bucket = self::classifyLine($line);

        return match ($filter) {
            'humans' => $bucket === self::BUCKET_HUMAN,
            'crawlers' => $bucket === self::BUCKET_CRAWLER,
            'bots' => $bucket === self::BUCKET_BOT,
            'ai' => $bucket === self::BUCKET_AI,
            'noise' => in_array($bucket, [self::BUCKET_HUMAN, self::BUCKET_UNKNOWN], true),
            default => true,
        };
    }

    public static function normalizeFilter(string $filter): string
    {
        $filter = strtolower(trim($filter));

        return in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    public static function isAccessLogSource(string $logKey, array $def): bool
    {
        if (($def['type'] ?? '') !== 'file') {
            return false;
        }

        $key = strtolower($logKey);
        $path = strtolower((string) ($def['path'] ?? ''));
        $label = strtolower((string) ($def['label'] ?? ''));

        return str_contains($key, '_access')
            || str_ends_with($path, 'access.log')
            || str_contains($path, '-access.log')
            || str_contains($label, 'access log');
    }

    public static function classifyUserAgent(string $userAgent): string
    {
        $ua = trim($userAgent);
        if ($ua === '' || $ua === '-') {
            return self::BUCKET_UNKNOWN;
        }

        $lower = strtolower($ua);

        if (self::matchesAny($lower, self::aiPatterns())) {
            return self::BUCKET_AI;
        }

        if (self::matchesAny($lower, self::crawlerPatterns())) {
            return self::BUCKET_CRAWLER;
        }

        if (self::matchesAny($lower, self::botPatterns())) {
            return self::BUCKET_BOT;
        }

        if (self::looksLikeHumanBrowser($lower)) {
            return self::BUCKET_HUMAN;
        }

        return self::BUCKET_UNKNOWN;
    }

    public static function extractUserAgent(string $line): ?string
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        if (str_starts_with($line, '{')) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $fromJson = self::userAgentFromJson($decoded);
                if ($fromJson !== null) {
                    return $fromJson;
                }
            }
        }

        if (preg_match('/"([^"]*)"\s+"([^"]*)"\s*$/', $line, $matches) === 1) {
            return $matches[2] !== '' ? $matches[2] : null;
        }

        if (preg_match('/"([^"]*)"\s*$/', $line, $matches) === 1) {
            return $matches[1] !== '' ? $matches[1] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function userAgentFromJson(array $payload): ?string
    {
        $candidates = [
            $payload['request']['headers']['User-Agent'][0] ?? null,
            $payload['request']['headers']['user-agent'][0] ?? null,
            $payload['RequestHeaders']['User-Agent'][0] ?? null,
            $payload['user_agent'] ?? null,
            $payload['userAgent'] ?? null,
            $payload['ClientRequestUserAgent'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matchesAny(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($haystack, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    private static function looksLikeHumanBrowser(string $lower): bool
    {
        if (str_contains($lower, 'bot')
            || str_contains($lower, 'spider')
            || str_contains($lower, 'crawl')
            || str_contains($lower, 'slurp')
            || str_contains($lower, 'preview')
            || str_contains($lower, 'headless')
        ) {
            return false;
        }

        $browserHints = [
            'mozilla/5.0',
            'chrome/',
            'safari/',
            'firefox/',
            'edg/',
            'opr/',
            'version/',
        ];

        foreach ($browserHints as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function aiPatterns(): array
    {
        return [
            'gptbot',
            'chatgpt-user',
            'oai-searchbot',
            'claudebot',
            'claude-web',
            'anthropic-ai',
            'bytespider',
            'perplexitybot',
            'google-extended',
            'amazonbot',
            'cohere-ai',
            'meta-externalagent',
            'meta-externalfetcher',
            'applebot-extended',
            'youbot',
            'ai2bot',
            'diffbot',
            'omgili',
            'facebookbot',
        ];
    }

    /**
     * @return list<string>
     */
    private static function crawlerPatterns(): array
    {
        return [
            'googlebot',
            'bingbot',
            'duckduckbot',
            'yandexbot',
            'baiduspider',
            'slurp',
            'facebot',
            'ia_archiver',
            'applebot/',
            'twitterbot',
            'linkedinbot',
            'pinterestbot',
            'discordbot',
            'telegrambot',
            'whatsapp',
            'mastodon',
        ];
    }

    /**
     * @return list<string>
     */
    private static function botPatterns(): array
    {
        return [
            'bot',
            'spider',
            'crawl',
            'curl/',
            'wget/',
            'python-requests',
            'python-urllib',
            'go-http-client',
            'java/',
            'libwww',
            'httpclient',
            'scrapy',
            'semrushbot',
            'ahrefsbot',
            'dotbot',
            'petalbot',
            'mj12bot',
            'uptimerobot',
            'pingdom',
            'statuscake',
            'monitor',
            'headlesschrome',
            'phantomjs',
            'selenium',
            'postman',
            'insomnia',
        ];
    }
}
