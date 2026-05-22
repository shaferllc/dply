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
 * Add a redirect rule to a site.
 *
 *   dply:site:redirect-add <site> <from> <to> [--code=301] [--internal] [--comment=...]
 *
 * --internal flips the kind to internal_rewrite (path remap, no 3xx).
 * Otherwise the rule is an HTTP redirect with the given status code
 * (default 301; must be one of the allowed values).
 */
class AddSiteRedirectCommand extends Command
{
    protected $signature = 'dply:site:redirect-add
        {site : Site ID, slug, or name}
        {from : Source path (e.g. /old)}
        {to : Destination URL or path}
        {--code=301 : HTTP status code (ignored for --internal)}
        {--internal : Internal rewrite instead of HTTP redirect}
        {--comment= : Optional free-text comment for the row}
        {--no-apply : Skip the webserver config apply}
        {--json : Output as JSON}';

    protected $description = 'Add a redirect rule to a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $from = trim((string) $this->argument('from'));
        $to = trim((string) $this->argument('to'));
        if ($from === '' || $to === '') {
            $this->error('From and To are required.');

            return self::FAILURE;
        }
        $kind = (bool) $this->option('internal') ? SiteRedirectKind::InternalRewrite : SiteRedirectKind::Http;
        $code = (int) ($this->option('code') ?? 301);
        if ($kind === SiteRedirectKind::Http && ! in_array($code, SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes(), true)) {
            $this->error('Status code is not allowed.');

            return self::FAILURE;
        }

        $redirect = SiteRedirect::query()->create([
            'site_id' => $site->id,
            'kind' => $kind,
            'from_path' => $from,
            'to_url' => $to,
            'status_code' => $kind === SiteRedirectKind::InternalRewrite ? 301 : $code,
            'response_headers' => null,
            'comment' => trim((string) $this->option('comment')) ?: null,
            'sort_order' => (int) ($site->redirects()->max('sort_order') ?? 0) + 1,
        ]);

        if (! (bool) $this->option('no-apply') && $site->server?->hostCapabilities()->supportsWebserverProvisioning()) {
            ApplySiteWebserverConfigJob::dispatch($site->id);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'redirect' => [
                    'id' => $redirect->id,
                    'kind' => $kind->value,
                    'from' => $redirect->from_path,
                    'to' => $redirect->to_url,
                    'code' => $redirect->status_code,
                    'comment' => $redirect->comment,
                ],
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Added redirect %s → %s on %s.', $from, $to, $site->name));

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
