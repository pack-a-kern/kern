<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\NestedExtractionSchemaTest;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\ExternalAi\ExtractionPrompt;
use App\Domain\Documents\ExternalAi\ExtractionSchemaValidator;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * A field can be a value, a group, or a list of groups — and the prompt, the JSON schema and the
 * validator are all generated from the same definition, so none of them can drift from the others.
 */
function validator(): ExtractionSchemaValidator
{
    return new ExtractionSchemaValidator();
}

/**
 * One value out of a nested result, by dotted path.
 *
 * The schema and the validated reply are both `array<string, mixed>` by design — their shape is the
 * thing under test, so it cannot also be their static type. Reaching in through `data_get` says
 * that once, here, instead of at every assertion.
 *
 * @param  array<string, mixed>|null  $subject
 */
function at(?array $subject, string $path): mixed
{
    return data_get($subject, $path);
}

it('asks for invoice lines as an array of typed objects', function (): void {
    $schema = validator()->jsonSchema(DocumentType::INVOICE);

    // Nullable, like every optional field: an invoice with no itemisation answers null.
    expect(at($schema, 'properties.items.type'))->toBe(['array', 'null'])
        ->and(at($schema, 'properties.items.items.properties'))->toHaveKeys(
            ['product_name', 'total_price', 'product_code', 'quantity', 'unit', 'unit_price', 'vat_price'],
        )
        // Every property is listed, because that is what `strict: true` means. A field this
        // codebase calls optional is expressed as nullable instead.
        ->and(at($schema, 'properties.items.items.required'))
        ->toBe(['product_name', 'total_price', 'product_code', 'quantity', 'unit', 'unit_price', 'vat_price'])
        ->and(at($schema, 'properties.items.items.properties.product_name.type'))->toBe('string')
        ->and(at($schema, 'properties.items.items.properties.quantity.type'))->toBe(['string', 'null'])
        ->and(at($schema, 'properties.issuer.type'))->toBe(['object', 'null']);
});

it('sends a schema that strict structured output will actually accept', function (): void {
    // A schema listing twenty properties and requiring three is *invalid* under `strict: true`, and
    // a provider handed one either refuses it or ignores `response_format` altogether — which is
    // what ours was evidently doing. So every property is required, and optionality is expressed
    // the way the format expresses it: a null in the type union.
    foreach (DocumentType::cases() as $type) {
        $schema = validator()->jsonSchema($type);
        $properties = at($schema, 'properties');
        $required = at($schema, 'required');

        expect($properties)->toBeArray()
            ->and($required)->toBeArray();

        assert(is_array($properties) && is_array($required));

        expect($required)->toBe(array_keys($properties), $type->value)
            // Only `description` is genuinely required of every document; the rest may answer null.
            ->and(at($schema, 'properties.description.type'))->toBe('string')
            ->and(at($schema, 'properties.date.type'))->toBe(['string', 'null'])
            // Except confidence, which is typed non-nullable so that a model has to produce a
            // number. Offered the choice it answered null every time, and the review screen had
            // nothing to show. Its absence still never fails a reading — see `missing()`.
            ->and(at($schema, 'properties.confidence.type'))->toBe('string');
    }
});

it('keeps invoice lines instead of discarding them', function (): void {
    $extraction = validator()->validate(DocumentType::INVOICE, [
        'description' => 'Factură servicii contabilitate martie 2026',
        'date' => '2026-01-05',
        'document_number' => 'TXS 0042',
        'issuer' => ['name' => 'ACME SRL', 'tax_id' => 'RO123456'],
        'payments' => [['amount_paid' => '1200.00', 'currency_paid' => 'RON', 'due_date' => '2026-02-05']],
        'items' => [
            ['product_name' => 'Laptop', 'total_price' => '1000.00', 'quantity' => '1', 'vat_price' => 19],
            ['product_name' => 'Monitor', 'total_price' => '200.00'],
        ],
    ]);

    expect($extraction)->not->toBeNull();
    assert($extraction !== null);

    $fields = $extraction->fields;

    expect(at($fields, 'issuer'))->toBe(['name' => 'ACME SRL', 'tax_id' => 'RO123456'])
        ->and(at($fields, 'payments.0.amount_paid'))->toBe('1200.00')
        ->and(at($fields, 'items'))->toHaveCount(2)
        ->and(at($fields, 'items.0.product_name'))->toBe('Laptop')
        // A number where a decimal string was asked for keeps its value, not its formatting.
        ->and(at($fields, 'items.0.vat_price'))->toBe('19')
        ->and(at($fields, 'items.1'))->toBe(['product_name' => 'Monitor', 'total_price' => '200.00']);
});

it('drops a line that cannot stand on its own, and keeps the rest', function (): void {
    // One unreadable line on a long invoice must not discard the others — a reviewer can see that
    // the lines do not sum to the total, which is more useful than no lines at all.
    $extraction = validator()->validate(DocumentType::INVOICE, [
        'description' => 'Factură',
        'date' => '2026-01-05',
        'items' => [
            ['product_name' => 'Laptop', 'total_price' => '1000.00'],
            ['quantity' => '2'],
        ],
    ]);

    expect(at($extraction?->fields, 'items'))->toHaveCount(1);
});

it('refuses a group that is present but says nothing', function (): void {
    // An issuer block naming nobody is worse than no issuer block: the review screen would show an
    // empty panel as though something had been read.
    $extraction = validator()->validate(DocumentType::INVOICE, [
        'description' => 'Factură',
        'date' => '2026-01-05',
        'issuer' => ['tax_id' => 'RO123456'],
    ]);

    expect($extraction)->not->toBeNull()
        ->and($extraction?->fields)->not->toHaveKey('issuer');
});

it('still fails a reading that cannot say what the document is', function (): void {
    expect(validator()->validate(DocumentType::INVOICE, [
        'date' => '2026-01-05',
        'items' => [['product_name' => 'Laptop', 'total_price' => '1000.00']],
    ]))->toBeNull();
});

it('gives an account statement a schema shaped around its rows', function (): void {
    $extraction = validator()->validate(DocumentType::ACCOUNT_STATEMENT, [
        'description' => 'Extras ING ianuarie 2017',
        'statement' => ['account_iban' => 'RO39INGB0000999903613944', 'institution_name' => 'ING'],
        'transactions' => [
            // Signed, because a statement prints the sign: a Debit column and a Credit column.
            ['transaction_date' => '2016-12-29', 'amount' => '-8.00'],
            // No reference printed, which is the ordinary case and must not fail the row.
            ['transaction_date' => '2016-12-31', 'amount' => '1000.00', 'reference' => '5104'],
        ],
    ]);

    expect(at($extraction?->fields, 'transactions'))->toHaveCount(2)
        ->and(at($extraction?->fields, 'transactions.0.amount'))->toBe('-8.00')
        ->and(at($extraction?->fields, 'transactions.0'))->not->toHaveKey('reference')
        ->and(at($extraction?->fields, 'statement.institution_name'))->toBe('ING');
});

it('reads payments off a document that is neither an invoice nor a receipt', function (): void {
    // The point of layer two: whether a document carries money is a fact about its content, not
    // about its kind. An obligație de plată is a tax document *and* three payments.
    $extraction = validator()->validate(null, [
        'document_kind' => 'TAX_DOCUMENT',
        'description' => 'Decizie de impunere CASS 2024',
        'date' => '2026-05-11',
        'payments' => [
            ['amount_paid' => '1080.00', 'due_date' => '2026-05-25', 'category' => 'TAX_ANAF'],
            ['amount_paid' => '540.00', 'due_date' => '2026-09-25'],
        ],
    ]);

    expect($extraction?->type)->toBe(DocumentType::TAX_DOCUMENT)
        ->and(at($extraction?->fields, 'payments'))->toHaveCount(2)
        ->and(at($extraction?->fields, 'payments.0.category'))->toBe('TAX_ANAF');
});

it('stores a contract that commits no money at all', function (): void {
    // No payments, and that is the answer rather than a failure: a contract is an archive entry.
    $extraction = validator()->validate(null, [
        'document_kind' => 'CONTRACT',
        'description' => 'Contract de prestări servicii',
        'date' => '2026-02-01',
    ]);

    expect($extraction?->type)->toBe(DocumentType::CONTRACT)
        ->and($extraction?->fields)->not->toHaveKey('payments');
});

it('names every nested field in the prompt it sends', function (): void {
    // The real config file, not a stub: the point is that the words shipped for these fields
    // actually reach the prompt.
    $config = new ConfigRepository(['ai' => ['documents' => require __DIR__ . '/../../../../config/ai/documents.php']]);
    $instruction = (new ExtractionPrompt($config))->instruction();

    // The prompt and the JSON schema are generated from one definition, so the words asked for and
    // the shape enforced cannot drift apart.
    foreach (['items', 'total_price', 'unit_price', 'vat_price', 'issuer', 'recipient', 'payments'] as $field) {
        expect($instruction)->toContain($field);
    }
});

it('lets the model say what the document is, and holds the reply to that kind', function (): void {
    // Nobody chose a type at upload, so the union is asked for and `document_kind` decides which
    // schema the reply has to satisfy.
    $extraction = validator()->validate(null, [
        'document_kind' => 'INVOICE',
        'description' => 'Factură Altex',
        'date' => '2026-01-05',
        'document_number' => 'TXS 0042',
        'items' => [['product_name' => 'Laptop', 'total_price' => '1200.00']],
    ]);

    expect($extraction?->type)->toBe(DocumentType::INVOICE)
        ->and(at($extraction?->fields, 'items'))->toHaveCount(1)
        // The classification is not a field of the reading — it is what the reading turned out to
        // be, and it lives on the document.
        ->and($extraction?->fields)->not->toHaveKey('document_kind');
});

it('keeps a reading whose kind it does not recognise, rather than destroying it', function (): void {
    // The regression this whole shape exists to prevent: a model answered `ANAF_DECISION`, nothing
    // could store that kind, and a good read of the issuer and every line item was thrown away over
    // a word. An unknown kind is now `OTHER`, which is a real schema with real rules.
    $extraction = validator()->validate(null, [
        'document_kind' => 'PURCHASE_ORDER',
        'description' => 'Comandă furnizor 1187',
        'date' => '2026-01-05',
        'issuer' => ['name' => 'ACME SRL'],
    ]);

    expect($extraction?->type)->toBe(DocumentType::OTHER)
        ->and(at($extraction?->fields, 'issuer.name'))->toBe('ACME SRL');
});

it('offers the model every kind it can store, each with a line saying what it is', function (): void {
    $config = new ConfigRepository(['ai' => ['documents' => require __DIR__ . '/../../../../config/ai/documents.php']]);
    $instruction = (new ExtractionPrompt($config))->instruction();

    // Generated from the enum, so a kind that exists is offered and one that does not cannot be.
    foreach (DocumentType::cases() as $case) {
        expect($instruction)->toContain($case->value);
    }

    expect($instruction)->toContain('factură')
        ->and($instruction)->toContain('extras de cont')
        // Gone with the types they named. A prompt still offering them is a prompt that can produce
        // a reply nothing downstream will accept.
        ->and($instruction)->not->toContain('ANAF_DECISION')
        ->and($instruction)->not->toContain('BANK_STATEMENT');
});

it('asks an untyped document to classify itself', function (): void {
    $config = new ConfigRepository(['ai' => ['documents' => require __DIR__ . '/../../../../config/ai/documents.php']]);
    $instruction = (new ExtractionPrompt($config))->instruction();

    expect($instruction)->toContain('document_kind')
        // The union carries every type's fields, so one call can read whatever it turns out to be.
        ->and($instruction)->toContain('items')
        ->and($instruction)->toContain('transactions');
});
