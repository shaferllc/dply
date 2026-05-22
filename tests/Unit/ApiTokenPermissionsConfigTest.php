<?php


namespace Tests\Unit\ApiTokenPermissionsConfigTest;
use App\Models\ApiToken;

test('presets only reference defined or star abilities', function () {
    $presets = config('api_token_permissions.presets', []);

    foreach ($presets as $name => $abilities) {
        expect($abilities)->toBeArray('Preset '.$name.' must be an array');
        foreach ($abilities as $ab) {
            expect($ab)->toBeString();
            expect(ApiToken::abilityIsAllowedForStorage($ab))->toBeTrue('Preset "'.$name.'" contains invalid ability: '.$ab);
        }
    }
});

test('deployer allowlist is subset of catalog or star', function () {
    $catalog = array_flip(ApiToken::catalogAbilities());

    foreach (ApiToken::deployerApiAllowlist() as $ab) {
        expect($catalog)->toHaveKey($ab, 'Deployer allowlist must use catalog abilities: '.$ab);
    }
});

test('http route abilities reference catalog', function () {
    $routes = config('api_token_permissions.http_route_abilities', []);

    foreach ($routes as $key => $ability) {
        expect(ApiToken::abilityIsAllowedForStorage($ability))->toBeTrue('Route "'.$key.'" ability invalid: '.$ability);
    }
});
