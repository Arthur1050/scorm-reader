<?php

declare(strict_types=1);

namespace ScormReader\Validation;

use JsonSerializable;

final class ValidationResult implements JsonSerializable
{
    /** @var list<ValidationIssue> */
    private array $issues = [];

    /**
     * @param array<string, mixed> $context
     */
    public function addError(string $code, string $message, ?string $path = null, array $context = []): self
    {
        $this->issues[] = new ValidationIssue(ValidationIssue::ERROR, $code, $message, $path, $context);

        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function addWarning(string $code, string $message, ?string $path = null, array $context = []): self
    {
        $this->issues[] = new ValidationIssue(ValidationIssue::WARNING, $code, $message, $path, $context);

        return $this;
    }

    public function merge(self $result): self
    {
        foreach ($result->issues() as $issue) {
            $this->issues[] = $issue;
        }

        return $this;
    }

    public function isValid(): bool
    {
        return !$this->hasErrors();
    }

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity() === ValidationIssue::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ValidationIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<ValidationIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (ValidationIssue $issue): bool => $issue->severity() === ValidationIssue::ERROR,
        ));
    }

    /**
     * @return list<ValidationIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (ValidationIssue $issue): bool => $issue->severity() === ValidationIssue::WARNING,
        ));
    }

    /**
     * @return array{valid: bool, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'errors' => array_map(
                static fn (ValidationIssue $issue): array => $issue->toArray(),
                $this->errors(),
            ),
            'warnings' => array_map(
                static fn (ValidationIssue $issue): array => $issue->toArray(),
                $this->warnings(),
            ),
        ];
    }

    /**
     * @return array{valid: bool, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
