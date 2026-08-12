<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download the notebook. Two shapes, same filtered set:
 *
 * - **Markdown** — a single readable document for a handover doc or a wiki
 *   paste. Notes keep their Markdown verbatim under an H2 heading.
 * - **JSON** — the machine-readable form (tags, archive state, attribution,
 *   comments), for scripting or re-import.
 *
 * Both export exactly what the filter strip is showing, so "export the
 * archived incident notes" is just filter-then-download. Follows the
 * streamDownload pattern already used by {@see ManagesExtendedServerSettings}.
 *
 * Companion to {@see ManagesServerNotes}, whose query builder it reuses.
 */
trait ManagesServerNoteExports
{
    /**
     * @param  bool  $everything  Ignore the filter strip and take the whole
     *                            notebook. The Settings → Export tab passes
     *                            true: the notes filters aren't visible from
     *                            there, so a filtered download would silently
     *                            drop notes the operator never chose to hide.
     */
    public function exportServerNotesMarkdown(bool $everything = false): StreamedResponse
    {
        $this->authorize('view', $this->server);

        $notes = $this->exportableServerNotes($everything);
        $markdown = $this->buildServerNotesMarkdown($notes, $everything);

        return response()->streamDownload(
            fn () => print ($markdown),
            $this->serverNotesExportFilename('md', $everything),
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    /** @param  bool  $everything  See {@see exportServerNotesMarkdown()}. */
    public function exportServerNotesJson(bool $everything = false): StreamedResponse
    {
        $this->authorize('view', $this->server);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'server' => [
                'id' => $this->server->id,
                'name' => $this->server->name,
            ],
            'filter' => $everything ? null : [
                'state' => $this->noteFilter,
                'tag' => $this->noteTagFilter,
                'search' => trim($this->noteSearch) ?: null,
            ],
            'notes' => $this->exportableServerNotes($everything)->map(fn (ServerNote $note) => [
                'id' => $note->id,
                'title' => $note->title(),
                'body' => $note->body,
                'tags' => $note->tagList(),
                'pinned' => $note->pinned,
                'archived' => $note->isArchived(),
                'archived_at' => $note->archived_at?->toIso8601String(),
                'archived_by' => $note->archiver?->name,
                'created_by' => $note->creator?->name,
                'updated_by' => $note->editor?->name,
                'created_at' => $note->created_at?->toIso8601String(),
                'updated_at' => $note->updated_at?->toIso8601String(),
                'comments' => $note->comments->map(fn ($comment) => [
                    'body' => $comment->body,
                    'created_by' => $comment->creator?->name,
                    'created_at' => $comment->created_at?->toIso8601String(),
                    'updated_at' => $comment->updated_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response()->streamDownload(
            fn () => print ($json),
            $this->serverNotesExportFilename('json', $everything),
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ServerNote>
     */
    protected function exportableServerNotes(bool $everything = false)
    {
        $query = $everything ? $this->server->notes() : $this->serverNotesQuery();

        return $query
            ->with(['creator:id,name', 'editor:id,name', 'archiver:id,name', 'comments.creator:id,name'])
            ->get();
    }

    /**
     * @param  Collection<int, ServerNote>  $notes
     */
    protected function buildServerNotesMarkdown($notes, bool $everything = false): string
    {
        $lines = [
            '# '.__('Notes — :server', ['server' => $this->server->name]),
            '',
            '> '.__('Exported :date from :app.', [
                'date' => now()->toDayDateTimeString(),
                'app' => config('app.name'),
            ]),
        ];

        if (! $everything && $this->serverNotesAreFiltered()) {
            $lines[] = '> '.__('Filter: :filter.', ['filter' => $this->describeServerNoteFilter()]);
        }

        $lines[] = '';

        if ($notes->isEmpty()) {
            $lines[] = '_'.__('No notes matched this filter.').'_';

            return implode("\n", $lines)."\n";
        }

        foreach ($notes as $note) {
            $lines[] = '## '.$note->title();

            $badges = [];
            if ($note->pinned) {
                $badges[] = __('Pinned');
            }
            if ($note->isArchived()) {
                $badges[] = __('Archived :date', ['date' => $note->archived_at?->toFormattedDateString()]);
            }
            if ($note->tagList() !== []) {
                $badges[] = __('Tags: :tags', ['tags' => implode(', ', $note->tagList())]);
            }
            $badges[] = $note->creator
                ? __('Added by :name on :date', [
                    'name' => $note->creator->name,
                    'date' => $note->created_at?->toFormattedDateString(),
                ])
                : __('Added on :date', ['date' => $note->created_at?->toFormattedDateString()]);

            $lines[] = '';
            $lines[] = '_'.implode(' · ', $badges).'_';
            $lines[] = '';
            $lines[] = trim($note->body);

            if ($note->comments->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '### '.__('Comments');
                $lines[] = '';

                foreach ($note->comments as $comment) {
                    $author = $comment->creator?->name ?? __('Unknown');
                    $when = $comment->created_at?->toFormattedDateString();
                    // Indent continuation lines so multi-line comments stay
                    // inside their bullet when the Markdown is rendered.
                    $body = str_replace("\n", "\n  ", trim($comment->body));
                    $lines[] = "- **{$author}** ({$when}): {$body}";
                }
            }

            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected function describeServerNoteFilter(): string
    {
        $parts = [match ($this->noteFilter) {
            'archived' => __('archived notes'),
            'all' => __('all notes'),
            default => __('active notes'),
        }];

        if ($this->noteTagFilter !== null) {
            $parts[] = __('tagged “:tag”', ['tag' => $this->noteTagFilter]);
        }

        if (trim($this->noteSearch) !== '') {
            $parts[] = __('matching “:term”', ['term' => trim($this->noteSearch)]);
        }

        return implode(', ', $parts);
    }

    protected function serverNotesExportFilename(string $extension, bool $everything = false): string
    {
        $slug = Str::slug((string) $this->server->name) ?: 'server';
        $scope = match (true) {
            $everything => '-all',
            $this->noteFilter === 'active' => '',
            default => '-'.$this->noteFilter,
        };

        return "{$slug}-notes{$scope}-".now()->format('Y-m-d').".{$extension}";
    }
}
