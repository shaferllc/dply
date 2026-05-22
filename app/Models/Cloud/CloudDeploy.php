<?php

namespace App\Models\Cloud;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deployment record for a CloudApp.
 *
 * Tracks the build and deployment process from git commit to running pods.
 */
class CloudDeploy extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_BUILDING = 'building';
    public const STATUS_BUILD_FAILED = 'build_failed';
    public const STATUS_PUSHING = 'pushing';
    public const STATUS_DEPLOYING = 'deploying';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $table = 'cloud_deploys';

    protected $fillable = [
        'cloud_app_id',
        'commit_sha',
        'git_branch',
        'git_author',
        'git_message',
        'status',
        'build_output',
        'container_image',
        'container_image_tag',
        'started_at',
        'completed_at',
        'rolled_back_at',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function cloudApp(): BelongsTo
    {
        return $this->belongsTo(CloudApp::class, 'cloud_app_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_BUILDING,
            self::STATUS_PUSHING,
            self::STATUS_DEPLOYING,
        ], true);
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isFailed(): bool
    {
        return in_array($this->status, [
            self::STATUS_BUILD_FAILED,
            self::STATUS_FAILED,
        ], true);
    }

    public function isRolledBack(): bool
    {
        return $this->status === self::STATUS_ROLLED_BACK;
    }

    public function durationSeconds(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $end = $this->completed_at ?? now();

        return (int) $this->started_at->diffInSeconds($end);
    }

    public function shortSha(): string
    {
        return substr($this->commit_sha, 0, 7);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_BUILDING => 'Building',
            self::STATUS_BUILD_FAILED => 'Build Failed',
            self::STATUS_PUSHING => 'Pushing',
            self::STATUS_DEPLOYING => 'Deploying',
            self::STATUS_RUNNING => 'Running',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_ROLLED_BACK => 'Rolled Back',
            default => 'Unknown',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_RUNNING => 'green',
            self::STATUS_PENDING, self::STATUS_BUILDING, self::STATUS_PUSHING, self::STATUS_DEPLOYING => 'blue',
            self::STATUS_BUILD_FAILED, self::STATUS_FAILED => 'red',
            self::STATUS_ROLLED_BACK => 'yellow',
            default => 'gray',
        };
    }

    /**
     * Append a line to the build output.
     */
    public function appendBuildOutput(string $line): void
    {
        $current = $this->build_output ?? '';
        $this->build_output = $current.$line."\n";
        $this->save();
    }

    /**
     * Get build output as an array of lines for streaming display.
     *
     * @return list<string>
     */
    public function buildOutputLines(): array
    {
        if (!$this->build_output) {
            return [];
        }

        return array_filter(
            explode("\n", $this->build_output),
            fn ($line) => trim($line) !== ''
        );
    }
}
