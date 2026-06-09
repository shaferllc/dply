<?php

declare(strict_types=1);

use App\Services\Servers\OpenLiteSpeedVhostsConfig;

it('indexes native virtualhost stanzas and listener map domains', function (): void {
    $httpd = <<<'CONF'
listener Default {
  address                 *:80
  secure                  0
  map                     dply-01kss4c3c3kg3f0z97gtfa6q60-test test.com
  map                     dply-01kss4c3c3kg3f0z97gtfa6q60-test test-37ae09ec.on-dply.app
}

virtualhost dply-01kss4c3c3kg3f0z97gtfa6q60-test {
  vhRoot                  /var/www/test
  configFile              /usr/local/lsws/conf/vhosts/dply-01kss4c3c3kg3f0z97gtfa6q60-test/vhconf.conf
  allowSymbolLink         1
  enableScript            1
  restrained              0
}
CONF;

    $entries = app(OpenLiteSpeedVhostsConfig::class)->parseHttpdIndex($httpd);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['name'])->toBe('dply-01kss4c3c3kg3f0z97gtfa6q60-test');
    expect($entries[0]['conf_path'])->toBe('/usr/local/lsws/conf/vhosts/dply-01kss4c3c3kg3f0z97gtfa6q60-test/vhconf.conf');
    expect($entries[0]['vh_root'])->toBe('/var/www/test');
    expect($entries[0]['domains'])->toBe(['test.com', 'test-37ae09ec.on-dply.app']);
});

it('still indexes legacy vhTemplate blocks', function (): void {
    $httpd = <<<'CONF'
vhTemplate dply-demo {
  templateFile            /usr/local/lsws/conf/vhosts/dply-demo/vhconf.conf
  vhRoot                  /var/www/demo
  member dply-demo {
    vhDomain              demo.example.com
  }
}
CONF;

    $entries = app(OpenLiteSpeedVhostsConfig::class)->parseHttpdIndex($httpd);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['name'])->toBe('dply-demo');
    expect($entries[0]['domains'])->toBe(['demo.example.com']);
});
