<?php

declare(strict_types=1);

use App\Actions\Decorators\DebounceDecorator;
use App\Jobs\ExecuteDebouncedAction;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

describe('DebounceDecorator', function () {
    it('dispatches ExecuteDebouncedAction after the quiet period starts', function () {
        Queue::fake();

        $action = new class
        {
            public function handle(string $value): string
            {
                return $value;
            }
        };
        $decorator = new DebounceDecorator($action);

        expect($decorator->handle('hello'))->toBeNull();

        Queue::assertPushed(ExecuteDebouncedAction::class, function (ExecuteDebouncedAction $job) use ($action): bool {
            return $job->actionClass === $action::class
                && $job->arguments === ['hello']
                && str_starts_with($job->cacheKey, 'debounce:'.$action::class.':');
        });
    });
});
