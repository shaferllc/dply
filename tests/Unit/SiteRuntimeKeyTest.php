<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SiteType;
use App\Models\Site;
use Tests\TestCase;

class SiteRuntimeKeyTest extends TestCase
{
    public function test_returns_runtime_column_when_set(): void
    {
        $site = new Site(['runtime' => 'python']);

        $this->assertSame('python', $site->runtimeKey());
    }

    public function test_falls_back_to_type_enum_when_runtime_is_null(): void
    {
        $site = new Site;
        $site->type = SiteType::Php;

        $this->assertSame('php', $site->runtimeKey());
    }

    public function test_returns_null_when_neither_is_set(): void
    {
        $this->assertNull((new Site)->runtimeKey());
    }

    public function test_runtime_column_wins_over_type_when_both_present(): void
    {
        $site = new Site(['runtime' => 'ruby']);
        $site->type = SiteType::Php;

        $this->assertSame('ruby', $site->runtimeKey());
    }

    public function test_internal_port_is_fillable_and_round_trips(): void
    {
        $site = new Site(['internal_port' => 31234]);

        $this->assertSame(31234, $site->internal_port);
    }

    public function test_start_command_is_fillable_and_round_trips(): void
    {
        $site = new Site(['start_command' => 'gunicorn app:app --bind 0.0.0.0:8000']);

        $this->assertSame(
            'gunicorn app:app --bind 0.0.0.0:8000',
            $site->start_command,
        );
    }
}
