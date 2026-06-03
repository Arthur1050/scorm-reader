<?php

declare(strict_types=1);

namespace ScormReader\Exception;

use RuntimeException;
use ScormReader\Validation\ValidationResult;
use Throwable;

class InvalidScormPackageException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?ValidationResult $validationResult = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function validationResult(): ?ValidationResult
    {
        return $this->validationResult;
    }
}
