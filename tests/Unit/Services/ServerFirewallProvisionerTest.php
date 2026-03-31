<?php

namespace Tests\Unit\Services;

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
