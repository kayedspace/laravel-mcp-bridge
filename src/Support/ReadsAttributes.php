<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Support;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Instantiating the attributes on a class or a method.
 *
 * IS_INSTANCEOF throughout, so an application subclassing one of these
 * attributes still gets it read, and so that a vendor attribute hierarchy like
 * Scramble's four parameter kinds is found in one pass.
 */
trait ReadsAttributes
{
    /**
     * @template T of object
     *
     * @param  ReflectionClass<object>|ReflectionMethod  $target
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    protected function one(ReflectionClass|ReflectionMethod $target, string $attribute): ?object
    {
        $found = $target->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF);

        return $found === [] ? null : $found[0]->newInstance();
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<object>|ReflectionMethod  $target
     * @param  class-string<T>  $attribute
     * @return list<T>
     */
    protected function many(ReflectionClass|ReflectionMethod $target, string $attribute): array
    {
        // Reflection throws for a filter class that does not exist, and an
        // older release of an integration may not ship the attribute yet.
        if (! class_exists($attribute)) {
            return [];
        }

        return array_map(
            fn (ReflectionAttribute $found): object => $found->newInstance(),
            $target->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF),
        );
    }
}
