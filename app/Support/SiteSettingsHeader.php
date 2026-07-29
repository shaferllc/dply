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
                'title' => __('Routing'),
                'description' => __('Domains, redirects, rewrites, and headers.'),
                'icon' => 'heroicon-o-arrows-right-left',
            ],
            'edge-build' => [
                'title' => __('Build'),
                'description' => __('Command and output directory for each deploy.'),
                'icon' => 'heroicon-o-wrench-screwdriver',
            ],
            'edge-routing' => [
                'title' => __('Routing'),
                'description' => __('Domains, redirects, rewrites, and headers.'),
                'icon' => 'heroicon-o-arrows-right-left',
            ],
            'edge-error-pages' => [
                'title' => __('Error pages'),
                'description' => __('Custom 404 / 500 HTML and maintenance mode.'),
                'icon' => 'heroicon-o-exclamation-circle',
            ],
            'edge-environment' => [
                'title' => __('Environment'),
                'description' => __('Set production env vars for builds and runtime.'),
                'icon' => 'heroicon-o-command-line',
            ],
            'edge-deploy-triggers' => [
                'title' => __('Deploy triggers'),
                'description' => __('GitHub auto-deploy and CMS deploy hooks.'),
                'icon' => 'heroicon-o-bolt',
            ],
            'edge-bindings' => [
                'title' => __('Bindings'),
                'description' => __('Attach KV, R2, D1, and queues for your worker.'),
                'icon' => 'heroicon-o-puzzle-piece',
            ],
            'edge-members' => [
                'title' => __('Members'),
                'description' => __('Grant site access without changing org roles.'),
                'icon' => 'heroicon-o-user-group',
            ],
            'edge-delivery' => [
                'title' => __('Delivery'),
                'description' => __('Hybrid origin, image optimization, and cache tools.'),
                'icon' => 'heroicon-o-cloud',
            ],
            'edge-crons' => [
                'title' => __('Crons'),
                'description' => __('Scheduled worker invocations.'),
                'icon' => 'heroicon-o-clock',
            ],
            'edge-firewall' => [
                'title' => __('Firewall'),
                'description' => __('Allow or block traffic by country.'),
                'icon' => 'heroicon-o-shield-check',
            ],
            'edge-alerts' => [
                'title' => __('Alerts'),
                'description' => __('RUM and error thresholds for this Edge site.'),
                'icon' => 'heroicon-o-bell-alert',
            ],
            'edge-audit' => [
                'title' => __('Audit log'),
                'description' => __('Who changed what on this Edge site.'),
                'icon' => 'heroicon-o-clipboard-document-list',
            ],
            'edge-previews' => [
                'title' => __('Previews'),
                'description' => __('PR and ad-hoc preview deploys.'),
                'icon' => 'heroicon-o-sparkles',
            ],
            'edge-billing' => [
                'title' => __('Billing & usage'),
                'description' => __('Platform fee, usage, and monthly quota for this Edge site.'),
                'icon' => 'heroicon-o-chart-bar',
            ],
            'edge-traffic' => [
                'title' => __('Traffic & analytics'),
                'description' => __('CDN requests, bandwidth, and performance for this Edge site.'),
                'icon' => 'heroicon-o-signal',
            ],
            'edge-logs' => [
                'title' => __('Build & deploy logs'),
                'description' => __('Recent deploys, build output, and a live request tail.'),
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
