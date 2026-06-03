<?php

declare(strict_types=1);

namespace ScormReader\Manifest;

use DOMDocument;
use DOMElement;
use ScormReader\Security\PathSecurity;
use ScormReader\Version\ScormVersion;

final class ManifestBuilder
{
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';
    private const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';
    private const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';

    public function build(Manifest $manifest): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $namespaces = $this->namespaces($manifest->version());
        $root = $document->createElementNS($namespaces['content'], 'manifest');
        $document->appendChild($root);

        $root->setAttribute('identifier', $manifest->identifier());
        $root->setAttribute('version', $manifest->version()->is2004() ? '1.3' : '1.0');
        $root->setAttributeNS(self::XMLNS_NS, 'xmlns:adlcp', $namespaces['adlcp']);
        $root->setAttributeNS(self::XMLNS_NS, 'xmlns:xsi', self::XSI_NS);
        $root->setAttributeNS(self::XSI_NS, 'xsi:schemaLocation', $namespaces['schemaLocation']);

        $root->appendChild($this->metadataElement($document, $manifest, $namespaces['content']));
        $root->appendChild($this->organizationsElement($document, $manifest, $namespaces['content']));
        $root->appendChild($this->resourcesElement($document, $manifest, $namespaces['content'], $namespaces['adlcp']));

        $xml = $document->saveXML();

        return is_string($xml) ? $xml : '';
    }

    /**
     * @return array{content: string, adlcp: string, schemaLocation: string}
     */
    private function namespaces(ScormVersion $version): array
    {
        if ($version->is2004()) {
            return [
                'content' => 'http://www.imsglobal.org/xsd/imscp_v1p1',
                'adlcp' => 'http://www.adlnet.org/xsd/adlcp_v1p3',
                'schemaLocation' => 'http://www.imsglobal.org/xsd/imscp_v1p1 imscp_v1p1.xsd http://www.adlnet.org/xsd/adlcp_v1p3 adlcp_v1p3.xsd',
            ];
        }

        return [
            'content' => 'http://www.imsproject.org/xsd/imscp_rootv1p1p2',
            'adlcp' => 'http://www.adlnet.org/xsd/adlcp_rootv1p2',
            'schemaLocation' => 'http://www.imsproject.org/xsd/imscp_rootv1p1p2 imscp_rootv1p1p2.xsd http://www.adlnet.org/xsd/adlcp_rootv1p2 adlcp_rootv1p2.xsd',
        ];
    }

    private function metadataElement(DOMDocument $document, Manifest $manifest, string $namespace): DOMElement
    {
        $metadata = $document->createElementNS($namespace, 'metadata');
        $metadata->appendChild($document->createElementNS($namespace, 'schema', 'ADL SCORM'));
        $metadata->appendChild($document->createElementNS(
            $namespace,
            'schemaversion',
            $this->schemaVersion($manifest),
        ));

        return $metadata;
    }

    private function organizationsElement(DOMDocument $document, Manifest $manifest, string $namespace): DOMElement
    {
        $organizations = $document->createElementNS($namespace, 'organizations');

        if ($manifest->defaultOrganizationIdentifier() !== null) {
            $organizations->setAttribute('default', $manifest->defaultOrganizationIdentifier());
        }

        foreach ($manifest->organizations() as $organization) {
            $organizationElement = $document->createElementNS($namespace, 'organization');
            $organizationElement->setAttribute('identifier', $organization->identifier());

            if ($organization->structure() !== null) {
                $organizationElement->setAttribute('structure', $organization->structure());
            }

            if ($organization->title() !== null) {
                $organizationElement->appendChild($document->createElementNS($namespace, 'title', $organization->title()));
            }

            foreach ($organization->items() as $item) {
                $organizationElement->appendChild($this->itemElement($document, $item, $namespace));
            }

            $organizations->appendChild($organizationElement);
        }

        return $organizations;
    }

    private function itemElement(DOMDocument $document, Item $item, string $namespace): DOMElement
    {
        $itemElement = $document->createElementNS($namespace, 'item');
        $itemElement->setAttribute('identifier', $item->identifier());

        if ($item->identifierRef() !== null) {
            $itemElement->setAttribute('identifierref', $item->identifierRef());
        }

        if (!$item->visible()) {
            $itemElement->setAttribute('isvisible', 'false');
        }

        if ($item->parameters() !== null) {
            $itemElement->setAttribute('parameters', $item->parameters());
        }

        if ($item->title() !== null) {
            $itemElement->appendChild($document->createElementNS($namespace, 'title', $item->title()));
        }

        foreach ($item->children() as $child) {
            $itemElement->appendChild($this->itemElement($document, $child, $namespace));
        }

        return $itemElement;
    }

    private function resourcesElement(DOMDocument $document, Manifest $manifest, string $namespace, string $adlcpNamespace): DOMElement
    {
        $resources = $document->createElementNS($namespace, 'resources');

        foreach ($manifest->resources() as $resource) {
            $resourceElement = $document->createElementNS($namespace, 'resource');
            $resourceElement->setAttribute('identifier', $resource->identifier());

            if ($resource->type() !== null) {
                $resourceElement->setAttribute('type', $resource->type());
            }

            if ($resource->scormType() !== null) {
                $attributeName = $manifest->version()->is2004() ? 'adlcp:scormType' : 'adlcp:scormtype';
                $resourceElement->setAttributeNS($adlcpNamespace, $attributeName, $resource->scormType());
            }

            if ($resource->xmlBase() !== null) {
                $resourceElement->setAttributeNS(self::XML_NS, 'xml:base', $resource->xmlBase());
            }

            $href = $resource->href() ?? $this->pathRelativeToBase($resource->launchPath(), $resource->xmlBase());
            if ($href !== null) {
                $resourceElement->setAttribute('href', $href);
            }

            foreach ($resource->files() as $file) {
                $fileElement = $document->createElementNS($namespace, 'file');
                $fileElement->setAttribute('href', $this->pathRelativeToBase($file, $resource->xmlBase()) ?? $file);
                $resourceElement->appendChild($fileElement);
            }

            foreach ($resource->dependencies() as $dependency) {
                $dependencyElement = $document->createElementNS($namespace, 'dependency');
                $dependencyElement->setAttribute('identifierref', $dependency);
                $resourceElement->appendChild($dependencyElement);
            }

            $resources->appendChild($resourceElement);
        }

        return $resources;
    }

    private function schemaVersion(Manifest $manifest): string
    {
        if ($manifest->rawSchemaVersion() !== null) {
            return $manifest->rawSchemaVersion();
        }

        return $manifest->version()->is2004() ? '2004 4th Edition' : '1.2';
    }

    private function pathRelativeToBase(?string $path, ?string $base): ?string
    {
        if ($path === null) {
            return null;
        }

        if ($base === null || $base === '' || PathSecurity::isExternalUri($path)) {
            return $path;
        }

        $normalizedPath = ltrim(PathSecurity::normalizeSlashes($path), '/');
        $normalizedBase = trim(PathSecurity::normalizeSlashes($base), '/');

        if ($normalizedBase !== '' && str_starts_with($normalizedPath, $normalizedBase . '/')) {
            return substr($normalizedPath, strlen($normalizedBase) + 1);
        }

        return $path;
    }
}
