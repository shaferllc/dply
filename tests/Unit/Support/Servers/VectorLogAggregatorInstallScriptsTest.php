<?php

declare(strict_types=1);

use App\Models\ServerLogAggregator;
use App\Modules\Logs\Services\ServerLogAggregatorPolicyMap;
use App\Support\Servers\VectorLogAggregatorInstallScripts;

/*
| The aggregator side of dply Logs: the box that terminates mTLS from every
| edge agent and writes to ClickHouse. Same pure-string-builder contract as
| the agent installer — no SSH, no IO.
|
| Note the deliberate split: renderVectorToml() emits a template still holding
| __LISTEN_PORT__ / __CH_* / __DEFAULT_RETENTION__ placeholders, and
| installScript() ships a `sed` that fills them in on the box. Tests below pin
| both halves so the pair cannot drift apart.
*/

beforeEach(function () {
    $this->scripts = new VectorLogAggregatorInstallScripts;
});

function logAggregator(int $listenPort = 0): ServerLogAggregator
{
    return new ServerLogAggregator(['listen_port' => $listenPort]);
}

test('parseVersion pulls the semver out of vector --version output', function () {
    expect($this->scripts->parseVersion('vector 0.48.0 (x86_64-unknown-linux-gnu)'))->toBe('0.48.0')
        ->and($this->scripts->parseVersion('Vector 2.0.11'))->toBe('2.0.11')
        ->and($this->scripts->parseVersion('nothing here'))->toBeNull()
        ->and($this->scripts->parseVersion(''))->toBeNull();
});

test('configVersion reports the rendered aggregator config version', function () {
    expect($this->scripts->configVersion())
        ->toBe(VectorLogAggregatorInstallScripts::CONFIG_VERSION);
});

test('rendered toml is stamped with the config version', function () {
    $toml = $this->scripts->renderVectorToml();

    expect($toml)
        ->toContain('# dply-config-version: '.VectorLogAggregatorInstallScripts::CONFIG_VERSION)
        ->and($toml)->not->toContain('__CONFIG_VERSION__');
});

test('rendered toml terminates mtls from the edges with certificate verification', function () {
    $toml = $this->scripts->renderVectorToml();

    expect($toml)
        ->toContain('[sources.edges]')
        ->toContain('type = "vector"')
        ->toContain('[sources.edges.tls]')
        ->toContain('verify_certificate = true')
        ->toContain(VectorLogAggregatorInstallScripts::TLS_DIR.'/ca.crt')
        ->toContain(VectorLogAggregatorInstallScripts::TLS_DIR.'/server.crt')
        ->toContain(VectorLogAggregatorInstallScripts::TLS_DIR.'/server.key');
});

test('rendered toml wires the per-org policy enrichment table', function () {
    $toml = $this->scripts->renderVectorToml();

    expect($toml)
        ->toContain('[enrichment_tables.policy]')
        ->toContain(VectorLogAggregatorInstallScripts::POLICY_PATH);
});

test('rendered toml leaves on-box placeholders for the installer to fill', function () {
    // By design: these depend on per-install values, so the template ships with
    // them intact and the install script seds them in place.
    $toml = $this->scripts->renderVectorToml();

    expect($toml)
        ->toContain('__LISTEN_PORT__')
        ->toContain('__DEFAULT_RETENTION__');
});

test('install script fills every placeholder the toml leaves behind', function () {
    $script = $this->scripts->installScript(logAggregator());

    // Each placeholder present in the template must have a matching sed
    // substitution, or the box runs a config with a literal __PLACEHOLDER__.
    preg_match_all('/__[A-Z_]+__/', $this->scripts->renderVectorToml(), $matches);

    foreach (array_unique($matches[0]) as $placeholder) {
        expect($script)->toContain('s|'.$placeholder.'|');
    }
});

test('listen port defaults to 6000 when the row has none set', function () {
    expect($this->scripts->installScript(logAggregator(0)))->toContain('|6000|')
        ->and($this->scripts->installScript(logAggregator(6789)))->toContain('|6789|');
});

test('install script seeds the policy csv header so validate passes before rows ship', function () {
    $script = $this->scripts->installScript(logAggregator());

    expect($script)
        ->toContain(ServerLogAggregatorPolicyMap::HEADER)
        ->toContain(VectorLogAggregatorInstallScripts::POLICY_PATH);
});

test('install script keeps the clickhouse password out of the config', function () {
    $script = $this->scripts->installScript(logAggregator());

    // Password is read on-box and injected via EnvironmentFile, never rendered
    // into vector.toml.
    expect($script)
        ->toContain(VectorLogAggregatorInstallScripts::CLICKHOUSE_PASSWORD_FILE)
        ->toContain('CH_PASSWORD=')
        ->toContain('umask 077')
        ->toContain('chmod 0600 "'.VectorLogAggregatorInstallScripts::ENV_PATH.'"');

    expect($this->scripts->renderVectorToml())->not->toContain('CH_PASSWORD=');
});

test('install script installs the binary, unit and config at the namespaced paths', function () {
    $script = $this->scripts->installScript(logAggregator());

    expect($script)
        ->toContain(VectorLogAggregatorInstallScripts::BINARY_PATH)
        ->toContain(VectorLogAggregatorInstallScripts::CONFIG_PATH)
        ->toContain(VectorLogAggregatorInstallScripts::UNIT_PATH)
        ->toContain(VectorLogAggregatorInstallScripts::DATA_DIR)
        ->toContain('systemctl daemon-reload');
});

test('uninstall script disables the unit and is idempotent', function () {
    $script = $this->scripts->uninstallScript();

    expect($script)
        ->toContain('systemctl disable --now '.VectorLogAggregatorInstallScripts::UNIT_NAME)
        ->toContain(VectorLogAggregatorInstallScripts::UNIT_PATH)
        ->toContain('|| true');
});

test('systemd unit renders with normalised line endings', function () {
    $unit = $this->scripts->renderSystemdUnit();

    expect($unit)->not->toBe('')
        ->and($unit)->not->toContain("\r\n");
});

test('agent and aggregator share one vector binary path but separate config trees', function () {
    // Both install the same namespaced binary; their config/state must not collide.
    expect(VectorLogAggregatorInstallScripts::CONFIG_DIR)
        ->not->toBe(\App\Support\Servers\VectorLogAgentInstallScripts::CONFIG_DIR)
        ->and(VectorLogAggregatorInstallScripts::DATA_DIR)
        ->not->toBe(\App\Support\Servers\VectorLogAgentInstallScripts::DATA_DIR)
        ->and(VectorLogAggregatorInstallScripts::UNIT_NAME)
        ->not->toBe(\App\Support\Servers\VectorLogAgentInstallScripts::UNIT_NAME);
});
