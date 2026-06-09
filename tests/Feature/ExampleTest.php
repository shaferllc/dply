<?php

namespace Tests\Feature\ExampleTest;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\RedirectGuestsToComingSoon;

test('the application returns a successful response', function () {
    // RedirectGuestsToComingSoon redirects '/' for non-local hosts;
    // bypass it here since this test asserts the homepage renders.
    $response = $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get('/');

    $response->assertStatus(200);
});

test('404 page renders interactive not-found experience', function () {
    $this->get('/definitely-not-a-real-dply-route-'.uniqid())
        ->assertNotFound()
        ->assertSee('not-found-experience', false)
        ->assertSee(__('Trace route'), false)
        ->assertSee(__('Page not found'), false);
});
