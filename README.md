# SCORM Reader

Biblioteca PHP backend para importar, ler, validar, criar e exportar pacotes SCORM 1.2 e SCORM 2004.

O modulo trabalha com uma representacao interna unica de manifesto (`Manifest`, `Organization`, `Item` e `Resource`), independente da versao SCORM. Essa representacao pode vir de um pacote existente, via importacao, ou pode ser criada pela propria lib e exportada como pasta ou ZIP SCORM.

## Status

Ja esta implementado:

- importacao de ZIP ou pasta;
- extracao segura de ZIP;
- parser de `imsmanifest.xml`;
- deteccao de SCORM 1.2 e SCORM 2004;
- leitura de `organizations`, `items`, `resources`, `files`, `dependencies` e `xml:base`;
- resolucao de `item.identifierref` para `Resource`;
- listagem de itens SCO lancaveis;
- validacoes de pacote, manifesto, paths, limites, MIME e extensoes;
- validacao estrutural opcional via XSD bundled (SCORM 1.2 e 2004);
- criacao fluente de pacotes SCORM;
- builders fluentes para `Item`, `Resource` e `Organization`;
- suporte a multiplas `organizations` por pacote;
- criacao de manifestos SCORM 1.2 e SCORM 2004;
- exportacao para pasta;
- exportacao para ZIP;
- validacao automatica apos exportacao;
- limpeza automatica de diretorios temporarios de extracao;
- serializacao para array/JSON.

Ainda nao esta implementado:

- runtime SCORM, CMI, tracking, sequencing ou comunicacao LMS.

## Requisitos

- PHP 8.1 ou superior;
- `ext-dom`;
- `ext-libxml`;
- `ext-simplexml`;
- `ext-zlib`;
- opcional: `ext-zip`.

Para ZIP, a biblioteca prefere `ZipArchive`. Quando `ZipArchive` nao esta disponivel, tenta usar `PharData`.

## Instalacao

O projeto esta preparado para autoload PSR-4 via Composer:

```bash
composer dump-autoload
```

Depois use:

```php
require __DIR__ . '/vendor/autoload.php';
```

Sem Composer, use um autoloader simples como `tests/bootstrap.php`:

```php
require __DIR__ . '/tests/bootstrap.php';
```

## Importar SCORM

Importando um ZIP:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ScormReader\Exception\InvalidScormPackageException;
use ScormReader\Package\ScormPackageImporter;

$importer = new ScormPackageImporter();

try {
    $package = $importer->import('C:/materiais/curso.zip');

    if (!$package->isValid()) {
        print_r($package->validationResult()->toArray());
        exit(1);
    }

    foreach ($package->launchableItems() as $item) {
        echo $item->title() . ': ' . $item->resource()?->launchPath() . PHP_EOL;
    }
} catch (InvalidScormPackageException $exception) {
    echo 'Falha ao importar SCORM: ' . $exception->getMessage();
}
```

Importando uma pasta ja extraida:

```php
$package = $importer->import('C:/materiais/curso-extraido');
```

O `imsmanifest.xml` precisa estar diretamente na raiz do pacote.

### Limpeza automatica do diretorio temporario

Quando o pacote e importado de um ZIP, a lib extrai os arquivos em um diretorio temporario.
Esse diretorio e removido automaticamente quando o objeto `ScormPackage` e destruido.

Voce tambem pode chamar `cleanUp()` manualmente:

```php
$package = $importer->import('curso.zip');

// ... usa o pacote ...

$package->cleanUp(); // Remove o tempdir imediatamente.
// Chamar novamente e seguro (idempotente).
```

## Criar SCORM

A forma mais simples de criar material SCORM e usar `ScormPackageCreator`.

Criando um pacote SCORM 1.2 em pasta:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ScormReader\Package\ScormPackageCreator;

$package = ScormPackageCreator::scorm12('Curso de Boas Vindas')
    ->addScoContent(
        title: 'Introducao',
        launchPath: 'index.html',
        contents: '<!doctype html><html><body>Conteudo do SCO</body></html>',
    )
    ->exportToDirectory('C:/saida/curso-scorm');

echo $package->isValid() ? 'Pacote criado' : 'Pacote criado com problemas';
```

Criando um pacote SCORM 2004 em ZIP:

```php
use ScormReader\Package\ExportOptions;
use ScormReader\Package\ScormPackageCreator;

$package = ScormPackageCreator::scorm2004('Treinamento Interno')
    ->addSco(
        title: 'Aula 1',
        launchPath: 'aula-1/index.html',
        sourcePath: __DIR__ . '/conteudo/aula-1/index.html',
        files: [
            'aula-1/style.css' => __DIR__ . '/conteudo/aula-1/style.css',
            'aula-1/app.js' => __DIR__ . '/conteudo/aula-1/app.js',
        ],
    )
    ->exportToZip(
        destinationZipPath: 'C:/saida/treinamento.zip',
        options: new ExportOptions(overwrite: true),
    );
```

`addSco()` cria o resource SCO, cria o item correspondente na organization default e registra os arquivos declarados no manifesto.

O array `files` aceita dois formatos:

```php
// Apenas declarar no manifesto:
['aula-1/style.css']

// Declarar no manifesto e copiar de um arquivo local:
['aula-1/style.css' => __DIR__ . '/conteudo/style.css']
```

Tambem e possivel adicionar arquivos manualmente:

```php
$creator = ScormPackageCreator::scorm12('Curso')
    ->addSco('Aula', 'index.html')
    ->addFileContent('index.html', '<!doctype html><html><body>Aula</body></html>')
    ->addFileFromPath(__DIR__ . '/assets/logo.png', 'assets/logo.png');
```

### Multiplas organizations

Use `addOrganization()` para incluir organizations adicionais alem da organization default.
Combine com `OrganizationBuilder` para montar a arvore de navegacao:

```php
use ScormReader\Manifest\ItemBuilder;
use ScormReader\Manifest\OrganizationBuilder;
use ScormReader\Package\ScormPackageCreator;

$creator = ScormPackageCreator::scorm12('Curso Completo')
    ->addScoContent('Introducao', 'intro/index.html', $htmlIntro)
    ->addOrganization(
        OrganizationBuilder::create('ORG-MODULO-2', 'Modulo 2')
            ->addItem(
                ItemBuilder::create('ITEM-M2-A1', 'Aula 2.1')->withResource('RES-001')
            )
            ->addItem(
                ItemBuilder::create('ITEM-M2-A2', 'Aula 2.2')->withResource('RES-002')
            )
    );

$manifest = $creator->buildManifest();
echo count($manifest->organizations()); // 2
```

A organization default continua sendo a primeira e e referenciada por `defaultOrganizationIdentifier`.

## Builders Fluentes

A biblioteca oferece builders fluentes para construir modelos de forma mais legivel.

### ItemBuilder

```php
use ScormReader\Manifest\ItemBuilder;

// Item simples
$item = ItemBuilder::create('ITEM-001', 'Aula 1')
    ->withResource('RES-001')
    ->withParameters('?mode=browse')
    ->build();

// Hierarquia aninhada
$modulo = ItemBuilder::create('ITEM-MOD', 'Modulo 1')
    ->addChild(
        ItemBuilder::create('ITEM-A', 'Aula A')->withResource('RES-A')
    )
    ->addChild(
        ItemBuilder::create('ITEM-B', 'Aula B')->withResource('RES-B')->hidden()
    )
    ->build();
```

Metodos disponiveis:

- `create($identifier, $title)` — factory estatico;
- `withResource($identifierRef)` — associa um resource;
- `withParameters($parameters)` — parametros de lancamento (ex: `'?mode=normal'`);
- `hidden()` — define `isvisible="false"`;
- `addChild(ItemBuilder $child)` — adiciona sub-item;
- `build()` — constroi o `Item`.

### ResourceBuilder

```php
use ScormReader\Manifest\ResourceBuilder;

// SCO (o href e automaticamente incluido na lista de files)
$sco = ResourceBuilder::sco('RES-AULA-1', 'aula-1/index.html')
    ->withFile('aula-1/style.css')
    ->withFile('aula-1/app.js')
    ->withDependency('RES-SHARED')
    ->build();

// Asset compartilhado
$asset = ResourceBuilder::asset('RES-SHARED')
    ->withFile('shared/api.js')
    ->withFile('shared/style.css')
    ->build();

// SCO com xml:base
$sco = ResourceBuilder::sco('RES-002', 'index.html')
    ->withXmlBase('modulo-2/')
    ->build();
// launchPath resolvido: 'modulo-2/index.html'
```

Metodos disponiveis:

- `sco($identifier, $href)` — factory para SCO, inclui href na lista de files;
- `asset($identifier)` — factory para asset;
- `withType($type)` — substitui o atributo `type` (default: `'webcontent'`);
- `withFile($href)` — declara arquivo no manifesto;
- `withDependency($identifierRef)` — adiciona referencia de dependencia;
- `withXmlBase($xmlBase)` — define `xml:base`; o `launchPath` e resolvido automaticamente;
- `build()` — constroi o `Resource`.

### OrganizationBuilder

```php
use ScormReader\Manifest\ItemBuilder;
use ScormReader\Manifest\OrganizationBuilder;

$org = OrganizationBuilder::create('ORG-01', 'Modulo Principal')
    ->addItem(
        ItemBuilder::create('ITEM-INTRO', 'Introducao')->withResource('RES-001')
    )
    ->addItem(
        ItemBuilder::create('ITEM-SUBS', 'Sub-modulos')
            ->addChild(ItemBuilder::create('ITEM-S1', 'Sub 1')->withResource('RES-S1'))
            ->addChild(ItemBuilder::create('ITEM-S2', 'Sub 2')->withResource('RES-S2'))
    )
    ->asDefault()
    ->build();
```

Metodos disponiveis:

- `create($identifier, $title)` — factory estatico;
- `addItem(ItemBuilder|Item $item)` — adiciona item de topo;
- `withStructure($structure)` — define o atributo `structure` (default: `'hierarchical'`);
- `asDefault()` — marca como organization default ao montar `Manifest` manualmente;
- `build()` — constroi a `Organization`.

## Exportar Manifesto Avancado

Para casos mais complexos, o dev pode montar os modelos diretamente e gerar o XML com `ManifestBuilder`:

```php
use ScormReader\Manifest\Manifest;
use ScormReader\Manifest\ManifestBuilder;
use ScormReader\Manifest\OrganizationBuilder;
use ScormReader\Manifest\ItemBuilder;
use ScormReader\Manifest\ResourceBuilder;
use ScormReader\Version\ScormVersion;

$resource = ResourceBuilder::sco('RES-AULA-1', 'aula-1/index.html')
    ->withFile('aula-1/style.css')
    ->build();

$org = OrganizationBuilder::create('ORG-CURSO', 'Curso')
    ->addItem(ItemBuilder::create('ITEM-AULA-1', 'Aula 1')->withResource('RES-AULA-1'))
    ->asDefault()
    ->build();

$manifest = new Manifest(
    identifier: 'MANIFEST-CURSO',
    version: ScormVersion::SCORM_2004,
    rawSchemaVersion: '2004 4th Edition',
    title: 'Curso',
    organizations: [$org],
    resources: [$resource->build()],
    defaultOrganizationIdentifier: 'ORG-CURSO',
);

$xml = (new ManifestBuilder())->build($manifest);
```

Para exportar esse manifesto com uma pasta de arquivos:

```php
use ScormReader\Package\ScormPackageExporter;

$package = (new ScormPackageExporter())->exportManifestToZip(
    manifest: $manifest,
    sourceDirectory: __DIR__ . '/conteudo',
    destinationZipPath: 'C:/saida/curso.zip',
);
```

## Opcoes

### ImportOptions

`ImportOptions` controla validacoes usadas na importacao e tambem na validacao de pacotes criados:

```php
use ScormReader\Package\ImportOptions;

$options = new ImportOptions(
    maxFileCount: 5000,
    maxTotalBytes: 500 * 1024 * 1024,
    maxFileBytes: 200 * 1024 * 1024,
    allowExternalResources: false,
    allowUnknownFileExtensions: false,
    validateMimeTypes: true,
    requireScoForLaunchableItems: true,
    validateXsd: false,      // habilita validacao XSD (desligado por padrao)
    xsdErrorsAsWarnings: true, // erros XSD viram warnings em vez de erros
);
```

| Campo | Padrao | Descricao |
|---|---|---|
| `maxFileCount` | `5000` | Limite de arquivos no pacote |
| `maxTotalBytes` | `500 MB` | Tamanho total descomprimido |
| `maxFileBytes` | `200 MB` | Tamanho maximo por arquivo |
| `allowExternalResources` | `false` | Permite URLs externas em hrefs |
| `allowUnknownFileExtensions` | `false` | Permite extensoes desconhecidas |
| `validateMimeTypes` | `true` | Valida MIME type via `finfo` |
| `requireScoForLaunchableItems` | `true` | Exige `scormType=sco` para item lancavel |
| `validateXsd` | `false` | Ativa validacao XSD estrutural |
| `xsdErrorsAsWarnings` | `true` | Trata erros XSD como warnings |

### ExportOptions

`ExportOptions` controla o comportamento da exportacao:

```php
use ScormReader\Package\ExportOptions;

$exportOptions = new ExportOptions(
    overwrite: true,
    validateAfterExport: true,
    validationOptions: $options,
);
```

| Campo | Padrao | Descricao |
|---|---|---|
| `overwrite` | `false` | Permite sobrescrever destino existente |
| `validateAfterExport` | `true` | Reimporta o pacote gerado para validar |
| `validationOptions` | `ImportOptions()` | Regras usadas na validacao pos-exportacao |

Quando `validateAfterExport = false`, o pacote retornado usa o manifesto ja construido em memoria, sem nenhuma reimportacao.

## Validacao XSD (opcional)

A biblioteca inclui schemas XSD bundled para validacao estrutural dos manifestos.
A validacao e desativada por padrao para nao rejeitar pacotes comerciais com desvios menores.

```php
use ScormReader\Package\ImportOptions;
use ScormReader\Package\ScormPackageImporter;

// Ativando XSD como warnings (padrao ao habilitar)
$package = (new ScormPackageImporter())->import(
    'curso.zip',
    options: new ImportOptions(validateXsd: true),
);

foreach ($package->validationResult()->warnings() as $warn) {
    if (str_starts_with($warn->code(), 'XSD_')) {
        echo $warn->message() . PHP_EOL;
    }
}

// Ativando XSD como erros (rejeita pacotes com falhas estruturais)
$package = (new ScormPackageImporter())->import(
    'curso.zip',
    options: new ImportOptions(validateXsd: true, xsdErrorsAsWarnings: false),
);
```

Os schemas bundled cobrem:

| Versao | Schema principal | Extensao ADL |
|---|---|---|
| SCORM 1.2 | `imscp_rootv1p1p2.xsd` | `adlcp_rootv1p2.xsd` |
| SCORM 2004 | `imscp_v1p1.xsd` | `adlcp_v1p3.xsd` |

Os imports entre schemas sao resolvidos localmente, sem nenhuma chamada de rede em runtime.

## Fluxo de Importacao

1. Recebe um ZIP ou uma pasta.
2. Se for ZIP, extrai para um diretorio temporario usando `SafeZipExtractor`.
3. Valida limites, extensoes perigosas, nomes perigosos, paths absolutos, traversal e symlinks.
4. Procura `imsmanifest.xml` na raiz.
5. Faz parser do manifesto XML.
6. Se `validateXsd = true`, valida a estrutura contra o schema bundled.
7. Detecta a versao SCORM.
8. Monta organizations, items e resources.
9. Resolve `identifierref`, `href`, `xml:base`, `file` e `dependency`.
10. Valida consistencia do manifesto e existencia dos arquivos.
11. Retorna um `ScormPackage`. O diretorio temporario e limpo automaticamente ao destruir o objeto.

## Fluxo de Criacao

1. O dev monta o pacote com `ScormPackageCreator` ou monta um `Manifest` manualmente.
2. A lib gera o `imsmanifest.xml` com `ManifestBuilder`.
3. A lib escreve os arquivos do pacote na pasta de destino.
4. Se solicitado, compacta a pasta em ZIP.
5. Se `validateAfterExport = true`, reimporta o pacote gerado para validar o resultado.
6. Se `validateAfterExport = false`, usa o manifesto ja em memoria, sem I/O extra.
7. Retorna um `ScormPackage`.

## Retorno

Chamando:

```php
echo json_encode($package->toArray(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

O retorno segue este formato geral:

```json
{
    "identifier": "MANIFEST-001",
    "schemaVersion": "1.2",
    "title": "Golf Explained",
    "defaultOrganizationIdentifier": "ORG-001",
    "organizations": [
        {
            "identifier": "ORG-001",
            "title": "Golf Explained",
            "items": [
                {
                    "identifier": "ITEM-001",
                    "title": "Golf Explained",
                    "resourceIdentifier": "RES-001",
                    "visible": true,
                    "launchable": true,
                    "resource": {
                        "identifier": "RES-001",
                        "type": "webcontent",
                        "scormType": "sco",
                        "href": "index.html",
                        "hrefExists": true,
                        "files": ["index.html"]
                    }
                }
            ]
        }
    ],
    "resources": [
        {
            "identifier": "RES-001",
            "type": "webcontent",
            "scormType": "sco",
            "href": "index.html",
            "hrefExists": true,
            "files": ["index.html"]
        }
    ],
    "launchableItems": [
        {
            "identifier": "ITEM-001",
            "title": "Golf Explained",
            "resourceIdentifier": "RES-001",
            "visible": true,
            "launchable": true
        }
    ]
}
```

Use `toArray(false)` para retornar somente os dados do manifesto. Use `toArray()` para incluir metadados como `sourcePath`, `packageRoot`, `extracted` e `validation`.

## API Principal

### ScormPackageCreator

Fachada para criar pacotes novos.

Metodos principais:

- `scorm12($title)`;
- `scorm2004($title)`;
- `create($title, $version)`;
- `addSco($title, $launchPath, $sourcePath = null, ...)`;
- `addScoContent($title, $launchPath, $contents, ...)`;
- `addAsset($identifier, $files, $xmlBase = null)`;
- `addItem(Item $item)`;
- `addResource(Resource $resource)`;
- `addOrganization(Organization|OrganizationBuilder $organization)`;
- `addFileFromPath($sourcePath, $targetPath = null)`;
- `addFileContent($targetPath, $contents)`;
- `buildManifest()`;
- `exportToDirectory($destinationDirectory)`;
- `exportToZip($destinationZipPath)`.

### ScormPackageImporter

Classe de entrada para leitura de pacotes existentes.

```php
$package = (new ScormPackageImporter())->import($sourcePath);
```

Parametros:

- `sourcePath`: caminho do ZIP ou da pasta extraida;
- `workDirectory`: diretorio opcional usado para extrair ZIPs;
- `options`: instancia opcional de `ImportOptions`.

### ScormPackageExporter

Exporta pacotes existentes, manifestos manuais ou pacotes criados pela fachada.

Metodos principais:

- `export($package, $destinationZipPath)`;
- `exportPackageToDirectory($package, $destinationDirectory)`;
- `exportManifestToDirectory($manifest, $sourceDirectory, $destinationDirectory)`;
- `exportManifestToZip($manifest, $sourceDirectory, $destinationZipPath)`;
- `exportCreatedPackageToDirectory($creator, $destinationDirectory)`;
- `exportCreatedPackageToZip($creator, $destinationZipPath)`.

### ManifestBuilder

Gera XML SCORM a partir de um `Manifest`.

```php
$xml = (new ManifestBuilder())->build($manifest);
```

Ele gera:

- `adlcp:scormtype` para SCORM 1.2;
- `adlcp:scormType` para SCORM 2004;
- `schemaversion` correspondente;
- namespaces principais de content packaging e ADL.

### ScormPackage

Representa o pacote importado ou exportado.

Metodos principais:

- `sourcePath()`;
- `packageRoot()`;
- `manifest()`;
- `validationResult()`;
- `isValid()`;
- `wasExtracted()`;
- `temporaryDirectory()`;
- `cleanUp()` — remove o diretorio temporario imediatamente;
- `launchableItems()`;
- `toArray()`.

### ItemBuilder

Builder fluente para `Item`. Ver secao [Builders Fluentes](#builders-fluentes).

### ResourceBuilder

Builder fluente para `Resource`. Ver secao [Builders Fluentes](#builders-fluentes).

### OrganizationBuilder

Builder fluente para `Organization`. Ver secao [Builders Fluentes](#builders-fluentes).

## Modelos

### Manifest

Representa o `imsmanifest.xml` normalizado.

Metodos principais:

- `identifier()`;
- `version()`;
- `rawSchemaVersion()`;
- `title()`;
- `organizations()`;
- `resources()`;
- `resourceMap()`;
- `defaultOrganization()`;
- `findOrganization($identifier)`;
- `findResource($identifier)`;
- `launchableItems()`;
- `toArray()`.

### Item

Representa um item da arvore de navegacao.

Um item e lancavel quando:

- esta visivel;
- possui resource resolvido;
- o resource e `sco`;
- existe caminho de launch;
- o arquivo de launch existe ou nao foi marcado como inexistente.

### Resource

Representa um recurso do manifesto.

Campos principais:

- `identifier`;
- `type`;
- `scormType`;
- `href`;
- `launchPath`;
- `files`;
- `dependencies`;
- `xmlBase`.

## Validacoes

A biblioteca valida:

- existencia do `imsmanifest.xml` na raiz;
- versao SCORM suportada;
- formato XML do manifesto;
- estrutura XSD quando `validateXsd = true`;
- `resource` com `scormType` valido;
- SCO com `href`;
- `href` apontando para arquivo existente dentro do pacote;
- arquivos declarados em `file`;
- dependencias declaradas em `dependency`;
- paths com `../`;
- paths absolutos;
- paths com backslash;
- URLs externas quando `allowExternalResources` for `false`;
- extensoes permitidas;
- extensoes perigosas;
- MIME type quando `fileinfo/finfo` estiver disponivel;
- `.htaccess` e `web.config`;
- symlinks em ZIP ou pasta;
- quantidade maxima de arquivos;
- tamanho maximo por arquivo;
- tamanho total maximo do pacote.

Erros e avisos sao retornados por `ValidationResult`:

```php
$validation = $package->validationResult();

foreach ($validation->errors() as $error) {
    echo $error->code() . ': ' . $error->message() . PHP_EOL;
}

foreach ($validation->warnings() as $warning) {
    echo '[warn] ' . $warning->code() . ': ' . $warning->message() . PHP_EOL;
}
```

## SCORM 1.2 e SCORM 2004

A biblioteca usa a mesma representacao interna para as duas versoes.

Ela normaliza diferencas comuns, incluindo:

- SCORM 1.2: `adlcp:scormtype`;
- SCORM 2004: `adlcp:scormType`.

Na leitura, ambos ficam disponiveis como `Resource::scormType()`. Na criacao, `ManifestBuilder` gera o atributo correto para a versao escolhida.

## Seguranca

Pacotes SCORM sao entrada nao confiavel. A biblioteca bloqueia ou sinaliza:

- zip slip/path traversal;
- arquivo fora da raiz do pacote;
- caminho absoluto;
- symlink;
- arquivo executavel ou server-side;
- nomes sensiveis para servidores web;
- URLs externas quando desabilitadas;
- pacotes acima dos limites configurados.

Ao publicar o conteudo, a aplicacao deve servir os arquivos em local isolado, sem permissao de executar scripts server-side dentro da pasta do material.

## Testes

Execute:

```bash
php tests/run.php
```

Os testes cobrem:

- importacao SCORM 1.2;
- importacao SCORM 2004 com `xml:base` e `dependency`;
- manifesto invalido com path traversal;
- extensao perigosa (`.php`) no manifesto;
- `dependency` apontando para resource inexistente;
- criacao de pacote SCORM 1.2 em pasta;
- criacao de pacote SCORM 1.2 em ZIP;
- criacao de pacote SCORM 2004;
- exportacao com `validateAfterExport = false`;
- multiplas organizations via `addOrganization()`;
- `ItemBuilder` com hierarquia e item hidden;
- `ResourceBuilder` SCO e asset com files e dependencies;
- `OrganizationBuilder` com items e `asDefault()`;
- validacao XSD em pacote valido;
- `cleanUp()` removendo diretorio temporario.

Para validar sintaxe de todos os PHP no PowerShell:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

## Estrutura do Projeto

```text
src/
+-- Exception/
+-- Manifest/
|   +-- Item.php
|   +-- ItemBuilder.php          (novo)
|   +-- Manifest.php
|   +-- ManifestBuilder.php
|   +-- ManifestParser.php
|   +-- Organization.php
|   +-- OrganizationBuilder.php  (novo)
|   +-- Resource.php
|   `-- ResourceBuilder.php      (novo)
+-- Package/
+-- Security/
+-- Validation/
|   +-- xsd/
|   |   +-- scorm12/             (schemas bundled para SCORM 1.2)
|   |   `-- scorm2004/           (schemas bundled para SCORM 2004)
|   +-- ManifestValidator.php
|   +-- PackageValidator.php
|   +-- ValidationIssue.php
|   +-- ValidationResult.php
|   `-- XsdValidator.php         (novo)
`-- Version/

tests/
+-- fixtures/
|   +-- bad-dependency/          (novo)
|   +-- dangerous-ext/           (novo)
|   +-- invalid-path/
|   +-- scorm12/
|   `-- scorm2004/
+-- bootstrap.php
`-- run.php
```

Responsabilidades:

- `Package`: importacao, criacao, exportacao, arquivos, opcoes e pacote resultante;
- `Manifest`: parser, builder XML, modelos normalizados e builders fluentes;
- `Version`: deteccao e enumeracao de versoes suportadas;
- `Validation`: validadores, XSD, erros e avisos;
- `Security`: regras de path seguro e extracao segura;
- `Exception`: excecoes especificas da biblioteca.

## Referencias

- ADL, SCORM Best Practices Guide for Programmers: descreve `imsmanifest.xml`, `organizations`, `resources`, `schemaVersion` e `resource.href`.
- ADL, SCORM 2004 4th Edition Testing Requirements: requisitos de manifest, caminhos, `resource`, `href`, SCO e asset.
