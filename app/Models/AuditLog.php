<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $action
 * @property string $ip_address
 * @property array<string, mixed> $new_values
 * @property array<string, mixed> $old_values
 * @property ?string $organization_id
 * @property ?string $subject_id
 * @property string $subject_type
 * @property ?string $user_id
 * @property-read ?Organization $organization
 * @property-read ?User $user
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class AuditLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'ip_address',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create an audit log entry.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function log(
        Organization $organization,
        ?User $user,
        string $action,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): self {
        $subjectType = null;
        $subjectId = null;
        if ($subject) {
            $subjectType = $subject->getMorphClass();
            $subjectId = $subject->getKey();
        }

        return self::create([
            'organization_id' => $organization->id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Human-readable summary of the subject for display (e.g. "Server: my-droplet").
     */
    public function getSubjectSummaryAttribute(): ?string
    {
        $subject = $this->subject_type && $this->subject_id ? $this->subject : null;
        $name = $subject
            ? match (true) {
                $subject instanceof Server => $subject->name,
                $subject instanceof Team => $subject->name,
                $subject instanceof OrganizationInvitation => $subject->email,
                $subject instanceof Site => $subject->name,
                $subject instanceof SiteDeployment => 'deployment #'.$subject->getKey(),
                $subject instanceof Workspace => $subject->name,
                default => null,
            }
        : ($this->old_values['name'] ?? $this->new_values['name'] ?? null);

        if ($name !== null) {
            $label = match ($this->subject_type) {
                Server::class => 'Server',
                Team::class => 'Team',
                OrganizationInvitation::class => 'Invitation',
                Site::class => 'Site',
                SiteDeployment::class => 'Deployment',
                Workspace::class => 'Project',
                default => class_basename($this->subject_type ?? ''),
            };

            return ($label ? $label.': ' : '').$name;
        }

        if ($this->subject_type && $this->subject_id) {
            return class_basename($this->subject_type).' #'.$this->subject_id;
        }

        return null;
    }

    public function getActionSummaryAttribute(): string
    {
        return match ($this->action) {
            'project.updated' => 'Project details updated',
            'project.server_attached' => 'Server added to project',
            'project.server_detached' => 'Server removed from project',
            'project.site_attached' => 'Site added to project',
            'project.site_detached' => 'Site removed from project',
            'project.member_updated' => 'Project member role updated',
            'project.member_removed' => 'Project member removed',
            'project.environment_added' => 'Environment added',
            'project.environment_removed' => 'Environment removed',
            'project.deploy.queued' => 'Project deploy queued',
            'project.deploy.success' => 'Project deploy finished successfully',
            'project.deploy.failed' => 'Project deploy failed',
            default => str($this->action)
                ->replace(['.', '_'], ' ')
                ->title()
                ->toString(),
        };
    }
}
