<?php

declare(strict_types=1);

namespace ScormReader\Security;

use PharData;
use RecursiveIteratorIterator;
use ScormReader\Package\ImportOptions;
use ScormReader\Package\ZipExtractor;
use ScormReader\Validation\ValidationResult;
use Throwable;
use ZipArchive;

final class SafeZipExtractor implements ZipExtractor
{
    public function extract(string $zipPath, string $destinationDirectory, ImportOptions $options): ValidationResult
    {
        $result = new ValidationResult();

        if (!is_file($zipPath)) {
            return $result->addError('ZIP_NOT_FOUND', 'ZIP file was not found.', $zipPath);
        }

        if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
            return $result->addError('EXTRACT_DESTINATION_NOT_CREATED', 'Could not create ZIP extraction directory.', $destinationDirectory);
        }

        if (class_exists(ZipArchive::class)) {
            return $this->extractWithZipArchive($zipPath, $destinationDirectory, $options);
        }

        return $this->extractWithPharData($zipPath, $destinationDirectory, $options);
    }

    private function extractWithZipArchive(string $zipPath, string $destinationDirectory, ImportOptions $options): ValidationResult
    {
        $result = new ValidationResult();
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return $result->addError('ZIP_OPEN_FAILED', 'Could not open ZIP file.', $zipPath);
        }

        $entries = [];
        $totalBytes = 0;
        $fileCount = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';

            if ($name === '' || str_ends_with($name, '/') || str_ends_with($name, '\\')) {
                continue;
            }

            $fileCount++;
            $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            $totalBytes += $size;
            $context = 'zip://' . $name;

            $this->validateArchiveEntry($name, $size, $fileCount, $totalBytes, $options, $result, $context);

            if ($this->isZipArchiveSymlink($zip, $i)) {
                $result->addError('ZIP_SYMLINK_FORBIDDEN', 'ZIP entry is a symlink and will not be extracted.', $context);
            }

            $entries[] = $name;
        }

        if ($result->hasErrors()) {
            $zip->close();

            return $result;
        }

        foreach ($entries as $entry) {
            $targetPath = PathSecurity::toFilesystemPath($destinationDirectory, $entry);

            if ($targetPath === null) {
                $result->addError('ZIP_TARGET_OUTSIDE_DESTINATION', 'ZIP entry resolves outside the extraction directory.', 'zip://' . $entry);
                continue;
            }

            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
                $result->addError('ZIP_TARGET_DIRECTORY_NOT_CREATED', 'Could not create directory for ZIP entry.', 'zip://' . $entry);
                continue;
            }

            $source = $zip->getStream($entry);
            if (!is_resource($source)) {
                $result->addError('ZIP_ENTRY_READ_FAILED', 'Could not read ZIP entry.', 'zip://' . $entry);
                continue;
            }

            $target = fopen($targetPath, 'wb');
            if (!is_resource($target)) {
                fclose($source);
                $result->addError('ZIP_ENTRY_WRITE_FAILED', 'Could not write extracted ZIP entry.', 'zip://' . $entry);
                continue;
            }

            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);
        }

        $zip->close();

        return $result;
    }

    private function extractWithPharData(string $zipPath, string $destinationDirectory, ImportOptions $options): ValidationResult
    {
        $result = new ValidationResult();

        try {
            $archive = new PharData($zipPath);
        } catch (Throwable $throwable) {
            return $result->addError('ZIP_BACKEND_UNAVAILABLE', 'Could not open ZIP file. Install ext-zip or enable PharData ZIP support.', $zipPath, [
                'error' => $throwable->getMessage(),
            ]);
        }

        $entries = [];
        $totalBytes = 0;
        $fileCount = 0;
        $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                continue;
            }

            $entryName = $this->relativePharPath((string) $fileInfo->getPathname(), $zipPath);
            $fileCount++;
            $size = (int) $fileInfo->getSize();
            $totalBytes += $size;
            $context = 'zip://' . $entryName;

            $this->validateArchiveEntry($entryName, $size, $fileCount, $totalBytes, $options, $result, $context);

            if (method_exists($fileInfo, 'isLink') && $fileInfo->isLink()) {
                $result->addError('ZIP_SYMLINK_FORBIDDEN', 'ZIP entry is a symlink and will not be extracted.', $context);
            }

            $entries[] = [$entryName, (string) $fileInfo->getPathname()];
        }

        if ($result->hasErrors()) {
            return $result;
        }

        foreach ($entries as [$entryName, $archivePath]) {
            $targetPath = PathSecurity::toFilesystemPath($destinationDirectory, $entryName);

            if ($targetPath === null) {
                $result->addError('ZIP_TARGET_OUTSIDE_DESTINATION', 'ZIP entry resolves outside the extraction directory.', 'zip://' . $entryName);
                continue;
            }

            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
                $result->addError('ZIP_TARGET_DIRECTORY_NOT_CREATED', 'Could not create directory for ZIP entry.', 'zip://' . $entryName);
                continue;
            }

            $source = fopen($archivePath, 'rb');
            if (!is_resource($source)) {
                $result->addError('ZIP_ENTRY_READ_FAILED', 'Could not read ZIP entry.', 'zip://' . $entryName);
                continue;
            }

            $target = fopen($targetPath, 'wb');
            if (!is_resource($target)) {
                fclose($source);
                $result->addError('ZIP_ENTRY_WRITE_FAILED', 'Could not write extracted ZIP entry.', 'zip://' . $entryName);
                continue;
            }

            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);
        }

        return $result;
    }

    private function validateArchiveEntry(
        string $entryName,
        int $size,
        int $fileCount,
        int $totalBytes,
        ImportOptions $options,
        ValidationResult $result,
        string $context,
    ): void {
        PathSecurity::validateRelativeUri($entryName, $options, $result, $context, 'ZIP_ENTRY');
        PathSecurity::validateFilenameAndExtension($entryName, $options, $result, $context);

        if ($size > $options->maxFileBytes) {
            $result->addError('ZIP_ENTRY_TOO_LARGE', 'ZIP entry exceeds the configured per-file size limit.', $context, [
                'size' => $size,
                'limit' => $options->maxFileBytes,
            ]);
        }

        if ($fileCount > $options->maxFileCount) {
            $result->addError('ZIP_TOO_MANY_FILES', 'ZIP package exceeds the configured file count limit.', $context, [
                'fileCount' => $fileCount,
                'limit' => $options->maxFileCount,
            ]);
        }

        if ($totalBytes > $options->maxTotalBytes) {
            $result->addError('ZIP_TOO_LARGE', 'ZIP package exceeds the configured total uncompressed size limit.', $context, [
                'totalBytes' => $totalBytes,
                'limit' => $options->maxTotalBytes,
            ]);
        }
    }

    private function isZipArchiveSymlink(ZipArchive $zip, int $index): bool
    {
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        return (($attributes >> 16) & 0xF000) === 0xA000;
    }

    private function relativePharPath(string $path, string $zipPath): string
    {
        $normalizedPath = PathSecurity::normalizeSlashes($path);
        $normalizedZip = PathSecurity::normalizeSlashes($zipPath);
        $prefix = 'phar://' . $normalizedZip . '/';

        if (str_starts_with($normalizedPath, $prefix)) {
            return substr($normalizedPath, strlen($prefix));
        }

        return ltrim(basename($normalizedPath), '/');
    }
}
