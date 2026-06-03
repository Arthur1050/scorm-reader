<?php

declare(strict_types=1);

namespace ScormReader\Validation;

use ScormReader\Manifest\Item;
use ScormReader\Manifest\Manifest;
use ScormReader\Manifest\Resource;
use ScormReader\Package\ImportOptions;
use ScormReader\Security\PathSecurity;

final class ManifestValidator
{
    public function validate(Manifest $manifest, string $packageRoot, ImportOptions $options): ValidationResult
    {
        $result = new ValidationResult();

        if ($manifest->identifier() === '') {
            $result->addError('MANIFEST_IDENTIFIER_MISSING', 'Manifest identifier is required.', 'imsmanifest.xml');
        }

        if ($manifest->rawSchemaVersion() === null) {
            $result->addWarning('SCHEMA_VERSION_MISSING', 'Manifest metadata does not declare schemaversion; version was inferred from namespaces.', 'imsmanifest.xml');
        }

        if ($manifest->organizations() === []) {
            $result->addWarning('ORGANIZATIONS_MISSING', 'Manifest has no organizations section; no activity tree can be built.', 'imsmanifest.xml');
        }

        if ($manifest->defaultOrganizationIdentifier() === null && $manifest->organizations() !== []) {
            $result->addWarning('DEFAULT_ORGANIZATION_MISSING', 'Organizations section does not declare a default organization; the first organization will be used as fallback.', 'organizations');
        }

        if ($manifest->defaultOrganizationIdentifier() !== null && $manifest->defaultOrganization() === null) {
            $result->addError('DEFAULT_ORGANIZATION_NOT_FOUND', 'Default organization identifier does not match any organization.', 'organizations', [
                'default' => $manifest->defaultOrganizationIdentifier(),
            ]);
        }

        if ($manifest->resources() === []) {
            $result->addError('RESOURCES_MISSING', 'Manifest has no resources section.', 'resources');
        }

        foreach ($manifest->resources() as $resource) {
            $this->validateResource($resource, $manifest, $packageRoot, $options, $result);
        }

        foreach ($manifest->organizations() as $organization) {
            foreach ($organization->flattenItems() as $item) {
                $this->validateItem($item, $manifest, $options, $result);
            }
        }

        if ($manifest->organizations() !== [] && $manifest->launchableItems(true) === []) {
            $result->addWarning('NO_LAUNCHABLE_SCO_ITEMS', 'Default organization has no visible launchable SCO item with a valid href.', 'organizations');
        }

        return $result;
    }

    private function validateResource(
        Resource $resource,
        Manifest $manifest,
        string $packageRoot,
        ImportOptions $options,
        ValidationResult $result,
    ): void {
        $context = $resource->identifier() !== '' ? 'resource:' . $resource->identifier() : 'resource';

        if ($resource->identifier() === '') {
            $result->addError('RESOURCE_IDENTIFIER_MISSING', 'Resource identifier is required.', $context);
        }

        if ($resource->type() === null || trim($resource->type()) === '') {
            $result->addError('RESOURCE_TYPE_MISSING', 'Resource type is required.', $context);
        }

        if ($resource->scormType() === null) {
            $result->addError('RESOURCE_SCORM_TYPE_MISSING', 'Resource must declare adlcp:scormtype/adlcp:scormType as sco or asset.', $context);
        } elseif (!$resource->isSco() && !$resource->isAsset()) {
            $result->addError('RESOURCE_SCORM_TYPE_INVALID', 'Resource scormType must be either sco or asset.', $context, [
                'scormType' => $resource->scormType(),
            ]);
        }

        if ($resource->isSco() && !$resource->hasLaunchPath()) {
            $result->addError('SCO_HREF_MISSING', 'SCO resource must define an href launch path.', $context);
            $resource->setHrefExists(false);
        }

        if ($resource->hasLaunchPath()) {
            $this->validateResourceHref($resource, $packageRoot, $options, $result, $context);
        }

        foreach ($resource->files() as $file) {
            $fileContext = $context . ':file:' . $file;

            if (!PathSecurity::validateRelativeUri($file, $options, $result, $fileContext, 'RESOURCE_FILE')) {
                continue;
            }

            PathSecurity::validateFilenameAndExtension($file, $options, $result, $fileContext);
            $filePath = PathSecurity::toFilesystemPath($packageRoot, $file);

            if ($filePath !== null && !is_file($filePath)) {
                $result->addError('RESOURCE_FILE_NOT_FOUND', 'Resource file is listed in manifest but does not exist in the package.', $fileContext, [
                    'file' => $file,
                ]);
            }
        }

        foreach ($resource->dependencies() as $dependencyIdentifier) {
            if ($manifest->findResource($dependencyIdentifier) === null) {
                $result->addError('RESOURCE_DEPENDENCY_NOT_FOUND', 'Resource dependency identifier does not match any resource.', $context, [
                    'dependency' => $dependencyIdentifier,
                ]);
            }
        }
    }

    private function validateResourceHref(
        Resource $resource,
        string $packageRoot,
        ImportOptions $options,
        ValidationResult $result,
        string $context,
    ): void {
        $launchPath = (string) $resource->launchPath();
        $hrefContext = $context . ':href';

        if (!PathSecurity::validateRelativeUri($launchPath, $options, $result, $hrefContext, 'RESOURCE_HREF')) {
            $resource->setHrefExists(false);

            return;
        }

        PathSecurity::validateFilenameAndExtension($launchPath, $options, $result, $hrefContext);

        if (PathSecurity::isExternalUri($launchPath) && $options->allowExternalResources) {
            $resource->setHrefExists(null);

            return;
        }

        $filesystemPath = PathSecurity::toFilesystemPath($packageRoot, $launchPath);

        if ($filesystemPath === null || !is_file($filesystemPath)) {
            $resource->setHrefExists(false);
            $result->addError('RESOURCE_HREF_NOT_FOUND', 'Resource href does not point to an existing file inside the package.', $hrefContext, [
                'href' => $launchPath,
            ]);

            return;
        }

        $resource->setHrefExists(true);

        if ($resource->files() !== [] && !$this->pathInList($launchPath, $resource->files())) {
            $result->addWarning('RESOURCE_HREF_NOT_LISTED_AS_FILE', 'Resource href exists but is not listed in the resource file list.', $hrefContext, [
                'href' => $launchPath,
            ]);
        }
    }

    private function validateItem(Item $item, Manifest $manifest, ImportOptions $options, ValidationResult $result): void
    {
        $context = $item->identifier() !== '' ? 'item:' . $item->identifier() : 'item';

        if ($item->identifier() === '') {
            $result->addError('ITEM_IDENTIFIER_MISSING', 'Item identifier is required.', $context);
        }

        $identifierRef = $item->identifierRef();

        if ($identifierRef === null) {
            if ($item->isLeaf()) {
                $result->addWarning('LEAF_ITEM_WITHOUT_RESOURCE', 'Leaf item does not reference a resource and cannot be launched.', $context);
            }

            return;
        }

        $resource = $manifest->findResource($identifierRef);

        if (!$resource instanceof Resource) {
            $result->addError('ITEM_RESOURCE_NOT_FOUND', 'Item identifierref does not match any resource.', $context, [
                'identifierref' => $identifierRef,
            ]);

            return;
        }

        if ($options->requireScoForLaunchableItems && !$resource->isSco()) {
            $result->addWarning('ITEM_REFERENCES_NON_SCO_RESOURCE', 'Item references a resource that is not a SCO; it will not be returned as launchable.', $context, [
                'identifierref' => $identifierRef,
                'scormType' => $resource->scormType(),
            ]);
        }
    }

    /**
     * @param list<string> $paths
     */
    private function pathInList(string $needle, array $paths): bool
    {
        $normalizedNeedle = $this->normalizeManifestPath($needle);

        foreach ($paths as $path) {
            if ($this->normalizeManifestPath($path) === $normalizedNeedle) {
                return true;
            }
        }

        return false;
    }

    private function normalizeManifestPath(string $path): string
    {
        return ltrim(PathSecurity::pathPart($path), '/');
    }
}
