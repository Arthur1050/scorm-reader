<?php

declare(strict_types=1);

namespace ScormReader\Security;

use ScormReader\Package\ImportOptions;
use ScormReader\Validation\ValidationResult;

final class PathSecurity
{
    public static function normalizeSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function pathPart(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path)) {
            $path = preg_split('/[?#]/', $uri, 2)[0] ?? '';
        }

        return self::normalizeSlashes($path);
    }

    public static function decodedPathPart(string $uri): string
    {
        $path = self::pathPart($uri);

        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($path);

            if ($decoded === $path) {
                break;
            }

            $path = $decoded;
        }

        return self::normalizeSlashes($path);
    }

    public static function isExternalUri(string $uri): bool
    {
        $uri = trim($uri);

        if ($uri === '') {
            return false;
        }

        if (str_starts_with($uri, '//')) {
            return true;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $uri) === 1
            && preg_match('/^[a-zA-Z]:[\\\\/]/', $uri) !== 1;
    }

    public static function isAbsolutePath(string $uri): bool
    {
        $path = self::decodedPathPart($uri);

        return str_starts_with($path, '/')
            || preg_match('/^[a-zA-Z]:\//', $path) === 1;
    }

    public static function containsTraversal(string $uri): bool
    {
        $path = self::decodedPathPart($uri);

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    public static function joinUriPaths(?string $base, ?string $path): string
    {
        $base = trim((string) $base);
        $path = trim((string) $path);

        if ($path === '') {
            return self::normalizeSlashes($base);
        }

        if ($base === '' || self::isExternalUri($path) || self::isAbsolutePath($path)) {
            return self::normalizeSlashes($path);
        }

        return rtrim(self::normalizeSlashes($base), '/') . '/' . ltrim(self::normalizeSlashes($path), '/');
    }

    public static function validateRelativeUri(
        string $uri,
        ImportOptions $options,
        ValidationResult $result,
        string $context,
        string $codePrefix = 'PATH',
        bool $allowEmpty = false,
    ): bool {
        $valid = true;
        $value = trim($uri);

        if ($value === '') {
            if (!$allowEmpty) {
                $result->addError($codePrefix . '_EMPTY', 'Path is empty.', $context);
            }

            return $allowEmpty;
        }

        if (str_contains($value, '\\')) {
            $result->addError($codePrefix . '_BACKSLASH', 'Path must use forward slashes, not backslashes.', $context, ['path' => $uri]);
            $valid = false;
        }

        if (self::isExternalUri($value) && !$options->allowExternalResources) {
            $result->addError($codePrefix . '_EXTERNAL', 'External URLs are not allowed inside this SCORM package.', $context, ['path' => $uri]);
            $valid = false;
        }

        if (self::isAbsolutePath($value)) {
            $result->addError($codePrefix . '_ABSOLUTE', 'Absolute paths are not allowed inside this SCORM package.', $context, ['path' => $uri]);
            $valid = false;
        }

        if (self::containsTraversal($value)) {
            $result->addError($codePrefix . '_TRAVERSAL', 'Path traversal segments are not allowed inside this SCORM package.', $context, ['path' => $uri]);
            $valid = false;
        }

        return $valid;
    }

    public static function validateFilenameAndExtension(
        string $uri,
        ImportOptions $options,
        ValidationResult $result,
        string $context,
    ): bool {
        $path = self::pathPart($uri);
        $filename = strtolower(basename($path));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $valid = true;

        if ($filename !== '' && in_array($filename, $options->dangerousFilenames, true)) {
            $result->addError('DANGEROUS_FILENAME', 'Package contains a dangerous server configuration file.', $context, ['filename' => $filename]);
            $valid = false;
        }

        if ($extension !== '' && in_array($extension, $options->dangerousExtensions, true)) {
            $result->addError('DANGEROUS_FILE_EXTENSION', 'Package contains a dangerous executable or server-side file extension.', $context, ['extension' => $extension]);
            $valid = false;
        }

        if (
            !$options->allowUnknownFileExtensions
            && $extension !== ''
            && !in_array($extension, $options->allowedExtensions, true)
        ) {
            $result->addError('UNSUPPORTED_FILE_EXTENSION', 'File extension is not allowed for SCORM web content.', $context, ['extension' => $extension]);
            $valid = false;
        }

        if (!$options->allowUnknownFileExtensions && $extension === '' && $filename !== '') {
            $result->addWarning('UNKNOWN_FILE_EXTENSION', 'File has no extension; MIME validation falls back to path safety only.', $context, ['filename' => $filename]);
        }

        return $valid;
    }

    public static function validateMimeType(
        string $filesystemPath,
        ImportOptions $options,
        ValidationResult $result,
        string $context,
    ): bool {
        if (!$options->validateMimeTypes || !function_exists('finfo_open')) {
            return true;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return true;
        }

        $mimeType = finfo_file($finfo, $filesystemPath);
        finfo_close($finfo);

        if (!is_string($mimeType) || $mimeType === '') {
            return true;
        }

        $mimeType = strtolower($mimeType);

        if (!in_array($mimeType, $options->allowedMimeTypes, true)) {
            $result->addError('UNSUPPORTED_MIME_TYPE', 'Detected MIME type is not allowed for SCORM web content.', $context, [
                'mimeType' => $mimeType,
            ]);

            return false;
        }

        return true;
    }

    public static function toFilesystemPath(string $rootDirectory, string $relativeUri): ?string
    {
        if (self::isExternalUri($relativeUri) || self::isAbsolutePath($relativeUri) || self::containsTraversal($relativeUri)) {
            return null;
        }

        $root = realpath($rootDirectory);

        if ($root === false) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', self::pathPart($relativeUri)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));

        $target = $root;

        foreach ($segments as $segment) {
            if ($segment === '..') {
                return null;
            }

            $target .= DIRECTORY_SEPARATOR . $segment;
        }

        return self::isWithinDirectory($root, $target) ? $target : null;
    }

    public static function isWithinDirectory(string $rootDirectory, string $targetPath): bool
    {
        $root = self::normalizeFilesystemPath($rootDirectory);
        $target = self::normalizeFilesystemPath($targetPath);

        return $target === $root || str_starts_with($target, rtrim($root, '/') . '/');
    }

    private static function normalizeFilesystemPath(string $path): string
    {
        $path = rtrim(self::normalizeSlashes($path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
