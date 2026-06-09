<?php

namespace App\Services\Logging;

/**
 * Marks a string as a raw PHP expression so {@see LoggingConfigGenerator}'s
 * exporter emits it verbatim instead of quoting it — used for `env(...)` calls,
 * `\Class::class` references, `storage_path(...)`, and constant facilities.
 */
final class RawPhp
{
    public function __construct(public readonly string $code) {}
}
