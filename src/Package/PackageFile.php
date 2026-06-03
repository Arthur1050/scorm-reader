<?php

declare(strict_types=1);

namespace ScormReader\Package;

use ScormReader\Exception\InvalidScormPackageException;
use ScormReader\Security\PathSecurity;
use ScormReader\Validation\ValidationResult;

final class PackageFile
{
    private function __construct(
        private readonly string $targetPath,
        private readonly ?string $sourcePath = null,
        private readonly ?string $contents = null,
    ) {
    }

    public static function fromPath(string $sourcePath, ?string $targetPath = null): self
    {
        $sourceRealPath = realpath($sourcePath);

        if ($sourceRealPath === false || !is_file($sourceRealPath)) {
            throw new InvalidScormPackageException('Package source file does not exist: ' . $sourcePath);
        }

        return new self(
            targetPath: $targetPath ?? basename($sourceRealPath),
            sourcePath: $sourceRealPath,
        );
    }

    public static function fromString(string $targetPath, string $contents): self
    {
        return new self(targetPath: $targetPath, contents: $contents);
    }

    public function targetPath(): string
    {
        return $this->targetPath;
    }

    public function sourcePath(): ?string
    {
        return $this->sourcePath;
    }

    public function contents(): ?string
    {
        return $this->contents;
    }

    public function validate(ImportOptions $options, ValidationResult $result, string $context): bool
    {
        $valid = PathSecurity::validateRelativeUri($this->targetPath, $options, $result, $context, 'PACKAGE_FILE');
        $valid = PathSecurity::validateFilenameAndExtension($this->targetPath, $options, $result, $context) && $valid;

        if ($this->sourcePath !== null) {
            PathSecurity::validateMimeType($this->sourcePath, $options, $result, $context);
            $size = filesize($this->sourcePath);
        } else {
            $size = strlen((string) $this->contents);
        }

        if ($size > $options->maxFileBytes) {
            $result->addError('PACKAGE_FILE_TOO_LARGE', 'Package file exceeds the configured per-file size limit.', $context, [
                'size' => $size,
                'limit' => $options->maxFileBytes,
            ]);

            $valid = false;
        }

        return $valid;
    }

    public function writeTo(string $packageRoot, bool $overwrite): void
    {
        $targetPath = PathSecurity::toFilesystemPath($packageRoot, $this->targetPath);

        if ($targetPath === null) {
            throw new InvalidScormPackageException('Package target path is not safe: ' . $this->targetPath);
        }

        if (is_file($targetPath) && !$overwrite) {
            throw new InvalidScormPackageException('Package target file already exists: ' . $this->targetPath);
        }

        $targetDirectory = dirname($targetPath);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new InvalidScormPackageException('Could not create package target directory: ' . $targetDirectory);
        }

        if ($this->sourcePath !== null) {
            if (!copy($this->sourcePath, $targetPath)) {
                throw new InvalidScormPackageException('Could not copy package file: ' . $this->targetPath);
            }

            return;
        }

        if (file_put_contents($targetPath, (string) $this->contents) === false) {
            throw new InvalidScormPackageException('Could not write package file: ' . $this->targetPath);
        }
    }
}
