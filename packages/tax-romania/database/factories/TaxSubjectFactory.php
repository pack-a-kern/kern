<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Database\Factories;

use function app;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectType;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * @extends Factory<TaxSubject>
 *
 * `definition()` deliberately sets no encrypted column: a `TaxSubject`'s own DEK
 * (`encryptionSubjectId()` returns its own id) can only be created once the row already exists
 * in the database (see `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject`'s docblock and the
 * `tax_subjects` migration for exactly why) — a single-statement `Factory::create()` with an
 * encrypted attribute in `definition()` would try to encrypt it *before* the insert, which fails.
 * `configure()`'s `afterCreating()` hook runs a second `save()` after the row exists, mirroring
 * the same two-write pattern `RegisterPfSubject` uses for real registrations.
 */
final class TaxSubjectFactory extends Factory
{
    protected $model = TaxSubject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => SubjectType::PF,
            'country_code' => 'RO',
            'status' => SubjectStatus::ACTIVE,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (TaxSubject $subject): void {
            $subject->display_name_ciphertext = 'Test Subject ' . $subject->id;
            $subject->save();
        });
    }

    /**
     * `SubjectType::PFA` instead of the default `PF` — Milestone 12's extension proof
     * (`docs/handoff/034-pfa-extension.md`) needs a PFA `TaxSubject` in exactly the places PF
     * tests already use `TaxSubject::factory()->create()`, with no other change to this factory's
     * behaviour (still a bare subject with a display name, no `PfaProfile` — tests that need one
     * create it directly, mirroring `docs/handoff/011-identity-tenancy.md`'s decision not to give
     * `PfProfile` a factory either).
     */
    public function pfa(): static
    {
        return $this->state(['type' => SubjectType::PFA]);
    }

    /**
     * A fiscal identifier for this subject (encrypted, plus its kind-bound blind index — ADR
     * 0011, `docs/adr/0011-canonical-fiscal-identifier.md`) — for tests that need duplicate-
     * identifier detection or a decryptable `fiscal_identifier_ciphertext`, for either subject
     * type's identifier kind.
     */
    public function withFiscalIdentifier(FiscalIdentifierKind $kind, string $rawValue): static
    {
        return $this->afterCreating(function (TaxSubject $subject) use ($kind, $rawValue): void {
            $subject->fiscal_identifier_ciphertext = $rawValue;
            $subject->fiscal_identifier_blind_index = app(BlindIndexHasher::class)
                ->compute($kind->keyedValue($rawValue));
            $subject->fiscal_identifier_kind = $kind;
            $subject->save();
        });
    }
}
