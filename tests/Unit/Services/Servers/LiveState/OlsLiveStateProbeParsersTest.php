<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\LiveState;

use App\Services\Servers\LiveState\OlsLiveStateProbe;
use ReflectionClass;
use Tests\TestCase;

/**
 * Validates the OLS config parsers in isolation — fixtures simulate what
 * SSH would return so we don't need a live server for the parser logic.
 * We exercise via reflection because the parsers are private (right
 * scope — they're internal to the probe) but their correctness is the
 * load-bearing piece of the whole live-state pipeline.
 */
class OlsLiveStateProbeParsersTest extends TestCase
{
    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionClass(OlsLiveStateProbe::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $m = $reflection->getMethod($method);

        return $m->invokeArgs($instance, $args);
    }

    public function test_split_sections_demuxes_combined_output(): void
    {
        $combined = "leading garbage\n"
            ."###dply-section:httpd###\nlistener Default {}\n###dply-section:end###\n"
            ."###dply-section:vhosts###\n###dply-file:/usr/local/lsws/conf/vhosts/site1/vhconf.conf###\ndocRoot /var/www/site1\n###dply-section:end###\n"
            ."###dply-section:rtreport###\n###dply-section:end###\n"
            ."###dply-section:lsphp###\n/usr/local/lsws/lsphp83/bin/lsphp\n###dply-section:end###\n";

        $sections = $this->invoke('splitSections', [$combined]);

        $this->assertArrayHasKey('httpd', $sections);
        $this->assertStringContainsString('listener Default', $sections['httpd']);
        $this->assertStringContainsString('docRoot /var/www/site1', $sections['vhosts']);
        $this->assertStringContainsString('/lsphp83/bin/lsphp', $sections['lsphp']);
    }

    public function test_parses_listeners_with_maps(): void
    {
        $httpd = <<<CONF
serverName foo
listener Default {
  address                 *:80
  secure                  0
  map                     site1 a.example.com
  map                     site2 b.example.com
}
listener SSL {
  address                 *:443
  secure                  1
  map                     site1 a.example.com
}
CONF;

        $listeners = $this->invoke('parseListeners', [$httpd]);

        $this->assertCount(2, $listeners);
        $this->assertSame('Default', $listeners[0]['name']);
        $this->assertSame('*:80', $listeners[0]['address']);
        $this->assertFalse($listeners[0]['secure']);
        $this->assertSame(['site1', 'site2'], $listeners[0]['vhosts']);
        $this->assertTrue($listeners[1]['secure']);
    }

    public function test_parses_vhost_conf_with_extprocessor(): void
    {
        $blob = "###dply-file:/usr/local/lsws/conf/vhosts/site1/vhconf.conf###\n".<<<CONF
docRoot                   \$VH_ROOT/public/
vhDomain                  site1.example.com,www.site1.example.com
extprocessor lsapi-php83 {
  type                    lsapi
  path                    /usr/local/lsws/lsphp83/bin/lsphp
  maxConns                10
}
CONF;

        $vhconfs = $this->invoke('parseVhostConfFiles', [$blob]);

        $this->assertArrayHasKey('site1', $vhconfs);
        $this->assertSame('$VH_ROOT/public/', $vhconfs['site1']['doc_root']);
        $this->assertSame(['site1.example.com', 'www.site1.example.com'], $vhconfs['site1']['domains']);
        $this->assertSame('8.3', $vhconfs['site1']['php_version']);
        $this->assertCount(1, $vhconfs['site1']['extprocessors']);
        $this->assertSame('lsapi', $vhconfs['site1']['extprocessors'][0]['type']);
    }

    public function test_parses_rtreport_aggregates_numeric_keys(): void
    {
        $blob = "###dply-file:/tmp/lshttpd/.rtreport###\n".<<<RT
VERSION: LiteSpeed Web Server/Open/1.7.18
UPTIME: 12s
BPS_IN: 0, BPS_OUT: 0
MAXCONN: 10000, PLAINCONN: 5, SSLCONN: 3
REQ_RATE []: REQ_PROCESSING: 2, REQ_PER_SEC: 1.5, TOT_REQS: 1234, TOTAL_PUB_CACHE_HITS: 100, TOTAL_PRIVATE_CACHE_HITS: 50, TOTAL_STATIC_HITS: 30
RT;

        $report = $this->invoke('parseRtReportFiles', [$blob]);

        $this->assertNotEmpty($report);
        $first = reset($report);
        $this->assertSame(5, $first['PLAINCONN']);
        $this->assertSame(3, $first['SSLCONN']);
        $this->assertSame(1234, $first['TOT_REQS']);
        $this->assertSame(1.5, $first['REQ_PER_SEC']);
        $this->assertSame(100, $first['TOTAL_PUB_CACHE_HITS']);
    }

    public function test_parse_lsphp_list_extracts_version_dotted(): void
    {
        $blob = "/usr/local/lsws/lsphp82/bin/lsphp\n/usr/local/lsws/lsphp83/bin/lsphp\n";
        $versions = $this->invoke('parseLsphpList', [$blob]);
        $this->assertEqualsCanonicalizing(['8.2', '8.3'], $versions);
    }

    public function test_build_cache_units_computes_hit_rate(): void
    {
        $rtreport = ['/tmp/lshttpd/.rtreport' => [
            'TOTAL_PUB_CACHE_HITS' => 50,
            'TOTAL_PRIVATE_CACHE_HITS' => 30,
            'TOTAL_STATIC_HITS' => 20,
            'TOT_REQS' => 200,
        ]];
        $rows = $this->invoke('buildCacheUnits', [$rtreport]);
        $this->assertCount(1, $rows);
        $this->assertSame(50, $rows[0]['public_hits']);
        $this->assertSame(40.0, $rows[0]['hit_rate_pct']); // (50+30)/200 = 0.40
    }

    public function test_build_extapp_units_flags_missing_php_versions(): void
    {
        $vhconfs = [
            'site1' => [
                'name' => 'site1',
                'doc_root' => null,
                'domains' => [],
                'php_version' => '8.3',
                'extprocessors' => [
                    ['name' => 'lsapi-php83', 'type' => 'lsapi', 'path' => '/usr/local/lsws/lsphp83/bin/lsphp'],
                    ['name' => 'lsapi-php82', 'type' => 'lsapi', 'path' => '/usr/local/lsws/lsphp82/bin/lsphp'],
                ],
                'ssl' => false,
            ],
        ];
        $rows = $this->invoke('buildExtAppUnits', [$vhconfs, ['8.3']]);
        $byVersion = [];
        foreach ($rows as $row) {
            $byVersion[$row['php_version']] = $row;
        }
        $this->assertTrue($byVersion['8.3']['installed']);
        $this->assertFalse($byVersion['8.2']['installed']);
    }
}
