<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ServerFirewallProvisioner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerFirewallProvisionerTest extends TestCase
{
    #[Test]
    public function ufw_rule_fragment_allow_any_tcp(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => 80,
            'protocol' => 'tcp',
            'action' => 'allow',
            'source' => 'any',
        ]);

        $this->assertSame('allow 80/tcp', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_deny_udp(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => 53,
            'protocol' => 'udp',
            'action' => 'deny',
            'source' => 'any',
        ]);

        $this->assertSame('deny 53/udp', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_allow_from_cidr(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => 22,
            'protocol' => 'tcp',
            'action' => 'allow',
            'source' => '203.0.113.0/24',
        ]);

        $this->assertSame(
            'allow from 203.0.113.0/24 to any port 22 proto tcp',
            $p->ufwRuleFragment($rule)
        );
    }

    #[DataProvider('invalidProtocolFallsBackProvider')]
    #[Test]
    public function invalid_protocol_defaults_to_tcp_in_fragment(string $bad): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => 25,
            'protocol' => $bad,
            'action' => 'allow',
            'source' => 'any',
        ]);

        $this->assertSame('allow 25/tcp', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_limit_tcp(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => 22,
            'protocol' => 'tcp',
            'action' => 'limit',
            'source' => 'any',
        ]);

        $this->assertSame('limit 22/tcp', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_limit_with_source(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => 22,
            'protocol' => 'tcp',
            'action' => 'limit',
            'source' => '203.0.113.10',
        ]);

        $this->assertSame('limit from 203.0.113.10 to any port 22 proto tcp', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_limit_on_udp_falls_back_to_allow(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => 53,
            'protocol' => 'udp',
            'action' => 'limit',
            'source' => 'any',
        ]);

        $this->assertSame('allow 53/udp', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function default_policies_from_meta_returns_only_set_chains(): void
    {
        $p = new ServerFirewallProvisioner;
        $server = new Server([
            'meta' => [
                config('server_firewall.meta_default_incoming_key') => 'deny',
                config('server_firewall.meta_default_routed_key') => 'reject',
                'unrelated_key' => 'ignored',
            ],
        ]);

        $this->assertSame(['incoming' => 'deny', 'routed' => 'reject'], $p->defaultPoliciesFromMeta($server));
    }

    #[Test]
    public function default_policies_from_meta_filters_invalid_values(): void
    {
        $p = new ServerFirewallProvisioner;
        $server = new Server([
            'meta' => [
                config('server_firewall.meta_default_incoming_key') => 'panic',
            ],
        ]);

        $this->assertSame([], $p->defaultPoliciesFromMeta($server));
    }

    #[Test]
    public function ufw_rule_fragment_includes_in_on_iface(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => 80,
            'protocol' => 'tcp',
            'action' => 'allow',
            'source' => 'any',
            'iface' => 'eth0',
            'iface_direction' => 'in',
        ]);

        $this->assertSame('allow in on eth0 80/tcp', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_out_on_iface_with_source(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => 443,
            'protocol' => 'tcp',
            'action' => 'allow',
            'source' => '10.0.0.0/8',
            'iface' => 'wg0',
            'iface_direction' => 'out',
        ]);

        $this->assertSame('allow out on wg0 from 10.0.0.0/8 to any port 443 proto tcp', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_iface_works_with_app_profile(): void
    {
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

        $this->assertSame('allow in on eth1 OpenSSH', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_iface_without_direction_defaults_to_in(): void
    {
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
        $this->assertSame('allow in on eth0 80/tcp', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_app_profile_any(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => null,
            'protocol' => 'tcp',
            'action' => 'allow',
            'source' => 'any',
            'app_profile' => 'OpenSSH',
        ]);

        $this->assertSame('allow OpenSSH', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function ufw_rule_fragment_app_profile_with_source(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => null,
            'protocol' => 'tcp',
            'action' => 'allow',
            'source' => '203.0.113.0/24',
            'app_profile' => 'Nginx Full',
        ]);

        $this->assertSame('allow from 203.0.113.0/24 to any app Nginx Full', $p->ufwRuleFragment($rule));
    }

    #[Test]
    public function parses_ufw_show_added_limit_line(): void
    {
        $p = new ServerFirewallProvisioner;
        $parsed = $p->parseUfwShowAddedLine('ufw limit 22/tcp');

        $this->assertSame('limit', $parsed['action']);
        $this->assertSame(22, $parsed['port']);
        $this->assertSame('tcp', $parsed['protocol']);
    }

    #[Test]
    public function ufw_rule_fragment_icmpv6(): void
    {
        $p = new ServerFirewallProvisioner;
        $rule = new ServerFirewallRule([
            'port' => null,
            'protocol' => 'ipv6-icmp',
            'action' => 'allow',
            'source' => 'any',
        ]);

        $this->assertSame('allow proto ipv6-icmp', $p->ufwRuleFragment($rule));
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function invalidProtocolFallsBackProvider(): array
    {
        return [
            ['bogusproto'],
            [''],
        ];
    }
}
