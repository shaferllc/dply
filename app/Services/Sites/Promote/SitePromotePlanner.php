<?php

declare(strict_types=1);

namespace App\Services\Sites\Promote;

use App\Models\Site;

/**
 * Cutover playbook for a preview-first site promote clone.
 */
final class SitePromotePlanner
{
    /**
     * @return list<array{text: string, href: string|null, link_label: string|null}>
     */
    /** @return array<string, mixed> */
    /**
     * @return array<string, mixed>
     */
    public function previewSteps(Site $source): array
    {
        $productionHostname = (string) ($source->primaryDomain()->hostname ?? '');

        $steps = [
            ['text' => __('Provision completes on the standby server with a preview hostname.'), 'href' => null, 'link_label' => null],
            ['text' => __('Smoke-test the preview URL and copy environment variables from the source site.'), 'href' => null, 'link_label' => null],
            ['text' => __('Verify deploy hooks, crons, and workers on the standby site.'), 'href' => null, 'link_label' => null],
        ];

        if ($productionHostname !== '') {
            $steps[] = [
                'text' => __('Cut over :hostname via Routing → Domains and DNS when preview looks good.', ['hostname' => $productionHostname]),
                'href' => null,
                'link_label' => null,
            ];
        } else {
            $steps[] = ['text' => __('Attach production DNS on the standby site when ready.'), 'href' => null, 'link_label' => null];
        }

        $steps[] = ['text' => __('Issue TLS and decommission the source site after traffic is stable.'), 'href' => null, 'link_label' => null];

        return $steps;
    }

    /**
     * @return array<int, array<string, array|string|null>>
     */
    /** @return array<string, mixed> */
    /**
     * @return array<string, mixed>
     */
    public function cutoverSteps(Site $destination, ?Site $source = null): array
    {
        $promote = is_array($destination->meta['promote'] ?? null) ? $destination->meta['promote'] : [];
        $productionHostname = is_string($promote['source_production_hostname'] ?? null)
            ? (string) $promote['source_production_hostname']
            : null;

        $sourceSite = $source;
        if ($sourceSite === null && is_string($promote['source_site_id'] ?? null)) {
            $sourceSite = Site::query()->find($promote['source_site_id']);
        }

        $steps = [
            [
                'text' => __('Wait for the standby site to finish provisioning on the destination server, then open its preview hostname and smoke-test deploy output.'),
                'href' => $destination->server_id !== null
                    ? route('sites.show', ['server' => $destination->server_id, 'site' => $destination, 'section' => 'routing'])
                    : null,
                'link_label' => __('Open routing'),
            ],
            [
                'text' => __('Copy environment variables and secrets from the source site — they are not cloned automatically.'),
                'href' => $destination->server_id !== null
                    ? route('sites.show', ['server' => $destination->server_id, 'site' => $destination, 'section' => 'settings'])
                    : null,
                'link_label' => __('Destination settings'),
            ],
            [
                'text' => __('Verify deploy hooks, cron jobs, and queue workers on the standby site match production expectations.'),
                'href' => $destination->server_id !== null
                    ? route('sites.show', ['server' => $destination->server_id, 'site' => $destination, 'section' => 'deploy'])
                    : null,
                'link_label' => __('Deploy settings'),
            ],
        ];

        if ($productionHostname !== null && $productionHostname !== '') {
            $steps[] = [
                'text' => __('When ready to cut over, add :hostname as the primary domain on the standby site (or swap primaries), lower DNS TTL ahead of time, and point A/CNAME records to :ip.', [
                    'hostname' => $productionHostname,
                    'ip' => $destination->server?->ip_address ?: __('the destination server IP'),
                ]),
                'href' => $destination->server_id !== null
                    ? route('sites.show', ['server' => $destination->server_id, 'site' => $destination, 'section' => 'routing'])
                    : null,
                'link_label' => __('Domains tab'),
            ];
        } else {
            $steps[] = [
                'text' => __('When ready to cut over, attach your production hostname on the standby site and update DNS to the destination server IP.'),
                'href' => $destination->server_id !== null
                    ? route('sites.show', ['server' => $destination->server_id, 'site' => $destination, 'section' => 'routing'])
                    : null,
                'link_label' => __('Domains tab'),
            ];
        }

        $steps[] = [
            'text' => __('Issue or renew TLS on the production hostname after DNS propagates, then monitor deploy health before decommissioning the source site.'),
            'href' => $destination->server_id !== null
                ? route('sites.show', ['server' => $destination->server_id, 'site' => $destination, 'section' => 'certificates'])
                : null,
            'link_label' => __('Certificates'),
        ];

        if ($sourceSite !== null && $sourceSite->server_id !== null) {
            $steps[] = [
                'text' => __('Keep the source site running until traffic is stable on the standby target, then suspend or delete the old site.'),
                'href' => route('sites.show', ['server' => $sourceSite->server_id, 'site' => $sourceSite, 'section' => 'danger']),
                'link_label' => __('Source danger zone'),
            ];
        }

        return $steps;
    }

    /**
     * @return array<int, array<string, array|string|null>>
     */
    /** @return array<string, mixed> */
    public function summary(Site $destination, ?Site $source = null): array
    {
        $promote = is_array($destination->meta['promote'] ?? null) ? $destination->meta['promote'] : [];

        if ($source === null && is_string($promote['source_site_id'] ?? null)) {
            $source = Site::query()->with('server:id,name')->find($promote['source_site_id']);
        }

        $preview = $destination->primaryDomain()->hostname
            ?? $destination->testingHostname();

        return [
            'source_site_name' => $source !== null ? (string) $source->name : null,
            'source_server_name' => $source?->server !== null ? (string) $source->server->name : null,
            'production_hostname' => is_string($promote['source_production_hostname'] ?? null)
                ? (string) $promote['source_production_hostname']
                : null,
            'preview_hostname' => ($preview) && $preview !== '' ? $preview : null,
            'destination_server_name' => $destination->server !== null ? (string) $destination->server->name : null,
        ];
    }
}
