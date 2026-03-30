<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\WebserverTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebserverTemplate>
 */
class WebserverTemplateFactory extends Factory
{
    protected $model = WebserverTemplate::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => null,
            'label' => fake()->words(2, true),
            'content' => "# Dply webserver template — do not remove\nserver {\n    listen 80;\n    server_name {DOMAIN};\n}\n",
        ];
    }
}
