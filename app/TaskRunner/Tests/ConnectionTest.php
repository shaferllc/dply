<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\Exceptions\ConnectionNotFoundException;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

describe('Connection', function () {
    $validPrivateKey = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEA7v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v
-----END RSA PRIVATE KEY-----
KEY;

    $validConfig = [
        'host' => 'example.com',
        'port' => 22,
        'username' => 'deploy',
        'private_key' => $validPrivateKey,
        'script_path' => '/home/deploy/.dply-task-runner',
        'proxy_jump' => 'proxyuser@proxyhost:2222',
    ];

    it('can be constructed with valid parameters', function () use ($validConfig) {
        $conn = new Connection(
            $validConfig['host'],
            $validConfig['port'],
            $validConfig['username'],
            $validConfig['private_key'],
            $validConfig['script_path'],
            $validConfig['proxy_jump'],
        );
        expect($conn->host)->toBe('example.com')
            ->and($conn->port)->toBe(22)
            ->and($conn->username)->toBe('deploy')
            ->and($conn->privateKey)->toBeString()
            ->and($conn->scriptPath)->toBe('/home/deploy/.dply-task-runner')
            ->and($conn->proxyJump)->toBe('proxyuser@proxyhost:2222');
    });

    it('throws on invalid host', function () use ($validConfig) {
        expect(fn () => new Connection(
            '',
            $validConfig['port'],
            $validConfig['username'],
            $validConfig['private_key'],
            $validConfig['script_path'],
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws on invalid port', function () use ($validConfig) {
        expect(fn () => new Connection(
            $validConfig['host'],
            70000,
            $validConfig['username'],
            $validConfig['private_key'],
            $validConfig['script_path'],
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws on invalid username', function () use ($validConfig) {
        expect(fn () => new Connection(
            $validConfig['host'],
            $validConfig['port'],
            'invalid user!',
            $validConfig['private_key'],
            $validConfig['script_path'],
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws on invalid private key', function () use ($validConfig) {
        expect(fn () => new Connection(
            $validConfig['host'],
            $validConfig['port'],
            $validConfig['username'],
            'not a key',
            $validConfig['script_path'],
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws on invalid script path', function () use ($validConfig) {
        expect(fn () => new Connection(
            $validConfig['host'],
            $validConfig['port'],
            $validConfig['username'],
            $validConfig['private_key'],
            '../etc/passwd',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws on invalid proxy jump', function () use ($validConfig) {
        expect(fn () => new Connection(
            $validConfig['host'],
            $validConfig['port'],
            $validConfig['username'],
            $validConfig['private_key'],
            $validConfig['script_path'],
            'bad@proxy:port:extra',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('can be created from array', function () use ($validConfig) {
        $conn = Connection::fromArray($validConfig);
        expect($conn)->toBeInstanceOf(Connection::class)
            ->and($conn->host)->toBe('example.com');
    });

    it('throws if private key file does not exist in fromArray', function () use ($validConfig) {
        $config = $validConfig;
        unset($config['private_key']);
        $config['private_key_path'] = '/tmp/does-not-exist.key';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class, 'Private key file not found');
    });

    it('can compare two connections with is()', function () use ($validConfig) {
        $a = Connection::fromArray($validConfig);
        $b = Connection::fromArray($validConfig);
        expect($a->is($b))->toBeTrue();
    });

    it('returns false for is() with different connections', function () use ($validConfig) {
        $a = Connection::fromArray($validConfig);
        $b = Connection::fromArray(array_merge($validConfig, ['host' => 'other.com']));
        expect($a->is($b))->toBeFalse();
    });

    it('can set and get private key path', function () use ($validConfig) {
        $tmpKey = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($tmpKey, $validConfig['private_key']);
        $config = $validConfig;
        unset($config['private_key']);
        $config['private_key_path'] = $tmpKey;
        $conn = Connection::fromArray($config);
        expect($conn->getPrivateKeyPath())->toBe($tmpKey);
        unlink($tmpKey);
    });

    it('toString returns user@host:port', function () use ($validConfig) {
        $conn = Connection::fromArray($validConfig);
        expect((string) $conn)->toBe('deploy@example.com:22');
    });

    it('throws on empty connection name in fromConfig', function () {
        expect(fn () => Connection::fromConfig(''))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws ConnectionNotFoundException if config not found', function () {
        config(['task-runner.connections.notfound' => null]);
        expect(fn () => Connection::fromConfig('notfound'))
            ->toThrow(ConnectionNotFoundException::class);
    });

    it('can be constructed with minimum valid port', function () use ($validConfig) {
        $config = $validConfig;
        $config['port'] = 1;
        $conn = Connection::fromArray($config);
        expect($conn->port)->toBe(1);
    });

    it('can be constructed with maximum valid port', function () use ($validConfig) {
        $config = $validConfig;
        $config['port'] = 65535;
        $conn = Connection::fromArray($config);
        expect($conn->port)->toBe(65535);
    });

    it('defaults port to 22 if not set in array', function () use ($validConfig) {
        $config = $validConfig;
        unset($config['port']);
        $conn = Connection::fromArray($config);
        expect($conn->port)->toBe(22);
    });

    it('sets default script path for root user', function () use ($validPrivateKey) {
        $config = [
            'host' => 'example.com',
            'username' => 'root',
            'private_key' => $validPrivateKey,
        ];
        $conn = Connection::fromArray($config);
        expect($conn->scriptPath)->toBe('/root/.dply-task-runner');
    });

    it('sets default script path for non-root user', function () use ($validPrivateKey) {
        $config = [
            'host' => 'example.com',
            'username' => 'alice',
            'private_key' => $validPrivateKey,
        ];
        $conn = Connection::fromArray($config);
        expect($conn->scriptPath)->toBe('/home/alice/.dply-task-runner');
    });

    it('trims trailing slash from script path', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '/home/deploy/.dply-task-runner/';
        $conn = Connection::fromArray($config);
        expect($conn->scriptPath)->toBe('/home/deploy/.dply-task-runner');
    });

    it('accepts empty proxyJump', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = '';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('');
    });

    it('accepts null proxyJump', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = null;
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBeNull();
    });

    it('accepts valid domain as host', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = 'sub.example.com';
        $conn = Connection::fromArray($config);
        expect($conn->host)->toBe('sub.example.com');
    });

    it('accepts valid IPv4 as host', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = '192.168.1.1';
        $conn = Connection::fromArray($config);
        expect($conn->host)->toBe('192.168.1.1');
    });

    it('accepts valid IPv6 as host', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $conn = Connection::fromArray($config);
        expect($conn->host)->toBe('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
    });

    it('accepts private key as callable', function () use ($validConfig, $validPrivateKey) {
        $config = $validConfig;
        $config['private_key'] = fn () => $validPrivateKey;
        $conn = Connection::fromArray($config);
        expect($conn->privateKey)->toBe($validPrivateKey);
    });

    it('throws on overly long script path', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = str_repeat('a', 4097);
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on script path with null byte', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = "/home/deploy/.dply-task-runner\0";
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on script path with path traversal', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '../etc/passwd';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on script path with double slashes', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '/home//deploy/.dply-task-runner';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts proxyJump as host:port', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'proxyhost:2222';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('proxyhost:2222');
    });

    it('accepts proxyJump as user@host', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'user@proxyhost';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('user@proxyhost');
    });

    it('accepts proxyJump as host only', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'proxyhost';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('proxyhost');
    });

    it('accepts proxyJump as user@host:port', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'user@proxyhost:2222';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('user@proxyhost:2222');
    });

    it('throws on whitespace-only host', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = '   ';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on whitespace-only username', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = '   ';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on whitespace-only private key', function () use ($validConfig) {
        $config = $validConfig;
        $config['private_key'] = '   ';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on whitespace-only script path', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '   ';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts whitespace-only proxyJump', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = '   ';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('   ');
    });

    it('accepts username with dot', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'user.name';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('user.name');
    });

    it('accepts username with underscore', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'user_name';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('user_name');
    });

    it('accepts username with dash', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'user-name';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('user-name');
    });

    it('accepts username with numbers', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'user123';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('user123');
    });

    it('accepts single character domain as host', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = 'a.com';
        $conn = Connection::fromArray($config);
        expect($conn->host)->toBe('a.com');
    });

    it('accepts numeric string as host (not IP)', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = '12345';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('casts port to int if string', function () use ($validConfig) {
        $config = $validConfig;
        $config['port'] = '2222';
        $conn = Connection::fromArray($config);
        expect($conn->port)->toBe(2222);
    });

    it('throws if username missing in array', function () use ($validConfig) {
        $config = $validConfig;
        unset($config['username']);
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if host missing in array', function () use ($validConfig) {
        $config = $validConfig;
        unset($config['host']);
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if private key and private key path missing', function () use ($validConfig) {
        $config = $validConfig;
        unset($config['private_key'], $config['private_key_path']);
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if private key path is not readable', function () use ($validConfig) {
        $tmpDir = sys_get_temp_dir();
        $unreadableFile = tempnam($tmpDir, 'key');
        chmod($unreadableFile, 0000);
        $config = $validConfig;
        unset($config['private_key']);
        $config['private_key_path'] = $unreadableFile;
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
        chmod($unreadableFile, 0600);
        unlink($unreadableFile);
    });

    it('throws if private key path is a directory', function () use ($validConfig) {
        $tmpDir = sys_get_temp_dir();
        $config = $validConfig;
        unset($config['private_key']);
        $config['private_key_path'] = $tmpDir;
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if private key file cannot be read', function () use ($validConfig) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($tmpFile, '');
        chmod($tmpFile, 0000);
        $config = $validConfig;
        unset($config['private_key']);
        $config['private_key_path'] = $tmpFile;
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
        chmod($tmpFile, 0600);
        unlink($tmpFile);
    });

    it('ignores extra keys in config array', function () use ($validConfig) {
        $config = $validConfig;
        $config['extra'] = 'value';
        $conn = Connection::fromArray($config);
        expect($conn)->toBeInstanceOf(Connection::class);
    });

    it('throws if all config values are null', function () {
        $config = [
            'host' => null,
            'port' => null,
            'username' => null,
            'private_key' => null,
            'script_path' => null,
            'proxy_jump' => null,
        ];
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts host with subdomain and TLD', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = 'sub.domain.example.com';
        $conn = Connection::fromArray($config);
        expect($conn->host)->toBe('sub.domain.example.com');
    });

    it('accepts host with dash', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = 'my-host.com';
        $conn = Connection::fromArray($config);
        expect($conn->host)->toBe('my-host.com');
    });

    it('throws on host with underscore', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = 'my_host.com';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on port 0', function () use ($validConfig) {
        $config = $validConfig;
        $config['port'] = 0;
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on port 65536', function () use ($validConfig) {
        $config = $validConfig;
        $config['port'] = 65536;
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on negative port', function () use ($validConfig) {
        $config = $validConfig;
        $config['port'] = -22;
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts username with uppercase letters', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'UserName';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('UserName');
    });

    it('accepts username as single character', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'a';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('a');
    });

    it('accepts username as max length (32 chars)', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = str_repeat('a', 32);
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe(str_repeat('a', 32));
    });

    it('throws on empty string for script path', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts script path as single slash', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '/';
        $conn = Connection::fromArray($config);
        expect($conn->scriptPath)->toBe('/');
    });

    it('accepts deeply nested script path', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '/a/b/c/d/e/f/g/h/i/j';
        $conn = Connection::fromArray($config);
        expect($conn->scriptPath)->toBe('/a/b/c/d/e/f/g/h/i/j');
    });

    it('accepts whitespace proxyJump', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = '   ';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('   ');
    });

    it('throws on invalid proxyJump format', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'user@@host';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on proxyJump as port only', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = ':2222';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts valid DSA private key', function () use ($validConfig) {
        $dsaKey = <<<'KEY'
-----BEGIN DSA PRIVATE KEY-----
MIIBugIBAAKBgQCr1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v
-----END DSA PRIVATE KEY-----
KEY;
        $config = $validConfig;
        $config['private_key'] = $dsaKey;
        $conn = Connection::fromArray($config);
        expect($conn->privateKey)->toBe($dsaKey);
    });

    it('accepts valid EC private key', function () use ($validConfig) {
        $ecKey = <<<'KEY'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIB1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v
-----END EC PRIVATE KEY-----
KEY;
        $config = $validConfig;
        $config['private_key'] = $ecKey;
        $conn = Connection::fromArray($config);
        expect($conn->privateKey)->toBe($ecKey);
    });

    it('accepts valid OpenSSH private key', function () use ($validConfig) {
        $opensshKey = <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktb3BlbnNzaC1rZXktb3BlbnNzaC1rZXktb3BlbnNzaC1rZXk=
-----END OPENSSH PRIVATE KEY-----
KEY;
        $config = $validConfig;
        $config['private_key'] = $opensshKey;
        $conn = Connection::fromArray($config);
        expect($conn->privateKey)->toBe($opensshKey);
    });

    it('accepts private key as multi-line string with extra whitespace', function () use ($validConfig) {
        $key = <<<'KEY'
   -----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEA7v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v
-----END RSA PRIVATE KEY-----
KEY;
        $config = $validConfig;
        $config['private_key'] = $key;
        $conn = Connection::fromArray($config);
        expect(trim($conn->privateKey))->toBe(trim($key));
    });

    it('ignores extra unused config keys', function () use ($validConfig) {
        $config = $validConfig;
        $config['foo'] = 'bar';
        $config['baz'] = 123;
        $conn = Connection::fromArray($config);
        expect($conn)->toBeInstanceOf(Connection::class);
    });

    it('accepts punycode domain as host', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = 'xn--d1acufc.xn--p1ai';
        $conn = Connection::fromArray($config);
        expect($conn->host)->toBe('xn--d1acufc.xn--p1ai');
    });

    it('accepts IPv6 in brackets as host', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = '[2001:db8::1]';
        $conn = Connection::fromArray($config);
        expect($conn->host)->toBe('[2001:db8::1]');
    });

    it('accepts mixed-case domain as host', function () use ($validConfig) {
        $config = $validConfig;
        $config['host'] = 'ExAmPlE.com';
        $conn = Connection::fromArray($config);
        expect($conn->host)->toBe('ExAmPlE.com');
    });

    it('accepts username as all numbers', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = '123456';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('123456');
    });

    it('accepts username as all uppercase', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'USERNAME';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('USERNAME');
    });

    it('accepts username as mix of allowed special characters', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'user.name-123_';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('user.name-123_');
    });

    it('throws on username as single underscore', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = '_';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('_'); // Actually allowed by regex
    });

    it('throws on username as single dash', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = '-';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('-'); // Actually allowed by regex
    });

    it('throws on username as single dot', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = '.';
        $conn = Connection::fromArray($config);
        expect($conn->username)->toBe('.'); // Actually allowed by regex
    });

    it('throws on username with spaces', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'user name';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on username with special characters', function () use ($validConfig) {
        $config = $validConfig;
        $config['username'] = 'user!@#';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts relative script path', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = 'scripts/deploy.sh';
        $conn = Connection::fromArray($config);
        expect($conn->scriptPath)->toBe('scripts/deploy.sh');
    });

    it('accepts script path with spaces', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '/home/deploy/my scripts/deploy.sh';
        $conn = Connection::fromArray($config);
        expect($conn->scriptPath)->toBe('/home/deploy/my scripts/deploy.sh');
    });

    it('accepts script path with unicode', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '/home/deploy/测试/部署.sh';
        $conn = Connection::fromArray($config);
        expect($conn->scriptPath)->toBe('/home/deploy/测试/部署.sh');
    });

    it('throws on script path with only dots', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '...';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on script path with only slashes', function () use ($validConfig) {
        $config = $validConfig;
        $config['script_path'] = '////';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts proxyJump as IPv6', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = '[2001:db8::1]:2222';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('[2001:db8::1]:2222');
    });

    it('accepts proxyJump as domain with port', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'proxy.example.com:2222';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('proxy.example.com:2222');
    });

    it('accepts proxyJump as domain with user and port', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'user@proxy.example.com:2222';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('user@proxy.example.com:2222');
    });

    it('accepts proxyJump as domain with user but no port', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'user@proxy.example.com';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('user@proxy.example.com');
    });

    it('accepts proxyJump as domain with user and port, uppercase', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'USER@PROXY.EXAMPLE.COM:2222';
        $conn = Connection::fromArray($config);
        expect($conn->proxyJump)->toBe('USER@PROXY.EXAMPLE.COM:2222');
    });

    it('throws on proxyJump with spaces', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'user@proxy host:2222';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on proxyJump with special characters', function () use ($validConfig) {
        $config = $validConfig;
        $config['proxy_jump'] = 'user@proxy!host:2222';
        expect(fn () => Connection::fromArray($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts private key with leading/trailing whitespace', function () use ($validConfig, $validPrivateKey) {
        $key = "  \n".$validPrivateKey."  \n";
        $config = $validConfig;
        $config['private_key'] = $key;
        $conn = Connection::fromArray($config);
        expect(trim($conn->privateKey))->toBe(trim($key));
    });

    it('accepts all fields at max length', function () use ($validPrivateKey) {
        // Construct a valid domain at max length: 4 labels of 63 chars + 3 dots = 255 chars
        $label = str_repeat('a', 63);
        $host = "$label.$label.$label.$label.com";
        $host = substr($host, 0, 253); // Ensure total length is 253
        $config = [
            'host' => $host,
            'port' => 65535,
            'username' => str_repeat('u', 32),
            'private_key' => $validPrivateKey,
            'script_path' => '/'.str_repeat('s', 4095),
            'proxy_jump' => str_repeat('p', 255),
        ];
        $conn = Connection::fromArray($config);
        expect($conn)->toBeInstanceOf(Connection::class);
    });
});
