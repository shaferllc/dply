<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $domain
 * @property ?string $forge_server_id
 * @property string $php_version
 * @property bool $removed_from_source
 * @property string $repository_branch
 * @property string $repository_url
 * @property string $site_type
 * @property int $source_id
 * @property array<string, mixed> $source_snapshot
 * @property string $status
 * @property string $web_directory
 * @property-read ?ForgeServer $forgeServer
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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
    public function forgeServer(): BelongsTo
    {
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
