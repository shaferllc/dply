<?php

namespace Tests\Feature\Marketing;

use App\Livewire\Marketing\ComingSoonSignup;
use App\Models\ComingSoonSignup as ComingSoonSignupRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComingSoonSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_coming_soon_page_can_be_rendered(): void
    {
        $this->get(route('coming-soon'))
            ->assertOk()
            ->assertSee('Request early access')
            ->assertSee(route('login'));
    }

    public function test_guest_public_pages_redirect_to_coming_soon(): void
    {
        $this->get('/')
            ->assertRedirect(route('coming-soon', absolute: false));

        $this->get(route('pricing'))
            ->assertRedirect(route('coming-soon', absolute: false));

        $this->get(route('features'))
            ->assertRedirect(route('coming-soon', absolute: false));

        $this->get(route('register', absolute: false))
            ->assertRedirect(route('coming-soon', absolute: false));
    }

    public function test_login_page_stays_available_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk();
    }

    public function test_guest_can_join_the_coming_soon_list(): void
    {
        Livewire::test(ComingSoonSignup::class)
            ->set('email', 'Founder@Example.com')
            ->call('submit')
            ->assertSet('submitted', true)
            ->assertSet('alreadySubscribed', false)
            ->assertSee('You are on the list');

        $this->assertDatabaseHas('coming_soon_signups', [
            'email' => 'founder@example.com',
            'source' => 'coming-soon',
        ]);
    }

    public function test_duplicate_email_submissions_are_handled_gracefully(): void
    {
        ComingSoonSignupRecord::query()->create([
            'email' => 'founder@example.com',
            'source' => 'coming-soon',
        ]);

        Livewire::test(ComingSoonSignup::class)
            ->set('email', 'Founder@Example.com')
            ->call('submit')
            ->assertSet('submitted', true)
            ->assertSet('alreadySubscribed', true)
            ->assertSee('already on the list');

        $this->assertDatabaseCount('coming_soon_signups', 1);
    }
}
