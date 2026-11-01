<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reading application source without running it.
 *
 * Several readers need the same thing: the parsed body of one method or class,
 * with class names resolved through the file's own use statements so that
 * `Course::class` arrives fully qualified. A file this package cannot parse is
 * a file it has no opinion about, never a failed build.
 */
class Ast
{
    protected ?Parser $parser = null;

    /** @var array<string, list<Node>|null> */
    protected array $parsed = [];

    public function methodNode(ReflectionMethod $method): ?Node\Stmt\ClassMethod
    {
        $class = $this->classNode($method->getDeclaringClass()->getName());

        if ($class === null) {
            return null;
        }

        $found = (new NodeFinder)->findFirst(
            $class,
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $method->getName(),
        );

        return $found instanceof Node\Stmt\ClassMethod ? $found : null;
    }

    /** @param class-string $class */
    public function classNode(string $class): ?Node\Stmt\Class_
    {
        if (! class_exists($class)) {
            return null;
        }

        $statements = $this->statements(new ReflectionClass($class));

        if ($statements === null) {
            return null;
        }

        $found = (new NodeFinder)->findFirst(
            $statements,
            fn (Node $node): bool => $node instanceof Node\Stmt\Class_
                && $node->namespacedName?->toString() === $class,
        );

        return $found instanceof Node\Stmt\Class_ ? $found : null;
    }

    /**
     * Every class a file declares, fully qualified.
     *
     * Read from the file rather than guessed from its path, because mapping a
     * path back to a namespace means assuming the application's PSR-4 roots,
     * and a package is in no position to assume those.
     *
     * @return list<class-string>
     */
    public function classesIn(string $file): array
    {
        $statements = $this->parse($file);

        if ($statements === null) {
            return [];
        }

        $classes = [];

        /** @var list<Node\Stmt\Class_> $found */
        $found = (new NodeFinder)->findInstanceOf($statements, Node\Stmt\Class_::class);

        foreach ($found as $class) {
            $name = $class->namespacedName?->toString();

            if ($name !== null && $this->loads($name)) {
                $classes[] = $name;
            }
        }

        return $classes;
    }

    /**
     * A class whose parent or interface is not installed throws while it
     * autoloads, which is ordinary in an application that dropped an optional
     * package and kept a class built on it. That class can never be a tool,
     * and one such file must not stop discovery of every other.
     *
     * @phpstan-assert-if-true class-string $class
     */
    protected function loads(string $class): bool
    {
        try {
            return class_exists($class);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The file's statements, parsed once per file: compilation walks many
     * actions of the same controller, and several readers walk the same
     * action.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<Node>|null
     */
    protected function statements(ReflectionClass $class): ?array
    {
        $file = $class->getFileName();

        if ($file === false || ! is_readable($file)) {
            return null;
        }

        if (array_key_exists($file, $this->parsed)) {
            return $this->parsed[$file];
        }

        return $this->parsed[$file] = $this->parse($file);
    }

    /** @return list<Node>|null */
    protected function parse(string $file): ?array
    {
        $source = file_get_contents($file);

        if ($source === false) {
            return null;
        }

        try {
            $statements = $this->parser()->parse($source);
        } catch (Throwable) {
            return null;
        }

        if ($statements === null) {
            return null;
        }

        return array_values((new NodeTraverser(new NameResolver))->traverse($statements));
    }

    protected function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory)->createForNewestSupportedVersion();
    }
}
