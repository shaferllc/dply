<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment on a {@see ServerNote}. Plain text rendered through the same
 * Markdown pipeline as the note body, but deliberately short-form: this is the
 * "we tried that, it didn't work" trail under a runbook, not a second note.
 *
 * Attribution is nullable so deleting a user keeps the discussion intact.
 *
 * @property string $id
 * @property string $server_note_id
 * @property string $body
 * @property ?string $created_by_user_id
 * @property ?string $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ?ServerNote $note
 * @property-read ?User $creator
 * @property-read ?User $editor
 */
class ServerNoteComment extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_note_id',
        'body',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /** @return BelongsTo<ServerNote, $this> */
    public function note(): BelongsTo
    {
        return $this->belongsTo(ServerNote::class, 'server_note_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** Was this edited after the fact? Drives the "· edited" suffix in the UI. */
    public function wasEdited(): bool
    {
        return $this->updated_at !== null
            && $this->created_at !== null
            && $this->updated_at->gt($this->created_at->addMinute());
    }
}
