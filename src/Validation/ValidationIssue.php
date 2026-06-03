<?php

declare(strict_types=1);

namespace ScormReader\Validation;

use JsonSerializable;

final class ValidationIssue implements JsonSerializable
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly string $severity,
        private readonly string $code,
        private readonly string $message,
        private readonly ?string $path = null,
        private readonly array $context = [],
    ) {
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'path' => $this->path,
            'context' => $this->context,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
