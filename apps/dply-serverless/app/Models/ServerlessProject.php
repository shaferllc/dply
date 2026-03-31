<?php

namespace App\Models;

use Database\Factories\ServerlessProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $credentials
 */
class ServerlessProject extends Model
{
    /** @use HasFactory<ServerlessProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'settings',
        'credentials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'credentials' => 'encrypted:array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<ServerlessFunctionDeployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(ServerlessFunctionDeployment::class);
    }
}
