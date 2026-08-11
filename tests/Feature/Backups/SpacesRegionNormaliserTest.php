<?php

declare(strict_types=1);

use App\Livewire\Servers\Concerns\ManagesBackupDestinationModal;

/**
 * DigitalOcean does not answer GetBucketLocation the way the AWS SDK models it,
 * so the region arrives as raw XML. That reached the UI once already — the Space
 * chip read `dply-backups <LocationConstraint xmlns="…">nyc3` — so the parser
 * gets its own coverage rather than relying on a live account to catch it.
 */
function normaliseRegion(mixed $raw, string $fallback = 'nyc3'): string
{
    // The trait's only dependency here is the argument, so an anonymous host
    // that just uses the trait is enough to invoke it. Reflect on the host's
    // class, not the trait — a trait method is not bound to trait instances.
    $host = new class
    {
        use ManagesBackupDestinationModal;
    };

    return (new ReflectionMethod($host, 'normaliseSpacesRegion'))->invoke($host, $raw, $fallback);
}

it('unwraps the location constraint element digitalocean actually returns', function () {
    expect(normaliseRegion('<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">nyc3</LocationConstraint>'))
        ->toBe('nyc3');
});

it('passes a bare region slug straight through', function (string $raw) {
    expect(normaliseRegion($raw, 'nyc3'))->toBe($raw);
})->with(['nyc3', 'ams3', 'sfo2', 'syd1', 'fra1']);

it('falls back rather than emitting something that cannot be an endpoint', function (mixed $raw) {
    // A junk region would be substituted into the endpoint template and produce
    // a URL that never resolves — the fallback is editable, a broken host is not.
    expect(normaliseRegion($raw, 'nyc3'))->toBe('nyc3');
})->with([
    'empty' => [''],
    'whitespace' => ["  \n "],
    'null' => [null],
    'array' => [['nyc3']],
    'markup only' => ['<LocationConstraint/>'],
    'quote injection' => ["nyc3') + alert('x"],
    'too long' => [str_repeat('a', 64)],
    'uppercase' => ['NYC3'],
]);

it('trims the whitespace an xml body carries with it', function () {
    expect(normaliseRegion("<LocationConstraint>\n  ams3\n</LocationConstraint>"))->toBe('ams3');
});
