<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Livewire\Sites\Settings;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 */
trait ResolvesSiteUrls
{
    /**
     * Naive apex guess from the primary site hostname (last two labels), e.g. app.example.com → example.com.
     */
    public function guessDnsZoneFromPrimaryHostname(): ?string
    {
        $this->loadMissing('domains');
        $host = strtolower(trim((string) optional($this->primaryDomain())->hostname));

        return self::apexGuessForHostname($host);
    }

    /**
     * Apex extraction helper for an arbitrary hostname — same rule as
     * {@see guessDnsZoneFromPrimaryHostname()} but doesn't read from the
     * site's current domain. Used by the rename-cascade planner to decide
     * whether the saved `dns_zone` was the operator's choice or matched
     * what dply would have auto-suggested from the *old* hostname.
     */
    public static function apexGuessForHostname(string $hostname): ?string
    {
        $host = strtolower(trim($hostname));
        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return null;
        }

        return $parts[count($parts) - 2].'.'.$parts[count($parts) - 1];
    }

    /**
     * True when the saved `dns_zone` equals the apex dply would auto-guess
     * from the supplied hostname. Treats an empty saved zone as "no operator
     * value" — also auto-derived for cascade purposes.
     */
    public function dnsZoneMatchesAutoGuessForHostname(string $hostname): bool
    {
        $saved = strtolower(trim((string) ($this->dns_zone ?? '')));
        if ($saved === '') {
            return true;
        }

        $guess = self::apexGuessForHostname($hostname);

        return $guess !== null && strtolower($guess) === $saved;
    }

    /**
     * What the value of {@see $git_branch} represents — a branch name, a
     * tag name, or a commit SHA. Deployers branch on this to pick clone
     * flags: branch/tag use `--branch`, commit needs a full clone + checkout.
     * Defaults to 'branch' for legacy sites with no meta value.
     *
     * @return 'branch'|'tag'|'commit'
     */
    public function gitRefKind(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $value = $meta['git_ref_kind'] ?? null;

        return in_array($value, ['branch', 'tag', 'commit'], true) ? $value : 'branch';
    }

    public function visitUrl(): ?string
    {
        if ($this->provisionedUrl() !== null) {
            return $this->provisionedUrl();
        }

        if (! $this->isReadyForTraffic()) {
            return null;
        }

        $hostname = $this->testingHostname();
        if ($hostname !== '') {
            return 'http://'.$hostname;
        }

        return ($this->primaryDomain()?->hostname)
            ? 'http://'.$this->primaryDomain()->hostname
            : null;
    }

    /**
     * Public URL of the site's custom logo (uploaded or pulled from its
     * favicon), or null when none is set — callers fall back to the generated
     * gradient + initials avatar. Stored on the `public` disk.
     */
    public function logoUrl(): ?string
    {
        $path = $this->logo_path;
        if (! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function hasLogo(): bool
    {
        return is_string($this->logo_path) && $this->logo_path !== '';
    }

    /**
     * Git remote URL for source-control API browsing (BYO git_repository_url or Edge source.repo).
     */
    public function sourceControlRepositoryUrl(): ?string
    {
        $direct = trim((string) $this->git_repository_url);
        if ($direct !== '') {
            return $direct;
        }

        if (! $this->usesEdgeRuntime()) {
            return null;
        }

        $source = is_array($this->edgeMeta()['source'] ?? null) ? $this->edgeMeta()['source'] : [];
        $repo = trim((string) ($source['repo'] ?? ''));
        if ($repo === '') {
            return null;
        }

        if (str_contains($repo, '://')) {
            return $repo;
        }

        return 'https://github.com/'.$repo.'.git';
    }

    /**
     * Web URL to view a commit on the site's git provider, derived from its
     * remote ({@see sourceControlRepositoryUrl()}). Handles SSH and HTTPS
     * remotes and the path quirks of GitHub (/commit), GitLab (/-/commit) and
     * Bitbucket (/commits). Null when there's no remote or sha.
     */
    public function commitWebUrl(?string $sha): ?string
    {
        $sha = trim((string) $sha);
        $remote = trim((string) $this->sourceControlRepositoryUrl());
        if ($sha === '' || $remote === '') {
            return null;
        }

        // git@host:owner/repo(.git) → host + owner/repo
        if (preg_match('#^[\w.-]+@([^:]+):(.+?)(?:\.git)?/?$#', $remote, $m) === 1) {
            [$host, $path] = [$m[1], $m[2]];
            // scheme://[user@]host/owner/repo(.git)
        } elseif (preg_match('#^[a-z]+://(?:[^@/]+@)?([^/]+)/(.+?)(?:\.git)?/?$#i', $remote, $m) === 1) {
            [$host, $path] = [$m[1], $m[2]];
        } else {
            return null;
        }

        $base = 'https://'.$host.'/'.$path;

        return match (true) {
            str_contains($host, 'gitlab') => $base.'/-/commit/'.$sha,
            str_contains($host, 'bitbucket') => $base.'/commits/'.$sha,
            default => $base.'/commit/'.$sha, // GitHub + generic
        };
    }

    public function deployHookUrl(): string
    {
        return route('hooks.site.deploy', ['site' => $this->id]);
    }

    /**
     * Signed URL CI can POST to for redeploying a cloud container
     * site. The signature uses Laravel's signed-route mechanism
     * keyed on APP_KEY — no expiry (CI scripts shouldn't have to
     * refresh the URL on a schedule). Operators can rotate by
     * regenerating webhook_secret on the site, which invalidates
     * the URL via that field's inclusion in the signature.
     */
    public function cloudRedeployHookUrl(): string
    {
        return URL::signedRoute(
            'hooks.cloud.redeploy',
            ['site' => $this->id, 's' => substr((string) $this->webhook_secret, 0, 8)],
        );
    }

    /**
     * Inbound GitHub webhook URL — paste this into the repository's
     * webhook settings on GitHub. The site's webhook_secret is the
     * shared HMAC-SHA256 signing secret operators paste alongside.
     * No URL signing here: GitHub signs the body, not the URL.
     */
    public function cloudGithubHookUrl(): string
    {
        return route('hooks.cloud.github', ['site' => $this->id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function repositoryMeta(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        return is_array($meta['repository'] ?? null) ? $meta['repository'] : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function mergeRepositoryMeta(array $patch): void
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $current = is_array($meta['repository'] ?? null) ? $meta['repository'] : [];
        $meta['repository'] = array_merge($current, $patch);
        $this->meta = $meta;
    }
}
