<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class PloiSite extends Model
{
    use HasUlids;

    protected $fillable = [
        'ploi_server_id',
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

    /** @return BelongsTo<PloiServer, $this> */
    public function ploiServer(): BelongsTo {
        return $this->belongsTo(PloiServer::class);
    }

    /**
     * v1 migration eligibility. Laravel and generic PHP are the only types
     * dply can fully migrate today (per the design decision in Q11);
     * everything else is surfaced in the inventory as unsupported.
     */
    public function isMigrationEligible(): bool
    {
        return in_array($this->site_type, ['laravel', 'php'], true);
    }
}
