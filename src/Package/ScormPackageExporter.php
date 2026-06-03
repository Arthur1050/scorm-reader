<?php

declare(strict_types=1);

namespace ScormReader\Package;

use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ScormReader\Exception\InvalidScormPackageException;
use ScormReader\Manifest\Manifest;
use ScormReader\Manifest\ManifestBuilder;
use ScormReader\Security\PathSecurity;
use ScormReader\Validation\ValidationResult;
use ZipArchive;

final class ScormPackageExporter
{
    public function __construct(
        private readonly ?ManifestBuilder $manifestBuilder = null,
        private readonly ?ScormPackageImporter $importer = null,
    ) {
    }

    public function export(ScormPackage $package, string $destinationZipPath, ?ExportOptions $options = null): void
    {
        $this->exportDirectoryToZip($package->packageRoot(), $destinationZipPath, $options ?? new ExportOptions());
    }

    public function exportPackageToDirectory(ScormPackage $package, string $destinationDirectory, ?ExportOptions $options = null): ScormPackage
    {
        $options ??= new ExportOptions();
        $this->prepareDirectory($destinationDirectory, $options);
        $this->copyDirectory($package->packageRoot(), $destinationDirectory, $options->overwrite);

        return $this->packageFromDirectory($destinationDirectory, $options);
    }

    public function exportManifestToDirectory(
        Manifest $manifest,
        string $sourceDirectory,
        string $destinationDirectory,
        ?ExportOptions $options = null,
    ): ScormPackage {
        $options ??= new ExportOptions();
        $this->prepareDirectory($destinationDirectory, $options);
        $this->copyDirectory($sourceDirectory, $destinationDirectory, $options->overwrite);
        $this->writeManifest($manifest, $destinationDirectory, $options->overwrite);

        return $this->packageFromDirectory($destinationDirectory, $options);
    }

    public function exportManifestToZip(
        Manifest $manifest,
        string $sourceDirectory,
        string $destinationZipPath,
        ?string $workDirectory = null,
        ?ExportOptions $options = null,
    ): ScormPackage {
        $options ??= new ExportOptions();
        $temporaryDirectory = $this->createTemporaryDirectory($workDirectory);

        try {
            $this->exportManifestToDirectory(
                manifest: $manifest,
                sourceDirectory: $sourceDirectory,
                destinationDirectory: $temporaryDirectory,
                options: new ExportOptions(overwrite: true, validateAfterExport: false, validationOptions: $options->validationOptions()),
            );

            $this->exportDirectoryToZip($temporaryDirectory, $destinationZipPath, $options);

            return $this->packageFromZip($destinationZipPath, $options, $workDirectory);
        } finally {
            $this->removeDirectory($temporaryDirectory);
        }
    }

    public function exportCreatedPackageToDirectory(
        ScormPackageCreator $creator,
        string $destinationDirectory,
        ?ExportOptions $options = null,
    ): ScormPackage {
        $options ??= new ExportOptions();
        $this->validateCreator($creator, $options);
        $this->prepareDirectory($destinationDirectory, $options);

        foreach ($creator->files() as $file) {
            $file->writeTo($destinationDirectory, $options->overwrite);
        }

        $this->writeManifest($creator->buildManifest(), $destinationDirectory, $options->overwrite);

        return $this->packageFromDirectory($destinationDirectory, $options);
    }

    public function exportCreatedPackageToZip(
        ScormPackageCreator $creator,
        string $destinationZipPath,
        ?string $workDirectory = null,
        ?ExportOptions $options = null,
    ): ScormPackage {
        $options ??= new ExportOptions();
        $temporaryDirectory = $this->createTemporaryDirectory($workDirectory);

        try {
            $this->exportCreatedPackageToDirectory(
                creator: $creator,
                destinationDirectory: $temporaryDirectory,
                options: new ExportOptions(overwrite: true, validateAfterExport: false, validationOptions: $options->validationOptions()),
            );

            $this->exportDirectoryToZip($temporaryDirectory, $destinationZipPath, $options);

            return $this->packageFromZip($destinationZipPath, $options, $workDirectory);
        } finally {
            $this->removeDirectory($temporaryDirectory);
        }
    }

    private function validateCreator(ScormPackageCreator $creator, ExportOptions $options): void
    {
        $validation = $creator->validate($options->validationOptions());

        if ($validation->hasErrors()) {
            throw new InvalidScormPackageException('Created SCORM package failed validation before export.', $validation);
        }
    }

    private function writeManifest(Manifest $manifest, string $destinationDirectory, bool $overwrite): void
    {
        $manifestPath = rtrim($destinationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'imsmanifest.xml';

        if (is_file($manifestPath) && !$overwrite) {
            throw new InvalidScormPackageException('imsmanifest.xml already exists in destination directory.');
        }

        $xml = ($this->manifestBuilder ?? new ManifestBuilder())->build($manifest);

        if (file_put_contents($manifestPath, $xml) === false) {
            throw new InvalidScormPackageException('Could not write imsmanifest.xml.');
        }
    }

    private function packageFromDirectory(string $directory, ExportOptions $options): ScormPackage
    {
        if ($options->validateAfterExport) {
            return ($this->importer ?? new ScormPackageImporter())->import($directory, options: $options->validationOptions());
        }

        return new ScormPackage(
            sourcePath: realpath($directory) ?: $directory,
            packageRoot: realpath($directory) ?: $directory,
            manifest: ($this->importer ?? new ScormPackageImporter())->import($directory, options: $options->validationOptions())->manifest(),
            validationResult: new ValidationResult(),
        );
    }

    private function packageFromZip(string $zipPath, ExportOptions $options, ?string $workDirectory): ScormPackage
    {
        if ($options->validateAfterExport) {
            return ($this->importer ?? new ScormPackageImporter())->import($zipPath, $workDirectory, $options->validationOptions());
        }

        return new ScormPackage(
            sourcePath: realpath($zipPath) ?: $zipPath,
            packageRoot: realpath(dirname($zipPath)) ?: dirname($zipPath),
            manifest: ($this->importer ?? new ScormPackageImporter())->import($zipPath, $workDirectory, $options->validationOptions())->manifest(),
            validationResult: new ValidationResult(),
            extracted: false,
        );
    }

    private function exportDirectoryToZip(string $sourceDirectory, string $destinationZipPath, ExportOptions $options): void
    {
        $sourceRoot = realpath($sourceDirectory);

        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new InvalidScormPackageException('Source package directory does not exist.');
        }

        $destinationDirectory = dirname($destinationZipPath);
        if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
            throw new InvalidScormPackageException('Could not create ZIP destination directory.');
        }

        if (is_file($destinationZipPath)) {
            if (!$options->overwrite) {
                throw new InvalidScormPackageException('Destination ZIP already exists.');
            }

            if (!unlink($destinationZipPath)) {
                throw new InvalidScormPackageException('Could not overwrite destination ZIP.');
            }
        }

        if (class_exists(ZipArchive::class)) {
            $this->exportWithZipArchive($sourceRoot, $destinationZipPath);

            return;
        }

        $this->exportWithPharData($sourceRoot, $destinationZipPath);
    }

    private function exportWithZipArchive(string $sourceRoot, string $destinationZipPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($destinationZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new InvalidScormPackageException('Could not create destination ZIP.');
        }

        foreach ($this->filesInDirectory($sourceRoot) as [$path, $relativePath]) {
            if (!$zip->addFile($path, $relativePath)) {
                $zip->close();
                throw new InvalidScormPackageException('Could not add file to ZIP: ' . $relativePath);
            }
        }

        $zip->close();
    }

    private function exportWithPharData(string $sourceRoot, string $destinationZipPath): void
    {
        if (!class_exists(PharData::class)) {
            throw new InvalidScormPackageException('ZIP export requires ZipArchive or PharData.');
        }

        try {
            $archive = new PharData($destinationZipPath);

            foreach ($this->filesInDirectory($sourceRoot) as [$path, $relativePath]) {
                $archive->addFile($path, $relativePath);
            }
        } catch (\Throwable $exception) {
            throw new InvalidScormPackageException('Could not create destination ZIP with PharData.', previous: $exception);
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function filesInDirectory(string $sourceRoot): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $relativePath = ltrim(substr(PathSecurity::normalizeSlashes($path), strlen(PathSecurity::normalizeSlashes($sourceRoot))), '/');
            $files[] = [$path, $relativePath];
        }

        usort($files, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $files;
    }

    private function prepareDirectory(string $directory, ExportOptions $options): void
    {
        if (is_file($directory)) {
            throw new InvalidScormPackageException('Destination directory points to an existing file.');
        }

        if (is_dir($directory)) {
            if (!$options->overwrite && $this->directoryHasFiles($directory)) {
                throw new InvalidScormPackageException('Destination directory is not empty.');
            }

            if ($options->overwrite) {
                $this->removeDirectoryContents($directory);
            }

            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidScormPackageException('Could not create destination directory.');
        }
    }

    private function copyDirectory(string $sourceDirectory, string $destinationDirectory, bool $overwrite): void
    {
        $sourceRoot = realpath($sourceDirectory);
        $destinationRoot = realpath($destinationDirectory);

        if ($sourceRoot === false || !is_dir($sourceRoot) || $destinationRoot === false) {
            throw new InvalidScormPackageException('Could not copy package directory.');
        }

        foreach ($this->filesInDirectory($sourceRoot) as [$sourcePath, $relativePath]) {
            $targetPath = PathSecurity::toFilesystemPath($destinationRoot, $relativePath);

            if ($targetPath === null) {
                throw new InvalidScormPackageException('Unsafe package path during copy: ' . $relativePath);
            }

            if (is_file($targetPath) && !$overwrite) {
                throw new InvalidScormPackageException('Package target file already exists: ' . $relativePath);
            }

            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
                throw new InvalidScormPackageException('Could not create package target directory: ' . $targetDirectory);
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new InvalidScormPackageException('Could not copy package file: ' . $relativePath);
            }
        }
    }

    private function createTemporaryDirectory(?string $workDirectory): string
    {
        $baseDirectory = $workDirectory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scorm-reader-export';

        if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0775, true) && !is_dir($baseDirectory)) {
            throw new InvalidScormPackageException('Could not create SCORM exporter work directory.');
        }

        $directory = $baseDirectory . DIRECTORY_SEPARATOR . 'package-' . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidScormPackageException('Could not create SCORM package export directory.');
        }

        return $directory;
    }

    private function directoryHasFiles(string $directory): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                return true;
            }
        }

        return false;
    }

    private function removeDirectoryContents(string $directory): void
    {
        $root = realpath($directory);

        if ($root === false || !is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            $path = $fileInfo->getPathname();

            if (!PathSecurity::isWithinDirectory($root, $path)) {
                throw new InvalidScormPackageException('Refusing to remove file outside destination directory.');
            }

            $fileInfo->isDir() ? rmdir($path) : unlink($path);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $this->removeDirectoryContents($directory);

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
