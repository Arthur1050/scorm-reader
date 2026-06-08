<?php

declare(strict_types=1);

namespace ScormReader\Package;

use ScormReader\Exception\InvalidScormPackageException;
use ScormReader\Manifest\Item;
use ScormReader\Manifest\Manifest;
use ScormReader\Manifest\Organization;
use ScormReader\Manifest\OrganizationBuilder;
use ScormReader\Manifest\Resource;
use ScormReader\Validation\ValidationResult;
use ScormReader\Version\ScormVersion;

final class ScormPackageCreator
{
    /** @var list<Item> */
    private array $items = [];

    /** @var list<Resource> */
    private array $resources = [];

    /** @var array<string, PackageFile> */
    private array $files = [];

    /** @var list<Organization> */
    private array $extraOrganizations = [];

    private function __construct(
        private readonly string $title,
        private readonly ScormVersion $version,
        private readonly string $manifestIdentifier,
        private readonly string $organizationIdentifier,
    ) {
        $this->withScormApiWrapper();
    }

    public static function create(
        string $title,
        ScormVersion $version = ScormVersion::SCORM_2004,
        ?string $manifestIdentifier = null,
        ?string $organizationIdentifier = null,
    ): self {
        $slug = self::identifierSlug($title);

        return new self(
            title: $title,
            version: $version,
            manifestIdentifier: $manifestIdentifier ?? 'MANIFEST-' . $slug,
            organizationIdentifier: $organizationIdentifier ?? 'ORG-' . $slug,
        );
    }

    public static function scorm12(string $title, ?string $manifestIdentifier = null, ?string $organizationIdentifier = null): self
    {
        return self::create($title, ScormVersion::SCORM_12, $manifestIdentifier, $organizationIdentifier);
    }

    public static function scorm2004(string $title, ?string $manifestIdentifier = null, ?string $organizationIdentifier = null): self
    {
        return self::create($title, ScormVersion::SCORM_2004, $manifestIdentifier, $organizationIdentifier);
    }

    /**
     * Adds a visible SCO item to the default organization.
     *
     * The $files array accepts either declared manifest paths:
     *   ['css/style.css']
     * or target => source pairs that are also copied to the exported package:
     *   ['css/style.css' => __DIR__ . '/style.css']
     *
     * @param array<int|string, string> $files
     * @param list<string> $dependencies
     */
    public function addSco(
        string $title,
        string $launchPath,
        ?string $sourcePath = null,
        ?string $identifier = null,
        ?string $itemIdentifier = null,
        array $files = [],
        bool $visible = true,
        ?string $parameters = null,
        array $dependencies = [],
    ): self {
        $sequence = count($this->resources) + 1;
        $resourceIdentifier = $identifier ?? sprintf('RES-%03d', $sequence);
        $itemIdentifier ??= sprintf('ITEM-%03d', count($this->items) + 1);
        $resourceFiles = [$launchPath];

        if ($sourcePath !== null) {
            $this->addFile(PackageFile::fromPath($sourcePath, $launchPath));
        }

        foreach ($files as $targetPath => $file) {
            if (is_string($targetPath)) {
                $resourceFiles[] = $targetPath;
                $this->addFile(PackageFile::fromPath($file, $targetPath));
                continue;
            }

            $resourceFiles[] = $file;
        }

        foreach (array_keys($this->files) as $filePath) {
            if (str_ends_with($filePath, 'scorm-api.js')) {
                $resourceFiles[] = $filePath;
            }
        }

        $resource = new Resource(
            identifier: $resourceIdentifier,
            type: 'webcontent',
            scormType: 'sco',
            href: $launchPath,
            launchPath: $launchPath,
            files: array_values(array_unique($resourceFiles)),
            dependencies: array_values(array_unique($dependencies)),
        );
        $resource->setHrefExists(null);

        $item = new Item(
            identifier: $itemIdentifier,
            title: $title,
            identifierRef: $resourceIdentifier,
            visible: $visible,
            parameters: $parameters,
        );
        $item->setResource($resource);

        $this->resources[] = $resource;
        $this->items[] = $item;

        return $this;
    }

    /**
     * Adds a SCO item and writes its launch file from an in-memory string.
     *
     * @param array<int|string, string> $files
     * @param list<string> $dependencies
     */
    public function addScoContent(
        string $title,
        string $launchPath,
        string $contents,
        ?string $identifier = null,
        ?string $itemIdentifier = null,
        array $files = [],
        bool $visible = true,
        ?string $parameters = null,
        array $dependencies = [],
    ): self {
        $this->addSco(
            title: $title,
            launchPath: $launchPath,
            identifier: $identifier,
            itemIdentifier: $itemIdentifier,
            files: $files,
            visible: $visible,
            parameters: $parameters,
            dependencies: $dependencies,
        );

        return $this->addFileContent($launchPath, $contents);
    }

    /**
     * @param array<int|string, string> $files
     * @param list<string> $dependencies
     */
    public function addAsset(string $identifier, array $files, ?string $xmlBase = null, array $dependencies = []): self
    {
        $resourceFiles = [];

        foreach ($files as $targetPath => $file) {
            if (is_string($targetPath)) {
                $resourceFiles[] = $targetPath;
                $this->addFile(PackageFile::fromPath($file, $targetPath));
                continue;
            }

            $resourceFiles[] = $file;
        }

        $this->resources[] = new Resource(
            identifier: $identifier,
            type: 'webcontent',
            scormType: 'asset',
            files: array_values(array_unique($resourceFiles)),
            dependencies: array_values(array_unique($dependencies)),
            xmlBase: $xmlBase,
        );

        return $this;
    }

    public function addItem(Item $item): self
    {
        $this->items[] = $item;

        return $this;
    }

    public function addResource(Resource $resource): self
    {
        $this->resources[] = $resource;

        return $this;
    }

    /**
     * Adds an extra organization to the package.
     *
     * The organization added here will appear in the manifest after the default
     * organization. The default organization is always the one defined by the
     * constructor title/identifier — it cannot be replaced via this method.
     *
     * Use {@see OrganizationBuilder} to compose complex trees:
     *
     * ```php
     * $creator->addOrganization(
     *     OrganizationBuilder::create('ORG-EXTRA', 'Módulo Extra')
     *         ->addItem(ItemBuilder::create('ITEM-E1', 'Aula Extra')->withResource('RES-E1'))
     * );
     * ```
     */
    public function addOrganization(Organization|OrganizationBuilder $organization): self
    {
        $this->extraOrganizations[] = $organization instanceof OrganizationBuilder
            ? $organization->build()
            : $organization;

        return $this;
    }

    public function addFile(PackageFile $file): self
    {
        $this->files[$file->targetPath()] = $file;

        return $this;
    }

    public function addFileFromPath(string $sourcePath, ?string $targetPath = null): self
    {
        return $this->addFile(PackageFile::fromPath($sourcePath, $targetPath));
    }

    public function addFileContent(string $targetPath, string $contents): self
    {
        return $this->addFile(PackageFile::fromString($targetPath, $contents));
    }

    public function buildManifest(): Manifest
    {
        $resources = $this->deduplicateResources($this->resources);
        $resourceMap = [];

        foreach ($resources as $resource) {
            $resourceMap[$resource->identifier()] = $resource;
        }

        foreach ($this->items as $item) {
            $this->resolveItemResource($item, $resourceMap);
        }

        $defaultOrganization = new Organization(
            identifier: $this->organizationIdentifier,
            title: $this->title,
            items: $this->items,
            structure: 'hierarchical',
            default: true,
        );

        // Resolve resources for items in extra organizations.
        foreach ($this->extraOrganizations as $extraOrg) {
            foreach ($extraOrg->flattenItems() as $item) {
                $this->resolveItemResource($item, $resourceMap);
            }
        }

        $allOrganizations = array_merge([$defaultOrganization], $this->extraOrganizations);

        return new Manifest(
            identifier: $this->manifestIdentifier,
            version: $this->version,
            rawSchemaVersion: $this->version->is2004() ? '2004 4th Edition' : '1.2',
            title: $this->title,
            organizations: $allOrganizations,
            resources: $resources,
            defaultOrganizationIdentifier: $this->organizationIdentifier,
        );
    }

    public function validate(?ImportOptions $options = null): ValidationResult
    {
        $options ??= new ImportOptions();
        $result = new ValidationResult();
        $totalBytes = 0;

        if (trim($this->title) === '') {
            $result->addError('PACKAGE_TITLE_MISSING', 'Package title is required.', 'creator');
        }

        if ($this->items === []) {
            $result->addWarning('PACKAGE_HAS_NO_ITEMS', 'Package has no organization items.', 'creator');
        }

        if ($this->resources === []) {
            $result->addError('PACKAGE_HAS_NO_RESOURCES', 'Package has no resources.', 'creator');
        }

        foreach ($this->files as $file) {
            $file->validate($options, $result, $file->targetPath());
            $totalBytes += $file->sourcePath() !== null
                ? (int) filesize($file->sourcePath())
                : strlen((string) $file->contents());
        }

        if (count($this->files) > $options->maxFileCount) {
            $result->addError('PACKAGE_TOO_MANY_FILES', 'Package exceeds the configured file count limit.', 'creator', [
                'fileCount' => count($this->files),
                'limit' => $options->maxFileCount,
            ]);
        }

        if ($totalBytes > $options->maxTotalBytes) {
            $result->addError('PACKAGE_TOO_LARGE', 'Package exceeds the configured total size limit.', 'creator', [
                'totalBytes' => $totalBytes,
                'limit' => $options->maxTotalBytes,
            ]);
        }

        return $result;
    }

    public function exportToDirectory(string $destinationDirectory, ?ExportOptions $options = null): ScormPackage
    {
        return (new ScormPackageExporter())->exportCreatedPackageToDirectory($this, $destinationDirectory, $options);
    }

    public function exportToZip(string $destinationZipPath, ?string $workDirectory = null, ?ExportOptions $options = null): ScormPackage
    {
        return (new ScormPackageExporter())->exportCreatedPackageToZip($this, $destinationZipPath, $workDirectory, $options);
    }

    /**
     * @return list<PackageFile>
     */
    public function files(): array
    {
        return array_values($this->files);
    }

    /**
     * @param array<string, Resource> $resourceMap
     */
    private function resolveItemResource(Item $item, array $resourceMap): void
    {
        if ($item->identifierRef() !== null) {
            $item->setResource($resourceMap[$item->identifierRef()] ?? null);
        }

        foreach ($item->children() as $child) {
            $this->resolveItemResource($child, $resourceMap);
        }
    }

    /**
     * @param list<Resource> $resources
     * @return list<Resource>
     */
    private function deduplicateResources(array $resources): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($resources as $resource) {
            if ($resource->identifier() === '') {
                $deduplicated[] = $resource;
                continue;
            }

            if (isset($seen[$resource->identifier()])) {
                throw new InvalidScormPackageException('Resource identifier must be unique: ' . $resource->identifier());
            }

            $seen[$resource->identifier()] = true;
            $deduplicated[] = $resource;
        }

        return $deduplicated;
    }

    private static function identifierSlug(string $value): string
    {
        $slug = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '-', trim($value)));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : bin2hex(random_bytes(4));
    }

    /**
     * Auto-generates a standard SCORM API wrapper Javascript file in the package.
     * The wrapper exposes functions like LMSInitialize/Initialize depending on version.
     */
    public function withScormApiWrapper(string $targetPath = 'js/scorm-api.js'): self
    {
        foreach ($this->files as $key => $file) {
            if (str_ends_with($key, 'scorm-api.js')) {
                unset($this->files[$key]);
            }
        }

        $contents = $this->version->is2004()
            ? $this->getScorm2004ApiJs()
            : $this->getScorm12ApiJs();

        return $this->addFileContent($targetPath, $contents);
    }

    private function getScorm12ApiJs(): string
    {
        return <<<JS
/**
 * SCORM 1.2 API Wrapper
 * Automatically generated by SCORM Reader library.
 */
(function() {
    'use strict';

    var API = null;
    var findAPITries = 0;

    function findAPI(win) {
        while ((win.API == null) && (win.parent != null) && (win.parent != win)) {
            findAPITries++;
            if (findAPITries > 500) {
                console.error("SCORM: Error finding API - too deeply nested.");
                return null;
            }
            win = win.parent;
        }
        return win.API;
    }

    function getAPI() {
        var theAPI = findAPI(window);
        if ((theAPI == null) && (window.opener != null) && (typeof(window.opener) != "undefined")) {
            theAPI = findAPI(window.opener);
        }
        return theAPI;
    }

    function initAPI() {
        if (API == null) {
            API = getAPI();
        }
        return API;
    }

    window.LMSInitialize = function(param) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: LMSInitialize failed - API adapter not found.");
            return "false";
        }
        return API.LMSInitialize(param || "").toString();
    };

    window.LMSFinish = function(param) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: LMSFinish failed - API adapter not found.");
            return "false";
        }
        return API.LMSFinish(param || "").toString();
    };

    window.LMSGetValue = function(element) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: LMSGetValue failed - API adapter not found.");
            return "";
        }
        return API.LMSGetValue(element).toString();
    };

    window.LMSSetValue = function(element, value) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: LMSSetValue failed - API adapter not found.");
            return "false";
        }
        return API.LMSSetValue(element, value).toString();
    };

    window.LMSCommit = function(param) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: LMSCommit failed - API adapter not found.");
            return "false";
        }
        return API.LMSCommit(param || "").toString();
    };

    window.LMSGetLastError = function() {
        initAPI();
        if (API == null) return "301"; // API not initialized
        return API.LMSGetLastError().toString();
    };

    window.LMSGetErrorString = function(errorCode) {
        initAPI();
        if (API == null) return "API adapter not found";
        return API.LMSGetErrorString(errorCode).toString();
    };

    window.LMSGetDiagnostic = function(errorCode) {
        initAPI();
        if (API == null) return "API adapter not found";
        return API.LMSGetDiagnostic(errorCode).toString();
    };
})();
JS;
    }

    private function getScorm2004ApiJs(): string
    {
        return <<<JS
/**
 * SCORM 2004 API Wrapper
 * Automatically generated by SCORM Reader library.
 */
(function() {
    'use strict';

    var API = null;
    var findAPITries = 0;

    function findAPI(win) {
        while ((win.API_1484_11 == null) && (win.parent != null) && (win.parent != win)) {
            findAPITries++;
            if (findAPITries > 500) {
                console.error("SCORM: Error finding API_1484_11 - too deeply nested.");
                return null;
            }
            win = win.parent;
        }
        return win.API_1484_11;
    }

    function getAPI() {
        var theAPI = findAPI(window);
        if ((theAPI == null) && (window.opener != null) && (typeof(window.opener) != "undefined")) {
            theAPI = findAPI(window.opener);
        }
        return theAPI;
    }

    function initAPI() {
        if (API == null) {
            API = getAPI();
        }
        return API;
    }

    window.Initialize = function(param) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: Initialize failed - API adapter not found.");
            return "false";
        }
        return API.Initialize(param || "").toString();
    };

    window.Terminate = function(param) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: Terminate failed - API adapter not found.");
            return "false";
        }
        return API.Terminate(param || "").toString();
    };

    window.GetValue = function(element) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: GetValue failed - API adapter not found.");
            return "";
        }
        return API.GetValue(element).toString();
    };

    window.SetValue = function(element, value) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: SetValue failed - API adapter not found.");
            return "false";
        }
        return API.SetValue(element, value).toString();
    };

    window.Commit = function(param) {
        initAPI();
        if (API == null) {
            console.warn("SCORM: Commit failed - API adapter not found.");
            return "false";
        }
        return API.Commit(param || "").toString();
    };

    window.GetLastError = function() {
        initAPI();
        if (API == null) return "301"; // API not initialized
        return API.GetLastError().toString();
    };

    window.GetErrorString = function(errorCode) {
        initAPI();
        if (API == null) return "API adapter not found";
        return API.GetErrorString(errorCode).toString();
    };

    window.GetDiagnostic = function(errorCode) {
        initAPI();
        if (API == null) return "API adapter not found";
        return API.GetDiagnostic(errorCode).toString();
    };
})();
JS;
    }
}
