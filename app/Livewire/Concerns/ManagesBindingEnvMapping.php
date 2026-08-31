<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\SiteBinding;
use App\Modules\Deploy\Services\SiteBindingManager;
use App\Services\Sites\DotEnvFileParser;

/**
 * The "Environment mapping" editor for one resource binding: rename-by-adding
 * (aliases) and per-key value overrides.
 *
 * Why aliases exist: dply injects Laravel's names (DB_HOST, DATABASE_URL), but
 * plenty of stacks read the same connection under a different one — Payload
 * wants DATABASE_URI, Neon/Vercel want POSTGRES_URL, psql-based tooling wants
 * PGHOST/PGUSER/… . Detection covers one hardcoded case; this makes the rest
 * the operator's call, and makes the detected case visible and editable.
 *
 * Aliases ADD, never replace: the canonical key still ships, so nothing that
 * already reads it breaks. A name that is already taken is refused here rather
 * than silently losing to the existing key at push time.
 *
 * Overrides are the per-key escape hatch the UI has always advertised and
 * never actually had — bindings compose OVER the editable .env, so a .env key
 * of the same name never won. An override lives inside the binding, i.e. in
 * the layer that wins.
 *
 * Both live in the encrypted `env_customization` column: an override can be a
 * password, and a separate column survives the attach path rebuilding `config`
 * wholesale.
 */
trait ManagesBindingEnvMapping
{
    /** Same shape every other env key in the app is validated against. */
    private const ENV_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** Binding whose mapping editor is open; null = closed. */
    public ?string $envMappingBindingId = null;

    /**
     * Alias names being edited, keyed by canonical key. Held as a single
     * comma-separated string per row so the form stays flat for Livewire.
     *
     * @var array<string, string>
     */
    public array $envMappingAliases = [];

    /**
     * Override values being edited, keyed by canonical key. A row absent here
     * (or blank) means "no override" — the generated value stands.
     *
     * @var array<string, string>
     */
    public array $envMappingOverrides = [];

    /**
     * Rows to render: canonical key => current value ('' when the binding is
     * still provisioning). Rebuilt on open so the editor never renders against
     * a stale snapshot.
     *
     * @var array<string, string>
     */
    public array $envMappingRows = [];

    /** Keys in {@see $envMappingRows} whose value must be masked. */
    public array $envMappingSensitive = [];

    /** True when the binding has no values yet (provisioning) — rows are placeholders. */
    public bool $envMappingPending = false;

    public function openEnvMapping(string $bindingId): void
    {
        $this->authorize('update', $this->site);

        $binding = $this->ownedBinding($bindingId);
        if (! $binding instanceof SiteBinding) {
            return;
        }

        $current = $binding->connectionEnv();
        $keys = app(SiteBindingManager::class)->expectedEnvKeys($binding);

        $rows = [];
        foreach ($keys as $key) {
            $rows[$key] = (string) ($current[$key] ?? '');
        }
        ksort($rows);

        $this->envMappingBindingId = (string) $binding->id;
        $this->envMappingRows = $rows;
        $this->envMappingSensitive = $binding->sensitiveEnvKeys();
        $this->envMappingPending = $current === [];

        $this->envMappingAliases = [];
        foreach ($binding->envAliases() as $canonical => $names) {
            $this->envMappingAliases[(string) $canonical] = implode(', ', $names);
        }

        $this->envMappingOverrides = $binding->envOverrides();

        $this->resetErrorBag(['envMappingAliases', 'envMappingOverrides']);
        $this->dispatch('open-modal', 'binding-env-mapping-modal');
    }

    public function closeEnvMapping(): void
    {
        $this->envMappingBindingId = null;
        $this->envMappingRows = [];
        $this->envMappingAliases = [];
        $this->envMappingOverrides = [];
        $this->envMappingSensitive = [];
        $this->envMappingPending = false;
        $this->dispatch('close-modal', 'binding-env-mapping-modal');
    }

    public function saveEnvMapping(): void
    {
        $this->authorize('update', $this->site);

        $binding = $this->ownedBinding((string) $this->envMappingBindingId);
        if (! $binding instanceof SiteBinding) {
            return;
        }

        $this->resetErrorBag(['envMappingAliases', 'envMappingOverrides']);

        [$aliases, $ok] = $this->validatedEnvMappingAliases($binding);
        if (! $ok) {
            return;
        }

        $overrides = [];
        foreach ($this->envMappingOverrides as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;
            // Only keys this binding owns, and only non-blank values — clearing
            // the field is how you remove an override, not how you set an empty
            // one (an empty DB_HOST would trip the .env danger gate anyway).
            if ($value !== '' && array_key_exists($key, $this->envMappingRows)) {
                $overrides[$key] = $value;
            }
        }

        $customization = is_array($binding->env_customization) ? $binding->env_customization : [];
        // Always write `aliases`, even when empty: an empty map is "the operator
        // cleared it", which stack detection must not re-seed on the next
        // attach. See SiteBinding::hasEnvAliasMap().
        $customization['aliases'] = $aliases;
        $customization['overrides'] = $overrides;

        $config = is_array($binding->config) ? $binding->config : [];
        // Same stamp every other binding change uses to raise the
        // "redeploy to apply" prompt. Deliberately no automatic push: an
        // override repoints a live app, and "Push to server" is one click away
        // in this panel's own menu.
        $config['connection_ready_at'] = now()->toIso8601String();

        $binding->forceFill([
            'env_customization' => $customization,
            'config' => $config,
        ])->save();

        $this->site->load('bindings');
        $this->closeEnvMapping();
        $this->toastSuccess(__('Environment mapping saved. Redeploy to apply.'));
    }

    /**
     * Parse and validate the alias rows.
     *
     * Refuses a name that is already taken — by this binding, by another
     * binding on the site, or by the site's editable .env. All three would
     * otherwise be silent no-ops at push time, because an alias never
     * overwrites an existing key.
     *
     * @return array{0: array<string, list<string>>, 1: bool}
     */
    private function validatedEnvMappingAliases(SiteBinding $binding): array
    {
        $taken = $this->envMappingTakenNames($binding);

        $aliases = [];
        $seen = [];
        $ok = true;

        foreach ($this->envMappingAliases as $canonical => $raw) {
            $canonical = (string) $canonical;
            if (! array_key_exists($canonical, $this->envMappingRows)) {
                continue;
            }

            $names = [];
            foreach (preg_split('/[\s,]+/', (string) $raw) ?: [] as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }

                $field = 'envMappingAliases.'.$canonical;

                if (strlen($name) > 128 || preg_match(self::ENV_KEY_PATTERN, $name) !== 1) {
                    $this->addError($field, __(':name is not a valid variable name (letters, numbers and underscores; cannot start with a number).', ['name' => $name]));
                    $ok = false;

                    continue;
                }
                if (isset($seen[$name])) {
                    $this->addError($field, __(':name is already used as an alias for :other.', ['name' => $name, 'other' => $seen[$name]]));
                    $ok = false;

                    continue;
                }
                if (isset($taken[$name])) {
                    $this->addError($field, __(':name is already provided by :source, so the alias would never apply.', ['name' => $name, 'source' => $taken[$name]]));
                    $ok = false;

                    continue;
                }

                $seen[$name] = $canonical;
                $names[] = $name;
            }

            if ($names !== []) {
                $aliases[$canonical] = $names;
            }
        }

        return [$aliases, $ok];
    }

    /**
     * Names an alias cannot claim, mapped to a human description of what holds
     * them. Excludes the binding's own aliases (those are being replaced).
     *
     * @return array<string, string>
     */
    private function envMappingTakenNames(SiteBinding $binding): array
    {
        $taken = [];

        foreach (array_keys($this->envMappingRows) as $key) {
            $taken[(string) $key] = __('this resource');
        }

        $this->site->loadMissing('bindings');
        foreach ($this->site->bindings as $other) {
            if ((string) $other->id === (string) $binding->id) {
                continue;
            }
            foreach (array_keys($other->connectionEnv()) as $key) {
                $taken[(string) $key] = trim($other->type.' '.(string) $other->name);
            }
        }

        $parsed = app(DotEnvFileParser::class)->parse((string) ($this->site->env_file_content ?? ''));
        foreach (array_keys($parsed['variables']) as $key) {
            $taken[(string) $key] ??= __('a variable on this site');
        }

        return $taken;
    }

    private function ownedBinding(string $bindingId): ?SiteBinding
    {
        if (trim($bindingId) === '') {
            return null;
        }

        return $this->site->bindings()->whereKey($bindingId)->first();
    }
}
