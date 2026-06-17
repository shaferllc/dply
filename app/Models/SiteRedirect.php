<?php

namespace App\Models;

use App\Enums\SiteRedirectKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class SiteRedirect extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_id',
        'kind',
        'from_path',
        'to_url',
        'status_code',
        'response_headers',
        'comment',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SiteRedirectKind::class,
            'status_code' => 'integer',
            'response_headers' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }
}
