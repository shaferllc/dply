<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Sites\SecretResidencyResolver;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      One env var a site keeps OUT of the loose plaintext-in-DB `.env` blob —
 *                      the unit of "I don't want this secret sitting decryptable in your database."
 *                      The loose `env_file_content` carries only a {@see placeholder()} for the key;
 *                      the real value is resolved just-in-time at push/deploy by
 *                      {@see SecretResidencyResolver}. One mode:
 *                      - {@see MODE_ESCROW}: `$ciphertext` is an `age` blob encrypted to the org's
 *                      recipient. NOTE this is intentionally NOT an `encrypted` cast — the whole
 *                      point is that it is NOT readable under the platform APP_KEY. Whether dply
 *                      can open it depends on who holds the org's age identity (see OrgSecretKey,
 *                      PR1+). A customer-held identity ⇒ ciphertext dply cannot decrypt.
 *                      (An `external` mode pointing at Vault / AWS Secrets Manager / Doppler was
 *                      removed 2026-08-22 along with the external-store feature.)
 * @property string|null $ciphertext
 * @property string $key
 * @property ?array<string, mixed> $meta
 * @property string $mode
 * @property ?string $site_id
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteSecretResidency extends Model
{
    use HasUlids;

    public const MODE_ESCROW = 'escrow';

    protected $table = 'site_secret_residencies';

    protected $fillable = [
        'site_id',
        'key',
        'mode',
        'ciphertext',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * The literal token written into the loose `.env` in place of the value.
     * The resolver scans rendered values for this exact form.
     */
    public function placeholder(): string
    {
        return self::placeholderFor($this->id);
    }

    public static function placeholderFor(string $id): string
    {
        return '${dply:secret:'.$id.'}';
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
