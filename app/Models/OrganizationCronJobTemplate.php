<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function serverCronJobs(): HasMany
    {
        return $this->hasMany(ServerCronJob::class, 'applied_template_id');
    }
}
