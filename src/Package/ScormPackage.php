<?php

declare(strict_types=1);

namespace ScormReader\Package;

use JsonSerializable;
use ScormReader\Manifest\Item;
use ScormReader\Manifest\Manifest;
use ScormReader\Validation\ValidationResult;

final class ScormPackage implements JsonSerializable
{
    public function __construct(
        private readonly string $sourcePath,
        private readonly string $packageRoot,
        private readonly Manifest $manifest,
        private readonly ValidationResult $validationResult,
        private readonly bool $extracted = false,
        private readonly ?string $temporaryDirectory = null,
    ) {
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function packageRoot(): string
    {
        return $this->packageRoot;
    }

    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    public function validationResult(): ValidationResult
    {
        return $this->validationResult;
    }

    public function isValid(): bool
    {
        return $this->validationResult->isValid();
    }

    public function wasExtracted(): bool
    {
        return $this->extracted;
    }

    public function temporaryDirectory(): ?string
    {
        return $this->temporaryDirectory;
    }

    /**
     * @return list<Item>
     */
    public function launchableItems(bool $defaultOrganizationOnly = true): array
    {
        return $this->manifest->launchableItems($defaultOrganizationOnly);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeRuntimeMetadata = true): array
    {
        $data = $this->manifest->toArray();

        if (!$includeRuntimeMetadata) {
            return $data;
        }

        $data['sourcePath'] = $this->sourcePath;
        $data['packageRoot'] = $this->packageRoot;
        $data['extracted'] = $this->extracted;
        $data['validation'] = $this->validationResult->toArray();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
