<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Crypto\AssociatedDataTest;

use Kern\Platform\Crypto\AssociatedData;

it('joins the four segments in the documented order', function (): void {
    $aad = AssociatedData::for('subject-1', 'pf_profiles', 'cnp', 'record-1');

    expect($aad->bytes)->toBe('subject-1|pf_profiles|cnp|record-1');
});

it('produces different bytes when any single segment differs', function (): void {
    $base = AssociatedData::for('subject-1', 'pf_profiles', 'cnp', 'record-1');

    expect(AssociatedData::for('subject-2', 'pf_profiles', 'cnp', 'record-1')->bytes)->not->toBe($base->bytes)
        ->and(AssociatedData::for('subject-1', 'rental_contracts', 'cnp', 'record-1')->bytes)->not->toBe($base->bytes)
        ->and(AssociatedData::for('subject-1', 'pf_profiles', 'iban', 'record-1')->bytes)->not->toBe($base->bytes)
        ->and(AssociatedData::for('subject-1', 'pf_profiles', 'cnp', 'record-2')->bytes)->not->toBe($base->bytes);
});
