<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\CounterpartyIndexBatchTest;

use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;

/**
 * A statement's counterparties are indexed in one request to the key manager, and must come out
 * exactly as indexing each one alone would — or a row imported in a batch could never be matched to
 * the rule a person saved for that name one at a time.
 */

it('indexes a batch exactly as it indexes one name at a time, keeping blanks as null', function (): void {
    $directory = app(CounterpartyDirectory::class);

    $batch = $directory->indexesFor(['LIDL DISCOUNT SRL', '', null, '  kaufland  ']);

    expect($batch)->toBe([
        $directory->indexFor('LIDL DISCOUNT SRL'),
        null,
        null,
        $directory->indexFor('kaufland'),
    ]);
});
