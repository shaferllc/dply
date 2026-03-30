<?php

namespace App\Actions;

use App\Actions\Concerns\AsCommand;
use App\Actions\Concerns\AsController;
use App\Actions\Concerns\AsFake;
use App\Actions\Decorators\FeatureFlaggedDecorator;
use App\Actions\Decorators\FilteredDecorator;
use App\Actions\Decorators\IdempotentDecorator;
use App\Actions\Decorators\JobDecorator;
use App\Actions\Decorators\JWTDecorator;
use App\Actions\Decorators\LazyDecorator;
use App\Actions\Decorators\LifecycleDecorator;
use App\Actions\Decorators\LockDecorator;
use App\Actions\Decorators\LoggerDecorator;
use App\Actions\Decorators\RequiresBillingFeatureDecorator;
use App\Actions\Decorators\RequiresCapabilityDecorator;
use App\Actions\Decorators\RequiresPlanDecorator;
use App\Actions\Decorators\RequiresRoleDecorator;
use App\Actions\Decorators\RequiresSubscriptionDecorator;
use App\Actions\Decorators\TestableDecorator;
use App\Actions\Decorators\ThrottleDecorator;
use App\Actions\Decorators\TimeoutDecorator;
use App\Actions\Decorators\TracerDecorator;
use App\Actions\Decorators\TransactionDecorator;
use App\Actions\Decorators\UniqueJobDecorator;
use App\Actions\Decorators\ValidationDecorator;
use App\Actions\Decorators\VersionDecorator;
use App\Actions\Decorators\WatermarkDecorator;
use App\Actions\Decorators\WebhookDecorator;
use App\Actions\DesignPatterns\CommandDesignPattern;
use App\Actions\DesignPatterns\DesignPattern;
use App\Actions\DesignPatterns\FeatureFlaggedDesignPattern;
use App\Actions\DesignPatterns\FilteredDesignPattern;
use App\Actions\DesignPatterns\IdempotentDesignPattern;
use App\Actions\DesignPatterns\JWTDesignPattern;
use App\Actions\DesignPatterns\LazyDesignPattern;
use App\Actions\DesignPatterns\LifecycleDesignPattern;
use App\Actions\DesignPatterns\LockDesignPattern;
use App\Actions\DesignPatterns\LoggerDesignPattern;
use App\Actions\DesignPatterns\RequiresBillingFeatureDesignPattern;
use App\Actions\DesignPatterns\RequiresCapabilityDesignPattern;
use App\Actions\DesignPatterns\RequiresPlanDesignPattern;
use App\Actions\DesignPatterns\RequiresRoleDesignPattern;
use App\Actions\DesignPatterns\RequiresSubscriptionDesignPattern;
use App\Actions\DesignPatterns\TestableDesignPattern;
use App\Actions\DesignPatterns\ThrottleDesignPattern;
use App\Actions\DesignPatterns\TimeoutDesignPattern;
use App\Actions\DesignPatterns\TracerDesignPattern;
use App\Actions\DesignPatterns\TransactionDesignPattern;
use App\Actions\DesignPatterns\ValidationDesignPattern;
use App\Actions\DesignPatterns\VersionDesignPattern;
use App\Actions\DesignPatterns\WatermarkDesignPattern;
use App\Actions\DesignPatterns\WebhookDesignPattern;
use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Lorisleiva\Lody\Lody;

class ActionManager
{
    /** @var class-string<JobDecorator> */
    public static string $jobDecorator = JobDecorator::class;

    /** @var class-string<JobDecorator&ShouldBeUnique> */
    public static string $uniqueJobDecorator = UniqueJobDecorator::class;

    /** @var DesignPattern[] */
    protected array $designPatterns = [];

    /** @var bool[] */
    protected array $extended = [];

    protected int $backtraceLimit = 10;

    public function __construct(array $designPatterns = [])
    {
        $this->setDesignPatterns($designPatterns);
    }

    /**
     * @param  class-string<JobDecorator>  $jobDecoratorClass
     */
    public static function useJobDecorator(string $jobDecoratorClass): void
    {
        static::$jobDecorator = $jobDecoratorClass;
    }

    /**
     * @param  class-string<JobDecorator&ShouldBeUnique>  $uniqueJobDecoratorClass
     */
    public static function useUniqueJobDecorator(string $uniqueJobDecoratorClass): void
    {
        static::$uniqueJobDecorator = $uniqueJobDecoratorClass;
    }

    public function setBacktraceLimit(int $backtraceLimit): ActionManager
    {
        $this->backtraceLimit = $backtraceLimit;

        return $this;
    }

    public function setDesignPatterns(array $designPatterns): ActionManager
    {
        $this->designPatterns = $designPatterns;

        return $this;
    }

    public function getDesignPatterns(): array
    {
        return $this->designPatterns;
    }

    public function registerDesignPattern(DesignPattern $designPattern): ActionManager
    {
        $this->designPatterns[] = $designPattern;

        return $this;
    }

    public function getDesignPatternsMatching(array $usedTraits): array
    {
        $filter = function (DesignPattern $designPattern) use ($usedTraits) {
            return in_array($designPattern->getTrait(), $usedTraits);
        };

        return array_filter($this->getDesignPatterns(), $filter);
    }

    public function extend(Application $app, string $abstract): void
    {
        if ($this->isExtending($abstract)) {
            return;
        }

        if (! $this->shouldExtend($abstract)) {
            return;
        }

        $app->extend($abstract, function ($instance) {
            return $this->identifyAndDecorate($instance);
        });

        $this->extended[$abstract] = true;
    }

    public function isExtending(string $abstract): bool
    {
        return isset($this->extended[$abstract]);
    }

    public function shouldExtend(string $abstract): bool
    {
        $usedTraits = class_uses_recursive($abstract);

        return ! empty($this->getDesignPatternsMatching($usedTraits))
            || in_array(AsFake::class, $usedTraits);
    }

    public function identifyAndDecorate($instance)
    {
        // Get the original action instance (unwrap if already decorated)
        $originalInstance = $this->unwrapDecorator($instance);
        $usedTraits = class_uses_recursive($originalInstance);

        if (in_array(AsFake::class, $usedTraits) && $originalInstance::isFake()) {
            $originalInstance = $originalInstance::mock();
        }

        // Check if this is a command action being registered in console
        $isCommandAction = in_array(AsCommand::class, $usedTraits);
        $isConsoleContext = app()->runningInConsole();

        // Apply all matching decorators
        $decoratedInstance = $originalInstance;
        $designPatterns = $this->getDesignPatternsMatching($usedTraits);

        // Prioritize CommandDesignPattern - it must be the outermost decorator
        $commandPattern = null;
        $otherPatterns = [];
        foreach ($designPatterns as $designPattern) {
            if ($designPattern instanceof CommandDesignPattern) {
                $commandPattern = $designPattern;
            } else {
                $otherPatterns[] = $designPattern;
            }
        }

        // Apply non-command decorators first (but skip them for commands in console)
        foreach ($otherPatterns as $designPattern) {
            // Check if this decorator type is already applied
            if ($this->hasDecorator($decoratedInstance, $designPattern)) {
                continue;
            }

            // Skip all non-command decorators when registering commands in console
            // CommandDecorator must be the outermost decorator for commands
            // Exception: apply decorators in testing so action tests can verify behavior
            if ($isCommandAction && $isConsoleContext && ! app()->environment('testing')) {
                continue;
            }

            $frame = new BacktraceFrame([]);
            if (! $designPattern->recognizeFrame($frame)) {
                continue;
            }

            $decoratedInstance = $designPattern->decorate($decoratedInstance, $frame);
        }

        // Apply CommandDesignPattern last (outermost) - it will unwrap any existing decorators
        if ($commandPattern && ! $this->hasDecorator($decoratedInstance, $commandPattern)) {
            $frame = new BacktraceFrame([]);
            if ($commandPattern->recognizeFrame($frame)) {
                $decoratedInstance = $commandPattern->decorate($decoratedInstance, $frame);
            }
        }

        return $decoratedInstance;
    }

    protected function unwrapDecorator($instance)
    {
        // If instance is a decorator, get the wrapped action
        if (str_starts_with(get_class($instance), 'App\\Actions\\Decorators\\')) {
            $reflection = new \ReflectionClass($instance);
            if ($reflection->hasProperty('action')) {
                $property = $reflection->getProperty('action');
                $property->setAccessible(true);
                $wrappedAction = $property->getValue($instance);

                return $this->unwrapDecorator($wrappedAction);
            }
        }

        return $instance;
    }

    protected function hasDecorator($instance, DesignPattern $pattern): bool
    {
        // Check if instance is already decorated with this pattern's decorator
        $instanceClass = get_class($instance);
        $decoratorClass = $this->getDecoratorClassForPattern($pattern);

        if ($decoratorClass && $instanceClass === $decoratorClass) {
            return true;
        }

        // Check nested decorators
        if (str_starts_with($instanceClass, 'App\\Actions\\Decorators\\')) {
            $reflection = new \ReflectionClass($instance);
            if ($reflection->hasProperty('action')) {
                $property = $reflection->getProperty('action');
                $property->setAccessible(true);
                $wrappedAction = $property->getValue($instance);

                return $this->hasDecorator($wrappedAction, $pattern);
            }
        }

        return false;
    }

    protected function getDecoratorClassForPattern(DesignPattern $pattern): ?string
    {
        // Map design patterns to their decorator classes
        $patternClass = get_class($pattern);
        $decoratorMap = [
            FeatureFlaggedDesignPattern::class => FeatureFlaggedDecorator::class,
            FilteredDesignPattern::class => FilteredDecorator::class,
            IdempotentDesignPattern::class => IdempotentDecorator::class,
            JWTDesignPattern::class => JWTDecorator::class,
            LazyDesignPattern::class => LazyDecorator::class,
            LifecycleDesignPattern::class => LifecycleDecorator::class,
            LockDesignPattern::class => LockDecorator::class,
            LoggerDesignPattern::class => LoggerDecorator::class,
            RequiresBillingFeatureDesignPattern::class => RequiresBillingFeatureDecorator::class,
            RequiresCapabilityDesignPattern::class => RequiresCapabilityDecorator::class,
            RequiresPlanDesignPattern::class => RequiresPlanDecorator::class,
            RequiresRoleDesignPattern::class => RequiresRoleDecorator::class,
            RequiresSubscriptionDesignPattern::class => RequiresSubscriptionDecorator::class,
            ValidationDesignPattern::class => ValidationDecorator::class,
            VersionDesignPattern::class => VersionDecorator::class,
            WebhookDesignPattern::class => WebhookDecorator::class,
            WatermarkDesignPattern::class => WatermarkDecorator::class,
            TransactionDesignPattern::class => TransactionDecorator::class,
            TracerDesignPattern::class => TracerDecorator::class,
            TestableDesignPattern::class => TestableDecorator::class,
            ThrottleDesignPattern::class => ThrottleDecorator::class,
            TimeoutDesignPattern::class => TimeoutDecorator::class,
        ];

        return $decoratorMap[$patternClass] ?? null;
    }

    public function identifyFromBacktrace($usedTraits, ?BacktraceFrame &$frame = null): ?DesignPattern
    {
        $designPatterns = $this->getDesignPatternsMatching($usedTraits);
        $backtraceOptions = DEBUG_BACKTRACE_PROVIDE_OBJECT
            | DEBUG_BACKTRACE_IGNORE_ARGS;

        $ownNumberOfFrames = 2;
        $frames = array_slice(
            debug_backtrace($backtraceOptions, $ownNumberOfFrames + $this->backtraceLimit),
            $ownNumberOfFrames
        );
        foreach ($frames as $frame) {
            $frame = new BacktraceFrame($frame);

            /** @var DesignPattern $designPattern */
            foreach ($designPatterns as $designPattern) {
                if ($designPattern->recognizeFrame($frame)) {
                    return $designPattern;
                }
            }
        }

        return null;
    }

    public function registerRoutes(array|string $paths = 'app/Actions'): void
    {
        Lody::classes($paths)
            ->isNotAbstract()
            ->hasTrait(AsController::class)
            ->hasStaticMethod('routes')
            ->each(fn (string $classname) => $this->registerRoutesForAction($classname));
    }

    public function registerCommands(array|string $paths = 'app/Actions'): void
    {
        Lody::classes($paths)
            ->isNotAbstract()
            ->hasTrait(AsCommand::class)
            ->filter(function (string $classname): bool {
                return property_exists($classname, 'commandSignature')
                    || method_exists($classname, 'getCommandSignature');
            })
            ->each(fn (string $classname) => $this->registerCommandsForAction($classname));
    }

    public function registerRoutesForAction(string $className): void
    {
        $className::routes(app(Router::class));
    }

    public function registerCommandsForAction(string $className): void
    {
        Artisan::starting(function ($artisan) use ($className) {
            $artisan->resolve($className);
        });
    }
}
