<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceVariable extends Model
{
    use HasUlids;

    protected $fillable = [
        'workspace_id',
        'env_key',
        'env_value',
        'is_secret',
    ];

    protected function casts(): array
    {
        return [
            'env_value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
