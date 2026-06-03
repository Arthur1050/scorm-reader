<?php

declare(strict_types=1);

namespace ScormReader\Manifest;

use JsonSerializable;
use ScormReader\Version\ScormVersion;

final class Manifest implements JsonSerializable
{
    /** @var array<string, Resource> */
    private readonly array $resourceMap;

    /**
     * @param list<Organization> $organizations
     * @param list<Resource> $resources
     */
    public function __construct(
        private readonly string $identifier,
        private readonly ScormVersion $version,
        private readonly ?string $rawSchemaVersion = null,
        private readonly ?string $title = null,
        private readonly array $organizations = [],
        private readonly array $resources = [],
        private readonly ?string $defaultOrganizationIdentifier = null,
    ) {
        $map = [];

        foreach ($resources as $resource) {
            $map[$resource->identifier()] = $resource;
        }

        $this->resourceMap = $map;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function version(): ScormVersion
    {
        return $this->version;
    }

    public function rawSchemaVersion(): ?string
    {
        return $this->rawSchemaVersion;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    /**
     * @return list<Organization>
     */
    public function organizations(): array
    {
        return $this->organizations;
    }

    /**
     * @return list<Resource>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * @return array<string, Resource>
     */
    public function resourceMap(): array
    {
        return $this->resourceMap;
    }

    public function defaultOrganizationIdentifier(): ?string
    {
        return $this->defaultOrganizationIdentifier;
    }

    public function defaultOrganization(): ?Organization
    {
        if ($this->defaultOrganizationIdentifier !== null) {
            return $this->findOrganization($this->defaultOrganizationIdentifier);
        }

        return $this->organizations[0] ?? null;
    }

    public function findOrganization(string $identifier): ?Organization
    {
        foreach ($this->organizations as $organization) {
            if ($organization->identifier() === $identifier) {
                return $organization;
            }
        }

        return null;
    }

    public function findResource(string $identifier): ?Resource
    {
        return $this->resourceMap[$identifier] ?? null;
    }

    /**
     * @return list<Item>
     */
    public function launchableItems(bool $defaultOrganizationOnly = false): array
    {
        if ($defaultOrganizationOnly) {
            return $this->defaultOrganization()?->launchableItems() ?? [];
        }

        $items = [];

        foreach ($this->organizations as $organization) {
            array_push($items, ...$organization->launchableItems());
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'identifier' => $this->identifier,
            'schemaVersion' => $this->version->value,
            'schemaVersionRaw' => $this->rawSchemaVersion !== $this->version->value ? $this->rawSchemaVersion : null,
            'title' => $this->title,
            'defaultOrganizationIdentifier' => $this->defaultOrganizationIdentifier,
            'organizations' => array_map(static fn (Organization $organization): array => $organization->toArray(), $this->organizations),
            'resources' => array_map(static fn (Resource $resource): array => $resource->toArray(), $this->resources),
            'launchableItems' => array_map(static fn (Item $item): array => $item->toArray(), $this->launchableItems(true)),
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
