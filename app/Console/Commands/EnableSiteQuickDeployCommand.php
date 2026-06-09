<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\SourceControl\GitIdentity;
use App\Models\GitProviderToken;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Services\Sites\RepositoryWebhookProvisioner;
use Illuminate\Console\Command;

/**
 * Register (or re-register) the provider push webhook for a site so Quick
 * deploy fires on every push to the deploy branch.
 *
 *   dply:site:webhook:enable <site> [--account=<identity-ulid>]
 *
 * Mirrors the "Enable quick deploy" button on the Webhook tab, but runnable
 * out-of-band — handy when the UI's resolved identity lacks the webhook scope
 * and you want to register the hook with a different token, or when scripting
 * setup. The provider (github/gitlab/bitbucket) is detected from the repo URL
 * and synced into meta first, so sites created outside the connection form
 * (e.g. serverless workers carrying a stale 'custom' kind) still work.
 */
class EnableSiteQuickDeployCommand extends Command
{
    protected $signature = 'dply:site:webhook:enable
        {site : Site ID, slug, or name}
        {--account= : Source-control identity ULID (SocialAccount or GitProviderToken). Defaults to the repo'."'".'s wired account, else the site owner'."'".'s first token for the provider.}';

    protected $description = 'Register the provider push webhook (Quick deploy) for a site.';

    public function handle(RepositoryWebhookProvisioner $provisioner): int
    {
        $needle = trim((string) $this->argument('site'));
        $site = Site::query()->where('id', $needle)->orWhere('slug', $needle)->orWhere('name', $needle)->first();
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $provider = $this->detectProviderKind((string) ($site->git_repository_url ?? ''));
        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            $this->error('Quick deploy needs a GitHub, GitLab, or Bitbucket repository URL — got: '.($site->git_repository_url ?: '<empty>'));

            return self::FAILURE;
        }

        $account = $this->resolveIdentity($site, $provider, (string) ($this->option('account') ?? ''));
        if ($account === null) {
            $this->error("No usable {$provider} identity found. Pass --account=<ulid> or link an account with the webhook scope.");

            return self::FAILURE;
        }

        // Sync provider kind + backing account into meta before the provisioner
        // reloads via ->fresh() (mergeRepositoryMeta only sets the attribute).
        $patch = ['git_provider_kind' => $provider];
        if ((string) ($site->repositoryMeta()['git_source_control_account_id'] ?? '') === '') {
            $patch['git_source_control_account_id'] = $account->id();
        }
        $site->mergeRepositoryMeta($patch);
        $site->save();

        $this->line("Registering {$provider} webhook for <fg=white;options=bold>{$site->name}</> using identity <fg=gray>{$account->id()}</> ({$account->kind()})…");

        $result = $provisioner->enable($site->fresh(), $account);
        if (! ($result['ok'] ?? false)) {
            $this->error((string) ($result['message'] ?? 'Could not enable quick deploy.'));

            return self::FAILURE;
        }

        $this->info((string) ($result['message'] ?? 'Quick deploy enabled.'));
        $this->line('  Deploy hook: <fg=gray>'.$site->deployHookUrl().'</>');

        return self::SUCCESS;
    }

    private function resolveIdentity(Site $site, string $provider, string $accountId): ?GitIdentity
    {
        // 1. Explicit --account, or whatever the repo already has wired.
        $id = $accountId !== '' ? $accountId : (string) ($site->repositoryMeta()['git_source_control_account_id'] ?? '');
        if ($id !== '') {
            $identity = SocialAccount::query()->find($id) ?? GitProviderToken::query()->find($id);
            if ($identity instanceof GitIdentity && $identity->accessToken() !== '') {
                return $identity;
            }
        }

        // 2. Fall back to the site owner's first usable identity for the provider.
        $ownerId = $site->user_id;
        if ($ownerId === null) {
            return null;
        }

        $oauth = SocialAccount::query()
            ->where('user_id', $ownerId)->where('provider', $provider)
            ->whereNotNull('access_token')->where('access_token', '!=', '')
            ->orderBy('id')->first();
        if ($oauth instanceof GitIdentity) {
            return $oauth;
        }

        $pat = GitProviderToken::query()
            ->where('user_id', $ownerId)->where('provider', $provider)
            ->orderBy('id')->first();

        return ($pat instanceof GitIdentity && $pat->accessToken() !== '') ? $pat : null;
    }

    private function detectProviderKind(string $url): string
    {
        $url = strtolower($url);

        return match (true) {
            str_contains($url, 'github.com') => 'github',
            str_contains($url, 'gitlab') => 'gitlab',
            str_contains($url, 'bitbucket.org') => 'bitbucket',
            default => 'custom',
        };
    }
}
