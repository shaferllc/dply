<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Providers\Cloudflare\CloudflareDnsService;
use App\Support\TestingDomains;
use Illuminate\Console\Command;

/**
 * Check the platform Cloudflare token that testing hostnames depend on.
 *
 *   dply:doctor:cloudflare
 *   dply:doctor:cloudflare on-dply.cc
 *   dply:doctor:cloudflare --json
 *
 * Answers the four questions a "Zone [x] was not found" failure raises, in
 * order, so the answer is never a guess:
 *
 *   1. Is a token configured, and is it the one I think it is?
 *      (fingerprint + last 4 — compare against the Cloudflare dashboard)
 *   2. Does it authenticate at all?
 *   3. What zones can it actually see?
 *   4. Is the zone we need among them?
 *
 * The distinction that matters most is (2) vs (3). A token can authenticate
 * perfectly and still see ZERO zones — that is not an auth problem, it is an
 * empty Zone Resources list, and the fix is in a different part of the
 * Cloudflare UI than people go looking.
 *
 * Read-only. Exits 1 when the zone is not reachable.
 */
class DoctorCloudflareCommand extends Command
{
    protected $signature = 'dply:doctor:cloudflare
        {zone? : Zone to check (defaults to the VM testing apex)}
        {--raw : Print Cloudflare\'s own response, incl. the token id}
        {--json : Output as JSON}';

    protected $description = 'Check the platform Cloudflare token used for testing hostnames.';

    public function handle(): int
    {
        $zone = strtolower(trim((string) ($this->argument('zone') ?? ''))) ?: TestingDomains::vmApex();
        $token = TestingDomains::cloudflareApiToken();

        $report = [
            'zone' => $zone,
            'token_configured' => $token !== '',
            'token' => $token === '' ? null : [
                'origin' => TestingDomains::describeCloudflareToken($token),
                'fingerprint' => 'sha256:'.substr(hash('sha256', $token), 0, 8),
                'last4' => substr($token, -4),
                'auth_scheme' => (new CloudflareDnsService($token))->authScheme(),
            ],
            'authenticates' => null,
            'zones_visible' => null,
            'zone_reachable' => null,
            'error' => null,
        ];

        if ($token !== '') {
            $service = new CloudflareDnsService($token);

            try {
                $service->verifyToken();
                $report['authenticates'] = true;
            } catch (\Throwable $e) {
                $report['authenticates'] = false;
                $report['error'] = $e->getMessage();
            }

            if ($report['authenticates'] === true) {
                try {
                    $report['zones_visible'] = $service->listZoneNames();
                    $report['zone_reachable'] = $service->zoneExists($zone);
                } catch (\Throwable $e) {
                    $report['error'] = $e->getMessage();
                }
            }
        }

        if ($this->option('raw') && $token !== '') {
            $raw = (new CloudflareDnsService($token))->rawDiagnostics();
            $report['raw'] = $raw;

            if (! $this->option('json')) {
                $this->components->info('Raw Cloudflare response');
                $this->components->twoColumnDetail('token id', (string) ($raw['token_id'] ?? '—'));
                $this->components->twoColumnDetail('token status', (string) ($raw['token_status'] ?? '—'));
                if ($raw['verify_error'] !== null) {
                    $this->components->twoColumnDetail('verify note', (string) $raw['verify_error']);
                }
                $this->components->twoColumnDetail('GET /zones status', (string) ($raw['zones_status'] ?? '—'));
                $this->line((string) json_encode($raw['zones_body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->newLine();
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['zone_reachable'] === true ? self::SUCCESS : self::FAILURE;
        }

        return $this->render($report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): int
    {
        $this->components->info('Cloudflare check for zone ['.$report['zone'].']');

        if ($report['token_configured'] !== true) {
            $this->components->error('No Cloudflare token configured.');
            $this->components->info('Set CLOUDFLARE_DNS_API_TOKEN, then: php artisan config:clear');

            return self::FAILURE;
        }

        $t = $report['token'];
        $this->components->twoColumnDetail('token', (string) $t['origin']);
        $this->components->twoColumnDetail('fingerprint', $t['fingerprint'].'  …'.$t['last4']);
        $this->components->twoColumnDetail(
            'auth scheme',
            $t['auth_scheme'] === 'global-api-key'
                ? 'Global API Key (X-Auth-Email + X-Auth-Key)'
                : 'API token (Bearer)',
        );
        $this->components->twoColumnDetail(
            'authenticates',
            $report['authenticates'] === true ? '<fg=green>yes</>' : '<fg=red>NO</>',
        );

        if ($report['authenticates'] !== true) {
            $this->newLine();
            $this->components->error('The token was rejected by Cloudflare: '.(string) $report['error']);
            $this->components->info('The token is wrong, revoked, or truncated. Re-issue it and set CLOUDFLARE_DNS_API_TOKEN.');

            return self::FAILURE;
        }

        // The credential collision this whole class of failure comes from: if
        // DNS and mail resolve to the SAME value, CLOUDFLARE_KEY is still doing
        // both jobs and the Email Sending credential is being sent to the DNS
        // API, where it authenticates as nobody and lists no zones.
        $mailKey = trim((string) config('mail.mailers.cloudflare.key', ''));
        if ($mailKey !== '' && hash_equals($mailKey, TestingDomains::cloudflareApiToken())) {
            $this->newLine();
            $this->components->warn(
                'DNS and MAIL are using the SAME Cloudflare credential. An Email Sending key is not a '
                .'DNS token — it authenticates but sees no zones. Split them in .env: '
                .'CLOUDFLARE_DNS_API_TOKEN for DNS, CLOUDFLARE_MAIL_KEY for mail, then remove the shared CLOUDFLARE_KEY.'
            );
        }

        $zones = is_array($report['zones_visible']) ? $report['zones_visible'] : [];
        $this->components->twoColumnDetail('zones visible', $zones === [] ? '<fg=red>0</>' : (string) count($zones));

        if ($zones !== []) {
            foreach (array_slice($zones, 0, 15) as $z) {
                $this->line('    '.($z === $report['zone'] ? '<fg=green>'.$z.'  ← the one we need</>' : $z));
            }
            if (count($zones) > 15) {
                $this->line('    … +'.(count($zones) - 15).' more');
            }
        }

        $this->newLine();

        if ($report['zone_reachable'] === true) {
            $this->components->info('Zone ['.$report['zone'].'] is reachable. Testing hostnames should work.');

            return self::SUCCESS;
        }

        // Authenticating with zero zones is the confusing case: the credential
        // is valid, so nothing looks "broken", but it was issued with an empty
        // Zone Resources list (or for the wrong account entirely).
        if ($zones === []) {
            $this->components->error('The token authenticates but can see NO zones at all.');
            $this->components->info(
                'That is a Zone Resources problem, not an auth problem. In the Cloudflare dashboard: '
                .'My Profile → API Tokens → edit this token → Zone Resources → Include → '
                .'Specific zone → '.$report['zone'].'. Confirm you are in the account that OWNS that zone — '
                .'a token issued in the wrong account authenticates fine and sees nothing.'
            );

            return self::FAILURE;
        }

        $this->components->error('Zone ['.$report['zone'].'] is NOT among the zones this token can see.');
        $this->components->info(
            'The token belongs to an account that does not own '.$report['zone'].', '
            .'or that zone is missing from its Zone Resources. The list above is which account you are actually on.'
        );

        return self::FAILURE;
    }
}
