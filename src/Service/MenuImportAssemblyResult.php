<?php

namespace App\Service;

/** Counts from one MenuImportAssembler::assemble() run — for the command's output and for tests to assert against. */
final readonly class MenuImportAssemblyResult
{
    public function __construct(
        public int $categoriesCreated = 0,
        public int $categoriesReused = 0,
        public int $productsCreated = 0,
        public int $productsSkipped = 0,
        public int $ingredientsLinked = 0,
        public int $ingredientsSkippedUncertain = 0,
        public int $tagsAssigned = 0,
    ) {}
}
