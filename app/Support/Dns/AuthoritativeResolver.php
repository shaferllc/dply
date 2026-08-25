<?php

declare(strict_types=1);

namespace App\Support\Dns;

/**
 * Resolves a hostname by asking its zone's own nameservers, bypassing every
 * recursive cache in between.
 *
 * PHP's resolver functions (`gethostbynamel`, `dns_get_record`) all go through
 * the system resolver, so they return whatever that cache holds. Right after a
 * record changes that is the OLD address, for as long as the previous record's
 * TTL has left to run — and a control panel that reads it reports a correctly
 * configured domain as misconfigured. This asks the source instead, so
 * "propagating" can be told apart from "actually pointing somewhere else".
 *
 * Deliberately minimal: one UDP question, A records only, no EDNS, no TCP
 * fallback, no DNSSEC validation. Anything it cannot answer confidently comes
 * back as null and callers fall back to the cached view.
 *
 * The NS lookup itself goes through the system resolver on purpose — a zone's
 * nameservers change on the order of years, so a cached answer there is fine,
 * and it keeps this class to A-record parsing only.
 */
final class AuthoritativeResolver
{
    private const DNS_PORT = 53;

    private const QUERY_TIMEOUT_SECONDS = 2;

    /** Nameservers to try before giving up — the rest are almost always redundant. */
    private const MAX_NAMESERVERS = 2;

    /**
     * A records for $hostname straight from its authoritative nameservers.
     *
     * Returns null when no authoritative answer could be obtained (no NS found,
     * every nameserver timed out, a malformed response). Null means "unknown",
     * NOT "no records" — an empty array is the answer for a name that exists
     * with no A record.
     *
     * @return list<string>|null
     */
    public function resolveA(string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname, " \t\n\r\0\x0B."));
        if ($hostname === '' || ! str_contains($hostname, '.')) {
            return null;
        }

        foreach ($this->nameserverIps($hostname) as $nameserverIp) {
            $answer = $this->queryA($nameserverIp, $hostname);
            if ($answer !== null) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * True when the authoritative answer already contains $ip — i.e. the record
     * is correct at the source and any disagreement is a cache still expiring.
     *
     * Returns false when the authoritative answer is unknown, so an unreachable
     * nameserver never gets reported as "propagating".
     */
    public function pointsAt(string $hostname, string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        return in_array($ip, $this->resolveA($hostname) ?? [], true);
    }

    /**
     * IPs of the nameservers for the closest zone that has any, walking up the
     * label stack (app.acme.co.uk → acme.co.uk → co.uk) so a delegated
     * subdomain zone is preferred over the registrable domain.
     *
     * @return list<string>
     */
    private function nameserverIps(string $hostname): array
    {
        $labels = explode('.', $hostname);

        // Stop at two labels: a single label is a TLD, whose nameservers can
        // only ever delegate, never answer for this name.
        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $zone = implode('.', array_slice($labels, $i));

            $records = @dns_get_record($zone, DNS_NS) ?: [];

            $ips = [];
            foreach ($records as $record) {
                $target = $record['target'] ?? null;
                if (! is_string($target) || $target === '') {
                    continue;
                }

                $ip = @gethostbyname($target);
                // gethostbyname hands back the input unchanged on failure.
                if ($ip !== $target && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[] = $ip;
                }

                if (count($ips) >= self::MAX_NAMESERVERS) {
                    break;
                }
            }

            if ($ips !== []) {
                return $ips;
            }
        }

        return [];
    }

    /**
     * Ask one nameserver for $hostname's A records over UDP.
     *
     * @return list<string>|null null on timeout, transport error, or a response
     *                           that does not answer the question asked
     */
    private function queryA(string $nameserverIp, string $hostname): ?array
    {
        // A fixed ID is safe here: the socket is connected to one server and
        // read exactly once, so there is no cross-talk to disambiguate. The
        // ID is still echoed back and checked, which catches a stale datagram.
        $id = 0x4470;
        $query = pack('n6', $id, 0x0100 /* standard query, recursion desired */, 1, 0, 0, 0)
            .$this->encodeName($hostname)
            .pack('n2', 1 /* type A */, 1 /* class IN */);

        $socket = @stream_socket_client(
            'udp://'.$nameserverIp.':'.self::DNS_PORT,
            $errno,
            $errstr,
            self::QUERY_TIMEOUT_SECONDS
        );
        if (! is_resource($socket)) {
            return null;
        }

        try {
            stream_set_timeout($socket, self::QUERY_TIMEOUT_SECONDS);

            if (@fwrite($socket, $query) === false) {
                return null;
            }

            $response = @fread($socket, 4096);
            if (! is_string($response) || strlen($response) < 12) {
                return null;
            }
        } finally {
            @fclose($socket);
        }

        return $this->parseAnswers($response, $id, strlen($this->encodeName($hostname)));
    }

    /**
     * Pull the A records out of a response body.
     *
     * @return list<string>|null
     */
    private function parseAnswers(string $response, int $expectedId, int $questionNameLength): ?array
    {
        /** @var array{id: int, flags: int, qd: int, an: int} $header */
        $header = unpack('nid/nflags/nqd/nan', substr($response, 0, 8));

        if ($header['id'] !== $expectedId) {
            return null;
        }

        // Low nibble of the flags is RCODE. NXDOMAIN (3) is a real answer —
        // the name does not exist — so it reports as "no A records" rather
        // than "unknown". Any other failure is unknown.
        $rcode = $header['flags'] & 0x0F;
        if ($rcode === 3) {
            return [];
        }
        if ($rcode !== 0) {
            return null;
        }

        // Skip the header and the echoed question.
        $offset = 12 + $questionNameLength + 4;
        $ips = [];

        for ($i = 0; $i < $header['an']; $i++) {
            // Each record starts with a NAME, which is either a compression
            // pointer (two bytes, high bits set) or an inline label sequence.
            if ($offset + 2 > strlen($response)) {
                break;
            }

            if ((ord($response[$offset]) & 0xC0) === 0xC0) {
                $offset += 2;
            } else {
                while ($offset < strlen($response) && ord($response[$offset]) !== 0) {
                    $offset += ord($response[$offset]) + 1;
                }
                $offset++;
            }

            if ($offset + 10 > strlen($response)) {
                break;
            }

            /** @var array{type: int, class: int, ttl: int, length: int} $rr */
            $rr = unpack('ntype/nclass/Nttl/nlength', substr($response, $offset, 10));
            $offset += 10;

            // RDLENGTH is what the sender CLAIMS follows. A truncated datagram
            // leaves fewer bytes than that, and reading anyway built an address
            // out of whatever was there ("93" from a clipped 93.184.216.34).
            if ($offset + $rr['length'] > strlen($response)) {
                break;
            }

            // Type A, class IN, and exactly four bytes of address. A CNAME in
            // the chain is simply skipped — the A record it points to is in the
            // same answer section.
            if ($rr['type'] === 1 && $rr['class'] === 1 && $rr['length'] === 4) {
                $ips[] = implode('.', array_map('ord', str_split(substr($response, $offset, 4))));
            }

            $offset += $rr['length'];
        }

        return array_values(array_unique($ips));
    }

    /** Wire format: each label prefixed with its length, terminated by a zero byte. */
    private function encodeName(string $hostname): string
    {
        $encoded = '';
        foreach (explode('.', $hostname) as $label) {
            $encoded .= chr(strlen($label)).$label;
        }

        return $encoded."\0";
    }
}
