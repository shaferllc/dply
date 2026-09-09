<?php

declare(strict_types=1);

namespace App\Support\Sites;

/**
 * Which PHP extension an error is really complaining about.
 *
 * PHP never says "the imagick extension is missing". It says
 * `Class "Imagick" not found`, or `Call to undefined function imagecreate()` —
 * a symbol, not a package. Every one of those reads as an application bug until
 * someone knows the mapping, which is why a missing extension costs an hour and
 * a deploy that "worked on my machine".
 *
 * Only symbols that are unambiguously extension-owned belong here. `Redis` is
 * absent on purpose: `php_ext_redis_missing` matches it first and its fix has to
 * handle the PECL build and the duplicate-module case, which a plain apt install
 * does not.
 */
final class MissingPhpExtensionResolver
{
    /**
     * Class name (lower-cased) => extension id in the server extension catalog.
     *
     * @var array<string, string>
     */
    private const CLASSES = [
        'imagick' => 'imagick',
        'imagickpixel' => 'imagick',
        'imagickdraw' => 'imagick',
        'memcached' => 'memcached',
        'mongodb\\driver\\manager' => 'mongodb',
        'ziparchive' => 'zip',
        'soapclient' => 'soap',
        'soapserver' => 'soap',
        'intldateformatter' => 'intl',
        'numberformatter' => 'intl',
        'collator' => 'intl',
        'xsltprocessor' => 'xsl',
        'tidy' => 'tidy',
        'gmp' => 'gmp',
        'snmp' => 'snmp',
        'amqpconnection' => 'amqp',
        'ssh2' => 'ssh2',
    ];

    /**
     * Function name (lower-cased) => extension id.
     *
     * Every id here must exist in `server_php_extensions.extensions`, or the
     * fix card offers an install the server manager cannot perform. iconv,
     * pcntl and posix are absent for that reason — they are not in the
     * catalog (and are normally compiled in anyway).
     *
     * @var array<string, string>
     */
    private const FUNCTIONS = [
        'imagecreate' => 'gd',
        'imagecreatetruecolor' => 'gd',
        'imagejpeg' => 'gd',
        'imagepng' => 'gd',
        'exif_read_data' => 'exif',
        'bcadd' => 'bcmath',
        'bcmul' => 'bcmath',
        'gmp_add' => 'gmp',
        'curl_init' => 'curl',
        'mb_strlen' => 'mbstring',
        'ldap_connect' => 'ldap',
        'imap_open' => 'imap',
        'yaml_parse' => 'yaml',
        'msgpack_pack' => 'msgpack',
        'igbinary_serialize' => 'igbinary',
        'apcu_fetch' => 'apcu',
        'socket_create' => 'sockets',
        'gettext' => 'gettext',
    ];

    /**
     * The extension id an error text points at, or null when nothing matches.
     *
     * Deliberately returns the FIRST match rather than a list: a fix that
     * installs one named extension is verifiable, and a log tail can carry
     * several unrelated historic errors.
     */
    public static function fromErrorText(?string $text): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        if (preg_match('/Class\s+["\']([\\\\A-Za-z0-9_]+)["\']\s+not found/i', $text, $m) === 1) {
            $extension = self::CLASSES[strtolower(ltrim($m[1], '\\'))] ?? null;

            if ($extension !== null) {
                return $extension;
            }
        }

        if (preg_match('/Call to undefined function\s+\\\\?([A-Za-z0-9_]+)\s*\(/i', $text, $m) === 1) {
            return self::FUNCTIONS[strtolower($m[1])] ?? null;
        }

        return null;
    }
}
