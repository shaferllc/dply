<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StatusPageMonitor extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'status_page_id',
        'monitorable_type',
        'monitorable_id',
        'label',
        'sort_order',
    ];

    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function monitorable(): MorphTo
    {
        return $this->morphTo();
    }

    public function displayLabel(): string
    {
        if ($this->label) {
            return $this->label;
        }

        $m = $this->monitorable;
        if ($m instanceof Server) {
            return $m->name;
        }
        if ($m instanceof Site) {
            return $m->name;
        }

        return __('Monitor');
    }
}
