<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A platform-wide override for a single Pennant feature flag.
 *
 * @property int $id
 * @property string $name  The fully-qualified flag key ("{namespace}.{leaf}").
 * @property bool $enabled
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class FeaturePlatformOverride extends Model
{
    protected $fillable = [
        'name',
        'enabled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
