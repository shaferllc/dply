<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
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

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create an audit log entry.
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
            ? match ($this->subject_type) {
                Server::class => $subject->name,
                Team::class => $subject->name,
                OrganizationInvitation::class => $subject->email,
                Site::class => $subject->name,
                SiteDeployment::class => 'deployment #'.$subject->getKey(),
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
                default => class_basename($this->subject_type ?? ''),
            };

            return ($label ? $label.': ' : '').$name;
        }

        if ($this->subject_type && $this->subject_id) {
            return class_basename($this->subject_type).' #'.$this->subject_id;
        }

        return null;
    }
}
