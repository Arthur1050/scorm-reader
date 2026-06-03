<?php

declare(strict_types=1);

namespace ScormReader\Manifest;

use JsonSerializable;

final class Organization implements JsonSerializable
{
    /**
     * @param list<Item> $items
     */
    public function __construct(
        private readonly string $identifier,
        private readonly ?string $title = null,
        private readonly array $items = [],
        private readonly ?string $structure = null,
        private readonly bool $default = false,
    ) {
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    /**
     * @return list<Item>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function structure(): ?string
    {
        return $this->structure;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    /**
     * @return list<Item>
     */
    public function flattenItems(): array
    {
        $items = [];

        foreach ($this->items as $item) {
            array_push($items, ...$item->flatten());
        }

        return $items;
    }

    /**
     * @return list<Item>
     */
    public function launchableItems(): array
    {
        return array_values(array_filter(
            $this->flattenItems(),
            static fn (Item $item): bool => $item->isLaunchable(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'identifier' => $this->identifier,
            'title' => $this->title,
            'default' => $this->default,
            'structure' => $this->structure,
            'items' => array_map(static fn (Item $item): array => $item->toArray(), $this->items),
        ];

        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
