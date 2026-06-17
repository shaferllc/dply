<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $command
 * @property string $cron_expression
 * @property ?string $description
 * @property string $name
 * @property ?string $organization_id
 * @property string $user
 * @property-read ?Organization $organization
 * @property-read Collection<int, ServerCronJob> $serverCronJobs
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class OrganizationCronJobTemplate extends Model
{
    use HasUlids;

    protected $table = 'organization_cron_job_templates';

    protected $fillable = [
        'organization_id',
        'name',
        'cron_expression',
        'command',
        'user',
        'description',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ServerCronJob, $this> */
    public function serverCronJobs(): HasMany
    {
        return $this->hasMany(ServerCronJob::class, 'applied_template_id');
    }
}
