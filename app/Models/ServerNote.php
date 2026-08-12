<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ServerNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single free-form note attached to a server — runbooks, customer IDs,
 * "things the next engineer should know". The body is Markdown (rendered with
 * raw HTML escaped, see the <x-markdown> component). Pinned notes surface on the
 * server overview. created_by/updated_by drive the audit line in the UI and are
 * nullable so user deletion never removes the note, only its attribution.
 *
 * Notes are tagged (free-form lowercase strings) and archivable. Archiving is a
 * visible state rather than a soft delete: archived notes drop off the default
 * list and can never be pinned, but stay readable and exportable.
 *
 * @property string $id
 * @property string $server_id
 * @property string $body
 * @property bool $pinned
 * @property ?array<int, string> $tags
 * @property ?Carbon $archived_at
 * @property ?string $archived_by_user_id
 * @property ?string $created_by_user_id
 * @property ?string $updated_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ?Server $server
 * @property-read ?User $creator
 * @property-read ?User $editor
 * @property-read ?User $archiver
 * @property-read Collection<int, ServerNoteComment> $comments
 * @property-read int|null $comments_count
 */
class ServerNote extends Model
{
    /** @use HasFactory<ServerNoteFactory> */
    use HasFactory;

    use HasUlids;

    /** Free-form tags are normalised to this length so chips stay readable. */
    public const MAX_TAG_LENGTH = 32;

    /** Guard rail against a tag list that is really a note in disguise. */
    public const MAX_TAGS = 12;

    protected $fillable = [
        'server_id',
        'body',
        'pinned',
        'tags',
        'archived_at',
        'archived_by_user_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'tags' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
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

    /** @return BelongsTo<User, $this> */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    /** @return HasMany<ServerNoteComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(ServerNoteComment::class)->orderBy('created_at');
    }

    /** @param  Builder<ServerNote>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<ServerNote>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return array<int, string> */
    public function tagList(): array
    {
        return array_values(array_filter($this->tags ?? []));
    }

    public function hasTag(string $tag): bool
    {
        return in_array(self::normalizeTag($tag), $this->tagList(), true);
    }

    /**
     * Turn a raw comma/newline-separated tag string into the stored array:
     * trimmed, lowercased, de-duplicated, capped. Returns null when empty so
     * the column stays NULL rather than an empty JSON array.
     *
     * @return array<int, string>|null
     */
    public static function parseTags(?string $raw): ?array
    {
        $tags = collect(preg_split('/[,\n]+/', (string) $raw) ?: [])
            ->map(fn (string $tag): string => self::normalizeTag($tag))
            ->filter()
            ->unique()
            ->take(self::MAX_TAGS)
            ->values()
            ->all();

        return $tags === [] ? null : $tags;
    }

    public static function normalizeTag(string $tag): string
    {
        // Collapse internal whitespace so "needs review" and "needs  review"
        // are the same chip.
        $tag = (string) preg_replace('/\s+/', ' ', trim($tag));

        return Str::lower(Str::limit($tag, self::MAX_TAG_LENGTH, ''));
    }

    /**
     * A short display title derived from the body — the first Markdown heading
     * or first non-empty line. Notes have no title column on purpose (the body
     * is the note), but archived lists, exports and comment threads all need a
     * one-line handle.
     */
    public function title(int $limit = 80): string
    {
        $line = collect(preg_split('/\R/', $this->body) ?: [])
            ->map(fn (string $l): string => trim($l))
            ->first(fn (string $l): bool => $l !== '') ?? '';

        // Strip the leading Markdown noise so "## Restart runbook" reads as
        // "Restart runbook".
        $line = (string) preg_replace('/^([#>\-\*\+]+|\d+\.)\s*/', '', $line);
        $line = trim(str_replace(['**', '__', '`'], '', $line));

        return $line === '' ? __('Untitled note') : Str::limit($line, $limit);
    }

    public function wasEdited(): bool
    {
        return $this->updated_at !== null
            && $this->created_at !== null
            && $this->updated_at->gt($this->created_at->addMinute());
    }
}
