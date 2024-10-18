<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\PropertyProperty\RemoveNullPropertyInitializationRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withPhpSets(php81: true)
    ->withTypeCoverageLevel(48)
    ->withDeadCodeLevel(14)
    ->withSkip([
        RemoveNullPropertyInitializationRector::class,
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_81,
        SetList::PHP_81,

//        // SetList::PHP_POLYFILLS,
//        SetList::CODE_QUALITY,
//        SetList::CODING_STYLE,
//        SetList::DEAD_CODE,
//        // SetList::STRICT_BOOLEANS,
//        // SetList::NAMING,
//        // SetList::RECTOR_PRESET,
//        SetList::PRIVATIZATION,
//        SetList::TYPE_DECLARATION,
//        // SetList::EARLY_RETURN,
//        // SetList::INSTANCEOF,
//        // SetList::CARBON,
    ]);
