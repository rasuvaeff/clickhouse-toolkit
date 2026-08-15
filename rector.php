<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([AddNameToLiteralArgumentRector::class])
    ->withSkip([
        // Rewrites `$x !== null` into `$x instanceof Some\Long\ClassName` on a
        // property whose declared type already says which class it is: a
        // fully qualified name inline, stating nothing the type declaration
        // did not, and reading worse at every place it appears.
        FlipTypeControlToUseExclusiveTypeRector::class,
    ]);
