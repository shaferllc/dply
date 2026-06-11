<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Livewire\Sites\Concerns\ManagesSiteEnvironment;
use App\Models\ConsoleAction;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteEnvPushScheduler;
use App\Services\Sites\SiteEnvWriteGuard;

/**
 * Staged-env read/write for the env MCP tools, mirroring the Livewire UI's
 * {@see ManagesSiteEnvironment} flow:
 *
 *   parse `Site.env_file_content` (the DB-staged cache) → merge → render back to
 *   the cache (origin = 'local-edit') → schedule a debounced, per-server-serialised
 *   push via {@see SiteEnvPushScheduler} (which dispatches the ShouldBeUnique
 *   PushSiteEnvJob and seeds an `env_push` ConsoleAction to poll).
 *
 * Reads come from the staged cache, NOT over SSH — SSH must stay queued, and the
 * cache is the canonical editable copy the UI uses too.
 */
trait MutatesSiteEnv
{
    /**
     * Parsed staged env for a site: variables map, comments, parse errors.
     *
     * @return array{variables: array<string, string>, comments: array<string, mixed>, errors: array<int, mixed>}
     */
    protected function parseSiteEnv(Site $site): array
    {
        return app(DotEnvFileParser::class)->parse((string) $site->env_file_content);
    }

    /**
     * Upsert env vars into the staged cache and schedule a push. Returns the
     * env_push ConsoleAction to poll (via get_operation_status) and whether the
     * push coalesced into an already-pending one.
     *
     * @param  array<string, string>  $vars
     * @return array{run: ConsoleAction, coalesced: bool}
     */
    protected function upsertSiteEnv(Site $site, array $vars, ?string $userId): array
    {
        $parsed = $this->parseSiteEnv($site);
        $merged = array_merge($parsed['variables'], $vars);

        return $this->persistAndPush($site, $merged, $parsed['comments'], $userId);
    }

    /**
     * Remove a key from the staged cache and schedule a push. Returns null when
     * the key wasn't present (nothing changed, no push needed).
     *
     * @return array{run: ConsoleAction, coalesced: bool}|null
     */
    protected function deleteSiteEnv(Site $site, string $key, ?string $userId): ?array
    {
        $parsed = $this->parseSiteEnv($site);
        if (! array_key_exists($key, $parsed['variables'])) {
            return null;
        }

        unset($parsed['variables'][$key]);

        return $this->persistAndPush($site, $parsed['variables'], $parsed['comments'], $userId);
    }

    /**
     * Schedule a push of the current staged cache without changing it.
     *
     * @return array{run: ConsoleAction, coalesced: bool}
     */
    protected function pushSiteEnv(Site $site, ?string $userId): array
    {
        return app(SiteEnvPushScheduler::class)->schedule($site, $userId);
    }

    /**
     * @param  array<string, string>  $variables
     * @param  array<string, mixed>  $comments
     * @return array{run: ConsoleAction, coalesced: bool}
     */
    private function persistAndPush(Site $site, array $variables, array $comments, ?string $userId): array
    {
        // Block obviously app-breaking env (empty APP_KEY, …) before we touch
        // the cache — same gate the pusher enforces, surfaced earlier here.
        app(SiteEnvWriteGuard::class)->assertSafeToWrite($variables);

        $rendered = app(DotEnvFileWriter::class)->render($variables, $comments);

        $site->forceFill([
            'env_file_content' => $rendered,
            'env_cache_origin' => 'local-edit',
        ])->save();

        return app(SiteEnvPushScheduler::class)->schedule($site, $userId);
    }
}
