<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\If_\SimplifyIfElseToTernaryRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\DeadCode\Rector\PropertyProperty\RemoveNullPropertyInitializationRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withPhpSets(php81: true)
//    ->withTypeCoverageLevel(100)
//    ->withDeadCodeLevel(100)
        ->withCodeQualityLevel(60)
    ->withSkip([
        RemoveNullPropertyInitializationRector::class,
        RemoveUnusedPrivatePropertyRector::class,
        SimplifyIfElseToTernaryRector::class,
        ExplicitBoolCompareRector::class
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_81,
        SetList::PHP_81,

//        // SetList::PHP_POLYFILLS,
//        SetList::CODE_QUALITY,
//        SetList::CODING_STYLE,
        SetList::DEAD_CODE,
//        // SetList::STRICT_BOOLEANS,
//        // SetList::NAMING,
//        // SetList::RECTOR_PRESET,
//        SetList::PRIVATIZATION,
        SetList::TYPE_DECLARATION,
//        // SetList::EARLY_RETURN,
//        // SetList::INSTANCEOF,
//        // SetList::CARBON,
    ]);
