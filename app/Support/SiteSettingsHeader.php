<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Server;
use App\Models\Site;

/**
 * Per-section header metadata for the site settings workspace.
 *
 * Each entry returns the title/description/icon to render in the page header
 * when that section is active. Section-aware copy gives the operator a
 * specific orientation ("HTTP basic authentication", "Routing & redirects")
 * instead of the generic "Site workspace".
 */
final class SiteSettingsHeader
{
    /**
     * @return array{title: string, description: string, icon: string}
     */
    public static function for(Site $site, Server $server, string $section): array
    {
        if ($site->usesEdgeRuntime()) {
            return self::forEdge($section);
        }

        $resourceNoun = $site->runtimeTargetMode() === 'vm' ? __('site') : __('app');

        return match ($section) {
            'general' => [
                'title' => __('General'),
                'description' => __('Primary domain, working directory, and the headline metadata for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-rectangle-stack',
            ],
            'routing' => [
                'title' => __('Routing'),
                'description' => __('Domains, DNS automation, aliases, redirects, preview hosts, and tenant routing for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-share',
            ],
            'certificates' => [
                'title' => __('Certificates'),
                'description' => __('Issue and inspect TLS certificates that protect traffic to this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-shield-check',
            ],
            'deploy' => [
                'title' => __('Deployments'),
                'description' => __('Deploy history, triggers, and release rollback for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-code-bracket-square',
            ],
            'pipeline' => [
                'title' => __('Pipeline'),
                'description' => __('Build steps, deploy hooks, zero-downtime rollout, and post-activate checks for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-adjustments-horizontal',
            ],
            'repository' => [
                'title' => __('Repository'),
                'description' => __('Source control connection, branch tracking, and quick-deploy webhook for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-folder-open',
            ],
            'runtime' => [
                'title' => __('Runtime'),
                'description' => $site->usesFunctionsRuntime()
                    ? __('How this function executes — runtime, entrypoint, and the memory, timeout, and concurrency limits applied to the action.')
                    : __('What this :resource runs and how — language, processes, detection, and per-language tuning (PHP, Ruby, or Static) on the tabs below.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-cube-transparent',
            ],
            'system-user' => [
                'title' => __('System user'),
                'description' => __('The Linux user that owns this :resource on the server, plus permissions and sudo controls.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-user',
            ],
            'laravel-stack' => [
                'title' => __('Laravel'),
                'description' => __('Octane, Reverb, Horizon, Pulse, and Pail integrations for this Laravel :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-bolt',
            ],
            'wordpress' => [
                'title' => __('WordPress'),
                'description' => __('WordPress-specific settings and admin links for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-globe-alt',
            ],
            'environment' => [
                'title' => __('Environment'),
                'description' => __('Environment variables and secrets injected when this :resource builds and runs.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-command-line',
            ],
            'resources' => [
                'title' => __('Resources'),
                'description' => __('A visual map of this :resource and the resources wired to it — attach a database, cache, queue, storage, mail and more.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-puzzle-piece',
            ],
            'logs' => [
                'title' => __('Logs'),
                'description' => __('Deploy logs, runtime logs, and per-:resource log shortcuts.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-clipboard-document-list',
            ],
            'notifications' => [
                'title' => __('Notifications'),
                'description' => __('Channel routing for this :resource — pick who gets paged for which deploy and uptime events.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-bell',
            ],
            'basic-auth' => [
                'title' => __('HTTP basic authentication'),
                'description' => __('Username and password gate that the webserver checks before letting a request reach this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-lock-closed',
            ],
            'cli' => [
                'title' => __('CLI'),
                'description' => __('Install the dply CLI and manage this :resource from your terminal.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-command-line',
            ],
            'danger' => [
                'title' => __('Danger zone'),
                'description' => __('Suspend, archive, transfer, or delete this :resource. Actions here are scoped tightly and most are irreversible.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            default => [
                'title' => $site->name,
                'description' => __('Manage this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-rectangle-stack',
            ],
        };
    }

    /**
     * @return array{title: string, description: string, icon: string}
     */
    private static function forEdge(string $section): array
    {
        return match ($section) {
            'general' => [
                'title' => __('Overview'),
                'description' => __('Live URL, source repository, deploy status, and quick actions for this Edge site.'),
                'icon' => 'heroicon-o-home',
            ],
            'edge-deploys' => [
                'title' => __('Deploys'),
                'description' => __('Build and publish history — redeploy production or roll back to a previous release.'),
                'icon' => 'heroicon-o-code-bracket-square',
            ],
            'edge-domains' => [
                'title' => __('Domains'),
                'description' => __('Attach custom hostnames to this Edge site. TLS is provisioned automatically on the edge network.'),
                'icon' => 'heroicon-o-globe-alt',
            ],
            'edge-build' => [
                'title' => __('Build'),
                'description' => __('Build command, output directory, repo config snapshot, and deploy retention.'),
                'icon' => 'heroicon-o-wrench-screwdriver',
            ],
            'edge-routing' => [
                'title' => __('Routing'),
                'description' => __('Redirects, rewrites, and headers from dply.yaml (read-only).'),
                'icon' => 'heroicon-o-arrows-right-left',
            ],
            'edge-environment' => [
                'title' => __('Environment'),
                'description' => __('Production secrets injected at build and runtime.'),
                'icon' => 'heroicon-o-command-line',
            ],
            'edge-deploy-triggers' => [
                'title' => __('Deploy triggers'),
                'description' => __('Deploy hooks, GitHub auto-deploy webhooks, and deploy notification routing.'),
                'icon' => 'heroicon-o-bolt',
            ],
            'edge-members' => [
                'title' => __('Members'),
                'description' => __('Per-site viewer, deployer, and admin grants on top of org membership.'),
                'icon' => 'heroicon-o-user-group',
            ],
            'edge-delivery' => [
                'title' => __('Delivery'),
                'description' => __('Edge CDN backend, hybrid SSR origin, image optimization, and cache purge.'),
                'icon' => 'heroicon-o-cloud',
            ],
            'edge-previews' => [
                'title' => __('Previews'),
                'description' => __('Branch preview deployments, preview protection, and the review comment widget.'),
                'icon' => 'heroicon-o-sparkles',
            ],
            'edge-billing' => [
                'title' => __('Billing & usage'),
                'description' => __('Platform fee, delivery usage, and request/egress stats for this Edge site.'),
                'icon' => 'heroicon-o-chart-bar',
            ],
            'edge-traffic' => [
                'title' => __('Traffic & analytics'),
                'description' => __('CDN request and bandwidth stats from daily Cloudflare zone analytics — not real-time HTTP access logs.'),
                'icon' => 'heroicon-o-signal',
            ],
            'edge-logs' => [
                'title' => __('Build & deploy logs'),
                'description' => __('Recent build output and deployment activity — not HTTP visitor access logs.'),
                'icon' => 'heroicon-o-clipboard-document-list',
            ],
            'danger' => [
                'title' => __('Danger zone'),
                'description' => __('Permanently delete this Edge site and remove all deployments from the CDN.'),
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            default => [
                'title' => __('Edge site'),
                'description' => __('Manage this Edge site.'),
                'icon' => 'heroicon-o-globe-alt',
            ],
        };
    }
}
