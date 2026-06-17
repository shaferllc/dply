<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class ServerProvisionArtifact extends Model
{
    /** @use HasFactory<ServerProvisionArtifactFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'server_provision_run_id',
        'type',
        'key',
        'label',
        'content',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ServerProvisionRun, $this> */
    public function run(): BelongsTo {
        return $this->belongsTo(ServerProvisionRun::class, 'server_provision_run_id');
    }
}
