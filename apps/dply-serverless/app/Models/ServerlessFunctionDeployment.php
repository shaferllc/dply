<?php

namespace App\Models;

use Database\Factories\ServerlessFunctionDeploymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $serverless_project_id
 * @property string $function_name
 * @property string $runtime
 * @property string $artifact_path
 * @property string $status
 * @property string $trigger
 * @property string|null $idempotency_key
 * @property string|null $provisioner_output
 * @property string|null $revision_id
 * @property string|null $error_message
 */
class ServerlessFunctionDeployment extends Model
{
    /** @use HasFactory<ServerlessFunctionDeploymentFactory> */
    use HasFactory;

    protected $fillable = [
        'serverless_project_id',
        'function_name',
        'runtime',
        'artifact_path',
        'status',
        'trigger',
        'idempotency_key',
        'provisioner_output',
        'revision_id',
        'error_message',
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGER_API = 'api';

    /**
     * @return BelongsTo<ServerlessProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ServerlessProject::class, 'serverless_project_id');
    }
}
