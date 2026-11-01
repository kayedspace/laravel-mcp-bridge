<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Lint;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Support\Ast;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Finds the interactive prompts in a command that is about to be exposed as a
 * tool.
 *
 * An agent cannot answer a question. The executor always passes
 * `--no-interaction`, which makes Laravel's prompts return their default or
 * fail outright, so a command that asks for something it has no option for is
 * a command whose tool will misbehave in a way nothing else catches. Better to
 * say so at lint time, with the line number, than to let an agent discover it.
 */
class PromptScanner
{
    /** Laravel's own prompt helpers, all of which need a human. */
    protected const PROMPTS = [
        'ask', 'askWithCompletion', 'anticipate', 'secret', 'confirm', 'choice',
    ];

    public function __construct(protected Ast $ast) {}

    /**
     * @param  class-string  $class
     * @return list<array{method: string, line: int}>
     */
    public function scan(string $class): array
    {
        if (! class_exists($class) || ! is_subclass_of($class, Command::class)) {
            return [];
        }

        $node = $this->ast->classNode($class);

        if ($node === null) {
            return [];
        }

        $prompts = [];

        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = (new NodeFinder)->findInstanceOf($node, Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier || ! $call->var instanceof Node\Expr\Variable) {
                continue;
            }

            $method = $call->name->toString();

            // Only $this->ask(): a prompt on some other object is not this
            // command asking the operator anything.
            if ($call->var->name !== 'this' || ! in_array($method, self::PROMPTS, true)) {
                continue;
            }

            $prompts[] = ['method' => $method, 'line' => $call->getStartLine()];
        }

        return $prompts;
    }
}
