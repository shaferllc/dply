<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses an in-repo dply config file (YAML or JSON) into an
 * {@see EdgeRepoConfig} snapshot the build runner stores on the
 * deployment row.
 *
 * Schema (YAML form):
 *
 *   build:
 *     command: npm run build
 *     output: dist
 *     root: apps/web        # monorepo root, relative to checkout
 *     node: "20"
 *
 *   redirects:
 *     - from: /old/*
 *       to: /new/:splat
 *       status: 301
 *
 *   rewrites:
 *     - from: /api/*
 *       to: https://api.example.com/:splat
 *
 *   headers:
 *     - for: /static/*
 *       values:
 *         Cache-Control: "public, max-age=31536000, immutable"
 *
 * All sections are optional. Malformed rules are dropped with a
 * warning recorded in the returned config — surfaced in the build log
 * and on the deploy detail page so the user sees what was ignored.
 */
class EdgeRepoConfigLoader
{
    /** Files searched in priority order at the checkout root. */
    private const CANDIDATE_FILES = [
        'dply.yaml',
        'dply.yml',
        'dply.json',
    ];

    /** Hard cap so a hostile repo can't ship a huge config that blows up KV. */
    private const MAX_FILE_BYTES = 64 * 1024;

    private const ALLOWED_STATUS_CODES = [301, 302, 303, 307, 308];

    public function loadFromDirectory(string $checkoutPath): ?EdgeRepoConfig
    {
        $base = rtrim($checkoutPath, '/');
        if ($base === '' || ! is_dir($base)) {
            return null;
        }

        foreach (self::CANDIDATE_FILES as $candidate) {
            $path = $base.'/'.$candidate;
            if (! is_file($path)) {
                continue;
            }
            if (filesize($path) > self::MAX_FILE_BYTES) {
                return new EdgeRepoConfig(
                    sourcePath: $candidate,
                    warnings: [sprintf('%s exceeds the %d KB limit and was ignored.', $candidate, self::MAX_FILE_BYTES / 1024)],
                );
            }

            $raw = (string) file_get_contents($path);

            return $this->parse($candidate, $raw);
        }

        return null;
    }

    /**
     * @internal exposed for testing — callers should use {@see loadFromDirectory()}.
     */
    public function parse(string $sourcePath, string $raw): EdgeRepoConfig
    {
        $warnings = [];
        $parsed = $this->decode($sourcePath, $raw, $warnings);
        if (! is_array($parsed)) {
            return new EdgeRepoConfig(
                sourcePath: $sourcePath,
                warnings: $warnings === [] ? ['Config file could not be parsed.'] : $warnings,
            );
        }

        return new EdgeRepoConfig(
            sourcePath: $sourcePath,
            build: $this->normalizeBuild($parsed['build'] ?? null, $warnings),
            envFiles: $this->normalizeEnvFiles($parsed['build'] ?? null, $warnings),
            redirects: $this->normalizeRedirects($parsed['redirects'] ?? null, $warnings),
            rewrites: $this->normalizeRewrites($parsed['rewrites'] ?? null, $warnings),
            headers: $this->normalizeHeaders($parsed['headers'] ?? null, $warnings),
            crons: $this->normalizeCrons($parsed['crons'] ?? null, $warnings),
            firewall: $this->normalizeFirewall($parsed['firewall'] ?? null, $warnings),
            origin: $this->normalizeOrigin($parsed['origin'] ?? null, $warnings),
            images: $this->normalizeImages($parsed['images'] ?? null, $warnings),
            bindings: $this->normalizeBindings($parsed['bindings'] ?? null, $warnings),
            errorPages: $this->normalizeErrorPages($parsed['error_pages'] ?? null, $warnings),
            maintenance: $this->normalizeMaintenance($parsed['maintenance'] ?? null, $warnings),
            domains: $this->normalizeDomains($parsed['domains'] ?? null, $warnings),
            previews: $this->normalizePreviews($parsed['previews'] ?? null, $warnings),
            commentWidget: $this->normalizeCommentWidget($parsed['comment_widget'] ?? null, $warnings),
            env: $this->normalizeEnv($parsed['env'] ?? null, $warnings),
            warnings: $warnings,
        );
    }

    /**
     * Bindings declarations in dply.yaml. Co-equal alternative to
     * `wrangler.toml` for users who don't use wrangler; both formats
     * are accepted and merged at build time (see EdgeBuildRunner).
     *
     *   bindings:
     *     kv:
     *       SESSIONS: "<namespace_id>"
     *     r2:
     *       UPLOADS: "my-bucket"
     *     d1:
     *       MAIN_DB: "<database_id>"
     *     queues:
     *       JOBS: "queue-name"
     *
     * Names must be ALL_CAPS_WITH_UNDERSCORES (CF binding convention).
     * Values can be CF resource ids or titles — the auto-resolver
     * creates the resource on first use when given a title.
     *
     * @param  array<string, mixed> $warnings
     * @return array{kv?: array<string, string>, r2?: array<string, string>, d1?: array<string, string>, queues?: array<string, string>}
     */
    private function normalizeBindings(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (['kv', 'r2', 'd1', 'queues'] as $kind) {
            $bucket = $value[$kind] ?? null;
            if (! is_array($bucket) || $bucket === []) {
                continue;
            }
            $clean = [];
            foreach ($bucket as $name => $target) {
                if (! is_string($name) || preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $name) !== 1) {
                    $warnings[] = sprintf('bindings.%s.%s — names must be ALL_CAPS_WITH_UNDERSCORES.', $kind, (string) $name);

                    continue;
                }
                if (! is_string($target) || trim($target) === '') {
                    $warnings[] = sprintf('bindings.%s.%s — value must be a non-empty string.', $kind, $name);

                    continue;
                }
                $clean[$name] = trim($target);
            }
            if ($clean !== []) {
                $out[$kind] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $warnings
     */
    private function decode(string $sourcePath, string $raw, array &$warnings): mixed
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        try {
            if ($extension === 'json') {
                return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            }

            return Yaml::parse($raw);
        } catch (\Throwable $e) {
            $warnings[] = sprintf('%s parse error: %s', $sourcePath, $e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string, mixed> $warnings
     * @return array<string, string>
     */
    private function normalizeBuild(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $build = [];
        foreach (['command', 'output', 'root', 'node'] as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }
            $entry = $value[$key];
            if (! is_string($entry) && ! is_int($entry)) {
                $warnings[] = sprintf('build.%s must be a string — ignored.', $key);

                continue;
            }
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            $build[$key] = $entry;
        }

        if (isset($build['root']) && str_contains($build['root'], '..')) {
            $warnings[] = 'build.root may not contain "..".';
            unset($build['root']);
        }

        return $build;
    }

    /**
     * Parses `build.env_files: [".env.production", ...]` — paths
     * relative to the checkout root that the build runner loads + merges
     * into the env handed to Docker. Dashboard env vars win on conflict
     * (handled by the runner, not here).
     *
     * @param  array<string, mixed> $warnings
     * @return list<string>
     */
    private function normalizeEnvFiles(mixed $value, array &$warnings): array
    {
        if (! is_array($value) || ! isset($value['env_files'])) {
            return [];
        }
        $raw = $value['env_files'];
        if (! is_array($raw)) {
            $warnings[] = 'build.env_files must be a list of repo-relative paths.';

            return [];
        }

        $out = [];
        foreach ($raw as $index => $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                $warnings[] = sprintf('build.env_files[%d] must be a non-empty string.', $index);

                continue;
            }
            $path = trim($entry);
            if (str_contains($path, '..') || str_starts_with($path, '/')) {
                $warnings[] = sprintf('build.env_files[%d] %s — must be a repo-relative path (no ".." or leading "/").', $index, $path);

                continue;
            }
            $out[] = $path;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $warnings
     * @return list<array{from: string, to: string, status: int}>
     */
    private function normalizeRedirects(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = sprintf('redirects[%d] must be a map.', $index);

                continue;
            }
            $from = isset($entry['from']) && is_string($entry['from']) ? trim($entry['from']) : '';
            $to = isset($entry['to']) && is_string($entry['to']) ? trim($entry['to']) : '';
            $status = isset($entry['status']) ? (int) $entry['status'] : 301;
            if ($from === '' || $to === '') {
                $warnings[] = sprintf('redirects[%d] missing required `from`/`to`.', $index);

                continue;
            }
            if (! in_array($status, self::ALLOWED_STATUS_CODES, true)) {
                $warnings[] = sprintf('redirects[%d] status %d not in {301,302,303,307,308}; defaulting to 301.', $index, $status);
                $status = 301;
            }
            $out[] = ['from' => $from, 'to' => $to, 'status' => $status];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $warnings
     * @return list<array{from: string, to: string}>
     */
    private function normalizeRewrites(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = sprintf('rewrites[%d] must be a map.', $index);

                continue;
            }
            $from = isset($entry['from']) && is_string($entry['from']) ? trim($entry['from']) : '';
            $to = isset($entry['to']) && is_string($entry['to']) ? trim($entry['to']) : '';
            if ($from === '' || $to === '') {
                $warnings[] = sprintf('rewrites[%d] missing required `from`/`to`.', $index);

                continue;
            }
            $out[] = ['from' => $from, 'to' => $to];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $warnings
     * @return list<array{for: string, values: array<string, string>}>
     */
    private function normalizeHeaders(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = sprintf('headers[%d] must be a map.', $index);

                continue;
            }
            $for = isset($entry['for']) && is_string($entry['for']) ? trim($entry['for']) : '';
            $values = $entry['values'] ?? null;
            if ($for === '' || ! is_array($values) || $values === []) {
                $warnings[] = sprintf('headers[%d] requires `for` and a non-empty `values` map.', $index);

                continue;
            }

            $cleanValues = [];
            foreach ($values as $name => $headerValue) {
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }
                if (! is_string($headerValue) && ! is_int($headerValue)) {
                    continue;
                }
                $cleanValues[trim($name)] = trim((string) $headerValue);
            }

            if ($cleanValues === []) {
                $warnings[] = sprintf('headers[%d] has no valid name/value pairs.', $index);

                continue;
            }

            $out[] = ['for' => $for, 'values' => $cleanValues];
        }

        return $out;
    }

    /**
     * Custom error-page HTML for 404 and 500. Each can be inlined as
     * `html_404` / `html_500` (string) or referenced as a repo-relative
     * file via `html_404_path` / `html_500_path`. The build runner
     * resolves paths to inline HTML before persisting on edgeMeta.
     *
     * @param  array<string, mixed> $warnings
     * @return array{html_404?: string, html_500?: string, html_404_path?: string, html_500_path?: string}
     */
    private function normalizeErrorPages(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (['html_404', 'html_500'] as $key) {
            if (isset($value[$key])) {
                if (is_string($value[$key])) {
                    $out[$key] = $value[$key];
                } else {
                    $warnings[] = "error_pages.{$key} must be an HTML string.";
                }
            }
        }
        foreach (['html_404_path', 'html_500_path'] as $key) {
            if (isset($value[$key])) {
                if (is_string($value[$key]) && trim($value[$key]) !== '') {
                    $out[$key] = trim($value[$key]);
                } else {
                    $warnings[] = "error_pages.{$key} must be a repo-relative file path.";
                }
            }
        }

        return $out;
    }

    /**
     * Maintenance mode: `enabled` (bool) + `html` (string) or
     * `html_path` (repo-relative file). When enabled, the worker
     * short-circuits every request with 503 + the configured HTML.
     *
     * @param  array<string, mixed> $warnings
     * @return array{enabled?: bool, html?: string, html_path?: string}
     */
    private function normalizeMaintenance(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        if (isset($value['enabled'])) {
            $out['enabled'] = (bool) $value['enabled'];
        }
        if (isset($value['html'])) {
            if (is_string($value['html'])) {
                $out['html'] = $value['html'];
            } else {
                $warnings[] = 'maintenance.html must be an HTML string.';
            }
        }
        if (isset($value['html_path'])) {
            if (is_string($value['html_path']) && trim($value['html_path']) !== '') {
                $out['html_path'] = trim($value['html_path']);
            } else {
                $warnings[] = 'maintenance.html_path must be a repo-relative file path.';
            }
        }

        return $out;
    }

    /**
     * Custom domains declared by the repo. On deploy, dply ensures each
     * listed hostname is attached to the site (no-op when already
     * attached). Removing a hostname from `domains:` does NOT detach
     * — detaches are explicit only, via dashboard or API.
     *
     * @param  array<string, mixed> $warnings
     * @return list<string>
     */
    private function normalizeDomains(mixed $value, array &$warnings): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            $warnings[] = 'domains: must be a list of hostnames.';

            return [];
        }

        $out = [];
        foreach ($value as $idx => $entry) {
            if (! is_string($entry)) {
                $warnings[] = sprintf('domains[%d] must be a string hostname.', $idx);

                continue;
            }
            $host = strtolower(trim($entry));
            $host = (string) preg_replace('#^https?://#', '', $host);
            $host = rtrim($host, '/');
            if ($host === '' || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $host) !== 1) {
                $warnings[] = sprintf('domains[%d] does not look like a valid hostname: %s', $idx, $entry);

                continue;
            }
            $out[$host] = true;
        }

        return array_keys($out);
    }

    /**
     * Preview-deploy gating. When `enabled: false`, no previews are
     * ever created. When `pr_only: true` (default), only `pull_request`
     * webhooks create previews; bare branch pushes are ignored. The
     * `branches` whitelist allows specific branch names to ALSO deploy
     * previews even outside a PR (e.g. "staging"). `exclude_branches`
     * is a blacklist applied after the whitelist — useful for the
     * production branch you never want previewed.
     *
     * @param  array<string, mixed> $warnings
     * @return array{enabled?: bool, pr_only?: bool, branches?: list<string>, exclude_branches?: list<string>}
     */
    private function normalizePreviews(mixed $value, array &$warnings): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            $warnings[] = 'previews: must be a map of options.';

            return [];
        }

        $out = [];
        if (isset($value['enabled'])) {
            $out['enabled'] = (bool) $value['enabled'];
        }
        if (isset($value['pr_only'])) {
            $out['pr_only'] = (bool) $value['pr_only'];
        }
        foreach (['branches' => 'branches', 'exclude_branches' => 'exclude_branches'] as $key => $field) {
            if (! isset($value[$key])) {
                continue;
            }
            if (! is_array($value[$key])) {
                $warnings[] = "previews.{$key} must be a list of branch names.";

                continue;
            }
            $clean = [];
            foreach ($value[$key] as $branch) {
                if (! is_string($branch) || trim($branch) === '') {
                    continue;
                }
                $clean[trim($branch)] = true;
            }
            if ($clean !== []) {
                $out[$field] = array_keys($clean);
            }
        }

        // previews.protection — default access gate for every new
        // preview deploy. Passwords are NOT accepted via the file
        // (committing a password is a leak vector); set the value in
        // the dashboard / `edge env` and dply seeds it on creation.
        $proto = $value['protection'] ?? null;
        if (is_array($proto)) {
            $protOut = [];
            $mode = is_string($proto['mode'] ?? null) ? strtolower(trim($proto['mode'])) : '';
            if (in_array($mode, ['none', 'password', 'dply-account', 'email'], true)) {
                $protOut['mode'] = $mode;
            } elseif ($mode !== '') {
                $warnings[] = 'previews.protection.mode must be one of: none, password, dply-account, email.';
            }
            if (isset($proto['allowed_emails'])) {
                if (! is_array($proto['allowed_emails'])) {
                    $warnings[] = 'previews.protection.allowed_emails must be a list of email addresses.';
                } else {
                    $emails = [];
                    foreach ($proto['allowed_emails'] as $email) {
                        if (! is_string($email)) {
                            continue;
                        }
                        $clean = strtolower(trim($email));
                        if ($clean !== '' && filter_var($clean, FILTER_VALIDATE_EMAIL)) {
                            $emails[$clean] = true;
                        }
                    }
                    if ($emails !== []) {
                        $protOut['allowed_emails'] = array_keys($emails);
                    }
                }
            }
            if (isset($proto['password'])) {
                $warnings[] = 'previews.protection.password is ignored in dply.yaml — set it via the dashboard so it stays out of your repo.';
            }
            if ($protOut !== []) {
                $out['protection'] = $protOut;
            }
        } elseif ($proto !== null) {
            $warnings[] = 'previews.protection must be a map (mode + optional allowed_emails).';
        }

        return $out;
    }

    /**
     * Env vars declared in dply.yaml.
     *
     *   env:
     *     public:                # safe-to-commit: NEXT_PUBLIC_*, NODE_VERSION, feature flags
     *       NODE_VERSION: "20"
     *       NEXT_PUBLIC_API: "https://api.example.com"
     *     secret:                # NAMES ONLY — values set via dashboard
     *       - DATABASE_URL
     *       - STRIPE_SECRET_KEY
     *
     * Paranoia: keys in `public` matching common secret patterns
     * (*_KEY, *_SECRET, *_TOKEN, *_PASSWORD, *_PASS) produce a
     * warning. The value isn't dropped — the user might genuinely
     * have a public key — but they get nudged.
     *
     * @param  array<string, mixed> $warnings
     * @return array{public?: array<string, string>, secret?: list<string>}
     */
    private function normalizeEnv(mixed $value, array &$warnings): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            $warnings[] = 'env: must be a map with optional `public:` + `secret:` blocks.';

            return [];
        }

        $out = [];

        $public = $value['public'] ?? null;
        if (is_array($public)) {
            $publicOut = [];
            foreach ($public as $name => $raw) {
                if (! is_string($name) || preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) !== 1) {
                    $warnings[] = sprintf('env.public.%s — names must be ALL_CAPS_WITH_UNDERSCORES.', (string) $name);

                    continue;
                }
                if (! is_string($raw) && ! is_numeric($raw) && ! is_bool($raw)) {
                    $warnings[] = sprintf('env.public.%s — value must be a string, number, or boolean.', $name);

                    continue;
                }
                if (preg_match('/_(KEY|SECRET|TOKEN|PASSWORD|PASS|CREDENTIALS?|PRIVATE)$/', $name) === 1) {
                    $warnings[] = sprintf('env.public.%s — looks like a secret. Move to `env.secret:` and set the value via the dashboard so it stays out of your repo.', $name);
                }
                $publicOut[$name] = is_bool($raw) ? ($raw ? 'true' : 'false') : (string) $raw;
            }
            if ($publicOut !== []) {
                $out['public'] = $publicOut;
            }
        } elseif ($public !== null) {
            $warnings[] = 'env.public must be a map of NAME: value pairs.';
        }

        $secret = $value['secret'] ?? null;
        if (is_array($secret)) {
            $secretOut = [];
            foreach ($secret as $idx => $name) {
                if (! is_string($name)) {
                    $warnings[] = sprintf('env.secret[%d] must be a string (variable name).', $idx);

                    continue;
                }
                $clean = trim($name);
                if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $clean) !== 1) {
                    $warnings[] = sprintf('env.secret[%d] — names must be ALL_CAPS_WITH_UNDERSCORES.', $idx);

                    continue;
                }
                $secretOut[$clean] = true;
            }
            if ($secretOut !== []) {
                $out['secret'] = array_keys($secretOut);
            }
        } elseif ($secret !== null) {
            $warnings[] = 'env.secret must be a list of variable names (values stay in the dashboard).';
        }

        return $out;
    }

    /**
     * Preview comment widget — boolean toggle. The token + api_base
     * are generated server-side on enable; the file only expresses
     * intent.
     *
     * @param  array<string, mixed> $warnings
     * @return array{enabled?: bool}
     */
    private function normalizeCommentWidget(mixed $value, array &$warnings): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            $warnings[] = 'comment_widget: must be a map of options.';

            return [];
        }

        $out = [];
        if (isset($value['enabled'])) {
            $out['enabled'] = (bool) $value['enabled'];
        }

        return $out;
    }

    /**
     * Cron triggers attached to the per-deployment middleware / SSR
     * Worker. Schema:
     *
     *   crons:
     *     - schedule: "0 * * * *"
     *       handler: src/cron/hourly.ts  # optional, v1 ignored
     *
     * Shape-only cron validation (5 whitespace-separated tokens of
     * cron-legal characters) — Cloudflare is the source of truth for
     * semantics. Max 5 schedules per site (CF limit).
     *
     * @param  array<string, mixed> $warnings
     * @return list<array{schedule: string, handler?: string}>
     */
    private function normalizeCrons(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (count($out) >= 5) {
                $warnings[] = 'crons[]: dply Edge supports up to 5 schedules per site — extras ignored.';
                break;
            }
            if (! is_array($entry)) {
                $warnings[] = sprintf('crons[%d] must be a map.', $index);

                continue;
            }
            $schedule = isset($entry['schedule']) && is_string($entry['schedule']) ? trim($entry['schedule']) : '';
            if ($schedule === '' || ! $this->looksLikeCronExpression($schedule)) {
                $warnings[] = sprintf('crons[%d] missing or invalid `schedule` (expected 5-field cron).', $index);

                continue;
            }

            $normalized = ['schedule' => $schedule];
            $handler = isset($entry['handler']) && is_string($entry['handler']) ? trim($entry['handler']) : '';
            if ($handler !== '') {
                // v1 contract is "export `scheduled` from your middleware
                // module"; standalone handler files are deferred. Surface
                // the warning so users know their handler field is moot.
                $warnings[] = sprintf('crons[%d].handler is ignored in v1 — export `scheduled` from your middleware module instead.', $index);
                $normalized['handler'] = $handler;
            }

            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $warnings
     * @return array{country_mode?: string, countries?: list<string>}
     */
    private function normalizeFirewall(mixed $value, array &$warnings): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            $warnings[] = 'firewall: must be a map of options.';

            return [];
        }

        $out = [];
        $rawMode = $value['country_mode'] ?? null;
        if (is_string($rawMode)) {
            $mode = strtolower(trim($rawMode));
            if (! in_array($mode, ['off', 'allow', 'block'], true)) {
                $warnings[] = 'firewall.country_mode must be one of: off, allow, block.';
            } else {
                $out['country_mode'] = $mode;
            }
        }

        $countries = $value['countries'] ?? null;
        if (is_array($countries)) {
            $cleaned = [];
            foreach ($countries as $c) {
                if (! is_string($c)) {
                    continue;
                }
                $upper = strtoupper(trim($c));
                if (preg_match('/^[A-Z]{2}$/', $upper) === 1) {
                    $cleaned[$upper] = true;
                }
            }
            if ($cleaned !== []) {
                $out['countries'] = array_keys($cleaned);
            }
        } elseif ($countries !== null) {
            $warnings[] = 'firewall.countries must be a list of ISO 3166 alpha-2 codes.';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $warnings
     * @return array{url?: string, routes?: list<string>, failover_html?: string}
     */
    private function normalizeOrigin(mixed $value, array &$warnings): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            $warnings[] = 'origin: must be a map of options.';

            return [];
        }

        $out = [];
        if (isset($value['url'])) {
            if (is_string($value['url']) && filter_var(trim($value['url']), FILTER_VALIDATE_URL)) {
                $out['url'] = trim($value['url']);
            } else {
                $warnings[] = 'origin.url must be a valid URL (https://...).';
            }
        }
        if (isset($value['routes'])) {
            if (is_array($value['routes'])) {
                $routes = [];
                foreach ($value['routes'] as $route) {
                    if (is_string($route) && trim($route) !== '') {
                        $routes[] = trim($route);
                    }
                }
                if ($routes !== []) {
                    $out['routes'] = $routes;
                }
            } else {
                $warnings[] = 'origin.routes must be a list of path patterns (e.g. ["/api/*"]).';
            }
        }
        if (isset($value['failover_html'])) {
            if (is_string($value['failover_html'])) {
                $out['failover_html'] = $value['failover_html'];
            } else {
                $warnings[] = 'origin.failover_html must be an HTML string.';
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $warnings
     * @return array{allowed_hosts?: list<string>}
     */
    private function normalizeImages(mixed $value, array &$warnings): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            $warnings[] = 'images: must be a map of options.';

            return [];
        }

        $out = [];
        if (isset($value['allowed_hosts'])) {
            if (! is_array($value['allowed_hosts'])) {
                $warnings[] = 'images.allowed_hosts must be a list of hostnames.';
            } else {
                $hosts = [];
                foreach ($value['allowed_hosts'] as $host) {
                    if (! is_string($host)) {
                        continue;
                    }
                    $clean = strtolower(trim($host));
                    if ($clean !== '' && preg_match('/^[a-z0-9.\-]+$/', $clean)) {
                        $hosts[$clean] = true;
                    }
                }
                if ($hosts !== []) {
                    $out['allowed_hosts'] = array_keys($hosts);
                }
            }
        }
        if (isset($value['signing_secret'])) {
            $warnings[] = 'images.signing_secret is ignored in dply.yaml — set it via the dashboard so it stays out of your repo.';
        }

        return $out;
    }

    private function looksLikeCronExpression(string $value): bool
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        if (count($parts) !== 5) {
            return false;
        }

        foreach ($parts as $part) {
            if (preg_match('#^[0-9*/,\-A-Z]+$#i', $part) !== 1) {
                return false;
            }
        }

        return true;
    }
}
