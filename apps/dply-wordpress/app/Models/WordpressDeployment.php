<?php

namespace App\Models;

use Database\Factories\WordpressDeploymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $wordpress_project_id
 * @property string $application_name
 * @property string $php_version
 * @property string $git_ref
 * @property string $status
 * @property string $trigger
 * @property string|null $idempotency_key
 * @property string|null $provisioner_output
 * @property string|null $revision_id
 * @property string|null $error_message
 */
class WordpressDeployment extends Model
{
    /** @use HasFactory<WordpressDeploymentFactory> */
    use HasFactory;

    protected $fillable = [
        'wordpress_project_id',
        'application_name',
        'php_version',
        'git_ref',
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

    public const TRIGGER_API = 'api';

    /**
     * @return BelongsTo<WordpressProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(WordpressProject::class, 'wordpress_project_id');
    }
}
