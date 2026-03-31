<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteEnvironmentVariable extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_id',
        'env_key',
        'env_value',
        'environment',
    ];

    protected function casts(): array
    {
        return [
            'env_value' => 'encrypted',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
