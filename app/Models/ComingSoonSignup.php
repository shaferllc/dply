<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
