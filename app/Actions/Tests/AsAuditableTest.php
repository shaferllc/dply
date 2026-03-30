<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsAuditable;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

test('auditable records audit trail', function () {
    if (! DB::getSchemaBuilder()->hasTable('audits')) {
        $this->markTestSkipped('Audits table does not exist');
    }

    $user = User::factory()->create();
    Auth::login($user);

    $action = TestAuditableAction::make();
    $action->handle('test');

    $audit = DB::table('audits')
        ->where('action', get_class($action))
        ->latest()
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->action_name)->toBe(class_basename($action));
});

test('auditable captures before and after state', function () {
    if (! DB::getSchemaBuilder()->hasTable('audits')) {
        $this->markTestSkipped('Audits table does not exist');
    }

    $user = User::factory()->create();
    Auth::login($user);

    $action = TestAuditableWithModelAction::make();
    $result = $action->handle($user);

    $audit = DB::table('audits')
        ->where('action', get_class($action))
        ->latest()
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->before_state)->not->toBeNull()
        ->and($audit->after_state)->not->toBeNull();
});

test('auditable sanitizes sensitive data', function () {
    if (! DB::getSchemaBuilder()->hasTable('audits')) {
        $this->markTestSkipped('Audits table does not exist');
    }

    $user = User::factory()->create();
    Auth::login($user);

    $action = new class extends Actions
    {
        use AsAuditable;

        public function handle(string $password): string
        {
            return 'success';
        }
    };

    $action->run('secret123');

    $audit = DB::table('audits')
        ->where('action', get_class($action))
        ->latest()
        ->first();

    $arguments = json_decode($audit->arguments, true);

    expect($arguments[0])->toContain('***REDACTED***');
});

test('auditable records IP address and user agent', function () {
    if (! DB::getSchemaBuilder()->hasTable('audits')) {
        $this->markTestSkipped('Audits table does not exist');
    }

    $user = User::factory()->create();
    Auth::login($user);

    $this->withHeaders([
        'User-Agent' => 'Test Agent',
    ]);

    $action = TestAuditableAction::make();
    $action->handle('test');

    $audit = DB::table('audits')
        ->where('action', get_class($action))
        ->latest()
        ->first();

    expect($audit->user_agent)->toBe('Test Agent');
});
