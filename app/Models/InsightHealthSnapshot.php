<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsightHealthSnapshot extends Model
{
    protected $fillable = [
        'server_id',
        'score',
        'counts',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
