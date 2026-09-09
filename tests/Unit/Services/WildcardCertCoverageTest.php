<?php

declare(strict_types=1);

namespace Tests\Unit\Services\WildcardCertCoverageTest;

use App\Services\Sites\SiteNginxProvisioner;

/**
 * A site with a custom domain was served the server's *.on-dply.cc wildcard,
 * because coveringWildcardCertPair() salvaged the TESTING-zone wildcard without
 * checking it covered the vhost's hostnames. nginx then presented a cert that
 * could not match the requested name and every browser refused the connection.
 *
 * Serving no TLS is recoverable; serving a mismatched cert is a hard failure the
 * operator cannot see from inside dply.
 */
function covers(string $zone, string $hostname): bool
{
    $m = new \ReflectionMethod(SiteNginxProvisioner::class, 'wildcardCoversHostname');
    $m->setAccessible(true);

    return $m->invoke(null, $zone, $hostname);
}

test('a wildcard covers exactly one label below its zone', function () {
    expect(covers('on-dply.cc', 'placehold-ace9a552.on-dply.cc'))->toBeTrue()
        ->and(covers('on-dply.cc', 'site.on-dply.cc'))->toBeTrue();
});

test('a wildcard does not cover an unrelated domain', function () {
    // The reported bug, exactly.
    expect(covers('on-dply.cc', 'placehold.cloud'))->toBeFalse()
        ->and(covers('on-dply.cc', 'www.placehold.cloud'))->toBeFalse();
});

test('a wildcard covers neither the apex nor a deeper label', function () {
    // *.example.com matches one label only.
    expect(covers('on-dply.cc', 'on-dply.cc'))->toBeFalse()
        ->and(covers('on-dply.cc', 'a.b.on-dply.cc'))->toBeFalse();
});

test('a lookalike suffix does not count as covered', function () {
    // Suffix matching without the dot boundary would wrongly accept this.
    expect(covers('on-dply.cc', 'noton-dply.cc'))->toBeFalse()
        ->and(covers('on-dply.cc', 'evil-on-dply.cc'))->toBeFalse();
});

test('case and trailing dots do not change the answer', function () {
    expect(covers('ON-DPLY.CC', 'Site.On-Dply.cc'))->toBeTrue()
        ->and(covers('on-dply.cc.', 'site.on-dply.cc.'))->toBeTrue();
});

test('empty input is never covered', function () {
    expect(covers('', 'site.on-dply.cc'))->toBeFalse()
        ->and(covers('on-dply.cc', ''))->toBeFalse();
});
