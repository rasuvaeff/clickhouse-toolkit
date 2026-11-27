<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\CodeQuality\Rector\Property\FixClassCaseSensitivityVarDocblockRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSkip([
        // Takes the psalm type in `@var scalar` for the `Scalar` class of this
        // package and capitalizes it, which changes the type.
        FixClassCaseSensitivityVarDocblockRector::class,
        // `$x === null` reads clearer than `!$x instanceof Some\Long\ClassName`
        // when the declared type already names the class.
        ExplicitNullableObjectInstanceRector::class,
    ])
    ->withRules([AddNameToLiteralArgumentRector::class]);
