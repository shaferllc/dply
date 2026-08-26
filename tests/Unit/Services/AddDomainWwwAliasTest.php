<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AddDomainWwwAliasTest;

use App\Livewire\Sites\Concerns\ManagesSiteDomainsRouting;
use ReflectionMethod;

/**
 * The helper is guarded before it ever touches the database: a www hostname
 * gets no www-of-a-www, and an unticked box adds nothing. Those two branches
 * are the ones a future edit is most likely to break, and they need no DB.
 */
function callHelper(string $hostname, bool $withWww): mixed
{
    $component = new class
    {
        use ManagesSiteDomainsRouting;

        public bool $new_domain_with_www = true;
    };

    $component->new_domain_with_www = $withWww;

    $method = new ReflectionMethod($component, 'addWwwAliasFor');
    $method->setAccessible(true);

    return $method->invoke($component, $hostname);
}

test('an unticked "also serve www" adds nothing', function () {
    expect(callHelper('waypost.dply.io', false))->toBeNull();
});

test('a hostname that is already www never gets www.www', function () {
    expect(callHelper('www.example.com', true))->toBeNull();
});

test('a blank hostname is ignored', function () {
    expect(callHelper('   ', true))->toBeNull();
});
