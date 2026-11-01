<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Discovery;

use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpConfirm;
use KayedSpace\McpBridge\Attributes\McpDeprecated;
use KayedSpace\McpBridge\Attributes\McpExample;
use KayedSpace\McpBridge\Attributes\McpGroup;
use KayedSpace\McpBridge\Attributes\McpIgnore;
use KayedSpace\McpBridge\Attributes\McpInput;
use KayedSpace\McpBridge\Attributes\McpOutput;
use KayedSpace\McpBridge\Attributes\McpParam;
use KayedSpace\McpBridge\Attributes\McpQueue;
use KayedSpace\McpBridge\Attributes\McpRateLimit;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTimeout;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Support\ReadsAttributes;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reads the attributes on a class and one of its methods.
 *
 * This package's own attributes are instantiated, so their defaults and
 * named arguments apply. Foreign attributes are read by name and raw
 * arguments instead, which keeps a vendor package that is not installed from
 * breaking discovery, and lets laravel/mcp's safety annotations be read off a
 * controller method even though they declare TARGET_CLASS.
 */
class AttributeReader
{
    use ReadsAttributes;

    /** laravel/mcp owns these names, so they are consumed, never redeclared. */
    protected const SAFETY = [
        IsReadOnly::class => 'read_only',
        IsDestructive::class => 'destructive',
        IsIdempotent::class => 'idempotent',
        IsOpenWorld::class => 'open_world',
    ];

    /** @param class-string $class */
    public function read(string $class, ?string $method = null): Declarations
    {
        // A route action can name a class that no longer exists; discovery
        // degrades rather than throwing.
        if (! class_exists($class)) {
            return Declarations::none();
        }

        $reflection = new ReflectionClass($class);

        $targets = [$reflection];

        if ($method !== null && $reflection->hasMethod($method)) {
            $targets[] = $reflection->getMethod($method);
        }

        return $this->collect($targets);
    }

    /** @param list<ReflectionClass<object>|ReflectionMethod> $targets */
    protected function collect(array $targets): Declarations
    {
        $tool = null;
        $ignored = false;
        $input = null;
        $output = null;
        $confirm = null;
        $rateLimit = null;
        $timeout = null;
        $queue = null;
        $deprecated = null;
        $params = [];
        $examples = [];
        $scopes = [];
        $abilities = [];
        $groups = [];
        $safety = [];
        $raw = [];

        // Class first, then method, so the method wins on every single value
        // while repeatable declarations accumulate.
        foreach ($targets as $target) {
            foreach ($target->getAttributes() as $attribute) {
                $raw[$attribute->getName()] = $attribute->getArguments();
            }

            $tool = $this->one($target, McpTool::class) ?? $tool;
            $ignored = $this->one($target, McpIgnore::class) !== null || $ignored;
            $input = $this->one($target, McpInput::class) ?? $input;
            $output = $this->one($target, McpOutput::class) ?? $output;
            $confirm = $this->one($target, McpConfirm::class) ?? $confirm;
            $rateLimit = $this->one($target, McpRateLimit::class) ?? $rateLimit;
            $timeout = $this->one($target, McpTimeout::class) ?? $timeout;
            $queue = $this->one($target, McpQueue::class) ?? $queue;
            $deprecated = $this->one($target, McpDeprecated::class) ?? $deprecated;

            $params = [...$params, ...$this->many($target, McpParam::class)];
            $examples = [...$examples, ...$this->many($target, McpExample::class)];

            foreach ($this->many($target, McpScope::class) as $scope) {
                $scopes[] = $scope->scope;
            }

            foreach ($this->many($target, McpAbility::class) as $ability) {
                $abilities[] = $ability->ability;
            }

            foreach ($this->many($target, McpGroup::class) as $group) {
                $groups[] = $group->group;
            }

            $safety = [...$safety, ...$this->safety($target)];
        }

        return new Declarations(
            tool: $tool,
            ignored: $ignored,
            input: $input,
            output: $output,
            confirm: $confirm,
            rateLimit: $rateLimit,
            timeout: $timeout,
            queue: $queue,
            deprecated: $deprecated,
            params: $params,
            examples: $examples,
            scopes: array_values(array_unique($scopes)),
            abilities: array_values(array_unique($abilities)),
            groups: array_values(array_unique($groups)),
            safety: $safety,
            raw: $raw,
        );
    }

    /**
     * laravel/mcp's annotations are read from their arguments rather than
     * instantiated: they declare TARGET_CLASS, and newInstance() would refuse
     * one written on a controller method.
     *
     * @param  ReflectionClass<object>|ReflectionMethod  $target
     * @return array<string, bool>
     */
    protected function safety(ReflectionClass|ReflectionMethod $target): array
    {
        $safety = [];

        foreach (self::SAFETY as $attribute => $key) {
            $found = $target->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF);

            if ($found === []) {
                continue;
            }

            $arguments = $found[0]->getArguments();
            $value = $arguments[0] ?? $arguments['value'] ?? true;

            $safety[$key] = (bool) $value;
        }

        return $safety;
    }
}
