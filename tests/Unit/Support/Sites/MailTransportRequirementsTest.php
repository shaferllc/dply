<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\MailTransportRequirementsTest;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\Sites\MailTransportPreflight;
use App\Support\Sites\MailTransportRequirements;
use Illuminate\Database\Eloquent\Collection;

/**
 * @param  array<int, array<string, mixed>>  $configs
 */
function siteWithMailBindings(array $configs): Site
{
    $site = new Site;
    $site->forceFill(['id' => '01hzzzzzzzzzzzzzzzzzzzzzzz']);

    $bindings = new Collection;

    foreach ($configs as $i => $config) {
        $binding = new SiteBinding;
        $binding->forceFill(['id' => 'b'.$i, 'type' => $config['type'] ?? 'mail', 'config' => $config]);
        $bindings->push($binding);
    }

    $site->setRelation('bindings', $bindings);

    return $site;
}

test('cloudflare needs the http client — the package the real failure was hiding', function () {
    // The reported error was `Class "Symfony\Component\HttpClient\HttpClient"
    // not found`, which names a Symfony internal rather than the package.
    $site = siteWithMailBindings([['provider' => 'cloudflare']]);

    expect(MailTransportRequirements::missingFor($site, ['laravel/framework']))
        ->toBe(['cloudflare' => ['symfony/http-client']]);
});

test('an installed package is not reported missing', function () {
    $site = siteWithMailBindings([['provider' => 'cloudflare']]);

    expect(MailTransportRequirements::missingFor($site, ['laravel/framework', 'symfony/http-client']))->toBe([]);
});

test('every leg of a failover chain is checked, not just the primary', function () {
    // A chain fails on whichever leg lacks its package, so checking only the
    // outer provider would pass a site that cannot fail over.
    $site = siteWithMailBindings([[
        'provider' => 'failover',
        'legs' => [['provider' => 'postmark'], ['provider' => 'smtp']],
    ]]);

    expect(MailTransportRequirements::missingFor($site, ['symfony/http-client']))
        ->toBe(['postmark' => ['symfony/postmark-mailer']]);
});

test('smtp and log need nothing — both ship with the framework', function () {
    $site = siteWithMailBindings([['provider' => 'smtp'], ['provider' => 'log']]);

    expect(MailTransportRequirements::providersFor($site))->toBe([])
        ->and(MailTransportRequirements::missingFor($site, []))->toBe([]);
});

test('non-mail bindings are ignored', function () {
    $site = siteWithMailBindings([['type' => 'database', 'provider' => 'cloudflare']]);

    expect(MailTransportRequirements::providersFor($site))->toBe([]);
});

test('the deploy preflight reports the missing package and stays silent otherwise', function () {
    $lock = json_encode(['packages' => [['name' => 'laravel/framework']], 'packages-dev' => []]);

    $ssh = new class($lock) implements RemoteShell
    {
        public function __construct(private readonly string $lock) {}

        public function exec(string $command, int $timeoutSeconds = 120): string
        {
            return str_contains($command, 'composer.lock') ? $this->lock : '';
        }

        public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
    };

    $preflight = new MailTransportPreflight;

    $note = $preflight->check(siteWithMailBindings([['provider' => 'cloudflare']]), $ssh, '/home/dply/app');

    expect($note)->toContain('composer require symfony/http-client')
        ->and($note)->toContain('cloudflare');

    // No mail binding: nothing to say, and the lock is never even read.
    expect($preflight->check(siteWithMailBindings([]), $ssh, '/home/dply/app'))->toBeNull();
});

test('an unreadable composer.lock warns about nothing rather than warning wrongly', function () {
    $ssh = new class implements RemoteShell
    {
        public function exec(string $command, int $timeoutSeconds = 120): string
        {
            return '';
        }

        public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
    };

    $note = (new MailTransportPreflight)
        ->check(siteWithMailBindings([['provider' => 'cloudflare']]), $ssh, '/home/dply/app');

    expect($note)->toBeNull();
});
