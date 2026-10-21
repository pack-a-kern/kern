<?php

declare(strict_types=1);

namespace Kern\Documents\Parsing;

use Kern\Documents\Enums\DocumentType;

/**
 * What a reading of a document is made of — `BLUEPRINT.md` §14.4's per-type schema, in two layers.
 *
 * ## Layer one: every document, whatever it is
 *
 * A kind, a one-line description, a suggested filename, who issued it, its date and number. This is
 * the archive layer, and it is what makes a document findable again — which is half the point of
 * keeping documents at all, the other half being the money in them.
 *
 * It is also why classification no longer decides whether a reading survives. A type with no schema
 * used to make the validator reject the whole reply: a model that answered `ANAF_DECISION` threw
 * away a perfectly good read of the issuer, the recipient and every line item. Every type now has a
 * schema, because every type has layer one.
 *
 * ## Layer two: payments
 *
 * `payments` is what a document obliges or records, and its emptiness is the answer to "does this
 * carry a transaction". That is deliberately a fact about the *content*, not about the type: an
 * obligație de plată from ANAF and an AGA dividend decision both produce payments while being
 * neither invoice nor receipt, and a notificare produces none while being the same kind as the
 * first. A type-based rule would have to be wrong about at least one of them.
 *
 * Payments carry no sign and no direction. A document does not print one — an invoice for 1340 is
 * money out if you are the recipient and money in if you issued it, and which of those is true is
 * not on the page. The model reports what is printed; the direction is derived from `issuer` and
 * `recipient`, the same way "1.234,56" is normalised by us rather than by the model.
 *
 * Statement rows are the exception and keep their signs, because a statement *does* print them:
 * a Debit column and a Credit column are the sign, in the document.
 *
 * ## Why fields have a shape
 *
 * A field used to be a string in one of two lists. That cannot describe an invoice's lines — seven
 * values repeated once per article — so the prompt could not ask for them, the JSON schema could
 * not request them, and the validator would have dropped them. {@see ExtractionField} gives a field
 * a shape, and the prompt, the JSON schema and the validator are all generated from it.
 */
final readonly class ExtractionSchema
{
    /** What the model is asked to classify the document as. Layer one, and always required. */
    public const string FIELD_DOCUMENT_KIND = 'document_kind';

    /**
     * One line saying what this document actually is, in its own words.
     *
     * The only content field required of every document, and the one the archive is searched on —
     * "Decizie de impunere CASS 2024" rather than "IMG_4471.jpg". It is also what a transaction
     * built from this document is described as; before it existed the description was copied from
     * the counterparty, so a receipt read "Kaufland" twice and explained nothing.
     */
    public const string FIELD_DESCRIPTION = 'description';

    /** What to call the file. A starting point for the rename box, never applied on its own. */
    public const string FIELD_SUGGESTED_FILENAME = 'suggested_filename';

    public const string FIELD_DATE = 'date';

    public const string FIELD_CURRENCY = 'currency';

    public const string FIELD_DOCUMENT_NUMBER = 'document_number';

    /** What the document obliges or records. Empty means it carries no money. */
    public const string FIELD_PAYMENTS = 'payments';


    /**
     * How sure the model is of its own reading.
     *
     * Asked for rather than assumed: every proposal used to carry the same 0.60, a placeholder that
     * said nothing and looked like a measurement. Self-reported and therefore optimistic, but a
     * number that varies with the document beats one that never does.
     */
    public const string FIELD_CONFIDENCE = 'confidence';

    /**
     * @param  list<ExtractionField>  $fields
     */
    private function __construct(
        public DocumentType $type,
        public array $fields,
    ) {
    }

    /**
     * The schema for a type: layer one, payments, and whatever that type adds on top.
     *
     * Never null. A type nobody has written extras for still yields a complete, storable reading —
     * which is the whole correction: a rental contract or an ANAF notice is a document like any
     * other, and refusing to store what was read from it because nobody had described its extras
     * was throwing away work the model had already done.
     */
    public static function for(DocumentType $type): self
    {
        return new self($type, [...self::common(), ...self::extrasFor($type)]);
    }

    /**
     * Layer one plus payments — the part every document has.
     *
     * `description` is required and nothing else is. A reading that cannot say what the document is
     * has not read it; a reading that cannot find a date on a contract has still read it.
     *
     * @return list<ExtractionField>
     */
    private static function common(): array
    {
        return [
            ExtractionField::scalar(self::FIELD_CONFIDENCE),
            ExtractionField::scalar(self::FIELD_DESCRIPTION, required: true),
            ExtractionField::scalar(self::FIELD_SUGGESTED_FILENAME),
            ExtractionField::scalar(self::FIELD_DATE),
            // When a contract stops, which is what rental income is counted up to. In layer one
            // beside `date` rather than as a contract-only extra, so the model reads the two dates
            // together — a contract's signing date and its end date are the pair that gets confused.
            ExtractionField::scalar('contract_end_date'),
            // Where a rented property is. A rental is recorded against its property, and the address
            // is the one thing a person would otherwise have to retype from the contract.
            ExtractionField::scalar('contract_rental_address'),
            ExtractionField::scalar(self::FIELD_DOCUMENT_NUMBER),
            ExtractionField::scalar(self::FIELD_CURRENCY),
            // Who the parties are. `issuer` and `recipient` together are what decide whether a
            // payment is money in or money out, which is why neither is an afterthought — and why
            // the flat `merchant`/`issuer_tax_id` pair they replaced is gone rather than kept
            // alongside them, where the two could disagree about the same fact.
            ExtractionField::object('issuer', [
                ExtractionField::scalar('name', required: true),
                ExtractionField::scalar('tax_id'),
                ExtractionField::scalar('registration_number'),
                ExtractionField::scalar('iban'),
            ]),
            ExtractionField::object('recipient', [
                ExtractionField::scalar('name', required: true),
                ExtractionField::scalar('tax_id'),
            ]),
            // A payment with no amount is not a payment. Everything else about it can be missing —
            // an invoice that prints no due date still obliges you to pay it.
            // Every field here is a fact printed on the document. Nothing is relative to the
            // taxpayer, because nothing here can see the taxpayer.
            //
            // The previous shape asked for `counterparty` ("the other party from the taxpayer") and
            // `direction` ("in or out for the taxpayer"), and both rested on the model first
            // matching an account name against a name on the page. It never did, so both were
            // answered relative to the document instead — a dividend decision came back as money
            // OUT with "Alpha Bank" as the counterparty, because the bank was the only other name
            // it could find. Who the taxpayer is, is something this codebase knows and a model
            // does not; {@see \Kern\Documents\Payments\ResolvePaymentParties} decides it
            // here, where it can be tested.
            ExtractionField::listOf(self::FIELD_PAYMENTS, [
                // Named by what they are, so that no instruction has to arbitrate between two
                // figures for one field. `amount` with a rule saying "this is the net" and another
                // saying "this is the currency actually transferred" named different numbers on the
                // same document, and the model picked whichever it read first.
                ExtractionField::scalar('amount_paid', required: true),
                ExtractionField::scalar('currency_paid'),
                // The figure the document computes in, where it pays in one currency and states the
                // obligation in another — "279.448 usd echivalentul a 1.314.610 ron la cursul de
                // 4.7043" is an ordinary Romanian dividend decision. Both are real: the paid figure
                // is what moves through an account, the stated one is what a tax return is computed
                // on, and the rate is what ties them. Keeping one loses the other for good, because
                // a rate on a past day cannot be reconstructed from a single number.
                ExtractionField::scalar('amount_stated'),
                ExtractionField::scalar('currency_stated'),
                ExtractionField::scalar('exchange_rate'),
                // Both sides of the payment, as printed. Which of them is the taxpayer is decided
                // here rather than there.
                ExtractionField::scalar('payer'),
                ExtractionField::scalar('beneficiary'),
                ExtractionField::scalar('date'),
                ExtractionField::scalar('due_date'),
                ExtractionField::scalar('description'),
                ExtractionField::scalar('category'),
                // Kept apart from the amount rather than folded into it. A dividend decision states
                // all three, and which belongs in a ledger is a question about the document, not a
                // subtraction for a model to perform.
                ExtractionField::scalar('gross_amount'),
                ExtractionField::scalar('tax_withheld'),
            ]),
        ];
    }

    /**
     * What this type adds to layer one.
     *
     * Four of the seven add nothing, and that is the point: a tax document, a contract, a corporate
     * decision and an uncategorised file are complete as archive entries plus whatever payments
     * they carry. Only the two shapes with structure inside them — an invoice's lines and a
     * statement's rows — need more.
     *
     * @return list<ExtractionField>
     */
    private static function extrasFor(DocumentType $type): array
    {
        return match ($type) {
            // An invoice must identify itself: unlike a receipt it is a document someone can be
            // asked to produce again, and a number is how it is asked for. That is expressed as a
            // prompt description rather than a requirement, because a reading that found every line
            // of an invoice and missed its number is still worth keeping.
            DocumentType::INVOICE => [
                ExtractionField::scalar('net_amount'),
                ExtractionField::scalar('vat_amount'),
                // A line with no description and no total is not a line. The two that identify it
                // are required *of an entry that exists*; an invoice with no itemisation at all is
                // ordinary and stays valid.
                ExtractionField::listOf('items', [
                    ExtractionField::scalar('product_name', required: true),
                    ExtractionField::scalar('total_price', required: true),
                    ExtractionField::scalar('product_code'),
                    ExtractionField::scalar('quantity'),
                    ExtractionField::scalar('unit'),
                    ExtractionField::scalar('unit_price'),
                    ExtractionField::scalar('vat_price'),
                ]),
            ],
            DocumentType::RECEIPT => [
                ExtractionField::scalar('vat_amount'),
            ],
            // A statement yields *many* movements rather than one obligation, which is why its rows
            // are not `payments`: these have already happened and the document prints their
            // direction. Its own totals are deliberately absent — a statement has no single amount,
            // and asking for one invites a model to add the rows up.
            DocumentType::ACCOUNT_STATEMENT => [
                ExtractionField::object('statement', [
                    // Two account identifiers because there are two kinds of account: a bank prints
                    // an IBAN, an exchange prints an account id. Whichever is there is what a
                    // future import matches the statement to its wallet account by.
                    ExtractionField::scalar('account_iban'),
                    ExtractionField::scalar('account_reference'),
                    ExtractionField::scalar('institution_name'),
                    ExtractionField::scalar('period_start'),
                    ExtractionField::scalar('period_end'),
                ]),
                // `reference` is optional because most rows do not print one, and the date plus the
                // amount are required of a row because a row without them cannot become a
                // transaction.
                ExtractionField::listOf('transactions', [
                    ExtractionField::scalar('transaction_date', required: true),
                    ExtractionField::scalar('amount', required: true),
                    ExtractionField::scalar('reference'),
                    ExtractionField::scalar('value_date'),
                    ExtractionField::scalar('counterparty'),
                    ExtractionField::scalar('description'),
                    ExtractionField::scalar('category'),
                    ExtractionField::scalar('currency'),
                ]),
            ],
            DocumentType::TAX_DOCUMENT,
            DocumentType::CONTRACT,
            DocumentType::CORPORATE,
            DocumentType::OTHER => [],
        };
    }

    /**
     * The required top-level field names this map is missing, in schema order.
     *
     * Only the top level: a nested group\'s own requirements are checked by the validator while it
     * walks that group, because they are conditional on the group being present at all.
     *
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    public function missing(array $fields): array
    {
        $missing = [];

        foreach ($this->fields as $field) {
            if (! $field->required) {
                continue;
            }

            $value = $fields[$field->name] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $field->name;
            }
        }

        return $missing;
    }

    /**
     * Every field any type can yield, plus the kind of document this is.
     *
     * Sent when the type is not yet known, which is now the ordinary case: a document arrives
     * untyped and the reading is what says what it is. One call classifies and extracts, rather
     * than asking a person to choose first and then asking the wrong questions when they chose
     * wrong.
     *
     * `document_kind` and `description` are required here: the first because the reply has to say
     * which schema to hold it to, the second because it is layer one and every type asks for it. A
     * field required *of an invoice* cannot be required of a reply that may turn out to describe a
     * statement, so everything else is asked for loosely and the reply is then held to the schema
     * of whatever kind it reported.
     */
    public static function union(): self
    {
        $fields = [
            ExtractionField::scalar(self::FIELD_DOCUMENT_KIND, required: true),
        ];
        $seen = [self::FIELD_DOCUMENT_KIND => true];

        foreach (DocumentType::cases() as $type) {
            foreach (self::for($type)->fields as $field) {
                if (isset($seen[$field->name])) {
                    continue;
                }

                $seen[$field->name] = true;
                // Kept required only where every type requires it, which is layer one.
                $fields[] = $field->name === self::FIELD_DESCRIPTION || ! $field->required
                    ? $field
                    : self::relaxed($field);
            }
        }

        return new self(DocumentType::OTHER, $fields);
    }

    /**
     * The same field, no longer required.
     *
     * Children keep their own requiredness: a line that exists still needs a name and a total,
     * because that is a rule about the line, not about which kind of document it is on.
     */
    private static function relaxed(ExtractionField $field): ExtractionField
    {
        return match ($field->kind) {
            ExtractionFieldKind::SCALAR => ExtractionField::scalar($field->name),
            ExtractionFieldKind::OBJECT => ExtractionField::object($field->name, $field->children),
            ExtractionFieldKind::LIST => ExtractionField::listOf($field->name, $field->children),
        };
    }

    /**
     * The field names a person may correct on the review screen, split by requiredness.
     *
     * Top-level scalars only. A correction form is a flat set of inputs, and an invoice's `items`
     * are a table — correcting a line is a different screen with different rules (which line, added
     * or removed, does the total still agree), not a text box. Offering `items` here would put a
     * field in the form that nothing can sensibly fill.
     *
     * @return array{required: list<string>, optional: list<string>}
     */
    public function correctableFields(): array
    {
        $required = [];
        $optional = [];

        foreach ($this->fields as $field) {
            if ($field->kind !== ExtractionFieldKind::SCALAR) {
                continue;
            }

            if ($field->required) {
                $required[] = $field->name;
            } else {
                $optional[] = $field->name;
            }
        }

        return ['required' => $required, 'optional' => $optional];
    }

    /**
     * Every field name this schema uses, at any depth.
     *
     * For checks that must cover the whole vocabulary — the review screen's labels, for one, which
     * has to have a translation for anything that can appear on it.
     *
     * @return list<string>
     */
    public function allFieldNames(): array
    {
        return self::namesOf($this->fields);
    }

    /**
     * @param  list<ExtractionField>  $fields
     * @return list<string>
     */
    private static function namesOf(array $fields): array
    {
        $names = [];

        foreach ($fields as $field) {
            $names[] = $field->name;
            $names = [...$names, ...self::namesOf($field->children)];
        }

        return $names;
    }
}
