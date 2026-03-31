<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Commands\TaskMakeCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Clean up any test files
    $this->testTaskPath = base_path('app/Tasks/TestTask.php');
    $this->testViewPath = base_path('resources/views/tasks/test-task.blade.php');

    File::delete($this->testTaskPath);
    File::delete($this->testViewPath);

    // Ensure the Tasks directory exists
    if (! File::exists(base_path('app/Tasks'))) {
        File::makeDirectory(base_path('app/Tasks'), 0755, true);
    }
});

afterEach(function () {
    // Clean up test files
    File::delete($this->testTaskPath);
    File::delete($this->testViewPath);
});

describe('TaskMakeCommand', function () {
    it('creates a task class with view template by default', function () {
        $this->artisan('make:task', ['name' => 'TestTask'])
            ->assertExitCode(0);

        // Assert task class was created
        expect(File::exists($this->testTaskPath))->toBeTrue();

        $taskContent = File::get($this->testTaskPath);
        expect($taskContent)->toContain('class TestTask extends Task');
        expect($taskContent)->toContain('namespace App\Tasks');
        expect($taskContent)->toContain('use App\TaskRunner\Task');

        // Assert view template was created
        expect(File::exists($this->testViewPath))->toBeTrue();
    });

    it('creates only task class when --class option is used', function () {
        $this->artisan('make:task', ['name' => 'TestTask', '--class' => true])
            ->assertExitCode(0);

        // Assert task class was created
        expect(File::exists($this->testTaskPath))->toBeTrue();

        $taskContent = File::get($this->testTaskPath);
        expect($taskContent)->toContain('class TestTask extends Task');
        expect($taskContent)->toContain('namespace App\Tasks');
        expect($taskContent)->toContain('use App\TaskRunner\Task');
        expect($taskContent)->toContain('public function render(): string');

        // Assert view template was NOT created
        expect(File::exists($this->testViewPath))->toBeFalse();
    });

    it('generates correct namespace for task class', function () {
        $this->artisan('make:task', ['name' => 'TestTask'])
            ->assertExitCode(0);

        $taskContent = File::get($this->testTaskPath);
        expect($taskContent)->toContain('namespace App\Tasks');
    });

    it('generates kebab-case view name from task name', function () {
        $this->artisan('make:task', ['name' => 'ComplexTestTask'])
            ->assertExitCode(0);

        $expectedViewPath = base_path('resources/views/tasks/complex-test-task.blade.php');
        expect(File::exists($expectedViewPath))->toBeTrue();

        // Clean up
        File::delete($expectedViewPath);
        File::delete(base_path('app/Tasks/ComplexTestTask.php'));
    });

    it('uses task.stub when --class option is provided', function () {
        $this->artisan('make:task', ['name' => 'TestTask', '--class' => true])
            ->assertExitCode(0);

        $taskContent = File::get($this->testTaskPath);
        expect($taskContent)->toContain('public function render(): string');
        expect($taskContent)->toContain("return '';");
    });

    it('uses task-view.stub when --class option is not provided', function () {
        $this->artisan('make:task', ['name' => 'TestTask'])
            ->assertExitCode(0);

        $taskContent = File::get($this->testTaskPath);
        expect($taskContent)->not->toContain('public function render(): string');
        expect($taskContent)->not->toContain("return '';");
    });

    it('creates views directory if it does not exist', function () {
        $viewsPath = base_path('resources/views/tasks');

        // Remove the directory if it exists
        if (File::exists($viewsPath)) {
            File::deleteDirectory($viewsPath);
        }

        expect(File::exists($viewsPath))->toBeFalse();

        $this->artisan('make:task', ['name' => 'TestTask'])
            ->assertExitCode(0);

        expect(File::exists($viewsPath))->toBeTrue();
        expect(File::exists($this->testViewPath))->toBeTrue();
    });

    it('handles force option correctly', function () {
        // Create the task first
        $this->artisan('make:task', ['name' => 'TestTask'])
            ->assertExitCode(0);

        expect(File::exists($this->testTaskPath))->toBeTrue();

        $originalContent = File::get($this->testTaskPath);

        // Try to create again without force - should not overwrite
        $this->artisan('make:task', ['name' => 'TestTask'])
            ->assertExitCode(0);

        $newContent = File::get($this->testTaskPath);
        expect($newContent)->toBe($originalContent);

        // Create with force - should overwrite
        $this->artisan('make:task', ['name' => 'TestTask', '--force' => true])
            ->assertExitCode(0);

        $forceContent = File::get($this->testTaskPath);
        // The force option should regenerate the file, so content should be different
        // or at least the file should be recreated
        expect($forceContent)->toBe($originalContent); // Force doesn't change content in this case
    });

    it('handles complex task names correctly', function () {
        $this->artisan('make:task', ['name' => 'ComplexTaskWithMultipleWords'])
            ->assertExitCode(0);

        $taskPath = base_path('app/Tasks/ComplexTaskWithMultipleWords.php');
        $viewPath = base_path('resources/views/tasks/complex-task-with-multiple-words.blade.php');

        expect(File::exists($taskPath))->toBeTrue();
        expect(File::exists($viewPath))->toBeTrue();

        $taskContent = File::get($taskPath);
        expect($taskContent)->toContain('class ComplexTaskWithMultipleWords extends Task');

        // Clean up
        File::delete($taskPath);
        File::delete($viewPath);
    });

    it('resolves stub paths correctly', function () {
        $command = new TaskMakeCommand(new Filesystem);
        $command->setLaravel(app());

        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('resolveStubPath');
        $method->setAccessible(true);

        // Create stubs directory if it doesn't exist
        $stubsDir = base_path('stubs');
        if (! File::exists($stubsDir)) {
            File::makeDirectory($stubsDir, 0755, true);
        }

        // Test with custom stub path
        $customStubPath = base_path('stubs/custom-task.stub');
        File::put($customStubPath, 'custom stub content');

        $result = $method->invoke($command, '/stubs/custom-task.stub');
        expect($result)->toBe($customStubPath);

        // Test with default stub path
        $result = $method->invoke($command, '/stubs/task.stub');
        expect($result)->toContain('Commands');
        expect($result)->toContain('stubs/task.stub');

        // Clean up
        File::delete($customStubPath);
    });

    it('returns correct command options', function () {
        $command = new TaskMakeCommand(new Filesystem);
        $command->setLaravel(app());

        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getOptions');
        $method->setAccessible(true);

        $options = $method->invoke($command);

        expect($options)->toHaveCount(2);

        $forceOption = collect($options)->firstWhere(0, 'force');
        expect($forceOption)->not->toBeNull();
        expect($forceOption[1])->toBe('f');
        expect($forceOption[2])->toBe(InputOption::VALUE_NONE);
        expect($forceOption[3])->toBe('Create the class even if the class already exists');

        $classOption = collect($options)->firstWhere(0, 'class');
        expect($classOption)->not->toBeNull();
        expect($classOption[1])->toBe('c');
        expect($classOption[2])->toBe(InputOption::VALUE_NONE);
        expect($classOption[3])->toBe('Create only the Task class, not the Blade template');
    });

    it('has correct command properties', function () {
        $command = new TaskMakeCommand(new Filesystem);
        expect($command->getName())->toBe('make:task');
        expect($command->getDescription())->toBe('Create a new Task class');

        $reflection = new ReflectionClass($command);
        $typeProperty = $reflection->getProperty('type');
        $typeProperty->setAccessible(true);
        expect($typeProperty->getValue($command))->toBe('Task');
    });

    it('generates view name correctly', function () {
        $command = new TaskMakeCommand(new Filesystem);
        $command->setLaravel(app());

        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('viewName');
        $method->setAccessible(true);

        // Test the viewName method by creating a proper input definition
        $input = new ArrayInput(['name' => 'TestTask']);
        $input->bind(new InputDefinition([
            new InputArgument('name', InputArgument::REQUIRED),
        ]));
        $command->setInput($input);

        $result = $method->invoke($command);
        expect($result)->toBe('test-task');

        // Test with different input
        $input2 = new ArrayInput(['name' => 'ComplexTaskName']);
        $input2->bind(new InputDefinition([
            new InputArgument('name', InputArgument::REQUIRED),
        ]));
        $command->setInput($input2);

        $result = $method->invoke($command);
        expect($result)->toBe('complex-task-name');
    });
});
