<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * @phpstan-type CommandTargetArray array{type: 'command', name: string, arguments: list<string>, options: list<string>}
 */
final readonly class CommandTarget implements Target
{
    /**
     * @param  list<string>  $arguments  argument names, in signature order
     * @param  list<string>  $options  option names, without leading dashes
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public array $options = [],
    ) {}

    public function type(): string
    {
        return 'command';
    }

    public function describe(): string
    {
        return "artisan {$this->name}";
    }

    /** @return CommandTargetArray */
    public function toArray(): array
    {
        return [
            'type' => 'command',
            'name' => $this->name,
            'arguments' => $this->arguments,
            'options' => $this->options,
        ];
    }

    /** @param CommandTargetArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            arguments: $data['arguments'],
            options: $data['options'],
        );
    }
}
