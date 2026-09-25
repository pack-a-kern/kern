<?php

declare(strict_types=1);

use App\Domain\Declarations\DeclarationsServiceProvider;
use App\Domain\FiscalParameters\FiscalParametersServiceProvider;
use App\Domain\Fx\FxServiceProvider;
use App\Domain\Spv\SpvServiceProvider;
use App\Domain\StatementImport\StatementImportServiceProvider;
use App\Domain\Subjects\SubjectsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SubjectsServiceProvider::class,
    FiscalParametersServiceProvider::class,
    FxServiceProvider::class,
    DeclarationsServiceProvider::class,
    SpvServiceProvider::class,
    StatementImportServiceProvider::class,
];
