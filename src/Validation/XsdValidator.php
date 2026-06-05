<?php

declare(strict_types=1);

namespace ScormReader\Validation;

use DOMDocument;
use LibXMLError;
use ScormReader\Package\ImportOptions;
use ScormReader\Version\ScormVersion;

/**
 * Optional XSD structural validator for SCORM manifest files.
 *
 * Validates the imsmanifest.xml against bundled schema files.
 * Activated by {@see ImportOptions::$validateXsd}.
 *
 * By default, XSD errors are reported as warnings ({@see ImportOptions::$xsdErrorsAsWarnings})
 * because many commercial SCORM packages have minor deviations from the official schemas
 * (e.g., extra namespace attributes, missing optional elements).
 *
 * The bundled schemas are simplified but structurally complete versions of the
 * official IMS Content Packaging and ADL SCORM extension schemas.
 */
final class XsdValidator
{
    private const SCHEMA_DIR = __DIR__ . '/xsd';

    /**
     * Maps SCORM version to the root XSD path and the set of namespace-to-local-path
     * mappings used by the external entity loader.
     *
     * @return array{schema: string, imports: array<string, string>}
     */
    private function schemaConfig(ScormVersion $version): array
    {
        if ($version->is2004()) {
            $dir = self::SCHEMA_DIR . '/scorm2004';

            return [
                'schema' => $dir . '/imscp_v1p1.xsd',
                'imports' => [
                    'http://www.imsglobal.org/xsd/imscp_v1p1 imscp_v1p1.xsd'  => $dir . '/imscp_v1p1.xsd',
                    'http://www.adlnet.org/xsd/adlcp_v1p3 adlcp_v1p3.xsd'     => $dir . '/adlcp_v1p3.xsd',
                    'imscp_v1p1.xsd'                                           => $dir . '/imscp_v1p1.xsd',
                    'adlcp_v1p3.xsd'                                           => $dir . '/adlcp_v1p3.xsd',
                ],
            ];
        }

        $dir = self::SCHEMA_DIR . '/scorm12';

        return [
            'schema' => $dir . '/imscp_rootv1p1p2.xsd',
            'imports' => [
                'http://www.imsproject.org/xsd/imscp_rootv1p1p2 imscp_rootv1p1p2.xsd' => $dir . '/imscp_rootv1p1p2.xsd',
                'http://www.adlnet.org/xsd/adlcp_rootv1p2 adlcp_rootv1p2.xsd'         => $dir . '/adlcp_rootv1p2.xsd',
                'imscp_rootv1p1p2.xsd'                                                => $dir . '/imscp_rootv1p1p2.xsd',
                'adlcp_rootv1p2.xsd'                                                   => $dir . '/adlcp_rootv1p2.xsd',
                'ims_xml.xsd'                                                          => null,
            ],
        ];
    }

    /**
     * Validates the given DOMDocument against the bundled XSD schema for the
     * provided SCORM version.
     *
     * Issues are appended to the supplied ValidationResult.
     * When $options->xsdErrorsAsWarnings is true, XSD failures become warnings
     * instead of errors, so they do not cause isValid() to return false.
     */
    public function validate(
        DOMDocument $document,
        ScormVersion $version,
        ImportOptions $options,
        ValidationResult $result,
        string $context = 'imsmanifest.xml',
    ): void {
        $config = $this->schemaConfig($version);
        $schemaPath = $config['schema'];
        $imports = $config['imports'];

        if (!is_file($schemaPath)) {
            $result->addWarning(
                'XSD_SCHEMA_NOT_FOUND',
                'Bundled XSD schema file not found; XSD validation was skipped.',
                $context,
                ['schema' => $schemaPath],
            );

            return;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // Redirect schema imports to local bundled files via the external entity loader.
        // This prevents runtime network fetches for schema dependencies.
        $this->withEntityLoader($imports, function () use ($document, $schemaPath): void {
            $document->schemaValidate($schemaPath);
        });

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($errors as $error) {
            $message = trim($error->message);
            $data = ['line' => $error->line, 'column' => $error->column];

            if ($options->xsdErrorsAsWarnings) {
                $result->addWarning('XSD_VALIDATION_WARNING', $message, $context, $data);
            } else {
                $result->addError('XSD_VALIDATION_ERROR', $message, $context, $data);
            }
        }
    }

    /**
     * Installs a temporary external entity loader that redirects XSD imports
     * to local bundled files, then restores the original loader.
     *
     * @param array<string, string|null> $imports  map of system-id → local path (null = suppress)
     */
    private function withEntityLoader(array $imports, callable $callback): void
    {
        if (!function_exists('libxml_set_external_entity_loader')) {
            // PHP compiled without external entity loader support — run without redirection.
            $callback();

            return;
        }

        // Install our redirecting loader.
        // @phpstan-ignore-next-line
        libxml_set_external_entity_loader(
            static function (?string $public, ?string $system, array $context) use ($imports): ?string {
                if ($system !== null) {
                    // Try exact match first.
                    if (array_key_exists($system, $imports)) {
                        return $imports[$system];
                    }

                    // Try basename match (handles absolute path references).
                    $basename = basename($system);

                    foreach ($imports as $key => $localPath) {
                        if (basename((string) $key) === $basename) {
                            return $localPath;
                        }
                    }
                }

                // Unknown import — return null to suppress (avoid network fetch).
                return null;
            }
        );

        try {
            $callback();
        } finally {
            // Restore the default behaviour (null removes the custom loader).
            // @phpstan-ignore-next-line
            libxml_set_external_entity_loader(null);
        }
    }
}
