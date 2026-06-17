<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $server_provision_run_id
 * @property string $type
 * @property string $key
 * @property ?string $label
 * @property ?string $content
 * @property array<string, mixed> $metadata
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ServerProvisionRun $run
 */
class ServerProvisionArtifact extends Model
{
    use HasUlids;

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
    public function run(): BelongsTo
    {
        return $this->belongsTo(ServerProvisionRun::class, 'server_provision_run_id');
    }
}
