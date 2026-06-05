<?php

declare(strict_types=1);

namespace ScormReader\Package;

final class ImportOptions
{
    /** @var list<string> */
    public readonly array $allowedExtensions;

    /** @var list<string> */
    public readonly array $dangerousExtensions;

    /** @var list<string> */
    public readonly array $dangerousFilenames;

    /** @var list<string> */
    public readonly array $allowedMimeTypes;

    /**
     * @param list<string>|null $allowedExtensions
     * @param list<string>|null $dangerousExtensions
     * @param list<string>|null $dangerousFilenames
     * @param list<string>|null $allowedMimeTypes
     */
    public function __construct(
        public readonly int $maxFileCount = 5000,
        public readonly int $maxTotalBytes = 524_288_000,
        public readonly int $maxFileBytes = 209_715_200,
        ?array $allowedExtensions = null,
        ?array $dangerousExtensions = null,
        ?array $dangerousFilenames = null,
        ?array $allowedMimeTypes = null,
        public readonly bool $allowExternalResources = false,
        public readonly bool $allowUnknownFileExtensions = false,
        public readonly bool $validateMimeTypes = true,
        public readonly bool $requireScoForLaunchableItems = true,
        public readonly bool $validateXsd = false,
        public readonly bool $xsdErrorsAsWarnings = true,
    ) {
        $this->allowedExtensions = $this->normalizeExtensions($allowedExtensions ?? self::defaultAllowedExtensions());
        $this->dangerousExtensions = $this->normalizeExtensions($dangerousExtensions ?? self::defaultDangerousExtensions());
        $this->dangerousFilenames = array_values(array_unique(array_map(
            static fn (string $name): string => strtolower($name),
            $dangerousFilenames ?? self::defaultDangerousFilenames(),
        )));
        $this->allowedMimeTypes = array_values(array_unique(array_map(
            static fn (string $mimeType): string => strtolower($mimeType),
            $allowedMimeTypes ?? self::defaultAllowedMimeTypes(),
        )));
    }

    /**
     * @return list<string>
     */
    public static function defaultAllowedExtensions(): array
    {
        return [
            'html', 'htm', 'xhtml', 'css', 'js', 'mjs', 'json', 'xml', 'xsd', 'dtd',
            'txt', 'md', 'csv', 'pdf', 'rtf',
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp', 'ico', 'avif',
            'mp3', 'wav', 'ogg', 'oga', 'm4a',
            'mp4', 'm4v', 'webm', 'ogv', 'mov',
            'vtt', 'srt',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            'swf',
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaultDangerousExtensions(): array
    {
        return [
            'php', 'php3', 'php4', 'php5', 'phtml', 'phar',
            'asp', 'aspx', 'ashx', 'jsp', 'jspx', 'cgi',
            'pl', 'py', 'rb', 'lua',
            'exe', 'dll', 'com', 'scr', 'msi', 'bat', 'cmd', 'ps1', 'vbs', 'wsf',
            'sh', 'bash', 'zsh', 'fish',
            'jar', 'war', 'class',
            'so', 'dylib', 'apk', 'ipa', 'dmg', 'deb', 'rpm',
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaultDangerousFilenames(): array
    {
        return ['.htaccess', 'web.config'];
    }

    /**
     * @return list<string>
     */
    public static function defaultAllowedMimeTypes(): array
    {
        return [
            'text/plain',
            'text/html',
            'application/xhtml+xml',
            'text/css',
            'text/javascript',
            'application/javascript',
            'application/x-javascript',
            'application/json',
            'application/xml',
            'text/xml',
            'text/csv',
            'application/pdf',
            'application/rtf',
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/svg+xml',
            'image/webp',
            'image/bmp',
            'image/x-icon',
            'image/vnd.microsoft.icon',
            'image/avif',
            'audio/mpeg',
            'audio/wav',
            'audio/x-wav',
            'audio/ogg',
            'audio/mp4',
            'video/mp4',
            'video/quicktime',
            'video/webm',
            'video/ogg',
            'text/vtt',
            'font/woff',
            'font/woff2',
            'application/font-woff',
            'application/x-font-ttf',
            'application/vnd.ms-fontobject',
            'application/x-shockwave-flash',
            'application/octet-stream',
        ];
    }

    /**
     * @param list<string> $extensions
     * @return list<string>
     */
    private function normalizeExtensions(array $extensions): array
    {
        return array_values(array_unique(array_map(
            static fn (string $extension): string => strtolower(ltrim($extension, '.')),
            $extensions,
        )));
    }
}
