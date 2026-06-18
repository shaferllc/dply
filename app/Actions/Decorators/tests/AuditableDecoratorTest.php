<?php

declare(strict_types=1);

use App\Actions\Decorators\AuditableDecorator;
use Tests\TestCase;

uses(TestCase::class);

final class AuditableDecoratorTestCapture
{
    /** @var array<string, mixed> */
    public array $data = [];
}

describe('AuditableDecorator', function () {
    it('executes wrapped action and returns result', function () {
        $action = new class
        {
            public function handle(string $input): string
            {
                return strtoupper($input);
            }
        };
        $decorator = new AuditableDecorator($action);

        $result = $decorator->handle('hello');

        expect($result)->toBe('HELLO');
    });

    it('calls storeAuditRecord when action defines it', function () {
        $captured = new AuditableDecoratorTestCapture();
        $action = new class($captured)
        {
            public function __construct(
                public AuditableDecoratorTestCapture $captured
            ) {}

            public function handle(): string
            {
                return 'result';
            }

            /**
             * @param  array<string, mixed>  $data
             */
            public function storeAuditRecord(array $data): void
            {
                $this->captured->data = $data;
            }
        };
        $decorator = new AuditableDecorator($action);

        $decorator->handle();

        expect($captured->data)->not->toBeEmpty();
        expect($captured->data['action_name'])->not->toBeEmpty();
        expect($captured->data['result'])->toContain('result');
    });

    it('records audit on action failure when storeAuditRecord defined', function () {
        $captured = new AuditableDecoratorTestCapture();
        $action = new class($captured)
        {
            public function __construct(
                public AuditableDecoratorTestCapture $captured
            ) {}

            public function handle(): never
            {
                throw new RuntimeException('Action failed');
            }

            /**
             * @param  array<string, mixed>  $data
             */
            public function storeAuditRecord(array $data): void
            {
                $this->captured->data = $data;
            }
        };
        $decorator = new AuditableDecorator($action);

        expect(fn () => $decorator->handle())
            ->toThrow(RuntimeException::class, 'Action failed');

        expect($captured->data['exception'])->toContain('Action failed');
    });
});
