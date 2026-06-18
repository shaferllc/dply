<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * Local hostname checks for fake-edge development (Valet, dnsmasq, etc.).
 */
final class EdgeLocalDevDiagnostics
{
    /**
     * @return list<array{name: string, ok: bool, detail: string}>
     *
     * @phpstan-impure
     */
    public static function checks(): array
    {
        if (EdgeTestingDomains::all() === []) {
            return [[
                'name' => 'edge_testing_domain',
                'ok' => false,
                'detail' => 'Set DPLY_EDGE_TESTING_DOMAINS to a domain that resolves to this machine (for example edge.test or dplyi.test).',
            ]];
        }

        $testingDomain = EdgeTestingDomains::defaultApex();
        $sampleHost = 'edge-local-dev-probe.'.$testingDomain;
        $resolved = self::resolveHost($sampleHost);
        $loopback = in_array($resolved, ['127.0.0.1', '::1'], true);
        $appHost = strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST)));

        $checks = [[
            'name' => 'edge_testing_domain',
            'ok' => true,
            'detail' => 'Testing domain: '.$testingDomain,
        ]];

        $checks[] = [
            'name' => 'wildcard_dns',
            'ok' => $loopback,
            'detail' => $loopback
                ? $sampleHost.' resolves to '.$resolved.' (requests can reach this app).'
                : self::dnsFailureDetail($testingDomain, $sampleHost, $resolved, $appHost),
        ];

        if ($appHost !== '' && $appHost === strtolower($testingDomain)) {
            $checks[] = [
                'name' => 'app_url_host',
                'ok' => true,
                'detail' => 'APP_URL host matches the Edge testing domain — site hostnames use {slug}.'.$testingDomain.' subdomains.',
            ];
        }

        return $checks;
    }

    /**
     * @return array{title: string, message: string}
     */
    public static function fakeModeBannerHint(): array
    {
        $testingDomain = EdgeTestingDomains::defaultApex();
        $appHost = strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST)));

        if (str_ends_with($testingDomain, '.test')) {
            return [
                'title' => __('Local hostname'),
                'message' => __(
                    'Edge sites live at {slug}.{domain}. With Valet, link this project to :domain (or use a subdomain of APP_URL) so wildcard DNS routes traffic here. See docs/edge-local-development.md.',
                    ['domain' => $testingDomain]
                ),
            ];
        }

        if ($appHost !== '' && str_ends_with($appHost, '.test') && $testingDomain !== $appHost) {
            return [
                'title' => __('Local hostname'),
                'message' => __(
                    'Easiest on Valet: set DPLY_EDGE_TESTING_DOMAINS=:app_host so sites use {slug}.:app_host (already routed by Valet). Or add dnsmasq for *.:domain — see docs/edge-local-development.md.',
                    ['app_host' => $appHost, 'domain' => $testingDomain]
                ),
            ];
        }

        return [
            'title' => __('Local hostname'),
            'message' => __(
                'Edge sites use {slug}.{domain}. Point *.{domain} to 127.0.0.1 with dnsmasq and route it to this app (Valet proxy or nginx). See docs/edge-local-development.md.',
                ['domain' => $testingDomain]
            ),
        ];
    }

    private static function resolveHost(string $host): string
    {
        $records = @dns_get_record($host, DNS_A);
        if (is_array($records) && $records !== []) {
            foreach ($records as $record) {
                if (($record) && isset($record['ip']) && is_string($record['ip']) && $record['ip'] !== '') {
                    return $record['ip'];
                }
            }
        }

        $fallback = gethostbyname($host);
        if ($fallback !== $host) {
            return $fallback;
        }

        return '';
    }

    private static function dnsFailureDetail(string $testingDomain, string $sampleHost, string $resolved, string $appHost): string
    {
        if (str_ends_with($testingDomain, '.test')) {
            return 'Valet dnsmasq did not resolve '.$sampleHost
                .($resolved !== '' ? ' to loopback (got '.$resolved.')' : '')
                .'. Run `valet link` in this project with `--domain='.self::valetLinkDomain($testingDomain).'` or set DPLY_EDGE_TESTING_DOMAINS to your linked APP_URL host'
                .($appHost !== '' ? ' ('.$appHost.')' : '')
                .'. See docs/edge-local-development.md.';
        }

        return $sampleHost.' does not resolve to 127.0.0.1'
            .($resolved !== '' ? ' (got '.$resolved.')' : '')
            .'. Add dnsmasq `address=/.'.$testingDomain.'/127.0.0.1` and route *.'.$testingDomain.' to this app. See docs/edge-local-development.md.';
    }

    private static function valetLinkDomain(string $testingDomain): string
    {
        $parts = explode('.', $testingDomain);
        if (count($parts) >= 2 && $parts[count($parts) - 1] === 'test') {
            return $parts[0];
        }

        return $testingDomain;
    }
}
