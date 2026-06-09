<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Servers;

use App\Actions\Servers\FilterServerProvisionOptionsForCreateForm;

test('database server role only allows no cache service', function () {
    $options = FilterServerProvisionOptionsForCreateForm::run('hetzner', true, 'database');

    expect(collect($options['cache_services'])->pluck('id')->all())->toBe(['none']);
});

test('application server role still includes redis cache options', function () {
    $options = FilterServerProvisionOptionsForCreateForm::run('hetzner', true, 'application');

    expect(collect($options['cache_services'])->pluck('id')->all())->toContain('redis', 'none');
});
