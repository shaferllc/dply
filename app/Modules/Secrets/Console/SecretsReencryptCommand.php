<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Re-encrypt every encrypted value under the CURRENT APP_KEY (G3 rotation).
 *
 * Flow: deploy new APP_KEY + the old key in APP_PREVIOUS_KEYS everywhere (so
 * both decrypt during the transition), run this to re-save all encrypted data,
 * then drop the old key — gated by `--assert-complete`.
 *
 * Coverage = auto-discovered `encrypted`/`encrypted:*` casts on app/Models +
 * the explicit `raw_crypt` registry for encrypt()-helper columns. The
 * SecretReencryptCoverageTest fails if a new raw Crypt usage is added without
 * being registered, so this command never silently misses data.
 */
class SecretsReencryptCommand extends Command
{
    protected $signature = 'secrets:reencrypt
        {--dry-run : report what would change, write nothing}
        {--batch=200 : rows per chunk}
        {--model=* : limit to these model FQCNs}
        {--resume : skip targets already completed this run}
        {--show-plan : print discovered targets and exit}
        {--assert-complete : verify every row decrypts under the CURRENT key alone, then exit}';

    protected $description = 'Re-encrypt all secrets under the current APP_KEY (rotation).';

    public function handle(): int
    {
        if ($this->option('show-plan')) {
            return $this->showPlan();
        }

        if ($this->option('assert-complete')) {
            return $this->assertComplete();
        }

        if (empty(config('app.previous_keys'))) {
            $this->warn('APP_PREVIOUS_KEYS is empty — only data already on the current key can be re-saved. Continuing.');
        }

        $batch = max(1, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');
        $checkpoint = $this->loadCheckpoint();

        $castTargets = $this->castTargets();
        foreach ($castTargets as $class => $columns) {
            if ($this->option('resume') && in_array($class, $checkpoint, true)) {
                $this->line("skip (done): {$class}");

                continue;
            }
            $this->reencryptModel($class, $columns, $batch, $dryRun);
            if (! $dryRun) {
                $checkpoint[] = $class;
                $this->saveCheckpoint($checkpoint);
            }
        }

        foreach ($this->rawCryptTargets() as $target) {
            $tag = 'raw:'.$target['table'];
            if ($this->option('resume') && in_array($tag, $checkpoint, true)) {
                $this->line("skip (done): {$tag}");

                continue;
            }
            $this->reencryptRaw($target, $batch, $dryRun);
            if (! $dryRun) {
                $checkpoint[] = $tag;
                $this->saveCheckpoint($checkpoint);
            }
        }

        foreach ($this->jsonCryptTargets() as $target) {
            $tag = 'json:'.$target['table'].'.'.$target['column'];
            if ($this->option('resume') && in_array($tag, $checkpoint, true)) {
                $this->line("skip (done): {$tag}");

                continue;
            }
            $this->reencryptJson($target, $batch, $dryRun);
            if (! $dryRun) {
                $checkpoint[] = $tag;
                $this->saveCheckpoint($checkpoint);
            }
        }

        if (! $dryRun) {
            $this->clearCheckpoint();
        }

        $this->info($dryRun ? 'Dry run complete.' : 'Re-encryption complete. Run with --assert-complete before dropping the old key.');

        return self::SUCCESS;
    }

    // --- discovery --------------------------------------------------------

    /**
     * @return array<class-string<Model>, list<string>>
     */
    private function castTargets(): array
    {
        $only = (array) $this->option('model');
        $targets = [];

        $dir = (string) config('secret_vault.reencrypt.models_path');
        $files = glob(rtrim($dir, '/').'/*.php') ?: [];
        $classes = array_map(fn ($f) => 'App\\Models\\'.basename($f, '.php'), $files);
        $classes = array_merge($classes, (array) config('secret_vault.reencrypt.extra_models', []));
        $excluded = (array) config('secret_vault.reencrypt.exclude_models', []);

        foreach ($classes as $class) {
            if (in_array($class, $excluded, true)) {
                continue;
            }
            if ($only !== [] && ! in_array($class, $only, true)) {
                continue;
            }
            if (! class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(Model::class)) {
                continue;
            }
            try {
                /** @var Model $model */
                $model = new $class;
                $casts = $model->getCasts();
            } catch (\Throwable) {
                continue;
            }
            $cols = [];
            foreach ($casts as $col => $cast) {
                if ($cast === 'encrypted' || (is_string($cast) && str_starts_with($cast, 'encrypted:'))) {
                    $cols[] = $col;
                }
            }
            if ($cols !== []) {
                $targets[$class] = $cols;
            }
        }

        ksort($targets);

        return $targets;
    }

    /**
     * @return list<array{connection: ?string, table: string, key?: string, columns: list<string>}>
     */
    private function rawCryptTargets(): array
    {
        return array_values((array) config('secret_vault.reencrypt.raw_crypt', []));
    }

    /**
     * @return list<array{connection: ?string, table: string, key?: string, column: string, paths: list<string>}>
     */
    private function jsonCryptTargets(): array
    {
        return array_values((array) config('secret_vault.reencrypt.json_crypt', []));
    }

    // --- execution --------------------------------------------------------

    /**
     * @param  list<string>  $columns
     */
    private function reencryptModel(string $class, array $columns, int $batch, bool $dryRun): void
    {
        $changed = 0;
        $skipped = 0;

        $class::query()->orderBy((new $class)->getKeyName())
            ->chunkById($batch, function ($rows) use ($columns, $dryRun, &$changed, &$skipped): void {
                foreach ($rows as $row) {
                    $dirty = false;
                    foreach ($columns as $col) {
                        if ($row->getRawOriginal($col) === null) {
                            continue;
                        }
                        try {
                            $plain = $row->{$col}; // decrypts under current OR previous keys
                        } catch (DecryptException $e) {
                            $skipped++;
                            $this->warn("  undecryptable: {$row->getKey()}.{$col} — left as-is.");

                            continue;
                        }
                        $row->{$col} = $plain; // re-encrypts under the current key on set
                        $dirty = true;
                    }
                    if ($dirty && $row->isDirty()) {
                        $changed++;
                        if (! $dryRun) {
                            $row->saveQuietly();
                        }
                    }
                }
            });

        $verb = $dryRun ? 'would re-encrypt' : 're-encrypted';
        $this->line(sprintf('%s: %s %d row(s)%s', $class, $verb, $changed, $skipped > 0 ? " ({$skipped} skipped)" : ''));
    }

    /**
     * @param  array{connection: ?string, table: string, key?: string, columns: list<string>}  $target
     */
    private function reencryptRaw(array $target, int $batch, bool $dryRun): void
    {
        $conn = $target['connection'];
        $table = $target['table'];
        $key = $target['key'] ?? 'id';
        $columns = $target['columns'];
        $changed = 0;

        DB::connection($conn)->table($table)->orderBy($key)
            ->chunkById($batch, function ($rows) use ($conn, $table, $key, $columns, $dryRun, &$changed): void {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($columns as $col) {
                        $val = $row->{$col} ?? null;
                        if (! is_string($val) || $val === '') {
                            continue;
                        }
                        try {
                            $plain = Crypt::decryptString($val);
                        } catch (DecryptException) {
                            $this->warn("  undecryptable: {$table}#{$row->{$key}}.{$col} — left as-is.");

                            continue;
                        }
                        $updates[$col] = Crypt::encryptString($plain);
                    }
                    if ($updates !== []) {
                        $changed++;
                        if (! $dryRun) {
                            DB::connection($conn)->table($table)->where($key, $row->{$key})->update($updates);
                        }
                    }
                }
            }, $key);

        $verb = $dryRun ? 'would re-encrypt' : 're-encrypted';
        $this->line(sprintf('raw:%s: %s %d row(s)', $table, $verb, $changed));
    }

    /**
     * Re-encrypt secret values nested at dot-paths inside a plain JSON column.
     *
     * @param  array{connection: ?string, table: string, key?: string, column: string, paths: list<string>}  $target
     */
    private function reencryptJson(array $target, int $batch, bool $dryRun): void
    {
        $conn = $target['connection'];
        $table = $target['table'];
        $key = $target['key'] ?? 'id';
        $column = $target['column'];
        $paths = $target['paths'];
        $changed = 0;

        DB::connection($conn)->table($table)->orderBy($key)
            ->chunkById($batch, function ($rows) use ($conn, $table, $key, $column, $paths, $dryRun, &$changed): void {
                foreach ($rows as $row) {
                    $raw = $row->{$column} ?? null;
                    if (! is_string($raw) || $raw === '') {
                        continue;
                    }
                    $meta = json_decode($raw, true);
                    if (! is_array($meta)) {
                        continue;
                    }
                    $dirty = false;
                    foreach ($paths as $path) {
                        $val = data_get($meta, $path);
                        if (! is_string($val) || $val === '') {
                            continue;
                        }
                        try {
                            $plain = Crypt::decryptString($val);
                        } catch (DecryptException) {
                            $this->warn("  undecryptable: {$table}#{$row->{$key}} {$column}.{$path} — left as-is.");

                            continue;
                        }
                        data_set($meta, $path, Crypt::encryptString($plain));
                        $dirty = true;
                    }
                    if ($dirty) {
                        $changed++;
                        if (! $dryRun) {
                            DB::connection($conn)->table($table)->where($key, $row->{$key})
                                ->update([$column => json_encode($meta)]);
                        }
                    }
                }
            }, $key);

        $verb = $dryRun ? 'would re-encrypt' : 're-encrypted';
        $this->line(sprintf('json:%s.%s: %s %d row(s)', $table, $column, $verb, $changed));
    }

    // --- assert-complete --------------------------------------------------

    private function assertComplete(): int
    {
        // Rebind the encrypter to a CURRENT-KEY-ONLY instance so any value still
        // on an old key throws instead of silently decrypting via previous_keys.
        $currentOnly = new Encrypter($this->currentKeyBytes(), (string) config('app.cipher'));
        $this->laravel->instance('encrypter', $currentOnly);
        Crypt::clearResolvedInstances();

        $failures = 0;

        foreach ($this->castTargets() as $class => $columns) {
            $class::query()->orderBy((new $class)->getKeyName())
                ->chunkById(200, function ($rows) use ($columns, &$failures): void {
                    foreach ($rows as $row) {
                        foreach ($columns as $col) {
                            if ($row->getRawOriginal($col) === null) {
                                continue;
                            }
                            try {
                                $row->getAttribute($col);
                            } catch (DecryptException) {
                                $failures++;
                                $this->error("  not on current key: {$row->getKey()}.{$col}");
                            }
                        }
                    }
                });
        }

        foreach ($this->rawCryptTargets() as $target) {
            $key = $target['key'] ?? 'id';
            DB::connection($target['connection'])->table($target['table'])->orderBy($key)
                ->chunkById(200, function ($rows) use ($target, $key, &$failures): void {
                    foreach ($rows as $row) {
                        foreach ($target['columns'] as $col) {
                            $val = $row->{$col} ?? null;
                            if (! is_string($val) || $val === '') {
                                continue;
                            }
                            try {
                                Crypt::decryptString($val);
                            } catch (DecryptException) {
                                $failures++;
                                $this->error("  not on current key: {$target['table']}#{$row->{$key}}.{$col}");
                            }
                        }
                    }
                }, $key);
        }

        foreach ($this->jsonCryptTargets() as $target) {
            $key = $target['key'] ?? 'id';
            DB::connection($target['connection'])->table($target['table'])->orderBy($key)
                ->chunkById(200, function ($rows) use ($target, $key, &$failures): void {
                    foreach ($rows as $row) {
                        $raw = $row->{$target['column']} ?? null;
                        if (! is_string($raw) || $raw === '') {
                            continue;
                        }
                        $meta = json_decode($raw, true);
                        if (! is_array($meta)) {
                            continue;
                        }
                        foreach ($target['paths'] as $path) {
                            $val = data_get($meta, $path);
                            if (! is_string($val) || $val === '') {
                                continue;
                            }
                            try {
                                Crypt::decryptString($val);
                            } catch (DecryptException) {
                                $failures++;
                                $this->error("  not on current key: {$target['table']}#{$row->{$key}} {$target['column']}.{$path}");
                            }
                        }
                    }
                }, $key);
        }

        if ($failures > 0) {
            $this->error("{$failures} value(s) are NOT on the current key — do NOT drop APP_PREVIOUS_KEYS yet.");

            return self::FAILURE;
        }

        $this->info('All encrypted values decrypt under the current key alone. Safe to drop the old key.');

        return self::SUCCESS;
    }

    private function currentKeyBytes(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            return (string) base64_decode(substr($key, 7), true);
        }

        return $key;
    }

    private function showPlan(): int
    {
        $this->info('Cast-discovered targets:');
        foreach ($this->castTargets() as $class => $cols) {
            $this->line("  {$class}: ".implode(', ', $cols));
        }
        $this->info('Raw Crypt() column targets:');
        foreach ($this->rawCryptTargets() as $t) {
            $this->line("  {$t['table']}: ".implode(', ', $t['columns']));
        }
        $this->info('JSON-nested Crypt() targets:');
        foreach ($this->jsonCryptTargets() as $t) {
            $this->line("  {$t['table']}.{$t['column']}: ".implode(', ', $t['paths']));
        }

        return self::SUCCESS;
    }

    // --- checkpoint -------------------------------------------------------

    /**
     * @return list<string>
     */
    private function loadCheckpoint(): array
    {
        if (! $this->option('resume')) {
            return [];
        }
        $disk = Storage::disk((string) config('secret_vault.reencrypt.checkpoint_disk', 'local'));
        $path = (string) config('secret_vault.reencrypt.checkpoint_path');
        if (! $disk->exists($path)) {
            return [];
        }
        $data = json_decode((string) $disk->get($path), true);

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    /**
     * @param  list<string>  $done
     */
    private function saveCheckpoint(array $done): void
    {
        Storage::disk((string) config('secret_vault.reencrypt.checkpoint_disk', 'local'))
            ->put((string) config('secret_vault.reencrypt.checkpoint_path'), json_encode($done));
    }

    private function clearCheckpoint(): void
    {
        $disk = Storage::disk((string) config('secret_vault.reencrypt.checkpoint_disk', 'local'));
        $path = (string) config('secret_vault.reencrypt.checkpoint_path');
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
