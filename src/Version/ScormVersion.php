<?php

declare(strict_types=1);

namespace ScormReader\Version;

enum ScormVersion: string
{
    case SCORM_12 = '1.2';
    case SCORM_2004 = '2004';

    public function label(): string
    {
        return match ($this) {
            self::SCORM_12 => 'SCORM 1.2',
            self::SCORM_2004 => 'SCORM 2004',
        };
    }

    public function is2004(): bool
    {
        return $this === self::SCORM_2004;
    }
}
