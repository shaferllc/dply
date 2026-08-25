<?php

declare(strict_types=1);

namespace App\Support\Cli;

/**
 * Indexed catalog of invokable `dply` CLI commands for in-app reference UIs.
 *
 * Kept in PHP (not scraped from the Node package) so Blade/Livewire can bind
 * server/site context into examples without shipping Node into the render path.
 * Mirror packages/dply-cli help when adding new commands.
 */
final class DplyCliCommandCatalog
{
    /**
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function groups(): array
    {
        return [
            ['key' => 'setup', 'label' => __('Setup'), 'description' => __('Install, login, shell, and help')],
            ['key' => 'account', 'label' => __('Account'), 'description' => __('Profile, orgs, sessions, auth')],
            ['key' => 'server', 'label' => __('Server'), 'description' => __('BYO VMs — show, run, firewall, users')],
            ['key' => 'site', 'label' => __('Site (BYO)'), 'description' => __('VM sites — deploy, logs, env')],
            ['key' => 'project', 'label' => __('Projects'), 'description' => __('Group servers + sites')],
            ['key' => 'billing', 'label' => __('Billing'), 'description' => __('Plan estimate and invoices')],
            ['key' => 'edge', 'label' => __('Edge'), 'description' => __('Static / SSG Edge sites')],
            ['key' => 'shortcuts', 'label' => __('Shortcuts'), 'description' => __('Single-token aliases')],
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     group: string,
     *     title: string,
     *     command: string,
     *     summary: string,
     *     keywords: string,
     *     scope: string|null,
     *     server_bound: bool
     * }>
     */
    public static function entries(?string $serverId = null): array
    {
        $serverFlag = filled($serverId) ? '--server '.$serverId : '--server <id>';

        /** @var list<array{id: string, group: string, title: string, command: string, summary: string, keywords?: string, scope?: string|null, server_bound?: bool}> $raw */
        $raw = [
            // Setup / shell
            ['id' => 'login', 'group' => 'setup', 'title' => 'login', 'command' => 'dply login', 'summary' => 'Browser device-flow login, then interactive shell.', 'keywords' => 'auth sign-in authenticate'],
            ['id' => 'login-no-shell', 'group' => 'setup', 'title' => 'login --no-shell', 'command' => 'dply login --no-shell', 'summary' => 'Authenticate without dropping into the interactive shell (CI).', 'keywords' => 'ci scripts'],
            ['id' => 'logout', 'group' => 'setup', 'title' => 'logout', 'command' => 'dply logout', 'summary' => 'Remove the saved token from this machine.', 'keywords' => 'sign-out'],
            ['id' => 'menu', 'group' => 'setup', 'title' => 'menu', 'command' => 'dply menu', 'summary' => 'Numbered menus — type names or numbers.', 'keywords' => 'browse interactive'],
            ['id' => 'shell', 'group' => 'setup', 'title' => 'shell', 'command' => 'dply shell', 'summary' => 'Interactive mode (same as bare `dply` on a TTY).', 'keywords' => 'repl interactive'],
            ['id' => 'whoami', 'group' => 'setup', 'title' => 'whoami', 'command' => 'dply whoami', 'summary' => 'Show account + session (alias for account show).', 'keywords' => 'me identity'],
            ['id' => 'help', 'group' => 'setup', 'title' => 'help', 'command' => 'dply help', 'summary' => 'Top-level command help.', 'keywords' => 'usage docs'],
            ['id' => 'ls', 'group' => 'setup', 'title' => 'ls', 'command' => 'dply ls', 'summary' => 'Compact command index. Scopes: top · account · server · site · edge · billing · shortcuts.', 'keywords' => 'index list commands'],
            ['id' => 'ls-server', 'group' => 'setup', 'title' => 'ls server', 'command' => 'dply ls server', 'summary' => 'List server-family commands only.', 'keywords' => 'index'],
            ['id' => 'guide', 'group' => 'setup', 'title' => 'guide', 'command' => 'dply guide', 'summary' => 'Short getting-started guide in the terminal.', 'keywords' => 'onboarding'],
            ['id' => 'update', 'group' => 'setup', 'title' => 'update', 'command' => 'dply update', 'summary' => 'Install the CLI build this instance is serving — how you pick up new commands.', 'keywords' => 'upgrade newer version outdated install'],
            ['id' => 'update-check', 'group' => 'setup', 'title' => 'update --check', 'command' => 'dply update --check', 'summary' => 'Report only; exits 1 when your build differs from the instance.', 'keywords' => 'upgrade ci version'],
            ['id' => 'link', 'group' => 'setup', 'title' => 'link', 'command' => 'dply link', 'summary' => 'Interactive picker — link repo to BYO or Edge (.dply/site.json).', 'keywords' => 'repo link'],
            ['id' => 'link-byo', 'group' => 'setup', 'title' => 'link --byo', 'command' => 'dply link --byo <site>', 'summary' => 'Link this repo to a BYO site for bare `dply deploy`.', 'keywords' => 'byo'],
            ['id' => 'link-edge', 'group' => 'setup', 'title' => 'link --edge', 'command' => 'dply link --edge <site>', 'summary' => 'Link this repo to an Edge site.', 'keywords' => 'edge'],
            ['id' => 'deploy-linked', 'group' => 'setup', 'title' => 'deploy', 'command' => 'dply deploy --follow', 'summary' => 'Deploy linked repo (BYO or Edge via .dply/site.json).', 'keywords' => 'ship release'],
            ['id' => 'deploy-ci', 'group' => 'setup', 'title' => 'deploy --sync --wait', 'command' => 'dply deploy --sync --wait --idempotency-key <sha>', 'summary' => 'CI-friendly linked deploy — wait until finished.', 'keywords' => 'ci github actions'],

            // Account
            ['id' => 'account-show', 'group' => 'account', 'title' => 'account show', 'command' => 'dply account show', 'summary' => 'Profile, org, token, and abilities.', 'scope' => 'account.read'],
            ['id' => 'account-orgs', 'group' => 'account', 'title' => 'account orgs', 'command' => 'dply account orgs', 'summary' => 'Organizations this user belongs to.', 'scope' => 'account.read'],
            ['id' => 'account-projects', 'group' => 'account', 'title' => 'account projects', 'command' => 'dply account projects', 'summary' => 'Projects in the current organization.', 'scope' => 'projects.read'],
            ['id' => 'account-sessions', 'group' => 'account', 'title' => 'account sessions', 'command' => 'dply account sessions', 'summary' => 'Active CLI sessions in this org.', 'scope' => 'account.read'],
            ['id' => 'account-revoke', 'group' => 'account', 'title' => 'account revoke', 'command' => 'dply account revoke <session-id>', 'summary' => 'Revoke a CLI session by ID.', 'scope' => 'account.write'],
            ['id' => 'account-refresh', 'group' => 'account', 'title' => 'account refresh', 'command' => 'dply account refresh', 'summary' => 'Re-approve scopes for more permissions.', 'keywords' => 'scopes'],
            ['id' => 'account-logout', 'group' => 'account', 'title' => 'account logout', 'command' => 'dply account logout', 'summary' => 'Remove the saved token (same as `dply logout`).'],
            ['id' => 'auth-refresh', 'group' => 'account', 'title' => 'auth refresh', 'command' => 'dply auth refresh', 'summary' => 'Device-flow re-approval for additional scopes.', 'keywords' => 'scopes r refresh'],
            ['id' => 'refresh', 'group' => 'account', 'title' => 'refresh', 'command' => 'dply refresh', 'summary' => 'Shortcut for auth refresh.', 'keywords' => 'scopes r'],

            // Server
            ['id' => 'server-list', 'group' => 'server', 'title' => 'server list', 'command' => 'dply server list', 'summary' => 'List VM servers in your organization.', 'scope' => 'servers.read', 'keywords' => 'vm byo'],
            ['id' => 'server-show', 'group' => 'server', 'title' => 'server show', 'command' => 'dply server show '.$serverFlag, 'summary' => 'One server + BYO sites on it.', 'scope' => 'servers.read', 'server_bound' => true],
            ['id' => 'server-health', 'group' => 'server', 'title' => 'server health', 'command' => 'dply server health '.$serverFlag, 'summary' => 'Status + open insight findings.', 'scope' => 'insights.read', 'server_bound' => true, 'keywords' => 'insights'],
            ['id' => 'server-run', 'group' => 'server', 'title' => 'server run', 'command' => 'dply server run '.$serverFlag.' uptime', 'summary' => 'Ad-hoc SSH command. Needs commands.run scope.', 'scope' => 'commands.run', 'server_bound' => true, 'keywords' => 'ssh exec shell'],
            ['id' => 'server-run-command', 'group' => 'server', 'title' => 'server run --command', 'command' => 'dply server run '.$serverFlag.' --command "df -h"', 'summary' => 'Quoted remote command via --command.', 'scope' => 'commands.run', 'server_bound' => true],
            ['id' => 'server-firewall-show', 'group' => 'server', 'title' => 'server firewall show', 'command' => 'dply server firewall show '.$serverFlag, 'summary' => 'List UFW rules, org templates, bundled keys.', 'scope' => 'network.read', 'server_bound' => true, 'keywords' => 'ufw'],
            ['id' => 'server-firewall-apply', 'group' => 'server', 'title' => 'server firewall apply', 'command' => 'dply server firewall apply '.$serverFlag.' --ack-ssh-lockout', 'summary' => 'Push dply rules to the VM (UFW).', 'scope' => 'network.write', 'server_bound' => true],
            ['id' => 'server-firewall-bundled', 'group' => 'server', 'title' => 'server firewall apply-bundled', 'command' => 'dply server firewall apply-bundled laravel_web '.$serverFlag, 'summary' => 'Merge a bundled starter ruleset.', 'scope' => 'network.write', 'server_bound' => true],
            ['id' => 'server-firewall-template', 'group' => 'server', 'title' => 'server firewall apply-template', 'command' => 'dply server firewall apply-template <template-id> '.$serverFlag, 'summary' => 'Merge an org firewall template.', 'scope' => 'network.write', 'server_bound' => true],
            ['id' => 'server-firewall-help', 'group' => 'server', 'title' => 'server firewall help', 'command' => 'dply server firewall help', 'summary' => 'Firewall subcommand help.'],
            ['id' => 'server-users-list', 'group' => 'server', 'title' => 'server system-users list', 'command' => 'dply server system-users list '.$serverFlag, 'summary' => 'List Linux users from the dply snapshot.', 'scope' => 'system_users.read', 'server_bound' => true, 'keywords' => 'linux accounts'],
            ['id' => 'server-users-sync', 'group' => 'server', 'title' => 'server system-users sync', 'command' => 'dply server system-users sync '.$serverFlag, 'summary' => 'SSH-sync /etc/passwd into dply.', 'scope' => 'system_users.write', 'server_bound' => true],
            ['id' => 'server-users-add', 'group' => 'server', 'title' => 'server system-users add', 'command' => 'dply server system-users add deployer '.$serverFlag.' --sudo', 'summary' => 'Queue user creation (--shell, --sudo, --no-web-group).', 'scope' => 'system_users.write', 'server_bound' => true],
            ['id' => 'server-users-update', 'group' => 'server', 'title' => 'server system-users update', 'command' => 'dply server system-users update deployer '.$serverFlag.' --sudo', 'summary' => 'Queue shell / sudo / web-group changes.', 'scope' => 'system_users.write', 'server_bound' => true],
            ['id' => 'server-users-remove', 'group' => 'server', 'title' => 'server system-users remove', 'command' => 'dply server system-users remove deployer '.$serverFlag, 'summary' => 'Queue user removal.', 'scope' => 'system_users.delete', 'server_bound' => true],
            ['id' => 'server-users-help', 'group' => 'server', 'title' => 'server system-users help', 'command' => 'dply server system-users help', 'summary' => 'System-users subcommand help.'],
            ['id' => 'server-shared-host', 'group' => 'server', 'title' => 'server shared-host explain', 'command' => 'dply server shared-host explain '.$serverFlag, 'summary' => 'Multi-site fairness advisor briefing.', 'scope' => 'insights.read', 'server_bound' => true, 'keywords' => 'radar contention'],
            ['id' => 'server-help', 'group' => 'server', 'title' => 'server help', 'command' => 'dply server help', 'summary' => 'Server command family help.'],

            // Site BYO
            ['id' => 'site-list', 'group' => 'site', 'title' => 'site list', 'command' => 'dply site list', 'summary' => 'List VM-hosted sites.', 'scope' => 'sites.read', 'keywords' => 'byo'],
            ['id' => 'site-show', 'group' => 'site', 'title' => 'site show', 'command' => 'dply site show --site <site>', 'summary' => 'Site details by id or name.', 'scope' => 'sites.read'],
            ['id' => 'site-status', 'group' => 'site', 'title' => 'site status', 'command' => 'dply site status --site <site>', 'summary' => 'Site + latest deployment summary.', 'scope' => 'sites.read'],
            ['id' => 'site-deploy', 'group' => 'site', 'title' => 'site deploy', 'command' => 'dply site deploy --site <site> --follow', 'summary' => 'Queue a deploy (--sync · --follow/--wait · --idempotency-key).', 'scope' => 'sites.deploy', 'keywords' => 'ship'],
            ['id' => 'site-logs', 'group' => 'site', 'title' => 'site logs', 'command' => 'dply site logs --site <site> --follow', 'summary' => 'Latest deploy log · --follow to tail.', 'scope' => 'sites.read'],
            ['id' => 'site-errors', 'group' => 'site', 'title' => 'errors', 'command' => 'dply errors --site <site>', 'summary' => 'Open error events for a site. Exits 1 when any are open.', 'scope' => 'sites.read', 'keywords' => 'failures problems incidents'],
            ['id' => 'site-errors-full', 'group' => 'site', 'title' => 'errors --full', 'command' => 'dply errors --site <site> --full', 'summary' => 'Open error events with detail + remediation codes.', 'scope' => 'sites.read'],
            ['id' => 'site-errors-watch', 'group' => 'site', 'title' => 'errors --watch', 'command' => 'dply errors --site <site> --watch', 'summary' => 'Poll for new error events (--interval ms).', 'scope' => 'sites.read', 'keywords' => 'tail follow'],
            ['id' => 'sites-all', 'group' => 'site', 'title' => 'sites', 'command' => 'dply sites', 'summary' => 'Every site — VM, Cloud, Edge — with its kind.', 'scope' => 'sites.read', 'keywords' => 'list apps everything'],
            ['id' => 'sites-kind', 'group' => 'site', 'title' => 'sites --kind', 'command' => 'dply sites --kind cloud', 'summary' => 'One product: vm | cloud | edge.', 'scope' => 'sites.read', 'keywords' => 'filter product kind'],
            ['id' => 'site-notifications', 'group' => 'site', 'title' => 'notifications', 'command' => 'dply notifications --site <site>', 'summary' => 'What fires for a site, and which channels get it.', 'scope' => 'notifications.read', 'keywords' => 'alerts channels routing subscriptions'],
            ['id' => 'site-notifications-channels', 'group' => 'site', 'title' => 'notifications channels', 'command' => 'dply notifications channels', 'summary' => 'Channels this token can route events to.', 'scope' => 'notifications.read', 'keywords' => 'slack email webhook pagerduty'],
            ['id' => 'site-notifications-subscribe', 'group' => 'site', 'title' => 'notifications subscribe', 'command' => 'dply notifications subscribe <event> --channel <id> --site <site>', 'summary' => 'Route an event to a channel (unsubscribe to undo).', 'scope' => 'notifications.write', 'keywords' => 'route alert subscribe'],
            ['id' => 'site-notifications-test', 'group' => 'site', 'title' => 'notifications test', 'command' => 'dply notifications test <channel>', 'summary' => 'Send a channel its test message.', 'scope' => 'notifications.write', 'keywords' => 'test ping verify'],
            ['id' => 'site-uptime', 'group' => 'site', 'title' => 'uptime', 'command' => 'dply uptime --site <site>', 'summary' => 'Uptime monitors: status, code, latency, region.', 'scope' => 'sites.read', 'keywords' => 'monitor monitors health up down'],
            ['id' => 'site-uptime-history', 'group' => 'site', 'title' => 'uptime history', 'command' => 'dply uptime history --site <site>', 'summary' => '24h / 7d / 30d uptime and recent incidents.', 'scope' => 'sites.read', 'keywords' => 'incidents downtime sla'],
            ['id' => 'site-uptime-check', 'group' => 'site', 'title' => 'uptime check', 'command' => 'dply uptime check <id> --site <site>', 'summary' => 'Probe a monitor now (--all for every monitor).', 'scope' => 'sites.write', 'keywords' => 'probe recheck check now'],
            ['id' => 'site-errors-dismiss', 'group' => 'site', 'title' => 'errors dismiss', 'command' => 'dply errors dismiss <id> --site <site>', 'summary' => 'Dismiss one error, or --all to clear the stream.', 'scope' => 'sites.write', 'keywords' => 'clear ack acknowledge resolve'],
            ['id' => 'site-errors-retry', 'group' => 'site', 'title' => 'errors retry', 'command' => 'dply errors retry <id> --site <site>', 'summary' => 'Re-run the operation behind a retryable error.', 'scope' => 'commands.run', 'keywords' => 're-run rerun again'],
            ['id' => 'site-errors-fix', 'group' => 'site', 'title' => 'errors fix', 'command' => 'dply errors fix <id> --site <site>', 'summary' => 'Queue the catalogued remediation (--action <key>).', 'scope' => 'commands.run', 'keywords' => 'remediate remediation repair heal'],
            ['id' => 'site-deployments', 'group' => 'site', 'title' => 'site deployments', 'command' => 'dply site deployments --site <site>', 'summary' => 'Recent deploy runs.', 'scope' => 'sites.read'],
            ['id' => 'site-deployment', 'group' => 'site', 'title' => 'site deployment', 'command' => 'dply site deployment <id> --site <site>', 'summary' => 'One deploy run + logs.', 'scope' => 'sites.read'],
            ['id' => 'site-env-list', 'group' => 'site', 'title' => 'site env list', 'command' => 'dply site env --site <site> list', 'summary' => 'List environment variables.', 'scope' => 'sites.read', 'keywords' => '.env'],
            ['id' => 'site-env-set', 'group' => 'site', 'title' => 'site env set', 'command' => 'dply site env --site <site> set KEY=value', 'summary' => 'Set an environment variable.', 'scope' => 'sites.write'],
            ['id' => 'site-env-rm', 'group' => 'site', 'title' => 'site env rm', 'command' => 'dply site env --site <site> rm KEY', 'summary' => 'Remove an environment variable.', 'scope' => 'sites.write'],
            ['id' => 'site-env-push', 'group' => 'site', 'title' => 'site env push', 'command' => 'dply site env --site <site> push --file .env', 'summary' => 'Push a local .env file to the site.', 'scope' => 'sites.write'],
            ['id' => 'site-help', 'group' => 'site', 'title' => 'site help', 'command' => 'dply site help', 'summary' => 'BYO site command help.'],

            // Projects
            ['id' => 'project-list', 'group' => 'project', 'title' => 'project list', 'command' => 'dply project list', 'summary' => 'List projects in this organization.', 'scope' => 'projects.read'],
            ['id' => 'project-show', 'group' => 'project', 'title' => 'project show', 'command' => 'dply project show <project>', 'summary' => 'Project details + attached resources.', 'scope' => 'projects.read'],
            ['id' => 'project-create', 'group' => 'project', 'title' => 'project create', 'command' => 'dply project create --name "…" [--description]', 'summary' => 'Create a project grouping.', 'scope' => 'projects.write'],
            ['id' => 'project-update', 'group' => 'project', 'title' => 'project update', 'command' => 'dply project update <project> --name|--description|--notes', 'summary' => 'Update project metadata.', 'scope' => 'projects.write'],
            ['id' => 'project-delete', 'group' => 'project', 'title' => 'project delete', 'command' => 'dply project delete <project>', 'summary' => 'Remove project grouping (org admin).', 'scope' => 'projects.delete'],
            ['id' => 'project-health', 'group' => 'project', 'title' => 'project health', 'command' => 'dply project health <project>', 'summary' => 'Health summary for grouped resources.', 'scope' => 'projects.read'],
            ['id' => 'project-deploy', 'group' => 'project', 'title' => 'project deploy', 'command' => 'dply project deploy <project>', 'summary' => 'Deploy all or selected sites in a project.', 'scope' => 'projects.deploy'],
            ['id' => 'project-deploys', 'group' => 'project', 'title' => 'project deploys', 'command' => 'dply project deploys <project>', 'summary' => 'Recent project deploy runs.', 'scope' => 'projects.read'],
            ['id' => 'project-members', 'group' => 'project', 'title' => 'project members', 'command' => 'dply project members list <project>', 'summary' => 'list | add | remove project members.', 'scope' => 'projects.write'],
            ['id' => 'project-attach', 'group' => 'project', 'title' => 'project attach', 'command' => 'dply project attach server <project> <server-id>', 'summary' => 'Attach a server or site to a project.', 'scope' => 'projects.write'],
            ['id' => 'project-detach', 'group' => 'project', 'title' => 'project detach', 'command' => 'dply project detach server <project> <server-id>', 'summary' => 'Detach a server or site from a project.', 'scope' => 'projects.write'],
            ['id' => 'project-environments', 'group' => 'project', 'title' => 'project environments', 'command' => 'dply project environments list <project>', 'summary' => 'list | add | remove environments.', 'scope' => 'projects.write'],
            ['id' => 'project-variables', 'group' => 'project', 'title' => 'project variables', 'command' => 'dply project variables list <project>', 'summary' => 'list | set KEY=val | remove.', 'scope' => 'projects.write'],
            ['id' => 'project-runbooks', 'group' => 'project', 'title' => 'project runbooks', 'command' => 'dply project runbooks list <project>', 'summary' => 'list | add | remove runbooks.', 'scope' => 'projects.write'],
            ['id' => 'project-help', 'group' => 'project', 'title' => 'project help', 'command' => 'dply project help', 'summary' => 'Project command family help.'],

            // Billing
            ['id' => 'billing-show', 'group' => 'billing', 'title' => 'billing show', 'command' => 'dply billing show', 'summary' => 'Plan + monthly estimate (org admin).', 'scope' => 'billing.read'],
            ['id' => 'billing-breakdown', 'group' => 'billing', 'title' => 'billing breakdown', 'command' => 'dply billing breakdown', 'summary' => 'Category + line-item estimate.', 'scope' => 'billing.read'],
            ['id' => 'billing-invoices', 'group' => 'billing', 'title' => 'billing invoices', 'command' => 'dply billing invoices', 'summary' => 'Recent Stripe invoices.', 'scope' => 'billing.read'],
            ['id' => 'billing-help', 'group' => 'billing', 'title' => 'billing help', 'command' => 'dply billing help', 'summary' => 'Billing command help.'],

            // Edge
            ['id' => 'sites-edge', 'group' => 'edge', 'title' => 'sites', 'command' => 'dply sites', 'summary' => 'List Edge sites visible to your token.', 'scope' => 'edge.read', 'keywords' => 'edge list'],
            ['id' => 'edge-deploy', 'group' => 'edge', 'title' => 'edge deploy', 'command' => 'dply edge deploy --site <site>', 'summary' => 'Queue a deploy (--commit / --branch / --prod).', 'scope' => 'edge.deploy'],
            ['id' => 'edge-deployments', 'group' => 'edge', 'title' => 'edge deployments', 'command' => 'dply edge deployments --site <site>', 'summary' => 'List recent Edge deployments.', 'scope' => 'edge.read'],
            ['id' => 'edge-status', 'group' => 'edge', 'title' => 'edge status', 'command' => 'dply edge status --site <site>', 'summary' => 'Edge site + latest deployment.', 'scope' => 'edge.read'],
            ['id' => 'edge-status-wait', 'group' => 'edge', 'title' => 'edge status --wait', 'command' => 'dply edge status --site <site> --wait', 'summary' => 'Block until the latest deploy finishes.', 'scope' => 'edge.read'],
            ['id' => 'edge-lint', 'group' => 'edge', 'title' => 'edge lint', 'command' => 'dply edge lint', 'summary' => 'Validate dply.yaml in cwd (--path).', 'keywords' => 'yaml config'],
            ['id' => 'edge-open', 'group' => 'edge', 'title' => 'edge open', 'command' => 'dply edge open --site <site>', 'summary' => 'Open live URL (--dashboard for workspace).', 'scope' => 'edge.read'],
            ['id' => 'edge-rollback', 'group' => 'edge', 'title' => 'edge rollback', 'command' => 'dply edge rollback --site <site> <deployment>', 'summary' => 'Re-point production at a prior deployment.', 'scope' => 'edge.deploy'],
            ['id' => 'edge-promote', 'group' => 'edge', 'title' => 'edge promote', 'command' => 'dply edge promote --site <site> <preview>', 'summary' => 'Promote a preview to production.', 'scope' => 'edge.deploy'],
            ['id' => 'edge-previews-list', 'group' => 'edge', 'title' => 'edge previews list', 'command' => 'dply edge previews list --site <site>', 'summary' => 'List preview deployments.', 'scope' => 'edge.read'],
            ['id' => 'edge-previews-create', 'group' => 'edge', 'title' => 'edge previews create', 'command' => 'dply edge previews create --site <site> [--commit|--branch] [--wait]', 'summary' => 'Create a preview deployment.', 'scope' => 'edge.deploy'],
            ['id' => 'edge-previews-rm', 'group' => 'edge', 'title' => 'edge previews rm', 'command' => 'dply edge previews rm <id> --site <site>', 'summary' => 'Remove a preview.', 'scope' => 'edge.write'],
            ['id' => 'edge-domains-list', 'group' => 'edge', 'title' => 'edge domains list', 'command' => 'dply edge domains list --site <site>', 'summary' => 'List custom domains.', 'scope' => 'edge.read'],
            ['id' => 'edge-domains-add', 'group' => 'edge', 'title' => 'edge domains add', 'command' => 'dply edge domains add <host> --site <site>', 'summary' => 'Add a custom domain.', 'scope' => 'edge.write'],
            ['id' => 'edge-domains-verify', 'group' => 'edge', 'title' => 'edge domains verify', 'command' => 'dply edge domains verify <host> --site <site>', 'summary' => 'Verify domain ownership / DNS.', 'scope' => 'edge.write'],
            ['id' => 'edge-domains-rm', 'group' => 'edge', 'title' => 'edge domains rm', 'command' => 'dply edge domains rm <host> --site <site>', 'summary' => 'Remove a custom domain.', 'scope' => 'edge.write'],
            ['id' => 'edge-aliases', 'group' => 'edge', 'title' => 'edge aliases', 'command' => 'dply edge aliases --site <site>', 'summary' => 'List per-deploy stable URLs.', 'scope' => 'edge.read'],
            ['id' => 'edge-purge', 'group' => 'edge', 'title' => 'edge purge', 'command' => 'dply edge purge --site <site> --tag <tag>', 'summary' => 'Purge edge cache by tag.', 'scope' => 'edge.write', 'keywords' => 'cache cdn'],
            ['id' => 'edge-usage', 'group' => 'edge', 'title' => 'edge usage', 'command' => 'dply edge usage --site <site>', 'summary' => 'Traffic / billing usage.', 'scope' => 'edge.read'],
            ['id' => 'edge-logs', 'group' => 'edge', 'title' => 'edge logs', 'command' => 'dply edge logs --site <site>', 'summary' => 'Tail request logs (--interval · --window · --once).', 'scope' => 'edge.read'],
            ['id' => 'edge-env-list', 'group' => 'edge', 'title' => 'edge env list', 'command' => 'dply edge env list --site <site>', 'summary' => 'list | set | rm | push | pull environment variables.', 'scope' => 'edge.read'],
            ['id' => 'edge-env-set', 'group' => 'edge', 'title' => 'edge env set', 'command' => 'dply edge env set KEY=value --site <site>', 'summary' => 'Set an Edge environment variable.', 'scope' => 'edge.write'],
            ['id' => 'edge-env-push', 'group' => 'edge', 'title' => 'edge env push', 'command' => 'dply edge env push --file .env --site <site>', 'summary' => 'Push a local .env to Edge.', 'scope' => 'edge.write'],
            ['id' => 'edge-env-pull', 'group' => 'edge', 'title' => 'edge env pull', 'command' => 'dply edge env pull --site <site>', 'summary' => 'Pull Edge env vars to stdout / file.', 'scope' => 'edge.read'],

            // Shortcuts
            ['id' => 'sc-r', 'group' => 'shortcuts', 'title' => 'r', 'command' => 'dply r', 'summary' => '→ auth refresh', 'keywords' => 'refresh scopes'],
            ['id' => 'sc-me', 'group' => 'shortcuts', 'title' => 'me', 'command' => 'dply me', 'summary' => '→ whoami', 'keywords' => 'who'],
            ['id' => 'sc-m', 'group' => 'shortcuts', 'title' => 'm', 'command' => 'dply m', 'summary' => '→ menu'],
            ['id' => 'sc-servers', 'group' => 'shortcuts', 'title' => 'servers', 'command' => 'dply servers', 'summary' => '→ server list', 'keywords' => 'sv'],
            ['id' => 'sc-sv', 'group' => 'shortcuts', 'title' => 'sv', 'command' => 'dply sv', 'summary' => '→ server list'],
            ['id' => 'sc-projects', 'group' => 'shortcuts', 'title' => 'projects', 'command' => 'dply projects', 'summary' => '→ project list', 'keywords' => 'p projs'],
            ['id' => 'sc-p', 'group' => 'shortcuts', 'title' => 'p', 'command' => 'dply p', 'summary' => '→ project list'],
            ['id' => 'sc-create', 'group' => 'shortcuts', 'title' => 'create', 'command' => 'dply create', 'summary' => '→ project create', 'keywords' => 'new'],
            ['id' => 'sc-orgs', 'group' => 'shortcuts', 'title' => 'orgs', 'command' => 'dply orgs', 'summary' => '→ account orgs'],
            ['id' => 'sc-bill', 'group' => 'shortcuts', 'title' => 'bill', 'command' => 'dply bill', 'summary' => '→ billing show'],
            ['id' => 'sc-sites', 'group' => 'shortcuts', 'title' => 'sites', 'command' => 'dply sites', 'summary' => 'List Edge sites (also a top-level command).'],
        ];

        return array_map(static function (array $entry): array {
            return [
                'id' => $entry['id'],
                'group' => $entry['group'],
                'title' => $entry['title'],
                'command' => $entry['command'],
                'summary' => $entry['summary'],
                'keywords' => trim(($entry['keywords'] ?? '').' '.$entry['title'].' '.$entry['command'].' '.$entry['summary']),
                'scope' => $entry['scope'] ?? null,
                'server_bound' => (bool) ($entry['server_bound'] ?? false),
            ];
        }, $raw);
    }

    /**
     * @return array{groups: list<array{key: string, label: string, description: string, count: int}>, entries: list<array<string, mixed>>, total: int}
     */
    public static function forServer(?string $serverId = null): array
    {
        $entries = self::entries($serverId);
        $counts = [];
        foreach ($entries as $entry) {
            $counts[$entry['group']] = ($counts[$entry['group']] ?? 0) + 1;
        }

        $groups = array_map(static function (array $group) use ($counts): array {
            return [
                ...$group,
                'count' => $counts[$group['key']] ?? 0,
            ];
        }, self::groups());

        return [
            'groups' => $groups,
            'entries' => $entries,
            'total' => count($entries),
        ];
    }
}
