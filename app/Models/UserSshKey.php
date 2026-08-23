<?php

namespace App\Models;

use App\Models\Concerns\SyncsServerAuthorizedKeysOnManagedKeyDelete;
use Database\Factories\UserSshKeyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $public_key
 * @property bool $provision_on_new_servers
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read User $user
 * @property-read Collection<int, ServerAuthorizedKey> $serverAuthorizedKeys
 */
class UserSshKey extends Model
{
    /** @use HasFactory<UserSshKeyFactory> */
    use HasFactory, HasUlids;

    use SyncsServerAuthorizedKeysOnManagedKeyDelete;

    protected $fillable = [
        'user_id',
        'name',
        'public_key',
        'provision_on_new_servers',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provision_on_new_servers' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphMany<ServerAuthorizedKey, $this> */
    public function serverAuthorizedKeys(): MorphMany
    {
        return $this->morphMany(ServerAuthorizedKey::class, 'managed_key');
    }

    /**
     * The algorithm the key was generated with — "ed25519", "rsa", "ecdsa".
     * Rows list it because "Work laptop" and "Work laptop (old)" are otherwise
     * only tellable apart by opening the edit form.
     */
    public function keyType(): string
    {
        $prefix = (string) (preg_split('/\s+/', trim($this->public_key), 2)[0] ?? '');

        return match (true) {
            str_starts_with($prefix, 'sk-ssh-ed25519') => 'ed25519-sk',
            str_starts_with($prefix, 'ssh-ed25519') => 'ed25519',
            str_starts_with($prefix, 'ssh-rsa') => 'rsa',
            str_starts_with($prefix, 'ssh-dss') => 'dsa',
            str_starts_with($prefix, 'ecdsa-sha2-') => 'ecdsa',
            default => '',
        };
    }

    /**
     * OpenSSH-style fingerprint (SHA256:…), the same string `ssh-keygen -lf`
     * prints, so an operator can match a row against a key on their machine.
     * Empty when the stored blob is not decodable rather than throwing — this
     * is display-only.
     */
    public function fingerprint(): string
    {
        $parts = preg_split('/\s+/', trim($this->public_key), 3);
        $blob = base64_decode((string) ($parts[1] ?? ''), true);

        if ($blob === false || $blob === '') {
            return '';
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    public static function publicKeyLooksValid(string $key): bool
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 8000) {
            return false;
        }

        $parts = preg_split('/\s+/', $key, 3);
        if (count($parts) < 2) {
            return false;
        }

        return (bool) preg_match(
            '/^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com)/',
            $parts[0]
        );
    }
}
