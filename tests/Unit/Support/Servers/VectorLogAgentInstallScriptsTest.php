<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerLogAgent;
use App\Support\Servers\VectorLogAgentInstallScripts;

/*
| Pure string builders for the dply Logs edge agent — the bash that installs
| Vector on a managed box and the vector.toml it renders. No SSH and no IO
| (InstallLogAgentJob runs the output), so these are plain unit tests over
| unsaved models with the `server` relation set by hand.
*/

function logAgent(array $enabledSources = [], string $orgId = 'org_123', string $serverId = 'srv_456'): ServerLogAgent
{
    $agent = new ServerLogAgent([
        'server_id' => $serverId,
        'enabled_sources' => $enabledSources,
    ]);

    $agent->setRelation('server', new Server(['organization_id' => $orgId]));

    return $agent;
}

beforeEach(function () {
    $this->scripts = new VectorLogAgentInstallScripts;
});

test('parseVersion pulls the semver out of vector --version output', function () {
    expect($this->scripts->parseVersion('vector 0.48.0 (x86_64-unknown-linux-gnu)'))->toBe('0.48.0')
        ->and($this->scripts->parseVersion('Vector 1.2.30'))->toBe('1.2.30')
        ->and($this->scripts->parseVersion("dply-logship installed and running\nvector 0.9.1 (aarch64)"))->toBe('0.9.1');
});

test('parseVersion returns null when no version is present', function () {
    expect($this->scripts->parseVersion(''))->toBeNull()
        ->and($this->scripts->parseVersion('command not found'))->toBeNull()
        // Needs all three semver segments — a truncated version is not a match.
        ->and($this->scripts->parseVersion('vector 0.48'))->toBeNull();
});

test('configVersion reports the rendered edge config version', function () {
    expect($this->scripts->configVersion())
        ->toBe(VectorLogAgentInstallScripts::CONFIG_VERSION)
        ->toBe(ServerLogAgent::currentConfigVersion());
});

test('rendered toml is stamped with the config version and data dir', function () {
    $toml = $this->scripts->renderVectorToml(logAgent());

    expect($toml)
        ->toContain('# dply-config-version: '.VectorLogAgentInstallScripts::CONFIG_VERSION)
        ->toContain('data_dir = "'.VectorLogAgentInstallScripts::DATA_DIR.'"')
        ->toContain('do not edit by hand');
});

test('each enabled source renders a source block plus its tag transform', function () {
    // Vector does not carry the component name as a field, so every source is
    // paired with a tag_<name> transform that stamps `.source` — without it the
    // aggregator cannot populate the column.
    $toml = $this->scripts->renderVectorToml(logAgent([
        'journald' => true,
        'web' => true,
        'php_fpm' => false,
        'site_app' => false,
        'auth' => false,
    ]));

    expect($toml)
        ->toContain('[sources.journald]')
        ->toContain('[sources.web]')
        ->toContain('tag_journald')
        ->toContain('tag_web');
});

test('a disabled source is left out of the rendered pipeline', function () {
    $toml = $this->scripts->renderVectorToml(logAgent([
        'journald' => true,
        'web' => false,
        'php_fpm' => false,
        'site_app' => false,
        'auth' => false,
    ]));

    expect($toml)->toContain('[sources.journald]')
        ->and($toml)->not->toContain('tag_web');
});

test('with every source off the config still renders a valid heartbeat pipeline', function () {
    // A config with an empty pipeline crash-loops the unit; the heartbeat keeps
    // it healthy while shipping nothing.
    $toml = $this->scripts->renderVectorToml(logAgent([
        'journald' => false,
        'web' => false,
        'php_fpm' => false,
        'site_app' => false,
        'auth' => false,
    ]));

    expect($toml)
        ->toContain('[sources.heartbeat]')
        ->toContain('internal_metrics');
});

test('the enrich transform stamps server and organization identity', function () {
    $toml = $this->scripts->renderVectorToml(logAgent([], 'org_abc', 'srv_xyz'));

    expect($toml)->toContain('srv_xyz')
        ->and($toml)->toContain('org_abc');
});

test('defaults come from config when the agent has saved no toggles', function () {
    $defaultOn = array_keys(array_filter(
        collect(config('server_logs.sources', []))
            ->map(fn (array $meta) => (bool) ($meta['default'] ?? false))
            ->all()
    ));

    $toml = $this->scripts->renderVectorToml(logAgent());

    // Every config-default source must appear; this is what a fresh install ships.
    foreach ($defaultOn as $key) {
        expect($toml)->toContain('tag_'.$key);
    }
});

test('install script fetches vector, validates the config, and starts the unit', function () {
    $script = $this->scripts->installScript(logAgent());

    expect($script)
        ->toContain(VectorLogAgentInstallScripts::BINARY_PATH)
        ->toContain(VectorLogAgentInstallScripts::CONFIG_PATH)
        ->toContain(VectorLogAgentInstallScripts::UNIT_PATH)
        // Validate before restart so a bad render fails loudly instead of
        // crash-looping the unit.
        ->toContain('validate')
        ->toContain('systemctl daemon-reload')
        ->toContain('systemctl enable --now '.VectorLogAgentInstallScripts::UNIT_NAME)
        ->toContain('base64 -d');
});

test('install script embeds the rendered config as base64', function () {
    $agent = logAgent();
    $script = $this->scripts->installScript($agent);

    expect($script)->toContain(base64_encode($this->scripts->renderVectorToml($agent)));
});

test('uninstall script disables the unit and removes config, unit and state', function () {
    $script = $this->scripts->uninstallScript();

    expect($script)
        ->toContain('systemctl disable --now '.VectorLogAgentInstallScripts::UNIT_NAME)
        ->toContain(VectorLogAgentInstallScripts::UNIT_PATH)
        ->toContain('daemon-reload');
});

test('uninstall tolerates an already-removed agent', function () {
    // Idempotent by contract — the job re-runs it on retry.
    expect($this->scripts->uninstallScript())->toContain('|| true');
});

test('systemd unit is rendered with the configured cpu and memory ceilings', function () {
    config([
        'server_logs.limits.cpu_quota_percent' => 22,
        'server_logs.limits.memory_max' => '256M',
    ]);

    $unit = $this->scripts->renderSystemdUnit();

    expect($unit)->toContain('22%')
        ->and($unit)->toContain('256M')
        // Placeholders must all be substituted or systemd rejects the unit.
        ->and($unit)->not->toContain('__CPU_QUOTA__')
        ->and($unit)->not->toContain('__MEMORY_MAX__')
        ->and($unit)->not->toContain("\r\n");
});
