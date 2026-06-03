<?php

declare(strict_types=1);

namespace ScormReader\Package;

use ScormReader\Validation\ValidationResult;

interface ZipExtractor
{
    public function extract(string $zipPath, string $destinationDirectory, ImportOptions $options): ValidationResult;
}
