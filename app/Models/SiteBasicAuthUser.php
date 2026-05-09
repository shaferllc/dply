<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteBasicAuthUser extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'site_basic_auth_users';

    protected $fillable = [
        'site_id',
        'username',
        'password_hash',
        'path',
        'source_file_path',
        'sort_order',
        'pending_removal_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'pending_removal_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * True while the row is awaiting hard-deletion by ApplySiteWebserverConfigJob,
     * after the operator clicked remove and the htpasswd-rewrite apply hasn't
     * finished yet.
     */
    public function isPendingRemoval(): bool
    {
        return $this->pending_removal_at !== null;
    }

    /**
     * Canonical path for routing: `/` is site-wide; otherwise `/segment` without trailing slash (except root).
     */
    public static function normalizePath(?string $path): string
    {
        $p = trim((string) $path);
        if ($p === '' || $p === '/') {
            return '/';
        }

        $p = '/'.ltrim($p, '/');

        return rtrim($p, '/') ?: '/';
    }

    public function normalizedPath(): string
    {
        return self::normalizePath($this->path);
    }

    /**
     * True for entries created by syncBasicAuthFromServer — i.e. imported from
     * a .htpasswd file Dply found on the server but didn't itself author. These
     * rows carry the file's absolute path so the apply flow can edit/unlink it
     * on removal instead of rewriting Dply's managed group file.
     */
    public function isDiscoveredFromServer(): bool
    {
        return $this->source_file_path !== null && $this->source_file_path !== '';
    }

    /**
     * Caddy v2's `basicauth` directive only accepts bcrypt hashes inline. Other
     * htpasswd formats (apr1, sha) can land here via Sync from server but Caddy
     * will refuse to parse them — the operator needs to rotate the password to
     * regenerate a bcrypt hash before Caddy can enforce the credential.
     */
    public function passwordHashIsBcrypt(): bool
    {
        $hash = (string) $this->password_hash;

        return str_starts_with($hash, '$2y$')
            || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$2b$');
    }
}
