<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ScormReader\Package\ScormPackageImporter;
use ScormReader\Package\ExportOptions;
use ScormReader\Package\ScormPackageCreator;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function issue_codes(array $issues): array
{
    return array_map(static fn ($issue): string => $issue->code(), $issues);
}

function remove_test_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $fileInfo) {
        $fileInfo->isDir() ? rmdir($fileInfo->getPathname()) : unlink($fileInfo->getPathname());
    }

    rmdir($directory);
}

$importer = new ScormPackageImporter();

$scorm12 = $importer->import(__DIR__ . '/fixtures/scorm12');
assert_true($scorm12->isValid(), 'SCORM 1.2 fixture should be valid.');
assert_true($scorm12->manifest()->version()->value === '1.2', 'SCORM 1.2 version should be detected.');
assert_true($scorm12->manifest()->title() === 'Golf Explained', 'SCORM 1.2 title should come from the default organization.');
assert_true(count($scorm12->launchableItems()) === 1, 'SCORM 1.2 should expose one launchable item.');
assert_true($scorm12->launchableItems()[0]->resource()?->launchPath() === 'index.html', 'SCORM 1.2 launch path should resolve.');

$scorm2004 = $importer->import(__DIR__ . '/fixtures/scorm2004');
assert_true($scorm2004->isValid(), 'SCORM 2004 fixture should be valid.');
assert_true($scorm2004->manifest()->version()->value === '2004', 'SCORM 2004 version should be detected.');
assert_true($scorm2004->manifest()->rawSchemaVersion() === '2004 4th Edition', 'SCORM 2004 raw schema version should be preserved.');
assert_true(count($scorm2004->launchableItems()) === 1, 'SCORM 2004 should expose one launchable item.');
assert_true($scorm2004->launchableItems()[0]->resource()?->launchPath() === 'sco/index.html', 'SCORM 2004 xml:base should resolve launch path.');

$invalidPath = $importer->import(__DIR__ . '/fixtures/invalid-path');
assert_true(!$invalidPath->isValid(), 'Invalid path fixture should fail validation.');
assert_true(in_array('RESOURCE_HREF_TRAVERSAL', issue_codes($invalidPath->validationResult()->errors()), true), 'Invalid path fixture should report traversal.');
assert_true(count($invalidPath->launchableItems()) === 0, 'Invalid path fixture should not expose launchable items.');

$exportRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scorm-reader-tests-' . bin2hex(random_bytes(4));
$createdDirectory = $exportRoot . DIRECTORY_SEPARATOR . 'created-directory';
$createdZip = $exportRoot . DIRECTORY_SEPARATOR . 'created.zip';
mkdir($exportRoot, 0775, true);

$creator = ScormPackageCreator::scorm12('Created Course', 'MANIFEST-CREATED', 'ORG-CREATED')
    ->addScoContent('Intro SCO', 'index.html', '<!doctype html><html><head><title>Intro SCO</title></head><body>Intro</body></html>');

$createdPackage = $creator->exportToDirectory($createdDirectory);
assert_true($createdPackage->isValid(), 'Created package directory should be valid.');
assert_true($createdPackage->manifest()->version()->value === '1.2', 'Created package should preserve SCORM 1.2.');
assert_true(count($createdPackage->launchableItems()) === 1, 'Created package should expose one launchable item.');
assert_true(is_file($createdDirectory . DIRECTORY_SEPARATOR . 'imsmanifest.xml'), 'Created package should write imsmanifest.xml.');
assert_true(str_contains(file_get_contents($createdDirectory . DIRECTORY_SEPARATOR . 'imsmanifest.xml'), 'adlcp:scormtype="sco"'), 'SCORM 1.2 builder should use adlcp:scormtype.');

$zipPackage = $creator->exportToZip($createdZip, $exportRoot, new ExportOptions(overwrite: true));
assert_true(is_file($createdZip), 'Created package ZIP should exist.');
assert_true($zipPackage->isValid(), 'Created package ZIP should import as valid.');
assert_true(count($zipPackage->launchableItems()) === 1, 'Created package ZIP should expose one launchable item.');

$creator2004 = ScormPackageCreator::scorm2004('Created 2004', 'MANIFEST-CREATED-2004', 'ORG-CREATED-2004')
    ->addScoContent('SCO 2004', 'sco/index.html', '<!doctype html><html><head><title>SCO 2004</title></head><body>2004</body></html>');
$created2004 = $creator2004->exportToDirectory($exportRoot . DIRECTORY_SEPARATOR . 'created-2004');
assert_true($created2004->isValid(), 'Created SCORM 2004 package should be valid.');
assert_true(str_contains(file_get_contents($exportRoot . DIRECTORY_SEPARATOR . 'created-2004' . DIRECTORY_SEPARATOR . 'imsmanifest.xml'), 'adlcp:scormType="sco"'), 'SCORM 2004 builder should use adlcp:scormType.');

remove_test_directory($exportRoot);

echo "All tests passed.\n";
