<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SiteDeployKeyGenerator;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A theme or plugin that comes from its own Git repository.
 *
 * @property string $id
 * @property string $site_id
 * @property string $kind
 * @property string $slug
 * @property string $repository_url
 * @property string $git_branch
 * @property ?string $composer_package
 * @property ?string $deploy_key_private
 * @property ?string $deploy_key_public
 * @property string $status
 * @property ?Carbon $last_synced_at
 * @property ?string $last_synced_commit
 * @property ?string $last_error
 * @property-read Site $site
 */
class SiteGitSource extends Model
{
    use HasFactory, HasUlids;

    public const KIND_THEME = 'theme';

    public const KIND_PLUGIN = 'plugin';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_ERROR = 'error';

    /** @var list<string> */
    public const KINDS = [self::KIND_THEME, self::KIND_PLUGIN];

    protected $fillable = [
        'site_id',
        'kind',
        'slug',
        'repository_url',
        'git_branch',
        'composer_package',
        'source_control_account_id',
        'connected_by_user_id',
        'deploy_key_private',
        'deploy_key_public',
        'status',
        'last_synced_at',
        'last_synced_commit',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Same treatment as sites.git_deploy_key_private.
            'deploy_key_private' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Directory this source materializes into, relative to the site's document
     * root parent. Classic WordPress keeps wp-content at the top level; Bedrock
     * moves it to web/app, and both use the same themes/ plugins/ split.
     */
    public function relativePath(bool $bedrock = false): string
    {
        $base = $bedrock ? 'web/app' : 'wp-content';

        return $base.'/'.($this->kind === self::KIND_THEME ? 'themes' : 'plugins').'/'.$this->slug;
    }

    /**
     * Give this source its own deploy keypair. Per-source rather than reusing
     * the site's so that revoking access to one private theme repo cannot
     * break every other source on the site.
     */
    public function ensureDeployKey(): void
    {
        if (($this->deploy_key_public ?? '') !== '') {
            return;
        }

        [$private, $public] = SiteDeployKeyGenerator::generate();

        $this->deploy_key_private = $private;
        $this->deploy_key_public = $public;
        $this->save();
    }

    public function markSyncing(): void
    {
        $this->forceFill(['status' => self::STATUS_SYNCING, 'last_error' => null])->save();
    }

    public function markSynced(?string $commit = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SYNCED,
            'last_synced_at' => now(),
            'last_synced_commit' => $commit,
            'last_error' => null,
        ])->save();
    }

    public function markError(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_ERROR,
            // Long git/composer output is useless in a table cell and unbounded
            // in the column, so keep the actionable head of it.
            'last_error' => mb_substr($error, 0, 2000),
        ])->save();
    }

    /**
     * Picked through a connected source-control account: clones with that
     * account's access, so it has no deploy key and never needs one installed.
     */
    public function isConnected(): bool
    {
        return trim((string) ($this->source_control_account_id ?? '')) !== '';
    }
}
