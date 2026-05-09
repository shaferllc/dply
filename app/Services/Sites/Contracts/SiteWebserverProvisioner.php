<?php

namespace App\Services\Sites\Contracts;

use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;

interface SiteWebserverProvisioner
{
    public function webserver(): string;

    /**
     * Apply the webserver config. Returns the full transcript on success and
     * throws on failure. The `$emit` callable streams progress lines into the
     * console_actions row that backs the page banner; pass `new ConsoleEmitter(null)`
     * (or omit) for a no-op emitter when running outside a job context.
     *
     * Hard-cutover signature for emit calls inside the provisioner:
     *   $emit(string $line, string $level = 'info', ?string $source = null): void
     * with helpers $emit->step($source, $line), ->warn(...), ->error(...), ->success(...).
     */
    public function provision(Site $site, ?ConsoleEmitter $emit = null): string;

    public function remove(Site $site): string;
}
