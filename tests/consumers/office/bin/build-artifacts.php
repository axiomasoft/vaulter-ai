#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Local artifact builder for the tests/consumers/office stand.
 * Packs first-party and SDK dist ZIPs + copies local third-party vendor mirror without symlinks,
 * emitting an artifacts.json v1 manifest ready for `./stack.sh prepare <profile> <artifacts.json>`.
 */
$standDir = dirname(__DIR__);
$repoRoot = dirname($standDir, 3);
$parentRoot = dirname($repoRoot);

$out = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    }
}
if ($out === null || $out === '') {
    $out = sys_get_temp_dir().'/vaulter-ai-office-artifacts-'.bin2hex(random_bytes(4));
}

if (! is_dir($out) && ! mkdir($out, 0775, true)) {
    fwrite(STDERR, "Failed to create output directory {$out}\n");
    exit(1);
}
$out = realpath($out) ?: $out;

function packArtifactZip(string $sourceDir, string $zipPath, string $version, array $rewrites = []): void
{
    if (! is_dir($sourceDir)) {
        throw new RuntimeException("Missing package directory: {$sourceDir}");
    }

    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Cannot create ZIP archive: {$zipPath}");
    }

    $excluded = ['vendor', 'node_modules', 'tests', 'reference', 'testkit', 'conformance', 'tmp', '.phpunit.cache', 'build', '.git'];
    $dirIter = new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS);
    $filtered = new RecursiveCallbackFilterIterator(
        $dirIter,
        static fn (SplFileInfo $item): bool => ! str_starts_with($item->getFilename(), '.') && ! in_array($item->getFilename(), $excluded, true),
    );
    $iter = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST);
    $prefixLen = strlen(rtrim($sourceDir, '/')) + 1;

    foreach ($iter as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isLink()) {
            $zip->close();
            throw new RuntimeException("Symlink forbidden in artifact: {$entry->getPathname()}");
        }
        if ($entry->isDir()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($entry->getPathname(), $prefixLen));
        if ($rel === 'composer.json') {
            $manifest = json_decode((string) file_get_contents($entry->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            $manifest['version'] = $version;
            unset($manifest['repositories']);
            foreach ($rewrites as $dep => $constraint) {
                if (isset($manifest['require'][$dep])) {
                    $manifest['require'][$dep] = $constraint;
                }
            }
            $zip->addFromString('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        } else {
            $zip->addFromString($rel, (string) file_get_contents($entry->getPathname()));
        }
    }

    $zip->close();
}

function copyTreeWithoutSymlinks(string $src, string $dst): void
{
    if (! is_dir($dst)) {
        mkdir($dst, 0775, true);
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    $prefixLen = strlen(rtrim($src, '/')) + 1;
    foreach ($iter as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isLink()) {
            continue;
        }
        $rel = substr($entry->getPathname(), $prefixLen);
        $target = $dst.'/'.$rel;
        if ($entry->isDir()) {
            if (! is_dir($target)) {
                mkdir($target, 0775, true);
            }
        } else {
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($entry->getPathname(), $target);
        }
    }
}

$packages = [
    ['axiomasoft/vaulter-ai-core', '1.0.0', $repoRoot.'/packages/core', []],
    ['axiomasoft/vaulter-ai-documents', '1.0.0', $repoRoot.'/packages/documents', [
        'axiomasoft/vaulter-ai-core' => '^1.0',
        'axiomasoft/vaulter-core' => '^1.0',
        'axiomasoft/vaulter-documents' => '^1.0',
        'axiomasoft/vaulter-drive' => '^1.0',
    ]],
    ['axiomasoft/vaulter-ai-sheets', '1.0.0', $repoRoot.'/packages/sheets', [
        'axiomasoft/vaulter-ai-core' => '^1.0',
        'axiomasoft/vaulter-sheets' => '^1.0',
        'axiomasoft/vaulter-core' => '^1.0',
        'axiomasoft/vaulter-documents' => '^1.0',
        'axiomasoft/vaulter-drive' => '^1.0',
        'axiomasoft/packman-plugins' => '0.1.1',
    ]],
    ['axiomasoft/vaulter-sheets', '1.0.0', $parentRoot.'/vaulter-sheets/packages/sheets', [
        'axiomasoft/vaulter-core' => '1.0.0',
        'axiomasoft/vaulter-media' => '1.0.0',
        'axiomasoft/vaulter-drive' => '1.0.0',
        'axiomasoft/vaulter-documents' => '1.0.0',
        'axiomasoft/packman' => '0.1.1',
        'axiomasoft/packman-plugins' => '0.1.1',
    ]],
    ['axiomasoft/vaulter-ai-collaboration', '1.0.0', $repoRoot.'/packages/collaboration', []],
    ['axiomasoft/vaulter-collaboration', '1.0.0', $parentRoot.'/vaulter-collaboration/packages/collaboration', []],
    ['axiomasoft/vaulter-ai-laravel-ai', '1.0.0', $repoRoot.'/packages/laravel-ai', ['axiomasoft/vaulter-ai-core' => '^1.0']],
    ['axiomasoft/vaulter-ai-agentools', '1.0.0', $repoRoot.'/packages/agentools', ['axiomasoft/vaulter-ai-core' => '^1.0', 'agentools/sdk' => '^1.0']],
    ['agentools/sdk', '1.0.0', $parentRoot.'/agentools/php', []],
    ['axiomasoft/vaulter', '1.0.0', $parentRoot.'/vaulter/packages/vaulter', [
        'axiomasoft/vaulter-core' => '1.0.0',
        'axiomasoft/vaulter-media' => '1.0.0',
        'axiomasoft/vaulter-drive' => '1.0.0',
        'axiomasoft/vaulter-documents' => '1.0.0',
        'axiomasoft/packman' => '0.1.1',
        'axiomasoft/packman-plugins' => '0.1.1',
        'axiomasoft/packman-schema' => '0.1.1',
    ]],
    ['axiomasoft/vaulter-core', '1.0.0', $parentRoot.'/vaulter/packages/core', [
        'axiomasoft/packman' => '0.1.1',
        'axiomasoft/packman-plugins' => '0.1.1',
        'axiomasoft/packman-schema' => '0.1.1',
    ]],
    ['axiomasoft/vaulter-media', '1.0.0', $parentRoot.'/vaulter/packages/media', [
        'axiomasoft/vaulter-core' => '1.0.0',
        'axiomasoft/packman' => '0.1.1',
        'axiomasoft/packman-plugins' => '0.1.1',
        'axiomasoft/packman-schema' => '0.1.1',
    ]],
    ['axiomasoft/vaulter-drive', '1.0.0', $parentRoot.'/vaulter/packages/drive', [
        'axiomasoft/vaulter-core' => '1.0.0',
        'axiomasoft/vaulter-media' => '1.0.0',
        'axiomasoft/packman' => '0.1.1',
        'axiomasoft/packman-plugins' => '0.1.1',
        'axiomasoft/packman-schema' => '0.1.1',
    ]],
    ['axiomasoft/vaulter-documents', '1.0.0', $parentRoot.'/vaulter/packages/documents', [
        'axiomasoft/vaulter-core' => '1.0.0',
        'axiomasoft/vaulter-drive' => '1.0.0',
        'axiomasoft/packman' => '0.1.1',
        'axiomasoft/packman-plugins' => '0.1.1',
        'axiomasoft/packman-schema' => '0.1.1',
    ]],
    ['axiomasoft/packman', '0.1.1', $parentRoot.'/packman/packages/core', []],
    ['axiomasoft/packman-plugins', '0.1.1', $parentRoot.'/packman/packages/plugins', ['axiomasoft/packman' => '0.1.1']],
    ['axiomasoft/packman-schema', '0.1.1', $parentRoot.'/packman/packages/schema', ['axiomasoft/packman' => '0.1.1']],
];

$composerEntries = [];
foreach ($packages as [$name, $version, $srcDir, $rewrites]) {
    $zipName = str_replace('/', '-', $name).'-'.$version.'.zip';
    $zipPath = $out.'/'.$zipName;
    packArtifactZip($srcDir, $zipPath, $version, $rewrites);
    $composerEntries[] = [
        'name' => $name,
        'version' => $version,
        'path' => $zipName,
        'sha256' => hash_file('sha256', $zipPath),
    ];
}

// Mirror installed third-party dependencies for offline consumer Composer builds.
$installed = json_decode((string) file_get_contents($repoRoot.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$vendorVersions = [];
foreach ($installed['packages'] ?? [] as $pkg) {
    $name = $pkg['name'] ?? '';
    $version = $pkg['version'] ?? '';
    if (! is_string($name) || ! is_string($version) || $name === '' || $version === '') {
        continue;
    }
    if (str_starts_with($name, 'axiomasoft/') || $name === 'agentools/sdk') {
        continue;
    }
    $srcPkg = $repoRoot.'/vendor/'.$name;
    if (! is_file($srcPkg.'/composer.json')) {
        continue;
    }
    copyTreeWithoutSymlinks($srcPkg, $out.'/vendor-mirror/'.$name);
    $vendorVersions[$name] = $version;
}

$manifest = [
    'schema_version' => 1,
    'composer' => $composerEntries,
    'npm' => [],
    'images' => [
        ['service' => 'postgres', 'reference' => 'postgres:18.7'],
    ],
    'vendor_mirror' => 'vendor-mirror',
    'vendor_versions' => $vendorVersions,
];

$manifestPath = $out.'/artifacts.json';
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo $manifestPath.PHP_EOL;
