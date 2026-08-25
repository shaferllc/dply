<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Dns\AuthoritativeResolverTest;

use App\Support\Dns\AuthoritativeResolver;

/**
 * Byte-level parsing, exercised on crafted packets so the suite never touches
 * the network. The live path is covered by the caller's tests.
 */
function invoke(string $method, mixed ...$args): mixed
{
    $ref = new \ReflectionMethod(AuthoritativeResolver::class, $method);
    $ref->setAccessible(true);

    return $ref->invoke(new AuthoritativeResolver, ...$args);
}

/** Build a response for one question, with the given answer records appended. */
function response(int $id, string $qname, string $answers, int $answerCount, int $flags = 0x8180): string
{
    $encoded = invoke('encodeName', $qname);

    return pack('n6', $id, $flags, 1, $answerCount, 0, 0)
        .$encoded
        .pack('n2', 1, 1)
        .$answers;
}

/** One A record whose NAME is a compression pointer back to the question. */
function aRecord(string $ip): string
{
    return pack('n', 0xC00C)
        .pack('n2', 1, 1)
        .pack('N', 300)
        .pack('n', 4)
        .implode('', array_map('chr', array_map('intval', explode('.', $ip))));
}

function qnameLength(string $qname): int
{
    return strlen((string) invoke('encodeName', $qname));
}

test('a name is encoded as length-prefixed labels', function () {
    expect(invoke('encodeName', 'a.example.com'))->toBe("\x01a\x07example\x03com\x00");
});

test('it reads the A records out of an answer', function () {
    $packet = response(0x4470, 'example.com', aRecord('93.184.216.34').aRecord('93.184.216.35'), 2);

    expect(invoke('parseAnswers', $packet, 0x4470, qnameLength('example.com')))
        ->toBe(['93.184.216.34', '93.184.216.35']);
});

test('a mismatched transaction id is not trusted', function () {
    $packet = response(0x1111, 'example.com', aRecord('93.184.216.34'), 1);

    // A stray datagram must read as "unknown", never as an answer — otherwise
    // it could report a hostname as pointing somewhere it does not.
    expect(invoke('parseAnswers', $packet, 0x4470, qnameLength('example.com')))->toBeNull();
});

test('NXDOMAIN is an answer, other failures are unknown', function () {
    $nx = response(0x4470, 'nope.example.com', '', 0, 0x8183);
    $servfail = response(0x4470, 'nope.example.com', '', 0, 0x8182);

    // The distinction matters: [] means "no A record" and can be reported,
    // null means "could not ask" and must fall back to the cached view.
    expect(invoke('parseAnswers', $nx, 0x4470, qnameLength('nope.example.com')))->toBe([]);
    expect(invoke('parseAnswers', $servfail, 0x4470, qnameLength('nope.example.com')))->toBeNull();
});

test('non-A records in the answer are skipped', function () {
    // A CNAME ahead of the A record — the usual shape for a www host.
    $cname = pack('n', 0xC00C)
        .pack('n2', 5, 1)
        .pack('N', 300)
        .pack('n', 2)
        ."\xC0\x0C";

    $packet = response(0x4470, 'www.example.com', $cname.aRecord('93.184.216.34'), 2);

    expect(invoke('parseAnswers', $packet, 0x4470, qnameLength('www.example.com')))
        ->toBe(['93.184.216.34']);
});

test('a truncated packet stops rather than reading past the end', function () {
    $packet = substr(response(0x4470, 'example.com', aRecord('93.184.216.34'), 1), 0, -3);

    expect(invoke('parseAnswers', $packet, 0x4470, qnameLength('example.com')))->toBe([]);
});

test('hostnames it cannot ask about resolve to null without a lookup', function () {
    $resolver = new AuthoritativeResolver;

    // No dot means no zone to find nameservers for; both must return before
    // any network call is attempted.
    expect($resolver->resolveA('localhost'))->toBeNull();
    expect($resolver->resolveA(''))->toBeNull();
    expect($resolver->pointsAt('localhost', ''))->toBeFalse();
});
