<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Services\CallbackService;
use App\Modules\TaskRunner\Traits\HandlesCallbacks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

describe('HandlesCallbacks Trait (Complex)', function () {
    beforeEach(function () {
        $this->callbackService = Mockery::mock(CallbackService::class);
        app()->instance(CallbackService::class, $this->callbackService);
        Log::spy();
        $this->testClass = new class
        {
            use HandlesCallbacks;

            public $task;

            public $callbackUrl = 'http://localhost/callback';

            public $callbacksEnabled = true;

            public $afterCallbackCalled = false;

            public function __construct()
            {
                $this->task = (object) [
                    'id' => 1,
                    'name' => 'Test',
                    'status' => null,
                    'exit_code' => 0,
                    'getDuration' => fn () => 10,
                    'getOutput' => fn () => '',
                    'timeout' => 30,
                ];
            }

            protected function afterCallback($task, $request, $type)
            {
                $this->afterCallbackCalled = true;
            }
        };
    });

    it('sends all callback types and handles afterCallback', function () {
        $this->callbackService->shouldReceive('send')->andReturn(true);
        foreach ([CallbackType::Started, CallbackType::Finished, CallbackType::Failed, CallbackType::Timeout, CallbackType::Progress, CallbackType::Custom] as $type) {
            $result = $this->testClass->sendCallback($type, ['foo' => 'bar']);
            expect($result)->toBeTrue();
        }
        $request = Request::create('/', 'POST', ['foo' => 'bar']);
        $task = new Task;
        $this->testClass->handleCallback($task, $request, CallbackType::Custom);
        expect($this->testClass->afterCallbackCalled)->toBeTrue();
    });

    it('returns false if callbacks are disabled or url missing', function () {
        $this->testClass->callbacksEnabled = false;
        expect($this->testClass->sendCallback(CallbackType::Started))->toBeFalse();
        $this->testClass->callbacksEnabled = true;
        $this->testClass->callbackUrl = null;
        expect($this->testClass->sendCallback(CallbackType::Started))->toBeFalse();
    });

    it('validates callback data and headers', function () {
        $data = $this->testClass->getCallbackData();
        expect($this->testClass->validateCallbackData($data))->toBeTrue();
        $headers = $this->testClass->getCallbackHeaders();
        expect($headers)->toHaveKey('X-Task-ID');
    });

    it('handles failed callback sending', function () {
        $this->callbackService->shouldReceive('send')->andReturn(false);
        expect($this->testClass->sendCallback(CallbackType::Failed))->toBeFalse();
    });
});
