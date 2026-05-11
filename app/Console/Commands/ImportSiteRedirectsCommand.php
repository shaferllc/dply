<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SiteRedirectKind;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Support\SiteRedirectConfigSupport;
use Illuminate\Console\Command;

/**
 * Bulk import HTTP redirects from a CSV file.
 *
 *   dply:site:redirect-import <site> --file=redirects.csv [--replace]
 *
 * One rule per line: `from,to[,code]`. Code defaults to 301. Internal
 * rewrites use the single-add command. --replace drops all existing
 * redirects for the site before importing.
 */
class ImportSiteRedirectsCommand extends Command
{
    protected $signature = 'dply:site:redirect-import
        {site : Site ID, slug, or name}
        {--file= : Path to a CSV file with from,to[,code] per line}
        {--replace : Drop existing redirects before importing}
        {--no-apply : Skip the webserver config apply}
        {--json : Output as JSON}';

    protected $description = 'Bulk import HTTP redirects from a CSV file.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $file = (string) ($this->option('file') ?? '');
        if ($file === '') {
            $this->error('--file is required.');

            return self::FAILURE;
        }
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File not found or unreadable: {$file}");

            return self::FAILURE;
        }

        $allowedCodes = SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes();
        $contents = (string) file_get_contents($file);
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $rules = [];
        $errors = [];

        foreach ($lines as $i => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) < 2) {
                $errors[] = "Line {$i}: expected `from,to[,code]`.";

                continue;
            }
            [$from, $to] = $parts;
            $code = isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : 301;
            if ($from === '' || $to === '') {
                $errors[] = "Line {$i}: from/to may not be blank.";

                continue;
            }
            if (! in_array($code, $allowedCodes, true)) {
                $errors[] = "Line {$i}: status code {$code} is not allowed.";

                continue;
            }
            $rules[] = ['from' => $from, 'to' => $to, 'code' => $code];
        }

        if ($errors !== []) {
            foreach ($errors as $msg) {
                $this->warn($msg);
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('replace')) {
            $site->redirects()->delete();
        }

        $sortBase = (int) ($site->redirects()->max('sort_order') ?? 0);
        foreach ($rules as $rule) {
            SiteRedirect::query()->create([
                'site_id' => $site->id,
                'kind' => SiteRedirectKind::Http,
                'from_path' => $rule['from'],
                'to_url' => $rule['to'],
                'status_code' => $rule['code'],
                'response_headers' => null,
                'sort_order' => ++$sortBase,
            ]);
        }

        if (! (bool) $this->option('no-apply') && $site->server?->hostCapabilities()->supportsWebserverProvisioning()) {
            ApplySiteWebserverConfigJob::dispatch($site->id);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'imported' => count($rules),
                'replaced' => (bool) $this->option('replace'),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Imported %d redirect(s) on %s.', count($rules), $site->name));

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
