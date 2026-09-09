<?php

declare(strict_types=1);

use App\Domain\Declarations\DeclarationsServiceProvider;
use App\Domain\Documents\DocumentsServiceProvider;
use App\Domain\Fx\FxServiceProvider;
use App\Domain\Identity\IdentityServiceProvider;
use App\Domain\Settings\SettingsServiceProvider;
use App\Domain\Spv\SpvServiceProvider;
use App\Domain\Subjects\SubjectsServiceProvider;
use App\Domain\TaxKnowledge\TaxKnowledgeServiceProvider;
use App\Providers\AppServiceProvider;
use App\Support\KeyManagement\KeyManagementServiceProvider;
use App\Support\ObjectStore\ObjectStoreServiceProvider;

return [
    AppServiceProvider::class,
    KeyManagementServiceProvider::class,
    ObjectStoreServiceProvider::class,
    SubjectsServiceProvider::class,
    IdentityServiceProvider::class,
    TaxKnowledgeServiceProvider::class,
    FxServiceProvider::class,
    DocumentsServiceProvider::class,
    DeclarationsServiceProvider::class,
    SpvServiceProvider::class,
    SettingsServiceProvider::class,
];
