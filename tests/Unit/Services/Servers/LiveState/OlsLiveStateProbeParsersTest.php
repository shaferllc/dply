<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\LiveState\OlsLiveStateProbeParsersTest;

use App\Services\Servers\LiveState\OlsLiveStateProbe;
use ReflectionClass;

function invoke(string $method, array $args): mixed
{
    $reflection = new ReflectionClass(OlsLiveStateProbe::class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $m = $reflection->getMethod($method);

    return $m->invokeArgs($instance, $args);
}
test('split sections demuxes combined output', function () {
    $combined = "leading garbage\n"
        ."###dply-section:httpd###\nlistener Default {}\n###dply-section:end###\n"
        ."###dply-section:vhosts###\n###dply-file:/usr/local/lsws/conf/vhosts/site1/vhconf.conf###\ndocRoot /var/www/site1\n###dply-section:end###\n"
        ."###dply-section:rtreport###\n###dply-section:end###\n"
        ."###dply-section:lsphp###\n/usr/local/lsws/lsphp83/bin/lsphp\n###dply-section:end###\n";

    $sections = invoke('splitSections', [$combined]);

    expect($sections)->toHaveKey('httpd');
    $this->assertStringContainsString('listener Default', $sections['httpd']);
    $this->assertStringContainsString('docRoot /var/www/site1', $sections['vhosts']);
    $this->assertStringContainsString('/lsphp83/bin/lsphp', $sections['lsphp']);
});
test('parses listeners with maps', function () {
    $httpd = <<<'CONF'
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

    $listeners = invoke('parseListeners', [$httpd]);

    expect($listeners)->toHaveCount(2);
    expect($listeners[0]['name'])->toBe('Default');
    expect($listeners[0]['address'])->toBe('*:80');
    expect($listeners[0]['secure'])->toBeFalse();
    expect($listeners[0]['vhosts'])->toBe(['site1', 'site2']);
    expect($listeners[1]['secure'])->toBeTrue();
});
test('parses vhost conf with extprocessor', function () {
    $blob = "###dply-file:/usr/local/lsws/conf/vhosts/site1/vhconf.conf###\n".<<<'CONF'
docRoot                   $VH_ROOT/public/
vhDomain                  site1.example.com,www.site1.example.com
extprocessor lsapi-php83 {
  type                    lsapi
  path                    /usr/local/lsws/lsphp83/bin/lsphp
  maxConns                10
}
CONF;

    $vhconfs = invoke('parseVhostConfFiles', [$blob]);

    expect($vhconfs)->toHaveKey('site1');
    expect($vhconfs['site1']['doc_root'])->toBe('$VH_ROOT/public/');
    expect($vhconfs['site1']['domains'])->toBe(['site1.example.com', 'www.site1.example.com']);
    expect($vhconfs['site1']['php_version'])->toBe('8.3');
    expect($vhconfs['site1']['extprocessors'])->toHaveCount(1);
    expect($vhconfs['site1']['extprocessors'][0]['type'])->toBe('lsapi');
});
test('parses rtreport aggregates numeric keys', function () {
    $blob = "###dply-file:/tmp/lshttpd/.rtreport###\n".<<<'RT'
VERSION: LiteSpeed Web Server/Open/1.7.18
UPTIME: 12s
BPS_IN: 0, BPS_OUT: 0
MAXCONN: 10000, PLAINCONN: 5, SSLCONN: 3
REQ_RATE []: REQ_PROCESSING: 2, REQ_PER_SEC: 1.5, TOT_REQS: 1234, TOTAL_PUB_CACHE_HITS: 100, TOTAL_PRIVATE_CACHE_HITS: 50, TOTAL_STATIC_HITS: 30
RT;

    $report = invoke('parseRtReportFiles', [$blob]);

    expect($report)->not->toBeEmpty();
    $first = reset($report);
    expect($first['PLAINCONN'])->toBe(5);
    expect($first['SSLCONN'])->toBe(3);
    expect($first['TOT_REQS'])->toBe(1234);
    expect($first['REQ_PER_SEC'])->toBe(1.5);
    expect($first['TOTAL_PUB_CACHE_HITS'])->toBe(100);
});
test('parse lsphp list extracts version dotted', function () {
    $blob = "/usr/local/lsws/lsphp82/bin/lsphp\n/usr/local/lsws/lsphp83/bin/lsphp\n";
    $versions = invoke('parseLsphpList', [$blob]);
    expect($versions)->toEqualCanonicalizing(['8.2', '8.3']);
});
test('build cache units computes hit rate', function () {
    $rtreport = ['/tmp/lshttpd/.rtreport' => [
        'TOTAL_PUB_CACHE_HITS' => 50,
        'TOTAL_PRIVATE_CACHE_HITS' => 30,
        'TOTAL_STATIC_HITS' => 20,
        'TOT_REQS' => 200,
    ]];
    $rows = invoke('buildCacheUnits', [$rtreport]);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['public_hits'])->toBe(50);
    expect($rows[0]['hit_rate_pct'])->toBe(40.0);
    // (50+30)/200 = 0.40
});
test('build extapp units flags missing php versions', function () {
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
    $rows = invoke('buildExtAppUnits', [$vhconfs, ['8.3']]);
    $byVersion = [];
    foreach ($rows as $row) {
        $byVersion[$row['php_version']] = $row;
    }
    expect($byVersion['8.3']['installed'])->toBeTrue();
    expect($byVersion['8.2']['installed'])->toBeFalse();
});
