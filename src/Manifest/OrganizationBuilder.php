<?php

declare(strict_types=1);

namespace ScormReader\Manifest;

/**
 * Fluent builder for {@see Organization}.
 *
 * Usage:
 *
 * ```php
 * $org = OrganizationBuilder::create('ORG-01', 'Módulo Principal')
 *     ->addItem(ItemBuilder::create('ITEM-INTRO', 'Introdução')->withResource('RES-001'))
 *     ->addItem(
 *         ItemBuilder::create('ITEM-MODULOS', 'Módulos')
 *             ->addChild(ItemBuilder::create('ITEM-M1', 'Módulo 1')->withResource('RES-M1'))
 *             ->addChild(ItemBuilder::create('ITEM-M2', 'Módulo 2')->withResource('RES-M2'))
 *     )
 *     ->build();
 * ```
 */
final class OrganizationBuilder
{
    private ?string $structure = 'hierarchical';
    private bool $isDefault = false;

    /** @var list<ItemBuilder|Item> */
    private array $items = [];

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
     * Adds an item to the organization's top-level item list.
     * Accepts an ItemBuilder (built lazily on build()) or a pre-built Item.
     */
    public function addItem(ItemBuilder|Item $item): self
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * Sets the organization structure attribute (default: 'hierarchical').
     */
    public function withStructure(string $structure): self
    {
        $this->structure = $structure;

        return $this;
    }

    /**
     * Marks this organization as the default organization.
     * When used inside ScormPackageCreator::addOrganization(), this flag is
     * overridden — the default organization is always the one defined in the
     * constructor. Use this flag only when building standalone Manifest objects.
     */
    public function asDefault(): self
    {
        $this->isDefault = true;

        return $this;
    }

    public function build(): Organization
    {
        $items = array_map(
            static fn (ItemBuilder|Item $item): Item => $item instanceof ItemBuilder ? $item->build() : $item,
            $this->items,
        );

        return new Organization(
            identifier: $this->identifier,
            title: $this->title,
            items: array_values($items),
            structure: $this->structure,
            default: $this->isDefault,
        );
    }
}
