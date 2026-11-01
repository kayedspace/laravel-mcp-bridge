<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

use KayedSpace\McpBridge\Enums\WireIn;

/**
 * How one field travels to the code behind a tool: a query parameter, a JSON
 * body key, a route segment, a command option or a constructor argument.
 *
 * @phpstan-type WireArray array{in: string, name: string, delimiter: string|null, flag: bool, position: int|null}
 */
final readonly class Wire
{
    public function __construct(
        public WireIn $in,
        public string $name,
        public ?string $delimiter = null,
        public bool $flag = false,
        public ?int $position = null,
    ) {}

    /** @return WireArray */
    public function toArray(): array
    {
        return [
            'in' => $this->in->value,
            'name' => $this->name,
            'delimiter' => $this->delimiter,
            'flag' => $this->flag,
            'position' => $this->position,
        ];
    }

    /** @param WireArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            in: WireIn::from($data['in']),
            name: $data['name'],
            delimiter: $data['delimiter'],
            flag: $data['flag'],
            position: $data['position'],
        );
    }
}
