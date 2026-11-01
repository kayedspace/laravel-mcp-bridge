<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Query;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Contracts\ReadsQueryDefinitions;
use ReflectionMethod;
use Throwable;

/**
 * Where a list endpoint's allowed query comes from, in order of specificity:
 * the fluent chain, then a shared definition, then the query attributes.
 *
 * They layer rather than replace each other, because a team with an existing
 * Spatie chain should be able to add one attribute to describe one filter
 * without restating the rest of the chain, and a team with no chain at all
 * should be able to declare the whole thing in attributes.
 */
class QueryReader implements ReadsQueryDefinitions
{
    public function __construct(
        protected QueryAttributeReader $attributes,
        protected SpatieChainReader $chain,
        protected Repository $config,
    ) {}

    /** @param class-string $class */
    public function read(string $class, ?string $method = null): ?QueryDefinition
    {
        if (! $this->config->boolean('mcp-bridge.query.enabled', true)) {
            return null;
        }

        $layers = array_values(array_filter([
            $this->config->boolean('mcp-bridge.query.read_chains', true)
                ? $this->chain->read($class, $method)
                : null,
            $this->shared($class, $method),
            $this->attributes->read($class, $method),
        ]));

        if ($layers === []) {
            return null;
        }

        $definition = array_shift($layers);

        foreach ($layers as $layer) {
            $definition = $definition->mergedWith($layer);
        }

        return $this->capped($definition);
    }

    /**
     * A definition both the controller and this package read, so a list that
     * several endpoints share is declared once:
     * `public static function mcpQuery(): QueryDefinition`.
     *
     * @param  class-string  $class
     */
    protected function shared(string $class, ?string $method): ?QueryDefinition
    {
        if ($method === 'mcpQuery' || ! method_exists($class, 'mcpQuery')) {
            return null;
        }

        $reflection = new ReflectionMethod($class, 'mcpQuery');

        if (! $reflection->isStatic() || $reflection->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        try {
            $definition = $reflection->invoke(null);
        } catch (Throwable) {
            // A shared definition that throws is one this package has no
            // opinion about, not a failed build.
            return null;
        }

        return $definition instanceof QueryDefinition ? $definition : null;
    }

    /**
     * Safety caps, so an agent cannot ask for a relation tree the application
     * never meant to hand over. Applied here rather than in the schema, so the
     * same cap holds when ApiQuery builds the query from this definition.
     */
    protected function capped(QueryDefinition $definition): QueryDefinition
    {
        return $definition->withIncludeDepth(
            $this->config->integer('mcp-bridge.query.max_include_depth', 2),
        );
    }
}
