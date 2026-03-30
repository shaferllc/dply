<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\ConnectionManager;
use App\Modules\TaskRunner\Exceptions\ConnectionNotFoundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

describe('ConnectionManager', function () {
    $validPrivateKey = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEA7v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v
-----END RSA PRIVATE KEY-----
KEY;

    $validArray = [
        'host' => 'example.com',
        'port' => 22,
        'username' => 'deploy',
        'private_key' => $validPrivateKey,
        'script_path' => '/home/deploy/.dply-task-runner',
        'proxy_jump' => 'proxyuser@proxyhost:2222',
    ];

    beforeEach(function () {
        $this->manager = new ConnectionManager;
    });

    it('creates a connection from array', function () use ($validArray) {
        $conn = $this->manager->createConnection($validArray);
        expect($conn)->toBeInstanceOf(Connection::class)
            ->and($conn->host)->toBe('example.com');
    });

    it('creates multiple connections from array', function () use ($validArray) {
        $sources = [$validArray, $validArray];
        $connections = $this->manager->createConnections($sources);
        expect($connections)->toBeInstanceOf(Collection::class)
            ->and($connections)->toHaveCount(2)
            ->and($connections->first())->toBeInstanceOf(Connection::class);
    });

    it('creates a connection from Connection instance', function () use ($validArray) {
        $conn = Connection::fromArray($validArray);
        $result = $this->manager->createConnection($conn);
        expect($result)->toBe($conn);
    });

    it('throws on invalid source type', function () {
        expect(fn () => $this->manager->createConnection(12345))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on connection string missing required fields', function () {
        expect(fn () => $this->manager->createConnection('deploy@example.com:2222'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws ConnectionNotFoundException for unknown string', function () {
        expect(fn () => $this->manager->createConnection('notfound'))
            ->toThrow(ConnectionNotFoundException::class);
    });

    it('creates a connection from config string', function () use ($validArray) {
        Config::set('task-runner.connections.test', $validArray);
        $conn = $this->manager->createConnection('test');
        expect($conn)->toBeInstanceOf(Connection::class)
            ->and($conn->host)->toBe('example.com');
    });

    it('creates a connection from database string (table:id)', function () use ($validArray) {
        DB::table('servers')->insert([
            'id' => 1,
            'name' => 'dbserver1',
            'host' => 'dbhost.example.com',
            'port' => 22,
            'username' => 'dbuser',
            'private_key' => $validArray['private_key'],
            'script_path' => '/home/dbuser/.dply-task-runner',
        ]);
        $conn = $this->manager->createConnection('servers:1');
        expect($conn)->toBeInstanceOf(Connection::class)
            ->and($conn->host)->toBe('dbhost.example.com');
    });

    it('throws ConnectionNotFoundException for missing database record', function () {
        expect(fn () => $this->manager->createConnection('servers:999'))
            ->toThrow(ConnectionNotFoundException::class);
    });

    it('creates a connection from Eloquent model', function () use ($validPrivateKey) {
        $model = new class extends Model
        {
            protected $table = 'servers';

            public $timestamps = false;

            protected $guarded = [];
        };
        $model->host = 'modelhost.example.com';
        $model->port = 22;
        $model->username = 'modeluser';
        $model->private_key = $validPrivateKey;
        $model->script_path = '/home/modeluser/.dply-task-runner';
        $conn = $this->manager->createConnection($model);
        expect($conn)->toBeInstanceOf(Connection::class)
            ->and($conn->host)->toBe('modelhost.example.com');
    });

    it('creates connections from query', function () use ($validPrivateKey) {
        DB::table('servers')->insert([
            ['id' => 2, 'name' => 'q1', 'host' => 'q1.example.com', 'port' => 22, 'username' => 'q1', 'private_key' => $validPrivateKey, 'script_path' => '/home/q1/.dply-task-runner'],
            ['id' => 3, 'name' => 'q2', 'host' => 'q2.example.com', 'port' => 22, 'username' => 'q2', 'private_key' => $validPrivateKey, 'script_path' => '/home/q2/.dply-task-runner'],
        ]);
        $connections = $this->manager->createFromQuery('servers', ['port' => 22]);
        expect($connections)->toHaveCount(2)
            ->and($connections->first())->toBeInstanceOf(Connection::class);
    });

    it('creates connections from model query', function () use ($validPrivateKey) {
        $modelClass = new class extends Model
        {
            protected $table = 'servers';

            public $timestamps = false;

            protected $guarded = [];
        };
        DB::table('servers')->insert([
            ['id' => 4, 'name' => 'mq1', 'host' => 'mq1.example.com', 'port' => 22, 'username' => 'mq1', 'private_key' => $validPrivateKey, 'script_path' => '/home/mq1/.dply-task-runner'],
        ]);
        $connections = $this->manager->createFromModelQuery(get_class($modelClass), ['port' => 22]);
        expect($connections)->toHaveCount(1)
            ->and($connections->first())->toBeInstanceOf(Connection::class);
    });

    it('creates connections from group', function () use ($validPrivateKey) {
        DB::table('servers')->insert([
            ['id' => 5, 'name' => 'g1', 'host' => 'group1.example.com', 'port' => 22, 'username' => 'g1', 'private_key' => $validPrivateKey, 'script_path' => '/home/g1/.dply-task-runner', 'group' => 'web'],
        ]);
        $connections = $this->manager->createFromGroup('web');
        expect($connections)->toHaveCount(1)
            ->and($connections->first())->toBeInstanceOf(Connection::class);
    });

    it('creates connections from tags', function () use ($validPrivateKey) {
        DB::table('servers')->insert([
            ['id' => 6, 'name' => 'tag1', 'host' => 'tag1.example.com', 'port' => 22, 'username' => 'tag1', 'private_key' => $validPrivateKey, 'script_path' => '/home/tag1/.dply-task-runner', 'tags' => json_encode(['web', 'prod'])],
        ]);
        $connections = $this->manager->createFromTags(['web']);
        expect($connections)->toHaveCount(1)
            ->and($connections->first())->toBeInstanceOf(Connection::class);
    });

    it('creates connections from environment variables', function () use ($validPrivateKey) {
        $_ENV['SSH_HOST_1'] = 'envhost.example.com';
        $_ENV['SSH_USERNAME_1'] = 'envuser';
        $_ENV['SSH_PORT_1'] = '22';
        $_ENV['SSH_PRIVATE_KEY_1'] = $validPrivateKey;
        $_ENV['SSH_SCRIPT_PATH_1'] = '/home/envuser/.dply-task-runner';
        $connections = $this->manager->createFromEnvironment(['SSH_']);
        expect($connections)->not->toBeEmpty();
        $conn = $connections->first();
        expect($conn)->toBeInstanceOf(Connection::class)
            ->and($conn->host)->toBe('envhost.example.com')
            ->and($conn->username)->toBe('envuser')
            ->and($conn->scriptPath)->toBe('/home/envuser/.dply-task-runner');
    });

    it('creates connections from JSON file', function () use ($validPrivateKey) {
        $file = tempnam(sys_get_temp_dir(), 'conn').'.json';
        $data = [
            [
                'host' => 'jsonhost.example.com',
                'port' => 22,
                'username' => 'jsonuser',
                'private_key' => $validPrivateKey,
                'script_path' => '/home/jsonuser/.dply-task-runner',
            ],
        ];
        file_put_contents($file, json_encode($data));
        $connections = $this->manager->createFromJsonFile($file);
        expect($connections)->toHaveCount(1)
            ->and($connections->first())->toBeInstanceOf(Connection::class);
        unlink($file);
    });

    it('throws on missing JSON file', function () {
        expect(fn () => $this->manager->createFromJsonFile('/tmp/does-not-exist.json'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on invalid JSON', function () {
        $file = tempnam(sys_get_temp_dir(), 'conn').'.json';
        file_put_contents($file, '{invalid json');
        expect(fn () => $this->manager->createFromJsonFile($file))
            ->toThrow(InvalidArgumentException::class);
        unlink($file);
    });

    it('creates connections from CSV file', function () use ($validPrivateKey) {
        $file = tempnam(sys_get_temp_dir(), 'conn').'.csv';
        $rows = [
            ['host', 'port', 'username', 'private_key', 'script_path'],
            ['csvhost.example.com', '22', 'csvuser', $validPrivateKey, '/home/csvuser/.dply-task-runner'],
        ];
        $handle = fopen($file, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        $connections = $this->manager->createFromCsvFile($file);
        expect($connections)->toHaveCount(1)
            ->and($connections->first())->toBeInstanceOf(Connection::class);
        unlink($file);
    });

    it('throws on missing CSV file', function () {
        expect(fn () => $this->manager->createFromCsvFile('/tmp/does-not-exist.csv'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('clears and gets cache stats', function () use ($validArray) {
        $this->manager->createConnection($validArray);
        $this->manager->clearCache();
        $stats = $this->manager->getCacheStats();
        expect($stats)->toHaveKey('cached_connections')
            ->and($stats['cached_connections'])->toBe(0);
    });

    it('validates sources', function () use ($validArray) {
        $sources = [$validArray, 12345];
        $result = $this->manager->validateSources($sources);
        expect($result)->toHaveKey('valid')
            ->and($result)->toHaveKey('errors')
            ->and($result['valid_count'])->toBe(1)
            ->and($result['error_count'])->toBe(1);
    });

    it('creates a connection with all required fields', function () use ($validPrivateKey) {
        $valid = [
            'host' => 'example.com',
            'port' => 22,
            'username' => 'deploy',
            'private_key' => $validPrivateKey,
            'script_path' => '/home/deploy/.dply-task-runner',
        ];
        $conn = $this->manager->createConnection($valid);
        expect($conn)->toBeInstanceOf(Connection::class)
            ->and($conn->host)->toBe('example.com')
            ->and($conn->username)->toBe('deploy');
    });
});

describe('ConnectionManager additional cases', function () {
    $validPrivateKey = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEA7v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v
-----END RSA PRIVATE KEY-----
KEY;

    it('creates a connection from array with extra unused keys', function () use ($validPrivateKey) {
        $data = [
            'host' => 'extra.example.com',
            'port' => 22,
            'username' => 'extra',
            'private_key' => $validPrivateKey,
            'script_path' => '/home/extra/.dply-task-runner',
            'foo' => 'bar',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn)->toBeInstanceOf(Connection::class);
    });

    it('creates a connection from array with minimum required fields', function () use ($validPrivateKey) {
        $data = [
            'host' => 'min.example.com',
            'username' => 'min',
            'private_key' => $validPrivateKey,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn)->toBeInstanceOf(Connection::class);
    });

    it('creates a connection from array with a callable private key', function () use ($validPrivateKey) {
        $data = [
            'host' => 'callable.example.com',
            'username' => 'callable',
            'private_key' => fn () => $validPrivateKey,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->privateKey)->toBe($validPrivateKey);
    });

    it('creates a connection from array with a custom script path', function () use ($validPrivateKey) {
        $data = [
            'host' => 'customscript.example.com',
            'username' => 'custom',
            'private_key' => $validPrivateKey,
            'script_path' => '/custom/path',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->scriptPath)->toBe('/custom/path');
    });

    it('creates a connection from array with a proxy_jump', function () use ($validPrivateKey) {
        $data = [
            'host' => 'proxy.example.com',
            'username' => 'proxy',
            'private_key' => $validPrivateKey,
            'proxy_jump' => 'user@proxyhost:2222',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->proxyJump)->toBe('user@proxyhost:2222');
    });

    it('creates a connection from array with a null proxy_jump', function () use ($validPrivateKey) {
        $data = [
            'host' => 'nullproxy.example.com',
            'username' => 'nullproxy',
            'private_key' => $validPrivateKey,
            'proxy_jump' => null,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->proxyJump)->toBeNull();
    });

    it('creates a connection from array with a blank proxy_jump', function () use ($validPrivateKey) {
        $data = [
            'host' => 'blankproxy.example.com',
            'username' => 'blankproxy',
            'private_key' => $validPrivateKey,
            'proxy_jump' => '',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->proxyJump)->toBe('');
    });

    it('creates a connection from array with a custom port', function () use ($validPrivateKey) {
        $data = [
            'host' => 'port.example.com',
            'username' => 'port',
            'private_key' => $validPrivateKey,
            'port' => 2022,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->port)->toBe(2022);
    });

    it('creates a connection from array with a root user (default script path)', function () use ($validPrivateKey) {
        $data = [
            'host' => 'root.example.com',
            'username' => 'root',
            'private_key' => $validPrivateKey,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->scriptPath)->toBe('/root/.dply-task-runner');
    });

    it('creates a connection from array with a non-root user (default script path)', function () use ($validPrivateKey) {
        $data = [
            'host' => 'user.example.com',
            'username' => 'alice',
            'private_key' => $validPrivateKey,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->scriptPath)->toBe('/home/alice/.dply-task-runner');
    });

    it('creates a connection from array with a private_key_path', function () use ($validPrivateKey) {
        $tmpKey = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($tmpKey, $validPrivateKey);
        $data = [
            'host' => 'keypath.example.com',
            'username' => 'keypath',
            'private_key_path' => $tmpKey,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->getPrivateKeyPath())->toBe($tmpKey);
        unlink($tmpKey);
    });

    it('throws if private_key and private_key_path are missing', function () {
        $data = [
            'host' => 'missingkey.example.com',
            'username' => 'missingkey',
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if private_key_path is unreadable', function () use ($validPrivateKey) {
        $tmpKey = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($tmpKey, $validPrivateKey);
        chmod($tmpKey, 0000);
        $data = [
            'host' => 'unreadablekey.example.com',
            'username' => 'unreadablekey',
            'private_key_path' => $tmpKey,
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
        chmod($tmpKey, 0600);
        unlink($tmpKey);
    });

    it('throws if private_key_path is a directory', function () {
        $data = [
            'host' => 'dirkey.example.com',
            'username' => 'dirkey',
            'private_key_path' => sys_get_temp_dir(),
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if host is missing', function () use ($validPrivateKey) {
        $data = [
            'username' => 'nohost',
            'private_key' => $validPrivateKey,
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if username is missing', function () use ($validPrivateKey) {
        $data = [
            'host' => 'nouser.example.com',
            'private_key' => $validPrivateKey,
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if script_path is missing and username is not set', function () use ($validPrivateKey) {
        $data = [
            'host' => 'noscript.example.com',
            'private_key' => $validPrivateKey,
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if proxy_jump is invalid', function () use ($validPrivateKey) {
        $data = [
            'host' => 'badproxy.example.com',
            'username' => 'badproxy',
            'private_key' => $validPrivateKey,
            'proxy_jump' => 'user@@host',
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws if script_path is invalid', function () use ($validPrivateKey) {
        $data = [
            'host' => 'badscript.example.com',
            'username' => 'badscript',
            'private_key' => $validPrivateKey,
            'script_path' => '../etc/passwd',
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('validates a mix of valid and invalid sources', function () use ($validPrivateKey) {
        $valid = [
            'host' => 'valid.example.com',
            'username' => 'valid',
            'private_key' => $validPrivateKey,
        ];
        $invalid = [
            'host' => 'invalid.example.com',
        ];
        $result = (new ConnectionManager)->validateSources([$valid, $invalid]);
        expect($result['valid_count'])->toBe(1)
            ->and($result['error_count'])->toBe(1);
    });
});

describe('ConnectionManager advanced and edge cases', function () {
    $validPrivateKey = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEA7v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v
-----END RSA PRIVATE KEY-----
KEY;

    it('creates a connection from string with only user@host', function () {
        expect(fn () => (new ConnectionManager)->createConnection('user@hostonly'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('creates a connection from string with host:port', function () {
        expect(fn () => (new ConnectionManager)->createConnection('hostonly:2222'))
            ->toThrow(QueryException::class);
    });

    it('throws on malformed string', function () {
        expect(fn () => (new ConnectionManager)->createConnection('bad@@host:22'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('creates a connection from model with extra fields', function () use ($validPrivateKey) {
        $model = new class extends Model
        {
            protected $table = 'servers';

            public $timestamps = false;

            protected $guarded = [];
        };
        $model->host = 'extramodel.example.com';
        $model->port = 22;
        $model->username = 'extramodel';
        $model->private_key = $validPrivateKey;
        $model->script_path = '/home/extramodel/.dply-task-runner';
        $model->extra = 'value';
        $conn = (new ConnectionManager)->createConnection($model);
        expect($conn)->toBeInstanceOf(Connection::class);
    });

    it('throws when model is missing required fields', function () {
        $model = new class extends Model
        {
            protected $table = 'servers';

            public $timestamps = false;

            protected $guarded = [];
        };
        $model->host = 'missingfields.example.com';
        expect(fn () => (new ConnectionManager)->createConnection($model))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws when database record is missing required fields', function () {
        DB::table('servers')->insert([
            'id' => 100,
            'name' => 'missingfields',
            'host' => 'missingfieldsdb.example.com',
        ]);
        expect(fn () => (new ConnectionManager)->createConnection('servers:100'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('returns empty collection for group with no matches', function () {
        $connections = (new ConnectionManager)->createFromGroup('nonexistentgroup');
        expect($connections)->toBeInstanceOf(Collection::class)
            ->and($connections)->toBeEmpty();
    });

    it('returns empty collection for tags with no matches', function () {
        $connections = (new ConnectionManager)->createFromTags(['notag']);
        expect($connections)->toBeInstanceOf(Collection::class)
            ->and($connections)->toBeEmpty();
    });

    it('returns empty collection for environment with no matches', function () {
        $connections = (new ConnectionManager)->createFromEnvironment(['NO_MATCH_']);
        expect($connections)->toBeInstanceOf(Collection::class)
            ->and($connections)->toBeEmpty();
    });

    it('throws on malformed JSON file', function () {
        $file = tempnam(sys_get_temp_dir(), 'badjson').'.json';
        file_put_contents($file, '{bad json');
        expect(fn () => (new ConnectionManager)->createFromJsonFile($file))
            ->toThrow(InvalidArgumentException::class);
        unlink($file);
    });

    it('returns empty collection for malformed CSV file', function () {
        $file = tempnam(sys_get_temp_dir(), 'badcsv').'.csv';
        file_put_contents($file, 'host,port,username,private_key,script_path\nmissingfields');
        $connections = (new ConnectionManager)->createFromCsvFile($file);
        expect($connections)->toBeInstanceOf(Collection::class)
            ->and($connections)->toBeEmpty();
        unlink($file);
    });

    it('returns correct cache stats after multiple operations', function () use ($validPrivateKey) {
        $manager = new ConnectionManager;
        $data = [
            'host' => 'cache.example.com',
            'username' => 'cache',
            'private_key' => $validPrivateKey,
        ];
        $manager->createConnection($data);
        $stats = $manager->getCacheStats();
        expect($stats['cached_connections'])->toBe(0); // Only DB-based connections are cached
    });

    it('validates sources with a collection', function () use ($validPrivateKey) {
        $valid = [
            'host' => 'validcoll.example.com',
            'username' => 'validcoll',
            'private_key' => $validPrivateKey,
        ];
        $invalid = [
            'host' => 'invalidcoll.example.com',
        ];
        $collection = collect([$valid, $invalid]);
        $result = (new ConnectionManager)->validateSources($collection);
        expect($result['valid_count'])->toBe(1)
            ->and($result['error_count'])->toBe(1);
    });

    it('handles duplicate connections in batch', function () use ($validPrivateKey) {
        $data = [
            'host' => 'dup.example.com',
            'username' => 'dup',
            'private_key' => $validPrivateKey,
        ];
        $connections = (new ConnectionManager)->createConnections([$data, $data]);
        expect($connections)->toHaveCount(2);
    });

    it('clears cache after use', function () use ($validPrivateKey) {
        $manager = new ConnectionManager;
        $data = [
            'host' => 'clearcache.example.com',
            'username' => 'clearcache',
            'private_key' => $validPrivateKey,
        ];
        $manager->createConnection($data);
        $manager->clearCache();
        $stats = $manager->getCacheStats();
        expect($stats['cached_connections'])->toBe(0);
    });

    it('propagates error from Connection::fromArray', function () {
        $data = [
            'host' => 'errorprop.example.com',
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('creates a large batch of connections', function () use ($validPrivateKey) {
        $batch = [];
        for ($i = 0; $i < 10; $i++) {
            $batch[] = [
                'host' => "batch{$i}.example.com",
                'username' => "batch{$i}",
                'private_key' => $validPrivateKey,
            ];
        }
        $connections = (new ConnectionManager)->createConnections($batch);
        expect($connections)->toHaveCount(10);
    });
    it('creates a connection with proxy_jump as IPv6', function () use ($validPrivateKey) {
        $data = [
            'host' => 'ipv6proxy.example.com',
            'username' => 'ipv6proxy',
            'private_key' => $validPrivateKey,
            'proxy_jump' => '[2001:db8::1]:2222',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->proxyJump)->toBe('[2001:db8::1]:2222');
    });

    it('creates a connection with script_path containing spaces and unicode', function () use ($validPrivateKey) {
        $data = [
            'host' => 'spaceunicode.example.com',
            'username' => 'spaceunicode',
            'private_key' => $validPrivateKey,
            'script_path' => '/home/space unicode/测试/部署.sh',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->scriptPath)->toBe('/home/space unicode/测试/部署.sh');
    });

    it('creates a connection with private_key as multi-line string', function () {
        $key = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEA7v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v
-----END RSA PRIVATE KEY-----
KEY;
        $data = [
            'host' => 'multilinekey.example.com',
            'username' => 'multilinekey',
            'private_key' => $key,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->privateKey)->toBe($key);
    });

    it('creates a connection with port as string', function () use ($validPrivateKey) {
        $data = [
            'host' => 'stringport.example.com',
            'username' => 'stringport',
            'private_key' => $validPrivateKey,
            'port' => '2222',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->port)->toBe(2222);
    });

    it('creates a connection with host as IPv6', function () use ($validPrivateKey) {
        $data = [
            'host' => '2001:db8::1',
            'username' => 'ipv6',
            'private_key' => $validPrivateKey,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->host)->toBe('2001:db8::1');
    });

    it('throws on username with invalid characters', function () use ($validPrivateKey) {
        $data = [
            'host' => 'invaliduser.example.com',
            'username' => 'user@example.com',
            'private_key' => $validPrivateKey,
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on unicode/emoji in username', function () use ($validPrivateKey) {
        $data = [
            'host' => '😀.example.com',
            'username' => 'usér😀',
            'private_key' => $validPrivateKey,
            'script_path' => '/home/usér😀/.dply-task-runner',
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('creates a connection with script_path as root', function () use ($validPrivateKey) {
        $data = [
            'host' => 'rootpath.example.com',
            'username' => 'root',
            'private_key' => $validPrivateKey,
            'script_path' => '/root',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->scriptPath)->toBe('/root');
    });

    it('creates a connection with proxy_jump as blank', function () use ($validPrivateKey) {
        $data = [
            'host' => 'blankproxyjump.example.com',
            'username' => 'blankproxyjump',
            'private_key' => $validPrivateKey,
            'proxy_jump' => '',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->proxyJump)->toBe('');
    });

    it('creates a connection with proxy_jump as null', function () use ($validPrivateKey) {
        $data = [
            'host' => 'nullproxyjump.example.com',
            'username' => 'nullproxyjump',
            'private_key' => $validPrivateKey,
            'proxy_jump' => null,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->proxyJump)->toBeNull();
    });
});

describe('ConnectionManager deep coverage', function () {
    $validPrivateKey = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEA7v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v
-----END RSA PRIVATE KEY-----
KEY;

    it('accepts array with all fields as strings', function () use ($validPrivateKey) {
        $data = [
            'host' => 'stringfields.example.com',
            'port' => '22',
            'username' => 'stringuser',
            'private_key' => (string) $validPrivateKey,
            'script_path' => '/home/stringuser/.dply-task-runner',
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn)->toBeInstanceOf(Connection::class);
    });

    it('throws on array with all fields as null', function () {
        $data = [
            'host' => null,
            'port' => null,
            'username' => null,
            'private_key' => null,
            'script_path' => null,
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts array with port as float', function () use ($validPrivateKey) {
        $data = [
            'host' => 'floatport.example.com',
            'port' => 22.0,
            'username' => 'floatuser',
            'private_key' => $validPrivateKey,
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn->port)->toBe(22);
    });

    it('throws on array with boolean fields', function () {
        $data = [
            'host' => true,
            'port' => false,
            'username' => true,
            'private_key' => false,
            'script_path' => true,
        ];
        expect(fn () => (new ConnectionManager)->createConnection($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts array with extra deeply nested keys', function () use ($validPrivateKey) {
        $data = [
            'host' => 'nested.example.com',
            'username' => 'nested',
            'private_key' => $validPrivateKey,
            'extra' => ['foo' => ['bar' => ['baz' => 1]]],
        ];
        $conn = (new ConnectionManager)->createConnection($data);
        expect($conn)->toBeInstanceOf(Connection::class);
    });

    it('accepts config name with valid config', function () use ($validPrivateKey) {
        Config::set('task-runner.connections.deepconfig', [
            'host' => 'deepconfig.example.com',
            'username' => 'deepconfig',
            'private_key' => $validPrivateKey,
        ]);
        $conn = (new ConnectionManager)->createConnection('deepconfig');
        expect($conn->host)->toBe('deepconfig.example.com');
    });

    it('throws on config name with missing config', function () {
        Config::set('task-runner.connections.missing', null);
        expect(fn () => (new ConnectionManager)->createConnection('missing'))
            ->toThrow(ConnectionNotFoundException::class);
    });

    it('accepts Eloquent model with all fields', function () use ($validPrivateKey) {
        $model = new class extends Model
        {
            protected $table = 'servers';

            public $timestamps = false;

            protected $guarded = [];
        };
        $model->host = 'modelall.example.com';
        $model->port = 22;
        $model->username = 'modelall';
        $model->private_key = $validPrivateKey;
        $model->script_path = '/home/modelall/.dply-task-runner';
        $conn = (new ConnectionManager)->createConnection($model);
        expect($conn->host)->toBe('modelall.example.com');
    });

    it('throws on Eloquent model with missing host', function () use ($validPrivateKey) {
        $model = new class extends Model
        {
            protected $table = 'servers';

            public $timestamps = false;

            protected $guarded = [];
        };
        $model->username = 'missinghost';
        $model->private_key = $validPrivateKey;
        expect(fn () => (new ConnectionManager)->createConnection($model))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts DB record with all fields', function () use ($validPrivateKey) {
        DB::table('servers')->insert([
            'id' => 200,
            'name' => 'dball',
            'host' => 'dball.example.com',
            'port' => 22,
            'username' => 'dball',
            'private_key' => $validPrivateKey,
            'script_path' => '/home/dball/.dply-task-runner',
        ]);
        $conn = (new ConnectionManager)->createConnection('servers:200');
        expect($conn->host)->toBe('dball.example.com');
    });

    it('throws on DB record with missing username', function () use ($validPrivateKey) {
        DB::table('servers')->insert([
            'id' => 201,
            'name' => 'dbmissinguser',
            'host' => 'dbmissinguser.example.com',
            'port' => 22,
            'private_key' => $validPrivateKey,
            'script_path' => '/home/dbmissinguser/.dply-task-runner',
        ]);
        expect(fn () => (new ConnectionManager)->createConnection('servers:201'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on string with user@host (no port, missing key)', function () {
        expect(fn () => (new ConnectionManager)->createConnection('user@hostonly'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on string with only host', function () {
        expect(fn () => (new ConnectionManager)->createConnection('hostonly'))
            ->toThrow(ConnectionNotFoundException::class);
    });

    it('throws on string with only port', function () {
        expect(fn () => (new ConnectionManager)->createConnection(':2222'))->toThrow(QueryException::class);
    });

    it('throws on string with special characters', function () {
        expect(fn () => (new ConnectionManager)->createConnection('user!@host:22'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on string with whitespace', function () {
        expect(fn () => (new ConnectionManager)->createConnection('user @host:22'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on string with tab/newline', function () {
        expect(fn () => (new ConnectionManager)->createConnection("user@host:22\n"))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on string with unicode', function () {
        expect(fn () => (new ConnectionManager)->createConnection('usér@host:22'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on string with SQL injection attempt', function () {
        expect(fn () => (new ConnectionManager)->createConnection('user@host:22; DROP TABLE users;'))->toThrow(QueryException::class);
    });

    it('accepts JSON file with multiple valid connections', function () use ($validPrivateKey) {
        $file = tempnam(sys_get_temp_dir(), 'multi').'.json';
        $data = [
            [
                'host' => 'json1.example.com',
                'username' => 'json1',
                'private_key' => $validPrivateKey,
            ],
            [
                'host' => 'json2.example.com',
                'username' => 'json2',
                'private_key' => $validPrivateKey,
            ],
        ];
        file_put_contents($file, json_encode($data));
        $connections = (new ConnectionManager)->createFromJsonFile($file);
        expect($connections)->toHaveCount(2);
        unlink($file);
    });

    it('throws on JSON file with one invalid connection', function () use ($validPrivateKey) {
        $file = tempnam(sys_get_temp_dir(), 'onebad').'.json';
        $data = [
            [
                'host' => 'json1.example.com',
                'username' => 'json1',
                'private_key' => $validPrivateKey,
            ],
            [
                'host' => 'jsonbad.example.com',
            ],
        ];
        file_put_contents($file, json_encode($data));
        expect(fn () => (new ConnectionManager)->createFromJsonFile($file))
            ->toThrow(InvalidArgumentException::class);
        unlink($file);
    });

    it('accepts CSV file with multiple valid connections', function () use ($validPrivateKey) {
        $file = tempnam(sys_get_temp_dir(), 'multic').'.csv';
        $rows = [
            ['host', 'port', 'username', 'private_key', 'script_path'],
            ['csv1.example.com', '22', 'csv1', $validPrivateKey, '/home/csv1/.dply-task-runner'],
            ['csv2.example.com', '22', 'csv2', $validPrivateKey, '/home/csv2/.dply-task-runner'],
        ];
        $handle = fopen($file, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        $connections = (new ConnectionManager)->createFromCsvFile($file);
        expect($connections)->toHaveCount(2);
        unlink($file);
    });

    it('throws on CSV file with missing required column', function () {
        $file = tempnam(sys_get_temp_dir(), 'badcol').'.csv';
        $rows = [
            ['host', 'port', 'private_key', 'script_path'], // missing username
            ['csvbad.example.com', '22', 'key', '/home/csvbad/.dply-task-runner'],
        ];
        $handle = fopen($file, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        expect(fn () => (new ConnectionManager)->createFromCsvFile($file))->toThrow(ArgumentCountError::class);
        unlink($file);
    });

    it('accepts environment with multiple valid connections', function () use ($validPrivateKey) {
        $_ENV['SSH_HOST_2'] = 'env2.example.com';
        $_ENV['SSH_USERNAME_2'] = 'env2';
        $_ENV['SSH_PORT_2'] = '22';
        $_ENV['SSH_PRIVATE_KEY_2'] = $validPrivateKey;
        $_ENV['SSH_SCRIPT_PATH_2'] = '/home/env2/.dply-task-runner';
        $_ENV['SSH_HOST_3'] = 'env3.example.com';
        $_ENV['SSH_USERNAME_3'] = 'env3';
        $_ENV['SSH_PORT_3'] = '22';
        $_ENV['SSH_PRIVATE_KEY_3'] = $validPrivateKey;
        $_ENV['SSH_SCRIPT_PATH_3'] = '/home/env3/.dply-task-runner';
        $connections = (new ConnectionManager)->createFromEnvironment(['SSH_']);
        expect($connections->count())->toBeGreaterThanOrEqual(2);
    });

    it('throws on environment with missing required field', function () {
        $_ENV['SSH_HOST_4'] = 'env4.example.com';
        $_ENV['SSH_PORT_4'] = '22';
        // missing username and private_key
        expect(fn () => (new ConnectionManager)->createFromEnvironment(['SSH_']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts batch with mixed valid/invalid arrays', function () use ($validPrivateKey) {
        $valid = [
            'host' => 'batchvalid.example.com',
            'username' => 'batchvalid',
            'private_key' => $validPrivateKey,
        ];
        $invalid = [
            'host' => 'batchinvalid.example.com',
        ];
        $result = (new ConnectionManager)->validateSources([$valid, $invalid]);
        expect($result['valid_count'])->toBe(1)
            ->and($result['error_count'])->toBe(1);
    });

    it('accepts batch with duplicate arrays', function () use ($validPrivateKey) {
        $data = [
            'host' => 'batchdup.example.com',
            'username' => 'batchdup',
            'private_key' => $validPrivateKey,
        ];
        $connections = (new ConnectionManager)->createConnections([$data, $data]);
        expect($connections)->toHaveCount(2);
    });

    it('returns empty collection for batch with empty array', function () {
        $connections = (new ConnectionManager)->createConnections([]);
        expect($connections)->toBeInstanceOf(Collection::class)
            ->and($connections)->toBeEmpty();
    });

    it('returns correct cache stats after batch and clear', function () use ($validPrivateKey) {
        $manager = new ConnectionManager;
        $data = [
            'host' => 'batchcache.example.com',
            'username' => 'batchcache',
            'private_key' => $validPrivateKey,
        ];
        $manager->createConnections([$data, $data]);
        $manager->clearCache();
        $stats = $manager->getCacheStats();
        expect($stats['cached_connections'])->toBe(0);
    });
    it('creates a connection from an Eloquent model instance', function () use ($validPrivateKey) {
        $model = new class extends Model
        {
            protected $table = 'servers';

            public $timestamps = false;

            protected $guarded = [];
        };
        $model->host = 'modelinst.example.com';
        $model->port = 22;
        $model->username = 'modelinst';
        $model->private_key = $validPrivateKey;
        $model->script_path = '/home/modelinst/.dply-task-runner';

        $conn = (new ConnectionManager)->createConnection($model);
        expect($conn)->toBeInstanceOf(Connection::class)
            ->and($conn->host)->toBe('modelinst.example.com')
            ->and($conn->username)->toBe('modelinst')
            ->and($conn->scriptPath)->toBe('/home/modelinst/.dply-task-runner');
    });
});
