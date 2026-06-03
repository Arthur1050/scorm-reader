<?php

declare(strict_types=1);

namespace ScormReader\Package;

final class ExportOptions
{
    public function __construct(
        public readonly bool $overwrite = false,
        public readonly bool $validateAfterExport = true,
        public readonly ?ImportOptions $validationOptions = null,
    ) {
    }

    public function validationOptions(): ImportOptions
    {
        return $this->validationOptions ?? new ImportOptions();
    }
}
