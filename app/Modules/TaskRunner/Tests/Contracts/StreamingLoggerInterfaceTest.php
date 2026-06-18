<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\StreamingLogger;
use Tests\TestCase;

uses(TestCase::class);

describe('StreamingLoggerInterface Contract', function () {
    describe('interface contract', function () {
        it('defines all required methods', function () {
            $reflection = new ReflectionClass(StreamingLoggerInterface::class);
            $methods = $reflection->getMethods();

            $methodNames = array_map(fn ($method) => $method->getName(), $methods);
            expect($methodNames)->toContain(
                'log',
                'stream',
                'addStreamHandler',
                'removeStreamHandler',
                'getStreamHandlers',
                'clearStreamHandlers',
                'streamProcessOutput',
                'streamTaskEvent',
                'streamError',
                'streamProgress',
                'streamChainEvent'
            );
        });

        it('has correct log method signature', function () {
            $reflection = new ReflectionClass(StreamingLoggerInterface::class);
            $method = $reflection->getMethod('log');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(4);

            $levelParam = $method->getParameters()[0];
            expect($levelParam->getName())->toBe('level');
            expect($levelParam->getType()->getName())->toBe('string');

            $messageParam = $method->getParameters()[1];
            expect($messageParam->getName())->toBe('message');
            expect($messageParam->getType()->getName())->toBe('string');

            $contextParam = $method->getParameters()[2];
            expect($contextParam->getName())->toBe('context');
            expect($contextParam->getType()->getName())->toBe('array');
            expect($contextParam->isOptional())->toBeTrue();

            $streamParam = $method->getParameters()[3];
            expect($streamParam->getName())->toBe('stream');
            expect($streamParam->getType()->getName())->toBe('bool');
            expect($streamParam->isOptional())->toBeTrue();
        });

        it('has correct stream method signature', function () {
            $reflection = new ReflectionClass(StreamingLoggerInterface::class);
            $method = $reflection->getMethod('stream');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(3);

            $levelParam = $method->getParameters()[0];
            expect($levelParam->getName())->toBe('level');
            expect($levelParam->getType()->getName())->toBe('string');

            $messageParam = $method->getParameters()[1];
            expect($messageParam->getName())->toBe('message');
            expect($messageParam->getType()->getName())->toBe('string');

            $contextParam = $method->getParameters()[2];
            expect($contextParam->getName())->toBe('context');
            expect($contextParam->getType()->getName())->toBe('array');
            expect($contextParam->isOptional())->toBeTrue();
        });

        it('has correct addStreamHandler method signature', function () {
            $reflection = new ReflectionClass(StreamingLoggerInterface::class);
            $method = $reflection->getMethod('addStreamHandler');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(2);

            $handlerParam = $method->getParameters()[0];
            expect($handlerParam->getName())->toBe('handler');
            expect($handlerParam->getType()->getName())->toBe('callable');

            $channelParam = $method->getParameters()[1];
            expect($channelParam->getName())->toBe('channel');
            expect($channelParam->getType()->getName())->toBe('string');
            expect($channelParam->allowsNull())->toBeTrue();
            expect($channelParam->isOptional())->toBeTrue();
        });

        it('has correct getStreamHandlers return type', function () {
            $reflection = new ReflectionClass(StreamingLoggerInterface::class);
            $method = $reflection->getMethod('getStreamHandlers');

            expect($method->getReturnType()->getName())->toBe('array');
        });
    });

    describe('method accessibility', function () {
        it('ensures all methods are public', function () {
            $reflection = new ReflectionClass(StreamingLoggerInterface::class);
            $methods = $reflection->getMethods();

            foreach ($methods as $method) {
                expect($method->isPublic())->toBeTrue();
                expect($method->isAbstract())->toBeTrue();
            }
        });
    });

    describe('interface compliance', function () {
        it('StreamingLogger implements the interface', function () {
            $logger = new StreamingLogger;
            expect($logger)->toBeInstanceOf(StreamingLoggerInterface::class);
        });

        it('can log messages with different levels', function () {
            $logger = new StreamingLogger;

            // Test that these methods can be called without errors
            expect($logger->log('info', 'Test message'))->toBeNull();
            expect($logger->log('error', 'Error message'))->toBeNull();
            expect($logger->log('debug', 'Debug message', [], true))->toBeNull();
        });

        it('can stream messages', function () {
            $logger = new StreamingLogger;

            expect($logger->stream('info', 'Streamed message'))->toBeNull();
            expect($logger->stream('error', 'Streamed error', ['context' => 'test']))->toBeNull();
        });

        it('can manage stream handlers', function () {
            $logger = new StreamingLogger;
            $handler = function ($level, $message, $context) {
                return true;
            };

            expect($logger->addStreamHandler($handler))->toBeNull();
            expect($logger->addStreamHandler($handler, 'test-channel'))->toBeNull();
            expect($logger->removeStreamHandler($handler))->toBeNull();
            expect($logger->getStreamHandlers())->toBeArray();
            expect($logger->clearStreamHandlers())->toBeNull();
        });

        it('can stream specialized events', function () {
            $logger = new StreamingLogger;

            expect($logger->streamProcessOutput('stdout', 'Process output'))->toBeNull();
            expect($logger->streamTaskEvent('started', ['task_id' => 1]))->toBeNull();
            expect($logger->streamError('Error occurred', ['error_code' => 500]))->toBeNull();
            expect($logger->streamProgress(50, 100, 'Processing...'))->toBeNull();
            expect($logger->streamChainEvent('chain_started', ['chain_id' => 1]))->toBeNull();
        });
    });

    describe('log levels validation', function () {
        it('accepts standard log levels', function () {
            $logger = new StreamingLogger;
            $levels = ['debug', 'info', 'warning', 'error', 'critical'];

            foreach ($levels as $level) {
                expect($logger->log($level, "Test {$level} message"))->toBeNull();
            }
        });
    });

    describe('context handling', function () {
        it('handles various context data types', function () {
            $logger = new StreamingLogger;

            $contexts = [
                [],
                ['key' => 'value'],
                ['nested' => ['data' => 'value']],
                ['array' => [1, 2, 3]],
                ['null' => null],
                ['boolean' => true],
            ];

            foreach ($contexts as $context) {
                expect($logger->log('info', 'Test message', $context))->toBeNull();
            }
        });
    });
});
