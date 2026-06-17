<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class ForgeSite extends Model
{
    use HasUlids;

    protected $fillable = [
        'forge_server_id',
        'source_id',
        'domain',
        'site_type',
        'php_version',
        'repository_url',
        'repository_branch',
        'web_directory',
        'status',
        'removed_from_source',
        'source_snapshot',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'source_snapshot' => 'array',
            'removed_from_source' => 'boolean',
        ];
    }

    /** @return BelongsTo<ForgeServer, $this> */
    public function forgeServer(): BelongsTo {
        return $this->belongsTo(ForgeServer::class);
    }

    /**
     * v1 eligibility — same shape as PloiSite. Laravel + php sites only.
     */
    public function isMigrationEligible(): bool
    {
        return in_array($this->site_type, ['laravel', 'php'], true);
    }
}
