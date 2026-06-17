<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ServerBlueprintFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Org-scoped golden-server snapshot used to pre-fill the VM create wizard.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $source_server_id
 * @property string|null $created_by_user_id
 * @property string $name
 * @property array<string, mixed> $snapshot
 */
class ServerBlueprint extends Model
{
    /** @use HasFactory<ServerBlueprintFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'source_server_id',
        'created_by_user_id',
        'name',
        'snapshot',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function sourceServer(): BelongsTo {
        return $this->belongsTo(Server::class, 'source_server_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
