<?php

declare(strict_types=1);

/**
 * Detects debug calls accidentally left in source code.
 */
arch('no debug calls in production code')
    ->expect('Oriceon\N8nBridge')
    ->not->toUse([
        'dd',
        'dump',
        'var_dump',
        'var_export',
        'print_r',
        'ray',
        'ddd',
        'dump_server',
        'die',
    ]);

test('no Log::debug left in source files', function() {
    $srcPath = __DIR__ . '/../../src';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcPath)
    );

    $found = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content  = file_get_contents($file->getRealPath());
        $relative = str_replace(realpath($srcPath . '/..') . '/', '', $file->getRealPath());

        // Detect Log::debug(
        if (preg_match_all('/Log::debug\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line    = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $found[] = "{$relative}:{$line}";
            }
        }

        // Detect \Log::debug(
        if (preg_match_all('/\\\\Log::debug\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line    = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $found[] = "{$relative}:{$line}";
            }
        }
    }

    expect($found)->toBeEmpty(
        'Found Log::debug() calls in source code:' . PHP_EOL .
        implode(PHP_EOL, array_map(static fn($f) => "  → {$f}", $found))
    );
});

test('no var_dump or dd left in test files', function() {
    $testsPath = __DIR__ . '/../../tests';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsPath)
    );

    $patterns = [
        'var_dump(' => '/\bvar_dump\s*\(/',
        'dd('       => '/\bdd\s*\(/',
        'dump('     => '/\bdump\s*\(/',
        'ray('      => '/\bray\s*\(/',
    ];

    $found = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Ignore this file
        if ($file->getRealPath() === __FILE__) {
            continue;
        }

        $content  = file_get_contents($file->getRealPath());
        $relative = str_replace(realpath($testsPath . '/..') . '/', '', $file->getRealPath());

        foreach ($patterns as $name => $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line    = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $found[] = "{$relative}:{$line} [{$name}]";
                }
            }
        }
    }

    expect($found)->toBeEmpty(
        'Found debug calls in test files:' . PHP_EOL .
        implode(PHP_EOL, array_map(static fn($f) => "  → {$f}", $found))
    );
});
