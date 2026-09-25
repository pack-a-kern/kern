<?php

declare(strict_types=1);

use App\Domain\Declarations\DeclarationsServiceProvider;
use App\Domain\Fx\FxServiceProvider;
use App\Domain\Spv\SpvServiceProvider;
use App\Domain\StatementImport\StatementImportServiceProvider;
use App\Domain\Subjects\SubjectsServiceProvider;
use App\Domain\TaxKnowledge\TaxKnowledgeServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SubjectsServiceProvider::class,
    TaxKnowledgeServiceProvider::class,
    FxServiceProvider::class,
    DeclarationsServiceProvider::class,
    SpvServiceProvider::class,
    StatementImportServiceProvider::class,
];
