<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single free-form note attached to a server — runbooks, customer IDs,
 * "things the next engineer should know". The body is Markdown (rendered with
 * raw HTML escaped, see the <x-markdown> component). Pinned notes surface on the
 * server overview. created_by/updated_by drive the audit line in the UI and are
 * nullable so user deletion never removes the note, only its attribution.
 *
 * @property string $id
 * @property string $server_id
 * @property string $body
 * @property bool $pinned
 * @property ?string $created_by_user_id
 * @property ?string $updated_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServerNote extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'body',
        'pinned',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
