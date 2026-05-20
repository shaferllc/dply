<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Fetch recent OpenWhisk activation records for a serverless function so the
 * real runtime error is visible.
 *
 * DigitalOcean Functions returns a generic "error processing your request"
 * for any failure that happens before/around the action handler. The actual
 * exception, stdout/stderr, and timing live in the activation record — this
 * pulls them straight from the namespace API using the credentials dply
 * already stored on the host server.
 *
 * Example:
 *   php artisan serverless:logs laravel-demo
 *   php artisan serverless:logs 01ks1wxtrn4hqdn489mn9qn8d5 --limit=3
 */
class ServerlessActivationLogsCommand extends Command
{
    protected $signature = 'serverless:logs
                            {site : Function Site id or slug}
                            {--limit=5 : How many recent activations to show}
                            {--invoke : Invoke the public web URL first, then show its activation}
                            {--run : Invoke the action via the authenticated API (bypasses the web gateway) and print the activation}';

    protected $description = 'Show recent DigitalOcean Functions activation records (logs + result) for a serverless function.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $matches = Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orderByDesc('created_at')
            ->get();

        if ($matches->isEmpty()) {
            $this->error('No site found for "'.$needle.'".');

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->warn($matches->count().' sites match "'.$needle.'" — using the most recent. Pass an exact site id to pick another:');
            foreach ($matches as $candidate) {
                $this->line('  '.$candidate->id.'  '.$candidate->created_at?->toDateTimeString().'  ('.$candidate->status.')');
            }
        }

        $site = $matches->first();

        $server = $site->server;
        if (! $server instanceof Server || ! $server->isDigitalOceanFunctionsHost()) {
            $this->error('That site is not hosted on DigitalOcean Functions.');

            return self::FAILURE;
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $cfg = is_array($meta['digitalocean_functions'] ?? null) ? $meta['digitalocean_functions'] : [];
        $apiHost = rtrim((string) ($cfg['api_host'] ?? ''), '/');
        $accessKey = (string) ($cfg['access_key'] ?? '');

        if ($apiHost === '' || ! str_contains($accessKey, ':')) {
            $this->error('The host namespace is not provisioned yet (missing api_host / access_key).');

            return self::FAILURE;
        }

        [$keyId, $keySecret] = explode(':', $accessKey, 2);

        $actionUrl = $site->serverlessConfig()['action_url'] ?? null;

        $this->line('<fg=gray>site '.$site->id.' · server '.$server->id.'</>');
        $this->line('<fg=gray>namespace '.($cfg['namespace'] ?? '?').' · '.$apiHost.'</>');
        if (is_string($actionUrl)) {
            $this->line('<fg=gray>url '.$actionUrl.'</>');
        }

        // Inspect the deployed action doc — confirms web-export, runtime
        // kind, entry function, and code size are what dply pushed.
        $actionName = is_string($actionUrl) ? basename(rtrim($actionUrl, '/')) : null;
        if ($actionName !== null && $actionName !== '') {
            $this->newLine();
            $this->line('<fg=cyan>━━ action: '.$actionName.'</>');
            $doc = Http::withBasicAuth($keyId, $keySecret)
                ->acceptJson()
                ->timeout(30)
                ->get($apiHost.'/api/v1/namespaces/_/actions/'.rawurlencode($actionName));

            if (! $doc->successful()) {
                $this->error('  action lookup failed: HTTP '.$doc->status().' '.$doc->body());
            } else {
                $action = (array) $doc->json();
                $exec = (array) data_get($action, 'exec', []);
                $code = (string) ($exec['code'] ?? '');
                $this->line('  kind:        '.($exec['kind'] ?? '?'));
                $this->line('  binary:      '.json_encode($exec['binary'] ?? null));
                $this->line('  main:        '.($exec['main'] ?? '(default)'));
                $this->line('  code size:   '.number_format(strlen($code)).' base64 chars (~'.number_format((int) (strlen($code) * 0.75 / 1024)).' KB)');
                $this->line('  limits:      '.json_encode(data_get($action, 'limits')));
                $this->line('  annotations: '.json_encode(data_get($action, 'annotations')));
                $this->line('  published:   '.json_encode(data_get($action, 'publish')));
            }
        }

        // Invoke the action through the authenticated management API. This
        // bypasses the public web gateway entirely and a blocking call
        // returns the activation inline — so even when the web URL 400s
        // before scheduling anything, this surfaces the real result + logs.
        if ($this->option('run') && $actionName !== null && $actionName !== '') {
            $this->newLine();
            $this->line('Invoking via the authenticated API (blocking)…');
            $run = Http::withBasicAuth($keyId, $keySecret)
                ->acceptJson()
                ->timeout(75)
                ->post($apiHost.'/api/v1/namespaces/_/actions/'.rawurlencode($actionName).'?blocking=true&result=false', [
                    '__ow_method' => 'get',
                    '__ow_path' => '',
                    '__ow_headers' => ['accept' => 'application/json'],
                    '__ow_query' => '',
                ]);
            $this->line('  HTTP '.$run->status());
            $this->line(json_encode($run->json() ?? $run->body(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
        }

        if ($this->option('invoke')) {
            if (! is_string($actionUrl) || $actionUrl === '') {
                $this->error('No invocation URL on this site yet — it has not finished deploying.');

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('Invoking the function…');
            $invocation = Http::timeout(70)->get($actionUrl);
            $this->line('  HTTP '.$invocation->status());
            $this->line('  '.trim($invocation->body()));

            // Give the activation a moment to register before we query it.
            sleep(2);
        }

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->acceptJson()
            ->timeout(30)
            ->get($apiHost.'/api/v1/namespaces/_/activations', [
                'limit' => (int) $this->option('limit'),
                'docs' => 'true',
            ]);

        if (! $response->successful()) {
            $this->error('Activations API returned HTTP '.$response->status().': '.$response->body());

            return self::FAILURE;
        }

        $activations = $response->json();
        if (! is_array($activations) || $activations === []) {
            $this->info('No activations recorded yet for this namespace — the function has not been invoked.');

            return self::SUCCESS;
        }

        foreach ($activations as $activation) {
            if (! is_array($activation)) {
                continue;
            }

            $status = data_get($activation, 'response.status', 'unknown');
            $this->newLine();
            $this->line('<fg=cyan>━━ '.data_get($activation, 'name', '?').'  ('.data_get($activation, 'activationId', '?').')</>');
            $this->line('  status:   '.$status);
            $this->line('  duration: '.data_get($activation, 'duration', '?').'ms');

            $result = data_get($activation, 'response.result');
            if ($result !== null) {
                $this->line('  result:   '.json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $logs = data_get($activation, 'logs', []);
            if (is_array($logs) && $logs !== []) {
                $this->line('  logs:');
                foreach ($logs as $log) {
                    $this->line('    '.$log);
                }
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
