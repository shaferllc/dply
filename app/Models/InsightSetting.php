<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property array<string, mixed> $enabled_map
 * @property array<string, mixed> $parameters
 * @property ?string $settingsable_id
 * @property string $settingsable_type
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class InsightSetting extends Model
{
    protected $fillable = [
        'settingsable_type',
        'settingsable_id',
        'enabled_map',
        'parameters',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled_map' => 'array',
            'parameters' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function settingsable(): MorphTo
    {
        return $this->morphTo();
    }
}
