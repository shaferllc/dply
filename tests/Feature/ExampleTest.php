<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // RedirectGuestsToComingSoon redirects '/' for non-local hosts;
        // bypass it here since this test asserts the homepage renders.
        $response = $this->withoutMiddleware([\App\Http\Middleware\RedirectGuestsToComingSoon::class])
            ->get('/');

        $response->assertStatus(200);
    }
}
