<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\SiteManifestExporter;
use Illuminate\Console\Command;

/**
 * Export a Site's runtime config as a dply.yaml manifest.
 *
 *   dply:site:export-manifest <site>           # to stdout
 *   dply:site:export-manifest <site> --to=path
 *   dply:site:export-manifest <site> --to=path --force
 *
 * Captures: runtime, version, build (= [build_command]), release
 * (= [start_command]), processes (each with command + scale when
 * != 1). Omits env vars, domains, server, database — those stay
 * in the dashboard per the DplyManifest contract.
 *
 * Use case: I configured a site by hand in the dashboard and now
 * I want the same config in my repo so the next clone re-creates it
 * via auto-detection. Commit the result as `dply.yaml` at the repo
 * root.
 */
class ExportSiteManifestCommand extends Command
{
    protected $signature = 'dply:site:export-manifest
        {site : Site ID, slug, or name}
        {--to= : Write to this file path instead of stdout}
        {--force : Overwrite the destination file if it exists}';

    protected $description = 'Export a site\'s runtime config as a dply.yaml manifest.';

    public function handle(SiteManifestExporter $exporter): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $rendered = $exporter->render($site);

        $to = (string) ($this->option('to') ?? '');
        if ($to === '') {
            $this->getOutput()->write($rendered);

            return self::SUCCESS;
        }

        if (file_exists($to) && ! (bool) $this->option('force')) {
            $this->error("Refusing to overwrite existing file: {$to} (use --force)");

            return self::FAILURE;
        }

        $bytes = file_put_contents($to, $rendered);
        if ($bytes === false) {
            $this->error("Failed to write to: {$to}");

            return self::FAILURE;
        }

        $this->info(sprintf('Wrote dply.yaml manifest for %s to %s.', $site->name, $to));

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
