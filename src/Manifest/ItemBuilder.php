<?php

declare(strict_types=1);

namespace ScormReader\Manifest;

/**
 * Fluent builder for {@see Item}.
 *
 * Usage:
 *
 * ```php
 * $item = ItemBuilder::create('ITEM-001', 'Aula 1')
 *     ->withResource('RES-001')
 *     ->withParameters('?mode=browse')
 *     ->build();
 *
 * // Nested structure:
 * $parent = ItemBuilder::create('ITEM-MODULE', 'Módulo 1')
 *     ->addChild(ItemBuilder::create('ITEM-A', 'Aula A')->withResource('RES-A'))
 *     ->addChild(ItemBuilder::create('ITEM-B', 'Aula B')->withResource('RES-B'))
 *     ->build();
 * ```
 */
final class ItemBuilder
{
    private ?string $identifierRef = null;
    private bool $visible = true;
    private ?string $parameters = null;

    /** @var list<self> */
    private array $children = [];

    private function __construct(
        private readonly string $identifier,
        private readonly ?string $title = null,
    ) {
    }

    public static function create(string $identifier, ?string $title = null): self
    {
        return new self($identifier, $title);
    }

    /**
     * Associates this item with a resource identifier.
     */
    public function withResource(string $identifierRef): self
    {
        $this->identifierRef = $identifierRef;

        return $this;
    }

    /**
     * Adds a query-string parameter to the item launch URL (e.g. '?mode=browse').
     */
    public function withParameters(string $parameters): self
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Marks this item as hidden in the table of contents (isvisible="false").
     */
    public function hidden(): self
    {
        $this->visible = false;

        return $this;
    }

    /**
     * Adds a child item. Accepts another ItemBuilder (lazy build) or a built Item.
     */
    public function addChild(self $child): self
    {
        $this->children[] = $child;

        return $this;
    }

    public function build(): Item
    {
        return new Item(
            identifier: $this->identifier,
            title: $this->title,
            identifierRef: $this->identifierRef,
            visible: $this->visible,
            parameters: $this->parameters,
            children: array_map(static fn (self $b): Item => $b->build(), $this->children),
        );
    }
}
