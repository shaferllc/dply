<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\RunSiteFixerJob;
use App\Jobs\TestSiteHealthJob;
use App\Models\Site;
use App\Models\SiteSecretResidency;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SecretEscalator;
use App\Support\Sites\EnvImportSources;
use App\Support\Sites\SiteFixers;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteEnvImportFix
{


    /**
     * Seed this site's .env from another site. $verbatim distinguishes the two
     * intents:
     *  - verbatim (a worker / replica of THE SAME app — shares APP_KEY, DB, Redis):
     *    copy as-is so the boxes stay in lockstep. Auto-defaulted for pool workers.
     *  - sanitized (a DIFFERENT app used as a template): blank secret / host-bound
     *    values for the operator to fill and regenerate APP_KEY (see EnvImportSources).
     * Keys this site already has set always win, so an import never clobbers work.
     */
    public function importEnvFromSite(string $sourceSiteId, bool $verbatim, DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        if ($this->blockedForDerivedWorker()) {
            return;
        }

        $source = $this->resolveImportSource($sourceSiteId);
        if (! $source instanceof Site) {
            return;
        }

        $incoming = $parser->parse((string) $source->env_file_content);
        $vars = $verbatim
            ? $incoming['variables']
            : EnvImportSources::sanitize($incoming['variables']);

        $existing = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $merged = array_merge($vars, $existing['variables']);
        $comments = array_merge($incoming['comments'], $existing['comments']);

        $this->site->forceFill([
            'env_file_content' => $writer->render($merged, $comments),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.imported_from_site', $this->site, null, [
                'source_site_id' => $source->id,
                'verbatim' => $verbatim,
                'imported_keys' => array_keys($vars),
            ]);
        }

        $this->dispatch('close-modal', name: 'env-import-modal');
        $message = $verbatim
            ? __('Imported .env from :name verbatim (same APP_KEY + backend — they stay in lockstep).', ['name' => $source->name])
            : __('Imported .env from :name — :n secret(s) blanked to fill in, APP_KEY regenerated.', ['name' => $source->name, 'n' => count(array_filter($vars, fn ($v) => $v === ''))]);
        $this->autoPushAfterCacheMutation($message);
    }

    /**
     * Import a SINGLE variable's value from another site/server (e.g. pull
     * REVERB_APP_KEY from a worker so the app and worker match). Copies the value
     * verbatim — a single-key pull is always an explicit, intentional copy.
     */
    public function importEnvKeyFromSite(string $key, string $sourceSiteId, DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);

        $source = $this->resolveImportSource($sourceSiteId);
        if (! $source instanceof Site) {
            return;
        }

        $value = (string) ($parser->parse((string) $source->env_file_content)['variables'][$key] ?? '');
        if (trim($value) === '') {
            $this->toastError(__(':name has no :key value to import.', ['name' => $source->name, 'key' => $key]));

            return;
        }

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $parsed['variables'][$key] = $value;

        $this->site->forceFill([
            'env_file_content' => $writer->render($parsed['variables'], $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $this->env_import_key = null;
        $this->autoPushAfterCacheMutation(__(':key imported from :name.', ['key' => $key, 'name' => $source->name]));
    }

    /**
     * Sites (in the operator's org, other than this one) that have a NON-EMPTY
     * value for $key — the candidate sources for a per-variable import. Grouped
     * like the whole-env picker (workers / same-repo / org).
     *
     * @return array<int, array<string, mixed>>
     */
    public function envKeySources(string $key): array
    {
        if (trim($key) === '') {
            return [];
        }

        $parser = app(DotEnvFileParser::class);
        $groups = EnvImportSources::candidatesFor($this->site);
        $all = collect($groups['workers'])->merge($groups['same_repo'])->merge($groups['org'])
            ->unique('id')->values();

        return $all->map(function (array $c) use ($key, $parser): ?array {
            $src = Site::query()->whereKey($c['id'])->value('env_file_content');
            $val = trim((string) ($parser->parse((string) $src)['variables'][$key] ?? ''));
            if ($val === '') {
                return null;
            }

            return $c + ['masked' => EnvImportSources::isSecretKey($key) ? str_repeat('•', 6) : Str::limit($val, 40)];
        })->filter()->values()->all();
    }

    /**
     * Look up an import source within the operator's org with a usable .env.
     */
    private function resolveImportSource(string $sourceSiteId): ?Site
    {
        $source = Site::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($sourceSiteId)
            ->first();

        if (! $source instanceof Site || trim((string) $source->env_file_content) === '') {
            $this->toastError(__('That site has no .env to import.'));

            return null;
        }

        return $source;
    }

    /**
     * Candidate sites this site can seed its .env from, grouped (pool workers /
     * same-repo / org). Drives the import picker.
     *
     * @return array{workers: array<int, array<string, mixed>>, same_repo: array<int, array<string, mixed>>, org: array<int, array<string, mixed>>}
     */
    public function envImportCandidates(): array
    {
        return EnvImportSources::candidatesFor($this->site);
    }

    /**
     * True when this site has no .env yet AND has never deployed — the moment to
     * prompt "set up your .env" (with import options) before the first deploy.
     */
    public function needsFirstEnv(): bool
    {
        return trim((string) ($this->site->env_file_content ?? '')) === ''
            && $this->site->env_cache_origin === null
            && $this->site->latestDeployment() === null;
    }

    /**
     * Open the single-variable "Fix" modal for a key flagged by the config
     * check. Pre-fills the input with the current value (creating the row if
     * it doesn't exist yet) so the operator can correct it in place — works
     * for ANY key, not just the ones with a known suggested fix.
     */
    public function openFixEnvVar(string $key, DotEnvFileParser $parser): void
    {
        $this->authorize('update', $this->site);

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));

        $this->fixing_env_key = $key;
        $this->fixing_env_value = (string) ($parsed['variables'][$key] ?? '');
        $this->resetErrorBag('fixing_env_value');

        $this->dispatch('open-modal', 'fix-env-var-modal');
    }

    public function cancelFixEnvVar(): void
    {
        $this->fixing_env_key = null;
        $this->fixing_env_value = '';
    }

    /**
     * Drop the suggested fix into the input (the modal's "Use suggested"
     * button). For APP_KEY this mints a fresh key; for the boolean/enum keys
     * it's the safe-in-production value.
     */
    public function applySuggestedEnvFix(): void
    {
        $key = strtoupper(trim((string) $this->fixing_env_key));
        $this->fixing_env_value = match ($key) {
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
            'SESSION_SECURE_COOKIE' => 'true',
            'APP_KEY' => $this->freshAppKey(),
            'APP_URL' => str_starts_with(strtolower($this->fixing_env_value), 'http://')
                ? 'https://'.substr($this->fixing_env_value, 7)
                : $this->fixing_env_value,
            default => $this->fixing_env_value,
        };
    }

    /**
     * Human-readable label for the "Use suggested" button, or null when we
     * have no opinion on the right value (e.g. DB_PASSWORD — only the operator
     * knows it). Deterministic so it's safe to call on every render.
     */
    public function envFixSuggestionLabel(string $key, string $current): ?string
    {
        return match (strtoupper(trim($key))) {
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
            'SESSION_SECURE_COOKIE' => 'true',
            'APP_KEY' => __('Generate a fresh key'),
            'APP_URL' => str_starts_with(strtolower($current), 'http://')
                ? 'https://'.substr($current, 7)
                : null,
            default => null,
        };
    }

    /**
     * Write the single fixed key back into the cache and auto-push. Mirrors
     * {@see saveEditedEnvVar()} but scoped to the modal's one key.
     */
    public function saveFixedEnvVar(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);

        $key = trim((string) $this->fixing_env_key);
        if ($key === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            return;
        }

        $this->validate([
            'fixing_env_value' => 'nullable|string|max:20000',
        ]);

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $map = $parsed['variables'];
        $map[$key] = (string) $this->fixing_env_value;

        $this->site->forceFill([
            'env_file_content' => $writer->render($map, $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.var_updated', $this->site, null, [
                'key' => $key,
            ]);
        }

        $this->cancelFixEnvVar();
        $this->dispatch('close-modal', 'fix-env-var-modal');
        $this->autoPushAfterCacheMutation(__(':key updated.', ['key' => $key]));
    }

    /**
     * The site's escrowed/external secrets keyed by env var name, for the view:
     * [KEY => ['mode' => escrow|external, 'placeholder' => '${dply:secret:…}',
     *          'can_reveal' => bool]]. can_reveal is true only for escrow under a
     * dply-held org key (dply can decrypt); customer-held / external cannot be
     * revealed here.
     *
     * @return array<string, array{mode: string, placeholder: string, can_reveal: bool}>
     */
    public function secretResidencyMap(): array
    {
        $map = [];
        foreach ($this->site->secretResidencies()->get() as $residency) {
            /** @var SiteSecretResidency $residency */
            $canReveal = false;
            if ($residency->mode === SiteSecretResidency::MODE_ESCROW) {
                $orgKey = $this->site->organization?->secretKey;
                $canReveal = (bool) $orgKey?->dplyCanDecrypt();
            }

            $map[$residency->key] = [
                'mode' => $residency->mode,
                'placeholder' => $residency->placeholder(),
                'can_reveal' => $canReveal,
            ];
        }

        return $map;
    }

    /**
     * Move an env var off the plaintext-in-DB blob into the org's encryption key
     * (Tier 2a/2b). The value becomes an age blob; the loose .env keeps only a
     * placeholder. Pushes so the server still receives the real value.
     */
    public function escalateEnvVar(string $key): void
    {
        $this->authorize('update', $this->site);
        if ($this->blockedForDerivedWorker()) {
            return;
        }

        try {
            app(SecretEscalator::class)->escalate($this->site, $key);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site->refresh();
        $this->autoPushAfterCacheMutation(__(':key moved to the organization key.', ['key' => $key]));
    }

    /** Pull an escrowed secret back into the editable .env (dply-held only here). */
    public function demoteEnvVar(string $key): void
    {
        $this->authorize('update', $this->site);
        if ($this->blockedForDerivedWorker()) {
            return;
        }

        $residency = $this->site->secretResidencies()->where('key', $key)->first();
        if ($residency === null) {
            $this->toastError(__(':key is not escrowed.', ['key' => $key]));

            return;
        }

        try {
            app(SecretEscalator::class)->demote($this->site, $residency);
        } catch (\Throwable $e) {
            // Customer-held keys need the customer's identity — not available here.
            $this->toastError($e->getMessage());

            return;
        }

        unset($this->revealed_escrow_values[$key]);
        $this->site->refresh();
        $this->autoPushAfterCacheMutation(__(':key moved back into the editable environment.', ['key' => $key]));
    }

    /** Reveal an escrowed secret's plaintext (dply-held org key only). */
    public function revealEscrowedEnvVar(string $key): void
    {
        $this->authorize('update', $this->site);

        if (array_key_exists($key, $this->revealed_escrow_values)) {
            unset($this->revealed_escrow_values[$key]);

            return;
        }

        $residency = $this->site->secretResidencies()->where('key', $key)->first();
        if ($residency === null) {
            return;
        }

        try {
            $this->revealed_escrow_values[$key] = app(SecretEscalator::class)->reveal($residency);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * "Test site" — end-to-end check that the app actually loads with the
     * current environment (HTTP request + server-log tail on failure). Per-key
     * checks can pass while the app still 500s, so this exercises the real URL.
     */
    public function testSiteLoads(): void
    {
        $this->authorize('view', $this->site);

        $run = $this->seedQueuedConsoleAction('site_test', __('Testing the site'));

        TestSiteHealthJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('The site loaded successfully.'),
            __('The site did not load — see the error below.'),
        );
        $this->toastConsoleActionQueued();
    }

    /**
     * Run a whitelisted artisan remediation (Run migrations, Clear config
     * cache, …) on the server — surfaced as one-click buttons when "Test site"
     * recognises a known failure (e.g. a missing table → migrate).
     */
    public function runRemediation(string $key): void
    {
        $this->authorize('update', $this->site);

        $spec = SiteFixers::spec($key);
        if ($spec === null) {
            // Don't fail silently — the remediation list can outlive the fixer
            // registry (e.g. a renamed key after a re-test). Tell the operator.
            $this->toastWarning(__('That fix is no longer available — re-run “Test site” to refresh the suggestions.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('site_remediate', (string) $spec['label']);
        RunSiteFixerJob::dispatch((string) $run->id, (string) $this->site->id, $key);

        // Stream the run live into the global SSH console drawer and pop it open
        // so the operator can watch the fix execute (these are queued jobs — an
        // apt/pecl install easily outlasts the inline console's 60s cap).
        $this->dispatch('console-action-to-drawer', actionId: (string) $run->id)
            ->to(\App\Livewire\Servers\ConsoleDrawer::class);
        $this->dispatch('dply-open-console-drawer');

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            $spec['label'].' completed.',
            $spec['label'].' did not finish — see the output.',
        );
        $this->toastConsoleActionQueued();
    }
}
