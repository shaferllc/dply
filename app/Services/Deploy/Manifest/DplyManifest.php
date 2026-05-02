<?php

declare(strict_types=1);

namespace App\Services\Deploy\Manifest;

/**
 * Parsed, validated representation of a `dply.yaml` file.
 *
 * The manifest is intentionally minimal — code-shape only. It captures
 * what changes with the code (runtime version, build/release commands,
 * process definitions) and deliberately *omits* deployment-shape fields
 * (env vars, domains, server selection, database engine) which stay in
 * the dply dashboard because they vary per environment and may contain
 * secrets.
 *
 * All fields are optional. A repo with no `dply.yaml` deploys via auto-
 * detection; a repo with a one-line manifest overrides only that line.
 *
 * Precedence on deploy: manifest → auto-detection → dashboard.
 */
final readonly class DplyManifest
{
    /**
     * Allowed values for the top-level `runtime:` key.
     *
     * Static is included even though it has no runtime per se — it's the
     * "I'm a static site" marker that lets the manifest opt out of build/
     * runtime detection entirely.
     */
    public const ALLOWED_RUNTIMES = ['php', 'node', 'python', 'ruby', 'go', 'static'];

    /**
     * Top-level keys we recognize. Unknown top-level keys produce a
     * forward-compat warning but do not fail parsing — older dply versions
     * can read newer manifests with new keys, and vice versa.
     */
    public const KNOWN_TOP_LEVEL_KEYS = ['runtime', 'version', 'build', 'release', 'processes'];

    /**
     * @param  list<string>  $build
     * @param  list<string>  $release
     * @param  array<string, DplyManifestProcess>  $processes
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $runtime,
        public ?string $version,
        public array $build,
        public array $release,
        public array $processes,
        public array $warnings,
    ) {}

    public static function empty(): self
    {
        return new self(
            runtime: null,
            version: null,
            build: [],
            release: [],
            processes: [],
            warnings: [],
        );
    }
}
