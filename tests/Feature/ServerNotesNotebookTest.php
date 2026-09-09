<?php

namespace Tests\Feature\ServerNotesNotebookTest;

use App\Livewire\Servers\SettingsCard;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ownerWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ]);

    return [$user, $server];
}

function notesCard(User $user, Server $server)
{
    return Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server])
        ->set('section', 'notes');
}

test('the notes tab renders with the editor, filters and an existing note', function () {
    [$user, $server] = ownerWithServer();

    ServerNote::factory()->for($server)->tagged(['runbook'])->create(['body' => 'Restart the queue worker']);

    notesCard($user, $server)
        ->assertOk()
        ->assertSee('Internal notes')
        ->assertSee('Restart the queue worker')
        ->assertSee('runbook')
        ->assertSee('dplyMarkdownEditor', escape: false);
});

test('adding a note stores its tags normalised and resets the composer', function () {
    [$user, $server] = ownerWithServer();

    notesCard($user, $server)
        ->set('noteDraft', '  **Escalation path**  ')
        ->set('noteDraftTags', 'Runbook, billing,  Runbook , ')
        ->call('addServerNote')
        ->assertHasNoErrors()
        ->assertSet('noteDraft', '')
        ->assertSet('noteDraftTags', '')
        ->assertDispatched('markdown-editor-reset');

    $note = ServerNote::query()->where('server_id', $server->id)->sole();

    expect($note->body)->toBe('**Escalation path**')
        ->and($note->tagList())->toBe(['runbook', 'billing'])
        ->and($note->created_by_user_id)->toBe($user->id);
});

test('archiving hides a note from the active list, clears its pin, and is reversible', function () {
    [$user, $server] = ownerWithServer();
    $note = ServerNote::factory()->for($server)->pinned()->create(['body' => 'Stale runbook']);

    $card = notesCard($user, $server)
        ->call('archiveServerNote', $note->id)
        ->assertHasNoErrors();

    $note->refresh();
    expect($note->isArchived())->toBeTrue()
        ->and($note->pinned)->toBeFalse()
        ->and($note->archived_by_user_id)->toBe($user->id);

    // Default filter is "active", so the archived note drops out of the list.
    $card->assertDontSee('Stale runbook')
        ->set('noteFilter', 'archived')
        ->assertSee('Stale runbook');

    $card->call('restoreServerNote', $note->id);
    expect($note->refresh()->isArchived())->toBeFalse();
});

test('an archived note cannot be pinned back onto the overview', function () {
    [$user, $server] = ownerWithServer();
    $note = ServerNote::factory()->for($server)->archived()->create();

    notesCard($user, $server)->call('toggleServerNotePin', $note->id);

    expect($note->refresh()->pinned)->toBeFalse();
});

test('search and tag filters narrow the list', function () {
    [$user, $server] = ownerWithServer();
    ServerNote::factory()->for($server)->tagged(['billing'])->create(['body' => 'Invoice reconciliation steps']);
    ServerNote::factory()->for($server)->tagged(['runbook'])->create(['body' => 'Queue restart steps']);

    notesCard($user, $server)
        ->set('noteSearch', 'invoice')
        ->assertSee('Invoice reconciliation steps')
        ->assertDontSee('Queue restart steps')
        ->set('noteSearch', '')
        ->call('filterServerNotesByTag', 'runbook')
        ->assertSee('Queue restart steps')
        ->assertDontSee('Invoice reconciliation steps');
});

test('the tag cloud decodes the json column and counts each tag', function () {
    [$user, $server] = ownerWithServer();
    ServerNote::factory()->for($server)->tagged(['runbook', 'billing'])->create();
    ServerNote::factory()->for($server)->tagged(['runbook'])->create();
    ServerNote::factory()->for($server)->create();

    $tags = notesCard($user, $server)->instance()->serverNoteTags;

    expect($tags)->toBe([
        ['tag' => 'runbook', 'count' => 2],
        ['tag' => 'billing', 'count' => 1],
    ]);
});

test('comments attach to a note, render when expanded, and cascade on delete', function () {
    [$user, $server] = ownerWithServer();
    $note = ServerNote::factory()->for($server)->create();

    $card = notesCard($user, $server)
        ->call('toggleNoteComments', $note->id)
        ->set("commentDrafts.{$note->id}", 'Tried this, DNS was the real cause.')
        ->call('addNoteComment', $note->id)
        ->assertHasNoErrors()
        ->assertSee('Tried this, DNS was the real cause.');

    expect($note->comments()->count())->toBe(1);

    $card->call('deleteServerNote', $note->id);

    $this->assertDatabaseCount('server_note_comments', 0);
});

test('a comment cannot be reached through a note on another server', function () {
    [$user, $server] = ownerWithServer();
    $otherServer = Server::factory()->create(['organization_id' => $server->organization_id]);
    $foreignNote = ServerNote::factory()->for($otherServer)->create();
    $comment = $foreignNote->comments()->create(['body' => 'Not yours']);

    notesCard($user, $server)->call('deleteNoteComment', $comment->id);

    $this->assertDatabaseHas('server_note_comments', ['id' => $comment->id]);
});

test('the markdown export contains the filtered notes with their tags and comments', function () {
    [$user, $server] = ownerWithServer();
    $note = ServerNote::factory()->for($server)->tagged(['runbook'])->create(['body' => "## Restart\n\nRun the thing."]);
    $note->comments()->create(['body' => 'Worked on 2026-08-01.']);
    ServerNote::factory()->for($server)->archived()->create(['body' => 'Old and archived']);

    $download = notesCard($user, $server)
        ->call('exportServerNotesMarkdown')
        ->assertFileDownloaded()
        ->effects['download'];

    $markdown = base64_decode($download['content']);

    expect($markdown)->toContain('Restart')
        ->toContain('Run the thing.')
        ->toContain('runbook')
        ->toContain('Worked on 2026-08-01.')
        // The default "active" filter is what gets exported.
        ->and($markdown)->not->toContain('Old and archived');
});

test('the export tab explains what a manifest holds and offers the notebook', function () {
    [$user, $server] = ownerWithServer();
    ServerNote::factory()->for($server)->create();

    Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server])
        ->set('section', 'export')
        ->assertOk()
        ->assertSee('reference documents, not backups', escape: false)
        ->assertSee('Server manifest')
        ->assertSee('Never included:')
        ->assertSee('SSH keys')
        ->assertSee('See the shape of the file')
        ->assertSee('Notebook')
        ->assertSee('Transfer to another account');
});

test('the export tab downloads the whole notebook, ignoring the notes filters', function () {
    [$user, $server] = ownerWithServer();
    ServerNote::factory()->for($server)->create(['body' => 'Active runbook']);
    ServerNote::factory()->for($server)->archived()->create(['body' => 'Retired runbook']);

    $download = Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server])
        ->set('section', 'export')
        ->call('exportServerNotesMarkdown', true)
        ->assertFileDownloaded()
        ->effects['download'];

    $markdown = base64_decode($download['content']);

    expect($markdown)->toContain('Active runbook')->toContain('Retired runbook')
        ->and($download['name'])->toContain('-notes-all-');
});

test('a deployer cannot mutate notes', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ]);

    Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server])
        ->set('section', 'notes')
        ->set('noteDraft', 'Should not persist')
        ->call('addServerNote');

    $this->assertDatabaseCount('server_notes', 0);
});
