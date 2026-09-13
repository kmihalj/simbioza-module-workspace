<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    // HR: Zadržava postojeći dirname stil putanja neovisno o verziji Rectora u CI-ju.
    // EN: Keeps the existing dirname path style independent of the Rector version used in CI.
    ->withSkip([
        \Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector::class,
    ])
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/views',
        __DIR__ . '/heartphrame-manifest.php',
    ])
    // uncomment to reach your current PHP version
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
//        privatization: true,
//        naming: true,
        instanceOf: true,
        earlyReturn: true,
//        strictBooleans: true,
//        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
//        doctrineCodeQuality: true,
//        symfonyCodeQuality: true,
//        symfonyConfigs: true,
    );
