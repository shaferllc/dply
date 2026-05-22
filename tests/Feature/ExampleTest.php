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
