<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Services\TemplateService;
use App\Modules\TaskRunner\Traits\HandlesTemplates;
use Illuminate\Support\Facades\Cache;

describe('HandlesTemplates Trait (Complex)', function () {
    beforeEach(function () {
        $this->templateService = Mockery::mock(TemplateService::class);
        app()->instance(TemplateService::class, $this->templateService);
        Cache::shouldReceive('get')->andReturn(['foo' => ['configuration' => [], 'parameters_schema' => ['bar' => ['type' => 'string', 'required' => true]]]]);
        Cache::shouldReceive('put');
        $this->testClass = new class
        {
            use HandlesTemplates;

            public function getTaskType(): string
            {
                return 'TestType';
            }

            protected function applyTemplateConfiguration(array $template, array $parameters): void
            {
                $this->applied = true;
            }

            public $applied = false;
        };
    });

    it('retrieves and applies template', function () {
        $this->templateService->shouldReceive('recordUsage');
        $this->testClass->createFromTemplate('foo', ['bar' => 'baz']);
        expect($this->testClass->applied)->toBeTrue();
    });

    it('throws on missing template', function () {
        Cache::shouldReceive('get')->andReturn([]);
        expect(fn () => $this->testClass->createFromTemplate('missing'))->toThrow(InvalidArgumentException::class);
    });

    it('throws on invalid parameters', function () {
        expect(fn () => $this->testClass->createFromTemplate('foo', []))->toThrow(InvalidArgumentException::class);
    });

    it('validates template parameters', function () {
        $result = $this->testClass->validateTemplateParameters('foo', ['bar' => 'baz']);
        expect($result['valid'])->toBeTrue();
    });
});
