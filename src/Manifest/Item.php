<?php

declare(strict_types=1);

namespace ScormReader\Manifest;

use JsonSerializable;

final class Item implements JsonSerializable
{
    private ?Resource $resource = null;

    /**
     * @param list<Item> $children
     */
    public function __construct(
        private readonly string $identifier,
        private readonly ?string $title = null,
        private readonly ?string $identifierRef = null,
        private readonly bool $visible = true,
        private readonly ?string $parameters = null,
        private readonly array $children = [],
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

    public function identifierRef(): ?string
    {
        return $this->identifierRef;
    }

    public function visible(): bool
    {
        return $this->visible;
    }

    public function parameters(): ?string
    {
        return $this->parameters;
    }

    /**
     * @return list<Item>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function setResource(?Resource $resource): void
    {
        $this->resource = $resource;
    }

    public function resource(): ?Resource
    {
        return $this->resource;
    }

    public function isLeaf(): bool
    {
        return $this->children === [];
    }

    public function isLaunchable(): bool
    {
        if (!$this->visible || !$this->resource instanceof Resource || !$this->resource->isSco() || !$this->resource->hasLaunchPath()) {
            return false;
        }

        return $this->resource->hrefExists() !== false;
    }

    /**
     * @return list<Item>
     */
    public function flatten(): array
    {
        $items = [$this];

        foreach ($this->children as $child) {
            array_push($items, ...$child->flatten());
        }

        return $items;
    }

    /**
     * @return list<Item>
     */
    public function launchableItems(): array
    {
        return array_values(array_filter(
            $this->flatten(),
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
            'resourceIdentifier' => $this->identifierRef,
            'visible' => $this->visible,
            'parameters' => $this->parameters,
            'launchable' => $this->isLaunchable(),
            'resource' => $this->resource?->toArray(),
            'items' => array_map(static fn (Item $item): array => $item->toArray(), $this->children),
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
