<?php

declare(strict_types=1);

namespace ScormReader\Validation;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ScormReader\Package\ImportOptions;
use ScormReader\Security\PathSecurity;
use SplFileInfo;

final class PackageValidator
{
    public function validateDirectory(string $packageRoot, ImportOptions $options): ValidationResult
    {
        $result = new ValidationResult();

        if (!is_dir($packageRoot)) {
            return $result->addError('PACKAGE_DIRECTORY_NOT_FOUND', 'Package directory was not found.', $packageRoot);
        }

        $manifestPath = $packageRoot . DIRECTORY_SEPARATOR . 'imsmanifest.xml';
        if (!is_file($manifestPath)) {
            $result->addError('MANIFEST_NOT_FOUND', 'imsmanifest.xml must exist at the root of the SCORM package.', $manifestPath);
        }

        $root = realpath($packageRoot);
        if ($root === false) {
            return $result->addError('PACKAGE_DIRECTORY_NOT_READABLE', 'Package directory is not readable.', $packageRoot);
        }

        $fileCount = 0;
        $totalBytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || $fileInfo->isDir()) {
                continue;
            }

            $relativePath = $this->relativePath($root, $fileInfo->getPathname());
            $context = $relativePath !== '' ? $relativePath : $fileInfo->getPathname();
            $fileCount++;
            $size = $fileInfo->getSize();
            $totalBytes += $size;

            PathSecurity::validateRelativeUri($relativePath, $options, $result, $context, 'PACKAGE_FILE');
            PathSecurity::validateFilenameAndExtension($relativePath, $options, $result, $context);
            PathSecurity::validateMimeType($fileInfo->getPathname(), $options, $result, $context);

            if (method_exists($fileInfo, 'isLink') && $fileInfo->isLink()) {
                $result->addError('PACKAGE_SYMLINK_FORBIDDEN', 'Package contains a symlink, which is not allowed.', $context);
            }

            if ($size > $options->maxFileBytes) {
                $result->addError('PACKAGE_FILE_TOO_LARGE', 'Package file exceeds the configured per-file size limit.', $context, [
                    'size' => $size,
                    'limit' => $options->maxFileBytes,
                ]);
            }

            if ($fileCount > $options->maxFileCount) {
                $result->addError('PACKAGE_TOO_MANY_FILES', 'Package exceeds the configured file count limit.', $context, [
                    'fileCount' => $fileCount,
                    'limit' => $options->maxFileCount,
                ]);
            }

            if ($totalBytes > $options->maxTotalBytes) {
                $result->addError('PACKAGE_TOO_LARGE', 'Package exceeds the configured total size limit.', $context, [
                    'totalBytes' => $totalBytes,
                    'limit' => $options->maxTotalBytes,
                ]);
            }
        }

        return $result;
    }

    private function relativePath(string $root, string $path): string
    {
        $root = rtrim(PathSecurity::normalizeSlashes($root), '/') . '/';
        $path = PathSecurity::normalizeSlashes($path);

        if (PHP_OS_FAMILY === 'Windows') {
            $rootComparison = strtolower($root);
            $pathComparison = strtolower($path);

            if (str_starts_with($pathComparison, $rootComparison)) {
                return substr($path, strlen($root));
            }
        }

        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return $path;
    }
}
