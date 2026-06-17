<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Concerns\ResolvesDplyContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

/**
 * Current configuration snapshot for one site, addressable as
 * `dply://sites/{site_id}`. Mirrors the get_site tool output.
 */
#[MimeType('application/json')]
#[Description('Configuration snapshot for a single dply site (runtime, status, git, SSL, last deploy).')]
class SiteConfigResource extends Resource implements HasUriTemplate
{
    use ResolvesDplyContext;

    protected string $name = 'site-config';

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('dply://sites/{site_id}');
    }

    public function handle(Request $request): Response
    {
        $siteId = (string) $request->get('site_id');

        $site = $this->resolveSite($siteId);

        return Response::json([
            'data' => [
                'id' => $site->id,
                'slug' => $site->slug,
                'name' => $site->name,
                'server_id' => $site->server_id,
                'server_name' => $site->server?->name,
                'type' => $site->type,
                'runtime' => $site->runtime,
                'runtime_version' => $site->runtime_version,
                'status' => $site->status,
                'deploy_strategy' => $site->deploy_strategy,
                'document_root' => $site->document_root,
                'git_repository_url' => $site->git_repository_url,
                'git_branch' => $site->git_branch,
                'ssl_status' => $site->ssl_status,
                'last_deploy_at' => $site->last_deploy_at?->toIso8601String(),
            ],
        ]);
    }
}
