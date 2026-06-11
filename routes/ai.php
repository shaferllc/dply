<?php

declare(strict_types=1);

use App\Mcp\Servers\DplyServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP (Model Context Protocol) server
|--------------------------------------------------------------------------
|
| Streamable-HTTP MCP server that lets AI clients (Claude, ChatGPT, Cursor,
| …) manage dply-hosted sites. It is mounted behind the SAME auth + throttle
| stack as the REST API: users authenticate with their existing org-scoped
| `dply_…` API token (Authorization: Bearer …). `auth.api`
| (App\Http\Middleware\AuthenticateApiToken) validates the token, sets the
| user, and puts `api_token` + `api_organization` on the request — every MCP
| tool reuses those to scope and ability-gate its work. Per-operation
| abilities are enforced inside each tool (see App\Mcp\Tools\AbstractDplyTool),
| not on this route, because abilities are per-tool, not per-transport.
|
| This file is auto-loaded by Laravel\Mcp\Server\McpServiceProvider when it
| exists; no bootstrap/app.php wiring is required.
|
*/

Mcp::web('/mcp', DplyServer::class)
    ->middleware(['auth.api', 'throttle:api']);
