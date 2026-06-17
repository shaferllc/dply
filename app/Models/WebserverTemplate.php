<?php

namespace App\Models;

use Database\Factories\WebserverTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $content
 * @property string $content_after
 * @property string $content_before
 * @property string $engine
 * @property string $label
 * @property ?string $organization_id
 * @property ?string $user_id
 * @property-read ?Organization $organization
 * @property-read ?User $user
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
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
}
