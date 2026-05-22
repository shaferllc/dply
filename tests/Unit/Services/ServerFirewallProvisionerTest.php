<?php


namespace Tests\Unit\Services\ServerFirewallProvisionerTest;
use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ServerFirewallProvisioner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

test('ufw rule fragment allow any tcp', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 80,
        'protocol' => 'tcp',
        'action' => 'allow',
        'source' => 'any',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow 80/tcp');
});

test('ufw rule fragment deny udp', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 53,
        'protocol' => 'udp',
        'action' => 'deny',
        'source' => 'any',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('deny 53/udp');
});

test('ufw rule fragment allow from cidr', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 22,
        'protocol' => 'tcp',
        'action' => 'allow',
        'source' => '203.0.113.0/24',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow from 203.0.113.0/24 to any port 22 proto tcp');
});

test('invalid protocol defaults to tcp in fragment', function (string $bad) {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 25,
        'protocol' => $bad,
        'action' => 'allow',
        'source' => 'any',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow 25/tcp');
})->with('invalidProtocolFallsBackProvider');

test('ufw rule fragment limit tcp', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 22,
        'protocol' => 'tcp',
        'action' => 'limit',
        'source' => 'any',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('limit 22/tcp');
});

test('ufw rule fragment limit with source', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 22,
        'protocol' => 'tcp',
        'action' => 'limit',
        'source' => '203.0.113.10',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('limit from 203.0.113.10 to any port 22 proto tcp');
});

test('ufw rule fragment limit on udp falls back to allow', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 53,
        'protocol' => 'udp',
        'action' => 'limit',
        'source' => 'any',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow 53/udp');
});

test('default policies from meta returns only set chains', function () {
    $p = new ServerFirewallProvisioner;
    $server = new Server([
        'meta' => [
            config('server_firewall.meta_default_incoming_key') => 'deny',
            config('server_firewall.meta_default_routed_key') => 'reject',
            'unrelated_key' => 'ignored',
        ],
    ]);

    expect($p->defaultPoliciesFromMeta($server))->toBe(['incoming' => 'deny', 'routed' => 'reject']);
});

test('default policies from meta filters invalid values', function () {
    $p = new ServerFirewallProvisioner;
    $server = new Server([
        'meta' => [
            config('server_firewall.meta_default_incoming_key') => 'panic',
        ],
    ]);

    expect($p->defaultPoliciesFromMeta($server))->toBe([]);
});

test('ufw rule fragment includes in on iface', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 80,
        'protocol' => 'tcp',
        'action' => 'allow',
        'source' => 'any',
        'iface' => 'eth0',
        'iface_direction' => 'in',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow in on eth0 80/tcp');
});

test('ufw rule fragment out on iface with source', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 443,
        'protocol' => 'tcp',
        'action' => 'allow',
        'source' => '10.0.0.0/8',
        'iface' => 'wg0',
        'iface_direction' => 'out',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow out on wg0 from 10.0.0.0/8 to any port 443 proto tcp');
});

test('ufw rule fragment iface works with app profile', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => null,
        'protocol' => 'tcp',
        'action' => 'allow',
        'source' => 'any',
        'app_profile' => 'OpenSSH',
        'iface' => 'eth1',
        'iface_direction' => 'in',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow in on eth1 OpenSSH');
});

test('ufw rule fragment iface without direction defaults to in', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => 80,
        'protocol' => 'tcp',
        'action' => 'allow',
        'source' => 'any',
        'iface' => 'eth0',
        'iface_direction' => null,
    ]);

    // A half-set legacy row (iface present, direction null) falls back to "in" rather than
    // dropping the iface clause — that's the safe interpretation since UFW requires either.
    expect($p->ufwRuleFragment($rule))->toBe('allow in on eth0 80/tcp');
});

test('ufw rule fragment app profile any', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => null,
        'protocol' => 'tcp',
        'action' => 'allow',
        'source' => 'any',
        'app_profile' => 'OpenSSH',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow OpenSSH');
});

test('ufw rule fragment app profile with source', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => null,
        'protocol' => 'tcp',
        'action' => 'allow',
        'source' => '203.0.113.0/24',
        'app_profile' => 'Nginx Full',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow from 203.0.113.0/24 to any app Nginx Full');
});

test('parses ufw show added limit line', function () {
    $p = new ServerFirewallProvisioner;
    $parsed = $p->parseUfwShowAddedLine('ufw limit 22/tcp');

    expect($parsed['action'])->toBe('limit');
    expect($parsed['port'])->toBe(22);
    expect($parsed['protocol'])->toBe('tcp');
});

test('ufw rule fragment icmpv6', function () {
    $p = new ServerFirewallProvisioner;
    $rule = new ServerFirewallRule([
        'port' => null,
        'protocol' => 'ipv6-icmp',
        'action' => 'allow',
        'source' => 'any',
    ]);

    expect($p->ufwRuleFragment($rule))->toBe('allow proto ipv6-icmp');
});

/**
 * @return array<int, array{0: string}>
 */
dataset('invalidProtocolFallsBackProvider', function () {
    return [
        ['bogusproto'],
        [''],
    ];
});
