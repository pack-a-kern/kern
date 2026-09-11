<?php

declare(strict_types=1);

namespace App\Domain\Documents\Parsing;

use App\Domain\Documents\Enums\DocumentType;

/**
 * The fields each document type is expected to yield — `BLUEPRINT.md` §14.4's per-type schema.
 *
 * The blueprint's rule is blunt and worth keeping blunt: **output that does not satisfy the schema
 * is a failure, not a best-effort save.** A proposal missing the amount is not a partial receipt; it
 * is a reading that did not work, and storing it would put a half-filled form in front of a person
 * as though a machine had understood the document.
 *
 * Field *names* live here rather than in each parser so that a receipt read by a pattern parser, by
 * a model, or typed by a human all produce the same keys — which is what lets one review screen and
 * one "create transaction" step serve all three.
 *
 * Types are described loosely on purpose. `amount` is a decimal string and `date` an ISO date; the
 * schema's job is to say *which* facts a document type must supply, not to re-implement validation
 * that `RecordFinancialTransaction` already performs on the way in.
 */
final readonly class ExtractionSchema
{
    /** A receipt or invoice: enough to become one transaction. */
    public const string FIELD_AMOUNT = 'amount';

    public const string FIELD_CURRENCY = 'currency';

    public const string FIELD_DATE = 'date';

    public const string FIELD_MERCHANT = 'merchant';

    /** Fiscal identifier of the issuer, where the document prints one. */
    public const string FIELD_ISSUER_TAX_ID = 'issuer_tax_id';

    public const string FIELD_DOCUMENT_NUMBER = 'document_number';

    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    private function __construct(
        public DocumentType $type,
        public array $required,
        public array $optional,
    ) {
    }

    /**
     * The schema for a type, or null where none is defined yet.
     *
     * Null means "no parser output is accepted for this type", which is the honest state for a
     * document nobody has decided the shape of. Inventing a permissive schema so that something can
     * be stored is exactly the best-effort save §14.4 forbids.
     */
    public static function for(DocumentType $type): ?self
    {
        return match ($type) {
            // A receipt becomes one outgoing transaction. Currency is optional because most
            // Romanian receipts do not print one — the account's currency is the sane default, and
            // guessing it from the text would be worse than inheriting it.
            DocumentType::RECEIPT => new self(
                $type,
                [self::FIELD_AMOUNT, self::FIELD_DATE],
                [self::FIELD_CURRENCY, self::FIELD_MERCHANT, self::FIELD_ISSUER_TAX_ID, self::FIELD_DOCUMENT_NUMBER],
            ),
            // An invoice must identify itself: unlike a receipt it is a document someone can be
            // asked to produce again, and a number is how it is asked for.
            DocumentType::INVOICE => new self(
                $type,
                [self::FIELD_AMOUNT, self::FIELD_DATE, self::FIELD_DOCUMENT_NUMBER],
                [self::FIELD_CURRENCY, self::FIELD_MERCHANT, self::FIELD_ISSUER_TAX_ID],
            ),
            // BANK_STATEMENT, DEPOSIT_AGREEMENT, RENTAL_CONTRACT and ANAF_DECISION have no schema
            // yet. A statement yields *many* transactions rather than one and needs a different
            // shape entirely; the rest need a decision about what they produce before a parser can
            // be written for them.
            default => null,
        };
    }

    /**
     * The required field names this map is missing, in schema order.
     *
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    public function missing(array $fields): array
    {
        return array_values(array_filter(
            $this->required,
            static fn (string $name): bool => ! isset($fields[$name]) || trim($fields[$name]) === '',
        ));
    }

    /**
     * `$fields` with anything the schema does not name removed.
     *
     * A parser that learns to read an extra field must have it added here first. Otherwise a key
     * appears on the review screen that nothing downstream knows what to do with, and the schema
     * stops describing what is actually stored.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public function keepKnown(array $fields): array
    {
        $known = array_merge($this->required, $this->optional);

        return array_intersect_key($fields, array_flip($known));
    }
}
