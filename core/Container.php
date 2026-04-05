<?php

namespace Core;

/**
 * Dependency Injection Container
 *
 * ویژگی‌ها:
 *  - Auto-wiring کامل با Reflection (type-hint → خودکار resolve)
 *  - Manual binding با Closure یا class string
 *  - Singleton binding (یک بار ساخته، بعد cache)
 *  - تشخیص Circular Dependency (جلوگیری از حلقه بی‌نهایت)
 *  - Contextual override: bind() همیشه auto-wiring را override می‌کند
 *
 * جریان صحیح:
 *   Router::dispatch()
 *     → Container::make(ControllerClass)
 *         → Container::make(ServiceClass)        [از type-hint constructor]
 *             → Container::make(ModelClass)       [از type-hint constructor]
 *         → Controller::__construct(Service)
 */
class Container
{
    private static ?Container $instance = null;

    /** @var array<string, \Closure|string> */
    private array $bindings = [];

    /** @var array<string, object|null>  null = ثبت‌شده ولی هنوز build نشده */
    private array $singletons = [];

    /** @var array<string, bool>  برای تشخیص circular dependency */
    private array $buildStack = [];
   private $reflectionCache = [];
    // ─────────────────────────────────────────────────────────────
    // Singleton Access
    // ─────────────────────────────────────────────────────────────

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}

    public function __wakeup(): void
    {
        throw new \LogicException('Container cannot be unserialized.');
    }

    // ─────────────────────────────────────────────────────────────
    // Registration
    // ─────────────────────────────────────────────────────────────

    /**
     * ثبت binding ساده — هر بار instance جدید
     */
    public function bind(string $abstract, $concrete = null): void
    {
        $this->bindings[$abstract] = $concrete ?? $abstract;
        unset($this->singletons[$abstract]);
    }

    /**
     * ثبت Singleton — فقط یک بار ساخته، بعد cache
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bindings[$abstract]  = $concrete ?? $abstract;
        $this->singletons[$abstract] = null;
    }

    /**
     * ثبت یک instance آماده به‌عنوان singleton
     */
    public function instance(string $abstract, object $object): void
    {
        $this->bindings[$abstract]   = $abstract;
        $this->singletons[$abstract] = $object;
    }

    // ─────────────────────────────────────────────────────────────
    // Resolution
    // ─────────────────────────────────────────────────────────────

    /**
     * ساخت / دریافت instance
     *
     * @throws \RuntimeException
     */
    public function make(string $abstract): object
    {
        // Singleton cache
        if (array_key_exists($abstract, $this->singletons)) {
            if ($this->singletons[$abstract] === null) {
                $this->singletons[$abstract] = $this->resolve($abstract);
            }
            return $this->singletons[$abstract];
        }

        return $this->resolve($abstract);
    }

    private function resolve(string $class)
{
    if (!isset($this->reflectionCache[$class])) {
        $this->reflectionCache[$class] = new \ReflectionClass($class);
    }
    
    $reflector = $this->reflectionCache[$class];

    if (!$reflector->isInstantiable()) {
        throw new \RuntimeException("[Container] کلاس {$class} قابل نمونه‌سازی نیست");
    }

    $constructor = $reflector->getConstructor();

    if (is_null($constructor)) {
        return new $class;
    }

    $parameters  = $constructor->getParameters();
    $dependencies = $this->resolveDependencies($parameters, $class);

    return $reflector->newInstanceArgs($dependencies);
}

    private function build(string $class): object
    {
        // Circular Dependency Guard
        if (isset($this->buildStack[$class])) {
            $cycle = implode(' → ', array_keys($this->buildStack)) . ' → ' . $class;
            throw new \RuntimeException("[Container] Circular dependency: {$cycle}");
        }

        $this->buildStack[$class] = true;

        try {
            $reflection = new \ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                throw new \RuntimeException(
                    "[Container] '{$class}' is not instantiable (abstract/interface/private constructor)."
                );
            }

            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return new $class();
            }

            $dependencies = $this->resolveDependencies($constructor->getParameters(), $class);

            return $reflection->newInstanceArgs($dependencies);

        } finally {
            unset($this->buildStack[$class]);
        }
    }

    /**
     * حل کردن پارامترهای constructor به‌صورت خودکار
     *
     * @param  \ReflectionParameter[] $parameters
     */
    private function resolveDependencies(array $parameters, string $forClass): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            // بدون type-hint
            if ($type === null || !($type instanceof \ReflectionNamedType)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $dependencies[] = null;
                    continue;
                }
                throw new \RuntimeException(
                    "[Container] Cannot resolve '\${$parameter->getName()}'" .
                    " in {$forClass}::__construct() — no type-hint, no default."
                );
            }

            // Primitive type (int, string, bool, ...)
            if ($type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $dependencies[] = null;
                    continue;
                }
                throw new \RuntimeException(
                    "[Container] Cannot resolve primitive '\${$parameter->getName()}'" .
                    " ({$type->getName()}) in {$forClass}::__construct() — add a default value."
                );
            }

            // Class / Interface type-hint
            $typeName = $type->getName();

            if ($parameter->allowsNull()) {
                try {
                    $dependencies[] = $this->make($typeName);
                } catch (\RuntimeException) {
                    $dependencies[] = null;
                }
                continue;
            }

            $dependencies[] = $this->make($typeName);
        }

        return $dependencies;
    }

    // ─────────────────────────────────────────────────────────────
    // Utility
    // ─────────────────────────────────────────────────────────────

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || array_key_exists($abstract, $this->singletons);
    }

    public function forget(string $abstract): void
    {
        unset($this->bindings[$abstract], $this->singletons[$abstract]);
    }

    /** فهرست binding‌های ثبت‌شده — فقط برای Debug */
    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }
}
