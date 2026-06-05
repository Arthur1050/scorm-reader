<?php

declare(strict_types=1);

namespace ScormReader\Package;

use ScormReader\Exception\InvalidScormPackageException;
use ScormReader\Exception\ManifestNotFoundException;
use ScormReader\Manifest\ManifestParser;
use ScormReader\Security\SafeZipExtractor;
use ScormReader\Validation\ManifestValidator;
use ScormReader\Validation\PackageValidator;
use ScormReader\Validation\ValidationResult;

final class ScormPackageImporter
{
    public function __construct(
        private readonly ?ZipExtractor $zipExtractor = null,
        private readonly ?PackageValidator $packageValidator = null,
        private readonly ?ManifestParser $manifestParser = null,
        private readonly ?ManifestValidator $manifestValidator = null,
    ) {
    }

    public function import(string $sourcePath, ?string $workDirectory = null, ?ImportOptions $options = null): ScormPackage
    {
        $options ??= new ImportOptions();
        $validation = new ValidationResult();
        $sourceRealPath = realpath($sourcePath);

        if ($sourceRealPath === false) {
            throw new InvalidScormPackageException('SCORM package path does not exist.');
        }

        $sourcePath = $sourceRealPath;
        $extracted = false;
        $temporaryDirectory = null;

        if (is_dir($sourcePath)) {
            $packageRoot = $sourcePath;
        } elseif (is_file($sourcePath)) {
            $packageRoot = $this->createTemporaryDirectory($workDirectory);
            $temporaryDirectory = $packageRoot;
            $extracted = true;

            $zipValidation = ($this->zipExtractor ?? new SafeZipExtractor())->extract($sourcePath, $packageRoot, $options);
            $validation->merge($zipValidation);

            if ($zipValidation->hasErrors()) {
                throw new InvalidScormPackageException('ZIP package failed security validation and was not imported.', $validation);
            }
        } else {
            throw new InvalidScormPackageException('SCORM package path must be a ZIP file or directory.');
        }

        $packageValidation = ($this->packageValidator ?? new PackageValidator())->validateDirectory($packageRoot, $options);
        $validation->merge($packageValidation);

        $manifestPath = $packageRoot . DIRECTORY_SEPARATOR . 'imsmanifest.xml';
        if (!is_file($manifestPath)) {
            throw new ManifestNotFoundException('imsmanifest.xml was not found at the package root.', $validation);
        }

        $manifest = ($this->manifestParser ?? new ManifestParser())->parse($manifestPath, $validation, $options);
        $manifestValidation = ($this->manifestValidator ?? new ManifestValidator())->validate($manifest, $packageRoot, $options);
        $validation->merge($manifestValidation);

        return new ScormPackage(
            sourcePath: $sourcePath,
            packageRoot: $packageRoot,
            manifest: $manifest,
            validationResult: $validation,
            extracted: $extracted,
            temporaryDirectory: $temporaryDirectory,
        );
    }

    private function createTemporaryDirectory(?string $workDirectory): string
    {
        $baseDirectory = $workDirectory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scorm-reader';

        if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0775, true) && !is_dir($baseDirectory)) {
            throw new InvalidScormPackageException('Could not create SCORM importer work directory.');
        }

        $directory = $baseDirectory . DIRECTORY_SEPARATOR . 'package-' . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidScormPackageException('Could not create SCORM package extraction directory.');
        }

        return $directory;
    }
}
