<?php

declare(strict_types=1);

namespace Tests\Unit\Support\HostnameValidatorTest;

use App\Support\HostnameValidator;

test('it accepts a valid domain name', function () {
    expect(HostnameValidator::isValid('app.example.com'))->toBeTrue();
});
test('it rejects a single label hostname', function () {
    expect(HostnameValidator::isValid('test'))->toBeFalse();
});
test('it rejects invalid hostname characters', function () {
    expect(HostnameValidator::isValid('bad_host.example.com'))->toBeFalse();
});
test('it rejects ip addresses', function () {
    expect(HostnameValidator::isValid('127.0.0.1'))->toBeFalse();
});
