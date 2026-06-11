<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesDplyContext;
use App\Mcp\Exceptions\DplyMcpException;
use App\Models\Organization;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Base class for every dply MCP tool.
 *
 * Centralises the cross-cutting concerns so individual tools stay thin and
 * consistent with the REST API:
 *
 *  - Auth context + org-scoped site resolution come from {@see ResolvesDplyContext}
 *    (shared with MCP resources).
 *  - Ability gate: each tool declares the {@see $ability} it requires; we enforce
 *    it with `$token->allows()` — identical semantics to
 *    App\Http\Middleware\EnsureApiTokenAbility — so a restricted/deployer token is
 *    gated exactly as it is over REST.
 *
 * Subclasses implement {@see run()} instead of `handle()`; the base wraps it so
 * ability failures and expected errors become clean structured MCP errors.
 */
abstract class AbstractDplyTool extends Tool
{
    use ResolvesDplyContext;

    /**
     * Ability string (from config/api_token_permissions.php) the calling token
     * must hold to use this tool, e.g. 'sites.read', 'sites.deploy'. Empty means
     * no ability is required beyond a valid token.
     */
    protected string $ability = '';

    /**
     * Tool entry point. Resolves + ability-gates the org context, then defers to
     * {@see run()}. Never let an expected failure escape as a 500.
     */
    final public function handle(Request $request): Response
    {
        try {
            $token = $this->token();

            if ($this->ability !== '' && ! $token->allows($this->ability)) {
                return Response::error(
                    "This API token lacks the required \"{$this->ability}\" ability."
                );
            }

            return $this->run($request, $this->organization($token));
        } catch (DplyMcpException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('The operation failed: '.$e->getMessage());
        }
    }

    /**
     * Tool logic. Receives the validated MCP request and the resolved, ability-
     * checked organization the token is scoped to.
     */
    abstract protected function run(Request $request, Organization $organization): Response;
}
