<?php

namespace App\Models;

use App\Models\Concerns\SyncsServerAuthorizedKeysOnManagedKeyDelete;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TeamSshKey extends Model
{
    use HasUlids;
    use SyncsServerAuthorizedKeysOnManagedKeyDelete;

    protected $fillable = [
        'team_id',
        'name',
        'public_key',
        'provision_on_new_servers',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'provision_on_new_servers' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function serverAuthorizedKeys(): MorphMany
    {
        return $this->morphMany(ServerAuthorizedKey::class, 'managed_key');
    }
}
