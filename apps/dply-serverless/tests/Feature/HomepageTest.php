<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageTest extends TestCase
{
    public function test_homepage_renders_marketing_copy(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Serverless without', false);
        $response->assertSee('Create your dply account', false);
    }

    public function test_homepage_includes_main_app_register_link(): void
    {
        $main = rtrim((string) config('dply.main_app_url'), '/');

        $this->get('/')
            ->assertOk()
            ->assertSee($main.'/register', false);
    }
}
