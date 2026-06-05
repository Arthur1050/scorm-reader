<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ScormReader\Manifest\ItemBuilder;
use ScormReader\Manifest\OrganizationBuilder;
use ScormReader\Manifest\ResourceBuilder;
use ScormReader\Package\ExportOptions;
use ScormReader\Package\ImportOptions;
use ScormReader\Package\ScormPackageCreator;
use ScormReader\Package\ScormPackageImporter;

// ─── helpers ──────────────────────────────────────────────────────────────────

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

// ─── 1. Importação SCORM 1.2 ─────────────────────────────────────────────────

$scorm12 = $importer->import(__DIR__ . '/fixtures/scorm12');
assert_true($scorm12->isValid(), 'SCORM 1.2 fixture should be valid.');
assert_true($scorm12->manifest()->version()->value === '1.2', 'SCORM 1.2 version should be detected.');
assert_true($scorm12->manifest()->title() === 'Golf Explained', 'SCORM 1.2 title should come from the default organization.');
assert_true(count($scorm12->launchableItems()) === 1, 'SCORM 1.2 should expose one launchable item.');
assert_true($scorm12->launchableItems()[0]->resource()?->launchPath() === 'index.html', 'SCORM 1.2 launch path should resolve.');

// ─── 2. Importação SCORM 2004 ─────────────────────────────────────────────────

$scorm2004 = $importer->import(__DIR__ . '/fixtures/scorm2004');
assert_true($scorm2004->isValid(), 'SCORM 2004 fixture should be valid.');
assert_true($scorm2004->manifest()->version()->value === '2004', 'SCORM 2004 version should be detected.');
assert_true($scorm2004->manifest()->rawSchemaVersion() === '2004 4th Edition', 'SCORM 2004 raw schema version should be preserved.');
assert_true(count($scorm2004->launchableItems()) === 1, 'SCORM 2004 should expose one launchable item.');
assert_true($scorm2004->launchableItems()[0]->resource()?->launchPath() === 'sco/index.html', 'SCORM 2004 xml:base should resolve launch path.');

// ─── 3. Fixture com path traversal ────────────────────────────────────────────

$invalidPath = $importer->import(__DIR__ . '/fixtures/invalid-path');
assert_true(!$invalidPath->isValid(), 'Invalid path fixture should fail validation.');
assert_true(in_array('RESOURCE_HREF_TRAVERSAL', issue_codes($invalidPath->validationResult()->errors()), true), 'Invalid path fixture should report traversal.');
assert_true(count($invalidPath->launchableItems()) === 0, 'Invalid path fixture should not expose launchable items.');

// ─── 4. Fixture com extensão perigosa (.php) ──────────────────────────────────

$dangerousExt = $importer->import(__DIR__ . '/fixtures/dangerous-ext');
assert_true(!$dangerousExt->isValid(), 'Dangerous extension fixture should fail validation.');
$dangerousCodes = issue_codes($dangerousExt->validationResult()->errors());
assert_true(
    in_array('DANGEROUS_FILE_EXTENSION', $dangerousCodes, true),
    'Dangerous extension fixture should report DANGEROUS_FILE_EXTENSION. Got: ' . implode(', ', $dangerousCodes),
);

// ─── 5. Fixture com dependency inválida ───────────────────────────────────────

$badDep = $importer->import(__DIR__ . '/fixtures/bad-dependency');
assert_true(!$badDep->isValid(), 'Bad dependency fixture should fail validation.');
assert_true(
    in_array('RESOURCE_DEPENDENCY_NOT_FOUND', issue_codes($badDep->validationResult()->errors()), true),
    'Bad dependency fixture should report RESOURCE_DEPENDENCY_NOT_FOUND.',
);

// ─── 6. Criação SCORM 1.2 em pasta ───────────────────────────────────────────

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

// ─── 7. Criação SCORM 1.2 em ZIP ─────────────────────────────────────────────

$zipPackage = $creator->exportToZip($createdZip, $exportRoot, new ExportOptions(overwrite: true));
assert_true(is_file($createdZip), 'Created package ZIP should exist.');
assert_true($zipPackage->isValid(), 'Created package ZIP should import as valid.');
assert_true(count($zipPackage->launchableItems()) === 1, 'Created package ZIP should expose one launchable item.');

// ─── 8. Criação SCORM 2004 ───────────────────────────────────────────────────

$creator2004 = ScormPackageCreator::scorm2004('Created 2004', 'MANIFEST-CREATED-2004', 'ORG-CREATED-2004')
    ->addScoContent('SCO 2004', 'sco/index.html', '<!doctype html><html><head><title>SCO 2004</title></head><body>2004</body></html>');
$created2004 = $creator2004->exportToDirectory($exportRoot . DIRECTORY_SEPARATOR . 'created-2004');
assert_true($created2004->isValid(), 'Created SCORM 2004 package should be valid.');
assert_true(str_contains(file_get_contents($exportRoot . DIRECTORY_SEPARATOR . 'created-2004' . DIRECTORY_SEPARATOR . 'imsmanifest.xml'), 'adlcp:scormType="sco"'), 'SCORM 2004 builder should use adlcp:scormType.');

// ─── 9. validateAfterExport = false não faz importação extra ─────────────────

$exportDirNoValidate = $exportRoot . DIRECTORY_SEPARATOR . 'no-validate';
$creatorNoVal = ScormPackageCreator::scorm12('No Validate', 'MANIFEST-NV', 'ORG-NV')
    ->addScoContent('SCO NV', 'index.html', '<!doctype html><html><body>NV</body></html>');

$noValidatePackage = $creatorNoVal->exportToDirectory(
    $exportDirNoValidate,
    new ExportOptions(validateAfterExport: false),
);
// When validateAfterExport=false, the package is still populated with the in-memory manifest.
assert_true($noValidatePackage->manifest()->title() === 'No Validate', 'validateAfterExport=false should use in-memory manifest.');
assert_true($noValidatePackage->validationResult()->isValid(), 'validateAfterExport=false should produce empty (valid) ValidationResult.');

// ─── 10. Múltiplas organizations ─────────────────────────────────────────────

$multiOrgCreator = ScormPackageCreator::scorm12('Multi Org Course', 'MANIFEST-MULTI', 'ORG-DEFAULT')
    ->addScoContent('Default SCO', 'default/index.html', '<!doctype html><html><body>Default</body></html>')
    ->addOrganization(
        OrganizationBuilder::create('ORG-EXTRA', 'Módulo Extra')
            ->addItem(
                ItemBuilder::create('ITEM-EXTRA-01', 'Aula Extra')
                    ->withResource('RES-001')
            )
    );

$multiOrgManifest = $multiOrgCreator->buildManifest();
assert_true(count($multiOrgManifest->organizations()) === 2, 'Creator should produce 2 organizations.');
assert_true($multiOrgManifest->defaultOrganizationIdentifier() === 'ORG-DEFAULT', 'Default org identifier should be preserved.');
assert_true($multiOrgManifest->organizations()[1]->identifier() === 'ORG-EXTRA', 'Second organization identifier should be ORG-EXTRA.');
assert_true($multiOrgManifest->organizations()[1]->title() === 'Módulo Extra', 'Second organization title should be correct.');

// ─── 11. ItemBuilder fluente ──────────────────────────────────────────────────

$parentItem = ItemBuilder::create('ITEM-PARENT', 'Módulo Principal')
    ->addChild(
        ItemBuilder::create('ITEM-CHILD-1', 'Aula 1')->withResource('RES-A')
    )
    ->addChild(
        ItemBuilder::create('ITEM-CHILD-2', 'Aula 2')->withResource('RES-B')->hidden()
    )
    ->build();

assert_true($parentItem->identifier() === 'ITEM-PARENT', 'ItemBuilder: identifier should be set.');
assert_true(count($parentItem->children()) === 2, 'ItemBuilder: should have 2 children.');
assert_true($parentItem->children()[0]->identifierRef() === 'RES-A', 'ItemBuilder: child resource ref should be RES-A.');
assert_true(!$parentItem->children()[1]->visible(), 'ItemBuilder: hidden child should not be visible.');

// ─── 12. ResourceBuilder fluente ─────────────────────────────────────────────

$scoResource = ResourceBuilder::sco('RES-BUILT', 'module/index.html')
    ->withFile('module/style.css')
    ->withDependency('RES-SHARED')
    ->build();

assert_true($scoResource->identifier() === 'RES-BUILT', 'ResourceBuilder: identifier should be set.');
assert_true($scoResource->isSco(), 'ResourceBuilder: SCO resource should be a SCO.');
assert_true($scoResource->launchPath() === 'module/index.html', 'ResourceBuilder: launch path should be set.');
assert_true(in_array('module/style.css', $scoResource->files(), true), 'ResourceBuilder: file should be in list.');
assert_true(in_array('module/index.html', $scoResource->files(), true), 'ResourceBuilder: href should be auto-added to files.');
assert_true(in_array('RES-SHARED', $scoResource->dependencies(), true), 'ResourceBuilder: dependency should be set.');

$assetResource = ResourceBuilder::asset('RES-ASSET')
    ->withFile('shared/common.js')
    ->build();

assert_true($assetResource->isAsset(), 'ResourceBuilder: asset resource should be an asset.');
assert_true(!$assetResource->isSco(), 'ResourceBuilder: asset resource should not be a SCO.');

// ─── 13. OrganizationBuilder fluente ─────────────────────────────────────────

$org = OrganizationBuilder::create('ORG-BUILT', 'Organização Construída')
    ->addItem(ItemBuilder::create('ITEM-B1', 'Aula B1')->withResource('RES-B1'))
    ->addItem(ItemBuilder::create('ITEM-B2', 'Aula B2')->withResource('RES-B2'))
    ->asDefault()
    ->build();

assert_true($org->identifier() === 'ORG-BUILT', 'OrganizationBuilder: identifier should be set.');
assert_true($org->isDefault(), 'OrganizationBuilder: asDefault() should mark as default.');
assert_true(count($org->items()) === 2, 'OrganizationBuilder: should have 2 items.');
assert_true($org->items()[0]->title() === 'Aula B1', 'OrganizationBuilder: first item title should be correct.');

// ─── 14. XSD validation (valid package) ──────────────────────────────────────

$xsdOptions = new ImportOptions(validateXsd: true, xsdErrorsAsWarnings: false);
$scorm12Xsd = $importer->import(__DIR__ . '/fixtures/scorm12', options: $xsdOptions);
// XSD validation on a well-formed package should not add any XSD errors.
$xsdErrors = array_filter(
    $scorm12Xsd->validationResult()->errors(),
    static fn ($issue) => str_starts_with($issue->code(), 'XSD_'),
);
assert_true(count($xsdErrors) === 0, 'Valid SCORM 1.2 package should pass XSD validation without errors. Got: ' . implode(', ', array_map(fn($i) => $i->code() . ': ' . $i->message(), $xsdErrors)));

// ─── 15. Limpeza automática de diretório temporário ──────────────────────────

$zipForCleanup = $exportRoot . DIRECTORY_SEPARATOR . 'cleanup-test.zip';
$creatorForCleanup = ScormPackageCreator::scorm12('Cleanup Test', 'MANIFEST-CLEANUP', 'ORG-CLEANUP')
    ->addScoContent('Cleanup SCO', 'index.html', '<!doctype html><html><body>Cleanup</body></html>');
$creatorForCleanup->exportToZip($zipForCleanup, $exportRoot, new ExportOptions(validateAfterExport: true));

$packageForCleanup = $importer->import($zipForCleanup, $exportRoot);
assert_true($packageForCleanup->wasExtracted(), 'Package imported from ZIP should be marked as extracted.');

$tempDir = $packageForCleanup->temporaryDirectory();
assert_true(is_string($tempDir) && is_dir($tempDir), 'Temporary directory should exist before cleanUp.');

$packageForCleanup->cleanUp();
assert_true(!is_dir((string) $tempDir), 'cleanUp() should remove the temporary directory.');

// Call again — must be idempotent.
$packageForCleanup->cleanUp();

// ─── cleanup ──────────────────────────────────────────────────────────────────

remove_test_directory($exportRoot);

echo "All tests passed.\n";
