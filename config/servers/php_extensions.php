<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Manageable PHP extensions
|--------------------------------------------------------------------------
|
| Catalog for the server workspace Runtime → PHP tab (the expandable
| "Extensions" panel under each installed version row). Mirrors the shape of
| `server_provision_options.php_versions`: a flat, curated list the UI renders
| directly. Anything not listed here is still installable through the panel's
| free-text escape hatch, which is validated against a strict slug pattern
| rather than this catalog.
|
| Field notes:
|
|   id          apt package suffix — installs as `php{version}-{id}`.
|   modules     names `php -m` / the mods-available .ini files report for this
|               package. Several packages ship more than one module (php-mysql
|               provides mysqli + pdo_mysql, and reports NEITHER as "mysql"),
|               so presence detection must match on these, not on `id`.
|   pecl        true when the package can be built from PECL if apt has no
|               binary for that PHP version. Drives the apt→PECL fallback in
|               BuildsPhpExtensionScripts::installScript(). Compiling can take
|               minutes, which is why installs run as a queued job.
|   bundled     ships inside php{version}-common — there is nothing to apt
|               install or purge, so the panel offers enable/disable only.
|   min_php     lowest PHP version the package exists for (inclusive).
|   max_php     highest PHP version the package exists for (inclusive).
|               imap left core in 8.4; sqlsrv needs Microsoft's own repo.
|   note        short caveat rendered under the row when present.
|
| Uninstall protection lives in GuardsPhpExtensionActions::PROTECTED_SUFFIXES,
| not here — it must hold for free-text input too.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Category labels
    |--------------------------------------------------------------------------
    |
    | Render order for the grouped list. Keys must match the `category` on each
    | extension below; an extension in an unknown category falls to the bottom
    | under "Other".
    |
    */

    'categories' => [
        'databases' => 'Databases',
        'caching' => 'Caching & serialization',
        'imaging' => 'Imaging',
        'text' => 'Text, XML & i18n',
        'network' => 'Network & protocols',
        'performance' => 'Performance & runtime',
        'dev' => 'Development & profiling',
        'math' => 'Math & compression',
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    */

    'extensions' => [

        // ---- Databases -----------------------------------------------------

        [
            'id' => 'pgsql',
            'label' => 'PostgreSQL',
            'description' => 'Native pgsql driver plus PDO. Required for Laravel\'s pgsql connection.',
            'category' => 'databases',
            'modules' => ['pgsql', 'pdo_pgsql'],
        ],
        [
            'id' => 'mysql',
            'label' => 'MySQL / MariaDB',
            'description' => 'mysqli and PDO drivers for MySQL and MariaDB.',
            'category' => 'databases',
            'modules' => ['mysqli', 'pdo_mysql', 'mysqlnd'],
        ],
        [
            'id' => 'sqlite3',
            'label' => 'SQLite',
            'description' => 'SQLite driver plus PDO.',
            'category' => 'databases',
            'modules' => ['sqlite3', 'pdo_sqlite'],
        ],
        [
            'id' => 'mongodb',
            'label' => 'MongoDB',
            'description' => 'Official MongoDB driver, required by mongodb/laravel-mongodb.',
            'category' => 'databases',
            'modules' => ['mongodb'],
            'pecl' => true,
        ],
        [
            'id' => 'odbc',
            'label' => 'ODBC',
            'description' => 'Generic ODBC driver plus PDO.',
            'category' => 'databases',
            'modules' => ['odbc', 'pdo_odbc'],
        ],
        [
            'id' => 'dba',
            'label' => 'DBA',
            'description' => 'Berkeley-style key/value database abstraction.',
            'category' => 'databases',
            'modules' => ['dba'],
        ],

        // ---- Caching & serialization ---------------------------------------

        [
            'id' => 'redis',
            'label' => 'Redis',
            'description' => 'phpredis. Required for Laravel\'s redis cache, queue, and session drivers.',
            'category' => 'caching',
            'modules' => ['redis'],
            'pecl' => true,
        ],
        [
            'id' => 'memcached',
            'label' => 'Memcached',
            'description' => 'libmemcached-backed client for Laravel\'s memcached driver.',
            'category' => 'caching',
            'modules' => ['memcached'],
            'pecl' => true,
        ],
        [
            'id' => 'apcu',
            'label' => 'APCu',
            'description' => 'In-process user cache — backs Laravel\'s apc cache driver.',
            'category' => 'caching',
            'modules' => ['apcu'],
            'pecl' => true,
        ],
        [
            'id' => 'igbinary',
            'label' => 'igbinary',
            'description' => 'Compact binary serializer. Speeds up redis and memcached payloads.',
            'category' => 'caching',
            'modules' => ['igbinary'],
            'pecl' => true,
        ],
        [
            'id' => 'msgpack',
            'label' => 'MessagePack',
            'description' => 'MessagePack serializer, an alternative igbinary/serialize backend.',
            'category' => 'caching',
            'modules' => ['msgpack'],
            'pecl' => true,
        ],

        // ---- Imaging -------------------------------------------------------

        [
            'id' => 'gd',
            'label' => 'GD',
            'description' => 'Bundled image toolkit. The default driver for intervention/image.',
            'category' => 'imaging',
            'modules' => ['gd'],
        ],
        [
            'id' => 'imagick',
            'label' => 'ImageMagick',
            'description' => 'ImageMagick bindings — wider format support than GD.',
            'category' => 'imaging',
            'modules' => ['imagick'],
            'pecl' => true,
        ],
        [
            'id' => 'exif',
            'label' => 'EXIF',
            'description' => 'Reads EXIF metadata from images. Laravel\'s image validation rule uses it.',
            'category' => 'imaging',
            'modules' => ['exif'],
            'bundled' => true,
        ],

        // ---- Text, XML & i18n ----------------------------------------------

        [
            'id' => 'mbstring',
            'label' => 'mbstring',
            'description' => 'Multibyte string handling. Effectively required by Laravel.',
            'category' => 'text',
            'modules' => ['mbstring'],
        ],
        [
            'id' => 'intl',
            'label' => 'intl',
            'description' => 'ICU internationalization — collation, formatting, transliteration.',
            'category' => 'text',
            'modules' => ['intl'],
        ],
        [
            'id' => 'xml',
            'label' => 'XML',
            'description' => 'DOM, SimpleXML, XMLReader/Writer. Required by PHPUnit and many parsers.',
            'category' => 'text',
            'modules' => ['xml', 'dom', 'simplexml', 'xmlreader', 'xmlwriter'],
        ],
        [
            'id' => 'xsl',
            'label' => 'XSL',
            'description' => 'XSLT 1.0 transformations over the DOM extension.',
            'category' => 'text',
            'modules' => ['xsl'],
        ],
        [
            'id' => 'tidy',
            'label' => 'Tidy',
            'description' => 'HTML cleanup and repair via libtidy.',
            'category' => 'text',
            'modules' => ['tidy'],
        ],
        [
            'id' => 'yaml',
            'label' => 'YAML',
            'description' => 'libyaml parser and emitter.',
            'category' => 'text',
            'modules' => ['yaml'],
            'pecl' => true,
        ],
        [
            'id' => 'gettext',
            'label' => 'gettext',
            'description' => 'GNU gettext message catalogs.',
            'category' => 'text',
            'modules' => ['gettext'],
        ],

        // ---- Network & protocols -------------------------------------------

        [
            'id' => 'curl',
            'label' => 'cURL',
            'description' => 'HTTP client backend for Guzzle and Laravel\'s Http facade.',
            'category' => 'network',
            'modules' => ['curl'],
        ],
        [
            'id' => 'soap',
            'label' => 'SOAP',
            'description' => 'SOAP client and server.',
            'category' => 'network',
            'modules' => ['soap'],
        ],
        [
            'id' => 'ldap',
            'label' => 'LDAP',
            'description' => 'Directory access — Active Directory and OpenLDAP auth.',
            'category' => 'network',
            'modules' => ['ldap'],
        ],
        [
            'id' => 'imap',
            'label' => 'IMAP',
            'description' => 'Mailbox access over IMAP/POP3.',
            'category' => 'network',
            'modules' => ['imap'],
            'max_php' => '8.3',
            'note' => 'Removed from core in PHP 8.4 — use the PECL build or a userland client there.',
        ],
        [
            'id' => 'ssh2',
            'label' => 'SSH2',
            'description' => 'libssh2 bindings for SSH and SFTP.',
            'category' => 'network',
            'modules' => ['ssh2'],
            'pecl' => true,
        ],
        [
            'id' => 'amqp',
            'label' => 'AMQP',
            'description' => 'RabbitMQ client via librabbitmq.',
            'category' => 'network',
            'modules' => ['amqp'],
            'pecl' => true,
        ],
        [
            'id' => 'snmp',
            'label' => 'SNMP',
            'description' => 'SNMP agent queries.',
            'category' => 'network',
            'modules' => ['snmp'],
        ],
        [
            'id' => 'sockets',
            'label' => 'Sockets',
            'description' => 'Low-level BSD socket API.',
            'category' => 'network',
            'modules' => ['sockets'],
            'bundled' => true,
        ],

        // ---- Performance & runtime -----------------------------------------

        [
            'id' => 'opcache',
            'label' => 'OPcache',
            'description' => 'Bytecode cache. Disabling it will noticeably slow every site on this version.',
            'category' => 'performance',
            'modules' => ['Zend OPcache', 'opcache'],
        ],
        [
            'id' => 'swoole',
            'label' => 'Swoole',
            'description' => 'Coroutine runtime — the engine behind Laravel Octane\'s swoole server.',
            'category' => 'performance',
            'modules' => ['swoole'],
            'pecl' => true,
        ],
        [
            'id' => 'ffi',
            'label' => 'FFI',
            'description' => 'Calls into C libraries from PHP. Disabled in production INIs by default.',
            'category' => 'performance',
            'modules' => ['ffi'],
        ],
        [
            'id' => 'readline',
            'label' => 'readline',
            'description' => 'Line editing and history for interactive CLI (php -a, tinker).',
            'category' => 'performance',
            'modules' => ['readline'],
        ],
        [
            'id' => 'sodium',
            'label' => 'Sodium',
            'description' => 'libsodium modern cryptography.',
            'category' => 'performance',
            'modules' => ['sodium'],
            'bundled' => true,
        ],

        // ---- Development & profiling ---------------------------------------

        [
            'id' => 'xdebug',
            'label' => 'Xdebug',
            'description' => 'Step debugger and profiler. Leave it off on production — it is a large slowdown.',
            'category' => 'dev',
            'modules' => ['xdebug'],
            'pecl' => true,
            'note' => 'Install on staging only, or keep it installed and disabled until you need it.',
        ],
        [
            'id' => 'pcov',
            'label' => 'PCOV',
            'description' => 'Fast line-coverage driver for PHPUnit — far cheaper than Xdebug coverage.',
            'category' => 'dev',
            'modules' => ['pcov'],
            'pecl' => true,
        ],
        [
            'id' => 'dev',
            'label' => 'PHP dev headers',
            'description' => 'phpize and headers. Needed to build any PECL extension for this version.',
            'category' => 'dev',
            'modules' => [],
            'note' => 'Installed automatically when a PECL build is required.',
        ],

        // ---- Math & compression --------------------------------------------

        [
            'id' => 'bcmath',
            'label' => 'BCMath',
            'description' => 'Arbitrary-precision decimal math.',
            'category' => 'math',
            'modules' => ['bcmath'],
        ],
        [
            'id' => 'gmp',
            'label' => 'GMP',
            'description' => 'Arbitrary-precision integer math via libgmp.',
            'category' => 'math',
            'modules' => ['gmp'],
        ],
        [
            'id' => 'zip',
            'label' => 'Zip',
            'description' => 'Reads and writes ZIP archives. Composer uses it to unpack packages.',
            'category' => 'math',
            'modules' => ['zip'],
        ],
        [
            'id' => 'bz2',
            'label' => 'bzip2',
            'description' => 'bzip2 compression streams.',
            'category' => 'math',
            'modules' => ['bz2'],
        ],
        [
            'id' => 'zstd',
            'label' => 'Zstandard',
            'description' => 'Zstd compression — a fast igbinary/redis payload compressor.',
            'category' => 'math',
            'modules' => ['zstd'],
            'pecl' => true,
        ],
    ],
];
