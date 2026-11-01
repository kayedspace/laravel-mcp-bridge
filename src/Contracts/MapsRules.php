<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Contracts;

use KayedSpace\McpBridge\Data\Field;

/**
 * Turns one validation rule this package does not recognise into something an
 * agent can read.
 *
 * The built-in mapping covers Laravel's own rules. An application's custom
 * rule object carries logic no schema can express, so by default it becomes a
 * note on the field saying so, which is honest and not much use. A mapper
 * says what the rule means instead: `new Postcode` is a string with a
 * pattern, `new OneOfOurStatuses` is an enum.
 *
 * Registered in `mcp-bridge.schema.rule_mappers`, asked before the built-in
 * mapping, so an application can also override how a Laravel rule is read
 * without replacing the mapper that reads the rest.
 */
interface MapsRules
{
    /**
     * Whether this mapper has something to say about the rule.
     *
     * The rule is whatever validation was given: a string like `max:255`, or
     * an object implementing one of Laravel's rule interfaces.
     */
    public function handles(mixed $rule): bool;

    /**
     * The field with the rule's meaning added.
     *
     * Asked only when `handles()` said yes, and then it is the answer: the
     * built-in mapping and any later mapper are not asked about that rule.
     * A mapper that has nothing to say about a rule says so in `handles()`.
     */
    public function map(Field $field, mixed $rule): Field;
}
