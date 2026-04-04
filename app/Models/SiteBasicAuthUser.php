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
        'sort_order',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
}
