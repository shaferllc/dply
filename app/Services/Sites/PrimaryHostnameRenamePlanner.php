<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\Cloud\CloudRouter;

/**
 * Pure read-only previewer for the cascade triggered when an operator
 * changes a site's primary hostname. Returns a structured description
 * the Livewire layer renders in the confirmation modal; callers decide
 * which opt-in cascades to actually execute.
 *
 * Drives the "Save & apply selected" UX in the General settings page.
 * Does not mutate the site or dispatch any jobs.
 */
final class PrimaryHostnameRenamePlanner
{
    /**
     * Compute the cascade preview for renaming {old} → {new}. Returns the
     * shape consumed by `livewire.sites.settings` and
     * `Settings::confirmPrimaryHostnameRename()`.
     *
     * @return array{
     *   old: string,
     *   new: string,
     *   auto: list<array{key: string, label: string}>,
     *   optIn: list<array{key: string, label: string, detail?: array<string, mixed>}>,
     *   manual: list<string>,
     * }
     */
    /** @return array<string, mixed> */
    public function plan(Site $site, string $newHostname): array
    {
        $old = strtolower(trim((string) optional($site->primaryDomain())->hostname));
        $new = strtolower(trim($newHostname));

        $auto = [
            ['key' => 'nginx', 'label' => __('Rebuild nginx server_name')],
            ['key' => 'audit', 'label' => __('Record audit event')],
        ];

        // dns_zone re-suggest only when the saved zone matches what dply would
        // have auto-guessed from the *old* hostname. An operator-set custom zone
        // is left alone.
        if ($site->dnsZoneMatchesAutoGuessForHostname($old)) {
            $newApex = Site::apexGuessForHostname($new);
            $oldApex = Site::apexGuessForHostname($old);
            if ($newApex !== null && $newApex !== $oldApex) {
                $auto[] = [
                    'key' => 'dns_zone',
                    'label' => __('Re-suggest DNS zone (:old → :new)', ['old' => $oldApex ?? '—', 'new' => $newApex]),
                ];
            }
        }

        $optIn = [];

        $staleCerts = $this->staleCertificates($site, $old, $new);
        if ($staleCerts !== []) {
            $optIn[] = [
                'key' => 'reissue_cert',
                'label' => __('Re-issue SSL cert (existing covers :old only)', ['old' => $old]),
                'detail' => ['cert_ids' => array_map(fn (SiteCertificate $c) => (string) $c->id, $staleCerts)],
            ];
        }

        $backend = CloudRouter::backendFor($site);
        if ($backend !== null) {
            $optIn[] = [
                'key' => 'cycle_backend',
                'label' => __('Detach :old / attach :new on :backend', [
                    'old' => $old,
                    'new' => $new,
                    'backend' => $this->backendLabel((string) $site->container_backend),
                ]),
                'detail' => ['backend' => (string) $site->container_backend],
            ];
        }

        $manual = [
            __('Slack / webhook receivers that cached the URL'),
            __('Registrar DNS / external integrations'),
        ];
        if ($this->isLaravelScaffold($site)) {
            // Pre-pended so the .env warning is the first item operators read —
            // it's the one that breaks app behavior (redirects to old host),
            // not just observability.
            array_unshift($manual, __('Scaffolded .env APP_URL (Laravel apps must update manually)'));
        }

        return [
            'old' => $old,
            'new' => $new,
            'auto' => $auto,
            'optIn' => $optIn,
            'manual' => $manual,
        ];
    }

    /**
     * Trivial plan: only the always-auto rows (nginx, audit) and no opt-ins.
     * Lets greenfield sites skip the confirmation modal entirely.
     *
     * @param  array{auto: list<mixed>, optIn: list<mixed>}  $plan
     */
    public function isTrivial(array $plan): bool
    {
        if (! empty($plan['optIn'])) {
            return false;
        }

        // 2 = the two unconditional auto rows (nginx + audit). Anything beyond
        // that (dns_zone re-suggest) is a state change worth surfacing.
        return count($plan['auto']) <= 2;
    }

    /**
     * Active/issued/installing certificates whose `domains_json` covers the old
     * primary hostname but not the new one. These are the certs nginx will
     * serve until the operator re-issues.
     *
     * @return list<SiteCertificate>
     */
    private function staleCertificates(Site $site, string $old, string $new): array
    {
        if ($old === '') {
            return [];
        }
        $relevant = [
            SiteCertificate::STATUS_ACTIVE,
            SiteCertificate::STATUS_ISSUED,
            SiteCertificate::STATUS_INSTALLING,
        ];

        $site->loadMissing('certificates');

        return $site->certificates
            ->filter(function (SiteCertificate $cert) use ($relevant, $old, $new): bool {
                if (! in_array($cert->status, $relevant, true)) {
                    return false;
                }
                $hosts = $cert->domainHostnames();

                return in_array($old, $hosts, true) && ! in_array($new, $hosts, true);
            })
            ->values()
            ->all();
    }

    private function backendLabel(string $key): string
    {
        return match ($key) {
            'digitalocean_app_platform' => 'DigitalOcean App Platform',
            'aws_app_runner' => 'AWS App Runner',
            default => $key !== '' ? $key : __('container backend'),
        };
    }

    private function isLaravelScaffold(Site $site): bool
    {
        $meta = ($site->meta );
        $framework = $meta['scaffold']['framework'] ?? null;

        return is_string($framework) && strtolower($framework) === 'laravel';
    }
}
