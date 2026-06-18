<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $email
 * @property ?string $source
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ComingSoonSignup extends Model
{
    use HasUlids;

    protected $fillable = [
        'email',
        'source',
    ];

    public static function subscribe(string $email, ?string $source = null): self
    {
        return self::query()->firstOrCreate(
            ['email' => Str::lower(trim($email))],
            ['source' => $source]
        );
    }
}
