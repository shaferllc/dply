<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $after_body
 * @property string $before_body
 * @property ?Carbon $draft_saved_at
 * @property string $full_override_body
 * @property ?Carbon $last_applied_at
 * @property string $last_applied_core_hash
 * @property string $last_applied_effective_checksum
 * @property string $main_snippet_body
 * @property string $mode
 * @property ?string $site_id
 * @property string $webserver
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SiteWebserverConfigProfile extends Model
{
    use HasUlids;

    public const MODE_LAYERED = 'layered';

    public const MODE_FULL_OVERRIDE = 'full_override';

    /**
     * Default contents for the nginx "before" layer snippet. Seeded into new
     * layered profiles and written to disk as the fallback so the file is never
     * empty (the include glob always matches a real, self-documenting file).
     */
    public const DEFAULT_BEFORE_BODY = <<<'CONF'
        # dply "before" layer — included first, inside the server block.
        # Add NGINX directives here that should apply before the managed vhost
        # (e.g. custom headers, access rules, rate limiting). Managed by dply;
        # edit from the site's NGINX config editor.
        CONF;

    /**
     * Default contents for the nginx "after" layer snippet. See {@see DEFAULT_BEFORE_BODY}.
     */
    public const DEFAULT_AFTER_BODY = <<<'CONF'
        # dply "after" layer — included last, inside the server block.
        # Add NGINX directives here that should apply after the managed vhost
        # (e.g. extra location blocks, overrides). Managed by dply; edit from
        # the site's NGINX config editor.
        CONF;

    /**
     * Default contents for the main snippet, which dply merges into the managed
     * vhost (between the "before" and "after" includes). Seeded so the "Server"
     * layer opens with a documented starting point instead of a blank box; the
     * full generated vhost is viewable under the "Effective preview" tab.
     */
    public const DEFAULT_MAIN_SNIPPET_BODY = <<<'CONF'
        # dply "main" snippet — merged into the managed vhost server block.
        # Add NGINX directives here to extend the generated config (e.g. extra
        # location blocks, headers, proxy rules). This is NOT the whole vhost —
        # see the "Effective preview" tab for the full file dply assembles.
        CONF;

    /**
     * Legacy placeholder bodies older sites have on disk; normalized to the
     * informative defaults above when hydrating the editor.
     */
    public const LEGACY_BEFORE_PLACEHOLDER = '# Dply placeholder (empty before layer)';

    public const LEGACY_AFTER_PLACEHOLDER = '# Dply placeholder (empty after layer)';

    protected $fillable = [
        'site_id',
        'webserver',
        'mode',
        'before_body',
        'main_snippet_body',
        'after_body',
        'full_override_body',
        'last_applied_effective_checksum',
        'last_applied_core_hash',
        'last_applied_at',
        'draft_saved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_applied_at' => 'datetime',
            'draft_saved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isFullOverride(): bool
    {
        return $this->mode === self::MODE_FULL_OVERRIDE;
    }
}
