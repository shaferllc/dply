<?php

namespace App\Models;

use Database\Factories\WebserverTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebserverTemplate extends Model
{
    /** @use HasFactory<WebserverTemplateFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'label',
        'engine',
        'content',
        'content_before',
        'content_after',
    ];

    /**
     * Supported engines + their labels. Adding a new engine is two steps:
     * declare it here (drives the picker + validation), and teach the
     * renderer how to apply placeholders for it. Sequence matters — the
     * first entry is the default for new templates.
     */
    public const ENGINES = [
        'nginx' => 'NGINX',
        'apache' => 'Apache (httpd)',
        'caddy' => 'Caddy',
        'openlitespeed' => 'OpenLiteSpeed',
        'traefik' => 'Traefik',
        'lighttpd' => 'Lighttpd',
    ];

    public function engineLabel(): string
    {
        return self::ENGINES[$this->engine] ?? ucfirst((string) $this->engine);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
