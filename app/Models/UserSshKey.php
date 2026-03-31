<?php

namespace App\Models;

use App\Models\Concerns\SyncsServerAuthorizedKeysOnManagedKeyDelete;
use Database\Factories\UserSshKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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

    protected function casts(): array
    {
        return [
            'provision_on_new_servers' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serverAuthorizedKeys(): MorphMany
    {
        return $this->morphMany(ServerAuthorizedKey::class, 'managed_key');
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
