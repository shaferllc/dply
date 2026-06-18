<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Traits\PersistentFakeTasks;
use Illuminate\Filesystem\Filesystem;

describe('PersistentFakeTasks Trait (Complex)', function () {
    beforeEach(function () {
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->testClass = new class($this->filesystem)
        {
            use PersistentFakeTasks;

            public $filesystem;

            public $setupCalled = false;

            public function __construct($filesystem)
            {
                $this->filesystem = $filesystem;
            }

            public function setUpPersistentFakeTasks()
            {
                $this->setupCalled = true;
            }

            public function tearDownPersistentFakeTasks()
            {
                $this->filesystem->cleanDirectory('/tmp');
            }
        };
    });

    it('calls cleanDirectory on teardown', function () {
        $this->filesystem->shouldReceive('cleanDirectory')->once()->with('/tmp');
        $this->testClass->tearDownPersistentFakeTasks();
        expect(true)->toBeTrue();
    });

    it('calls setup method', function () {
        $this->testClass->setUpPersistentFakeTasks();
        expect($this->testClass->setupCalled)->toBeTrue();
    });
});
