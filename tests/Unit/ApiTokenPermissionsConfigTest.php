<?php

namespace Tests\Unit;

use App\Models\ApiToken;
use Tests\TestCase;

class ApiTokenPermissionsConfigTest extends TestCase
{
    public function test_presets_only_reference_defined_or_star_abilities(): void
    {
        $presets = config('api_token_permissions.presets', []);

        foreach ($presets as $name => $abilities) {
            $this->assertIsArray($abilities, 'Preset '.$name.' must be an array');
            foreach ($abilities as $ab) {
                $this->assertIsString($ab);
                $this->assertTrue(
                    ApiToken::abilityIsAllowedForStorage($ab),
                    'Preset "'.$name.'" contains invalid ability: '.$ab
                );
            }
        }
    }

    public function test_deployer_allowlist_is_subset_of_catalog_or_star(): void
    {
        $catalog = array_flip(ApiToken::catalogAbilities());

        foreach (ApiToken::deployerApiAllowlist() as $ab) {
            $this->assertArrayHasKey($ab, $catalog, 'Deployer allowlist must use catalog abilities: '.$ab);
        }
    }

    public function test_http_route_abilities_reference_catalog(): void
    {
        $routes = config('api_token_permissions.http_route_abilities', []);

        foreach ($routes as $key => $ability) {
            $this->assertTrue(
                ApiToken::abilityIsAllowedForStorage($ability),
                'Route "'.$key.'" ability invalid: '.$ability
            );
        }
    }
}
