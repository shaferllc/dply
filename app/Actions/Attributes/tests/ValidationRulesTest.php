<?php

use App\Actions\Attributes\ValidationRules;

describe('ValidationRules', function () {
    it('can be instantiated', function () {
        expect(new ValidationRules([]))->toBeInstanceOf(ValidationRules::class);
    });

    it('stores rules from constructor', function () {
        $rules = ['email' => 'required|email', 'name' => 'required'];
        $attribute = new ValidationRules($rules);

        expect($attribute->rules)->toBe($rules);
    });
});
