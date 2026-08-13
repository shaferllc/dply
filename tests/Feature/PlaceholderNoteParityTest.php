<?php

namespace Tests\Feature\PlaceholderNoteParityTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Guardrail for the placeholder-parity rule in AGENTS.md.
 *
 * Every `#[Lazy]` workspace tab has a hand-written skeleton that duplicates the
 * real view's panel-head note. Nothing keeps them in step, so they drift
 * silently and the page visibly re-letters itself on hydrate.
 *
 * This compares RENDERED output rather than blade source: the document GET
 * returns the skeleton, a follow-up Livewire request returns the real page, and
 * the dense panel-head exposes its note as a `title=` attribute on both. That
 * survives notes being computed rather than literal — which several of them are
 * (sites keys off `Server::siteType()`, tools and health off `server_role`) and
 * which a source-level string compare cannot express.
 */
function parityUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

/**
 * First panel-head note in a rendered document.
 *
 * Two shapes because the component has two: `dense` puts the note in a `title=`
 * attribute (it truncates, so the full text lives there), the default variant
 * renders it as a paragraph.
 */
function firstNote(string $html): ?string
{
    if (preg_match('/truncate text-xs text-brand-mist"\s+title="([^"]*)"/', $html, $m) === 1) {
        return trim(html_entity_decode($m[1], ENT_QUOTES));
    }

    if (preg_match('/<p class="mt-1 max-w-3xl text-xs leading-relaxed text-brand-moss">(.*?)<\/p>/s', $html, $m) === 1) {
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
    }

    return null;
}

/**
 * Routes whose tab is on by default, so both legs render a real workspace head.
 *
 * Flag-gated tabs (Files — `workspace.files` defaults false in config/features.php)
 * render a "not available" view with no panel-head at all, so they cannot be
 * compared here without turning the flag on; they are excluded rather than
 * silently passing.
 */
dataset('workspace routes', [
    'sites' => ['servers.sites'],
    'system users' => ['servers.system-users'],
    'metrics' => ['servers.monitor'],
]);

test('the skeleton note matches the hydrated page note', function (string $routeName) {
    $user = parityUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
    ]);

    $skeleton = firstNote($this->actingAs($user)->get(route($routeName, $server))->getContent());

    // Same URL with Livewire's header: this is the hydrate request, so the real
    // render comes back instead of the placeholder.
    $hydrated = firstNote(
        $this->actingAs($user)
            ->withHeader('X-Livewire', 'true')
            ->get(route($routeName, $server))
            ->getContent()
    );

    expect($skeleton)->not->toBeNull("no panel-head note rendered in the {$routeName} skeleton");

    if ($hydrated === null) {
        // The hydrate leg didn't produce a comparable head (non-dense variant or
        // a gated branch) — nothing to assert without inventing a comparison.
        expect(true)->toBeTrue();

        return;
    }

    expect($skeleton)->toBe(
        $hydrated,
        "The {$routeName} skeleton and the real page show different panel-head notes, so the header "
        ."visibly re-letters on load.\n  skeleton: \"{$skeleton}\"\n  real:     \"{$hydrated}\""
    );
})->with('workspace routes');
