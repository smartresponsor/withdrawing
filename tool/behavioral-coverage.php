<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$unexpectedSurfaces = [];

foreach (['templates', 'assets'] as $directory) {
    $path = $root.DIRECTORY_SEPARATOR.$directory;
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $unexpectedSurfaces[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}

$src = $root.DIRECTORY_SEPARATOR.'src';
if (is_dir($src)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || 'php' !== strtolower($file->getExtension())) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        if (str_contains($contents, '#[Route(') || str_contains($contents, 'Symfony\\Component\\Routing\\Attribute\\Route')) {
            $unexpectedSurfaces[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}

foreach (glob($root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'routes*') ?: [] as $routePath) {
    if (is_file($routePath) && '' !== trim((string) file_get_contents($routePath))) {
        $unexpectedSurfaces[] = str_replace('\\', '/', substr($routePath, strlen($root) + 1));
    }
}

if ([] !== $unexpectedSurfaces) {
    throw new RuntimeException(sprintf(
        'Behavioral/UI surface inventory must be updated before evidence can be generated: %s',
        implode(', ', array_values(array_unique($unexpectedSurfaces))),
    ));
}

$kernelTest = (string) file_get_contents($root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Kernel'.DIRECTORY_SEPARATOR.'StandaloneKernelTest.php');
$functionalEligible = ['standalone:kernel-boot', 'standalone:console-list'];
$functionalCovered = [];
if (str_contains($kernelTest, 'testStandaloneKernelBoots')) {
    $functionalCovered[] = 'standalone:kernel-boot';
}
if (str_contains($kernelTest, 'testStandaloneConsoleCanListCommands')) {
    $functionalCovered[] = 'standalone:console-list';
}

$evidence = [
    'schema' => 'behavioral-ui-coverage-v2',
    'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
    'producer' => ['kind' => 'repository_script', 'script' => 'test:behavioral-coverage'],
    'dimensions' => [
        'functional' => ['eligible' => $functionalEligible, 'covered' => $functionalCovered],
        'behavioral' => ['eligible' => [], 'covered' => []],
        'ui' => ['eligible' => [], 'covered' => []],
        'critical' => ['eligible' => [], 'covered' => []],
    ],
];

$coverageDirectory = $root.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'coverage';
if (!is_dir($coverageDirectory) && !mkdir($coverageDirectory, 0777, true) && !is_dir($coverageDirectory)) {
    throw new RuntimeException('Unable to create behavioral coverage directory.');
}

$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$evidencePath = $coverageDirectory.DIRECTORY_SEPARATOR.'behavioral-ui.json';
if (false === file_put_contents($evidencePath, $json.PHP_EOL)) {
    throw new RuntimeException('Unable to write behavioral/UI coverage evidence.');
}

printf(
    'Behavioral/UI coverage evidence: functional %d/%d; behavioral %d/%d; UI %d/%d; critical %d/%d.%s',
    count($functionalCovered),
    count($functionalEligible),
    0,
    0,
    0,
    0,
    0,
    0,
    PHP_EOL,
);
