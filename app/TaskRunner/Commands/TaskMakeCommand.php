<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:task')]
class TaskMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:task';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Task';

    /**
     * The name of the console command.
     *
     * This name is used to identify the command during lazy loading.
     *
     * @var string|null
     *
     * @deprecated
     */
    protected static $defaultName = 'make:task';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Task class';

    /**
     * Execute the console command.
     *
     * @throws FileNotFoundException
     */
    public function handle(): ?bool
    {
        if (parent::handle() === false) {
            return false;
        }

        if ($this->option('class')) {
            return null;
        }

        (new Filesystem)->ensureDirectoryExists(
            $path = resource_path('views/tasks')
        );

        touch($path.'/'.$this->viewName().'.blade.php');

        return null;
    }

    /**
     * Generate the name of the view.
     */
    protected function viewName(): string
    {
        return Str::kebab($this->getNameInput());
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->option('class')
            ? $this->resolveStubPath('/stubs/task.stub')
            : $this->resolveStubPath('/stubs/task-view.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
                        ? $customPath
                        : __DIR__.$stub;
    }

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Tasks';
    }

    /**
     * Get the console command options.
     *
     * @return array<int, array<int, string|int>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the class already exists'],
            ['class', 'c', InputOption::VALUE_NONE, 'Create only the Task class, not the Blade template'],
        ];
    }
}
