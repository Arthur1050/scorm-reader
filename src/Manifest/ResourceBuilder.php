<?php

declare(strict_types=1);

namespace ScormReader\Manifest;

use ScormReader\Security\PathSecurity;

/**
 * Fluent builder for {@see Resource}.
 *
 * Usage:
 *
 * ```php
 * // SCO resource
 * $sco = ResourceBuilder::sco('RES-001', 'aula/index.html')
 *     ->withFile('aula/style.css')
 *     ->withFile('aula/app.js')
 *     ->withDependency('RES-SHARED')
 *     ->build();
 *
 * // Asset resource
 * $asset = ResourceBuilder::asset('RES-SHARED')
 *     ->withFile('shared/common.js')
 *     ->withFile('shared/common.css')
 *     ->build();
 *
 * // SCO with xml:base
 * $sco = ResourceBuilder::sco('RES-002', 'index.html')
 *     ->withXmlBase('aula2/')
 *     ->build();
 * ```
 */
final class ResourceBuilder
{
    private ?string $type = 'webcontent';
    private ?string $scormType = null;
    private ?string $href = null;
    private ?string $xmlBase = null;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $dependencies = [];

    private function __construct(
        private readonly string $identifier,
    ) {
    }

    /**
     * Creates a SCO resource with the given launch href.
     * The href is automatically added to the file list.
     */
    public static function sco(string $identifier, string $href): self
    {
        $builder = new self($identifier);
        $builder->scormType = 'sco';
        $builder->href = $href;

        if ($href !== '' && !in_array($href, $builder->files, true)) {
            $builder->files[] = $href;
        }

        return $builder;
    }

    /**
     * Creates an asset resource (no launch path).
     */
    public static function asset(string $identifier): self
    {
        $builder = new self($identifier);
        $builder->scormType = 'asset';

        return $builder;
    }

    /**
     * Overrides the IMS resource type attribute (default: 'webcontent').
     */
    public function withType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Declares a file within the resource's file list (manifest entry only).
     */
    public function withFile(string $href): self
    {
        if (!in_array($href, $this->files, true)) {
            $this->files[] = $href;
        }

        return $this;
    }

    /**
     * Adds a dependency to another resource by its identifier.
     */
    public function withDependency(string $identifierRef): self
    {
        if (!in_array($identifierRef, $this->dependencies, true)) {
            $this->dependencies[] = $identifierRef;
        }

        return $this;
    }

    /**
     * Sets the xml:base attribute on the resource element.
     * The launch path will be computed as xmlBase + href.
     */
    public function withXmlBase(string $xmlBase): self
    {
        $this->xmlBase = $xmlBase;

        return $this;
    }

    public function build(): Resource
    {
        $launchPath = $this->href !== null
            ? PathSecurity::joinUriPaths($this->xmlBase ?? '', $this->href)
            : null;

        return new Resource(
            identifier: $this->identifier,
            type: $this->type,
            scormType: $this->scormType,
            href: $this->href,
            launchPath: ($launchPath !== '' ? $launchPath : null),
            files: array_values(array_unique($this->files)),
            dependencies: array_values(array_unique($this->dependencies)),
            xmlBase: $this->xmlBase,
        );
    }
}
