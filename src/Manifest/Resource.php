<?php

declare(strict_types=1);

namespace ScormReader\Manifest;

use JsonSerializable;

final class Resource implements JsonSerializable
{
    private ?bool $hrefExists = null;

    /**
     * @param list<string> $files
     * @param list<string> $dependencies
     */
    public function __construct(
        private readonly string $identifier,
        private readonly ?string $type = null,
        private readonly ?string $scormType = null,
        private readonly ?string $href = null,
        private readonly ?string $launchPath = null,
        private readonly array $files = [],
        private readonly array $dependencies = [],
        private readonly ?string $xmlBase = null,
    ) {
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function scormType(): ?string
    {
        return $this->scormType !== null ? strtolower($this->scormType) : null;
    }

    public function href(): ?string
    {
        return $this->href;
    }

    public function launchPath(): ?string
    {
        return $this->launchPath;
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return list<string>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    public function xmlBase(): ?string
    {
        return $this->xmlBase;
    }

    public function isSco(): bool
    {
        return $this->scormType() === 'sco';
    }

    public function isAsset(): bool
    {
        return $this->scormType() === 'asset';
    }

    public function hasLaunchPath(): bool
    {
        return is_string($this->launchPath) && trim($this->launchPath) !== '';
    }

    public function setHrefExists(?bool $hrefExists): void
    {
        $this->hrefExists = $hrefExists;
    }

    public function hrefExists(): ?bool
    {
        return $this->hrefExists;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'identifier' => $this->identifier,
            'type' => $this->type,
            'scormType' => $this->scormType(),
            'href' => $this->href,
            'launchPath' => $this->launchPath !== $this->href ? $this->launchPath : null,
            'hrefExists' => $this->hrefExists,
            'xmlBase' => $this->xmlBase,
            'files' => $this->files,
            'dependencies' => $this->dependencies,
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
