<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InsightSetting extends Model
{
    protected $fillable = [
        'settingsable_type',
        'settingsable_id',
        'enabled_map',
        'parameters',
    ];

    protected function casts(): array
    {
        return [
            'enabled_map' => 'array',
            'parameters' => 'array',
        ];
    }

    public function settingsable(): MorphTo
    {
        return $this->morphTo();
    }
}
