<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CliSearchCommandTest extends TestCase
{
    public function test_finds_commands_by_name_keyword(): void
    {
        Artisan::call('dply:cli-search', [
            'keyword' => 'env',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $names = array_column($decoded['matches'], 'name');
        $this->assertContains('dply:site:env-set', $names);
        $this->assertContains('dply:site:env-list', $names);
    }

    public function test_finds_by_description_match(): void
    {
        // dply:fleet:running-deploys has "in-progress" in its description but
        // not in its name.
        Artisan::call('dply:cli-search', [
            'keyword' => 'in-progress',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $names = array_column($decoded['matches'], 'name');
        $this->assertContains('dply:fleet:running-deploys', $names);
    }

    public function test_names_only_skips_description_matches(): void
    {
        // First: search for "in-progress" — matches a description.
        Artisan::call('dply:cli-search', [
            'keyword' => 'in-progress',
            '--json' => true,
        ]);
        $decodedAll = json_decode(Artisan::output(), true);

        // With --names-only, no command name contains "in-progress".
        Artisan::call('dply:cli-search', [
            'keyword' => 'in-progress',
            '--names-only' => true,
            '--json' => true,
        ]);
        $decodedNames = json_decode(Artisan::output(), true);

        $this->assertGreaterThan(0, $decodedAll['count']);
        $this->assertSame(0, $decodedNames['count']);
    }

    public function test_alternation_regex_works(): void
    {
        Artisan::call('dply:cli-search', [
            'keyword' => 'rename|set-runtime',
            '--names-only' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $names = array_column($decoded['matches'], 'name');
        $this->assertContains('dply:site:rename', $names);
        $this->assertContains('dply:server:rename', $names);
        $this->assertContains('dply:site:set-runtime', $names);
    }

    public function test_no_matches_returns_failure(): void
    {
        $exit = Artisan::call('dply:cli-search', [
            'keyword' => 'this-cannot-possibly-match-zzqzz',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No dply commands match', $output);
    }

    public function test_only_dply_namespaced_commands_appear(): void
    {
        // "list" is a Laravel built-in — but our search is restricted to dply:*.
        Artisan::call('dply:cli-search', [
            'keyword' => 'list',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        foreach ($decoded['matches'] as $m) {
            $this->assertStringStartsWith('dply:', $m['name']);
        }
    }

    public function test_empty_keyword_is_rejected(): void
    {
        $exit = Artisan::call('dply:cli-search', ['keyword' => '']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cannot be empty', $output);
    }
}
