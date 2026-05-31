<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

function waitForGateServer(int $port, int $timeoutMs = 5000): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (microtime(true) < $deadline) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($connection !== false) {
            fclose($connection);

            return;
        }

        usleep(50_000);
    }

    throw new RuntimeException("Gate test server did not start on port {$port}.");
}

beforeEach(function (): void {
    $this->gateDir = sys_get_temp_dir().'/dply-vm-gate-'.uniqid('', true);
    mkdir($this->gateDir);

    copy(
        resource_path('site-scripts/vm-access-gate.php'),
        $this->gateDir.'/index.php',
    );

    file_put_contents($this->gateDir.'/router.php', <<<'PHP'
<?php

chdir(__DIR__);
require __DIR__.'/index.php';

PHP);

    $salt = bin2hex(random_bytes(16));
    $this->password = 'gatepassword1';
    $this->config = [
        'mode' => 'password',
        'site_id' => '01TESTSITEID000000000',
        'cookie_secret' => str_repeat('s', 48),
        'passwords' => [[
            'id' => '01CREDENTIAL00000001',
            'label' => 'Sarah',
            'password_salt' => $salt,
            'password_verifier' => hash('sha256', $salt.'gatepassword1'),
        ]],
        'hostnames' => ['127.0.0.1'],
        'secure_cookies' => false,
    ];

    file_put_contents($this->gateDir.'/config.json', json_encode($this->config, JSON_THROW_ON_ERROR));

    $this->port = 9100 + random_int(0, 5000);

    $this->server = new Process([
        PHP_BINARY,
        '-S',
        "127.0.0.1:{$this->port}",
        'router.php',
    ], $this->gateDir);
    $this->server->start();

    if (! $this->server->isRunning()) {
        throw new RuntimeException(trim($this->server->getErrorOutput() ?: $this->server->getOutput() ?: 'Gate test server failed to start.'));
    }

    waitForGateServer($this->port);
});

afterEach(function (): void {
    if (isset($this->server) && $this->server->isRunning()) {
        $this->server->stop(0, 200_000);
    }

    if (isset($this->gateDir) && is_dir($this->gateDir)) {
        @unlink($this->gateDir.'/index.php');
        @unlink($this->gateDir.'/router.php');
        @unlink($this->gateDir.'/config.json');
        @rmdir($this->gateDir);
    }
});

test('vm access gate verify rejects missing cookie', function (): void {
    $response = Http::get("http://127.0.0.1:{$this->port}/__dply/access/verify");

    expect($response->status())->toBe(401);
});

test('vm access gate accepts password and verify accepts cookie', function (): void {
    $login = Http::asForm()
        ->withOptions(['allow_redirects' => false])
        ->post("http://127.0.0.1:{$this->port}/__dply/access", [
            'password' => $this->password,
            'return' => '/',
        ]);

    expect($login->status())->toBe(302);

    $cookieHeader = collect($login->headers()['Set-Cookie'] ?? [])->first();
    expect($cookieHeader)->toContain('__dply_vm_access=');

    preg_match('/__dply_vm_access=([^;]+)/', (string) $cookieHeader, $matches);
    $token = $matches[1] ?? '';

    $verify = Http::withHeaders([
        'Cookie' => '__dply_vm_access='.$token,
    ])->get("http://127.0.0.1:{$this->port}/__dply/access/verify");

    expect($verify->status())->toBe(204);
});

test('vm access gate rejects incorrect password', function (): void {
    $response = Http::asForm()->post("http://127.0.0.1:{$this->port}/__dply/access", [
        'password' => 'wrongpassword',
        'return' => '/',
    ]);

    expect($response->status())->toBe(401)
        ->and($response->body())->toContain('Incorrect password');
});

test('vm access gate writes login log entry on success', function (): void {
    Http::asForm()
        ->withOptions(['allow_redirects' => false])
        ->post("http://127.0.0.1:{$this->port}/__dply/access", [
            'password' => $this->password,
            'return' => '/',
        ]);

    $logPath = $this->gateDir.'/logins.jsonl';
    expect(file_exists($logPath))->toBeTrue();

    $line = trim((string) file_get_contents($logPath));
    $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

    expect($entry['label'])->toBe('Sarah')
        ->and($entry['credential_id'])->toBe('01CREDENTIAL00000001');
});

test('vm access gate verify returns 401 when config is missing', function (): void {
    @unlink($this->gateDir.'/config.json');

    $response = Http::get("http://127.0.0.1:{$this->port}/__dply/access/verify");

    expect($response->status())->toBe(401);
});
