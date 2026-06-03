<?php

declare(strict_types=1);

namespace ScormReader\Version;

use DOMDocument;
use DOMElement;
use ScormReader\Exception\UnsupportedScormVersionException;

final class VersionDetector
{
    public function detect(DOMDocument $document): ScormVersion
    {
        $rawSchemaVersion = $this->rawSchemaVersion($document);

        if ($rawSchemaVersion !== null) {
            $normalized = strtolower(trim($rawSchemaVersion));

            if ($normalized === '1.2' || str_contains($normalized, 'scorm 1.2')) {
                return ScormVersion::SCORM_12;
            }

            if (str_contains($normalized, '2004')) {
                return ScormVersion::SCORM_2004;
            }
        }

        $root = $document->documentElement;
        if ($root instanceof DOMElement) {
            $namespaceFingerprint = strtolower(implode(' ', [
                $root->namespaceURI ?? '',
                $root->getAttribute('xmlns'),
                $root->getAttribute('xmlns:adlcp'),
                $root->getAttribute('version'),
            ]));

            if (str_contains($namespaceFingerprint, 'adlcp_rootv1p2') || str_contains($namespaceFingerprint, 'imscp_rootv1p1p2')) {
                return ScormVersion::SCORM_12;
            }

            if (str_contains($namespaceFingerprint, 'adlcp_v1p3') || str_contains($namespaceFingerprint, 'imscp_v1p1') || str_starts_with($root->getAttribute('version'), '1.3')) {
                return ScormVersion::SCORM_2004;
            }
        }

        throw new UnsupportedScormVersionException('Unable to detect a supported SCORM version from imsmanifest.xml.');
    }

    public function rawSchemaVersion(DOMDocument $document): ?string
    {
        $root = $document->documentElement;

        if (!$root instanceof DOMElement) {
            return null;
        }

        foreach ($root->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->localName !== 'metadata') {
                continue;
            }

            foreach ($node->childNodes as $metadataNode) {
                if ($metadataNode instanceof DOMElement && strtolower($metadataNode->localName) === 'schemaversion') {
                    $value = trim($metadataNode->textContent);

                    return $value !== '' ? $value : null;
                }
            }
        }

        return null;
    }
}
