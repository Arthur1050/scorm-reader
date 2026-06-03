<?php

declare(strict_types=1);

namespace ScormReader\Manifest;

use DOMDocument;
use DOMElement;
use LibXMLError;
use ScormReader\Exception\InvalidScormPackageException;
use ScormReader\Exception\ManifestNotFoundException;
use ScormReader\Security\PathSecurity;
use ScormReader\Validation\ValidationResult;
use ScormReader\Version\VersionDetector;

final class ManifestParser
{
    private VersionDetector $versionDetector;

    public function __construct(?VersionDetector $versionDetector = null)
    {
        $this->versionDetector = $versionDetector ?? new VersionDetector();
    }

    public function parse(string $manifestPath, ?ValidationResult $validation = null): Manifest
    {
        $validation ??= new ValidationResult();

        if (!is_file($manifestPath)) {
            throw new ManifestNotFoundException('imsmanifest.xml was not found at the package root.');
        }

        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->load($manifestPath, LIBXML_NONET | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new InvalidScormPackageException('imsmanifest.xml is not well-formed XML.', $this->xmlErrorsToValidationResult($errors, $manifestPath));
        }

        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->localName !== 'manifest') {
            throw new InvalidScormPackageException('imsmanifest.xml root element must be <manifest>.');
        }

        $version = $this->versionDetector->detect($document);
        $rawSchemaVersion = $this->versionDetector->rawSchemaVersion($document);
        $identifier = trim($root->getAttribute('identifier'));
        $rootBase = $this->xmlBase($root);

        $resourcesElement = $this->firstChildElement($root, 'resources');
        $resourcesBase = PathSecurity::joinUriPaths($rootBase, $this->xmlBase($resourcesElement));
        [$resources, $resourceMap] = $resourcesElement instanceof DOMElement
            ? $this->parseResources($resourcesElement, $resourcesBase, $validation)
            : [[], []];

        $organizationsElement = $this->firstChildElement($root, 'organizations');
        $defaultOrganizationIdentifier = $organizationsElement instanceof DOMElement
            ? $this->nullableAttribute($organizationsElement, 'default')
            : null;

        $organizations = $organizationsElement instanceof DOMElement
            ? $this->parseOrganizations($organizationsElement, $defaultOrganizationIdentifier, $resourceMap)
            : [];

        $title = $this->resolveManifestTitle($organizations);

        return new Manifest(
            identifier: $identifier,
            version: $version,
            rawSchemaVersion: $rawSchemaVersion,
            title: $title,
            organizations: $organizations,
            resources: $resources,
            defaultOrganizationIdentifier: $defaultOrganizationIdentifier,
        );
    }

    /**
     * @return array{0: list<Resource>, 1: array<string, Resource>}
     */
    private function parseResources(DOMElement $resourcesElement, string $resourcesBase, ValidationResult $validation): array
    {
        $resources = [];
        $resourceMap = [];

        foreach ($this->childElements($resourcesElement, 'resource') as $resourceElement) {
            $identifier = trim($resourceElement->getAttribute('identifier'));

            if ($identifier !== '' && isset($resourceMap[$identifier])) {
                $validation->addError('DUPLICATE_RESOURCE_IDENTIFIER', 'Resource identifier must be unique inside imsmanifest.xml.', 'resource:' . $identifier);
                continue;
            }

            $href = $this->nullableAttribute($resourceElement, 'href');
            $resourceBase = PathSecurity::joinUriPaths($resourcesBase, $this->xmlBase($resourceElement));
            $launchPath = $href !== null ? PathSecurity::joinUriPaths($resourceBase, $href) : null;
            $files = [];
            $dependencies = [];

            foreach ($this->childElements($resourceElement, 'file') as $fileElement) {
                $fileHref = $this->nullableAttribute($fileElement, 'href');

                if ($fileHref !== null) {
                    $files[] = PathSecurity::joinUriPaths($resourceBase, $fileHref);
                }
            }

            foreach ($this->childElements($resourceElement, 'dependency') as $dependencyElement) {
                $identifierRef = $this->nullableAttribute($dependencyElement, 'identifierref');

                if ($identifierRef !== null) {
                    $dependencies[] = $identifierRef;
                }
            }

            $resource = new Resource(
                identifier: $identifier,
                type: $this->nullableAttribute($resourceElement, 'type'),
                scormType: $this->attributeByLocalName($resourceElement, ['scormType', 'scormtype']),
                href: $href,
                launchPath: $launchPath,
                files: array_values(array_unique($files)),
                dependencies: array_values(array_unique($dependencies)),
                xmlBase: $resourceBase !== '' ? $resourceBase : null,
            );

            $resources[] = $resource;

            if ($identifier !== '') {
                $resourceMap[$identifier] = $resource;
            }
        }

        return [$resources, $resourceMap];
    }

    /**
     * @param array<string, Resource> $resourceMap
     * @return list<Organization>
     */
    private function parseOrganizations(DOMElement $organizationsElement, ?string $defaultIdentifier, array $resourceMap): array
    {
        $organizations = [];

        foreach ($this->childElements($organizationsElement, 'organization') as $organizationElement) {
            $identifier = trim($organizationElement->getAttribute('identifier'));
            $items = [];

            foreach ($this->childElements($organizationElement, 'item') as $itemElement) {
                $items[] = $this->parseItem($itemElement, $resourceMap);
            }

            $organizations[] = new Organization(
                identifier: $identifier,
                title: $this->childText($organizationElement, 'title'),
                items: $items,
                structure: $this->nullableAttribute($organizationElement, 'structure'),
                default: $defaultIdentifier !== null && $identifier === $defaultIdentifier,
            );
        }

        return $organizations;
    }

    /**
     * @param array<string, Resource> $resourceMap
     */
    private function parseItem(DOMElement $itemElement, array $resourceMap): Item
    {
        $children = [];

        foreach ($this->childElements($itemElement, 'item') as $childElement) {
            $children[] = $this->parseItem($childElement, $resourceMap);
        }

        $identifierRef = $this->nullableAttribute($itemElement, 'identifierref');
        $item = new Item(
            identifier: trim($itemElement->getAttribute('identifier')),
            title: $this->childText($itemElement, 'title'),
            identifierRef: $identifierRef,
            visible: $this->isVisible($itemElement),
            parameters: $this->nullableAttribute($itemElement, 'parameters'),
            children: $children,
        );

        if ($identifierRef !== null) {
            $item->setResource($resourceMap[$identifierRef] ?? null);
        }

        return $item;
    }

    /**
     * @param list<Organization> $organizations
     */
    private function resolveManifestTitle(array $organizations): ?string
    {
        foreach ($organizations as $organization) {
            if ($organization->isDefault() && $organization->title() !== null) {
                return $organization->title();
            }
        }

        return ($organizations[0] ?? null)?->title();
    }

    /**
     * @return list<DOMElement>
     */
    private function childElements(DOMElement $element, string $localName): array
    {
        $elements = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    private function firstChildElement(DOMElement $element, string $localName): ?DOMElement
    {
        foreach ($this->childElements($element, $localName) as $child) {
            return $child;
        }

        return null;
    }

    private function childText(DOMElement $element, string $localName): ?string
    {
        $child = $this->firstChildElement($element, $localName);
        $value = $child instanceof DOMElement ? trim($child->textContent) : '';

        return $value !== '' ? $value : null;
    }

    private function nullableAttribute(DOMElement $element, string $name): ?string
    {
        $value = trim($element->getAttribute($name));

        return $value !== '' ? $value : null;
    }

    /**
     * @param list<string> $localNames
     */
    private function attributeByLocalName(DOMElement $element, array $localNames): ?string
    {
        $lookup = array_map('strtolower', $localNames);

        foreach ($element->attributes ?? [] as $attribute) {
            if (in_array(strtolower($attribute->localName), $lookup, true)) {
                $value = trim($attribute->value);

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    private function xmlBase(?DOMElement $element): ?string
    {
        if (!$element instanceof DOMElement) {
            return null;
        }

        $value = trim($element->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base'));

        return $value !== '' ? $value : null;
    }

    private function isVisible(DOMElement $itemElement): bool
    {
        $value = strtolower(trim($itemElement->getAttribute('isvisible')));

        return !in_array($value, ['false', '0', 'no'], true);
    }

    /**
     * @param list<LibXMLError> $errors
     */
    private function xmlErrorsToValidationResult(array $errors, string $path): ValidationResult
    {
        $result = new ValidationResult();

        foreach ($errors as $error) {
            $result->addError('XML_PARSE_ERROR', trim($error->message), $path, [
                'line' => $error->line,
                'column' => $error->column,
            ]);
        }

        return $result;
    }
}
